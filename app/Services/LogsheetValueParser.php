<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * Pure, static, never-throwing value coercion for imported spreadsheet cells.
 *
 * Contract for every method in this class:
 *  - it never throws, no matter what junk a cell contains;
 *  - it never returns a partially-cleaned, syntactically invalid number;
 *  - anything it cannot understand with certainty becomes `null`, which the
 *    importer stores as SQL NULL instead of crashing the transaction.
 *
 * The date logic here is a superset of the old
 * `LogsheetImportService::parseDateValue()`, which now delegates to
 * `date()` so every caller keeps working.
 */
class LogsheetValueParser
{
    /**
     * Maximum number of integer digits accepted, keyed by decimal scale.
     *
     * Derived from the widest real column at that scale:
     *  - scale 2 -> decimal(14,2)  (logsheet_details amounts, logsheets totals)
     *  - scale 3 -> decimal(14,3)  (logsheet_details gross_wt / volume / diff)
     *
     * A value that does not fit is rejected (`null`) rather than truncated or
     * silently clamped, so a runaway number can never become wrong money.
     *
     * @var array<int, int>
     */
    public const MAX_INTEGER_DIGITS = [
        0 => 14,
        1 => 13,
        2 => 12,
        3 => 11,
    ];

    /**
     * Lowest / highest year accepted from a date cell.
     */
    public const MIN_YEAR = 1990;

    public const MAX_YEAR = 2100;

    /**
     * Accepted Excel serial range (20000 ~ 1954-10-03, 80000 ~ 2119-01-24).
     * A date cell holding 45350959 is a log sheet number, not a date.
     */
    public const MIN_SERIAL = 20000;

    public const MAX_SERIAL = 80000;

    /**
     * Coerce a cell to a trimmed, UTF-8 safe, length-capped string.
     *
     * @param  mixed  $value
     */
    public static function text($value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = self::jsonSafe($value);
            $value = $encoded === '{}' || $encoded === '[]' ? '' : $encoded;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = self::stripInvalidUtf8($text);
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $max = max(1, $max);

        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') > $max) {
                $text = mb_substr($text, 0, $max, 'UTF-8');
            }
        } elseif (strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Coerce a cell to a fixed-scale, BCMath-safe decimal string.
     *
     * Accepts currency symbols, thousands separators, whitespace, accounting
     * parentheses "(500)" and the SAP trailing-minus "500-" as negatives, and
     * scientific notation. Everything else is `null` — including "1.2.3",
     * "12/05" and any word, so `bcadd()` can never receive garbage.
     *
     * Note: every comma is treated as a thousands separator ("1,20,000.50" ->
     * "120000.50"), which also means a mis-grouped "1,2,3" is read as 123.
     * That leniency is deliberate — real client sheets are grouped far more
     * often than they are malformed, and the strict `^-?\d+(\.\d+)?$` check
     * still rejects anything that is not one number.
     *
     * @param  mixed  $value
     */
    public static function number($value, int $scale): ?string
    {
        $scale = max(0, $scale);
        $maxIntegerDigits = self::MAX_INTEGER_DIGITS[$scale] ?? 12;

        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            if (! is_finite((float) $value)) {
                return null;
            }
            $raw = (string) $value;
        } else {
            $raw = trim((string) $value);
        }

        if ($raw === '' || ! preg_match('/\d/', $raw)) {
            return null;
        }

        // Keep a sane bound so a megabyte-long junk cell cannot reach bcmath.
        if (strlen($raw) > 64) {
            return null;
        }

        $negative = false;

        // Accounting parentheses: (500)
        if (preg_match('/^\((.*)\)$/', $raw, $m) === 1) {
            $negative = true;
            $raw = $m[1];
        }

        $raw = self::stripNumberNoise($raw);

        if ($raw === '' || $raw === '-') {
            return null;
        }

        // SAP trailing minus: 500-
        if (str_ends_with($raw, '-')) {
            $negative = true;
            $raw = substr($raw, 0, -1);
        }

        if (str_starts_with($raw, '-')) {
            $negative = true;
            $raw = substr($raw, 1);
        } elseif (str_starts_with($raw, '+')) {
            $raw = substr($raw, 1);
        }

        if ($raw === '') {
            return null;
        }

        // Scientific notation: 1.234e5
        if (preg_match('/^(\d+(?:\.\d+)?)[eE]([+-]?\d+)$/', $raw, $m) === 1) {
            $expanded = self::expandScientific($m[1], (int) $m[2]);
            if ($expanded === null) {
                return null;
            }
            $raw = $expanded;
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $m) !== 1) {
            return null;
        }

        $integer = ltrim($m[1], '0');
        if (strlen($integer) > $maxIntegerDigits) {
            return null;
        }

        $decimal = $m[2] ?? '';
        $negative = $negative && (($integer !== '') || $decimal !== '');

        $candidate = ($negative ? '-' : '').($integer === '' ? '0' : $integer)
            .($decimal === '' ? '' : '.'.$decimal);

        $rounded = self::roundToScale($candidate, $scale);

        if ($rounded === null) {
            return null;
        }

        $check = ltrim($rounded, '-');
        $dotPosition = strpos($check, '.');
        $integerPart = $dotPosition === false ? $check : substr($check, 0, $dotPosition);
        if (strlen(ltrim($integerPart, '0')) > $maxIntegerDigits) {
            return null;
        }

        return $rounded;
    }

    /**
     * Coerce a cell to `Y-m-d`, or `null` when it is not a believable date.
     *
     * @param  mixed  $value
     */
    public static function date($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return self::finishDate($value->format('Y-m-d'));
        }

        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return self::dateFromSerial((float) $value);
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }

        // Zero-ish placeholders Excel and SAP love: 0, 0.0, 00.00.0000
        if (preg_match('/^0+(\.0+)?$/', $trimmed) === 1) {
            return null;
        }
        if (in_array($trimmed, ['00.00.0000', '0000-00-00', '0000/00/00', '-'], true)) {
            return null;
        }

        if (is_numeric($trimmed)) {
            return self::dateFromSerial((float) $trimmed);
        }

        // Ordered most-specific first. Every format is round-trip verified, so
        // a value only matches when it genuinely represents that date (which is
        // what keeps 31/02/2026 out).
        $formats = [
            'Y-m-d',
            'Y-n-j',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y/m/d',
            'd.m.Y',
            'd.m.y',
            'd/m/Y',
            'd-m-Y',
            'j M Y',
            'j M y',
            'M j, Y',
            'd-M-y',
            'j-n-Y',
            'j/n/Y',
            'j-n-y',
        ];

        foreach ($formats as $format) {
            $parsed = self::createFrom($format, $trimmed);
            if ($parsed === null) {
                continue;
            }
            if ($parsed->format($format) !== $trimmed) {
                continue;
            }

            return self::finishDate($parsed->format('Y-m-d'));
        }

        // m/d/Y only as a fallback, so an ambiguous 09/06/2026 still reads as
        // 9 June (day first) rather than silently flipping to September.
        $parsed = self::createFrom('m/d/Y', $trimmed);
        if ($parsed !== null && $parsed->format('m/d/Y') === $trimmed) {
            return self::finishDate($parsed->format('Y-m-d'));
        }

        return null;
    }

    /**
     * `json_encode` that can never return false.
     *
     * @param  mixed  $value
     */
    public static function jsonSafe($value): string
    {
        $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

        if (is_string($value)) {
            $value = self::stripInvalidUtf8($value);
        }

        $encoded = json_encode($value, $flags);

        if (is_string($encoded)) {
            return $encoded;
        }

        $fallback = @json_encode(self::stripInvalidUtf8(self::stringify($value)), $flags);

        return is_string($fallback) ? $fallback : '{}';
    }

    /**
     * The single source of truth for a Log Sheet No.
     *
     * Trim, drop surrounding quotes, drop an Excel-rendered trailing ".0",
     * keep all-digit values as plain digits without leading zeros (but keep a
     * real "0"), and leave anything alphanumeric exactly as written.
     *
     * @param  mixed  $value
     */
    public static function logSheetNo($value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            if (! is_finite((float) $value)) {
                return null;
            }
            $raw = (float) $value == (int) $value ? (string) (int) $value : (string) $value;
        } else {
            $raw = trim((string) $value);
        }

        $raw = trim($raw, "\"'");
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^0+(\.0+)?$/', $raw) === 1) {
            return '0';
        }

        // 45350959.0 / 45350959.00 -> 45350959
        if (preg_match('/^(\d+)\.0+$/', $raw, $m) === 1) {
            $raw = $m[1];
        }

        if (ctype_digit($raw)) {
            $stripped = ltrim($raw, '0');

            return $stripped === '' ? '0' : $stripped;
        }

        return $raw;
    }

    private static function finishDate(?string $formatted): ?string
    {
        if ($formatted === null || $formatted === '') {
            return null;
        }

        $year = (int) substr($formatted, 0, 4);

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return $formatted;
    }

    private static function dateFromSerial(float $serial): ?string
    {
        if ($serial < self::MIN_SERIAL || $serial > self::MAX_SERIAL) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', '1899-12-30')
                ->addDays((int) floor($serial))
                ->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }

        return self::finishDate($date);
    }

    private static function createFrom(string $format, string $value): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat($format, $value);
        } catch (Throwable) {
            return null;
        }

        return $parsed === false ? null : $parsed;
    }

    private static function stripNumberNoise(string $raw): string
    {
        // Currency, "Rs", "Rs.", "INR", the rupee sign, apostrophe and
        // non-breaking space thousands separators, plus every kind of space.
        $raw = preg_replace('/(?:rs\.?|inr|usd|₹|\$|€|£)/i', '', $raw) ?? $raw;
        $raw = str_replace(["'", "\u{00A0}", "\u{202F}", '_', ','], '', $raw);
        $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;

        return $raw;
    }

    private static function expandScientific(string $mantissa, int $exponent): ?string
    {
        $integer = $mantissa;
        $fraction = '';
        $dot = strpos($mantissa, '.');
        if ($dot !== false) {
            $integer = substr($mantissa, 0, $dot);
            $fraction = substr($mantissa, $dot + 1);
        }

        if ($integer === '' && $fraction === '') {
            return null;
        }

        $digits = $integer.$fraction;
        $point = strlen($integer) + $exponent;

        if ($point <= 0) {
            return '0.'.str_repeat('0', -$point).$digits;
        }

        if ($point >= strlen($digits)) {
            return $digits.str_repeat('0', $point - strlen($digits));
        }

        return substr($digits, 0, $point).'.'.substr($digits, $point);
    }

    private static function roundToScale(string $value, int $scale): ?string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;

        // Half of the last kept place, e.g. 0.005 at scale 2 and 0.0005 at 3.
        $nudge = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $rounded = @bcadd($absolute, $nudge, $scale);

        if (! is_string($rounded)) {
            return null;
        }

        return ($negative && $rounded !== '0' ? '-' : '').$rounded;
    }

    private static function stripInvalidUtf8(string $text): string
    {
        if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        // Last resort: drop every byte that cannot start a UTF-8 sequence.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', '', $text) ?? '';
    }

    private static function stringify($value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }
}
