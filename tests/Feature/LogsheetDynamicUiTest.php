<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use App\Services\LogsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FX-3: every page must render whatever shape the imported sheet happened to
 * have — four columns or forty, sane headers or headers full of markup.
 */
class LogsheetDynamicUiTest extends TestCase
{
    use RefreshDatabase;

    protected const SCRIPT = '<script>alert(1)</script>';

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logsheet_ui_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------- builders

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function xlsx(array $rows, string $name): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        (new Xlsx($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function runImport(UploadedFile $file): LogsheetImport
    {
        $summary = app(LogsheetImportService::class)->import($file);

        return LogsheetImport::findOrFail($summary['import_id']);
    }

    /**
     * (a) Only the four required columns.
     */
    protected function minimalImport(): LogsheetImport
    {
        return $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date'],
            ['UI-MIN-1', '2026-09-19', 'INV-1', '2026-09-19'],
        ], 'minimal.xlsx'));
    }

    /**
     * (b) The full production header.
     */
    protected function fullImport(): LogsheetImport
    {
        return $this->runImport($this->xlsx([
            [
                'Log Sheet No', 'Date', 'Invoice No', 'Inv- Date', 'Payer', 'Payer Name', 'Town',
                'Gross Wt', 'difference', 'amount', 'Volume', 'Tprt Code', 'Tprt Name', 'Container ID',
                'Destination', 'SAPInvoiceNo', 'Posting Date', 'Bill Date', 'VendorInvNo', 'Route',
                'Town', 'Gross weight', 'Booked Amount', 'Actual Rate', 'Actual Amount', 'Diff',
            ],
            [
                'UI-FULL-1', '2026-09-19', 'INV-1', '2026-09-19', 'PAY-01', 'Payer One', 'Town A',
                '1600', '100', '1800', '12', 'T01', 'Transporter A', 'VH-01', 'Chennai', 'SAP-001',
                '2026-09-19', '2026-09-20', 'VIN-001', 'Route A', 'Town A', '1600', '1800', '120', '1900', '100',
            ],
        ], 'full.xlsx'));
    }

    /**
     * (c) Extras whose headers and values contain markup and special characters.
     */
    protected function hostileImport(): LogsheetImport
    {
        return $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', self::SCRIPT, 'Cust Group', 'Weird"Key', 'a&b'],
            ['UI-XSS-1', '2026-09-19', 'INV-1', '2026-09-19', self::SCRIPT, 'GROUP-A', 'quoted"value', 'amp&value'],
        ], 'hostile.xlsx'));
    }

    protected function assertPagesRender(LogsheetImport $import): array
    {
        $logsheet = Logsheet::where('last_import_id', $import->id)->firstOrFail();

        $pages = [
            'show' => route('logsheets.show', $logsheet),
            'imports.show' => route('logsheets.imports.show', $import),
            'records' => route('logsheets.records'),
            'index' => route('logsheets.index'),
        ];

        $contents = [];
        foreach ($pages as $name => $url) {
            $response = $this->get($url);
            $response->assertStatus(200);
            $content = (string) $response->getContent();
            $this->assertNoDownloadWord($content);
            $contents[$name] = $content;
        }

        return $contents;
    }

    protected function assertNoDownloadWord(string $content): void
    {
        $this->assertFalse(
            stripos($content, 'download') !== false,
            'No page may contain the word "download"'
        );
    }

    // ---------------------------------------------------------------- tests

    public function test_minimal_four_column_import_renders_every_page_and_hides_empty_columns(): void
    {
        $import = $this->minimalImport();

        $contents = $this->assertPagesRender($import);

        // The log sheet number is on the three log-sheet pages...
        foreach (['show', 'imports.show', 'records'] as $page) {
            $this->assertStringContainsString('UI-MIN-1', $contents[$page], "The {$page} page lost the log sheet number");
        }

        // ...and the imports index shows the import itself.
        $this->assertStringContainsString($import->original_filename, $contents['index']);

        $show = $contents['show'];

        // The four required details columns are always there...
        $this->assertStringContainsString('Consignment Details', $show);
        $this->assertStringContainsString('Invoice No', $show);
        $this->assertStringContainsString('Inv Date', $show);

        // ...and nothing else is, because no row has a value for them. The
        // ">Label</th>" form matches a details-table header cell specifically,
        // so the identically named summary card field cannot mask a miss.
        foreach (['TPRT Code', 'TPRT Name', 'Actual Amt', 'Booked Amt', 'Gross Wt', 'Cust Group', 'Payer Name', 'Difference', 'Route', 'Volume', 'Container', 'SAP Inv No', 'Vendor Inv'] as $hidden) {
            $this->assertStringNotContainsString('>'.$hidden.'</th>', $show, "'{$hidden}' should be hidden for a 4-column import");
        }

        // The summary card keeps the four always-present fields.
        $this->assertStringContainsString('Consignments', $show);
        $this->assertStringContainsString('Total Amount', $show);
        // ...and hides the ones with no value.
        $this->assertStringNotContainsString('Vehicle:', $show);
        $this->assertStringNotContainsString('TPRT Code:', $show);
    }

    public function test_full_import_renders_every_optional_column(): void
    {
        $import = $this->fullImport();

        $contents = $this->assertPagesRender($import);
        $show = $contents['show'];

        foreach (['Payer', 'Payer Name', 'Town', 'Gross Wt', 'Difference', 'Amount', 'Volume', 'TPRT Code', 'TPRT Name', 'Container', 'Destination', 'SAP Inv No', 'Posting', 'Bill', 'Vendor Inv', 'Route', 'Gross Wt 2', 'Booked Amt', 'Actual Rate', 'Actual Amt', 'Diff'] as $column) {
            $this->assertStringContainsString('>'.$column.'</th>', $show, "The '{$column}' column should be visible");
        }

        $this->assertStringContainsString('T01', $show);
        $this->assertStringContainsString('Chennai', $show);
        $this->assertStringContainsString('Transporter A', $show);
    }

    public function test_headers_and_values_with_markup_are_escaped_everywhere(): void
    {
        $import = $this->hostileImport();

        $contents = $this->assertPagesRender($import);

        foreach ($contents as $name => $content) {
            $this->assertStringNotContainsString(self::SCRIPT, $content, "Raw markup leaked onto the {$name} page");
        }

        // Escaped, not stripped: the value is still readable by the user.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $contents['show']);
        $this->assertStringContainsString('&amp;value', $contents['show']);
        $this->assertStringContainsString('&quot;value', str_replace('&quot;', '&quot;', $contents['show']));
    }

    public function test_blank_cells_render_as_a_dash_and_never_as_zero(): void
    {
        $import = $this->fullImport();

        $logsheet = Logsheet::where('last_import_id', $import->id)->firstOrFail();
        $seed = $logsheet->details()->firstOrFail();

        // A second row that is blank in every numeric column, the way a sparse
        // client sheet looks. The columns stay visible because the first row
        // still carries values, so their empty cells have to render as a dash.
        $sparse = $seed->replicate();
        $sparse->invoice_no = 'INV-BLANK';
        $sparse->extra_fields = null;
        foreach ([
            'time', 'cust_group', 'no_of_packs', 'payer', 'payer_name', 'town', 'town_2',
            'tprt_code', 'tprt_name', 'container_id', 'destination', 'sap_invoice_no',
            'posting_date', 'bill_date', 'vendor_inv_no', 'route', 'difference',
            'gross_wt', 'gross_weight_2', 'booked_amount', 'actual_amount', 'diff',
            'volume', 'amount', 'actual_rate', 'difference_placeholder',
        ] as $column) {
            $sparse->{$column} = null;
        }
        $sparse->save();

        $content = (string) $this->get(route('logsheets.show', $logsheet))->getContent();

        // Scope to the blank row: the populated row above it, and the summary
        // card the importer totalled, both legitimately carry numbers.
        preg_match('/<tr[^>]*>(?:(?!<\/tr>).)*INV-BLANK.*?<\/tr>/s', $this->detailsBody($content), $matches);
        $row = $matches[0] ?? '';

        $this->assertNotSame('', $row, 'The sparse row must still be rendered');
        $this->assertStringContainsString('—', $row, 'A blank cell must render as a dash');
        $this->assertStringNotContainsString('0.000', $row, 'A blank cell must not render as a number');
        $this->assertStringNotContainsString('NaN', $content);
        $this->assertStringNotContainsString('Array to string conversion', $content);
    }

    /**
     * The consignment details table body, without the raw rows table that
     * shares the same page.
     */
    protected function detailsBody(string $html): string
    {
        preg_match('/<tbody.*?<\/tbody>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_detail_and_raw_rows_are_paginated_100_per_page(): void
    {
        $import = $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'Actual Amount'],
            ['UI-PAGE-1', '2026-09-19', 'SEED', '2026-09-19', '0'],
        ], 'paged.xlsx'));

        $logsheet = Logsheet::where('last_import_id', $import->id)->firstOrFail();

        $details = [];
        $rawRows = [];
        for ($i = 1; $i <= 250; $i++) {
            $details[] = [
                'logsheet_id' => $logsheet->id,
                'log_sheet_no' => 'UI-PAGE-1',
                'date' => '2026-09-19',
                'invoice_no' => sprintf('INV-%03d', $i),
                'inv_date' => '2026-09-19',
                'actual_amount' => '10.00',
                'cleared' => false,
            ];
            $rawRows[] = [
                'import_id' => $import->id,
                'log_sheet_no' => 'UI-PAGE-1',
                'raw_data' => json_encode(['Invoice No' => sprintf('INV-%03d', $i)]),
                'row_number_in_file' => $i + 1,
                'is_valid' => true,
            ];
        }

        foreach (array_chunk($details, 100) as $chunk) {
            LogsheetDetail::insert($chunk);
        }
        foreach (array_chunk($rawRows, 100) as $chunk) {
            LogsheetRawRow::insert($chunk);
        }

        $url = route('logsheets.show', $logsheet);

        $firstPage = (string) $this->get($url)->getContent();
        $this->assertStringContainsString('251 rows', $firstPage, 'The header must show the full total, not the page size');
        $this->assertStringContainsString('Showing 1-100 of 251', $firstPage);
        $this->assertStringContainsString('INV-001', $this->detailsBody($firstPage));
        $this->assertStringNotContainsString('INV-150', $this->detailsBody($firstPage));

        $secondPage = (string) $this->get($url.'?details_page=2')->getContent();
        $this->assertStringContainsString('Showing 101-200 of 251', $secondPage);
        $this->assertStringContainsString('INV-150', $this->detailsBody($secondPage));
        $this->assertStringNotContainsString('INV-001', $this->detailsBody($secondPage));

        $lastPage = (string) $this->get($url.'?details_page=3')->getContent();
        $this->assertStringContainsString('Showing 201-251 of 251', $lastPage);
        $this->assertStringContainsString('INV-250', $this->detailsBody($lastPage));

        // The raw rows paginate independently, and the query string survives.
        $rawPage = (string) $this->get($url.'?details_page=2&raw_page=3')->getContent();
        $this->assertStringContainsString('Showing 201-251 of 251', $rawPage);
    }

    public function test_visible_columns_survive_pagination(): void
    {
        $import = $this->fullImport();

        $logsheet = Logsheet::where('last_import_id', $import->id)->firstOrFail();

        // 150 filler rows with no optional values, then the one real row: the
        // column set must come from all 151 rows, not just the visible page.
        $fillers = [];
        for ($i = 1; $i <= 150; $i++) {
            $fillers[] = [
                'logsheet_id' => $logsheet->id,
                'log_sheet_no' => 'UI-FULL-1',
                'date' => '2026-09-19',
                'invoice_no' => sprintf('FILL-%03d', $i),
                'inv_date' => '2026-09-19',
                'cleared' => false,
            ];
        }
        LogsheetDetail::insert($fillers);

        // The real row (with a Tprt Code) is pushed onto page 2.
        $content = (string) $this->get(route('logsheets.show', $logsheet).'?details_page=2')->getContent();

        $this->assertStringContainsString('TPRT Code', $content, 'A column present only on another page must still be a header');
    }

    public function test_invalid_import_page_shows_the_reason_without_anything_stored(): void
    {
        $import = $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Town'],
            ['UI-BAD-1', '2026-09-19', 'Town A'],
        ], 'invalid.xlsx'));

        $this->assertSame('invalid', $import->status);

        $content = (string) $this->get(route('logsheets.imports.show', $import))->getContent();

        $this->assertStringContainsString('Missing required columns', $content);
        $this->assertStringContainsString('Invoice No', $content);
        $this->assertStringContainsString('Nothing was stored for this file', $content);
        $this->assertNoDownloadWord($content);
    }

    public function test_warning_card_lists_the_diagnostics_of_a_partial_import(): void
    {
        $import = $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'Actual Amount'],
            ['UI-WARN-1', '2026-09-19', '', '', '100'],
            ['UI-WARN-1', 'not-a-date', 'INV-2', '2026-09-19', 'junk'],
        ], 'warnings.xlsx'));

        $content = (string) $this->get(route('logsheets.imports.show', $import))->getContent();

        $this->assertStringContainsString('Imported with warnings', $content);
        $this->assertStringContainsString('Optional columns not present in the file', $content);
        $this->assertStringContainsString('without Invoice No', $content);
        $this->assertStringContainsString('could not be read', $content);
        $this->assertNoDownloadWord($content);
    }

    public function test_index_renders_an_import_with_no_log_sheets_and_no_totals(): void
    {
        $import = $this->runImport($this->xlsx([
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date'],
            ['', '2026-09-19', 'INV-1', '2026-09-19'],
        ], 'no_sheets.xlsx'));

        $this->assertSame(0, Logsheet::count());

        $content = (string) $this->get(route('logsheets.index'))->getContent();

        $this->assertStringContainsString($import->original_filename, $content);
        $this->assertNoDownloadWord($content);
    }
}
