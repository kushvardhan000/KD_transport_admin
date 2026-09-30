<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LogsheetImportDetailTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(User $user, array $overrides = []): LogsheetImport
    {
        return LogsheetImport::create(array_merge([
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'original_filename' => 'june logsheets.xlsx',
            'file_path' => 'logsheet_imports/june_logsheets.xlsx',
            'uploaded_by' => $user->id,
            'row_count' => 30,
            'consolidated_count' => 30,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '30000.00',
            'total_booked_amount' => '30000.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '20000.000',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
        ], $overrides));
    }

    private function makeLogsheets(LogsheetImport $import, int $count, string $status, string $prefix = 'LS'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Logsheet::create([
                'log_sheet_no' => $prefix.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'date' => '2026-06-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'vehicle_no' => 'VH-'.$i,
                'tprt_code' => '007',
                'tprt_name' => 'Transporter A',
                'destination' => 'Chennai',
                'sap_invoice_no' => 'SAP-'.$i,
                'posting_date' => '2026-06-15',
                'bill_date' => '2026-06-16',
                'vendor_inv_no' => 'VEN-'.$i,
                'total_gross_wt' => '1000.000',
                'total_booked_amount' => '1000.00',
                'total_actual_amount' => '1000.00',
                'total_diff' => '0.00',
                'consignment_count' => 2,
                'status' => $status,
                'last_import_id' => $import->id,
            ]);
        }
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

    public function test_import_detail_paginates_log_sheets(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 30, 'pending');

        $response = $this->get(route('logsheets.imports.show', $import));

        $response->assertStatus(200);
        $logsheets = $response->viewData('logsheets');
        $this->assertSame(30, $logsheets->total());
        $this->assertCount(25, $logsheets->items());
        $this->assertSame(2, $logsheets->lastPage());

        $response->assertSee('Showing 1-25 of 30', false);

        $page2 = $this->get(route('logsheets.imports.show', ['import' => $import, 'page' => 2]));
        $page2->assertStatus(200);
        $this->assertCount(5, $page2->viewData('logsheets')->items());

        $page3 = $this->get(route('logsheets.imports.show', ['import' => $import, 'page' => 3]));
        $page3->assertStatus(200);
        $this->assertCount(0, $page3->viewData('logsheets')->items());
    }

    public function test_import_detail_status_filter_completed(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 4, 'cleared', 'C');

        $response = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));

        $response->assertStatus(200);
        $logsheets = $response->viewData('logsheets');
        $this->assertSame(4, $logsheets->total());
        $this->assertSame('completed', $response->viewData('scope'));

        foreach ($logsheets->items() as $logsheet) {
            $this->assertSame('cleared', $logsheet->status);
        }

        $response->assertSee('C000001', false);
        $response->assertDontSee('P000001', false);

        // Header counters stay unfiltered
        $this->assertSame(7, $response->viewData('totalCount'));
        $this->assertSame(4, $response->viewData('clearedCount'));
    }

    public function test_import_detail_status_filter_pending(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 4, 'cleared', 'C');

        $response = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'pending']));

        $response->assertStatus(200);
        $logsheets = $response->viewData('logsheets');
        $this->assertSame(3, $logsheets->total());

        foreach ($logsheets->items() as $logsheet) {
            $this->assertSame('pending', $logsheet->status);
        }

        $response->assertSee('P000001', false);
        $response->assertDontSee('C000001', false);
    }

    public function test_import_detail_filter_with_no_matches_renders_empty_state(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 2, 'pending');

        $response = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));

        $response->assertStatus(200);
        $this->assertSame(0, $response->viewData('logsheets')->total());
        $response->assertSee('No completed log sheets in this import.', false);
    }

    public function test_import_detail_invalid_status_fails_validation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 1, 'pending');

        $response = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'bogus']));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('status');
    }

    public function test_import_detail_paginates_invalid_rows(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);

        for ($i = 1; $i <= 30; $i++) {
            LogsheetRawRow::create([
                'import_id' => $import->id,
                'log_sheet_no' => 'BAD'.$i,
                'raw_data' => ['gross_wt' => '', 'amount' => (string) $i],
                'row_number_in_file' => $i,
                'is_valid' => false,
                'validation_error' => 'Invalid amount',
            ]);
        }

        $response = $this->get(route('logsheets.imports.show', $import));
        $response->assertStatus(200);

        $invalidRows = $response->viewData('invalidRows');
        $this->assertSame(30, $invalidRows->total());
        $this->assertCount(25, $invalidRows->items());
        $response->assertSee('Showing 1-25 of 30', false);

        $page2 = $this->get(route('logsheets.imports.show', ['import' => $import, 'invalid_page' => 2]));
        $page2->assertStatus(200);
        $this->assertCount(5, $page2->viewData('invalidRows')->items());
    }

    public function test_import_detail_renders_with_zero_logsheets_and_zero_invalid_rows(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);

        $response = $this->get(route('logsheets.imports.show', $import));

        $response->assertStatus(200);
        $response->assertSee('No log sheets in this import.', false);
        $response->assertSee('No invalid rows in this import.', false);
    }

    public function test_import_detail_page_has_no_download_text_or_routes(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 2, 'pending');

        $response = $this->get(route('logsheets.imports.show', $import));
        $response->assertStatus(200);
        $this->assertStringNotContainsString('download', strtolower($response->getContent()));

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! str_starts_with($name, 'logsheets.')) {
                continue;
            }
            $this->assertFalse(str_contains(strtolower($name), 'download'), "Route {$name} must not contain download");
        }
    }

    public function test_import_detail_page_is_forbidden_for_admin_and_redirects_guest(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $import = $this->makeImport($superAdmin);

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('logsheets.imports.show', $import))->assertStatus(403);
        $this->get(route('logsheets.imports.export', $import))->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $this->get(route('logsheets.imports.show', $import))->assertRedirect(route('login'));
        $this->get(route('logsheets.imports.export', $import))->assertRedirect(route('login'));
    }

    public function test_import_export_completed_returns_only_cleared(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'completed']));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('logsheets_import_'.$import->id.'_', $disposition);
        $this->assertStringContainsString('_completed_', $disposition);

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Cleared');
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());

        $values = array_map(fn ($row) => $sheet->getCell('A'.$row)->getValue(), range(2, 3));
        sort($values);
        $this->assertSame(['C000001', 'C000002'], $values);
    }

    public function test_import_export_pending_returns_only_pending(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']));

        $response->assertStatus(200);
        $this->assertStringContainsString('_pending_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        $sheet = $workbook->getSheetByName('Pending');
        $this->assertSame(4, $sheet->getHighestRow());
        $this->assertSame('pending', $sheet->getCell('P2')->getValue());
    }

    public function test_import_export_without_filter_returns_two_sheets(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        $response = $this->get(route('logsheets.imports.export', $import));

        $response->assertStatus(200);
        $this->assertStringContainsString('_all_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
        $this->assertSame(4, $workbook->getSheetByName('Pending')->getHighestRow());
        $this->assertSame(3, $workbook->getSheetByName('Cleared')->getHighestRow());
    }

    public function test_import_export_only_includes_this_import(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $importA = $this->makeImport($user, ['original_filename' => 'a.xlsx']);
        $importB = $this->makeImport($user, ['original_filename' => 'b.xlsx']);

        $this->makeLogsheets($importA, 2, 'pending', 'A');
        $this->makeLogsheets($importB, 3, 'pending', 'B');

        $response = $this->get(route('logsheets.imports.export', $importA));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Pending');

        $this->assertSame(3, $sheet->getHighestRow());

        $values = array_map(fn ($row) => $sheet->getCell('A'.$row)->getValue(), range(2, 3));
        sort($values);
        $this->assertSame(['A000001', 'A000002'], $values);
    }

    public function test_import_export_with_no_rows_returns_headers_only(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);

        $response = $this->get(route('logsheets.imports.export', $import));
        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);

        foreach (['Pending', 'Cleared'] as $name) {
            $sheet = $workbook->getSheetByName($name);
            $this->assertNotNull($sheet, "Missing sheet {$name}");
            $this->assertSame(1, $sheet->getHighestRow());
            $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());
        }
    }

    public function test_import_export_preserves_leading_zeros(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);

        Logsheet::create([
            'log_sheet_no' => '0045350959',
            'date' => '2026-06-15',
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

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');

        $this->assertSame('0045350959', $sheet->getCell('A2')->getValue());
        $this->assertSame('007', $sheet->getCell('D2')->getValue());
        $this->assertSame('SAP-000123', $sheet->getCell('G2')->getValue());
        $this->assertSame('VEN-0009', $sheet->getCell('J2')->getValue());
        $this->assertSame(3100.0, $sheet->getCell('N2')->getValue());
        $this->assertSame('june logsheets.xlsx', $sheet->getCell('S2')->getValue());
        $this->assertSame('2026-06-01 to 2026-06-30', $sheet->getCell('T2')->getValue());
    }

    public function test_import_export_invalid_status_fails_validation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);

        $response = $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'bogus']));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('status');
    }

    public function test_import_export_filename_is_sanitised(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user, ['original_filename' => '../../etc/passwd name!!.xlsx']);

        $response = $this->get(route('logsheets.imports.export', $import));
        $response->assertStatus(200);

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('logsheets_import_'.$import->id.'_passwd-name_all_', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('passwd name', $disposition);
    }

    /**
     * UPDATED (F8, bug B1) — this test replaces the former
     * `test_import_detail_export_links_carry_current_filters`.
     *
     * The old test asserted the OLD, BUGGY decoupled behaviour: that the import detail
     * page rendered three independent export links with hard-coded
     * `?status=completed` / `?status=pending` values, i.e. links that overrode the
     * page's status dropdown instead of following it. Those links were the defect, so
     * the assertion had to be updated rather than preserved.
     *
     * What was deliberately NOT changed: `status=completed` / `status=pending` remain
     * accepted on both the page and the export endpoint (asserted throughout this file),
     * and the `status`-less export still yields both sheets.
     *
     * The final assertion was additionally tightened in P2: it previously compared
     * against a bare URL that is a substring of the `?status=completed` href, so it
     * could never have failed. It now compares against the quoted href.
     */
    public function test_import_detail_export_link_follows_current_status_filter(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 2, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        // No status filter -> export every log sheet of the import (2 sheets).
        $unfiltered = $this->get(route('logsheets.imports.show', $import));
        $unfiltered->assertStatus(200);
        $this->assertStringContainsString(
            'href="'.route('logsheets.imports.export', $import).'"',
            $unfiltered->getContent()
        );

        // Status filter applied -> the export link must carry exactly that status.
        foreach (['completed', 'pending'] as $status) {
            $response = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => $status]));
            $response->assertStatus(200);

            $this->assertStringContainsString(
                'href="'.route('logsheets.imports.export', ['import' => $import, 'status' => $status]).'"',
                $response->getContent()
            );
        }

        // The primary link must never contradict the applied filter.
        $completedPage = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']));
        $this->assertStringNotContainsString(
            route('logsheets.imports.export', ['import' => $import, 'status' => 'pending']).'"',
            $completedPage->getContent()
        );
    }

    public function test_import_detail_primary_export_uses_the_submitted_status_value(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 2, 'pending', 'P');
        $this->makeLogsheets($import, 3, 'cleared', 'C');

        // The select is rendered from the submitted value, so it can never show an
        // unapplied choice on load.
        foreach (['completed' => 'Completed', 'pending' => 'Pending'] as $status => $label) {
            $page = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => $status]));
            $page->assertStatus(200);

            $content = $page->getContent();
            $this->assertStringContainsString(
                '<option value="'.$status.'" selected>'.$label.'</option>',
                $content
            );
            $this->assertStringContainsString('Export current view', $content);
        }

        // All Statuses when nothing is applied.
        $unfiltered = $this->get(route('logsheets.imports.show', $import));
        $unfiltered->assertStatus(200);
        $this->assertStringContainsString('<option value="" selected>All Statuses</option>', $unfiltered->getContent());
    }

    public function test_import_detail_status_filter_auto_submits_on_change(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 1, 'pending', 'P');

        $content = $this->get(route('logsheets.imports.show', $import))->getContent();

        // Auto-submit is what prevents the select from showing an unapplied value.
        //
        // The expression MUST use Alpine's `$el` magic property. `this.form` looks
        // equivalent but is dead on arrival: Alpine evaluates x-on expressions with
        // `this` unbound, so `this.form.submit()` throws
        // "TypeError: Cannot read properties of undefined (reading 'form')" in the
        // browser and the select silently does nothing. Verified in headless Chrome;
        // the previous literal-string assertion here passed while the handler was
        // completely non-functional, which is why this guards both spellings.
        $this->assertStringContainsString('x-on:change="$el.form.submit()"', $content);
        $this->assertStringNotContainsString('this.form.submit()', $content);

        // The Apply button is kept so the page still works without JavaScript.
        $this->assertStringContainsString('>Apply Filter<', preg_replace('/\s+/', ' ', $content));

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$content);
        $xpath = new \DOMXPath($dom);

        $selects = $xpath->query('//select[@name="status"]');
        $this->assertSame(1, $selects->length);
        $this->assertSame('$el.form.submit()', $selects->item(0)->getAttribute('x-on:change'));

        $forms = $xpath->query('//form[.//select[@name="status"]]');
        $this->assertSame(1, $forms->length);
        $this->assertSame(route('logsheets.imports.show', $import), $forms->item(0)->getAttribute('action'));
    }

    public function test_import_detail_export_overrides_are_secondary_and_use_force_status(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 1, 'pending', 'P');

        $content = $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']))->getContent();

        $this->assertStringContainsString('Override filter', $content);

        foreach (['all' => 'All (2 sheets)', 'completed' => 'Completed only', 'pending' => 'Pending only'] as $force => $label) {
            $expected = route('logsheets.imports.export', $import).'?'.http_build_query(['force_status' => $force]);
            $this->assertStringContainsString('href="'.htmlspecialchars($expected, ENT_QUOTES).'"', $content, 'Missing override for '.$label);
            $this->assertStringContainsString($label, $content);
        }

        // Overrides are plain anchors with muted styling and never carry the applied status.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$content);
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[contains(@href, "force_status=")]');

        $this->assertSame(3, $links->length);

        foreach ($links as $link) {
            /** @var \DOMElement $link */
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
            $this->assertArrayNotHasKey('status', $params, 'Override links must not carry the applied status filter');
            $this->assertArrayHasKey('force_status', $params);
            $this->assertStringContainsString('text-zinc-600', $link->getAttribute('class'));
            $this->assertStringNotContainsString('bg-brand-600', $link->getAttribute('class'));
        }
    }

    public function test_import_force_status_override_ignores_the_applied_status_filter(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 3, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        // Page shows Completed, override asks for Pending.
        $response = $this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'completed',
            'force_status' => 'pending',
        ]));

        $response->assertStatus(200);
        $this->assertStringContainsString('_pending_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(4, $workbook->getSheetByName('Pending')->getHighestRow());

        // Reverse direction.
        $response = $this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'pending',
            'force_status' => 'completed',
        ]));

        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(3, $workbook->getSheetByName('Cleared')->getHighestRow());

        // And "All" overrides a single status.
        $response = $this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'pending',
            'force_status' => 'all',
        ]));

        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending', 'Cleared'], $workbook->getSheetNames());
        $this->assertSame(4, $workbook->getSheetByName('Pending')->getHighestRow());
        $this->assertSame(3, $workbook->getSheetByName('Cleared')->getHighestRow());
    }

    public function test_import_force_status_precedence_and_validation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheets($import, 2, 'pending', 'P');
        $this->makeLogsheets($import, 2, 'cleared', 'C');

        // force_status beats the applied status.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'pending',
            'force_status' => 'completed',
        ])));
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        // Applied status still wins when no override is given.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'completed',
        ])));
        $this->assertSame(['Cleared'], $workbook->getSheetNames());

        // Empty override falls back to the applied status.
        $workbook = $this->loadWorkbook($this->get(route('logsheets.imports.export', [
            'import' => $import,
            'status' => 'pending',
            'force_status' => '',
        ])));
        $this->assertSame(['Pending'], $workbook->getSheetNames());

        // Invalid override is a validation error, not a 500.
        $response = $this->get(route('logsheets.imports.export', [
            'import' => $import,
            'force_status' => 'bogus',
        ]));
        $response->assertStatus(302);
        $response->assertSessionHasErrors('force_status');

        // Overrides remain scoped to their own import.
        $otherImport = $this->makeImport($user, ['original_filename' => 'other.xlsx']);
        $this->makeLogsheets($otherImport, 4, 'pending', 'O');

        $response = $this->get(route('logsheets.imports.export', [
            'import' => $import,
            'force_status' => 'pending',
        ]));
        $response->assertStatus(200);

        $sheet = $this->loadWorkbook($response)->getSheetByName('Pending');
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertStringStartsWith('P', (string) $sheet->getCell('A2')->getValue());
    }
}
