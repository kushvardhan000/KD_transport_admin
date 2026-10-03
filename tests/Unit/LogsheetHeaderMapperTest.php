<?php

namespace Tests\Unit;

use App\Services\LogsheetHeaderMapper;
use PHPUnit\Framework\TestCase;

/**
 * FX-1: the header mapper is pure, so it is unit tested with no application
 * bootstrap, no database and no Excel fake.
 */
class LogsheetHeaderMapperTest extends TestCase
{
    public function test_token_collapses_case_punctuation_and_whitespace(): void
    {
        // "Invoice No." , "INVOICE_NO" and "invoiceno" are the same header.
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('Invoice No.'));
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('INVOICE_NO'));
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('  invoice   no  '));
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('Invoice-No'));
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('Invoice( No )'));
        $this->assertSame('invoiceno', LogsheetHeaderMapper::token('Invoice_No.'));

        $this->assertSame('invdate', LogsheetHeaderMapper::token('Inv- Date '));
        $this->assertSame('invoicedate', LogsheetHeaderMapper::token('Invoice Date'));
        $this->assertSame('invoicedate', LogsheetHeaderMapper::token('INVOICE DATE'));
        $this->assertSame('invdt', LogsheetHeaderMapper::token('Inv. Dt'));
        $this->assertSame('invdt', LogsheetHeaderMapper::token('Inv Dt'));

        $this->assertSame('logsheetno', LogsheetHeaderMapper::token('Log Sheet No        '));
        $this->assertSame('logsheetno', LogsheetHeaderMapper::token('LogSheet No'));
        $this->assertSame('lsno', LogsheetHeaderMapper::token('LS No.'));
        $this->assertSame('sapinvoiceno', LogsheetHeaderMapper::token('SAP_Invoice_No'));
    }

    public function test_token_never_throws_on_odd_cell_values(): void
    {
        $this->assertSame('', LogsheetHeaderMapper::token(null));
        $this->assertSame('', LogsheetHeaderMapper::token(''));
        $this->assertSame('', LogsheetHeaderMapper::token('   '));
        $this->assertSame('', LogsheetHeaderMapper::token([]));
        $this->assertSame('', LogsheetHeaderMapper::token(true));
        $this->assertSame('', LogsheetHeaderMapper::token('---'));
    }

    public function test_map_resolves_punctuated_and_reordered_headers(): void
    {
        // Deliberately reordered and punctuated; only the four required and the
        // optional canonicals must still resolve.
        $result = LogsheetHeaderMapper::map([
            'Inv- Date ',
            'Tprt Name',
            'Log Sheet No',
            'amount',
            'Invoice No.',
            'Gross Wt',
            'Date',
            'TOWN',
            'SAP_Invoice_No',
            'Tprt Code',
        ]);

        $this->assertSame([
            'inv_date' => 0,
            'tprt_name' => 1,
            'log_sheet_no' => 2,
            'amount' => 3,
            'invoice_no' => 4,
            'gross_wt' => 5,
            'date' => 6,
            'town' => 7,
            'sap_invoice_no' => 8,
            'tprt_code' => 9,
        ], $result['canonical']);
        $this->assertSame([], $result['extra']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_map_accepts_a_sheet_with_only_the_four_required_columns(): void
    {
        $result = LogsheetHeaderMapper::map(['Log Sheet No', 'Date', 'Invoice No', 'Inv Date']);

        $this->assertSame([
            'log_sheet_no' => 0,
            'date' => 1,
            'invoice_no' => 2,
            'inv_date' => 3,
        ], $result['canonical']);
        $this->assertSame([], $result['extra']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_map_reports_missing_required_columns_with_display_names(): void
    {
        $result = LogsheetHeaderMapper::map(['Log Sheet No', 'Town', 'Gross Wt']);

        $this->assertSame(['Date', 'Invoice No', 'Invoice Date'], $result['missing_required']);
        $this->assertArrayHasKey('town', $result['canonical']);
        $this->assertArrayHasKey('gross_wt', $result['canonical']);
    }

    public function test_map_preserves_the_existing_second_occurrence_semantics(): void
    {
        $result = LogsheetHeaderMapper::map([
            'Log Sheet No', 'Date', 'Invoice No', 'Inv Date',
            'Town', 'Gross Wt', 'difference', 'Diff',
            'Town', 'Gross weight',
        ]);

        $this->assertSame(4, $result['canonical']['town']);
        $this->assertSame(8, $result['canonical']['town_2']);
        $this->assertSame(5, $result['canonical']['gross_wt']);
        $this->assertSame(9, $result['canonical']['gross_wt_2']);
        $this->assertSame(6, $result['canonical']['difference_placeholder']);
        $this->assertSame(7, $result['canonical']['diff']);
    }

    public function test_map_never_overwrites_a_mapped_column(): void
    {
        // A third "Gross Wt" and a second "Diff" must not steal the index of
        // the already-mapped canonical column.
        $result = LogsheetHeaderMapper::map([
            'Log Sheet No', 'Date', 'Invoice No', 'Inv Date',
            'Gross Wt', 'Diff', 'Gross Wt', 'Diff', 'Diff',
            'Invoice No', 'Date',
        ]);

        $this->assertSame(4, $result['canonical']['gross_wt']);
        $this->assertSame(5, $result['canonical']['diff']);
        $this->assertSame(1, $result['canonical']['date']);
        $this->assertSame(2, $result['canonical']['invoice_no']);

        // The second "Gross Wt" fills gross_wt_2, the second "Diff" fills
        // difference_placeholder, and everything after that becomes an extra.
        $this->assertSame(6, $result['canonical']['gross_wt_2']);
        $this->assertSame(7, $result['canonical']['difference_placeholder']);
        $this->assertSame([
            ['key' => 'Diff (2)', 'index' => 8],
            ['key' => 'Invoice No (2)', 'index' => 9],
            ['key' => 'Date (2)', 'index' => 10],
        ], $result['extra']);
    }

    public function test_map_keeps_unmatched_columns_as_extra_keyed_by_trimmed_header(): void
    {
        $result = LogsheetHeaderMapper::map([
            'Log Sheet No', 'Date', 'Invoice No', 'Inv Date',
            '  Time  ', 'Cust Group', 'No of Packs',
        ]);

        $this->assertSame([
            ['key' => 'Time', 'index' => 4],
            ['key' => 'Cust Group', 'index' => 5],
            ['key' => 'No of Packs', 'index' => 6],
        ], $result['extra']);
        $this->assertSame([
            'Log Sheet No', 'Date', 'Invoice No', 'Inv Date', 'Time', 'Cust Group', 'No of Packs',
        ], $result['found_headers']);
    }

    public function test_map_names_blank_headers_by_column_position(): void
    {
        $result = LogsheetHeaderMapper::map([
            'Log Sheet No', '', 'Date', 'Invoice No', '   ', 'Inv Date',
        ]);

        $this->assertSame([
            ['key' => 'Column 2', 'index' => 1],
            ['key' => 'Column 5', 'index' => 4],
        ], $result['extra']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_map_deduplicates_repeated_extra_header_text(): void
    {
        $result = LogsheetHeaderMapper::map([
            'Log Sheet No', 'Date', 'Invoice No', 'Inv Date', 'Remarks', 'Remarks', 'Remarks',
        ]);

        $this->assertSame([
            ['key' => 'Remarks', 'index' => 4],
            ['key' => 'Remarks (2)', 'index' => 5],
            ['key' => 'Remarks (3)', 'index' => 6],
        ], $result['extra']);
    }

    public function test_map_tolerates_null_and_non_string_cells(): void
    {
        $result = LogsheetHeaderMapper::map([
            null, 'Log Sheet No', 'Date', 123, 'Invoice No', 'Inv Date', [],
        ]);

        $this->assertSame([
            'log_sheet_no' => 1,
            'date' => 2,
            'invoice_no' => 4,
            'inv_date' => 5,
        ], $result['canonical']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_find_header_row_finds_a_header_on_row_six_under_title_rows(): void
    {
        $rows = [
            ['Tata Motors — Log Sheet'],
            [],
            ['Generated On', '01/10/2026'],
            [' '],
            [null, null, null],
            ['Log Sheet No', 'Date', 'Invoice No', 'Inv Date'],
            ['LS-1', '2026-09-19', 'INV-1', '2026-09-19'],
        ];

        $this->assertSame(5, LogsheetHeaderMapper::findHeaderRow($rows));
    }

    public function test_find_header_row_prefers_the_first_fully_resolving_row(): void
    {
        $rows = [
            ['Log Sheet No', 'Date'],
            ['a', 'b'],
            ['Log Sheet No', 'Date', 'Invoice No', 'Inv Date'],
        ];

        $this->assertSame(2, LogsheetHeaderMapper::findHeaderRow($rows));
    }

    public function test_find_header_row_falls_back_to_the_row_with_the_most_matches(): void
    {
        $rows = [
            ['Report'],
            ['Log Sheet No', 'Date', 'Town'],
            ['nothing', 'useful'],
            ['Invoice No'],
        ];

        $this->assertSame(1, LogsheetHeaderMapper::findHeaderRow($rows));
    }

    public function test_find_header_row_returns_null_when_nothing_matches(): void
    {
        $this->assertNull(LogsheetHeaderMapper::findHeaderRow([]));
        $this->assertNull(LogsheetHeaderMapper::findHeaderRow([['a', 'b'], ['c']]));
        $this->assertNull(LogsheetHeaderMapper::findHeaderRow(['not a row', 42]));
    }

    public function test_find_header_row_honours_the_scan_limit(): void
    {
        $rows = array_fill(0, 30, ['padding row']);
        $rows[30] = ['Log Sheet No', 'Date', 'Invoice No', 'Inv Date'];

        $this->assertNull(LogsheetHeaderMapper::findHeaderRow($rows));
        $this->assertSame(30, LogsheetHeaderMapper::findHeaderRow($rows, 40));
        $this->assertSame(30, LogsheetHeaderMapper::findHeaderRow($rows, 31));
    }

    public function test_pick_sheet_never_assumes_sheet_zero(): void
    {
        $sheets = [
            ['Cover page', 'Confidential'],
            ['Instructions', 'One', 'Two'],
            [['Town', 'Route'], ['Log Sheet No', 'Date', 'Invoice No', 'Inv Date'], ['LS-1']],
        ];

        $picked = LogsheetHeaderMapper::pickSheet($sheets);

        $this->assertNotNull($picked);
        $this->assertSame(2, $picked['sheet']);
        $this->assertSame(1, $picked['header_row']);
        $this->assertArrayHasKey('log_sheet_no', $picked['mapped']['canonical']);
    }

    public function test_pick_sheet_prefers_the_first_sheet_with_all_required_columns(): void
    {
        $sheets = [
            [['Log Sheet No', 'Date', 'Town']],
            [['Log Sheet No', 'Date', 'Invoice No', 'Inv Date']],
            [['Log Sheet No', 'Date', 'Invoice No', 'Inv Date']],
        ];

        $picked = LogsheetHeaderMapper::pickSheet($sheets);

        $this->assertNotNull($picked);
        $this->assertSame(1, $picked['sheet']);
        $this->assertSame([], $picked['mapped']['missing_required']);
    }

    public function test_pick_sheet_falls_back_to_the_sheet_with_the_most_matches(): void
    {
        $sheets = [
            [['Invoice No', 'Inv Date']],
            [['Log Sheet No', 'Date', 'Invoice No', 'Town']],
        ];

        $picked = LogsheetHeaderMapper::pickSheet($sheets);

        $this->assertNotNull($picked);
        $this->assertSame(1, $picked['sheet']);
        $this->assertSame(['Invoice Date'], $picked['mapped']['missing_required']);
    }

    public function test_pick_sheet_returns_null_when_no_sheet_is_usable(): void
    {
        $this->assertNull(LogsheetHeaderMapper::pickSheet([]));
        $this->assertNull(LogsheetHeaderMapper::pickSheet([['nothing'], []]));
        $this->assertNull(LogsheetHeaderMapper::pickSheet(['a', 'b']));
    }

    public function test_every_documented_alias_maps_to_its_canonical_name(): void
    {
        $expected = [
            'log_sheet_no' => ['logsheetno', 'logsheetnumber', 'logsheetnum', 'logsheet', 'lsno', 'lsnumber'],
            'date' => ['date', 'logdate', 'logsheetdate', 'lsdate', 'tripdate'],
            'invoice_no' => ['invoiceno', 'invoicenumber', 'invoicenum', 'invno', 'invnum', 'invoice'],
            'inv_date' => ['invoicedate', 'invdate', 'invoicedt', 'invdt'],
            'gross_wt' => ['grosswt', 'grossweight', 'grosswtkg'],
            'difference_placeholder' => ['difference'],
            'diff' => ['diff'],
            'tprt_code' => ['tprtcode', 'trptcode', 'transportercode'],
            'container_id' => ['containerid', 'vehicleno', 'vehicle'],
        ];

        foreach ($expected as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $this->assertSame(
                    $canonical,
                    LogsheetHeaderMapper::tokenMap()[LogsheetHeaderMapper::token($alias)] ?? null,
                    "Alias '{$alias}' should resolve to '{$canonical}'"
                );
            }
        }
    }

    public function test_only_the_four_documented_columns_are_required(): void
    {
        $this->assertSame(
            ['log_sheet_no', 'date', 'invoice_no', 'inv_date'],
            LogsheetHeaderMapper::REQUIRED
        );
    }
}
