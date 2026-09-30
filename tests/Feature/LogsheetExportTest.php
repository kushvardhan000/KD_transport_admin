<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\LogsheetDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LogsheetExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeImport(User $user, array $overrides = []): LogsheetImport
    {
        return LogsheetImport::create(array_merge([
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'original_filename' => 'june_logsheets.xlsx',
            'file_path' => 'logsheet_imports/june_logsheets.xlsx',
            'uploaded_by' => $user->id,
            'row_count' => 2,
            'consolidated_count' => 1,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'status' => 'completed',
            'total_amount' => '3000.00',
            'total_booked_amount' => '3000.00',
            'total_diff' => '0.00',
            'total_gross_wt' => '2000.000',
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
            'total_gross_wt' => '2000.000',
            'total_booked_amount' => '3000.00',
            'total_actual_amount' => '3100.00',
            'total_diff' => '100.00',
            'consignment_count' => 2,
            'status' => 'pending',
            'last_import_id' => $import->id,
        ], $overrides));
    }

    private function makeCleared(User $user): array
    {
        $import = $this->makeImport($user, ['original_filename' => 'cleared_file.xlsx']);

        $logsheet = $this->makeLogsheet($import, [
            'log_sheet_no' => '0001112223',
            'date' => '2026-07-20',
            'status' => 'cleared',
            'cleared_at' => now()->setDate(2026, 7, 21)->setTime(10, 30),
            'cleared_by' => $user->id,
        ]);

        LogsheetDetail::create([
            'logsheet_id' => $logsheet->id,
            'log_sheet_no' => $logsheet->log_sheet_no,
            'date' => '2026-07-20',
            'gross_wt' => '2000.000',
        ]);

        return [$logsheet, $import];
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

    public function test_export_all_scope_returns_xlsx_with_two_sheets(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheet($import);
        $this->makeCleared($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'all']));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $filename = $response->headers->get('content-disposition');
        $this->assertStringContainsString('logsheets_all_', (string) $filename);
        $this->assertStringContainsString('.xlsx', (string) $filename);

        $workbook = $this->loadWorkbook($response);
        $sheetNames = $workbook->getSheetNames();

        $this->assertCount(2, $sheetNames);
        $this->assertSame('Pending', $sheetNames[0]);
        $this->assertSame('Cleared', $sheetNames[1]);

        $pending = $workbook->getSheetByName('Pending');
        $this->assertSame('Log Sheet No', $pending->getCell('A1')->getValue());
        $this->assertSame('Import Period', $pending->getCell('T1')->getValue());
        $this->assertSame('pending', $pending->getCell('P2')->getValue());
        $this->assertSame('cleared', $workbook->getSheetByName('Cleared')->getCell('P2')->getValue());
    }

    public function test_export_pending_scope_returns_single_sheet(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $this->makeLogsheet($this->makeImport($user));
        $this->makeCleared($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'pending']));

        $response->assertStatus(200);
        $this->assertStringContainsString('logsheets_pending_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(2, $workbook->getSheetByName('Pending')->getHighestRow());
    }

    public function test_export_cleared_scope_returns_single_sheet(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $this->makeLogsheet($this->makeImport($user));
        $this->makeCleared($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'cleared']));

        $response->assertStatus(200);
        $this->assertStringContainsString('logsheets_cleared_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame(2, $workbook->getSheetByName('Cleared')->getHighestRow());
    }

    public function test_export_preserves_leading_zeros_and_numeric_amounts(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $this->makeLogsheet($this->makeImport($user));
        $this->makeCleared($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'pending']));
        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Pending');

        $logSheetNo = $sheet->getCell('A2')->getValue();
        $this->assertIsString($logSheetNo);
        $this->assertSame('0045350959', $logSheetNo);
        $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());

        $this->assertSame('007', $sheet->getCell('D2')->getValue());
        $this->assertSame('SAP-000123', $sheet->getCell('G2')->getValue());
        $this->assertSame('VEN-0009', $sheet->getCell('J2')->getValue());

        $this->assertSame(2, $sheet->getCell('K2')->getValue());
        $this->assertSame(2000.0, $sheet->getCell('L2')->getValue());
        $this->assertSame(3000.0, $sheet->getCell('M2')->getValue());
        $this->assertSame(3100.0, $sheet->getCell('N2')->getValue());
        $this->assertSame(100.0, $sheet->getCell('O2')->getValue());

        $this->assertSame('0.000', $sheet->getStyle('L2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('N2')->getNumberFormat()->getFormatCode());

        $this->assertSame('2026-06-15', $sheet->getCell('B2')->getValue());
        $this->assertSame('2026-06-16', $sheet->getCell('H2')->getValue());
        $this->assertSame('2026-06-17', $sheet->getCell('I2')->getValue());
        $this->assertSame('june_logsheets.xlsx', $sheet->getCell('S2')->getValue());
        $this->assertSame('2026-06-01 to 2026-06-30', $sheet->getCell('T2')->getValue());
    }

    public function test_export_populates_cleared_metadata_columns(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $this->makeCleared($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'cleared']));
        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Cleared');

        $this->assertSame('2026-07-21 10:30', $sheet->getCell('Q2')->getValue());
        $this->assertSame($user->name, $sheet->getCell('R2')->getValue());
        $this->assertSame('cleared_file.xlsx', $sheet->getCell('S2')->getValue());
    }

    public function test_export_respects_filters(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $import = $this->makeImport($user);
        $this->makeLogsheet($import, ['log_sheet_no' => '0045350959', 'date' => '2026-06-15']);
        $this->makeLogsheet($import, ['log_sheet_no' => '9999999999', 'date' => '2026-08-15']);
        $this->makeLogsheet($import, ['log_sheet_no' => '5555555555', 'date' => '2026-09-15']);

        $response = $this->get(route('logsheets.export', [
            'scope' => 'pending',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));

        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Pending');

        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('0045350959', $sheet->getCell('A2')->getValue());

        $response = $this->get(route('logsheets.export', [
            'scope' => 'pending',
            'log_sheet_no' => '5555555',
        ]));

        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $sheet = $workbook->getSheetByName('Pending');

        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('5555555555', $sheet->getCell('A2')->getValue());
    }

    /**
     * UPDATED (F8, bug B1) — this test replaces the former
     * `test_export_scope_overrides_status_filter`.
     *
     * The old test asserted the OLD, BUGGY decoupled behaviour: that `scope=cleared`
     * won over an applied `status=pending` filter, i.e. the export deliberately
     * contradicted the table the user was looking at. That is the defect this whole
     * change fixes, so its assertion had to be updated rather than preserved.
     *
     * What was deliberately NOT changed: the `scope` parameter is still accepted and
     * still honoured whenever no status filter is applied (covered by the other tests in
     * this file that pass `scope` on its own), and all other assertions are unchanged.
     */
    public function test_status_filter_wins_over_scope_parameter(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $this->makeLogsheet($this->makeImport($user));
        $this->makeCleared($user);

        // The on-screen status filter is authoritative: a stale scope param must not override it.
        $response = $this->get(route('logsheets.export', ['scope' => 'all', 'status' => 'pending']));

        $response->assertStatus(200);
        $this->assertStringContainsString('logsheets_pending_', (string) $response->headers->get('content-disposition'));

        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Pending'], $workbook->getSheetNames());
        $this->assertSame(2, $workbook->getSheetByName('Pending')->getHighestRow());
        $this->assertSame('pending', $workbook->getSheetByName('Pending')->getCell('P2')->getValue());

        $response = $this->get(route('logsheets.export', ['scope' => 'pending', 'status' => 'cleared']));

        $response->assertStatus(200);
        $workbook = $this->loadWorkbook($response);
        $this->assertSame(['Cleared'], $workbook->getSheetNames());
        $this->assertSame('cleared', $workbook->getSheetByName('Cleared')->getCell('P2')->getValue());
    }

    public function test_export_with_no_matching_rows_returns_headers_only(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'all']));

        $response->assertStatus(200);

        $workbook = $this->loadWorkbook($response);

        foreach (['Pending', 'Cleared'] as $name) {
            $sheet = $workbook->getSheetByName($name);
            $this->assertNotNull($sheet, "Missing sheet {$name}");
            $this->assertSame(1, $sheet->getHighestRow());
            $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());
        }
    }

    public function test_export_with_invalid_scope_fails_validation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'bogus']));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('scope');
    }

    public function test_export_with_invalid_date_fails_validation(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $response = $this->get(route('logsheets.export', ['scope' => 'all', 'date_from' => 'not-a-date']));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('date_from');
    }

    public function test_export_is_forbidden_for_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('logsheets.export', ['scope' => 'all']))->assertStatus(403);
    }

    public function test_export_redirects_guest_to_login(): void
    {
        $this->get(route('logsheets.export', ['scope' => 'all']))->assertRedirect(route('login'));
    }
}
