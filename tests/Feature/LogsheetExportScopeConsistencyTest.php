<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Scope-consistency between what a log sheet table SHOWS and what its Export control
 * PRODUCES, on both the records page and the import-detail page.
 *
 * Each test drives the *real* rendered control (extracted from the HTML with
 * DOMDocument) rather than hand-building a query string, so it fails if the view and
 * the controller ever disagree about which filters are "applied".
 *
 * These cases were derived from a real headless-Chrome walkthrough of the module, which
 * also caught two live JavaScript defects that HTTP-level tests cannot see:
 *   - `x-on:change="this.form.submit()"` throws in Alpine (`this` is unbound), so the
 *     import-detail auto-submit never fired;
 *   - `x-text="result.counts.…"` threw on every load of the pages that render the
 *     clear-payments partial, because `result` starts as `null`.
 * Both are guarded here so they cannot silently regress.
 */
class LogsheetExportScopeConsistencyTest extends TestCase
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

    // ------------------------------------------------------------------ fixtures

    private function makeImport(array $overrides = []): LogsheetImport
    {
        return LogsheetImport::create(array_merge([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'original_filename' => 'consistency.xlsx',
            'file_path' => 'logsheet_imports/consistency.xlsx',
            'uploaded_by' => $this->superAdmin->id,
            'row_count' => 6,
            'consolidated_count' => 6,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '2100.00',
            'total_booked_amount' => '2100.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '9000.000',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
        ], $overrides));
    }

    /**
     * Three pending and three cleared rows, each on a distinct date and amount so that
     * every filter dimension can discriminate.
     */
    private function seedFixture(): LogsheetImport
    {
        $import = $this->makeImport();
        $now = now();

        $rows = [
            ['0000000001', 'pending', '2026-06-01', '100.00'],
            ['0000000002', 'pending', '2026-06-10', '200.00'],
            ['0000000003', 'pending', '2026-07-01', '300.00'],
            ['0000000004', 'cleared', '2026-06-05', '400.00'],
            ['0000000005', 'cleared', '2026-07-15', '500.00'],
            ['0000000006', 'cleared', '2026-08-01', '600.00'],
        ];

        foreach ($rows as $i => [$number, $status, $date, $amount]) {
            $row = [
                'log_sheet_no' => $number,
                'date' => $date,
                'vehicle_no' => 'VH-'.($i + 1),
                'tprt_code' => '007',
                'tprt_name' => 'Transporter '.($i + 1),
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
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($status === 'cleared') {
                $row['cleared_at'] = $now;
                $row['cleared_by'] = $this->superAdmin->id;
            }

            DB::table('logsheets')->insert($row);
        }

        return $import;
    }

    // ------------------------------------------------------------------- helpers

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

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    /** The absolute export URL built by the real records-page export form. */
    private function recordsExportUrl(string $html): string
    {
        $xpath = $this->xpath($html);

        foreach ($xpath->query('//form') as $form) {
            if (rtrim((string) $form->getAttribute('action'), '/') !== route('logsheets.export')) {
                continue;
            }

            $params = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
                $name = $input->getAttribute('name');
                // Array-style hidden inputs are not produced by this form; keep it simple.
                $params[$name] = $input->getAttribute('value');
            }

            $action = $form->getAttribute('action');

            return $params === [] ? $action : $action.'?'.http_build_query($params);
        }

        $this->fail('The "Export current view" form was not found on the records page.');
    }

    /** The absolute export URL behind the real import-detail primary export link. */
    private function importExportUrl(string $html): string
    {
        foreach ($this->xpath($html)->query('//a[@href]') as $anchor) {
            $href = (string) $anchor->getAttribute('href');

            if (str_contains($href, '/export') && ! str_contains($href, 'force_status=')) {
                return $href;
            }
        }

        $this->fail('The primary export link was not found on the import detail page.');
    }

    /**
     * Turns a downloaded workbook into ['Pending' => [statuses], 'Cleared' => [...]].
     *
     * @return array<string, array<int, mixed>>
     */
    private function sheetStatuses($response): array
    {
        $workbook = $this->loadWorkbook($response);
        $out = [];

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray();
            $header = $rows[0] ?? [];

            $noCol = array_search('Log Sheet No', $header, true);
            $statusCol = array_search('Status', $header, true);

            $this->assertIsInt($noCol, 'Export is missing the "Log Sheet No" column');
            $this->assertIsInt($statusCol, 'Export is missing the "Status" column');

            $out[$sheet->getTitle()] = [
                'numbers' => [],
                'statuses' => [],
            ];

            foreach (array_slice($rows, 1) as $row) {
                if (count(array_filter($row, fn ($c) => $c !== null && $c !== '')) === 0) {
                    continue;
                }

                $out[$sheet->getTitle()]['numbers'][] = (string) $row[$noCol];
                $out[$sheet->getTitle()]['statuses'][] = $row[$statusCol];
            }
        }

        return $out;
    }

    private function assertOnlyStatus(array $sheets, string $expectedStatus): void
    {
        $seen = [];

        foreach ($sheets as $rows) {
            foreach ($rows['statuses'] as $status) {
                $seen[] = $status;
            }
        }

        $this->assertNotEmpty($seen, 'Export contained no data rows at all.');

        foreach ($seen as $status) {
            $this->assertSame($expectedStatus, $status, 'Export leaked a row with the wrong status.');
        }
    }

    // =====================================================================
    // 1. Records page: filter -> Apply -> Export
    // =====================================================================

    public function test_records_pending_filter_apply_export_yields_pending_only_workbook(): void
    {
        $this->seedFixture();

        $page = $this->get(route('logsheets.records', ['status' => 'pending']));
        $page->assertOk();
        $this->assertSame(3, $page->viewData('logsheets')->total());

        $url = $this->recordsExportUrl($page->getContent());

        $response = $this->get($url);
        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $sheets = $this->sheetStatuses($response);

        $this->assertSame(['Pending'], array_keys($sheets));
        $this->assertOnlyStatus($sheets, 'pending');
        $this->assertCount(3, $sheets['Pending']['numbers']);
        $this->assertCount(3, $sheets['Pending']['statuses']);
    }

    public function test_records_cleared_filter_apply_export_yields_cleared_only_workbook(): void
    {
        $this->seedFixture();

        $page = $this->get(route('logsheets.records', ['status' => 'cleared']));
        $page->assertOk();
        $this->assertSame(3, $page->viewData('logsheets')->total());

        $url = $this->recordsExportUrl($page->getContent());

        $response = $this->get($url);
        $response->assertOk();

        $sheets = $this->sheetStatuses($response);

        $this->assertSame(['Cleared'], array_keys($sheets));
        $this->assertOnlyStatus($sheets, 'cleared');
        $this->assertCount(3, $sheets['Cleared']['numbers']);
    }

    public function test_records_clearing_filter_back_to_all_statuses_restores_both_sheets(): void
    {
        $this->seedFixture();

        // Start filtered, then go back to "All Statuses" — the round trip a user makes.
        $filtered = $this->get(route('logsheets.records', ['status' => 'pending']));
        $filtered->assertOk();
        $this->assertSame(['Pending'], array_keys($this->sheetStatuses(
            $this->get($this->recordsExportUrl($filtered->getContent()))
        )));

        $clearedPage = $this->get(route('logsheets.records'));
        $clearedPage->assertOk();
        $this->assertSame(6, $clearedPage->viewData('logsheets')->total());

        // The dropdown is back on "All Statuses". The blade only emits an explicit
        // `selected` attribute for the pending/cleared options, so with no applied
        // status no option carries it and the browser falls back to the first option —
        // which must be the empty "All Statuses" value.
        $xpath = $this->xpath($clearedPage->getContent());

        $selected = $xpath->query('//select[@name="status"]/option[@selected]');
        $this->assertSame(0, $selected->length, 'No status should be marked selected when the filter is cleared.');

        $firstOption = $xpath->query('//select[@name="status"]/option')->item(0);
        $this->assertSame('', $firstOption->getAttribute('value'));
        $this->assertSame('All Statuses', trim($firstOption->textContent));

        $url = $this->recordsExportUrl($clearedPage->getContent());
        $this->assertStringNotContainsString('force_scope', $url);

        $sheets = $this->sheetStatuses($this->get($url));

        $this->assertSame(['Pending', 'Cleared'], array_keys($sheets));
        $this->assertCount(3, $sheets['Pending']['statuses']);
        $this->assertCount(3, $sheets['Cleared']['statuses']);

        // With the filter cleared both statuses are legitimately present, and each
        // sheet must still carry only rows of its own status.
        $this->assertSame(['pending', 'pending', 'pending'], $sheets['Pending']['statuses']);
        $this->assertSame(['cleared', 'cleared', 'cleared'], $sheets['Cleared']['statuses']);
    }

    /**
     * The export row count must equal the on-screen paginator total for every filter
     * combination — the core "what you see is what you export" guarantee.
     */
    public static function recordsFilterProvider(): array
    {
        // Fixture: pending 06-01/100, pending 06-10/200, pending 07-01/300,
        //           cleared 06-05/400, cleared 07-15/500, cleared 08-01/600.
        return [
            'no filter' => [[], 6, 6],
            'status pending' => [['status' => 'pending'], 3, 3],
            'status cleared' => [['status' => 'cleared'], 3, 3],
            'log sheet no' => [['log_sheet_no' => '0000000002'], 1, 1],
            // June holds 06-01, 06-10 and 06-05 -> 3.
            'date range June' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30'], 3, 3],
            // July holds 07-01 and 07-15 -> 2.
            'date range July' => [['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], 2, 2],
            'status + dates' => [['status' => 'pending', 'date_from' => '2026-06-01', 'date_to' => '2026-06-30'], 2, 2],
            'status + number' => [['status' => 'cleared', 'log_sheet_no' => '0000000004'], 1, 1],
            // All three pending rows fall inside 06-01..07-31 -> 3.
            'all filters combined' => [[
                'status' => 'pending',
                'log_sheet_no' => '000000000',
                'date_from' => '2026-06-01',
                'date_to' => '2026-07-31',
            ], 3, 3],
            // No rows at all: headers only, single sheet.
            'filter matching nothing' => [['log_sheet_no' => 'ZZZZZZZZZZ'], 0, 0],
        ];
    }

    /**
     * @dataProvider recordsFilterProvider
     */
    public function test_records_export_row_count_always_matches_the_visible_paginator(array $query, int $expectedTotal, int $expectedExported): void
    {
        $this->seedFixture();

        $page = $this->get(route('logsheets.records', $query));
        $page->assertOk();

        $this->assertSame(
            $expectedTotal,
            $page->viewData('logsheets')->total(),
            'On-screen row count for '.json_encode($query)
        );

        $sheets = $this->sheetStatuses($this->get($this->recordsExportUrl($page->getContent())));

        $this->assertSame(
            $expectedExported,
            array_sum(array_map(fn ($rows) => count($rows['numbers']), $sheets)),
            'Exported row count for '.json_encode($query)
        );
    }

    // =====================================================================
    // 2. Import detail: filter -> Export
    // =====================================================================

    public function test_import_detail_completed_filter_export_yields_cleared_only_workbook(): void
    {
        $import = $this->seedFixture();

        $page = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));
        $page->assertOk();
        $this->assertSame(3, $page->viewData('logsheets')->total());

        $url = $this->importExportUrl($page->getContent());
        $this->assertStringContainsString('status=completed', $url);

        $response = $this->get($url);
        $response->assertOk();

        $sheets = $this->sheetStatuses($response);

        $this->assertSame(['Cleared'], array_keys($sheets));
        $this->assertOnlyStatus($sheets, 'cleared');
        $this->assertCount(3, $sheets['Cleared']['numbers']);
    }

    public function test_import_detail_pending_filter_export_yields_pending_only_workbook(): void
    {
        $import = $this->seedFixture();

        $page = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'pending']));
        $page->assertOk();

        $url = $this->importExportUrl($page->getContent());
        $this->assertStringContainsString('status=pending', $url);

        $sheets = $this->sheetStatuses($this->get($url));

        $this->assertSame(['Pending'], array_keys($sheets));
        $this->assertOnlyStatus($sheets, 'pending');
        $this->assertCount(3, $sheets['Pending']['numbers']);
    }

    public function test_import_detail_clearing_filter_back_to_all_statuses_restores_both_sheets(): void
    {
        $import = $this->seedFixture();

        $filtered = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));
        $filtered->assertOk();
        $this->assertSame(['Cleared'], array_keys($this->sheetStatuses(
            $this->get($this->importExportUrl($filtered->getContent()))
        )));

        $all = $this->get(route('logsheets.imports.show', $import));
        $all->assertOk();
        $this->assertSame(6, $all->viewData('logsheets')->total());

        $url = $this->importExportUrl($all->getContent());
        $this->assertStringNotContainsString('status=', $url);
        $this->assertStringNotContainsString('force_status', $url);

        $sheets = $this->sheetStatuses($this->get($url));

        $this->assertSame(['Pending', 'Cleared'], array_keys($sheets));
        $this->assertCount(3, $sheets['Pending']['statuses']);
        $this->assertCount(3, $sheets['Cleared']['statuses']);
    }

    /**
     * An import export must never leak another import's rows, whatever the status filter.
     */
    public function test_import_detail_export_never_leaks_another_imports_rows(): void
    {
        $mine = $this->seedFixture();

        $other = $this->makeImport(['original_filename' => 'other.xlsx']);
        foreach (['9000000001', '9000000002'] as $i => $number) {
            DB::table('logsheets')->insert([
                'log_sheet_no' => $number,
                'date' => '2026-06-01',
                'vehicle_no' => 'VH-X'.$i,
                'tprt_code' => '007',
                'tprt_name' => 'Other',
                'destination' => 'Chennai',
                'sap_invoice_no' => 'SAP-OTHER',
                'posting_date' => '2026-06-01',
                'bill_date' => '2026-06-01',
                'vendor_inv_no' => 'VEN-OTHER',
                'total_gross_wt' => '1.000',
                'total_booked_amount' => '10.00',
                'total_actual_amount' => '10.00',
                'total_diff' => '0.00',
                'consignment_count' => 1,
                'status' => 'pending',
                'last_import_id' => $other->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([[], ['status' => 'pending'], ['status' => 'completed'], ['force_status' => 'all']] as $query) {
            $response = $this->get(route('logsheets.imports.export', array_merge(['import' => $mine], $query)));
            $response->assertOk();

            foreach ($this->sheetStatuses($response) as $rows) {
                foreach ($rows['numbers'] as $number) {
                    $this->assertStringStartsNotWith('9', $number, 'Leaked a row from another import.');
                }
            }
        }
    }

    // =====================================================================
    // 3. Stale dropdown: an unapplied selection must not be treated as applied
    // =====================================================================

    /**
     * A GET filter form can only ever reflect what is in the address bar. Selecting a
     * value in the dropdown without pressing Apply cannot change the server's view, so
     * the export must keep exporting the previously applied filter — and the page must
     * not advertise the un-applied value.
     */
    public function test_records_unapplied_dropdown_selection_does_not_change_the_export(): void
    {
        $this->seedFixture();

        $applied = $this->get(route('logsheets.records', ['status' => 'pending']));
        $applied->assertOk();

        $html = $applied->getContent();

        // The rendered select reflects the APPLIED status (pending), and the export
        // form carries status=pending.
        $selected = $this->xpath($html)->query('//select[@name="status"]/option[@selected]');
        $this->assertSame(1, $selected->length);
        $this->assertSame('pending', $selected->item(0)->getAttribute('value'));

        $url = $this->recordsExportUrl($html);
        $this->assertStringContainsString('status=pending', $url);
        $this->assertStringNotContainsString('status=cleared', $url);

        // And the export the form points at is genuinely pending-only.
        $sheets = $this->sheetStatuses($this->get($url));
        $this->assertSame(['Pending'], array_keys($sheets));
        $this->assertOnlyStatus($sheets, 'pending');
    }

    /**
     * The records page must never render a stale status label: the "Exports exactly what
     * is on screen" caption is derived from the applied status on the server.
     */
    public function test_records_export_caption_reports_the_applied_status_not_a_stale_one(): void
    {
        $this->seedFixture();

        $cases = [
            [['status' => 'pending'], 'Pending only'],
            [['status' => 'cleared'], 'Cleared only'],
            [[], 'Pending + Cleared (2 sheets)'],
        ];

        foreach ($cases as [$query, $expected]) {
            $content = $this->get(route('logsheets.records', $query))->getContent();

            $this->assertStringContainsString(
                $expected,
                $content,
                'Wrong export caption for '.json_encode($query)
            );
        }
    }

    /**
     * Guards the Alpine auto-submit expression. `this.form.submit()` is *not* equivalent
     * to `$el.form.submit()`: Alpine evaluates x-on handlers with `this` unbound, so the
     * former throws a TypeError and the dropdown silently does nothing — which is exactly
     * the stale-dropdown symptom, because the select then shows a value that was never
     * applied to the table or the export.
     */
    public function test_import_detail_autosubmit_uses_alpine_el_magic_not_this(): void
    {
        $import = $this->seedFixture();

        $content = $this->get(route('logsheets.imports.show', $import))->getContent();

        $this->assertStringContainsString('x-on:change="$el.form.submit()"', $content);
        $this->assertStringNotContainsString('this.form.submit()', $content);

        $select = $this->xpath($content)->query('//select[@name="status"]');
        $this->assertSame(1, $select->length);
        $this->assertSame('$el.form.submit()', $select->item(0)->getAttribute('x-on:change'));
    }

    /**
     * No Alpine expression in the module may dereference the `result` object unguarded.
     * `result` is `null` until a clear actually completes, and `x-show` only toggles CSS,
     * so Alpine evaluates those `x-text` bindings on every page load. Verified in Chrome:
     * each unguarded one threw "Cannot read properties of null (reading 'counts')".
     */
    public function test_clear_payments_result_bindings_are_null_safe(): void
    {
        $import = $this->seedFixture();

        foreach ([
            route('logsheets.index'),
            route('logsheets.records'),
            route('logsheets.imports.show', $import),
        ] as $url) {
            $response = $this->get($url);
            $response->assertOk();

            $content = $response->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/x-text="[^"]*\bresult\.(?!\?)/',
                $content,
                'Unguarded `result.` dereference in an Alpine binding on '.$url
                    .' — it throws on every page load while result is null.'
            );
        }
    }

    // =====================================================================
    // 4. Module sweep: no 500s, no broken links, exports stay authorized
    // =====================================================================

    public function test_every_logsheets_page_renders_without_server_errors(): void
    {
        $import = $this->seedFixture();
        $logsheet = Logsheet::where('status', 'pending')->first();

        $pages = [
            route('logsheets.index'),
            route('logsheets.records'),
            route('logsheets.records', ['status' => 'pending']),
            route('logsheets.records', ['status' => 'cleared']),
            route('logsheets.records', ['status' => 'pending', 'sort' => 'date', 'direction' => 'asc']),
            route('logsheets.records', ['log_sheet_no' => '0000000001']),
            route('logsheets.records', ['date_from' => '2026-06-01', 'date_to' => '2026-07-31']),
            route('logsheets.imports.show', $import),
            route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']),
            route('logsheets.imports.show', ['import' => $import, 'status' => 'pending']),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        // Destroy targets must at least resolve (they redirect, they must not 500).
        $this->actingAs($this->superAdmin);
        $this->delete(route('logsheets.destroy', $logsheet))->assertRedirect();
        $this->delete(route('logsheets.imports.destroy', $import))->assertRedirect();
    }

    public function test_no_internal_link_in_the_module_points_at_a_missing_route(): void
    {
        $import = $this->seedFixture();

        $pages = [
            route('logsheets.index'),
            route('logsheets.records'),
            route('logsheets.records', ['status' => 'pending']),
            route('logsheets.imports.show', $import),
        ];

        $checked = 0;

        foreach ($pages as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            foreach ($this->xpath($content)->query('//a[@href]') as $anchor) {
                $href = (string) $anchor->getAttribute('href');

                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                    continue;
                }

                // Only same-app absolute links can be resolved by the test client.
                if (! str_starts_with($href, config('app.url'))) {
                    continue;
                }

                // Export links legitimately return a file download, so assert they are
                // reachable (2xx) rather than that they return HTML.
                $response = $this->get($href);

                $this->assertLessThan(
                    400,
                    $response->getStatusCode(),
                    'Broken link on '.$url.' -> '.$href
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(10, $checked, 'Expected to check a meaningful number of links');
    }

    public function test_both_export_endpoints_return_a_workbook_for_every_supported_parameter(): void
    {
        $import = $this->seedFixture();

        $urls = [
            route('logsheets.export'),
            route('logsheets.export', ['status' => 'pending']),
            route('logsheets.export', ['status' => 'cleared']),
            route('logsheets.export', ['force_scope' => 'all']),
            route('logsheets.export', ['status' => 'cleared', 'force_scope' => 'pending']),
            route('logsheets.export', ['scope' => 'all']),
            route('logsheets.imports.export', $import),
            route('logsheets.imports.export', ['import' => $import, 'status' => 'completed']),
            route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']),
            route('logsheets.imports.export', ['import' => $import, 'force_status' => 'all']),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
            $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
            $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

            // It really is a readable workbook.
            $this->assertNotEmpty($this->loadWorkbook($response)->getSheetNames());
        }
    }
}
