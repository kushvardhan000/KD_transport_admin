<?php

namespace Tests\Feature;

use App\Exports\LogsheetsSheetExport;
use App\Models\Logsheet;
use App\Models\LogsheetClearing;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use App\Services\LogsheetClearingService;
use App\Services\LogsheetImportService;
use App\Services\LogsheetValueParser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FX-4: the rest of the module — clearing, exporting, deleting and re-importing
 * — has to keep working when a log sheet came from a minimal 4-column workbook.
 *
 * Everything a flexible import can leave NULL (dates, every optional column) or
 * change (the canonical form of a log sheet number) is exercised here, plus the
 * data backfill migration that brings pre-existing rows onto the same canonical
 * form.
 */
class LogsheetCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->superAdmin()->create();
        $this->actingAs($this->user);

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logsheet_compat_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Release the workbooks before deleting them, otherwise PhpSpreadsheet
        // keeps the whole package mapped and the suite creeps upwards.
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

    /**
     * The smallest workbook the importer accepts: the four required columns and
     * nothing else, so every optional column is NULL.
     *
     * @param  array<int, mixed>  $numbers
     * @param  array<int, array<int, mixed>>  $dataRows
     * @param  array<int, mixed>  $headers
     */
    protected function minimalImport(string $name, array $numbers, array $dataRows = [], array $headers = []): LogsheetImport
    {
        $rows = [$headers !== []
            ? $headers
            : ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date']];

        // Either give explicit rows, or one default row per number.
        $body = $dataRows !== []
            ? $dataRows
            : array_map(
                fn ($number, $index) => [$number, '2026-09-19', 'INV-'.($index + 1), '2026-09-19'],
                $numbers,
                array_keys($numbers)
            );

        foreach ($body as $row) {
            $rows[] = $row;
        }

        $summary = app(LogsheetImportService::class)->import($this->xlsx($rows, $name));

        return LogsheetImport::findOrFail($summary['import_id']);
    }

    // ------------------------------------- 1. log sheet number consistency

    public function test_clearing_normalizes_a_number_the_same_way_the_importer_stores_it(): void
    {
        $service = app(LogsheetClearingService::class);

        $this->assertSame(['45350959'], $service->normalize(['0045350959']));
        $this->assertSame(['45350959'], $service->normalize(['45350959.0']));
        $this->assertSame(['45350959'], $service->normalize(["  '0045350959' "]));
        $this->assertSame(['0'], $service->normalize(['000']));
        $this->assertSame(['0ABC'], $service->normalize(['0ABC']), 'an alphanumeric number is not stripped of characters');
        $this->assertSame(
            [LogsheetValueParser::logSheetNo('0045350959')],
            $service->normalize(['0045350959']),
            'clearing and importing must agree on the stored form'
        );
    }

    public function test_leading_zero_input_clears_a_sheet_stored_without_them(): void
    {
        $import = $this->minimalImport('leading-zero.xlsx', ['0045350959']);
        $logsheet = Logsheet::where('log_sheet_no', '45350959')->firstOrFail();

        $this->assertSame('45350959', $logsheet->log_sheet_no, 'the importer stores the canonical form');

        $this->post('/logsheets/clear', ['log_sheet_no' => '0045350959'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('cleared', $logsheet->fresh()->status);
        $this->assertSame(1, LogsheetDetail::where('log_sheet_no', '45350959')->where('cleared', true)->count());
        $this->assertSame($import->id, $logsheet->fresh()->last_import_id);
    }

    public function test_leading_zero_input_clears_a_sheet_that_a_previous_import_stored_with_zeros(): void
    {
        // The state the backfill migration repairs: a row stored with the zeros
        // the client sent, and nothing else to key it on.
        $import = LogsheetImport::create(['original_filename' => 'legacy.xlsx', 'status' => 'completed']);
        $logsheet = Logsheet::create([
            'log_sheet_no' => '0045350959',
            'date' => '2026-09-19',
            'status' => 'pending',
            'total_actual_amount' => 250,
            'last_import_id' => $import->id,
        ]);
        LogsheetDetail::create([
            'logsheet_id' => $logsheet->id,
            'log_sheet_no' => '0045350959',
            'date' => '2026-09-19',
            'invoice_no' => 'INV-LEGACY',
            'cleared' => false,
        ]);

        // The clearing service is asked for the canonical form, so it does not
        // match the not-yet-backfilled row; that is exactly what the migration
        // exists to fix.
        $preview = app(LogsheetClearingService::class)->preview(['0045350959']);
        $this->assertSame('not_found', $preview['items'][0]['status']);

        $this->backfillMigration();

        $this->assertSame('45350959', $logsheet->fresh()->log_sheet_no);
        $this->assertSame('45350959', LogsheetDetail::where('invoice_no', 'INV-LEGACY')->value('log_sheet_no'));

        $preview = app(LogsheetClearingService::class)->preview(['0045350959']);
        $this->assertSame('pending', $preview['items'][0]['status']);
    }

    // ---------------------------------------- 2. clearing with null dates

    public function test_a_null_dated_log_sheet_is_cleared_when_no_range_is_given(): void
    {
        $this->minimalImport('null-date.xlsx', ['NULLDATE1'], [
            ['NULLDATE1', '', 'INV-N', '2026-09-19'],
        ]);

        $logsheet = Logsheet::where('log_sheet_no', 'NULLDATE1')->firstOrFail();
        $this->assertNull($logsheet->date, 'a blank Date cell must import as NULL, not as today');

        $service = app(LogsheetClearingService::class);

        $preview = $service->preview(['NULLDATE1']);
        $this->assertSame('pending', $preview['items'][0]['status']);
        $this->assertSame(1, $preview['items'][0]['details_count']);
        $this->assertSame(1, $preview['counts']['pending']);

        $report = $service->clear(['NULLDATE1'], null, null, $this->user);

        $this->assertSame(1, $report['counts']['cleared']);
        $this->assertSame(0, $report['counts']['out_of_range']);
        $this->assertSame(1, $report['items'][0]['records_cleared'], 'a null-dated detail row is cleared');
        $this->assertSame('cleared', $logsheet->fresh()->status);
        $this->assertTrue(LogsheetDetail::where('log_sheet_no', 'NULLDATE1')->first()->cleared);
    }

    public function test_a_null_dated_log_sheet_is_out_of_range_when_a_range_is_given(): void
    {
        $this->minimalImport('null-date-range.xlsx', ['NULLDATE1'], [
            ['NULLDATE1', '', 'INV-N', '2026-09-19'],
        ]);

        $service = app(LogsheetClearingService::class);

        $preview = $service->preview(['NULLDATE1'], '2026-09-01', '2026-09-30');
        $this->assertSame('out_of_range', $preview['items'][0]['status']);
        $this->assertSame(1, $preview['counts']['out_of_range']);

        $report = $service->clear(['NULLDATE1'], '2026-09-01', '2026-09-30', $this->user);

        $this->assertSame(1, $report['counts']['out_of_range']);
        $this->assertSame(0, $report['counts']['cleared']);
        $this->assertSame('pending', Logsheet::where('log_sheet_no', 'NULLDATE1')->value('status'));
        $this->assertFalse(LogsheetDetail::where('log_sheet_no', 'NULLDATE1')->first()->cleared);
    }

    public function test_a_range_clears_a_dated_sheet_and_leaves_a_null_dated_detail_alone(): void
    {
        $this->minimalImport('mixed-dates.xlsx', ['MIXED1'], [
            ['MIXED1', '2026-09-19', 'INV-IN', '2026-09-19'],
            ['MIXED1', '', 'INV-NULL', '2026-09-19'],
        ]);

        $service = app(LogsheetClearingService::class);
        $report = $service->clear(['MIXED1'], '2026-09-01', '2026-09-30', $this->user);

        $this->assertSame(1, $report['counts']['cleared']);
        $this->assertSame(1, $report['items'][0]['records_cleared'], 'only the in-range detail row clears');
        $this->assertTrue(LogsheetDetail::where('invoice_no', 'INV-IN')->first()->cleared);
        $this->assertFalse(LogsheetDetail::where('invoice_no', 'INV-NULL')->first()->cleared);
    }

    public function test_the_bulk_clear_routes_do_not_crash_on_a_null_dated_log_sheet(): void
    {
        $this->minimalImport('null-date-routes.xlsx', ['NULLDATE1'], [
            ['NULLDATE1', '', 'INV-N', '2026-09-19'],
        ]);

        $this->postJson('/logsheets/clear/preview', ['numbers' => ['NULLDATE1']])
            ->assertOk()
            ->assertJsonPath('counts.out_of_range', 0);

        $this->postJson('/logsheets/clear/preview', [
            'numbers' => ['NULLDATE1'],
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ])->assertOk()->assertJsonPath('counts.out_of_range', 1);

        $this->postJson('/logsheets/clear/bulk', [
            'numbers' => ['NULLDATE1'],
            'expects_json' => true,
        ])->assertOk()->assertJsonPath('counts.cleared', 1);

        $this->assertSame('cleared', Logsheet::where('log_sheet_no', 'NULLDATE1')->value('status'));
    }

    // ------------------------------------------------------- 3. the export

    public function test_a_minimal_import_exports_with_the_same_twenty_headings(): void
    {
        $this->minimalImport('export-minimal.xlsx', ['0045350959']);

        $export = new LogsheetsSheetExport(Logsheet::query(), 'pending');

        $this->assertCount(20, $export->headings());
        $this->assertSame('Log Sheet No', $export->headings()[0]);
        $this->assertSame('Import Period', $export->headings()[19]);

        $rows = Logsheet::where('status', 'pending')->get()
            ->map(fn (Logsheet $row) => $export->map($row))
            ->all();

        $this->assertCount(1, $rows);
        $this->assertCount(20, $rows[0], 'a 4-column import still produces 20 cells');
        $this->assertSame('45350959', $rows[0][0]);
        $this->assertSame('2026-09-19', $rows[0][1]);
        $this->assertSame('', $rows[0][3], 'an absent TPRT Code is an empty cell, not a warning');
        $this->assertSame('', $rows[0][6]);
        $this->assertSame('2026-09-19 to 2026-09-19', $rows[0][19], 'the detected period is still exported');
    }

    public function test_a_minimal_import_downloads_as_a_real_workbook(): void
    {
        $this->minimalImport('download-minimal.xlsx', ['0045350959']);

        $response = $this->get(route('logsheets.export', ['scope' => 'all']));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Read a real workbook produced by the same export object: 20 headings,
        // one data row, and the log sheet number still typed as text so Excel
        // cannot round its zeros away.
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'exported.xlsx';
        file_put_contents($path, \Maatwebsite\Excel\Facades\Excel::raw(
            new LogsheetsSheetExport(Logsheet::query(), 'pending'),
            Excel::XLSX
        ));

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Exactly the twenty contractual headings, A..T. getHighestColumn()
        // reports Z because auto-sizing touches empty cells, so the boundary is
        // checked on the cells themselves.
        $this->assertNull($sheet->getCell('U1')->getValue(), 'nothing beyond the twentieth heading is written');
        $this->assertSame('Log Sheet No', $sheet->getCell('A1')->getValue());
        $this->assertSame('Import Period', $sheet->getCell('T1')->getValue());
        $this->assertSame('45350959', $sheet->getCell('A2')->getValue());
        $this->assertSame(
            DataType::TYPE_STRING,
            $sheet->getCell('A2')->getDataType(),
            'a log sheet number must reach the file as text so Excel keeps its zeros'
        );
        $this->assertSame(2, $sheet->getHighestRow(), 'one log sheet, one data row');

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    public function test_an_import_with_no_readable_dates_exports_an_empty_period(): void
    {
        $this->minimalImport('export-no-dates.xlsx', ['NODATES1'], [
            ['NODATES1', 'not-a-date', 'INV-N', 'not-a-date'],
        ]);

        $export = new LogsheetsSheetExport(Logsheet::query(), 'pending');
        $row = $export->map(Logsheet::where('log_sheet_no', 'NODATES1')->firstOrFail());

        $this->assertSame('', $row[1]);
        $this->assertSame('', $row[19], 'an import with no dates has no period');
    }

    public function test_a_null_dated_and_unpopulated_log_sheet_exports_without_an_exception(): void
    {
        $this->minimalImport('export-null-date.xlsx', ['NULLDATE1'], [
            ['NULLDATE1', '', 'INV-N', '2026-09-19'],
        ]);

        $export = new LogsheetsSheetExport(Logsheet::query(), 'pending');
        $row = $export->map(Logsheet::where('log_sheet_no', 'NULLDATE1')->firstOrFail());

        $this->assertSame('', $row[1], 'a NULL date is an empty cell');
        $this->assertSame('', $row[16]);
        $this->assertSame('', $row[17]);
        $this->assertSame('0.000', number_format((float) $row[11], 3, '.', ''));
        $this->assertSame('export-null-date.xlsx', $row[18]);
    }

    public function test_the_export_never_writes_a_leading_zero_number_as_a_number(): void
    {
        $import = LogsheetImport::create([
            'original_filename' => 'legacy.xlsx',
            'status' => 'completed',
            'file_path' => 'legacy.xlsx',
        ]);
        $logsheet = Logsheet::create([
            'log_sheet_no' => '0045350959',
            'status' => 'pending',
            'tprt_code' => '0007',
            'sap_invoice_no' => '00012345',
            'vendor_inv_no' => '00099',
            'last_import_id' => $import->id,
        ]);
        Storage::disk('public')->put('legacy.xlsx', 'x');

        $export = new LogsheetsSheetExport(Logsheet::query(), 'pending');
        $row = $export->map($logsheet);

        // A: Log Sheet No, D: TPRT Code, G: SAP Invoice No, J: Vendor Inv No.
        $this->assertSame('0045350959', $row[0]);
        $this->assertSame('0007', $row[3]);
        $this->assertSame('00012345', $row[6]);
        $this->assertSame('00099', $row[9]);

        // Those four columns are declared text, which is what makes Excel keep
        // the zeros instead of rounding them away.
        $formats = $export->columnFormats();
        foreach (['A', 'D', 'G', 'J'] as $letter) {
            $this->assertSame(
                NumberFormat::FORMAT_TEXT,
                $formats[$letter],
                "column {$letter} must be written as text"
            );
        }

        $this->delete(route('logsheets.imports.destroy', $import));
    }

    public function test_the_export_survives_a_value_that_is_not_a_scalar(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'weird.xlsx', 'status' => 'completed']);
        $logsheet = Logsheet::create([
            'log_sheet_no' => 'WEIRD1',
            'status' => 'pending',
            'last_import_id' => $import->id,
        ]);

        $export = new LogsheetsSheetExport(Logsheet::query(), 'pending');

        // setRawAttributes bypasses casting, standing in for any future column
        // that resolves to an array or a boolean rather than a string.
        $row = (new Logsheet)->setRawAttributes([
            'log_sheet_no' => 'WEIRD1',
            'date' => null,
            'vehicle_no' => ['a', 'b'],
            'tprt_code' => true,
            'tprt_name' => null,
            'destination' => null,
            'sap_invoice_no' => null,
            'posting_date' => null,
            'bill_date' => null,
            'vendor_inv_no' => null,
            'consignment_count' => null,
            'total_gross_wt' => null,
            'total_booked_amount' => null,
            'total_actual_amount' => null,
            'total_diff' => null,
            'status' => 'pending',
            'cleared_at' => null,
            'cleared_by' => null,
            'last_import_id' => $import->id,
        ]);
        $row->setRelation('lastImport', $import);

        $mapped = $export->map($row);

        $this->assertCount(20, $mapped);
        $this->assertSame('["a","b"]', $mapped[2], 'an array value is written as JSON, not as "Array"');
        $this->assertSame('1', $mapped[3], 'a boolean is written as 1/0');
        $this->assertSame('', $mapped[4]);
    }

    // ---------------------------------------------------------- 4. deletes

    public function test_deleting_a_minimal_import_removes_everything_it_created(): void
    {
        Storage::fake('public');

        $import = $this->minimalImport('delete-me.xlsx', ['DEL1', 'DEL2']);
        $filePath = $import->file_path;

        $this->assertNotNull($filePath);
        Storage::disk('public')->assertExists($filePath);

        $this->assertSame(2, Logsheet::count());
        $this->assertSame(2, LogsheetDetail::count());
        $this->assertSame(2, LogsheetRawRow::count());

        $this->delete(route('logsheets.imports.destroy', $import))
            ->assertRedirect(route('logsheets.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Logsheet::withTrashed()->count());
        $this->assertSame(0, LogsheetDetail::count());
        $this->assertSame(0, LogsheetRawRow::count());
        $this->assertSame(0, LogsheetClearing::count());
        $this->assertNull(LogsheetImport::find($import->id));
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_deleting_a_minimal_log_sheet_clears_its_details_raw_rows_and_clearing(): void
    {
        $import = $this->minimalImport('delete-one.xlsx', ['DEL1', 'KEEP1']);
        $logsheet = Logsheet::where('log_sheet_no', 'DEL1')->firstOrFail();
        $keeper = Logsheet::where('log_sheet_no', 'KEEP1')->firstOrFail();

        app(LogsheetClearingService::class)->clear(['DEL1'], null, null, $this->user);
        $this->assertSame(1, LogsheetClearing::count());

        $this->delete(route('logsheets.destroy', $logsheet))
            ->assertRedirect(route('logsheets.records'))
            ->assertSessionHasNoErrors();

        $this->assertNull(Logsheet::find($logsheet->id));
        $this->assertSame(0, LogsheetDetail::where('log_sheet_no', 'DEL1')->count());
        $this->assertSame(0, LogsheetRawRow::where('log_sheet_no', 'DEL1')->count());
        $this->assertSame(0, LogsheetClearing::where('logsheet_id', $logsheet->id)->count());

        // The sibling from the same minimal import is untouched.
        $this->assertNotNull(Logsheet::find($keeper->id));
        $this->assertSame(1, LogsheetDetail::where('log_sheet_no', 'KEEP1')->count());
        $this->assertSame(1, LogsheetRawRow::where('log_sheet_no', 'KEEP1')->count());
        $this->assertNotNull(LogsheetImport::find($import->id));
    }

    public function test_force_deleting_the_last_log_sheet_of_a_minimal_import_removes_the_stored_file(): void
    {
        Storage::fake('public');

        $import = $this->minimalImport('last-one.xlsx', ['ONLY1']);
        $logsheet = Logsheet::where('log_sheet_no', 'ONLY1')->firstOrFail();
        $filePath = $import->file_path;
        Storage::disk('public')->assertExists($filePath);

        $logsheet->forceDelete();

        Storage::disk('public')->assertMissing($filePath);
        $this->assertNull(LogsheetImport::find($import->id));
    }

    // ------------------------------------------------------- 5. re-import

    public function test_importing_the_same_minimal_file_twice_updates_and_adds_without_an_error(): void
    {
        $first = $this->minimalImport('twice.xlsx', ['RE1']);
        $firstId = $first->id;
        $logsheetId = Logsheet::where('log_sheet_no', 'RE1')->value('id');

        $second = $this->minimalImport('twice.xlsx', ['RE1']);

        $this->assertNotSame($firstId, $second->id, 'each upload gets its own import row');
        $this->assertSame(1, Logsheet::count(), 'the log sheet is updated in place, not duplicated');
        $this->assertSame($logsheetId, Logsheet::where('log_sheet_no', 'RE1')->value('id'), 'no second row, so no unique violation');
        $this->assertSame($second->id, Logsheet::where('log_sheet_no', 'RE1')->value('last_import_id'));
        $this->assertSame(2, LogsheetImport::count());

        // The existing behaviour (D2): re-importing adds the rows again rather
        // than replacing them, so no consignment is ever lost.
        $this->assertSame(
            ['INV-1', 'INV-1'],
            LogsheetDetail::where('log_sheet_no', 'RE1')->orderBy('id')->pluck('invoice_no')->all()
        );
        $this->assertSame(2, LogsheetRawRow::where('log_sheet_no', 'RE1')->count());
        $this->assertSame(
            [$firstId, $secondId = $second->id],
            LogsheetRawRow::where('log_sheet_no', 'RE1')->orderBy('id')->pluck('import_id')->all()
        );
        unset($secondId);
    }

    public function test_importing_a_second_file_with_different_columns_keeps_both(): void
    {
        $this->minimalImport('first.xlsx', ['MULTI1']);

        $second = $this->minimalImport(
            'second.xlsx',
            ['MULTI1'],
            [['MULTI1', '2026-09-20', 'INV-B', '2026-09-20', 'T09', 'Transporter Nine']],
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'TPRT Code', 'TPRT Name']
        );

        $logsheet = Logsheet::where('log_sheet_no', 'MULTI1')->firstOrFail();

        $this->assertSame($second->id, $logsheet->last_import_id);
        $this->assertSame('T09', $logsheet->tprt_code, 'the newer file supplies the optional column');
        $this->assertSame('2026-09-20', $logsheet->date?->format('Y-m-d'));
        $this->assertSame(1, Logsheet::count(), 'the same log sheet number is one log sheet, updated in place');
        $this->assertSame(2, LogsheetImport::count(), 'both uploads keep their own import row');
        $this->assertSame(
            ['INV-1', 'INV-B'],
            LogsheetDetail::where('log_sheet_no', 'MULTI1')->orderBy('id')->pluck('invoice_no')->all(),
            'neither file loses its rows'
        );
    }

    public function test_re_importing_a_soft_deleted_log_sheet_restores_it(): void
    {
        $import = $this->minimalImport('restore.xlsx', ['RESTORE1']);
        $logsheet = Logsheet::where('log_sheet_no', 'RESTORE1')->firstOrFail();

        $logsheet->delete();
        $this->assertSoftDeleted('logsheets', ['id' => $logsheet->id]);
        $this->assertNull(Logsheet::find($logsheet->id));

        $second = $this->minimalImport('restore.xlsx', ['RESTORE1']);

        $restored = Logsheet::withTrashed()->where('log_sheet_no', 'RESTORE1')->firstOrFail();

        $this->assertNull($restored->deleted_at, 'the soft-deleted row is restored, not duplicated');
        $this->assertSame($restored->id, $logsheet->id);
        $this->assertSame($second->id, $restored->last_import_id);
        $this->assertSame(1, Logsheet::withTrashed()->where('log_sheet_no', 'RESTORE1')->count());

        // Soft deleting removed the old details (LogsheetObserver::deleting), so
        // the restored sheet carries exactly the new import's rows.
        $this->assertSame(
            ['INV-1'],
            LogsheetDetail::where('log_sheet_no', 'RESTORE1')->orderBy('id')->pluck('invoice_no')->all()
        );
        $this->assertNotNull(LogsheetImport::find($import->id), 'the earlier import row is still there');
    }

    // ------------------------------------------- 6. the backfill migration

    /**
     * Run a migration class by hand.
     *
     * RefreshDatabase has already applied every migration to the test schema,
     * so `artisan migrate` is a no-op here. Loading the file is the only way to
     * exercise the migration's own logic (and its idempotency) against real rows.
     */
    protected function runMigration(string $filename): void
    {
        $migration = require database_path('migrations/'.$filename);
        $migration->up();
    }

    protected function backfillMigration(): void
    {
        $this->runMigration('2026_10_02_054500_normalize_logsheet_numbers.php');
    }

    public function test_the_backfill_migration_normalizes_and_skips_a_collision(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'legacy.xlsx', 'status' => 'completed']);

        // Two rows competing for one canonical value: the first wins, the
        // second is skipped and reported rather than failing the migration.
        $winner = Logsheet::create([
            'log_sheet_no' => '0045350959', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
        $loser = Logsheet::create([
            'log_sheet_no' => '45350959.0', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
        $plain = Logsheet::create([
            'log_sheet_no' => '000123', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);

        foreach ([$winner, $loser, $plain] as $logsheet) {
            LogsheetDetail::create([
                'logsheet_id' => $logsheet->id,
                'log_sheet_no' => $logsheet->log_sheet_no,
                'date' => '2026-09-19',
                'invoice_no' => 'INV-'.$logsheet->id,
                'cleared' => false,
            ]);
            LogsheetRawRow::create([
                'import_id' => $import->id,
                'log_sheet_no' => $logsheet->log_sheet_no,
                'raw_data' => ['Log Sheet No' => $logsheet->log_sheet_no],
                'row_number_in_file' => $logsheet->id,
                'is_valid' => true,
            ]);
        }

        $warnings = [];
        Log::listen(function ($message) use (&$warnings) {
            if ($message->level === 'warning') {
                $warnings[] = $message->message;
            }
        });

        $this->backfillMigration();

        $this->assertSame('45350959', $winner->fresh()->log_sheet_no, 'the first claimant is normalized');
        $this->assertSame('45350959.0', $loser->fresh()->log_sheet_no, 'the colliding row keeps its value');
        $this->assertSame('123', $plain->fresh()->log_sheet_no);

        // The loser's children keep its value too, so the three tables agree.
        $this->assertSame('45350959', LogsheetDetail::where('invoice_no', 'INV-'.$winner->id)->value('log_sheet_no'));
        $this->assertSame('45350959.0', LogsheetDetail::where('invoice_no', 'INV-'.$loser->id)->value('log_sheet_no'));
        $this->assertSame('123', LogsheetDetail::where('invoice_no', 'INV-'.$plain->id)->value('log_sheet_no'));
        $this->assertSame('45350959', LogsheetRawRow::where('row_number_in_file', $winner->id)->value('log_sheet_no'));
        $this->assertSame('45350959.0', LogsheetRawRow::where('row_number_in_file', $loser->id)->value('log_sheet_no'));

        $this->assertNotEmpty(
            array_filter($warnings, fn (string $message) => str_contains($message, 'collided')),
            'the skipped row must be reported in the log'
        );

        // Running it again changes nothing at all.
        $before = $this->snapshotNumbers();
        $this->backfillMigration();
        $this->assertSame($before, $this->snapshotNumbers(), 'the backfill is idempotent');
    }

    public function test_the_backfill_migration_is_idempotent_on_already_normalized_data(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'clean.xlsx', 'status' => 'completed']);
        $logsheet = Logsheet::create([
            'log_sheet_no' => '45350959', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
        LogsheetDetail::create([
            'logsheet_id' => $logsheet->id,
            'log_sheet_no' => '45350959',
            'date' => '2026-09-19',
            'invoice_no' => 'INV-CLEAN',
            'cleared' => false,
        ]);

        $before = $this->snapshotNumbers();
        $this->backfillMigration();

        $this->assertSame($before, $this->snapshotNumbers());
        $this->assertSame('45350959', $logsheet->fresh()->log_sheet_no);
    }

    public function test_the_backfill_leaves_a_null_log_sheet_number_alone(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'null-no.xlsx', 'status' => 'completed']);
        Logsheet::create([
            'log_sheet_no' => 'KEEPME1', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => null,
            'raw_data' => ['Log Sheet No' => ''],
            'row_number_in_file' => 1,
            'is_valid' => false,
        ]);

        $this->backfillMigration();

        $this->assertNull(LogsheetRawRow::where('row_number_in_file', 1)->value('log_sheet_no'));
        $this->assertSame(1, Logsheet::where('log_sheet_no', 'KEEPME1')->count());
    }

    public function test_every_child_row_sharing_a_stored_value_is_normalized(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'shared.xlsx', 'status' => 'completed']);
        $logsheet = Logsheet::create([
            'log_sheet_no' => '000777', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);

        foreach (['A', 'B', 'C'] as $index => $suffix) {
            LogsheetDetail::create([
                'logsheet_id' => $logsheet->id,
                'log_sheet_no' => '000777',
                'date' => '2026-09-19',
                'invoice_no' => 'INV-'.$suffix,
                'cleared' => false,
            ]);
            LogsheetRawRow::create([
                'import_id' => $import->id,
                'log_sheet_no' => '000777',
                'raw_data' => ['Log Sheet No' => '000777'],
                'row_number_in_file' => $index + 1,
                'is_valid' => true,
            ]);
        }

        $this->backfillMigration();

        $this->assertSame('777', $logsheet->fresh()->log_sheet_no);
        $this->assertSame(
            ['777', '777', '777'],
            LogsheetDetail::where('logsheet_id', $logsheet->id)->orderBy('id')->pluck('log_sheet_no')->all(),
            'one stored value means one statement, but every row still moves'
        );
        $this->assertSame(
            ['777', '777', '777'],
            LogsheetRawRow::where('import_id', $import->id)->orderBy('id')->pluck('log_sheet_no')->all()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function snapshotNumbers(): array
    {
        return [
            'logsheets' => Logsheet::withTrashed()->orderBy('id')->pluck('log_sheet_no')->all(),
            'logsheet_details' => LogsheetDetail::orderBy('id')->pluck('log_sheet_no')->all(),
            'logsheet_raw_rows' => LogsheetRawRow::orderBy('id')->pluck('log_sheet_no')->all(),
        ];
    }

    // ------------------------------------------------- index migration check

    public function test_the_detail_lookup_indexes_exist_and_the_migration_is_idempotent(): void
    {
        $this->runMigration('2026_10_02_054600_add_logsheet_detail_lookup_indexes.php');

        $indexed = collect(Schema::getIndexes('logsheet_details'))
            ->flatMap(fn (array $index) => $index['columns'])
            ->all();

        $this->assertContains('invoice_no', $indexed);
        $this->assertContains('inv_date', $indexed);

        // Running it again must be a no-op, not a duplicate-index failure.
        $names = collect(Schema::getIndexes('logsheet_details'))->pluck('name')->all();
        $this->runMigration('2026_10_02_054600_add_logsheet_detail_lookup_indexes.php');
        $this->assertSame($names, collect(Schema::getIndexes('logsheet_details'))->pluck('name')->all());
    }

    public function test_the_detail_index_migration_can_be_rolled_back_and_reapplied(): void
    {
        $file = '2026_10_02_054600_add_logsheet_detail_lookup_indexes.php';

        $migration = require database_path('migrations/'.$file);
        $migration->down();

        $indexed = collect(Schema::getIndexes('logsheet_details'))
            ->flatMap(fn (array $index) => $index['columns'])
            ->all();
        $this->assertNotContains('invoice_no', $indexed);
        $this->assertNotContains('inv_date', $indexed);

        $this->runMigration($file);

        $indexed = collect(Schema::getIndexes('logsheet_details'))
            ->flatMap(fn (array $index) => $index['columns'])
            ->all();
        $this->assertContains('invoice_no', $indexed);
        $this->assertContains('inv_date', $indexed);
    }

    public function test_the_unique_constraint_still_holds_after_the_backfill(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'uniq.xlsx', 'status' => 'completed']);
        Logsheet::create([
            'log_sheet_no' => '0045350959', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);

        $this->backfillMigration();

        $this->assertSame('45350959', Logsheet::where('last_import_id', $import->id)->value('log_sheet_no'));
        $this->assertSame(1, Logsheet::count());

        $this->expectException(QueryException::class);
        Logsheet::create([
            'log_sheet_no' => '45350959', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
    }

    public function test_the_backfill_moves_every_table_together(): void
    {
        $import = LogsheetImport::create(['original_filename' => 'partial.xlsx', 'status' => 'completed']);
        $logsheet = Logsheet::create([
            'log_sheet_no' => '000555', 'date' => '2026-09-19',
            'status' => 'pending', 'last_import_id' => $import->id,
        ]);
        LogsheetDetail::create([
            'logsheet_id' => $logsheet->id,
            'log_sheet_no' => '000555',
            'date' => '2026-09-19',
            'invoice_no' => 'INV-TX',
            'cleared' => false,
        ]);
        LogsheetRawRow::create([
            'import_id' => $import->id,
            'log_sheet_no' => '000555',
            'raw_data' => ['Log Sheet No' => '000555'],
            'row_number_in_file' => 1,
            'is_valid' => true,
        ]);

        $this->backfillMigration();

        // All three tables moved together, which they only can if the same
        // transaction committed and the migration did not abort part-way.
        $this->assertSame('555', $logsheet->fresh()->log_sheet_no);
        $this->assertSame('555', LogsheetDetail::where('invoice_no', 'INV-TX')->value('log_sheet_no'));
        $this->assertSame('555', LogsheetRawRow::where('row_number_in_file', 1)->value('log_sheet_no'));
    }
}
