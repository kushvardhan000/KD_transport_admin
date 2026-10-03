<?php

namespace App\Services;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports a client log sheet workbook.
 *
 * Tolerance contract (FX-2): only Log Sheet No, Date, Invoice No and Invoice
 * Date are required. Every other column is best-effort — a missing, renamed,
 * duplicated, blank or junk-valued optional column is reported in
 * `logsheet_imports.warnings` and never fails the import.
 */
class LogsheetImportService
{
    public const TOTAL_AMOUNT_FIELD = 'actual_amount';

    /**
     * Longest string we keep for a varchar(255) column.
     */
    protected const TEXT_MAX = 255;

    /**
     * raw_data keeps the *full* original value, so it gets a much larger cap.
     */
    protected const RAW_MAX = 10000;

    /**
     * Rows inserted per batch.
     */
    protected const CHUNK_SIZE = 500;

    /**
     * Decimal scale for every canonical numeric column, matched to the real
     * schema (decimal(14,3) for weights/volume, decimal(14,2) for amounts).
     *
     * @var array<string, int>
     */
    protected const NUMERIC_SCALES = [
        'gross_wt' => 3,
        'gross_wt_2' => 3,
        'volume' => 3,
        'difference_placeholder' => 3,
        'amount' => 2,
        'booked_amount' => 2,
        'actual_rate' => 2,
        'actual_amount' => 2,
        'diff' => 2,
    ];

    /**
     * @var array<int, string>
     */
    protected const DATE_COLUMNS = ['date', 'inv_date', 'posting_date', 'bill_date'];

    /**
     * Optional canonical columns, with the label used in warnings.
     *
     * @var array<string, string>
     */
    protected const OPTIONAL_LABELS = [
        'payer' => 'Payer',
        'payer_name' => 'Payer Name',
        'town' => 'Town',
        'town_2' => 'Town (2nd)',
        'gross_wt' => 'Gross Wt',
        'gross_wt_2' => 'Gross Wt (2nd)',
        'difference_placeholder' => 'Difference',
        'diff' => 'Diff',
        'amount' => 'Amount',
        'volume' => 'Volume',
        'tprt_code' => 'Tprt Code',
        'tprt_name' => 'Tprt Name',
        'container_id' => 'Container ID',
        'destination' => 'Destination',
        'sap_invoice_no' => 'SAP Invoice No',
        'posting_date' => 'Posting Date',
        'bill_date' => 'Bill Date',
        'vendor_inv_no' => 'Vendor Inv No',
        'route' => 'Route',
        'booked_amount' => 'Booked Amount',
        'actual_rate' => 'Actual Rate',
        'actual_amount' => 'Actual Amount',
    ];

    /**
     * Log-sheet level fields, taken from the first non-empty value in the group.
     * These are payload column names; `container_id` lands in `vehicle_no`.
     *
     * @var array<int, string>
     */
    protected const GROUP_HEADER_FIELDS = [
        'container_id',
        'tprt_code',
        'tprt_name',
        'destination',
        'sap_invoice_no',
        'posting_date',
        'bill_date',
        'vendor_inv_no',
    ];

    /**
     * Total Amount = sum of Actual Amount across valid consolidated rows in-range.
     */
    protected function calculateTotalAmount(?string $field = null): string
    {
        return $field ?? self::TOTAL_AMOUNT_FIELD;
    }

    public function import(UploadedFile $file, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $user = Auth::user();
        $filePath = null;

        try {
            return DB::transaction(function () use ($file, $user, $dateFrom, $dateTo, &$filePath) {
                $rows = Excel::toArray([], $file);

                if (! $this->workbookHasContent($rows)) {
                    return $this->invalidResult($dateFrom, $dateTo, $file, $user, 'The uploaded workbook is empty.');
                }

                $picked = LogsheetHeaderMapper::pickSheet($rows);

                // A CSV is re-read once, with its own delimiter, if the reader
                // split it so badly that no header row survived. This is the
                // only second read in the method, and Excel::toArray() is still
                // called exactly once.
                $rows = $this->recoverMisreadCsv($file, $rows, $picked);
                $picked = LogsheetHeaderMapper::pickSheet($rows);

                if ($picked === null) {
                    return $this->invalidResult(
                        $dateFrom,
                        $dateTo,
                        $file,
                        $user,
                        'No header row found. The file must contain a header row with the columns: '
                        . implode(', ', LogsheetHeaderMapper::REQUIRED_LABELS) . '.'
                    );
                }

                $headerCells = array_values($rows[$picked['sheet']][$picked['header_row']] ?? []);
                $mapped = $picked['mapped'];
                $headerRow = $picked['header_row'];
                $dataRows = array_values(array_slice(
                    array_values($rows[$picked['sheet']]),
                    $headerRow + 1
                ));

                // Release the workbook as early as possible: for a 3000-row
                // sheet this is the single largest allocation in the method.
                unset($rows, $picked);

                if (! empty($mapped['missing_required'])) {
                    $found = $mapped['found_headers'];

                    return $this->invalidResult(
                        $dateFrom,
                        $dateTo,
                        $file,
                        $user,
                        'Missing required columns: ' . implode(', ', $mapped['missing_required'])
                        . '. Found columns: ' . (empty($found) ? '(none)' : implode(', ', $found)) . '.'
                    );
                }

                if (empty($dataRows)) {
                    return $this->invalidResult(
                        $dateFrom,
                        $dateTo,
                        $file,
                        $user,
                        'The uploaded workbook has a header row but no data rows.'
                    );
                }

                $headers = $mapped['canonical'];
                $extraColumns = $mapped['extra'];
                $labels = $this->columnLabels($headerCells, $headers);
                $extraColumns = $this->alignExtraKeys($extraColumns, $labels['raw_keys']);
                $userProvidedRange = $dateFrom !== null && $dateTo !== null;

                $warnings = $this->buildStructuralWarnings($headers);

                $filePath = $file->store('logsheets', 'public');

                $import = LogsheetImport::create([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $filePath,
                    'uploaded_by' => $user?->id,
                    'row_count' => 0,
                    'consolidated_count' => 0,
                    'duplicate_count' => 0,
                    'invalid_count' => 0,
                    'out_of_range_rows' => 0,
                    'status' => 'processing',
                    'total_amount' => '0.00',
                    'total_booked_amount' => '0.00',
                    'total_diff' => '0.00',
                    'total_gross_wt' => '0.000',
                ]);

                $raw = [];
                $invalidRawRows = [];
                $allDates = [];
                $blankRowsSkipped = 0;
                $blankInvoiceNoRows = 0;
                $blankInvDateRows = 0;
                $blankDateRows = 0;
                $invalid = 0;
                $outOfRange = 0;
                $timestamp = now()->format('Y-m-d H:i:s');

                foreach ($dataRows as $idx => $line) {
                    if (! is_array($line) || $this->isBlankRow($line)) {
                        $blankRowsSkipped++;
                        continue;
                    }

                    $rowNum = $headerRow + $idx + 2;

                    try {
                        $parsed = $this->parseRow($line, $headers, $extraColumns, $labels);
                    } catch (Throwable $e) {
                        $invalid++;
                        $invalidRawRows[] = $this->invalidRawRow(
                            $import->id,
                            null,
                            $this->rawPayload($line, $headers, $extraColumns, $labels),
                            $rowNum,
                            'Row could not be read: ' . $e->getMessage(),
                            $timestamp
                        );
                        continue;
                    }

                    foreach ($parsed['unparseable'] as $label) {
                        $warnings['unparseable_values'][$label] = ($warnings['unparseable_values'][$label] ?? 0) + 1;
                    }

                    $payload = $parsed['payload'];
                    $rowDate = $payload['date'] ?? null;

                    if ($rowDate !== null) {
                        $allDates[] = $rowDate;
                    }

                    $logSheetNo = $payload['log_sheet_no'] ?? null;

                    // The only fatal per-row condition: we cannot file a row
                    // that has no Log Sheet No. Everything else is stored NULL.
                    if ($logSheetNo === null) {
                        $invalid++;
                        $invalidRawRows[] = $this->invalidRawRow(
                            $import->id,
                            null,
                            $this->rawPayload($line, $headers, $extraColumns, $labels),
                            $rowNum,
                            'Missing Log Sheet No',
                            $timestamp
                        );
                        continue;
                    }

                    if (($payload['invoice_no'] ?? null) === null) {
                        $blankInvoiceNoRows++;
                    }
                    if (($payload['inv_date'] ?? null) === null) {
                        $blankInvDateRows++;
                    }
                    if ($rowDate === null) {
                        $blankDateRows++;
                    }

                    // A row with no usable date is in range unless the user
                    // picked a range; with no range at all nothing is "out".
                    $inRange = $userProvidedRange
                        ? ($rowDate !== null && $rowDate >= $dateFrom && $rowDate <= $dateTo)
                        : true;

                    if (! $inRange) {
                        $outOfRange++;
                    }

                    // Only the raw row is kept: no duplicate parsed payload copy.
                    $raw[] = [
                        'row' => $line,
                        'rowNumber' => $rowNum,
                        'in_range' => $inRange,
                    ];
                }

                unset($dataRows);

                if (! empty($invalidRawRows)) {
                    foreach (array_chunk($invalidRawRows, self::CHUNK_SIZE) as $chunk) {
                        DB::table('logsheet_raw_rows')->insert($chunk);
                    }
                    $invalidRawRows = [];
                }

                $warnings['blank_rows_skipped'] = $blankRowsSkipped;
                $warnings['blank_invoice_no_rows'] = $blankInvoiceNoRows;
                $warnings['blank_invoice_date_rows'] = $blankInvDateRows;
                $warnings['blank_date_rows'] = $blankDateRows;

                // Auto-derive the range from the file when the user gave none.
                $detectedDateFrom = $allDates ? min($allDates) : null;
                $detectedDateTo = $allDates ? max($allDates) : null;

                if (! $userProvidedRange) {
                    $dateFrom = $detectedDateFrom;
                    $dateTo = $detectedDateTo;
                }

                $totalRowsImported = count($raw);

                $import->update([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'row_count' => $totalRowsImported,
                    'invalid_count' => $invalid,
                    'out_of_range_rows' => $outOfRange,
                ]);

                $groups = [];
                foreach ($raw as $item) {
                    $groups[$this->rowLogSheetNo($item, $headers)][] = $item;
                }
                unset($raw);

                $consolidatedCount = count($groups);
                $fullyOutOfRangeGroups = 0;

                $grandTotalAmount = '0.00';
                $grandTotalBooked = '0.00';
                $grandTotalDiff = '0.00';
                $grandTotalGross = '0.000';

                foreach ($groups as $logSheetNo => $items) {
                    $inRangeCount = 0;
                    foreach ($items as $item) {
                        if ($item['in_range']) {
                            $inRangeCount++;
                        }
                    }
                    $isFullyOutOfRange = $inRangeCount === 0;

                    if ($isFullyOutOfRange) {
                        $fullyOutOfRangeGroups++;
                    }

                    $totalGross = '0.000';
                    $totalBooked = '0.00';
                    $totalActual = '0.00';
                    $totalDiff = '0.00';
                    $groupFields = [];

                    // Pass 1: totals and the log-sheet level fields. Parsed
                    // values are dropped again immediately so only the raw row
                    // is ever held in memory for the group.
                    foreach ($items as $item) {
                        if (! $item['in_range'] && ! $isFullyOutOfRange) {
                            continue;
                        }

                        try {
                            $payload = $this->parseRow($item['row'], $headers, $extraColumns, $labels)['payload'];
                        } catch (Throwable) {
                            continue;
                        }

                        $totalGross = $this->addToTotal($totalGross, $payload['gross_wt'] ?? null, 3);
                        $totalBooked = $this->addToTotal($totalBooked, $payload['booked_amount'] ?? null, 2);
                        $totalActual = $this->addToTotal($totalActual, $payload[$this->calculateTotalAmount()] ?? null, 2);
                        $totalDiff = $this->addToTotal($totalDiff, $payload['diff'] ?? null, 2);
                        $this->collectGroupFields($groupFields, $payload);
                    }

                    $grandTotalAmount = bcadd($grandTotalAmount, $totalActual, 2);
                    $grandTotalBooked = bcadd($grandTotalBooked, $totalBooked, 2);
                    $grandTotalDiff = bcadd($grandTotalDiff, $totalDiff, 2);
                    $grandTotalGross = bcadd($grandTotalGross, $totalGross, 3);

                    $logsheet = $this->storeLogsheet($logSheetNo, $groupFields, [
                        'total_gross_wt' => $totalGross,
                        'total_booked_amount' => $totalBooked,
                        'total_actual_amount' => $totalActual,
                        'total_diff' => $totalDiff,
                        'consignment_count' => $isFullyOutOfRange ? count($items) : $inRangeCount,
                    ], $import, $isFullyOutOfRange && $userProvidedRange);

                    // Pass 2: detail + raw rows, in batches of 500.
                    $detailRows = [];
                    $rawRows = [];
                    $chunkTimestamp = now()->format('Y-m-d H:i:s');

                    foreach ($items as $item) {
                        try {
                            $parsed = $this->parseRow($item['row'], $headers, $extraColumns, $labels);
                        } catch (Throwable $e) {
                            $invalid++;
                            $invalidRawRows[] = $this->invalidRawRow(
                                $import->id,
                                $logSheetNo,
                                $this->rawPayload($item['row'], $headers, $extraColumns, $labels),
                                $item['rowNumber'],
                                'Row could not be read: ' . $e->getMessage(),
                                $chunkTimestamp
                            );
                            continue;
                        }

                        $detailRows[] = $this->detailRow($logsheet->id, $logSheetNo, $parsed['payload'], $parsed['extra']);
                        $rawRows[] = [
                            'import_id' => $import->id,
                            'log_sheet_no' => $logSheetNo,
                            'raw_data' => LogsheetValueParser::jsonSafe(
                                $this->rawPayload($item['row'], $headers, $extraColumns, $labels)
                            ),
                            'row_number_in_file' => $item['rowNumber'],
                            'is_valid' => true,
                            'validation_error' => null,
                            'created_at' => $chunkTimestamp,
                            'updated_at' => $chunkTimestamp,
                        ];

                        if (count($detailRows) >= self::CHUNK_SIZE) {
                            DB::table('logsheet_details')->insert($detailRows);
                            DB::table('logsheet_raw_rows')->insert($rawRows);
                            $detailRows = [];
                            $rawRows = [];
                        }
                    }

                    if (! empty($detailRows)) {
                        DB::table('logsheet_details')->insert($detailRows);
                        DB::table('logsheet_raw_rows')->insert($rawRows);
                    }
                    unset($detailRows, $rawRows);
                }

                unset($groups);

                if (! empty($invalidRawRows)) {
                    DB::table('logsheet_raw_rows')->insert($invalidRawRows);
                }

                $import->update([
                    'status' => 'completed',
                    'invalid_count' => $invalid,
                    'total_amount' => $grandTotalAmount,
                    'total_booked_amount' => $grandTotalBooked,
                    'total_diff' => $grandTotalDiff,
                    'total_gross_wt' => $grandTotalGross,
                    'out_of_range_rows' => $outOfRange,
                    'skipped_out_of_range_groups' => 0,
                    'fully_out_of_range_groups' => $fullyOutOfRangeGroups,
                    'consolidated_count' => $consolidatedCount,
                    'warnings' => $this->compactWarnings($warnings),
                ]);

                return [
                    'rows_imported' => $totalRowsImported,
                    'consolidated' => $consolidatedCount,
                    'duplicates' => 0,
                    'invalid' => $invalid,
                    'total_amount' => $grandTotalAmount,
                    'out_of_range_rows' => $outOfRange,
                    'skipped_out_of_range_groups' => 0,
                    'fully_out_of_range_groups' => $fullyOutOfRangeGroups,
                    'import_id' => $import->id,
                    'user_provided_range' => $userProvidedRange,
                    'detected_date_from' => $detectedDateFrom,
                    'detected_date_to' => $detectedDateTo,
                    'warnings' => $this->compactWarnings($warnings),
                ];
            });
        } catch (Throwable $e) {
            if ($filePath && Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            Log::error('Logsheet import failed', [
                'message' => $e->getMessage(),
                'file' => $file->getClientOriginalName() ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * D1: an unusable file still gets a durable, linkable `invalid` import row
     * carrying the reason — but the file itself is never written to disk.
     */
    protected function invalidResult(?string $dateFrom, ?string $dateTo, UploadedFile $file, $user, string $skip): array
    {
        $import = LogsheetImport::create([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => null,
            'uploaded_by' => $user?->id,
            'row_count' => 0,
            'consolidated_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'status' => 'invalid',
            'warnings' => ['error' => $skip],
            'total_amount' => '0.00',
            'total_booked_amount' => '0.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '0.000',
        ]);

        return [
            'rows_imported' => 0,
            'consolidated' => 0,
            'duplicates' => 0,
            'invalid' => 0,
            'total_amount' => '0.00',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
            'import_id' => $import->id,
            'skip' => $skip,
            'warnings' => ['error' => $skip],
        ];
    }

    // ------------------------------------------------------------ row parsing

    /**
     * Turn one raw row into canonical values plus its unrecognised extras.
     *
     * Every value goes through LogsheetValueParser, so a cell can never carry a
     * malformed literal into a decimal column or a non-UTF-8 byte into a string.
     *
     * @param  array<int, mixed>  $line
     * @param  array<string, int>  $headers
     * @param  array<int, array{key: string, index: int}>  $extraColumns
     * @param  array{raw_keys: array<int, string>, columns: array<string, string>}  $labels
     * @return array{payload: array<string, mixed>, extra: array<string, string>, unparseable: array<int, string>}
     */
    protected function parseRow(array $line, array $headers, array $extraColumns, array $labels): array
    {
        $payload = [];
        $unparseable = [];

        foreach ($headers as $canonical => $index) {
            $raw = $line[$index] ?? null;

            if ($canonical === 'log_sheet_no') {
                $payload[$canonical] = LogsheetValueParser::logSheetNo($raw);
                continue;
            }

            if (in_array($canonical, self::DATE_COLUMNS, true)) {
                $value = LogsheetValueParser::date($raw);
                $payload[$canonical] = $value;

                if ($value === null && ! $this->isBlankCell($raw)) {
                    $unparseable[] = $labels['columns'][$canonical] ?? $canonical;
                }

                continue;
            }

            if (isset(self::NUMERIC_SCALES[$canonical])) {
                $value = LogsheetValueParser::number($raw, self::NUMERIC_SCALES[$canonical]);
                $payload[$canonical] = $value;

                if ($value === null && ! $this->isBlankCell($raw)) {
                    $unparseable[] = $labels['columns'][$canonical] ?? $canonical;
                }

                continue;
            }

            $payload[$canonical] = LogsheetValueParser::text($raw, self::TEXT_MAX);
        }

        $extra = [];
        foreach ($extraColumns as $extraCol) {
            $value = LogsheetValueParser::text($line[$extraCol['index']] ?? null, self::TEXT_MAX);
            if ($value !== null) {
                $extra[$extraCol['key']] = $value;
            }
        }

        return [
            'payload' => $payload,
            'extra' => $extra,
            'unparseable' => $unparseable,
        ];
    }

    /**
     * The full original row, keyed by its own header text, for raw_data.
     *
     * @param  array<int, mixed>  $line
     * @param  array<string, int>  $headers
     * @param  array<int, array{key: string, index: int}>  $extraColumns
     * @param  array{raw_keys: array<int, string>, columns: array<string, string>}  $labels
     * @return array<string, string|null>
     */
    protected function rawPayload(array $line, array $headers, array $extraColumns, array $labels): array
    {
        $raw = [];

        foreach ($headers as $canonical => $index) {
            $raw[$this->rawKey($labels, $canonical, $index)] = LogsheetValueParser::text(
                $line[$index] ?? null,
                self::RAW_MAX
            );
        }

        foreach ($extraColumns as $extraCol) {
            $raw[$extraCol['key']] = LogsheetValueParser::text($line[$extraCol['index']] ?? null, self::RAW_MAX);
        }

        return $raw;
    }

    /**
     * raw_data is keyed by the header text the client actually used, which is
     * what makes it useful for support ("what did the file say?").
     *
     * @param  array{raw_keys: array<int, string>, columns: array<string, string>}  $labels
     */
    protected function rawKey(array $labels, string $canonical, int $index): string
    {
        return $labels['raw_keys'][$index]
            ?? $labels['columns'][$canonical]
            ?? ('Column ' . ($index + 1));
    }

    /**
     * Build the per-column labels used in warnings and in raw_data keys.
     *
     * raw_data keys and extra_fields keys are de-duplicated by column position,
     * so two columns both headed "Gross Wt" become "Gross Wt" and
     * "Gross Wt (2)" and neither value is lost.
     *
     * @param  array<int, mixed>  $headerCells
     * @param  array<string, int>  $headers
     * @return array{raw_keys: array<int, string>, columns: array<string, string>}
     */
    protected function columnLabels(array $headerCells, array $headers): array
    {
        $rawKeys = [];
        $seen = [];
        $cells = [];

        foreach (array_values($headerCells) as $index => $cell) {
            $text = is_array($cell) || is_object($cell) || is_bool($cell) || $cell === null
                ? ''
                : trim((string) $cell);
            $cells[$index] = $text;

            $key = $text === '' ? 'Column ' . ($index + 1) : $text;
            $base = $key;
            $suffix = 2;
            while (isset($seen[$key])) {
                $key = $base . ' (' . $suffix . ')';
                $suffix++;
            }
            $seen[$key] = true;
            $rawKeys[$index] = $key;
        }

        $columns = [];
        foreach ($headers as $canonical => $index) {
            $columns[$canonical] = $cells[$index] !== ''
                ? $cells[$index]
                : (self::OPTIONAL_LABELS[$canonical] ?? $canonical);
        }

        return ['raw_keys' => $rawKeys, 'columns' => $columns];
    }

    /**
     * Re-key the extra columns by their position so extra_fields and raw_data
     * always use the exact same key for the same column.
     *
     * @param  array<int, array{key: string, index: int}>  $extraColumns
     * @param  array<int, string>  $rawKeys
     * @return array<int, array{key: string, index: int}>
     */
    protected function alignExtraKeys(array $extraColumns, array $rawKeys): array
    {
        foreach ($extraColumns as $i => $extraCol) {
            if (isset($rawKeys[$extraCol['index']])) {
                $extraColumns[$i]['key'] = $rawKeys[$extraCol['index']];
            }
        }

        return $extraColumns;
    }

    // ------------------------------------------------------------- persistence

    /**
     * @param  array<string, string|null>  $payload
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    protected function detailRow(int $logsheetId, string $logSheetNo, array $payload, array $extra): array
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        return [
            'logsheet_id' => $logsheetId,
            'log_sheet_no' => $logSheetNo,
            'date' => $payload['date'] ?? null,
            'invoice_no' => $payload['invoice_no'] ?? null,
            'inv_date' => $payload['inv_date'] ?? null,
            'payer' => $payload['payer'] ?? null,
            'payer_name' => $payload['payer_name'] ?? null,
            'town' => $payload['town'] ?? null,
            'gross_wt' => $payload['gross_wt'] ?? null,
            'diff' => $payload['diff'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'volume' => $payload['volume'] ?? null,
            'tprt_code' => $payload['tprt_code'] ?? null,
            'tprt_name' => $payload['tprt_name'] ?? null,
            'container_id' => $payload['container_id'] ?? null,
            'destination' => $payload['destination'] ?? null,
            'sap_invoice_no' => $payload['sap_invoice_no'] ?? null,
            'posting_date' => $payload['posting_date'] ?? null,
            'bill_date' => $payload['bill_date'] ?? null,
            'vendor_inv_no' => $payload['vendor_inv_no'] ?? null,
            'route' => $payload['route'] ?? null,
            'town_2' => $payload['town_2'] ?? null,
            'gross_weight_2' => $payload['gross_wt_2'] ?? null,
            'booked_amount' => $payload['booked_amount'] ?? null,
            'actual_rate' => $payload['actual_rate'] ?? null,
            'actual_amount' => $payload['actual_amount'] ?? null,
            'difference_placeholder' => $payload['difference_placeholder'] ?? null,
            'extra_fields' => empty($extra) ? null : LogsheetValueParser::jsonSafe($extra),
            'cleared' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function invalidRawRow(int $importId, ?string $logSheetNo, array $raw, int $rowNum, string $error, string $timestamp): array
    {
        return [
            'import_id' => $importId,
            'log_sheet_no' => $logSheetNo,
            'raw_data' => LogsheetValueParser::jsonSafe($raw),
            'row_number_in_file' => $rowNum,
            'is_valid' => false,
            'validation_error' => $error,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * Insert or update the consolidated log sheet, restoring a soft-deleted one.
     *
     * @param  array<string, string|null>  $groupFields
     * @param  array<string, mixed>  $totals
     */
    protected function storeLogsheet(string $logSheetNo, array $groupFields, array $totals, LogsheetImport $import, bool $fullyOutOfRequestedRange): Logsheet
    {
        $attributes = array_merge([
            'date' => $groupFields['date'] ?? null,
            'vehicle_no' => $groupFields['container_id'] ?? null,            'tprt_code' => $groupFields['tprt_code'] ?? null,
            'tprt_name' => $groupFields['tprt_name'] ?? null,
            'destination' => $groupFields['destination'] ?? null,
            'sap_invoice_no' => $groupFields['sap_invoice_no'] ?? null,
            'posting_date' => $groupFields['posting_date'] ?? null,
            'bill_date' => $groupFields['bill_date'] ?? null,
            'vendor_inv_no' => $groupFields['vendor_inv_no'] ?? null,
        ], $totals, [
            'status' => 'pending',
            'last_import_id' => $import->id,
            'fully_out_of_requested_range' => $fullyOutOfRequestedRange,
        ]);

        $existing = Logsheet::withTrashed()->where('log_sheet_no', $logSheetNo)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return Logsheet::create(array_merge(['log_sheet_no' => $logSheetNo], $attributes));
    }

    /**
     * First non-empty value wins for the log-sheet level fields.
     *
     * @param  array<string, string|null>  $groupFields
     * @param  array<string, mixed>  $payload
     */
    protected function collectGroupFields(array &$groupFields, array $payload): void
    {
        foreach (self::GROUP_HEADER_FIELDS as $field) {
            if (! isset($groupFields[$field]) && ! empty($payload[$field])) {
                $groupFields[$field] = $payload[$field];
            }
        }

        if (! isset($groupFields['date']) && ! empty($payload['date'])) {
            $groupFields['date'] = $payload['date'];
        }
    }

    // ----------------------------------------------------------------- totals

    /**
     * Add one parsed value into a running total, skipping null/unparseable.
     */
    protected function addToTotal(string $total, $value, int $scale): string
    {
        $cleaned = $this->cleanNumberForBCMath($value, $scale);

        if ($cleaned === null) {
            return $total;
        }

        return bcadd($total, $cleaned, $scale);
    }

    /**
     * Backwards-compatible wrapper around LogsheetValueParser::number().
     *
     * @param  mixed  $value
     */
    protected function cleanNumberForBCMath($value, int $scale = 2): ?string
    {
        return LogsheetValueParser::number($value, $scale);
    }

    /**
     * @param  array<string, int>  $headers
     * @return array<string, mixed>
     */
    protected function buildStructuralWarnings(array $headers): array
    {
        $missing = [];
        foreach (self::OPTIONAL_LABELS as $canonical => $label) {
            if (! array_key_exists($canonical, $headers)) {
                $missing[] = $label;
            }
        }

        $warnings = [
            'missing_optional_columns' => $missing,
            'unparseable_values' => [],
            'notes' => [],
        ];

        if (! array_key_exists($this->calculateTotalAmount(), $headers)) {
            $warnings['notes'][] = 'No Actual Amount column found, totals are 0.';
        }

        return $warnings;
    }

    /**
     * Keep the stored payload compact: counts only, capped lists, no empty keys.
     *
     * @param  array<string, mixed>  $warnings
     * @return array<string, mixed>
     */
    protected function compactWarnings(array $warnings): array
    {
        $missing = $warnings['missing_optional_columns'] ?? [];
        if (count($missing) > 12) {
            $extraCount = count($missing) - 12;
            $missing = array_slice($missing, 0, 12);
            $missing[] = "and {$extraCount} more";
        }

        return [
            'missing_optional_columns' => $missing,
            'unparseable_values' => $warnings['unparseable_values'] ?? [],
            'blank_invoice_no_rows' => $warnings['blank_invoice_no_rows'] ?? 0,
            'blank_invoice_date_rows' => $warnings['blank_invoice_date_rows'] ?? 0,
            'blank_date_rows' => $warnings['blank_date_rows'] ?? 0,
            'blank_rows_skipped' => $warnings['blank_rows_skipped'] ?? 0,
            'notes' => $warnings['notes'] ?? [],
        ];
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Re-read a CSV whose delimiter the reader guessed wrongly.
     *
     * PhpSpreadsheet picks a CSV delimiter by sampling the file, and a title
     * banner such as `SLS TRANSPORT - CONSIGNMENT SHEET` is enough to make it
     * choose a space: every data row then collapses into one cell, the header
     * row disappears, and a perfectly good client file is reported as having no
     * header at all. When the first read produced no usable header and the file
     * is a CSV, the file is read again with the delimiter its own content
     * agrees on. Excel::toArray() is still called exactly once.
     *
     * @param  array<int|string, mixed>  $rows
     * @param  array{sheet: int, header_row: int, mapped: array}|null  $picked
     * @return array<int|string, mixed>
     */
    protected function recoverMisreadCsv(UploadedFile $file, array $rows, ?array $picked): array
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
            return $rows;
        }

        $missing = $picked === null ? count(LogsheetHeaderMapper::REQUIRED) : count($picked['mapped']['missing_required']);
        if ($missing === 0) {
            return $rows;
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return $rows;
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv;
            $reader->setDelimiter($this->detectDelimiter($path));
            $spreadsheet = $reader->load($path);

            $retry = [];
            foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
                $retry[$index] = $sheet->toArray();
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (Throwable $e) {
            Log::warning('Could not re-read a CSV with a detected delimiter', [
                'file' => $file->getClientOriginalName(),
                'message' => $e->getMessage(),
            ]);

            return $rows;
        }

        $retryPicked = LogsheetHeaderMapper::pickSheet($retry);
        $retryMissing = $retryPicked === null
            ? count(LogsheetHeaderMapper::REQUIRED)
            : count($retryPicked['mapped']['missing_required']);

        if ($retryMissing >= $missing || ! $this->workbookHasContent($retry)) {
            return $rows;
        }

        return $retry;
    }

    /**
     * The delimiter a CSV actually uses.
     *
     * Only real delimiter characters are considered — never a space, which is
     * the one candidate that appears inside ordinary data and is the reason the
     * reader's own guess goes wrong. The candidate with the most occurrences
     * across the first lines of the file wins, with a comma taking ties.
     */
    protected function detectDelimiter(string $path): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ',';
        }

        try {
            $lines = 0;
            while ($lines < 25 && ($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $lines++;

                foreach (array_keys($candidates) as $candidate) {
                    $candidates[$candidate] += substr_count($line, $candidate);
                }
            }
        } finally {
            fclose($handle);
        }

        $best = ',';
        foreach ($candidates as $candidate => $count) {
            if ($count > $candidates[$best]) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * A workbook counts as having content only if some sheet holds a row with a
     * non-blank cell — an empty grid of cells is still an empty workbook.
     *
     * @param  array<int|string, mixed>  $rows
     */
    protected function workbookHasContent(array $rows): bool
    {
        foreach ($rows as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }

            foreach ($sheet as $row) {
                if (is_array($row) && ! $this->isBlankRow($row)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $line
     */
    protected function isBlankRow(array $line): bool
    {
        foreach ($line as $value) {
            if (! $this->isBlankCell($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     */
    protected function isBlankCell($value): bool
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return $value === null;
        }

        return trim((string) $value) === '';
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $headers
     */
    protected function rowLogSheetNo(array $item, array $headers): string
    {
        return (string) LogsheetValueParser::logSheetNo(
            $item['row'][$headers['log_sheet_no']] ?? null
        );
    }

    /**
     * Kept as a thin wrapper so existing callers keep working; all of the real
     * logic lives in LogsheetValueParser.
     *
     * @param  mixed  $value
     */
    public static function parseDateValue($value): ?string
    {
        return LogsheetValueParser::date($value);
    }
}
