<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Drives the *rendered* primary export control (form on records, link on import detail),
 * so these tests exercise the same request a user would make.
 */
class LogsheetExportFilterMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        'Log Sheet No', 'Date', 'Vehicle No', 'TPRT Code', 'TPRT Name', 'Destination',
        'SAP Invoice No', 'Posting Date', 'Bill Date', 'Vendor Inv No', 'Consignments',
        'Gross Wt', 'Booked Amount', 'Total Amount', 'Diff', 'Status', 'Cleared At',
        'Cleared By', 'Import File', 'Import Period',
    ];

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($this->superAdmin);
    }

    private function makeImport(string $filename): LogsheetImport
    {
        return LogsheetImport::create([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'original_filename' => $filename,
            'file_path' => 'logsheet_imports/'.$filename,
            'uploaded_by' => $this->superAdmin->id,
            'row_count' => 0,
            'consolidated_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '0.00',
            'total_booked_amount' => '0.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '0.000',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
        ]);
    }

    /**
     * Rows chosen so every filter dimension actually discriminates:
     *  pending : P1 June/100, P2 June/200, P3 August/300
     *  cleared : C1 June/400, C2 July/500,  C3 September/600
     */
    private function makeRow(LogsheetImport $import, string $number, string $date, string $amount, string $status): Logsheet
    {
        return Logsheet::create([
            'log_sheet_no' => $number,
            'date' => $date,
            'vehicle_no' => 'VH-'.$number,
            'tprt_code' => '00'.$status[0],
            'tprt_name' => 'Transporter '.$number,
            'destination' => 'Chennai',
            'sap_invoice_no' => 'SAP-'.$number,
            'posting_date' => $date,
            'bill_date' => $date,
            'vendor_inv_no' => 'VEN-'.$number,
            'total_gross_wt' => '1500.500',
            'total_booked_amount' => $amount,
            'total_actual_amount' => $amount,
            'total_diff' => '0.00',
            'consignment_count' => 3,
            'status' => $status,
            'cleared_at' => $status === 'cleared' ? '2026-09-29 08:30:00' : null,
            'cleared_by' => $status === 'cleared' ? $this->superAdmin->id : null,
            'last_import_id' => $import->id,
        ]);
    }

    private function seedAll(): LogsheetImport
    {
        $import = $this->makeImport('matrix.xlsx');

        $this->makeRow($import, '0000000011', '2026-06-05', '100.00', 'pending');
        $this->makeRow($import, '0000000022', '2026-06-20', '200.00', 'pending');
        $this->makeRow($import, '0000000033', '2026-08-10', '300.00', 'pending');
        $this->makeRow($import, '0000000044', '2026-06-11', '400.00', 'cleared');
        $this->makeRow($import, '0000000055', '2026-07-15', '500.00', 'cleared');
        $this->makeRow($import, '0000000066', '2026-09-01', '600.00', 'cleared');

        return $import;
    }

    private function loadWorkbook($response)
    {
        $base = $response->baseResponse ?? $response;
        $path = null;

        if ($base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $file = $base->getFile();
            $path = method_exists($file, 'getPathname') ? $file->getPathname() : null;
        }

        if ($path === null || ! is_file($path)) {
            $path = tempnam(sys_get_temp_dir(), 'lsx').'.xlsx';
            file_put_contents($path, (string) $response->getContent());
        }

        return IOFactory::load($path);
    }

    /** The URL the rendered "Export current view" form on /logsheets/records would submit. */
    private function recordsExportAction(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        foreach ((new \DOMXPath($dom))->query('//form') as $form) {
            if ($form->getAttribute('action') !== route('logsheets.export')) {
                continue;
            }

            $params = [];

            foreach ((new \DOMXPath($dom))->query('.//input[@type="hidden"]', $form) as $input) {
                $params[$input->getAttribute('name')] = $input->getAttribute('value');
            }

            return route('logsheets.export').($params !== [] ? '?'.http_build_query($params) : '');
        }

        $this->fail('Export current view form not found.');
    }

    /** The href of the rendered primary export link on /logsheets/imports/{id}. */
    private function importExportAction(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        foreach ((new \DOMXPath($dom))->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');

            if (str_contains($href, 'logsheets.imports.export') || str_contains($href, '/imports/')) {
                if (str_contains($href, '/export') && ! str_contains($href, 'force_status=')) {
                    return $href;
                }
            }
        }

        $this->fail('Primary export link not found.');
    }

    /** @return array<int, string> log sheet numbers of the data rows on a sheet */
    private function numbers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $out = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $out[] = (string) $sheet->getCell('A'.$row)->getValue();
        }

        return $out;
    }

    /** Asserts the exported sheet carries the unchanged column layout and formats. */
    private function assertWorkbookShapeUnchanged(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        for ($i = 0; $i < count(self::HEADINGS); $i++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $this->assertSame(self::HEADINGS[$i], $sheet->getCell($column.'1')->getValue());
        }

        if ($sheet->getHighestRow() < 2) {
            return;
        }

        $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('J2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.000', $sheet->getStyle('L2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('M2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('N2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('O2')->getNumberFormat()->getFormatCode());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    // (a) records: status=pending + date range + log_sheet_no + amount range together
    public function test_a_records_pending_filter_with_all_other_filters_yields_one_pending_sheet(): void
    {
        $this->seedAll();

        $filters = [
            'status' => 'pending',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'log_sheet_no' => '0000000022',
            'min_amount' => '150',
            'max_amount' => '250',
        ];

        $table = $this->get(route('logsheets.records', $filters));
        $table->assertStatus(200);
        $this->assertSame(1, $table->viewData('logsheets')->total());

        $response = $this->get($this->recordsExportAction($table->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Pending');
        $this->assertWorkbookShapeUnchanged($sheet);
        $this->assertSame(['0000000022'], $this->numbers($sheet));
        $this->assertSame('pending', $sheet->getCell('P2')->getValue());
        $this->assertSame('2026-06-20', $sheet->getCell('B2')->getValue());
        $this->assertSame(200.0, $sheet->getCell('N2')->getValue());
    }

    // (b) records: status=cleared + all other filters together
    public function test_b_records_cleared_filter_with_all_other_filters_yields_one_cleared_sheet(): void
    {
        $this->seedAll();

        $filters = [
            'status' => 'cleared',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'log_sheet_no' => '0000000044',
            'min_amount' => '300',
            'max_amount' => '500',
        ];

        $table = $this->get(route('logsheets.records', $filters));
        $table->assertStatus(200);
        $this->assertSame(1, $table->viewData('logsheets')->total());

        $response = $this->get($this->recordsExportAction($table->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Cleared');
        $this->assertWorkbookShapeUnchanged($sheet);
        $this->assertSame(['0000000044'], $this->numbers($sheet));
        $this->assertSame('cleared', $sheet->getCell('P2')->getValue());
        $this->assertSame('2026-09-29 08:30', $sheet->getCell('Q2')->getValue());
        $this->assertSame($this->superAdmin->name, $sheet->getCell('R2')->getValue());
    }

    // (c) records: no status filter -> both sheets, workbook unchanged
    public function test_c_records_without_status_filter_yields_both_sheets_unchanged(): void
    {
        $this->seedAll();

        $table = $this->get(route('logsheets.records'));
        $table->assertStatus(200);
        $this->assertSame(6, $table->viewData('logsheets')->total());

        $response = $this->get($this->recordsExportAction($table->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());

        $pending = $workbook->getSheetByName('Pending');
        $cleared = $workbook->getSheetByName('Cleared');

        $this->assertWorkbookShapeUnchanged($pending);
        $this->assertWorkbookShapeUnchanged($cleared);

        $pendingNumbers = $this->numbers($pending);
        $clearedNumbers = $this->numbers($cleared);

        sort($pendingNumbers);
        sort($clearedNumbers);

        $this->assertSame(['0000000011', '0000000022', '0000000033'], $pendingNumbers);
        $this->assertSame(['0000000044', '0000000055', '0000000066'], $clearedNumbers);
        $this->assertCount($table->viewData('logsheets')->total(), array_merge($pendingNumbers, $clearedNumbers));

        // Leading zeros preserved as text, amounts numeric, dates as Y-m-d strings.
        // Rows are ordered date desc, so the newest pending row (2026-08-10) is first.
        $this->assertSame('0000000033', $pending->getCell('A2')->getValue());
        $this->assertSame('2026-08-10', $pending->getCell('B2')->getValue());
        $this->assertSame(1500.5, $pending->getCell('L2')->getValue());
        $this->assertSame(300.0, $pending->getCell('N2')->getValue());
        $this->assertSame(3, $pending->getCell('K2')->getValue());
        $this->assertSame('matrix.xlsx', $pending->getCell('S2')->getValue());
        $this->assertSame('2026-01-01 to 2026-12-31', $pending->getCell('T2')->getValue());
        // Pending rows have no clearing metadata; the cells are written blank.
        $this->assertEmpty($pending->getCell('Q2')->getValue());
        $this->assertEmpty($pending->getCell('R2')->getValue());

        // Cleared sheet carries the cleared metadata for its newest row (2026-09-01).
        $this->assertSame('0000000066', $cleared->getCell('A2')->getValue());
        $this->assertSame('cleared', $cleared->getCell('P2')->getValue());
        $this->assertSame('2026-09-29 08:30', $cleared->getCell('Q2')->getValue());
        $this->assertSame($this->superAdmin->name, $cleared->getCell('R2')->getValue());
    }

    // (d) import detail: status=completed -> single Cleared sheet, only this import's cleared rows
    public function test_d_import_completed_filter_yields_one_cleared_sheet(): void
    {
        $importA = $this->seedAll();
        $importB = $this->makeImport('other.xlsx');
        $this->makeRow($importB, '0000000099', '2026-06-11', '400.00', 'cleared');

        $page = $this->get(route('logsheets.imports.show', ['import' => $importA, 'status' => 'completed']));
        $page->assertStatus(200);
        $this->assertSame(3, $page->viewData('logsheets')->total());

        $response = $this->get($this->importExportAction($page->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Cleared');
        $this->assertWorkbookShapeUnchanged($sheet);

        $numbers = $this->numbers($sheet);
        sort($numbers);
        $this->assertSame(['0000000044', '0000000055', '0000000066'], $numbers);
        $this->assertNotContains('0000000099', $numbers, 'Other import rows must never appear');
    }

    // (e) import detail: status=pending -> single Pending sheet
    public function test_e_import_pending_filter_yields_one_pending_sheet(): void
    {
        $importA = $this->seedAll();
        $importB = $this->makeImport('other.xlsx');
        $this->makeRow($importB, '0000000099', '2026-06-20', '200.00', 'pending');

        $page = $this->get(route('logsheets.imports.show', ['import' => $importA, 'status' => 'pending']));
        $page->assertStatus(200);
        $this->assertSame(3, $page->viewData('logsheets')->total());

        $response = $this->get($this->importExportAction($page->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        $numbers = $this->numbers($workbook->getSheetByName('Pending'));
        sort($numbers);
        $this->assertSame(['0000000011', '0000000022', '0000000033'], $numbers);
        $this->assertNotContains('0000000099', $numbers);
    }

    // (f) import detail: no status filter -> both sheets, scoped to this import only
    public function test_f_import_without_status_filter_yields_both_sheets_scoped_to_import(): void
    {
        $importA = $this->seedAll();
        $importB = $this->makeImport('other.xlsx');
        $this->makeRow($importB, '0000000099', '2026-06-20', '200.00', 'pending');

        $page = $this->get(route('logsheets.imports.show', $importA));
        $page->assertStatus(200);
        $this->assertSame(6, $page->viewData('logsheets')->total());

        $response = $this->get($this->importExportAction($page->getContent()));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());

        $pending = $this->numbers($workbook->getSheetByName('Pending'));
        $cleared = $this->numbers($workbook->getSheetByName('Cleared'));

        $this->assertCount(3, $pending);
        $this->assertCount(3, $cleared);
        $this->assertCount($page->viewData('logsheets')->total(), array_merge($pending, $cleared));
        $this->assertNotContains('0000000099', array_merge($pending, $cleared));
        $this->assertWorkbookShapeUnchanged($workbook->getSheetByName('Pending'));
        $this->assertWorkbookShapeUnchanged($workbook->getSheetByName('Cleared'));
    }

    // (g) explicit overrides behave independently of the on-page filter
    public function test_g_records_overrides_ignore_the_on_page_status_filter(): void
    {
        $this->seedAll();

        $page = $this->get(route('logsheets.records', ['status' => 'cleared']));
        $page->assertStatus(200);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);

        $links = [];
        foreach ($xpath->query('//a[contains(@href, "force_scope=")]') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $params);
            $links[$params['force_scope']] = $anchor->getAttribute('href');
        }

        $this->assertSame(['all', 'pending', 'cleared'], array_keys($links));

        // "Pending only" while the table shows Cleared.
        $response = $this->get($links['pending']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Pending')));

        // "All (2 sheets)" while the table shows Cleared.
        $response = $this->get($links['all']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Pending')));
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Cleared')));

        // "Cleared only" agrees with the table in this case.
        $response = $this->get($links['cleared']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Cleared')));
    }

    public function test_g_records_legacy_scope_param_still_works(): void
    {
        $this->seedAll();

        $this->assertSame(
            ['Pending', 'Cleared'],
            $this->loadWorkbook($this->get(route('logsheets.export', ['scope' => 'all'])))->getSheetNames()
        );
        $this->assertSame(
            ['Pending'],
            $this->loadWorkbook($this->get(route('logsheets.export', ['scope' => 'pending'])))->getSheetNames()
        );
        $this->assertSame(
            ['Cleared'],
            $this->loadWorkbook($this->get(route('logsheets.export', ['scope' => 'cleared'])))->getSheetNames()
        );
    }

    public function test_g_import_overrides_ignore_the_on_page_status_filter(): void
    {
        $importA = $this->seedAll();

        $page = $this->get(route('logsheets.imports.show', ['import' => $importA, 'status' => 'completed']));
        $page->assertStatus(200);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);

        $links = [];
        foreach ($xpath->query('//a[contains(@href, "force_status=")]') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $params);
            $links[$params['force_status']] = $anchor->getAttribute('href');
        }

        $this->assertSame(['all', 'completed', 'pending'], array_keys($links));

        // "Pending only" while the table shows Completed.
        $response = $this->get($links['pending']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Pending')));

        // "All (2 sheets)" while the table shows Completed.
        $response = $this->get($links['all']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Pending')));
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Cleared')));

        // "Completed only" agrees with the table in this case.
        $response = $this->get($links['completed']);
        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertCount(3, $this->numbers($workbook->getSheetByName('Cleared')));
    }

    public function test_g_import_status_param_still_works(): void
    {
        $import = $this->seedAll();

        $this->assertSame(
            ['Cleared'],
            $this->loadWorkbook($this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'completed'])))->getSheetNames()
        );
        $this->assertSame(
            ['Pending'],
            $this->loadWorkbook($this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending'])))->getSheetNames()
        );
        $this->assertSame(
            ['Pending', 'Cleared'],
            $this->loadWorkbook($this->get(route('logsheets.imports.export', $import)))->getSheetNames()
        );
    }
}
