<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Edge cases, authorisation, filenames, query-string preservation and form-flow regressions
 * for the filter-coupled Logsheet exports.
 */
class LogsheetExportEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<int, \PhpOffice\PhpSpreadsheet\Spreadsheet> */
    private array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($this->superAdmin);
    }

    /**
     * Parsed workbooks and their temp files are released eagerly so the whole suite stays
     * inside the 128M PHP memory limit.
     */
    protected function tearDown(): void
    {
        foreach ($this->workbooks as $workbook) {
            $workbook->disconnectWorksheets();
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->workbooks = [];
        $this->tempFiles = [];

        gc_collect_cycles();

        parent::tearDown();
    }

    private function makeImport(array $overrides = []): LogsheetImport
    {
        return LogsheetImport::create(array_merge([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'original_filename' => 'edge.xlsx',
            'file_path' => 'logsheet_imports/edge.xlsx',
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
        ], $overrides));
    }

    private function makeRow(LogsheetImport $import, string $number, string $status, string $date, string $amount, array $extra = []): Logsheet
    {
        return Logsheet::create(array_merge([
            'log_sheet_no' => $number,
            'date' => $date,
            'vehicle_no' => 'VH-'.$number,
            'tprt_code' => '007',
            'tprt_name' => 'Transporter '.$number,
            'destination' => 'Chennai',
            'sap_invoice_no' => 'SAP-000123',
            'posting_date' => $date,
            'bill_date' => $date,
            'vendor_inv_no' => 'VEN-0009',
            'total_gross_wt' => '1500.500',
            'total_booked_amount' => $amount,
            'total_actual_amount' => $amount,
            'total_diff' => '0.00',
            'consignment_count' => 3,
            'status' => $status,
            'last_import_id' => $import->id,
        ], $extra));
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
            $this->tempFiles[] = $path;
            file_put_contents($path, (string) $response->getContent());
        }

        $workbook = IOFactory::load($path);
        $this->workbooks[] = $workbook;

        return $workbook;
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    private function recordsExportAction(string $html): string
    {
        $xpath = $this->dom($html);

        foreach ($xpath->query('//form') as $form) {
            if ($form->getAttribute('action') !== route('logsheets.export')) {
                continue;
            }

            $params = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
                $params[$input->getAttribute('name')] = $input->getAttribute('value');
            }

            return route('logsheets.export').($params !== [] ? '?'.http_build_query($params) : '');
        }

        $this->fail('Export current view form not found.');
    }

    private function importExportAction(string $html): string
    {
        foreach ($this->dom($html)->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');

            if (str_contains($href, '/export') && ! str_contains($href, 'force_status=')) {
                return $href;
            }
        }

        $this->fail('Primary export link not found.');
    }

    private function numbers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $out = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $out[] = (string) $sheet->getCell('A'.$row)->getValue();
        }

        return $out;
    }

    // ---------------------------------------------------------------- h. edge cases

    public function test_h_zero_matching_rows_returns_headers_only_single_sheet(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, '0045350959', 'pending', '2026-06-01', '100.00');

        $response = $this->get(route('logsheets.export', ['status' => 'cleared']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Cleared');
        $this->assertSame(1, $sheet->getHighestRow());
        $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());
        $this->assertSame('Import Period', $sheet->getCell('T1')->getValue());

        // Import detail behaves the same.
        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'completed']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('Cleared')->getHighestRow());
    }

    public function test_h_filter_matching_only_one_status_returns_rows_for_that_one_only(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');
        $this->makeRow($import, 'C000001', 'cleared', '2026-06-01', '200.00');

        // A filter that matches only the cleared row: pending yields headers only.
        $response = $this->get(route('logsheets.export', [
            'status' => 'pending',
            'log_sheet_no' => 'C000001',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('Pending')->getHighestRow());

        // Same filter with status=cleared yields the row.
        $response = $this->get(route('logsheets.export', [
            'status' => 'cleared',
            'log_sheet_no' => 'C000001',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(['C000001'], $this->numbers($workbook->getSheetByName('Cleared')));

        // And with no status filter the pending sheet is empty while cleared has the row.
        $response = $this->get(route('logsheets.export', ['log_sheet_no' => 'C000001']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(1, $workbook->getSheetByName('Pending')->getHighestRow());
        $this->assertSame(['C000001'], $this->numbers($workbook->getSheetByName('Cleared')));
    }

    public function test_h_invalid_values_fail_validation_with_session_errors_and_no_500(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        // Note: `force_status` is deliberately absent here — it is an import-export-only
        // parameter, and the records endpoint simply ignores unknown query keys.
        $invalid = [
            ['status' => 'bogus'],
            ['status' => 'completed'],
            ['scope' => 'bogus'],
            ['force_scope' => 'bogus'],
            ['status' => 'pending', 'force_scope' => 'nope'],
            ['status' => 'pending', 'date_from' => '13/06/2026'],
            ['status' => 'pending', 'min_amount' => 'abc'],
        ];

        foreach ($invalid as $query) {
            $response = $this->get(route('logsheets.export', $query));

            if ($response->getStatusCode() !== 302) {
                $this->fail('Expected 302 for '.json_encode($query).' but got '.$response->getStatusCode());
            }

            $response->assertSessionHasErrors();
        }

        $invalidImport = [
            ['status' => 'bogus'],
            ['force_status' => 'bogus'],
            ['status' => 'cleared'],
            ['status' => 'pending', 'force_status' => 'all-but-not'],
        ];

        foreach ($invalidImport as $query) {
            $response = $this->get(route('logsheets.imports.export', array_merge(['import' => $import], $query)));

            if ($response->getStatusCode() !== 302) {
                $this->fail('Expected 302 for '.json_encode($query).' but got '.$response->getStatusCode());
            }

            $response->assertSessionHasErrors();
        }
    }

    public function test_h_invalid_values_render_validation_errors_on_the_pages(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        $response = $this->from(route('logsheets.imports.show', $import))
            ->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'bogus']));
        $response->assertRedirect();
        $response->assertSessionHasErrors('status');

        $response = $this->from(route('logsheets.records'))
            ->get(route('logsheets.export', ['status' => 'bogus']));
        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
    }

    public function test_h_large_filtered_set_exports_every_row_via_chunking(): void
    {
        $import = $this->makeImport();

        $rows = [];
        $now = now();

        // 600 rows against a chunk size of 500, so chunking is genuinely exercised.
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = [
                'log_sheet_no' => str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'date' => '2026-06-15',
                'vehicle_no' => 'VH-'.$i,
                'tprt_code' => '007',
                'tprt_name' => 'Transporter',
                'destination' => 'Chennai',
                'sap_invoice_no' => 'SAP-000123',
                'posting_date' => '2026-06-15',
                'bill_date' => '2026-06-15',
                'vendor_inv_no' => 'VEN-0009',
                'total_gross_wt' => '1500.500',
                'total_booked_amount' => '100.00',
                'total_actual_amount' => '100.00',
                'total_diff' => '0.00',
                'consignment_count' => 1,
                'status' => 'pending',
                'last_import_id' => $import->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('logsheets')->insert($chunk);
        }

        $this->assertSame(600, Logsheet::count());

        $response = $this->get(route('logsheets.export', ['status' => 'pending']));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame(601, $sheet->getHighestRow(), 'Header row plus all 600 chunked rows');
        $this->assertCount(600, $this->numbers($sheet));

        // Leading zeros survive chunk boundaries.
        $this->assertSame('0000000001', $sheet->getCell('A601')->getValue());
    }

    public function test_h_text_and_numeric_columns_keep_their_types_and_formats(): void
    {
        $import = $this->makeImport();

        $this->makeRow($import, '0045350959', 'pending', '2026-06-01', '3100.00', [
            'tprt_code' => '007',
            'sap_invoice_no' => '00123456',
            'vendor_inv_no' => '00000009',
        ]);

        $response = $this->get(route('logsheets.export', ['status' => 'pending']));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');

        // Text columns: stored as strings, leading zeros intact, '@' format.
        foreach (['A' => '0045350959', 'D' => '007', 'G' => '00123456', 'J' => '00000009'] as $column => $expected) {
            $this->assertSame($expected, $sheet->getCell($column.'2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($column.'2')->getDataType());
            $this->assertSame('@', $sheet->getStyle($column.'2')->getNumberFormat()->getFormatCode());
        }

        // Numeric columns: real numbers with their formats.
        $this->assertSame(1500.5, $sheet->getCell('L2')->getValue());
        $this->assertSame('0.000', $sheet->getStyle('L2')->getNumberFormat()->getFormatCode());

        $this->assertSame(3100.0, $sheet->getCell('N2')->getValue());
        $this->assertSame('0.00', $sheet->getStyle('N2')->getNumberFormat()->getFormatCode());

        $this->assertSame(3, $sheet->getCell('K2')->getValue());
        $this->assertSame(3100.0, $sheet->getCell('M2')->getValue());
        $this->assertSame('0.00', $sheet->getStyle('O2')->getNumberFormat()->getFormatCode());
        $this->assertEmpty($sheet->getCell('O2')->getValue());
        $this->assertSame('2026-01-01 to 2026-12-31', $sheet->getCell('T2')->getValue());
    }

    public function test_h_text_columns_stay_text_across_an_override_scope(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, '0045350959', 'cleared', '2026-06-01', '100.00');

        $response = $this->get(route('logsheets.export', ['status' => 'pending', 'force_scope' => 'cleared']));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Cleared');
        $this->assertSame('0045350959', $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
    }

    // ------------------------------------------------- i. auth / role / middleware

    public static function exportQueryProvider(): array
    {
        return [
            'records: no params' => ['records', []],
            'records: status pending' => ['records', ['status' => 'pending']],
            'records: status cleared' => ['records', ['status' => 'cleared']],
            'records: legacy scope' => ['records', ['scope' => 'pending']],
            'records: force_scope override' => ['records', ['status' => 'cleared', 'force_scope' => 'pending']],
            'records: force_scope all' => ['records', ['force_scope' => 'all']],
            'import: no params' => ['import', []],
            'import: status completed' => ['import', ['status' => 'completed']],
            'import: status pending' => ['import', ['status' => 'pending']],
            'import: force_status override' => ['import', ['status' => 'completed', 'force_status' => 'pending']],
            'import: force_status all' => ['import', ['force_status' => 'all']],
        ];
    }

    /**
     * @dataProvider exportQueryProvider
     */
    public function test_i_export_routes_enforce_auth_and_role_for_every_new_query_combination(string $target, array $params): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');
        $this->makeRow($import, 'C000001', 'cleared', '2026-06-01', '200.00');

        $url = $target === 'records'
            ? route('logsheets.export', $params)
            : route('logsheets.imports.export', array_merge(['import' => $import], $params));

        // Guest -> login redirect.
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        // Admin -> 403.
        $this->actingAs(User::factory()->admin()->create());
        $this->get($url)->assertStatus(403);

        // Inactive super admin -> login redirect.
        $this->actingAs(User::factory()->superAdmin()->inactive()->create());
        $this->get($url)->assertRedirect(route('login'));

        // Super admin -> 200 with a workbook.
        $this->actingAs(User::factory()->superAdmin()->create());
        $response = $this->get($url);
        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('content-type'));

        // And the no-cache headers the middleware stack applies still land on the pages.
        $pageUrl = $target === 'records'
            ? route('logsheets.records')
            : route('logsheets.imports.show', $import);
        $this->assertStringContainsString(
            'no-store',
            (string) $this->get($pageUrl)->headers->get('Cache-Control')
        );
    }

    // ------------------------------------------------------ j. filenames + sanitising

    public static function scopeFilenameProvider(): array
    {
        return [
            'all (no params)' => [[], 'logsheets_all_'],
            'status pending' => [['status' => 'pending'], 'logsheets_pending_'],
            'status cleared' => [['status' => 'cleared'], 'logsheets_cleared_'],
            'legacy scope' => [['scope' => 'cleared'], 'logsheets_cleared_'],
            'force_scope all over pending' => [['status' => 'pending', 'force_scope' => 'all'], 'logsheets_all_'],
            'force_scope pending over cleared' => [['status' => 'cleared', 'force_scope' => 'pending'], 'logsheets_pending_'],
        ];
    }

    /**
     * @dataProvider scopeFilenameProvider
     */
    public function test_j_records_export_filenames_reflect_the_effective_scope(array $params, string $expectedPrefix): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        $response = $this->get(route('logsheets.export', $params));
        $response->assertStatus(200);

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString($expectedPrefix, $disposition);
        $this->assertMatchesRegularExpression('/filename="?logsheets_[a-z]+_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx"?/', $disposition);
    }

    public static function importFilenameProvider(): array
    {
        return [
            'no params' => [[], '_all_'],
            'status completed' => [['status' => 'completed'], '_completed_'],
            'status pending' => [['status' => 'pending'], '_pending_'],
            'force_status all over completed' => [['status' => 'completed', 'force_status' => 'all'], '_all_'],
            'force_status pending over completed' => [['status' => 'completed', 'force_status' => 'pending'], '_pending_'],
        ];
    }

    /**
     * @dataProvider importFilenameProvider
     */
    public function test_j_import_export_filenames_reflect_the_effective_scope(array $params, string $expectedSegment): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        $response = $this->get(route('logsheets.imports.export', array_merge(['import' => $import], $params)));
        $response->assertStatus(200);

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString('logsheets_import_'.$import->id.'_', $disposition);
        $this->assertStringContainsString($expectedSegment, $disposition);
        $this->assertMatchesRegularExpression(
            '/filename="?logsheets_import_\d+_[A-Za-z0-9_-]+_[a-z]+_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx"?/',
            $disposition
        );
    }

    public function test_j_import_export_filename_is_sanitised_against_traversal_and_illegal_chars(): void
    {
        $cases = [
            '../../etc/passwd' => 'logsheets_import_',
            'weird name (1) [v2]!.xlsx' => 'weird-name-1-v2',
            '   ' => 'import-',
            'C:\\Users\\Admin\\report.xlsx' => 'report',
            '.....xlsx' => 'xlsx',
        ];

        foreach ($cases as $filename => $expectedFragment) {
            $import = $this->makeImport(['original_filename' => $filename]);

            $response = $this->get(route('logsheets.imports.export', $import));
            $response->assertStatus(200);

            $disposition = (string) $response->headers->get('content-disposition');

            // Inspect only the filename portion, not the "attachment; " prefix.
            preg_match('/filename="?([^";]+)"?/', $disposition, $matches);
            $name = $matches[1] ?? '';

            $this->assertStringContainsString($expectedFragment, $name, 'Unexpected filename for: '.$filename);

            // No traversal, separators, spaces or other illegal characters in the name.
            $this->assertStringNotContainsString('..', $name);
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString(' ', $name);
            $this->assertStringNotContainsString('(', $name);
            $this->assertStringNotContainsString('[', $name);
            $this->assertStringNotContainsString('!', $name);
            $this->assertMatchesRegularExpression('/^logsheets_import_\d+_[A-Za-z0-9_-]+_[a-z]+_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx$/', $name);
        }
    }

    public function test_j_records_export_filename_is_always_safe(): void
    {
        $import = $this->makeImport();

        foreach ([[], ['status' => 'pending'], ['status' => 'cleared'], ['force_scope' => 'all']] as $params) {
            $response = $this->get(route('logsheets.export', $params));
            $response->assertStatus(200);

            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertMatchesRegularExpression('/filename="?logsheets_[a-z]+_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx"?/', $disposition);
            $this->assertStringNotContainsString('..', $disposition);
            $this->assertStringNotContainsString('/', $disposition);
        }
    }

    // ------------------------- k. pagination / query-string preservation on both pages

    public function test_k_records_export_form_forwards_filters_without_page_and_without_duplicates(): void
    {
        $import = $this->makeImport();
        for ($i = 1; $i <= 30; $i++) {
            $this->makeRow($import, 'P'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'pending', '2026-06-15', '100.00');
        }

        $page = $this->get(route('logsheets.records', [
            'status' => 'pending',
            'sort' => 'date',
            'direction' => 'asc',
            'page' => 2,
            'scope' => 'cleared',
            'force_scope' => 'cleared',
        ]));
        $page->assertStatus(200);
        $this->assertSame(30, $page->viewData('logsheets')->total());
        $this->assertSame(2, $page->viewData('logsheets')->currentPage());
        $this->assertCount(5, $page->viewData('logsheets')->items());

        $query = $this->recordsExportAction($page->getContent());
        $queryString = (string) parse_url($query, PHP_URL_QUERY);
        parse_str($queryString, $params);

        $this->assertSame('pending', $params['status']);
        $this->assertSame('date', $params['sort']);
        $this->assertSame('asc', $params['direction']);
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('scope', $params);
        $this->assertArrayNotHasKey('force_scope', $params);

        // No duplicated keys anywhere in the generated URL.
        $this->assertSame(1, substr_count($queryString, 'status='));

        // And the exported set covers all matching rows, not just page 2.
        $sheet = $this->loadWorkbook($this->get($query))->getSheetByName('Pending');
        $this->assertSame(31, $sheet->getHighestRow());
    }

    public function test_k_records_sort_links_still_preserve_filters(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        $page = $this->get(route('logsheets.records', ['status' => 'pending', 'log_sheet_no' => 'P0']));
        $page->assertStatus(200);

        $found = 0;
        foreach ($this->dom($page->getContent())->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');

            if (! str_contains($href, 'sort=')) {
                continue;
            }

            $query = (string) parse_url($href, PHP_URL_QUERY);
            parse_str($query, $params);

            $this->assertSame('pending', $params['status'], 'Sort link dropped the status filter');
            $this->assertSame('P0', $params['log_sheet_no'], 'Sort link dropped the log sheet no filter');
            $this->assertArrayNotHasKey('page', $params);
            $this->assertArrayHasKey('sort', $params);
            $this->assertArrayHasKey('direction', $params);
            $this->assertSame(1, substr_count($query, 'status='));

            $found++;
        }

        $this->assertGreaterThan(0, $found, 'No sort links found');
    }

    public function test_k_records_pagination_links_preserve_filters(): void
    {
        $import = $this->makeImport();
        for ($i = 1; $i <= 60; $i++) {
            $this->makeRow($import, 'P'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'pending', '2026-06-15', '100.00');
        }

        $page = $this->get(route('logsheets.records', ['status' => 'pending']));
        $page->assertStatus(200);
        $this->assertSame(3, $page->viewData('logsheets')->lastPage());

        $found = 0;
        foreach ($this->dom($page->getContent())->query('//a[@href]') as $anchor) {
            $query = (string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY);

            if (! str_contains($query, 'page=')) {
                continue;
            }

            parse_str($query, $params);
            $this->assertSame('pending', $params['status'], 'Pagination link dropped the status filter');
            $this->assertSame(1, substr_count($query, 'status='));

            $found++;
        }

        $this->assertGreaterThan(0, $found, 'No pagination links found');
    }

    public function test_k_import_export_link_forwards_status_but_not_pagination_params(): void
    {
        $import = $this->makeImport();

        for ($i = 1; $i <= 60; $i++) {
            $this->makeRow($import, 'P'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'pending', '2026-06-15', '100.00');
        }

        for ($i = 1; $i <= 40; $i++) {
            LogsheetRawRow::create([
                'import_id' => $import->id,
                'log_sheet_no' => 'BAD'.$i,
                'raw_data' => ['gross_wt' => ''],
                'row_number_in_file' => $i,
                'is_valid' => false,
                'validation_error' => 'Invalid amount',
            ]);
        }

        $page = $this->get(route('logsheets.imports.show', [
            'import' => $import,
            'status' => 'pending',
            'page' => 2,
            'invalid_page' => 2,
        ]));
        $page->assertStatus(200);

        $this->assertSame(60, $page->viewData('logsheets')->total());
        $this->assertCount(25, $page->viewData('logsheets')->items());
        // The request asked for page 2 of both paginators, so the invalid rows show page 2.
        $this->assertCount(15, $page->viewData('invalidRows')->items());
        $this->assertSame(2, $page->viewData('invalidRows')->currentPage());

        $href = $this->importExportAction($page->getContent());
        parse_str((string) parse_url($href, PHP_URL_QUERY), $params);

        $this->assertSame('pending', $params['status']);
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('invalid_page', $params);
        $this->assertSame(1, substr_count((string) parse_url($href, PHP_URL_QUERY), 'status='));

        // Both paginators still work and keep their own param names.
        $this->assertCount(10, $this->get(route('logsheets.imports.show', ['import' => $import, 'page' => 3]))->viewData('logsheets')->items());
        $this->assertCount(15, $this->get(route('logsheets.imports.show', ['import' => $import, 'invalid_page' => 2]))->viewData('invalidRows')->items());
    }

    // ------------------------------------------ l. form flows unaffected (re-verified)

    public function test_l_import_upload_flow_still_works(): void
    {
        $this->get('/logsheets');

        Excel::fake();
        Excel::shouldReceive('toArray')->once()->andReturn([
            [
                array_fill(0, 26, null),
                [
                    'Log Sheet No', 'Date', 'Invoice No', 'Inv- Date', 'Payer', 'Payer Name',
                    'Town', 'Gross Wt', 'difference', 'amount', 'Volume', 'Tprt Code',
                    'Tprt Name', 'Container ID', 'Destination', 'SAPInvoiceNo', 'Posting Date',
                    'Bill Date', 'VendorInvNo', 'Route', 'Town', 'Gross weight', 'Booked Amount',
                    'Actual Rate', 'Actual Amount', 'Diff',
                ],
                [
                    'LSFORM01', '2026-06-10', 'INV-001', '2026-06-10', 'PAY-001', 'Payer One',
                    'Kolkata', '1000.000', '100.00', '1100.00', '25', 'T01', 'Transporter A',
                    'VH-01', 'Chennai', 'SAP-001', '2026-06-10', '2026-06-11', 'VEN-001',
                    'Route A', 'Kolkata', '1000.000', '5000.00', '100', '6100.00', '100.00',
                ],
            ],
        ]);

        $response = $this->call('POST', '/logsheets', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('flow.xlsx'),
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');
        $response->assertSessionHasNoErrors();
        $this->assertNotNull(Logsheet::where('log_sheet_no', 'LSFORM01')->first());

        // The imported row is immediately visible on the records page and inside the
        // status-filtered export. The Excel fake is still swapped in here, so the export is
        // exercised in the dedicated filter-coupling tests instead.
        $records = $this->get(route('logsheets.records', ['status' => 'pending']));
        $records->assertStatus(200);
        $records->assertSee('LSFORM01');
        $this->assertSame(1, $records->viewData('logsheets')->total());
    }

    public function test_l_clear_preview_bulk_and_single_flows_still_work(): void
    {
        $import = $this->makeImport();
        $logsheet = $this->makeRow($import, 'CLEAR01', 'pending', '2026-06-01', '100.00');

        $this->get('/logsheets');

        $preview = $this->postJson('/logsheets/clear/preview', ['numbers' => ['CLEAR01'], '_token' => csrf_token()]);
        $preview->assertStatus(200);
        $this->assertSame('pending', $preview->json('items.0.status'));

        $bulk = $this->post('/logsheets/clear/bulk', ['numbers' => ['CLEAR01'], '_token' => csrf_token()]);
        $bulk->assertRedirect();
        $bulk->assertSessionHasNoErrors();
        $this->assertSame('cleared', Logsheet::find($logsheet->id)->status);

        // Now on the Cleared side, the filtered export must contain it.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.export', ['status' => 'cleared'])));
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertContains('CLEAR01', $this->numbers($workbook->getSheetByName('Cleared')));

        $single = $this->post('/logsheets/clear', ['log_sheet_no' => 'CLEAR01', '_token' => csrf_token()]);
        $single->assertRedirect();
        $single->assertSessionHasNoErrors();
        $single->assertSessionHas('info');
    }

    public function test_l_delete_logsheet_and_import_flows_still_work(): void
    {
        $import = $this->makeImport();
        $logsheet = $this->makeRow($import, 'DEL00001', 'pending', '2026-06-01', '100.00');

        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => 'ORPHAN1',
            'raw_data' => ['gross_wt' => ''],
            'row_number_in_file' => 1,
            'is_valid' => false,
            'validation_error' => 'Invalid',
        ]);

        $delete = $this->call('DELETE', route('logsheets.destroy', $logsheet), ['_token' => csrf_token()]);
        $delete->assertRedirect(route('logsheets.records'));
        $delete->assertSessionHasNoErrors();

        $this->get(route('logsheets.records'))->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);
        $this->get(route('logsheets.export'))->assertStatus(200);

        $deleteImport = $this->call('DELETE', route('logsheets.imports.destroy', $import), ['_token' => csrf_token()]);
        $deleteImport->assertRedirect(route('logsheets.index'));
        $deleteImport->assertSessionHasNoErrors();

        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.records'))->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(404);
    }

    public function test_l_forms_still_carry_csrf_tokens_and_filter_actions(): void
    {
        $import = $this->makeImport();
        $this->makeRow($import, 'P000001', 'pending', '2026-06-01', '100.00');

        foreach ([route('logsheets.index'), route('logsheets.records'), route('logsheets.imports.show', $import)] as $pageUrl) {
            $response = $this->get($pageUrl);
            $response->assertStatus(200);

            $xpath = $this->dom($response->getContent());

            foreach ($xpath->query('//form[not(@method="get")]') as $form) {
                /** @var \DOMElement $form */
                $tokens = $xpath->query('.//input[@name="_token"]', $form);

                if ($tokens->length === 0) {
                    continue;
                }

                $this->assertSame(csrf_token(), $tokens->item(0)->getAttribute('value'));
            }
        }

        // The records filter form and the export form both target real routes.
        $records = $this->get(route('logsheets.records'));
        $actions = [];

        foreach ($this->dom($records->getContent())->query('//form') as $form) {
            $actions[] = $form->getAttribute('action');
        }

        $this->assertContains(route('logsheets.records'), $actions);
        $this->assertContains(route('logsheets.export'), $actions);
    }
}
