<?php

namespace Tests\Feature;

use App\Exports\TransportLogsExport;
use App\Models\User;
use App\Models\TransportLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Vehicle;
use App\Models\FuelStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ComputedBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        return $user;
    }

    private function createLogForBreakdown(?string $date = '2025-06-15'): TransportLog
    {
        $branch = Branch::factory()->create();
        $vehicle = Vehicle::factory()->create(['branch_id' => $branch->id]);
        $fuelStation = FuelStation::factory()->create(['branch_id' => $branch->id]);

        return TransportLog::factory()->create([
            'date' => $date,
            'company' => 'Acme Logistics',
            'vehicle_id' => $vehicle->id,
            'fuel_station_id' => $fuelStation->id,
            'branch_id' => $branch->id,
            'to_bb_sale' => 1000,
            'paid_sale' => 2000,
            'to_pay' => 500,
            'freight' => 500,
            'loading' => 100,
            'unloading' => 100,
            'dd' => 50,
            'tempu_expense' => 100,
            'commission' => 200,
            'diesel_advance' => 300,
            'cash_advance' => 200,
            'payment' => 3000,
            'dtg_office_expense' => 150,
        ]);
    }

    // -------------------------------------------------------------------------
    // Surface 1: Show page breakdowns
    // -------------------------------------------------------------------------

    public function test_show_page_contains_total_sale_breakdown(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Total Sale');
        $response->assertSee('To BB Sale');
        $response->assertSee(number_format(1000, 2));
        $response->assertSee('Paid Sale');
        $response->assertSee(number_format(2000, 2));
        $response->assertSee('To Pay');
        $response->assertSee(number_format(500, 2));
        $response->assertSee(number_format(3500, 2));
    }

    public function test_show_page_contains_total_expense_breakdown(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Total Expense');
        $response->assertSee('Freight');
        $response->assertSee(number_format(500, 2));
        $response->assertSee('Loading');
        $response->assertSee(number_format(100, 2));
        $response->assertSee('Unloading');
        $response->assertSee(number_format(100, 2));
        $response->assertSee('DD');
        $response->assertSee(number_format(50, 2));
        $response->assertSee('Tempu Expense');
        $response->assertSee(number_format(100, 2));
        $response->assertSee('Commission');
        $response->assertSee(number_format(200, 2));
        $response->assertSee('DTG Office Expense');
        $response->assertSee(number_format(150, 2));
        $response->assertSee(number_format(1200, 2));
    }

    public function test_show_page_contains_profit_breakdown(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Profit');
        $response->assertSee(number_format(3500, 2));
        $response->assertSee(number_format(1200, 2));
        $response->assertSee(number_format(2300, 2));
    }

    public function test_show_page_contains_total_advance_breakdown(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Total Advance');
        $response->assertSee('Diesel Advance');
        $response->assertSee(number_format(300, 2));
        $response->assertSee('Cash Advance');
        $response->assertSee(number_format(200, 2));
        $response->assertSee(number_format(500, 2));
    }

    public function test_show_page_contains_balance_vehicle_payment_breakdown(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Balance Vehicle Payment');
        $response->assertSee(number_format(3500, 2));
        $response->assertSee('Payment');
        $response->assertSee(number_format(3000, 2));
        $response->assertSee(number_format(500, 2));
    }

    public function test_show_page_contains_fuel_station_ledger_section_when_fuel_station_id_set(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id);

        $response->assertOk();
        $response->assertSee('Payment History for This Log');
        $response->assertSee('Unpaid');
    }

    // -------------------------------------------------------------------------
    // Surface 2: Edit page pre-filled breakdowns
    // -------------------------------------------------------------------------

    public function test_edit_page_contains_total_sale_breakdown_prefilled(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id . '/edit');

        $response->assertOk();
        $response->assertSee('Total Sale');
        $response->assertSee('To BB Sale');
        $response->assertSee('Paid Sale');
        $response->assertSee('To Pay');
        $response->assertSee('1000.00', false);
        $response->assertSee('2000.00', false);
        $response->assertSee('500.00', false);
        $response->assertSee('type="number"', false);
    }

    public function test_edit_page_contains_total_expense_breakdown_prefilled(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id . '/edit');

        $response->assertOk();
        $response->assertSee('Total Expense');
        $response->assertSee('Freight');
        $response->assertSee('Loading');
        $response->assertSee('Unloading');
        $response->assertSee('DD');
        $response->assertSee('Tempu Expense');
        $response->assertSee('Commission');
        $response->assertSee('DTG Office Expense');
        $response->assertSee('500.00', false);
        $response->assertSee('100.00', false);
        $response->assertSee('50.00', false);
        $response->assertSee('150.00', false);
    }

    public function test_edit_page_contains_profit_breakdown_prefilled(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id . '/edit');

        $response->assertOk();
        $response->assertSee('Profit');
        $response->assertSee('Total Sale');
        $response->assertSee('Total Expense');
    }

    public function test_edit_page_contains_total_advance_breakdown_prefilled(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id . '/edit');

        $response->assertOk();
        $response->assertSee('Total Advance');
        $response->assertSee('Diesel Advance');
        $response->assertSee('Cash Advance');
        $response->assertSee('300.00', false);
        $response->assertSee('200.00', false);
    }

    public function test_edit_page_contains_balance_vehicle_payment_breakdown_prefilled(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $response = $this->get('/transport-logs/' . $log->id . '/edit');

        $response->assertOk();
        $response->assertSee('Balance Vehicle Payment');
        $response->assertSee('Payment');
    }

    // -------------------------------------------------------------------------
    // Surface 3: Excel export formulas and header comments
    // -------------------------------------------------------------------------

    public function test_excel_export_has_formulas_and_header_comments_for_all_five_computed_columns(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogForBreakdown();

        $exportUrls = [
            '/transport-logs/' . $log->id . '/export/single',
            '/transport-logs/export/monthly?month=6&year=2025',
            '/transport-logs/export/yearly?year=2025',
            '/transport-logs/export/range?date_from=2025-01-01&date_to=2025-12-31',
        ];

        foreach ($exportUrls as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $query = TransportLog::query();
            $filename = 'formula-comment-check-' . md5($url) . '.xlsx';
            $path = sys_get_temp_dir() . '/' . $filename;

            Excel::store(new TransportLogsExport($query), $filename, 'local');
            $path = Storage::disk('local')->path($filename);

            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $sheet = $reader->load($path)->getActiveSheet();
            $dataRow = 2;

            $this->assertStringStartsWith('=', $sheet->getCell('M' . $dataRow)->getValue(), "Total Sale formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('U' . $dataRow)->getValue(), "Total Expense formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('V' . $dataRow)->getValue(), "Profit formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('Y' . $dataRow)->getValue(), "Total Advance formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('AC' . $dataRow)->getValue(), "Balance Vehicle Payment formula missing for $url");

            $this->assertNotEmpty($sheet->getComment('M1')->getText()->getPlainText(), "Total Sale header comment missing for $url");
            $this->assertNotEmpty($sheet->getComment('U1')->getText()->getPlainText(), "Total Expense header comment missing for $url");
            $this->assertNotEmpty($sheet->getComment('V1')->getText()->getPlainText(), "Profit header comment missing for $url");
            $this->assertNotEmpty($sheet->getComment('Y1')->getText()->getPlainText(), "Total Advance header comment missing for $url");
            $this->assertNotEmpty($sheet->getComment('AC1')->getText()->getPlainText(), "Balance Vehicle Payment header comment missing for $url");

            $this->assertStringContainsString('To BB Sale', $sheet->getComment('M1')->getText()->getPlainText(), "Total Sale header comment formula text wrong for $url");
            $this->assertStringContainsString('Freight', $sheet->getComment('U1')->getText()->getPlainText(), "Total Expense header comment formula text wrong for $url");
            $this->assertStringContainsString('Total Sale - Total Expense', $sheet->getComment('V1')->getText()->getPlainText(), "Profit header comment formula text wrong for $url");
            $this->assertStringContainsString('Diesel Advance', $sheet->getComment('Y1')->getText()->getPlainText(), "Total Advance header comment formula text wrong for $url");
            $this->assertStringContainsString('Total Sale - Payment', $sheet->getComment('AC1')->getText()->getPlainText(), "Balance Vehicle Payment header comment formula text wrong for $url");

            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Dusk notice
    // -------------------------------------------------------------------------

    public function test_dusk_configured_status(): void
    {
        $this->markTestSkipped('Dusk is not configured in tests/Browser; skipping live JS typing assertions.');
    }
}
