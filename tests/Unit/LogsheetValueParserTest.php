<?php

namespace Tests\Unit;

use App\Services\LogsheetValueParser;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * FX-1: LogsheetValueParser is pure and static, so every documented coercion
 * rule is covered here without an application bootstrap.
 */
class LogsheetValueParserTest extends TestCase
{
    // ---------------------------------------------------------------- text

    public function test_text_trims_and_returns_null_for_blank(): void
    {
        $this->assertSame('Payer One', LogsheetValueParser::text('   Payer One   '));
        $this->assertNull(LogsheetValueParser::text(null));
        $this->assertNull(LogsheetValueParser::text(''));
        $this->assertNull(LogsheetValueParser::text('     '));
        $this->assertNull(LogsheetValueParser::text(true));
    }

    public function test_text_encodes_arrays_as_json(): void
    {
        $this->assertSame('["a","b"]', LogsheetValueParser::text(['a', 'b']));
        // An empty array is a blank cell, not the two-character string "[]".
        $this->assertNull(LogsheetValueParser::text([]));
    }

    public function test_text_strips_invalid_utf8(): void
    {
        $result = LogsheetValueParser::text("bad\xB1byte");

        $this->assertIsString($result);
        $this->assertSame('bad?byte', $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function test_text_truncates_to_the_given_length(): void
    {
        $this->assertSame('abc', LogsheetValueParser::text('abcdef', 3));
        $this->assertSame('abcde', LogsheetValueParser::text('abcdef', 5));
        $this->assertSame('abcdef', LogsheetValueParser::text('abcdef', 10));
        // $max is clamped to at least one character, never to zero.
        $this->assertSame('a', LogsheetValueParser::text('abcdef', 0));
    }

    public function test_text_formats_date_objects(): void
    {
        $this->assertSame('2026-09-19', LogsheetValueParser::text(Carbon::create(2026, 9, 19, 14, 30, 0)));
    }

    // -------------------------------------------------------------- number

    public function test_number_returns_a_fixed_scale_bcmath_safe_string(): void
    {
        $this->assertSame('1600.000', LogsheetValueParser::number('1600', 3));
        $this->assertSame('1900.00', LogsheetValueParser::number(1900, 2));
        $this->assertSame('0.00', LogsheetValueParser::number('0', 2));
        $this->assertSame('0.000', LogsheetValueParser::number('0.0', 3));
        $this->assertSame('0.50', LogsheetValueParser::number('0.5', 2));
        $this->assertSame('1900', LogsheetValueParser::number('1900', 0));
    }

    public function test_number_strips_currency_symbols_spaces_and_commas(): void
    {
        $this->assertSame('120000.50', LogsheetValueParser::number('Rs. 1,20,000.50', 2));
        $this->assertSame('120000.50', LogsheetValueParser::number('Rs 1,20,000.50', 2));
        $this->assertSame('45.60', LogsheetValueParser::number('₹ 45.60', 2));
        $this->assertSame('1000.00', LogsheetValueParser::number('  1,000  ', 2));
        $this->assertSame('1234.56', LogsheetValueParser::number('1 234.56', 2));
        $this->assertSame('1000.00', LogsheetValueParser::number("1'000.00", 2));
        // Every comma is a thousands separator, so even odd grouping is read.
        $this->assertSame('123.00', LogsheetValueParser::number('1,2,3', 2));
    }

    public function test_number_understands_negative_conventions(): void
    {
        // Accounting parentheses
        $this->assertSame('-500.00', LogsheetValueParser::number('(500)', 2));
        $this->assertSame('-500.250', LogsheetValueParser::number('(500.25)', 3));
        // SAP trailing minus
        $this->assertSame('-500.00', LogsheetValueParser::number('500-', 2));
        $this->assertSame('-1200.00', LogsheetValueParser::number('1,200-', 2));
        // Ordinary leading minus
        $this->assertSame('-0.50', LogsheetValueParser::number(-0.5, 2));
        $this->assertSame('-0.50', LogsheetValueParser::number('-0.5', 2));
    }

    public function test_number_accepts_scientific_notation(): void
    {
        $this->assertSame('1200.00', LogsheetValueParser::number('1.2E3', 2));
        $this->assertSame('1200.00', LogsheetValueParser::number('1.2e3', 2));
        $this->assertSame('0.00', LogsheetValueParser::number('1e-3', 2));
        $this->assertSame('0.001', LogsheetValueParser::number('1e-3', 3));
        $this->assertSame('15.00', LogsheetValueParser::number('1.5e1', 2));
    }

    public function test_number_rounds_half_up_to_the_requested_scale(): void
    {
        // bcmath truncates, so the parser adds half of the last kept place
        // first — the same half-up result MySQL gives a decimal column.
        $this->assertSame('1.24', LogsheetValueParser::number('1.235', 2));
        $this->assertSame('1.24', LogsheetValueParser::number('1.236', 2));
        $this->assertSame('1.23', LogsheetValueParser::number('1.234', 2));
        $this->assertSame('1.235', LogsheetValueParser::number('1.2345', 3));
        $this->assertSame('2.00', LogsheetValueParser::number('1.999', 2));
        $this->assertSame('-1.24', LogsheetValueParser::number('(1.235)', 2));
        $this->assertSame('0.00', LogsheetValueParser::number('0.004', 2));
    }

    public function test_number_rejects_junk_instead_of_guessing(): void
    {
        // Each of these reached bcadd() as "1.2.3" / "1205" / "" before FX-1.
        $this->assertNull(LogsheetValueParser::number('1.2.3', 2));
        $this->assertNull(LogsheetValueParser::number('12/05', 2));
        $this->assertNull(LogsheetValueParser::number('1.2.3.4', 2));
        $this->assertNull(LogsheetValueParser::number('N/A', 2));
        $this->assertNull(LogsheetValueParser::number('-', 2));
        $this->assertNull(LogsheetValueParser::number('', 2));
        $this->assertNull(LogsheetValueParser::number(null, 2));
        $this->assertNull(LogsheetValueParser::number('.', 2));
        $this->assertNull(LogsheetValueParser::number('..', 2));
        $this->assertNull(LogsheetValueParser::number('.5', 2));
        $this->assertNull(LogsheetValueParser::number([], 2));
        $this->assertNull(LogsheetValueParser::number(true, 2));
        $this->assertNull(LogsheetValueParser::number(Carbon::now(), 2));
    }

    public function test_number_clamps_to_the_column_capacity(): void
    {
        // decimal(14,2) -> 12 integer digits; decimal(14,3) -> 11 integer digits.
        $this->assertSame('999999999999.99', LogsheetValueParser::number('999999999999.99', 2));
        $this->assertNull(LogsheetValueParser::number('1000000000000.00', 2));
        $this->assertNull(LogsheetValueParser::number('1234567890123.00', 2));
        $this->assertSame('99999999999.999', LogsheetValueParser::number('99999999999.999', 3));
        $this->assertNull(LogsheetValueParser::number('999999999999.999', 3));
    }

    public function test_number_rejects_absurdly_large_input_without_ever_reaching_bcmath(): void
    {
        $this->assertNull(LogsheetValueParser::number(str_repeat('9', 200), 2));
        $this->assertNull(LogsheetValueParser::number(INF, 2));
        $this->assertNull(LogsheetValueParser::number(NAN, 2));
    }

    public function test_number_output_is_always_a_valid_decimal_literal(): void
    {
        $samples = ['1.2.3', '12/05', 'Rs 1,20,000.50', '(500)', '500-', '1.2E3', '0', '', 'abc'];

        foreach ($samples as $sample) {
            foreach ([0, 2, 3] as $scale) {
                $result = LogsheetValueParser::number($sample, $scale);

                if ($result === null) {
                    $this->assertTrue(true);

                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/^-?\d+(\.\d+)?$/',
                    $result,
                    "'{$sample}' at scale {$scale} must never produce an invalid literal"
                );
            }
        }
    }

    // ---------------------------------------------------------------- date

    public function test_date_accepts_every_documented_format(): void
    {
        $expected = [
            '2026-09-19',
            '2026-09-19 14:30:00',
            '19.09.2026',
            '19/09/2026',
            '19-09-2026',
            '19 Sep 2026',
            'Sep 19, 2026',
            '19-Sep-26',
        ];

        foreach ($expected as $value) {
            $this->assertSame('2026-09-19', LogsheetValueParser::date($value), "Failed for '{$value}'");
        }
    }

    public function test_date_reads_slashes_day_first(): void
    {
        $this->assertSame('2026-06-09', LogsheetValueParser::date('09/06/2026'));
        $this->assertSame('2026-06-09', LogsheetValueParser::date('09.06.2026'));
        // Only when d/m is impossible does it fall back to m/d.
        $this->assertSame('2026-09-13', LogsheetValueParser::date('09/13/2026'));
    }

    public function test_date_accepts_excel_serials_inside_the_plausible_range(): void
    {
        $this->assertSame('2026-05-30', LogsheetValueParser::date(46172));
        $this->assertSame('2026-05-30', LogsheetValueParser::date(46172.0));
        $this->assertSame('2026-05-30', LogsheetValueParser::date('46172'));
    }

    public function test_date_rejects_numbers_outside_the_excel_serial_range(): void
    {
        // A log sheet number landing in a date column is not a date.
        $this->assertNull(LogsheetValueParser::date('45350959'));
        $this->assertNull(LogsheetValueParser::date(19999));
        $this->assertNull(LogsheetValueParser::date(80001));
        $this->assertNull(LogsheetValueParser::date(0));
    }

    public function test_date_rejects_zero_placeholders_and_garbage(): void
    {
        $this->assertNull(LogsheetValueParser::date(null));
        $this->assertNull(LogsheetValueParser::date(''));
        $this->assertNull(LogsheetValueParser::date('   '));
        $this->assertNull(LogsheetValueParser::date('0'));
        $this->assertNull(LogsheetValueParser::date('0.0'));
        $this->assertNull(LogsheetValueParser::date('00.00.0000'));
        $this->assertNull(LogsheetValueParser::date('0000-00-00'));
        $this->assertNull(LogsheetValueParser::date('not-a-date'));
        $this->assertNull(LogsheetValueParser::date('31/02/2026'));
        $this->assertNull(LogsheetValueParser::date('2026-13-45'));
        $this->assertNull(LogsheetValueParser::date([]));
        $this->assertNull(LogsheetValueParser::date(true));
    }

    public function test_date_enforces_the_business_year_range(): void
    {
        $this->assertNull(LogsheetValueParser::date('1899-12-31'));
        $this->assertNull(LogsheetValueParser::date('1989-12-31'));
        $this->assertSame('1990-01-01', LogsheetValueParser::date('1990-01-01'));
        $this->assertSame('2100-12-31', LogsheetValueParser::date('2100-12-31'));
        $this->assertNull(LogsheetValueParser::date('2101-01-01'));
        $this->assertNull(LogsheetValueParser::date('9999-01-01'));
    }

    public function test_date_accepts_date_objects(): void
    {
        $this->assertSame('2026-09-19', LogsheetValueParser::date(Carbon::create(2026, 9, 19, 8, 0, 0)));
    }

    // ------------------------------------------------------------ jsonSafe

    public function test_json_safe_never_returns_false(): void
    {
        $this->assertSame('{"a":"b"}', LogsheetValueParser::jsonSafe(['a' => 'b']));
        $this->assertSame('[]', LogsheetValueParser::jsonSafe([]));
        $this->assertSame('{}', LogsheetValueParser::jsonSafe(new \stdClass));

        $invalid = LogsheetValueParser::jsonSafe(['a' => "bad\xB1byte"]);
        $this->assertIsString($invalid);
        $this->assertStringStartsWith('{"a":"bad', $invalid);

        $nested = LogsheetValueParser::jsonSafe(['a' => ["\xB1\xB1"], 'b' => "\x00"]);
        $this->assertIsString($nested);
    }

    // ------------------------------------------------------------ logSheetNo

    public function test_log_sheet_no_is_the_single_normalisation_source(): void
    {
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('0045350959'));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('  0045350959 '));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('45350959.0'));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('45350959.00'));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo(45350959.0));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo(45350959));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('"45350959"'));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo("'45350959'"));
    }

    public function test_log_sheet_no_keeps_a_real_zero(): void
    {
        $this->assertSame('0', LogsheetValueParser::logSheetNo('0'));
        $this->assertSame('0', LogsheetValueParser::logSheetNo('00000'));
        $this->assertSame('0', LogsheetValueParser::logSheetNo('0.0'));
    }

    public function test_log_sheet_no_leaves_alphanumerics_untouched(): void
    {
        $this->assertSame('LS-1001', LogsheetValueParser::logSheetNo('LS-1001'));
        $this->assertSame('LS-1001', LogsheetValueParser::logSheetNo(' LS-1001 '));
        $this->assertSame('AB0123', LogsheetValueParser::logSheetNo('AB0123'));
        $this->assertNull(LogsheetValueParser::logSheetNo(''));
        $this->assertNull(LogsheetValueParser::logSheetNo('   '));
        $this->assertNull(LogsheetValueParser::logSheetNo(null));
        $this->assertNull(LogsheetValueParser::logSheetNo([]));
    }

    public function test_log_sheet_no_agrees_with_the_clearing_service_normalisation(): void
    {
        // The clearing service already produced "45350959" for this input; the
        // import path must produce exactly the same key or clear would miss.
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('0045350959'));
        $this->assertSame('45350959', LogsheetValueParser::logSheetNo('45350959.0'));
        $this->assertSame('0', LogsheetValueParser::logSheetNo('0.0'));
    }
}
