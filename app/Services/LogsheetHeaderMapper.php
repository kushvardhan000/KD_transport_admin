<?php

namespace App\Services;

/**
 * Pure, dependency-free translation of a raw spreadsheet header row into
 * canonical column names.
 *
 * Nothing in here touches the database, the filesystem or Laravel, so every
 * rule below is unit-testable in isolation and can be reasoned about without
 * running an import.
 *
 * The whole point of this class is tolerance: a client workbook may spell a
 * column `Invoice No`, `Invoice No.`, `INVOICE_NO` or `Inv- Date ` and all of
 * those must resolve to the same canonical key. That is achieved by reducing
 * every header to a *token* (lowercase, all non-alphanumerics removed) and
 * comparing tokens instead of text.
 */
class LogsheetHeaderMapper
{
    /**
     * The only columns a workbook must provide to be importable at all.
     *
     * @var array<int, string>
     */
    public const REQUIRED = ['log_sheet_no', 'date', 'invoice_no', 'inv_date'];

    /**
     * Human readable names for the required columns, used in diagnostics.
     *
     * @var array<string, string>
     */
    public const REQUIRED_LABELS = [
        'log_sheet_no' => 'Log Sheet No',
        'date' => 'Date',
        'invoice_no' => 'Invoice No',
        'inv_date' => 'Invoice Date',
    ];

    /**
     * Canonical column name => list of accepted tokens.
     *
     * A canonical's own name is *always* accepted as a token too, so this list
     * only needs the extra spellings. One line per canonical: adding support
     * for a new client spelling is a one-line edit here and nowhere else.
     *
     * Adding a line here is safe — an unknown token never rejects a file, it
     * simply lands in `extra` and is preserved in extra_fields / raw_data.
     *
     * @var array<string, array<int, string>>
     */
    public const ALIASES = [
        // --- required -------------------------------------------------
        'log_sheet_no' => ['logsheetno', 'logsheetnumber', 'logsheetnum', 'logsheet', 'lsno', 'lsnumber'],
        'date' => ['date', 'logdate', 'logsheetdate', 'lsdate', 'tripdate'],
        'invoice_no' => ['invoiceno', 'invoicenumber', 'invoicenum', 'invno', 'invnum', 'invoice'],
        'inv_date' => ['invoicedate', 'invdate', 'invoicedt', 'invdt'],
        // --- optional -------------------------------------------------
        'payer' => ['payer'],
        'payer_name' => ['payername'],
        'town' => ['town'],
        'gross_wt' => ['grosswt', 'grossweight', 'grosswtkg'],
        'difference_placeholder' => ['difference'],
        'diff' => ['diff'],
        'amount' => ['amount'],
        'volume' => ['volume'],
        'tprt_code' => ['tprtcode', 'trptcode', 'transportercode'],
        'tprt_name' => ['tprtname'],
        'container_id' => ['containerid', 'vehicleno', 'vehicle'],
        'destination' => ['destination'],
        'sap_invoice_no' => ['sapinvoiceno'],
        'posting_date' => ['postingdate'],
        'bill_date' => ['billdate'],
        'vendor_inv_no' => ['vendorinvno'],
        'route' => ['route'],
        'booked_amount' => ['bookedamount'],
        'actual_rate' => ['actualrate'],
        'actual_amount' => ['actualamount'],
    ];

    /**
     * Canonical names that get a dedicated "second occurrence" slot, matching
     * the pre-existing import semantics: real client sheets carry two Town
     * columns, two Gross Wt columns and a `difference` before the real `Diff`.
     *
     * @var array<string, string>
     */
    public const SECOND_OCCURRENCE = [
        'town' => 'town_2',
        'gross_wt' => 'gross_wt_2',
        'diff' => 'difference_placeholder',
    ];

    /**
     * @var array<string, string>|null
     */
    private static ?array $tokenMap = null;

    /**
     * Reduce a raw header cell to a comparable token.
     *
     * "Invoice No." , "INVOICE_NO" and "Inv- Date " therefore compare cleanly
     * against "invoiceno" and "invdate" respectively.
     *
     * @param  mixed  $header
     */
    public static function token($header): string
    {
        if ($header === null || is_array($header) || is_object($header)) {
            return '';
        }

        if (is_bool($header)) {
            return '';
        }

        $text = trim((string) $header);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        $token = preg_replace('/[^a-z0-9]+/', '', $text);

        return is_string($token) ? $token : '';
    }

    /**
     * Token => canonical name. The canonical's own name is included implicitly.
     *
     * @return array<string, string>
     */
    public static function tokenMap(): array
    {
        if (self::$tokenMap !== null) {
            return self::$tokenMap;
        }

        $map = [];
        foreach (self::ALIASES as $canonical => $aliases) {
            $map[self::token($canonical)] = $canonical;
            foreach ($aliases as $alias) {
                $token = self::token($alias);
                if ($token === '') {
                    continue;
                }
                // First definition wins so a later line can never steal a
                // token that an earlier canonical already owns.
                $map[$token] ??= $canonical;
            }
        }

        return self::$tokenMap = $map;
    }

    /**
     * Map one header row.
     *
     * Never overwrites an already-mapped column: a repeated canonical is
     * preserved as an extra column keyed "<Header> (2)" so no data is lost.
     * Blank headers become "Column N". Anything unrecognised is kept as an
     * extra column keyed by its trimmed original text.
     *
     * @param  array<int|string, mixed>  $headerRow
     * @return array{
     *     canonical: array<string, int>,
     *     extra: array<int, array{key: string, index: int}>,
     *     missing_required: array<int, string>,
     *     found_headers: array<int, string>
     * }
     */
    public static function map(array $headerRow): array
    {
        $headerRow = array_values($headerRow);

        $canonical = [];
        $extra = [];
        $foundHeaders = [];
        $extraSeen = [];
        $secondSeen = [];
        $tokenMap = self::tokenMap();

        foreach ($headerRow as $index => $header) {
            $rawText = self::headerText($header);
            if ($rawText !== '') {
                $foundHeaders[] = $rawText;
            }

            $key = self::token($header);
            $target = $tokenMap[$key] ?? null;

            if ($target === null) {
                $extra[] = self::extraEntry(
                    $rawText !== '' ? $rawText : 'Column '.($index + 1),
                    $index,
                    $extraSeen
                );

                continue;
            }

            $slot = $target;
            if (isset(self::SECOND_OCCURRENCE[$target])) {
                if (isset($secondSeen[$target])) {
                    $slot = self::SECOND_OCCURRENCE[$target];
                }
                $secondSeen[$target] = true;
            }

            if (isset($canonical[$slot])) {
                // Third occurrence of anything (and any slot already taken):
                // keep the data as an extra column instead of overwriting.
                $extra[] = self::extraEntry(
                    $rawText !== '' ? $rawText.' (2)' : 'Column '.($index + 1).' (2)',
                    $index,
                    $extraSeen
                );

                continue;
            }

            $canonical[$slot] = $index;
        }

        $missing = [];
        foreach (self::REQUIRED as $required) {
            if (! isset($canonical[$required])) {
                $missing[] = self::REQUIRED_LABELS[$required] ?? $required;
            }
        }

        return [
            'canonical' => $canonical,
            'extra' => $extra,
            'missing_required' => $missing,
            'found_headers' => $foundHeaders,
        ];
    }

    /**
     * Locate the header row inside a sheet.
     *
     * Wins the first row that resolves all four required columns; otherwise
     * falls back to the row with the most required matches (needs at least
     * one); otherwise null. The returned index is positional within `$rows`.
     *
     * @param  array<int|string, mixed>  $rows
     */
    public static function findHeaderRow(array $rows, int $scan = 30): ?int
    {
        $rows = array_values($rows);
        $limit = min(count($rows), max(0, $scan));
        $bestIndex = null;
        $bestScore = 0;

        for ($i = 0; $i < $limit; $i++) {
            $row = $rows[$i];
            if (! is_array($row)) {
                continue;
            }

            $mapped = self::map($row);
            $score = count(array_intersect(self::REQUIRED, array_keys($mapped['canonical'])));

            if ($score === count(self::REQUIRED)) {
                return $i;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }

        return $bestScore > 0 ? $bestIndex : null;
    }

    /**
     * Choose the sheet to import from. Sheet 0 is never assumed.
     *
     * @param  array<int|string, mixed>  $sheets
     * @return array{sheet: int, header_row: int, mapped: array}|null
     */
    public static function pickSheet(array $sheets): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach (array_values($sheets) as $sheetIndex => $sheetRows) {
            if (! is_array($sheetRows)) {
                continue;
            }

            $rows = array_values($sheetRows);
            $headerRow = self::findHeaderRow($rows);
            if ($headerRow === null) {
                continue;
            }

            $mapped = self::map($rows[$headerRow]);
            $score = count(self::REQUIRED) - count($mapped['missing_required']);

            if ($score === count(self::REQUIRED)) {
                return [
                    'sheet' => $sheetIndex,
                    'header_row' => $headerRow,
                    'mapped' => $mapped,
                ];
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'sheet' => $sheetIndex,
                    'header_row' => $headerRow,
                    'mapped' => $mapped,
                ];
            }
        }

        return $best;
    }

    /**
     * Trimmed header text, or '' for anything that is not a scalar cell.
     *
     * @param  mixed  $header
     */
    private static function headerText($header): string
    {
        if ($header === null || is_array($header) || is_object($header) || is_bool($header)) {
            return '';
        }

        return trim((string) $header);
    }

    /**
     * @param  array<string, bool>  $seen
     * @return array{key: string, index: int}
     */
    private static function extraEntry(string $key, int $index, array &$seen): array
    {
        $base = $key;
        $suffix = 2;
        while (isset($seen[$key])) {
            $key = $base.' ('.$suffix.')';
            $suffix++;
        }
        $seen[$key] = true;

        return ['key' => $key, 'index' => $index];
    }
}
