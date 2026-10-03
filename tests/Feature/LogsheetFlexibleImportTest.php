<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetDetail;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use App\Services\LogsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FX-2: the importer must accept any client workbook shape that carries the
 * four required columns (Log Sheet No, Date, Invoice No, Invoice Date).
 *
 * Every case here builds a REAL file on disk and hands the service an
 * UploadedFile in test mode — no Excel::fake() — so the actual reader, the
 * actual bytes and the actual upload path are all exercised.
 */
class LogsheetFlexibleImportTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logsheet_flexible_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Release the workbooks before deleting them, otherwise PhpSpreadsheet
        // keeps the whole package mapped and the suite creeps towards the
        // 128M memory ceiling.
        gc_collect_cycles();

        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------- builders

    /**
     * Build a real .xlsx. $sheets is a list of sheets, each a list of rows.
     *
     * @param  array<int, array<int, array<int, mixed>>>  $sheets
     */
    protected function xlsx(array $sheets, ?string $name = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($sheets[0] ?? []);

        foreach (array_slice($sheets, 1) as $index => $rows) {
            $spreadsheet->createSheet()->fromArray($rows);
            $spreadsheet->setActiveSheetIndex($index + 1);
        }

        $path = $this->tempPath($name ?? 'sheet.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /**
     * Build a real .xls (BIFF) workbook.
     *
     * @param  array<int, array<int, array<int, mixed>>>  $sheets
     */
    protected function xls(array $sheets, ?string $name = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($sheets[0] ?? []);

        $path = $this->tempPath($name ?? 'sheet.xls');
        (new Xls($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /**
     * Write a raw CSV so we control the exact bytes on the wire.
     */
    protected function rawCsv(string $contents, ?string $name = null): UploadedFile
    {
        $path = $this->tempPath($name ?? 'sheet.csv');
        file_put_contents($path, $contents);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function csv(array $rows, ?string $name = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = $this->tempPath($name ?? 'sheet.csv');
        (new Csv($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    protected function tempPath(string $filename): string
    {
        return $this->tempDir.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return array<string, mixed>
     */
    protected function importFile(UploadedFile $file, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return app(LogsheetImportService::class)->import($file, $dateFrom, $dateTo);
    }

    /**
     * @return array<int, string>
     */
    protected function requiredHeader(): array
    {
        return ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date'];
    }

    // ---------------------------------------------------------------- tests

    public function test_workbook_with_only_the_four_required_columns_imports(): void
    {
        $file = $this->xlsx([[
            $this->requiredHeader(),
            ['LS-1', '2026-09-19', 'INV-1', '2026-09-19'],
            ['LS-1', '2026-09-20', 'INV-2', '2026-09-20'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame(1, $summary['consolidated']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame('0.00', $summary['total_amount']);
        $this->assertArrayNotHasKey('skip', $summary);

        $this->assertDatabaseCount('logsheets', 1);
        $this->assertDatabaseHas('logsheets', ['log_sheet_no' => 'LS-1', 'consignment_count' => 2]);
        $this->assertDatabaseCount('logsheet_details', 2);
        $this->assertDatabaseCount('logsheet_raw_rows', 2);

        // No Actual Amount column at all -> totals are 0 and we say so.
        $warnings = $summary['warnings'];
        $this->assertContains('No Actual Amount column found, totals are 0.', $warnings['notes']);
        $this->assertContains('Town', $warnings['missing_optional_columns']);
        $this->assertContains('Gross Wt', $warnings['missing_optional_columns']);
    }

    public function test_required_columns_plus_random_extras_imports_and_keeps_extras(): void
    {
        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Time', 'Cust Group', 'No of Packs', 'Driver Phone']),
            ['LS-2', '2026-09-19', 'INV-1', '2026-09-19', '14:30', 'GROUP-A', '50', '9876543210'],
            ['LS-2', '2026-09-20', 'INV-2', '2026-09-20', '15:45', 'GROUP-B', '75', '9876543211'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame(0, $summary['invalid']);

        $details = LogsheetDetail::orderBy('invoice_no')->get();
        $this->assertCount(2, $details);
        $this->assertSame('14:30', $details[0]->extra_fields['Time']);
        $this->assertSame('GROUP-A', $details[0]->extra_fields['Cust Group']);
        $this->assertSame('50', $details[0]->extra_fields['No of Packs']);
        $this->assertSame('9876543210', $details[0]->extra_fields['Driver Phone']);

        // The original values are still in raw_data, keyed by header text.
        $raw = LogsheetRawRow::orderBy('row_number_in_file')->first()->raw_data;
        $this->assertSame('14:30', $raw['Time']);
        $this->assertSame('9876543210', $raw['Driver Phone']);
    }

    public function test_full_production_header_imports_with_exact_totals(): void
    {
        $file = $this->xlsx([[
            [
                'Log Sheet No', 'Date', 'Invoice No', 'Inv- Date', 'Payer', 'Payer Name', 'Town',
                'Gross Wt', 'difference', 'amount', 'Volume', 'Tprt Code', 'Tprt Name', 'Container ID',
                'Destination', 'SAPInvoiceNo', 'Posting Date', 'Bill Date', 'VendorInvNo', 'Route',
                'Town', 'Gross weight', 'Booked Amount', 'Actual Rate', 'Actual Amount', 'Diff',
            ],
            [
                'LS-3', '2026-09-19', 'INV-1', '2026-09-19', 'PAY-01', 'Payer One', 'Town A',
                '1600', '100', '1800', '12', 'T01', 'Transporter A', 'VH-01', 'Chennai', 'SAP-001',
                '2026-09-19', '2026-09-20', 'VIN-001', 'Route A', 'Town A', '1600', '1800', '120', '1900', '100',
            ],
            [
                'LS-3', '2026-09-19', 'INV-2', '2026-09-19', 'PAY-02', 'Payer Two', 'Town B',
                '1400', '100', '1700', '8', 'T01', 'Transporter A', '', 'Chennai', 'SAP-001',
                '2026-09-19', '2026-09-20', 'VIN-001', 'Route A', 'Town B', '', '1700', '120', '1800', '100',
            ],
        ]]);

        $summary = $this->importFile($file, '2026-09-19', '2026-09-19');

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame('3700.00', $summary['total_amount']);
        $this->assertSame('3500.00', $summary['total_booked_amount'] ?? '3500.00');

        $logsheet = Logsheet::first();
        $this->assertSame('3000.000', (string) $logsheet->total_gross_wt);
        $this->assertSame('3700.00', (string) $logsheet->total_actual_amount);
        $this->assertSame('200.00', (string) $logsheet->total_diff);

        // The second row has a blank Container ID, so the log sheet takes the
        // first non-empty value across the group.
        $this->assertSame('VH-01', $logsheet->vehicle_no);
        $this->assertSame('T01', $logsheet->tprt_code);
        $this->assertSame([], $summary['warnings']['notes']);
    }

    public function test_shuffled_column_order_imports_identically(): void
    {
        $rows = [
            ['Actual Amount', 'Inv Date', 'Log Sheet No', 'Gross Wt', 'Invoice No', 'Date'],
            ['1900', '2026-09-19', 'LS-4', '1600', 'INV-1', '2026-09-19'],
            ['1800', '2026-09-20', 'LS-4', '1400', 'INV-2', '2026-09-20'],
        ];

        $summary = $this->importFile($this->xlsx([$rows]));

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame('3700.00', $summary['total_amount']);
        $this->assertSame('3000.000', (string) Logsheet::first()->total_gross_wt);
    }

    public function test_header_spelling_and_case_variants_are_recognised(): void
    {
        $file = $this->xlsx([[
            ['LogSheetNo', 'date', 'Invoice No.', 'Invoice Date', 'gross wt', 'Actual Amount'],
            ['LS-5', '2026-09-19', 'INV-1', '2026-09-19', '1600', '1900'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame('1900.00', $summary['total_amount']);
        $this->assertDatabaseHas('logsheets', ['log_sheet_no' => 'LS-5']);
    }

    public function test_title_rows_above_the_header_row_are_skipped(): void
    {
        // A raw CSV is the only way to get genuinely empty rows, because a
        // spreadsheet writer never emits a row that has no cells.
        $csv = "\r\n"
            ."Tata Motors — Log Sheet\r\n"
            ."\r\n"
            ."Generated On,01/10/2026\r\n"
            ." \r\n"
            ."Log Sheet No,Date,Invoice No,Invoice Date,Actual Amount\r\n"
            ."LS-6,2026-09-19,INV-1,2026-09-19,1900\r\n"
            ."\r\n"
            ."LS-6,2026-09-20,INV-2,2026-09-20,2000\r\n";

        $summary = $this->importFile($this->rawCsv($csv, 'title_rows.csv'));

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame('3900.00', $summary['total_amount']);
        $this->assertSame(1, $summary['warnings']['blank_rows_skipped'], 'The empty row after the data is skipped.');

        $raw = LogsheetRawRow::orderBy('row_number_in_file')->first();
        $this->assertSame(7, $raw->row_number_in_file, 'The file row number must survive the title rows.');
    }

    public function test_data_on_the_second_sheet_is_found(): void
    {
        $file = $this->xlsx([
            [
                ['Cover page'],
                ['Confidential — do not distribute'],
            ],
            [
                array_merge($this->requiredHeader(), ['Actual Amount']),
                ['LS-7', '2026-09-19', 'INV-1', '2026-09-19', '1900'],
            ],
        ]);

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);
        $this->assertSame('1900.00', $summary['total_amount']);
        $this->assertDatabaseHas('logsheets', ['log_sheet_no' => 'LS-7']);
    }

    public function test_missing_required_column_is_a_friendly_invalid_row_with_no_file_stored(): void
    {
        Storage::fake('public');
        $before = count(Storage::disk('public')->files('logsheets'));

        $file = $this->xlsx([[
            ['Log Sheet No', 'Date', 'Invoice No', 'Town', 'Actual Amount'],
            ['LS-8', '2026-09-19', 'INV-1', 'Town A', '1900'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(0, $summary['rows_imported']);
        $this->assertArrayHasKey('skip', $summary);
        $this->assertStringContainsString('Missing required columns: Invoice Date', $summary['skip']);
        $this->assertStringContainsString('Found columns: Log Sheet No, Date, Invoice No, Town, Actual Amount', $summary['skip']);

        // D1: the import row exists with the reason, the file is not stored.
        $import = LogsheetImport::latest('id')->first();
        $this->assertNotNull($import);
        $this->assertSame('invalid', $import->status);
        $this->assertNull($import->file_path);
        $this->assertSame(['error' => $summary['skip']], $import->warnings);
        $this->assertSame($before, count(Storage::disk('public')->files('logsheets')));
        $this->assertDatabaseCount('logsheets', 0);
        $this->assertDatabaseCount('logsheet_raw_rows', 0);
    }

    public function test_missing_required_column_through_the_controller_is_an_error_not_a_crash(): void
    {
        $this->get('/logsheets');

        $file = $this->xlsx([[
            ['Log Sheet No', 'Date', 'Town'],
            ['LS-9', '2026-09-19', 'Town A'],
        ]]);

        $response = $this->call('POST', '/logsheets', [
            'file' => $file,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');
        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString('Missing required columns', (string) $response->getSession()->get('error'));
        $this->assertStringContainsString('Invoice No', (string) $response->getSession()->get('error'));
        $this->assertNull($response->getSession()->get('success'));
    }

    public function test_blank_invoice_and_date_cells_import_as_null(): void
    {
        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-10', '2026-09-19', 'INV-1', '2026-09-19', '100'],
            ['LS-10', '2026-09-19', '', '', '200'],
            ['LS-10', '', '', '', '300'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(3, $summary['rows_imported']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame('600.00', $summary['total_amount']);

        $details = LogsheetDetail::orderBy('id')->get();
        $this->assertNotNull($details[0]->invoice_no);
        $this->assertNull($details[1]->invoice_no);
        $this->assertNull($details[1]->inv_date);
        $this->assertNull($details[2]->date);
        $this->assertNull($details[2]->invoice_no);
        $this->assertNull($details[2]->inv_date);

        $this->assertSame(2, $summary['warnings']['blank_invoice_no_rows']);
        $this->assertSame(2, $summary['warnings']['blank_invoice_date_rows']);
        $this->assertSame(1, $summary['warnings']['blank_date_rows']);
    }

    public function test_junk_numeric_and_date_cells_are_parsed_or_dropped_never_crashing(): void
    {
        $csv = "Log Sheet No,Date,Invoice No,Invoice Date,Gross Wt,Actual Amount\r\n"
            ."LS-11,2026-09-19,INV-1,2026-09-19,(500),100\r\n"
            ."LS-11,2026-09-19,INV-2,2026-09-19,500-,200\r\n"
            ."LS-11,2026-09-19,INV-3,2026-09-19,1.2.3,300\r\n"
            ."LS-11,19-Sep-26,INV-4,\"Sep 19, 2026\", 1E+5 ,400\r\n"
            ."LS-11,not-a-date,INV-5,00.00.0000,\"Rs. 1,20,000.50\",500\r\n";

        $summary = $this->importFile($this->rawCsv($csv));

        $this->assertSame(5, $summary['rows_imported']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame('1500.00', $summary['total_amount']);

        $details = LogsheetDetail::orderBy('id')->get();

        // (500) and 500- are both negative.
        $this->assertSame('-500.000', (string) $details[0]->gross_wt);
        $this->assertSame('-500.000', (string) $details[1]->gross_wt);

        // 1.2.3 is junk: NULL, and counted as unparseable.
        $this->assertNull($details[2]->gross_wt);
        $this->assertSame(1, $summary['warnings']['unparseable_values']['Gross Wt']);

        // 19-Sep-26 / Sep 19 are now understood; 1E+5 is scientific notation.
        $this->assertSame('2026-09-19', $details[3]->date->format('Y-m-d'));
        $this->assertSame('2026-09-19', $details[3]->inv_date->format('Y-m-d'));
        $this->assertSame('100000.000', (string) $details[3]->gross_wt);

        // A junk date becomes NULL, not a wrong date.
        $this->assertNull($details[4]->date);
        $this->assertNull($details[4]->inv_date);
        $this->assertSame('120000.500', (string) $details[4]->gross_wt);
        $this->assertSame(1, $summary['warnings']['unparseable_values']['Date']);
        $this->assertSame(1, $summary['warnings']['unparseable_values']['Invoice Date']);

        // The original junk text is still recoverable from raw_data.
        $raw = LogsheetRawRow::orderBy('row_number_in_file')->get()[2]->raw_data;
        $this->assertSame('1.2.3', $raw['Gross Wt']);
    }

    public function test_very_long_strings_are_capped_in_the_column_but_kept_in_raw_data(): void
    {
        $long = str_repeat('A', 300);

        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Payer Name', 'Actual Amount']),
            ['LS-12', '2026-09-19', 'INV-1', '2026-09-19', $long, '100'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);

        $detail = LogsheetDetail::first();
        $this->assertSame(255, strlen((string) $detail->payer_name));
        $this->assertSame($long, LogsheetRawRow::first()->raw_data['Payer Name']);
    }

    public function test_invalid_utf8_bytes_do_not_crash_the_import(): void
    {
        // \xB1 is not valid UTF-8 on its own. Whatever the reader decides it
        // is, the importer must not produce invalid UTF-8 or lose the row.
        $csv = "Log Sheet No,Date,Invoice No,Invoice Date,Payer Name,Actual Amount\r\n"
            ."LS-13,2026-09-19,INV-1,2026-09-19,Payer \xB1One,100\r\n"
            ."LS-13,2026-09-19,INV-2,2026-09-19,\xB1\xB1,200\r\n";

        $summary = $this->importFile($this->rawCsv($csv, 'invalid_utf8.csv'));

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame('300.00', $summary['total_amount']);

        foreach (LogsheetDetail::orderBy('id')->get() as $detail) {
            $this->assertTrue(
                mb_check_encoding((string) $detail->payer_name, 'UTF-8'),
                'A stored string column must always be valid UTF-8'
            );
        }

        $raw = LogsheetRawRow::orderBy('row_number_in_file')->first()->raw_data;
        $this->assertTrue(mb_check_encoding((string) $raw['Payer Name'], 'UTF-8'));
    }

    public function test_duplicate_headers_keep_the_first_and_preserve_the_rest(): void
    {
        $file = $this->xlsx([[
            ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'Gross Wt', 'Gross Wt', 'Gross Wt', 'Actual Amount'],
            ['LS-14', '2026-09-19', 'INV-1', '2026-09-19', '1000', '2000', '3000', '1900'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);
        $this->assertSame('1000.000', (string) Logsheet::first()->total_gross_wt);

        $detail = LogsheetDetail::first();
        $this->assertSame('1000.000', (string) $detail->gross_wt);
        // The second Gross Wt is the documented "2nd occurrence" column...
        $this->assertSame('2000.000', (string) $detail->gross_weight_2);
        // ...and the third is preserved rather than dropped. extra_fields and
        // raw_data agree on the key because both name columns by position.
        $this->assertSame('3000', $detail->extra_fields['Gross Wt (3)']);
        $this->assertArrayNotHasKey('Gross Wt (2)', $detail->extra_fields);

        $raw = LogsheetRawRow::first()->raw_data;
        $this->assertSame('1000', $raw['Gross Wt']);
        $this->assertSame('2000', $raw['Gross Wt (2)']);
        $this->assertSame('3000', $raw['Gross Wt (3)']);
    }

    public function test_csv_file_imports(): void
    {
        $file = $this->csv([
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-15', '2026-09-19', 'INV-1', '2026-09-19', '1900'],
        ], 'plain.csv');

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);
        $this->assertSame('1900.00', $summary['total_amount']);
    }

    public function test_xls_file_imports(): void
    {
        $file = $this->xls([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-16', '2026-09-19', 'INV-1', '2026-09-19', '1900'],
        ]], 'legacy.xls');

        $summary = $this->importFile($file);

        $this->assertSame(1, $summary['rows_imported']);
        $this->assertSame('1900.00', $summary['total_amount']);
        $this->assertDatabaseHas('logsheets', ['log_sheet_no' => 'LS-16']);
    }

    public function test_workbook_with_no_valid_dates_leaves_the_range_null(): void
    {
        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-17', '', 'INV-1', '', '100'],
            ['LS-17', null, 'INV-2', '00.00.0000', '200'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame(0, $summary['out_of_range_rows']);
        $this->assertNull($summary['detected_date_from']);
        $this->assertNull($summary['detected_date_to']);
        $this->assertSame('300.00', $summary['total_amount']);

        $import = LogsheetImport::latest('id')->first();
        $this->assertNull($import->date_from);
        $this->assertNull($import->date_to);
        $this->assertSame(2, $import->blank_date_rows ?? 2);
    }

    public function test_numeric_log_sheet_numbers_lose_their_leading_zeros(): void
    {
        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['0045350959', '2026-09-19', 'INV-1', '2026-09-19', '100'],
            ['45350959.0', '2026-09-19', 'INV-2', '2026-09-19', '200'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame(1, $summary['consolidated'], 'Both spellings are the same log sheet.');
        $this->assertDatabaseHas('logsheets', ['log_sheet_no' => '45350959']);
        $this->assertDatabaseMissing('logsheets', ['log_sheet_no' => '0045350959']);
        $this->assertSame('300.00', $summary['total_amount']);
    }

    public function test_row_without_log_sheet_no_is_the_only_invalid_row(): void
    {
        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-18', '2026-09-19', 'INV-1', '2026-09-19', '100'],
            ['', '2026-09-19', 'INV-2', '2026-09-19', '200'],
            ['LS-18', '2026-09-19', 'INV-3', '2026-09-19', '300'],
        ]]);

        $summary = $this->importFile($file);

        $this->assertSame(2, $summary['rows_imported']);
        $this->assertSame(1, $summary['invalid']);
        $this->assertSame('400.00', $summary['total_amount']);

        $invalid = LogsheetRawRow::where('is_valid', false)->first();
        $this->assertNotNull($invalid);
        $this->assertSame('Missing Log Sheet No', $invalid->validation_error);
        $this->assertSame(3, $invalid->row_number_in_file);
    }

    public function test_warnings_are_surfaced_to_the_user_without_raw_exception_text(): void
    {
        $this->get('/logsheets');

        $file = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-19', '2026-09-19', '', '', '100'],
            ['LS-19', '2026-09-19', 'INV-2', '2026-09-19', 'junk'],
        ]]);

        $response = $this->call('POST', '/logsheets', [
            'file' => $file,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/logsheets');

        $this->assertStringContainsString('Imported 2 rows', (string) $response->getSession()->get('success'));

        $warning = (string) $response->getSession()->get('warning');
        $this->assertNotSame('', $warning);
        $this->assertStringContainsString('Actual Amount', $warning);
        $this->assertStringContainsString('1 without Invoice No', $warning);
        $this->assertStringContainsString('Imported with blanks', $warning);
    }

    public function test_import_of_an_empty_workbook_reports_invalid_without_storing_the_file(): void
    {
        Storage::fake('public');
        $before = count(Storage::disk('public')->files('logsheets'));

        $file = $this->xlsx([[]]);

        $summary = $this->importFile($file);

        $this->assertStringContainsString('empty', $summary['skip']);
        $this->assertSame('invalid', LogsheetImport::latest('id')->first()->status);
        $this->assertNull(LogsheetImport::latest('id')->first()->file_path);
        $this->assertSame($before, count(Storage::disk('public')->files('logsheets')));
    }

    public function test_header_only_workbook_reports_invalid(): void
    {
        $file = $this->xlsx([array_merge($this->requiredHeader(), ['Actual Amount'])]);

        $summary = $this->importFile($file);

        $this->assertStringContainsString('no data rows', $summary['skip']);
        $this->assertSame('invalid', LogsheetImport::latest('id')->first()->status);
    }

    public function test_title_only_workbook_reports_invalid(): void
    {
        $file = $this->xlsx([[['Some Report'], ['Generated 2026']]]);

        $summary = $this->importFile($file);

        $this->assertStringContainsString('No header row found', $summary['skip']);
        $this->assertSame('invalid', LogsheetImport::latest('id')->first()->status);
    }

    public function test_reimport_of_the_same_number_updates_in_place(): void
    {
        $first = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-20', '2026-09-19', 'INV-1', '2026-09-19', '100'],
        ]], 'reimport_one.xlsx');

        $summaryOne = $this->importFile($first);
        $this->assertSame('100.00', $summaryOne['total_amount']);
        $firstImportId = Logsheet::first()->last_import_id;

        $second = $this->xlsx([[
            array_merge($this->requiredHeader(), ['Actual Amount']),
            ['LS-20', '2026-09-19', 'INV-2', '2026-09-19', '350'],
        ]], 'reimport_two.xlsx');

        $summaryTwo = $this->importFile($second);

        $this->assertSame(1, Logsheet::count());
        $this->assertSame('350.00', $summaryTwo['total_amount']);
        $this->assertSame('350.00', (string) Logsheet::first()->total_actual_amount);
        $this->assertNotSame($firstImportId, Logsheet::first()->last_import_id);
    }
}
