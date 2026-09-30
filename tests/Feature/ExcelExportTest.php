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
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

class ExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        return $user;
    }

    private function createLogWithDate(string $date, ?string $company = null): TransportLog
    {
        $branch = Branch::factory()->create();
        $vehicle = Vehicle::factory()->create(['branch_id' => $branch->id]);
        $fuelStation = FuelStation::factory()->create(['branch_id' => $branch->id]);

        return TransportLog::factory()->create([
            'date' => $date,
            'company' => $company ?? 'Acme Logistics',
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

    private function storeExport(\Illuminate\Database\Eloquent\Builder $query, string $filename): string
    {
        Storage::fake('local');
        Excel::store(new TransportLogsExport($query), $filename, 'local');

        return Storage::disk('local')->path($filename);
    }

    private function loadSpreadsheet(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $reader = new Xlsx();
        return $reader->load($path);
    }

    public function test_single_log_export_contains_formula_not_static_value(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogWithDate('2025-06-15');

        $response = $this->get('/transport-logs/' . $log->id . '/export/single');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = $this->storeExport(TransportLog::where('id', $log->id), 'single-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(2, $sheet->getHighestRow());

        $this->assertStringStartsWith('=', $sheet->getCell('M2')->getValue());
        $this->assertStringStartsWith('=', $sheet->getCell('U2')->getValue());
        $this->assertStringStartsWith('=', $sheet->getCell('V2')->getValue());
        $this->assertStringStartsWith('=', $sheet->getCell('Y2')->getValue());
        $this->assertStringStartsWith('=', $sheet->getCell('AC2')->getValue());
    }

    public function test_monthly_export_returns_only_requested_month(): void
    {
        $this->actingAsSuperAdmin();

        $this->createLogWithDate('2025-01-10');
        $this->createLogWithDate('2025-01-20');
        $this->createLogWithDate('2025-02-15');
        $this->createLogWithDate('2025-03-05');
        $this->createLogWithDate('2025-03-25');

        $response = $this->get('/transport-logs/export/monthly?month=2&year=2025');

        $response->assertOk();

        $query = TransportLog::query()
            ->whereMonth('date', 2)
            ->whereYear('date', 2025);

        $path = $this->storeExport($query, 'monthly-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(2, $sheet->getHighestRow());

        $dateCell = $sheet->getCell('B2')->getValue();
        $this->assertStringContainsString('2025-02', $dateCell);
    }

    public function test_yearly_export_returns_only_requested_year(): void
    {
        $this->actingAsSuperAdmin();

        $this->createLogWithDate('2024-06-15');
        $this->createLogWithDate('2025-01-10');
        $this->createLogWithDate('2025-07-20');
        $this->createLogWithDate('2025-11-05');

        $response = $this->get('/transport-logs/export/yearly?year=2025');

        $response->assertOk();

        $query = TransportLog::query()->whereYear('date', 2025);

        $path = $this->storeExport($query, 'yearly-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(4, $sheet->getHighestRow());

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $this->assertStringStartsWith('2025', $sheet->getCell('B' . $row)->getValue());
        }
    }

    public function test_date_range_export_respects_company_filter(): void
    {
        $this->actingAsSuperAdmin();

        $this->createLogWithDate('2025-01-10', 'Acme Logistics');
        $this->createLogWithDate('2025-02-15', 'Acme Logistics');
        $this->createLogWithDate('2025-03-05', 'Beta Transport');
        $this->createLogWithDate('2025-03-20', 'Acme Logistics');

        $response = $this->get('/transport-logs/export/range?date_from=2025-01-01&date_to=2025-03-01&company=Acme');

        $response->assertOk();

        $query = TransportLog::query()
            ->whereBetween('date', ['2025-01-01', '2025-03-01'])
            ->where('company', 'like', '%Acme%');

        $path = $this->storeExport($query, 'range-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow());

        $companyCell = $sheet->getCell('D2')->getValue();
        $this->assertStringContainsString('Acme', $companyCell);
    }

    public function test_all_export_types_contain_formulas_in_all_five_computed_columns(): void
    {
        $this->actingAsSuperAdmin();

        $log = $this->createLogWithDate('2025-06-15');

        $exportUrls = [
            '/transport-logs/' . $log->id . '/export/single',
            '/transport-logs/export/monthly?month=6&year=2025',
            '/transport-logs/export/yearly?year=2025',
            '/transport-logs/export/range?date_from=2025-01-01&date_to=2025-12-31',
        ];

        foreach ($exportUrls as $url) {
            $response = $this->get($url);
            $response->assertOk();

            $query = TransportLog::query();
            $filename = 'formula-check-' . md5($url) . '.xlsx';
            $path = $this->storeExport($query, $filename);
            $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

            $dataRow = 2;
            $this->assertStringStartsWith('=', $sheet->getCell('M' . $dataRow)->getValue(), "Total Sale formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('U' . $dataRow)->getValue(), "Total Expense formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('V' . $dataRow)->getValue(), "Profit formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('Y' . $dataRow)->getValue(), "Total Advance formula missing for $url");
            $this->assertStringStartsWith('=', $sheet->getCell('AC' . $dataRow)->getValue(), "Balance Vehicle Payment formula missing for $url");

            $this->assertNotNull($sheet->getComment('M1')->getText(), "Total Sale header comment missing for $url");
            $this->assertNotNull($sheet->getComment('U1')->getText(), "Total Expense header comment missing for $url");
            $this->assertNotNull($sheet->getComment('V1')->getText(), "Profit header comment missing for $url");
            $this->assertNotNull($sheet->getComment('Y1')->getText(), "Total Advance header comment missing for $url");
            $this->assertNotNull($sheet->getComment('AC1')->getText(), "Balance Vehicle Payment header comment missing for $url");
        }
    }

    public function test_single_export_requires_authentication(): void
    {
        $log = TransportLog::factory()->create();

        $response = $this->get('/transport-logs/' . $log->id . '/export/single');

        $response->assertRedirect('/login');
    }

    public function test_monthly_export_respects_active_filters(): void
    {
        $this->actingAsSuperAdmin();

        $this->createLogWithDate('2025-01-10', 'Acme Logistics');
        $this->createLogWithDate('2025-01-20', 'Acme Logistics');
        $this->createLogWithDate('2025-01-15', 'Beta Transport');

        $response = $this->get('/transport-logs/export/monthly?month=1&year=2025&company=Acme');

        $response->assertOk();

        $query = TransportLog::query()
            ->whereMonth('date', 1)
            ->whereYear('date', 2025)
            ->where('company', 'like', '%Acme%');

        $path = $this->storeExport($query, 'monthly-filter-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow());

        $companyCell = $sheet->getCell('D2')->getValue();
        $this->assertStringContainsString('Acme', $companyCell);
    }

    public function test_yearly_export_respects_active_filters(): void
    {
        $this->actingAsSuperAdmin();

        $this->createLogWithDate('2025-03-10', 'Acme Logistics');
        $this->createLogWithDate('2025-06-15', 'Acme Logistics');
        $this->createLogWithDate('2025-06-20', 'Beta Transport');

        $response = $this->get('/transport-logs/export/yearly?year=2025&search=Acme');

        $response->assertOk();

        $query = TransportLog::query()
            ->whereYear('date', 2025)
            ->where(function ($q) {
                $q->where('vehicle_no', 'like', '%Acme%')
                  ->orWhere('company', 'like', '%Acme%')
                  ->orWhere('transport_name', 'like', '%Acme%');
            });

        $path = $this->storeExport($query, 'yearly-filter-test.xlsx');
        $sheet = $this->loadSpreadsheet($path)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow());
    }
}
