<?php

namespace App\Services;

use App\Models\Logsheet;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Throwable;

class LogsheetImportService
{
    public const TOTAL_AMOUNT_FIELD = 'actual_amount';

    /**
     * Total Amount = sum of Actual Amount across valid consolidated rows in-range.
     */
    protected function calculateTotalAmount(string $field = null): string
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
                $sheet = $rows[0] ?? [];
                if (empty($sheet)) {
                    return $this->emptyResult($dateFrom, $dateTo, $file, $user, 'The uploaded workbook is empty.');
                }

                \Log::info('LogsheetImportService: Processing sheet', ['row_count' => count($sheet)]);

                $headerRow = $this->findHeaderRow($sheet);
                if ($headerRow === null) {
                    return $this->emptyResult($dateFrom, $dateTo, $file, $user, 'Missing Log Sheet No header; cannot identify the client workbook format.');
                }

                $rawHeaders = array_values($sheet[$headerRow] ?? []);
                $headerResult = $this->normalizeHeaders($rawHeaders);
                $headers = $headerResult['canonical'];
                $extraColumns = $headerResult['extra'];
                $missing = collect($this->requiredColumns())->diff(array_keys($headers))->values();

                if ($missing->isNotEmpty()) {
                    return $this->emptyResult($dateFrom, $dateTo, $file, $user, 'Missing required columns: ' . $missing->implode(', '));
                }

                // Parse all rows first to collect dates for auto-derivation
                $allParsedRows = [];
                $allDates = [];

                foreach (array_slice($sheet, $headerRow + 1) as $idx => $line) {
                    $rowNum = $headerRow + $idx + 2;

                    if ($this->isBlankRow($line)) {
                        continue;
                    }

                    $payload = [];
                    foreach ($headers as $canonical => $headIndex) {
                        $payload[$canonical] = $this->cellValue($line[$headIndex] ?? null);
                    }

                    // Capture extra columns
                    $extraFields = [];
                    foreach ($extraColumns as $extraCol) {
                        $value = $this->cellValue($line[$extraCol['index']] ?? null);
                        if ($value !== '' && $value !== null) {
                            $extraFields[$extraCol['key']] = $value;
                        }
                    }
                    if (!empty($extraFields)) {
                        $payload['extra_fields'] = $extraFields;
                    }

                    // Build raw payload with ALL columns (canonical + extra) using raw header names
                    $rawPayload = $payload; // Start with canonical payload
                    // Add extra columns with their raw header names
                    foreach ($extraColumns as $extraCol) {
                        $value = $this->cellValue($line[$extraCol['index']] ?? null);
                        if ($value !== '' && $value !== null) {
                            $rawPayload[$extraCol['key']] = $value;
                        }
                    }
                    // Remove extra_fields from raw payload (it's internal)
                    unset($rawPayload['extra_fields']);

                    $payload = $this->normalizePayload($payload);

                    $rowDate = $this->parseDate($payload['date'] ?? null);
                    if ($rowDate !== null) {
                        $allDates[] = $rowDate;
                    }

                    $allParsedRows[] = [
                        'rowNum' => $rowNum,
                        'payload' => $payload,
                        'rawPayload' => $rawPayload,
                        'rowDate' => $rowDate,
                    ];
                }

                // Auto-derive date range from file data if not provided
                $userProvidedRange = $dateFrom !== null && $dateTo !== null;
                if (!$userProvidedRange) {
                    if (!empty($allDates)) {
                        $dateFrom = min($allDates);
                        $dateTo = max($allDates);
                    } else {
                        // Fallback to today if no valid dates found
                        $dateFrom = now()->toDateString();
                        $dateTo = now()->toDateString();
                    }
                }

                // Store detected dates for flash message
                $detectedDateFrom = !empty($allDates) ? min($allDates) : null;
                $detectedDateTo = !empty($allDates) ? max($allDates) : null;

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
                    'status' => 'pending',
                    'total_amount' => '0.00',
                    'total_booked_amount' => '0.00',
                    'total_diff' => '0.00',
                    'total_gross_wt' => '0.000',
                ]);

                $raw = [];
                $invalid = 0;
                $outOfRange = 0;
                $invalidRawRows = [];
                $timestamp = now()->format('Y-m-d H:i:s');

                foreach ($allParsedRows as $parsed) {
                    $rowNum = $parsed['rowNum'];
                    $payload = $parsed['payload'];
                    $rawPayload = $parsed['rawPayload'];
                    $rowDate = $parsed['rowDate'];

                    $logSheetNo = trim((string) ($payload['log_sheet_no'] ?? ''));
                    if ($logSheetNo === '') {
                        $invalid++;
                        $invalidRawRows[] = [
                            'import_id' => $import->id,
                            'log_sheet_no' => null,
                            'raw_data' => json_encode($rawPayload),
                            'row_number_in_file' => $rowNum,
                            'is_valid' => false,
                            'validation_error' => 'Missing Log Sheet No',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                        continue;
                    }

                    $inRange = $rowDate !== null && $rowDate >= $dateFrom && $rowDate <= $dateTo;
                    if (!$inRange) {
                        $outOfRange++;
                    }

                    $raw[] = [
                        'log_sheet_no' => $logSheetNo,
                        'payload' => $payload,
                        'rawPayload' => $rawPayload,
                        'rowNumber' => $rowNum,
                        'in_range' => $inRange,
                    ];
                }

                if (!empty($invalidRawRows)) {
                    DB::table('logsheet_raw_rows')->insert($invalidRawRows);
                }

                $groups = collect($raw)->groupBy('log_sheet_no');
                $totalRowsImported = count($raw);
                $consolidatedCount = $groups->count();

                $skippedOutOfRangeGroups = 0;
                $fullyOutOfRangeGroups = 0;

                $import->update([
                    'row_count' => $totalRowsImported,
                    'consolidated_count' => $consolidatedCount,
                    'duplicate_count' => 0,
                    'invalid_count' => $invalid,
                    'out_of_range_rows' => $outOfRange,
                    'status' => 'processing',
                ]);

                $grandTotalAmount = '0.00';
                $grandTotalBooked = '0.00';
                $grandTotalDiff = '0.00';
                $grandTotalGross = '0.000';

                foreach ($groups as $logSheetNo => $items) {
                    $inRangeItems = collect($items)->filter(fn ($i) => $i['in_range']);
                    $outOfRangeItems = collect($items)->filter(fn ($i) => !$i['in_range']);
                    $isFullyOutOfRange = $inRangeItems->isEmpty();

                    if ($isFullyOutOfRange) {
                        $fullyOutOfRangeGroups++;
                        // Use ALL items for this group (not just in-range)
                        $itemsForTotals = $items;
                    } else {
                        $itemsForTotals = $inRangeItems;
                    }

                    $payloads = collect($itemsForTotals)->pluck('payload');
                    $first = $payloads->first();

                    $date = $this->parseDate($first['date'] ?? null);
                    $posting = $this->parseDate($first['posting_date'] ?? null);
                    $bill = $this->parseDate($first['bill_date'] ?? null);

                    $totalGross = $this->sumWithBCMath($payloads, 'gross_wt', 3);
                    $totalBooked = $this->sumWithBCMath($payloads, 'booked_amount', 2);
                    $totalActual = $this->sumWithBCMath($payloads, $this->calculateTotalAmount(), 2);
                    $totalDiff = $this->sumWithBCMath($payloads, 'diff', 2);

                    $grandTotalAmount = bcadd($grandTotalAmount, $totalActual, 2);
                    $grandTotalBooked = bcadd($grandTotalBooked, $totalBooked, 2);
                    $grandTotalDiff = bcadd($grandTotalDiff, $totalDiff, 2);
                    $grandTotalGross = bcadd($grandTotalGross, $totalGross, 3);

                    $existing = Logsheet::withTrashed()->where('log_sheet_no', $logSheetNo)->first();

                    if ($existing) {
                        if ($existing->trashed()) {
                            $existing->restore();
                        }

                        $existing->fill([
                            'date' => $date,
                            'vehicle_no' => $first['container_id'] ?? null,
                            'tprt_code' => $first['tprt_code'] ?? null,
                            'tprt_name' => $first['tprt_name'] ?? null,
                            'destination' => $first['destination'] ?? null,
                            'sap_invoice_no' => $first['sap_invoice_no'] ?? null,
                            'posting_date' => $posting,
                            'bill_date' => $bill,
                            'vendor_inv_no' => $first['vendor_inv_no'] ?? null,
                            'total_gross_wt' => $totalGross,
                            'total_booked_amount' => $totalBooked,
                            'total_actual_amount' => $totalActual,
                            'total_diff' => $totalDiff,
                            'consignment_count' => count($itemsForTotals),
                            'status' => 'pending',
                            'last_import_id' => $import->id,
                            'fully_out_of_requested_range' => $isFullyOutOfRange && $userProvidedRange,
                        ]);
                        $existing->save();
                        $logsheet = $existing;
                    } else {
                        $logsheet = Logsheet::create([
                            'log_sheet_no' => $logSheetNo,
                            'date' => $date,
                            'vehicle_no' => $first['container_id'] ?? null,
                            'tprt_code' => $first['tprt_code'] ?? null,
                            'tprt_name' => $first['tprt_name'] ?? null,
                            'destination' => $first['destination'] ?? null,
                            'sap_invoice_no' => $first['sap_invoice_no'] ?? null,
                            'posting_date' => $posting,
                            'bill_date' => $bill,
                            'vendor_inv_no' => $first['vendor_inv_no'] ?? null,
                            'total_gross_wt' => $totalGross,
                            'total_booked_amount' => $totalBooked,
                            'total_actual_amount' => $totalActual,
                            'total_diff' => $totalDiff,
                            'consignment_count' => count($itemsForTotals),
                            'status' => 'pending',
                            'last_import_id' => $import->id,
                            'fully_out_of_requested_range' => $isFullyOutOfRange && $userProvidedRange,
                        ]);
                    }

                    $detailRows = [];
                    $rawRows = [];
                    $timestamp = now()->format('Y-m-d H:i:s');
                    $chunkSize = 500;

                    // Insert ALL rows (both in-range and out-of-range) for detail/raw_rows
                    foreach ($items as $item) {
                        $payload = $item['payload'];

                        $detailDate = $this->parseDate($payload['date'] ?? null);
                        $detailInvDate = $this->parseDate($payload['inv_date'] ?? null);
                        $detailPosting = $this->parseDate($payload['posting_date'] ?? null);
                        $detailBill = $this->parseDate($payload['bill_date'] ?? null);

                        $detailRows[] = [
                            'logsheet_id' => $logsheet->id,
                            'log_sheet_no' => $logSheetNo,
                            'date' => $detailDate,
                            'invoice_no' => $payload['invoice_no'] ?? null,
                            'inv_date' => $detailInvDate,
                            'payer' => $payload['payer'] ?? null,
                            'payer_name' => $payload['payer_name'] ?? null,
                            'town' => $payload['town'] ?? null,
                            // First "Gross Wt" column (authoritative for totals)
                            'gross_wt' => $payload['gross_wt'] ?? null,
                            // "Diff" column (authoritative for totals, appears later in sheet as "Diff")
                            'diff' => $payload['diff'] ?? null,
                            'amount' => $payload['amount'] ?? null,
                            'volume' => $payload['volume'] ?? null,
                            'tprt_code' => $payload['tprt_code'] ?? null,
                            'tprt_name' => $payload['tprt_name'] ?? null,
                            'container_id' => $payload['container_id'] ?? null,
                            'destination' => $payload['destination'] ?? null,
                            'sap_invoice_no' => $payload['sap_invoice_no'] ?? null,
                            'posting_date' => $detailPosting,
                            'bill_date' => $detailBill,
                            'vendor_inv_no' => $payload['vendor_inv_no'] ?? null,
                            'route' => $payload['route'] ?? null,
                            'town_2' => $payload['town_2'] ?? null,
                            // Second "Gross weight" column (appears later in sheet, kept for audit)
                            'gross_weight_2' => $payload['gross_wt_2'] ?? null,
                            'booked_amount' => $payload['booked_amount'] ?? null,
                            'actual_rate' => $payload['actual_rate'] ?? null,
                            'actual_amount' => $payload['actual_amount'] ?? null,
                            // Earlier "difference" column (appears before "Diff", kept for reference)
                            'difference_placeholder' => $payload['difference_placeholder'] ?? null,
                            'extra_fields' => !empty($payload['extra_fields']) ? json_encode($payload['extra_fields']) : null,
                            'cleared' => false,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];

                        $rawRows[] = [
                            'import_id' => $import->id,
                            'log_sheet_no' => $logSheetNo,
                            'raw_data' => json_encode($item['rawPayload']),
                            'row_number_in_file' => $item['rowNumber'],
                            'is_valid' => true,
                            'validation_error' => null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];

                        if (count($detailRows) >= $chunkSize) {
                            \Log::info('LogsheetImportService: Chunk insert', ['detail_count' => count($detailRows), 'raw_count' => count($rawRows)]);
                            DB::table('logsheet_details')->insert($detailRows);
                            DB::table('logsheet_raw_rows')->insert($rawRows);
                            $detailRows = [];
                            $rawRows = [];
                        }
                    }

                    if (!empty($detailRows)) {
                        \Log::info('LogsheetImportService: Final batch insert', ['detail_count' => count($detailRows), 'raw_count' => count($rawRows)]);
                        DB::table('logsheet_details')->insert($detailRows);
                        DB::table('logsheet_raw_rows')->insert($rawRows);
                    }
                }

                $import->update([
                    'status' => 'completed',
                    'total_amount' => $grandTotalAmount,
                    'total_booked_amount' => $grandTotalBooked,
                    'total_diff' => $grandTotalDiff,
                    'total_gross_wt' => $grandTotalGross,
                    'out_of_range_rows' => $outOfRange,
                    'skipped_out_of_range_groups' => $skippedOutOfRangeGroups,
                    'fully_out_of_range_groups' => $fullyOutOfRangeGroups,
                    'consolidated_count' => $consolidatedCount,
                ]);

                return [
                    'rows_imported' => $totalRowsImported,
                    'consolidated' => $consolidatedCount,
                    'duplicates' => 0,
                    'invalid' => $invalid,
                    'total_amount' => $grandTotalAmount,
                    'out_of_range_rows' => $outOfRange,
                    'skipped_out_of_range_groups' => $skippedOutOfRangeGroups,
                    'fully_out_of_range_groups' => $fullyOutOfRangeGroups,
                    'import_id' => $import->id,
                    'user_provided_range' => $userProvidedRange,
                    'detected_date_from' => $detectedDateFrom,
                    'detected_date_to' => $detectedDateTo,
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

    protected function emptyResult(?string $dateFrom, ?string $dateTo, UploadedFile $file, $user, string $skip): array
    {
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
            'skipped_out_of_range_groups' => 0,
            'status' => 'invalid',
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
            'import_id' => $import->id,
            'skip' => $skip,
        ];
    }

    protected function sumWithBCMath($collection, string $field, int $scale): string
    {
        $sum = '0';
        $sum = str_pad($sum, $scale + 1, '0', STR_PAD_RIGHT);
        if ($scale > 0) {
            $sum = rtrim($sum, '0');
            if (str_ends_with($sum, '.')) {
                $sum .= '0';
            }
        }

        foreach ($collection as $item) {
            $value = $item[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $cleaned = $this->cleanNumberForBCMath($value);
            if ($cleaned !== null) {
                $sum = bcadd($sum, $cleaned, $scale);
            }
        }

        return $this->formatBCMathResult($sum, $scale);
    }

    protected function cleanNumberForBCMath($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = (string) $value;
        $str = str_replace(',', '', $str);
        $str = preg_replace('/[^0-9.-]/', '', $str);
        if ($str === '' || $str === '-' || $str === '.') {
            return null;
        }
        return $str;
    }

    protected function formatBCMathResult(string $value, int $scale): string
    {
        if ($scale === 0) {
            return $value;
        }
        if (!str_contains($value, '.')) {
            return $value . '.' . str_repeat('0', $scale);
        }
        [$int, $dec] = explode('.', $value);
        $dec = str_pad($dec, $scale, '0');
        $dec = substr($dec, 0, $scale);
        return $int . '.' . $dec;
    }

    protected function requiredColumns(): array
    {
        return [
            'log_sheet_no',
            'date',
            'invoice_no',
            'inv_date',
            'payer',
            'payer_name',
            'town',
            'gross_wt',
            'volume',
            'tprt_code',
            'tprt_name',
            'container_id',
            'destination',
            'sap_invoice_no',
            'posting_date',
            'bill_date',
            'vendor_inv_no',
            'booked_amount',
            'actual_amount',
            'diff',
        ];
    }

    protected function findHeaderRow(array $sheet): ?int
    {
        foreach ($sheet as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $row = array_map(fn ($value) => ! is_string($value) ? (string) $value : trim($value), $row);
            $joined = implode('|', $row);
            if (stripos($joined, 'Log Sheet No') !== false) {
                return $index;
            }
        }

        return null;
    }

    protected function normalizeHeaders(array $rawHeaders): array
    {
        $normalized = [];
        $extra = [];
        $townSeen = false;
        $grossWtSeen = false;
        $diffSeen = false; // tracks the actual "Diff" column (authoritative for totals)

        // Known canonical keys (normalized tokens) - used to detect unknown columns
        // NOTE: 'time', 'cust_group', 'no_of_packs' are intentionally OMITTED from this list
        // so they are treated as "extra" columns and captured in extra_fields.
        // This allows flexible handling of production logsheet variations.
        $knownKeys = [
            'log_sheet_no', 'logsheet_no',
            'date',
            'invoice_no',
            'inv_date',
            'payer',
            'payer_name',
            'town',
            'gross_wt', 'gross_weight',
            'difference', 'diff',
            'amount',
            'volume',
            'tprt_code', 'trpt_code',
            'tprt_name', 'trpt_name',
            'container_id',
            'destination',
            'sapinvoiceno', 'sap_invoice_no',
            'posting_date',
            'bill_date',
            'vendorinvno', 'vendor_inv_no',
            'route',
            'booked_amount',
            'actual_rate',
            'actual_amount',
        ];

        foreach ($rawHeaders as $index => $header) {
            $key = $this->normalizeHeaderToken((string) $header);
            $rawHeaderText = trim((string) $header);

            $canonical = match ($key) {
                'log_sheet_no', 'logsheet_no' => 'log_sheet_no',
                'date' => 'date',
                'invoice_no' => 'invoice_no',
                'inv_date' => 'inv_date',
                'payer' => 'payer',
                'payer_name' => 'payer_name',
                'town' => $townSeen ? 'town_2' : 'town',
                'gross_wt' => $grossWtSeen ? 'gross_wt_2' : 'gross_wt',
                'gross_weight' => $grossWtSeen ? 'gross_wt_2' : 'gross_wt',
                'difference' => 'difference_placeholder',
                'diff' => $diffSeen ? 'difference_placeholder' : 'diff',
                'amount' => 'amount',
                'volume' => 'volume',
                'tprt_code', 'trpt_code' => 'tprt_code',
                'tprt_name', 'trpt_name' => 'tprt_name',
                'container_id' => 'container_id',
                'destination' => 'destination',
                'sapinvoiceno', 'sap_invoice_no' => 'sap_invoice_no',
                'posting_date' => 'posting_date',
                'bill_date' => 'bill_date',
                'vendorinvno', 'vendor_inv_no' => 'vendor_inv_no',
                'route' => 'route',
                'booked_amount' => 'booked_amount',
                'actual_rate' => 'actual_rate',
                'actual_amount' => 'actual_amount',
                default => null,
            };

            if ($key === 'town') {
                $townSeen = true;
            }
            if ($key === 'gross_wt' || $key === 'gross_weight') {
                $grossWtSeen = true;
            }
            if ($key === 'diff') {
                $diffSeen = true;
            }

            if ($canonical) {
                $normalized[$canonical] = $index;
            } elseif ($rawHeaderText !== '' && !in_array($key, $knownKeys, true)) {
                // Unknown column: capture its raw (trimmed) header text and index
                $extra[] = [
                    'key' => $rawHeaderText,
                    'index' => $index,
                ];
            }
        }

        return [
            'canonical' => $normalized,
            'extra' => $extra,
        ];
    }

    protected function normalizeHeaderToken(string $header): string
    {
        $key = trim($header);
        $key = strtolower($key);
        $key = str_replace(['-', '/', '(', ')'], '_', $key);
        $key = preg_replace('/\s+/', '_', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');

        return $key;
    }

    protected function normalizePayload(array $payload): array
    {
        foreach (['log_sheet_no', 'invoice_no', 'payer', 'payer_name', 'town', 'tprt_code', 'tprt_name', 'container_id', 'destination', 'sap_invoice_no', 'vendor_inv_no', 'route', 'town_2'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = trim((string) $payload[$field]);
            }
        }

        foreach (['gross_wt', 'gross_wt_2', 'volume', 'booked_amount', 'actual_rate', 'actual_amount', 'diff', 'difference_placeholder', 'amount'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = $this->parseNumber($payload[$field]);
            }
        }

        return $payload;
    }

    protected function parseNumber($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $cleaned = $this->cleanNumberForBCMath($value);
        if ($cleaned === null) {
            return null;
        }
        return $cleaned;
    }

    protected function isBlankRow(array $line): bool
    {
        foreach ($line as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function cellValue($value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    public static function parseDateValue($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial <= 0) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d', '1899-12-30')
                    ->addDays($serial)
                    ->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $trimmed = trim((string) $value);
        if (preg_match('/^0+(\.0+)?$/', $trimmed) || in_array($trimmed, ['00.00.0000', '0000-00-00'], true)) {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $trimmed);
                if ($parsed !== false && $parsed->format($format) === $trimmed && $parsed->year >= 1900) {
                    return $parsed->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next supported format.
            }
        }

        return null;
    }

    protected function parseDate($value): ?string
    {
        return self::parseDateValue($value);
    }
}