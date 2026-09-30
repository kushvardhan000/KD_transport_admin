<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetClearing;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class LogsheetModuleSmokeTest extends TestCase
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
            'original_filename' => 'smoke_logsheets.xlsx',
            'file_path' => 'logsheet_imports/smoke_logsheets.xlsx',
            'uploaded_by' => $this->superAdmin->id,
            'row_count' => 4,
            'consolidated_count' => 2,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '4000.00',
            'total_booked_amount' => '4000.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '4000.000',
            'out_of_range_rows' => 0,
            'skipped_out_of_range_groups' => 0,
            'fully_out_of_range_groups' => 0,
        ], $overrides));
    }

    private function makeLogsheet(LogsheetImport $import, array $overrides = []): Logsheet
    {
        return Logsheet::create(array_merge([
            'log_sheet_no' => '0045350959',
            'date' => '2026-06-15',
            'vehicle_no' => 'VH-01',
            'tprt_code' => '007',
            'tprt_name' => 'Transporter A',
            'destination' => 'Chennai',
            'sap_invoice_no' => 'SAP-000123',
            'posting_date' => '2026-06-16',
            'bill_date' => '2026-06-17',
            'vendor_inv_no' => 'VEN-0009',
            'total_gross_wt' => '4000.000',
            'total_booked_amount' => '4000.00',
            'total_actual_amount' => '4100.00',
            'total_diff' => '100.00',
            'consignment_count' => 2,
            'status' => 'pending',
            'last_import_id' => $import->id,
        ], $overrides));
    }

    private function makeDetail(Logsheet $logsheet, array $overrides = []): LogsheetDetail
    {
        return LogsheetDetail::create(array_merge([
            'logsheet_id' => $logsheet->id,
            'log_sheet_no' => $logsheet->log_sheet_no,
            'date' => '2026-06-15',
            'invoice_no' => 'INV-001',
            'gross_wt' => '2000.000',
            'amount' => '2000.00',
            'town' => 'Kolkata',
            'town_2' => 'Howrah',
            'tprt_code' => '007',
            'tprt_name' => 'Transporter A',
            'destination' => 'Chennai',
        ], $overrides));
    }

    private function seedFullFixture(): array
    {
        $import = $this->makeImport();

        $pending = $this->makeLogsheet($import);
        $this->makeDetail($pending);
        $this->makeDetail($pending, ['invoice_no' => 'INV-002', 'town' => 'Madras']);

        $cleared = $this->makeLogsheet($import, [
            'log_sheet_no' => '0001112223',
            'date' => '2026-07-15',
            'status' => 'cleared',
            'cleared_at' => now(),
            'cleared_by' => $this->superAdmin->id,
        ]);
        $this->makeDetail($cleared);

        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => $pending->log_sheet_no,
            'raw_data' => ['gross_wt' => '2000.000'],
            'row_number_in_file' => 1,
            'is_valid' => true,
        ]);

        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => 'BAD0001',
            'raw_data' => ['gross_wt' => '', 'amount' => ''],
            'row_number_in_file' => 2,
            'is_valid' => false,
            'validation_error' => 'Invalid amount',
        ]);

        return [$import, $pending, $cleared];
    }

    public static function logsheetPageProvider(): array
    {
        return [
            'imports index' => ['logsheets.index', []],
            'records' => ['logsheets.records', []],
        ];
    }

    /**
     * @dataProvider logsheetPageProvider
     */
    public function test_pages_render_for_super_admin(string $routeName, array $params): void
    {
        $this->seedFullFixture();

        $response = $this->get(route($routeName, $params));

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('download', strtolower($response->getContent()));
    }

    public function test_logsheet_show_page_renders_full_fixture(): void
    {
        [, $pending] = $this->seedFullFixture();

        $response = $this->get(route('logsheets.show', $pending));

        $response->assertStatus(200);
        $response->assertSee('0045350959');
        $response->assertSee('INV-001');
        $response->assertSee('INV-002');
    }

    public function test_records_page_filters_do_not_crash(): void
    {
        $this->seedFullFixture();

        $queries = [
            ['log_sheet_no' => '0045'],
            ['transport' => 'Transporter'],
            ['town' => 'Kolkata'],
            ['destination' => 'Chennai'],
            ['location' => 'Chennai'],
            ['vehicle_no' => 'VH'],
            ['sap_invoice_no' => 'SAP'],
            ['vendor_inv_no' => 'VEN'],
            ['status' => 'pending'],
            ['status' => 'cleared'],
            ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'],
            ['posting_date_from' => '2026-01-01', 'posting_date_to' => '2026-12-31'],
            ['bill_date_from' => '2026-01-01', 'bill_date_to' => '2026-12-31'],
            ['min_gross_wt' => '10', 'max_gross_wt' => '99999'],
            ['min_amount' => '10', 'max_amount' => '99999'],
            ['sort' => 'log_sheet_no', 'direction' => 'asc'],
            ['sort' => 'town', 'direction' => 'asc'],
            ['sort' => 'bogus', 'direction' => 'sideways'],
            ['log_sheet_no' => '0045', 'status' => 'pending', 'date_from' => '2026-01-01', 'sort' => 'amount', 'direction' => 'desc'],
        ];

        foreach ($queries as $query) {
            $response = $this->get(route('logsheets.records', $query));
            $response->assertStatus(200, 'Failed for query: '.json_encode($query));
        }
    }

    public function test_records_page_rejects_invalid_filter_values_without_500(): void
    {
        $this->seedFullFixture();

        $invalid = [
            ['date_from' => '13/06/2026'],
            ['date_to' => '2026-02-30'],
            ['posting_date_from' => 'nope'],
            ['bill_date_to' => 'nope'],
            ['min_gross_wt' => 'abc'],
            ['max_amount' => '-5'],
            ['status' => 'unknown'],
        ];

        foreach ($invalid as $query) {
            $response = $this->get(route('logsheets.records', $query));
            $response->assertStatus(302, 'Failed for query: '.json_encode($query));
            $response->assertSessionHasErrors();
        }
    }

    public function test_records_page_paginates(): void
    {
        $import = $this->makeImport();
        for ($i = 1; $i <= 30; $i++) {
            $this->makeLogsheet($import, ['log_sheet_no' => 'PG'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }

        $response = $this->get(route('logsheets.records'));
        $response->assertStatus(200);
        $this->assertSame(30, $response->viewData('logsheets')->total());
        $this->assertCount(25, $response->viewData('logsheets')->items());

        $page2 = $this->get(route('logsheets.records', ['page' => 2]));
        $page2->assertStatus(200);
        $this->assertCount(5, $page2->viewData('logsheets')->items());
    }

    public function test_records_page_renders_orphan_logsheet_without_import(): void
    {
        $this->makeLogsheet($this->makeImport(), ['last_import_id' => null]);

        $response = $this->get(route('logsheets.records'));

        $response->assertStatus(200);
        $response->assertSee('0045350959');
    }

    public function test_records_page_renders_when_all_data_is_missing(): void
    {
        $response = $this->get(route('logsheets.records'));

        $response->assertStatus(200);
        $this->assertSame(0, $response->viewData('logsheets')->total());
    }

    public function test_index_page_filters_and_pagination_do_not_crash(): void
    {
        $this->makeImport();

        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.index', ['period_from' => '2026-01-01', 'period_to' => '2026-12-31']))->assertStatus(200);
        $this->get(route('logsheets.index', ['period_from' => 'bad']))->assertStatus(302);
    }

    public function test_every_logsheets_page_respects_auth_active_and_role_middleware(): void
    {
        $import = $this->makeImport();
        $logsheet = $this->makeLogsheet($import);

        $urls = [
            route('logsheets.index'),
            route('logsheets.records'),
            route('logsheets.export'),
            route('logsheets.imports.show', $import),
            route('logsheets.imports.export', $import),
            route('logsheets.show', $logsheet),
        ];

        // Guest -> login redirect
        auth()->logout();
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        // Admin -> 403
        $this->actingAs(User::factory()->admin()->create());
        foreach ($urls as $url) {
            $this->get($url)->assertStatus(403);
        }

        // Inactive super admin -> login redirect with error
        $this->actingAs(User::factory()->superAdmin()->inactive()->create());
        $response = $this->get(route('logsheets.records'));
        $response->assertRedirect(route('login'));
    }

    public function test_all_logsheet_routes_are_registered_and_callable(): void
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'logsheets.')) {
                continue;
            }

            $names[] = $name;
            $this->assertStringNotContainsString('download', strtolower($name));

            $action = $route->getAction('controller');
            $this->assertNotNull($action, "Route {$name} has no controller action");

            [$class, $method] = explode('@', $action);
            $controller = app()->make($class);
            $this->assertTrue(is_callable([$controller, $method]), "{$class}::{$method} not callable for {$name}");

            $middleware = $route->gatherMiddleware();
            $this->assertContains('role:super_admin', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('active', $middleware);
            $this->assertContains('no.cache', $middleware);
        }

        foreach ([
            'logsheets.index',
            'logsheets.records',
            'logsheets.export',
            'logsheets.imports.show',
            'logsheets.imports.export',
            'logsheets.show',
            'logsheets.store',
            'logsheets.destroy',
            'logsheets.imports.destroy',
            'logsheets.clear',
            'logsheets.clear.preview',
            'logsheets.clear.bulk',
        ] as $expected) {
            $this->assertContains($expected, $names, "Missing route {$expected}");
        }
    }

    public function test_import_show_page_renders_every_state(): void
    {
        [$import] = $this->seedFullFixture();

        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);
        $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'completed']))->assertStatus(200);
        $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'pending']))->assertStatus(200);
        $this->get(route('logsheets.imports.show', ['import' => $import, 'status' => 'bogus']))->assertStatus(302);
    }

    public function test_import_show_page_renders_when_import_has_missing_relations(): void
    {
        $import = $this->makeImport(['uploaded_by' => null, 'date_from' => null, 'date_to' => null]);
        $this->makeLogsheet($import);

        $response = $this->get(route('logsheets.imports.show', $import));

        $response->assertStatus(200);
    }

    public function test_import_show_returns_404_for_unknown_import(): void
    {
        $this->get('/logsheets/imports/999999')->assertStatus(404);
    }

    public function test_logsheet_show_returns_404_for_unknown_logsheet(): void
    {
        $this->get('/logsheets/999999')->assertStatus(404);
    }

    public function test_exports_work_for_every_filter_combination(): void
    {
        $this->seedFullFixture();

        $queries = [
            [],
            ['scope' => 'all'],
            ['scope' => 'pending'],
            ['scope' => 'cleared'],
            ['scope' => 'all', 'status' => 'cleared'],
            ['log_sheet_no' => '0045', 'scope' => 'all'],
            ['date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'scope' => 'pending'],
            ['transport' => 'Transporter', 'scope' => 'cleared'],
            ['min_amount' => '1', 'scope' => 'all'],
            ['sort' => 'date', 'direction' => 'asc', 'scope' => 'all'],
        ];

        foreach ($queries as $query) {
            $response = $this->get(route('logsheets.export', $query));
            $response->assertStatus(200, 'Failed for query: '.json_encode($query));
            $this->assertStringContainsString(
                'spreadsheetml.sheet',
                (string) $response->headers->get('content-type')
            );
        }
    }

    public function test_export_with_invalid_input_does_not_crash(): void
    {
        [$import] = $this->seedFullFixture();

        $this->get(route('logsheets.export', ['scope' => 'bogus']))->assertStatus(302);
        $this->get(route('logsheets.export', ['date_from' => 'bad']))->assertStatus(302);
        $this->get(route('logsheets.export', ['status' => 'unknown']))->assertStatus(302);
        $this->get(route('logsheets.imports.export', ['import' => $import, 'status' => 'bogus']))->assertStatus(302);
        $this->get('/logsheets/imports/999999/export')->assertStatus(404);
    }

    public function test_import_store_flow_creates_data_and_rerenders(): void
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
                    'SMOKE001', '2026-06-10', 'INV-001', '2026-06-10', 'PAY-001', 'Payer One',
                    'Kolkata', '1000.000', '100.00', '1100.00', '25', 'T01', 'Transporter A',
                    'VH-01', 'Chennai', 'SAP-001', '2026-06-10', '2026-06-11', 'VEN-001',
                    'Route A', 'Kolkata', '1000.000', '5000.00', '100', '6100.00', '100.00',
                ],
            ],
        ]);

        $response = $this->call('POST', '/logsheets', [
            'file' => UploadedFile::fake()->create('smoke.xlsx'),
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHasNoErrors();

        $this->assertNotNull(Logsheet::where('log_sheet_no', 'SMOKE001')->first());
        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.records'))->assertStatus(200);
    }

    public function test_clear_endpoints_flow(): void
    {
        $import = $this->makeImport();
        $logsheet = $this->makeLogsheet($import, ['log_sheet_no' => 'SMK001']);
        $this->makeDetail($logsheet, ['log_sheet_no' => 'SMK001']);

        $this->get('/logsheets');

        $preview = $this->postJson('/logsheets/clear/preview', [
            'numbers' => ['SMK001'],
            '_token' => csrf_token(),
        ]);
        $preview->assertStatus(200);
        $this->assertSame('pending', $preview->json('items.0.status'));

        $bulk = $this->post('/logsheets/clear/bulk', [
            'numbers' => ['SMK001'],
            '_token' => csrf_token(),
        ]);
        $bulk->assertRedirect();
        $this->assertSame('cleared', Logsheet::find($logsheet->id)->status);
        $this->assertSame(1, LogsheetClearing::where('logsheet_id', $logsheet->id)->count());

        $single = $this->post('/logsheets/clear', [
            'log_sheet_no' => 'SMK001',
            '_token' => csrf_token(),
        ]);
        $single->assertRedirect();
        $single->assertSessionHas('info');
    }

    public function test_destroy_import_with_only_invalid_raw_rows_does_not_crash(): void
    {
        $import = $this->makeImport();

        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => 'BAD0001',
            'raw_data' => ['gross_wt' => ''],
            'row_number_in_file' => 1,
            'is_valid' => false,
            'validation_error' => 'Invalid amount',
        ]);

        $response = $this->call('DELETE', route('logsheets.imports.destroy', $import), ['_token' => csrf_token()]);

        $response->assertRedirect('/logsheets');
        $response->assertSessionHasNoErrors();
        $this->assertSame(0, LogsheetRawRow::where('import_id', $import->id)->count());
        $this->get(route('logsheets.index'))->assertStatus(200);
    }

    public function test_destroy_import_after_partial_logsheet_deletion_does_not_crash(): void
    {
        [$import, $pending] = $this->seedFullFixture();

        $this->call('DELETE', route('logsheets.destroy', $pending), ['_token' => csrf_token()])
            ->assertRedirect('/logsheets/records');

        $response = $this->call('DELETE', route('logsheets.imports.destroy', $import), ['_token' => csrf_token()]);
        $response->assertRedirect('/logsheets');

        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.records'))->assertStatus(200);
    }

    public function test_import_of_workbook_without_headers_fails_gracefully(): void
    {
        $this->get('/logsheets');

        Excel::fake();
        Excel::shouldReceive('toArray')->once()->andReturn([
            [
                ['Col A', 'Col B'],
                ['x', 'y'],
            ],
        ]);

        $response = $this->call('POST', '/logsheets', [
            'file' => UploadedFile::fake()->create('bad.xlsx'),
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');
        $this->assertStringContainsString('Missing Log Sheet No header', (string) $response->getSession()->get('error'));
        $this->assertNull($response->getSession()->get('success'));

        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.records'))->assertStatus(200);
    }

    public function test_import_of_malformed_rows_fails_gracefully(): void
    {
        $this->get('/logsheets');

        Excel::fake();
        Excel::shouldReceive('toArray')->once()->andReturn([
            [null, null, null],
        ]);

        $response = $this->call('POST', '/logsheets', [
            'file' => UploadedFile::fake()->create('weird.xlsx'),
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');
        $this->assertNull($response->getSession()->get('success'));
        $this->assertNotNull($response->getSession()->get('error'));

        $this->get(route('logsheets.index'))->assertStatus(200);
    }

    public function test_destroy_logsheet_and_import_keep_pages_working(): void
    {
        [$import, $logsheet] = $this->seedFullFixture();

        $delete = $this->call('DELETE', route('logsheets.destroy', $logsheet), ['_token' => csrf_token()]);
        $delete->assertRedirect('/logsheets/records');

        $this->get(route('logsheets.records'))->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);

        $deleteImport = $this->call('DELETE', route('logsheets.imports.destroy', $import), ['_token' => csrf_token()]);
        $deleteImport->assertRedirect('/logsheets');

        $this->get(route('logsheets.index'))->assertStatus(200);
        $this->get(route('logsheets.records'))->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(404);
    }
}
