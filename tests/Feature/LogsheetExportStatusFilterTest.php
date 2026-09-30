<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LogsheetExportStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($this->superAdmin);
    }

    private function makeImport(array $overrides = []): LogsheetImport
    {
        return LogsheetImport::create(array_merge([
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'original_filename' => 'status_filter.xlsx',
            'file_path' => 'logsheet_imports/status_filter.xlsx',
            'uploaded_by' => $this->superAdmin->id,
            'row_count' => 6,
            'consolidated_count' => 6,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '6000.00',
            'total_booked_amount' => '6000.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '6000.000',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
        ], $overrides));
    }

    /**
     * @return array{0: int, 1: int} [pending, cleared] counts
     */
    private function seedData(LogsheetImport $import, int $pending = 3, int $cleared = 3, string $prefix = ''): array
    {
        for ($i = 1; $i <= $pending; $i++) {
            Logsheet::create([
                'log_sheet_no' => $prefix.'P'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'date' => '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'vehicle_no' => 'VH-'.$i,
                'total_gross_wt' => '1000.000',
                'total_booked_amount' => '1000.00',
                'total_actual_amount' => '1000.00',
                'total_diff' => '0.00',
                'consignment_count' => 1,
                'status' => 'pending',
                'last_import_id' => $import->id,
            ]);
        }

        for ($i = 1; $i <= $cleared; $i++) {
            Logsheet::create([
                'log_sheet_no' => $prefix.'C'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'date' => '2026-07-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'vehicle_no' => 'VH-C'.$i,
                'total_gross_wt' => '1000.000',
                'total_booked_amount' => '1000.00',
                'total_actual_amount' => '1000.00',
                'total_diff' => '0.00',
                'consignment_count' => 1,
                'status' => 'cleared',
                'cleared_at' => now(),
                'cleared_by' => $this->superAdmin->id,
                'last_import_id' => $import->id,
            ]);
        }

        return [$pending, $cleared];
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

    /**
     * @return array{0: array<int, string>, 1: array<int, string>} [logSheetNos, statuses] for data rows
     */
    private function rows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $numbers = [];
        $statuses = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $numbers[] = (string) $sheet->getCell('A'.$row)->getValue();
            $statuses[] = (string) $sheet->getCell('P'.$row)->getValue();
        }

        return [$numbers, $statuses];
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    /**
     * Parse the primary "Export current view" form out of a rendered records page and
     * rebuild the exact URL it would submit. Proves the primary action mirrors the filters.
     */
    private function exportFormQuery(string $html): string
    {
        $xpath = $this->dom($html);

        foreach ($xpath->query('//form') as $form) {
            /** @var \DOMElement $form */
            if (strtolower($form->getAttribute('method')) !== 'get') {
                continue;
            }

            if ($form->getAttribute('action') !== route('logsheets.export')) {
                continue;
            }

            $params = [];

            foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
                /** @var \DOMElement $input */
                $params[$input->getAttribute('name')] = $input->getAttribute('value');
            }

            return http_build_query($params);
        }

        $this->fail('Export current view form not found on the records page.');
    }

    /** @return array<string, string> href => trimmed label */
    private function overrideLinks(string $html): array
    {
        $links = [];

        foreach ($this->dom($html)->query('//a[@href]') as $anchor) {
            /** @var \DOMElement $anchor */
            if (str_contains($anchor->getAttribute('href'), 'force_scope=')) {
                $links[$anchor->getAttribute('href')] = trim($anchor->textContent);
            }
        }

        return $links;
    }

    public function test_records_export_current_view_form_mirrors_the_filtered_table(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        foreach (['pending', 'cleared', null] as $status) {
            $filters = array_filter([
                'status' => $status,
                'log_sheet_no' => '00000',
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ]);

            $table = $this->get(route('logsheets.records', $filters));
            $table->assertStatus(200);

            $query = $this->exportFormQuery($table->getContent());

            $response = $this->get(route('logsheets.export').'?'.$query);
            $response->assertStatus(200);

            $workbook = $this->loadWorkbook($response);
            $expectedSheet = match ($status) {
                'pending' => ['Pending'],
                'cleared' => ['Cleared'],
                default => ['Pending', 'Cleared'],
            };

            $this->assertSame($expectedSheet, $workbook->getSheetNames(), 'Sheet set mismatch for status '.var_export($status, true));

            $exported = 0;
            foreach ($workbook->getSheetNames() as $name) {
                $exported += max(0, $workbook->getSheetByName($name)->getHighestRow() - 1);
            }

            $this->assertSame(
                $table->viewData('logsheets')->total(),
                $exported,
                'Exported row count must equal the visible row count for status '.var_export($status, true)
            );
        }
    }

    public function test_records_export_current_view_form_excludes_pagination_and_scope_keys(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 2);

        $table = $this->get(route('logsheets.records', [
            'status' => 'pending',
            'sort' => 'date',
            'direction' => 'asc',
            'page' => 2,
            'scope' => 'cleared',
            'force_scope' => 'cleared',
        ]));
        $table->assertStatus(200);

        $query = $this->exportFormQuery($table->getContent());
        parse_str($query, $params);

        $this->assertArrayHasKey('status', $params);
        $this->assertSame('pending', $params['status']);
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('scope', $params);
        $this->assertArrayNotHasKey('force_scope', $params);
    }

    public function test_records_page_keeps_secondary_override_controls(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 2);

        $table = $this->get(route('logsheets.records', [
            'status' => 'cleared',
            'log_sheet_no' => '000',
        ]));
        $table->assertStatus(200);

        $content = $table->getContent();

        $this->assertStringContainsString('Export current view', $content);
        $this->assertStringContainsString('Override filter', $content);

        $links = $this->overrideLinks($content);

        foreach (['all' => 'All (2 sheets)', 'pending' => 'Pending only', 'cleared' => 'Cleared only'] as $force => $label) {
            $expected = route('logsheets.export').'?'.http_build_query([
                'log_sheet_no' => '000',
                'force_scope' => $force,
            ]);

            $this->assertArrayHasKey($expected, $links, 'Missing override link for '.$label);
            $this->assertSame($label, $links[$expected]);
        }

        // The override must drop the status filter it is overriding.
        foreach (array_keys($links) as $href) {
            $this->assertStringNotContainsString('status=', $href);
        }
    }

    public function test_override_links_are_secondary_to_the_primary_action(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 2);

        $content = $this->get(route('logsheets.records'))->getContent();

        $xpath = $this->dom($content);
        $primary = null;
        $overrideBlock = null;

        foreach ($xpath->query('//form') as $form) {
            /** @var \DOMElement $form */
            if (strtolower($form->getAttribute('method')) === 'get' && $form->getAttribute('action') === route('logsheets.export')) {
                $primary = $form;
            }
        }

        foreach ($xpath->query('//span') as $span) {
            /** @var \DOMElement $span */
            if (str_contains($span->textContent, 'Override filter')) {
                $overrideBlock = $span;
            }
        }

        $this->assertNotNull($primary, 'Primary export form must exist');
        $this->assertNotNull($overrideBlock, 'Secondary override caption must exist');

        // The primary control is a submit button with the brand style.
        $buttons = $xpath->query('.//button[@type="submit"]', $primary);
        $this->assertSame(1, $buttons->length);
        $this->assertStringContainsString('Export current view', trim($buttons->item(0)->textContent));
        $this->assertStringContainsString('bg-brand-600', $buttons->item(0)->getAttribute('class'));

        // The override controls are plain anchors (never a submit button) with muted styling.
        $overrideLinks = $xpath->query('.//a[contains(@href, "force_scope=")]', $overrideBlock->parentNode);
        $this->assertSame(3, $overrideLinks->length);

        foreach ($overrideLinks as $link) {
            /** @var \DOMElement $link */
            $this->assertStringContainsString('text-zinc-600', $link->getAttribute('class'));
            $this->assertStringNotContainsString('bg-brand-600', $link->getAttribute('class'));
        }

        // Ordering: the primary action appears before the override row in the document.
        $this->assertLessThan(
            mb_strpos($content, 'Override filter'),
            mb_strpos($content, 'Export current view')
        );
    }

    public function test_force_scope_override_ignores_the_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        // Table shows Cleared only, but the override asks for Pending.
        $response = $this->get(route('logsheets.export', ['status' => 'cleared', 'force_scope' => 'pending']));
        $response->assertStatus(200);
        $this->assertStringContainsString('logsheets_pending_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        [$numbers, $statuses] = $this->rows($workbook->getSheetByName('Pending'));
        $this->assertCount(3, $numbers);
        $this->assertSame(['pending', 'pending', 'pending'], $statuses);

        // And the reverse direction.
        $response = $this->get(route('logsheets.export', ['status' => 'pending', 'force_scope' => 'cleared']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertCount(3, $this->rows($workbook->getSheetByName('Cleared'))[0]);
    }

    public function test_force_scope_all_overrides_a_single_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 3);

        $response = $this->get(route('logsheets.export', ['status' => 'pending', 'force_scope' => 'all']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
        $this->assertCount(2, $this->rows($workbook->getSheetByName('Pending'))[0]);
        $this->assertCount(3, $this->rows($workbook->getSheetByName('Cleared'))[0]);
    }

    public function test_force_scope_override_still_honours_every_other_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        // Cleared rows are dated July; narrowing by June must leave the pending override with 3 rows
        // (June pending) and the cleared override with none.
        $response = $this->get(route('logsheets.export', [
            'status' => 'cleared',
            'force_scope' => 'pending',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame(4, $sheet->getHighestRow());

        $response = $this->get(route('logsheets.export', [
            'status' => 'pending',
            'force_scope' => 'cleared',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('Cleared')->getHighestRow());

        // log_sheet_no search is forwarded through the override too.
        $response = $this->get(route('logsheets.export', [
            'force_scope' => 'pending',
            'log_sheet_no' => 'P000002',
        ]));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('P000002', $sheet->getCell('A2')->getValue());
    }

    public function test_precedence_force_scope_beats_status_beats_scope(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 2);

        // force_scope beats status and scope.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.export', [
            'force_scope' => 'cleared',
            'status' => 'pending',
            'scope' => 'pending',
        ])));
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        // status beats scope.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.export', [
            'status' => 'cleared',
            'scope' => 'pending',
        ])));
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        // scope is still honoured when nothing else is set (backward compatible).
        $workbook = $this->loadWorkbook($this->get(route('logsheets.export', ['scope' => 'pending'])));
        $this->assertSame(['Pending'], $workbook->getSheetNames());
    }

    public function test_invalid_force_scope_fails_validation_without_500(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $response = $this->get(route('logsheets.export', ['force_scope' => 'bogus']));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('force_scope');
    }

    public function test_empty_force_scope_falls_back_to_the_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 2);

        $response = $this->get(route('logsheets.export', ['force_scope' => '', 'status' => 'cleared']));
        $response->assertStatus(200);

        $this->assertSame(['Cleared'], $this->loadWorkbook($response)->getSheetNames());
    }

    public function test_records_export_matches_pending_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $table = $this->get(route('logsheets.records', ['status' => 'pending']));
        $table->assertStatus(200);
        $this->assertSame(3, $table->viewData('logsheets')->total());

        $response = $this->get(route('logsheets.export', ['status' => 'pending']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        [$numbers, $statuses] = $this->rows($workbook->getSheetByName('Pending'));
        $this->assertCount($table->viewData('logsheets')->total(), $numbers);
        $this->assertSame(['pending', 'pending', 'pending'], $statuses);
        $this->assertNotContains('Cleared', $workbook->getSheetNames());
    }

    public function test_records_export_matches_cleared_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $table = $this->get(route('logsheets.records', ['status' => 'cleared']));
        $table->assertStatus(200);
        $this->assertSame(3, $table->viewData('logsheets')->total());

        $response = $this->get(route('logsheets.export', ['status' => 'cleared']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        [$numbers, $statuses] = $this->rows($workbook->getSheetByName('Cleared'));
        $this->assertCount($table->viewData('logsheets')->total(), $numbers);
        $this->assertSame(['cleared', 'cleared', 'cleared'], $statuses);
    }

    public function test_records_export_without_status_filter_exports_both_sheets(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $table = $this->get(route('logsheets.records'));
        $table->assertStatus(200);
        $this->assertSame(6, $table->viewData('logsheets')->total());

        $response = $this->get(route('logsheets.export'));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());

        [$pendingNumbers] = $this->rows($workbook->getSheetByName('Pending'));
        [$clearedNumbers] = $this->rows($workbook->getSheetByName('Cleared'));

        $this->assertCount(3, $pendingNumbers);
        $this->assertCount(3, $clearedNumbers);
        $this->assertCount(
            $table->viewData('logsheets')->total(),
            array_merge($pendingNumbers, $clearedNumbers)
        );
    }

    public function test_empty_status_value_is_treated_as_no_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $response = $this->get(route('logsheets.export', ['status' => '']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
    }

    public function test_status_filter_combines_with_other_filters(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        // Pending rows live in June, cleared rows in July.
        $response = $this->get(route('logsheets.export', [
            'status' => 'pending',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(4, $workbook->getSheetByName('Pending')->getHighestRow());

        $response = $this->get(route('logsheets.export', [
            'status' => 'cleared',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('Cleared')->getHighestRow());

        $response = $this->get(route('logsheets.export', [
            'status' => 'pending',
            'log_sheet_no' => 'P000002',
        ]));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Pending');
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('P000002', $sheet->getCell('A2')->getValue());
    }

    public function test_status_filter_with_no_matches_exports_headers_only(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 2, 0);

        $response = $this->get(route('logsheets.export', ['status' => 'cleared']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Cleared');
        $this->assertSame(1, $sheet->getHighestRow());
        $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());
    }

    public function test_export_filename_reflects_the_effective_scope(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $this->assertStringContainsString(
            'logsheets_all_',
            (string) $this->get(route('logsheets.export'))->headers->get('content-disposition')
        );

        $this->assertStringContainsString(
            'logsheets_pending_',
            (string) $this->get(route('logsheets.export', ['status' => 'pending']))->headers->get('content-disposition')
        );

        $this->assertStringContainsString(
            'logsheets_cleared_',
            (string) $this->get(route('logsheets.export', ['status' => 'cleared', 'scope' => 'pending']))->headers->get('content-disposition')
        );
    }

    public function test_page_parameter_does_not_truncate_the_export(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 30, 0);

        $response = $this->get(route('logsheets.export', ['status' => 'pending', 'page' => 2]));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame(31, $sheet->getHighestRow());
    }

    public function test_records_export_control_carries_the_current_filters(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $response = $this->get(route('logsheets.records', [
            'status' => 'cleared',
            'log_sheet_no' => 'C000',
            'date_from' => '2026-07-01',
        ]));
        $response->assertStatus(200);

        $content = $response->getContent();

        $this->assertStringContainsString('action="'.route('logsheets.export').'"', $content);
        $this->assertStringContainsString('name="status" value="cleared"', $content);
        $this->assertStringContainsString('name="log_sheet_no" value="C000"', $content);
        $this->assertStringContainsString('name="date_from" value="2026-07-01"', $content);
        $this->assertStringNotContainsString('name="page"', $content);
        $this->assertStringNotContainsString('name="scope"', $content);
        $this->assertStringContainsString('Cleared only', $content);
        $this->assertStringNotContainsString('Pending + Cleared (2 sheets)', $content);
    }

    public function test_records_export_control_reports_both_sheets_when_unfiltered(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $response = $this->get(route('logsheets.records'));
        $response->assertStatus(200);

        $content = $response->getContent();

        $this->assertStringContainsString('action="'.route('logsheets.export').'"', $content);
        $this->assertStringContainsString('Pending + Cleared (2 sheets)', $content);
        $this->assertStringNotContainsString('name="status" value=', $content);
    }

    public function test_import_export_matches_completed_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $table = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));
        $table->assertStatus(200);
        $this->assertSame(3, $table->viewData('logsheets')->total());

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'completed']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        [$numbers, $statuses] = $this->rows($workbook->getSheetByName('Cleared'));
        $this->assertCount($table->viewData('logsheets')->total(), $numbers);
        $this->assertSame(['cleared', 'cleared', 'cleared'], $statuses);
    }

    public function test_import_export_matches_pending_status_filter(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        [$numbers, $statuses] = $this->rows($workbook->getSheetByName('Pending'));
        $this->assertCount(3, $numbers);
        $this->assertSame(['pending', 'pending', 'pending'], $statuses);
    }

    public function test_import_export_without_status_filter_exports_both_sheets(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 3, 3);

        $table = $this->get(route('logsheets.imports.show', $import));
        $table->assertStatus(200);
        $this->assertSame(6, $table->viewData('logsheets')->total());

        $response = $this->get(route('logsheets.imports.export', $import));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());

        [$pending] = $this->rows($workbook->getSheetByName('Pending'));
        [$cleared] = $this->rows($workbook->getSheetByName('Cleared'));

        $this->assertCount($table->viewData('logsheets')->total(), array_merge($pending, $cleared));
    }

    public function test_import_export_with_status_filter_and_no_matches(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 0, 2);

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('Pending')->getHighestRow());
    }

    public function test_import_export_is_still_scoped_to_its_own_import(): void
    {
        $importA = $this->makeImport(['original_filename' => 'a.xlsx']);
        $importB = $this->makeImport(['original_filename' => 'b.xlsx']);

        $this->seedData($importA, 2, 1, 'A');
        $this->seedData($importB, 5, 0, 'B');

        $response = $this->get(route('logsheets.imports.export', ['import' => $importA, 'status' => 'pending']));
        $response->assertStatus(200);

        [$numbers] = $this->rows($this->loadWorkbook($response)->getSheetByName('Pending'));
        $this->assertCount(2, $numbers);
        $this->assertNotContains('BP000001', $numbers);
    }

    public function test_status_filter_export_keeps_existing_guarantees(): void
    {
        $import = $this->makeImport();

        Logsheet::create([
            'log_sheet_no' => '0045350959',
            'date' => '2026-06-10',
            'tprt_code' => '007',
            'sap_invoice_no' => 'SAP-000123',
            'vendor_inv_no' => 'VEN-0009',
            'total_gross_wt' => '2000.000',
            'total_booked_amount' => '3000.00',
            'total_actual_amount' => '3100.00',
            'total_diff' => '100.00',
            'consignment_count' => 2,
            'status' => 'pending',
            'last_import_id' => $import->id,
        ]);

        $response = $this->get(route('logsheets.export', ['status' => 'pending']));
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame('0045350959', $sheet->getCell('A2')->getValue());
        $this->assertSame('007', $sheet->getCell('D2')->getValue());
        $this->assertSame('SAP-000123', $sheet->getCell('G2')->getValue());
        $this->assertSame('VEN-0009', $sheet->getCell('J2')->getValue());
        $this->assertSame(3100.0, $sheet->getCell('N2')->getValue());
    }

    public function test_status_filter_export_still_validates_and_authorises(): void
    {
        $import = $this->makeImport();
        $this->seedData($import, 1, 1);

        $this->get(route('logsheets.export', ['status' => 'bogus']))->assertStatus(302);
        $this->get(route('logsheets.export', ['status' => 'pending', 'date_from' => 'bad']))->assertStatus(302);
        $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'bogus']))->assertStatus(302);

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('logsheets.export', ['status' => 'pending']))->assertStatus(403);
        $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']))->assertStatus(403);
    }
}
