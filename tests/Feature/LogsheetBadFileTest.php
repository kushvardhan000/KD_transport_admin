<?php

namespace Tests\Feature;

use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use App\Services\LogsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FX-5: files that must not import, handled as ordinary outcomes.
 *
 * Every case here asserts the same contract: a response the user can act on (a
 * redirect with an error, never a 500), no raw exception text, no file left
 * behind on the public disk, and no log sheet or raw row created from a file
 * that was rejected.
 */
class LogsheetBadFileTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logsheet_bad_'.uniqid();
        mkdir($this->tempDir, 0777, true);

        // Any file the import writes must land on the faked disk, so "left
        // behind on the public disk" is actually observable.
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    protected function write(string $name, string $contents): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    protected function headerOnly(): array
    {
        return ['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'Actual Amount'];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     *
     * name => [original filename, bytes]
     */
    public function badFileProvider(): array
    {
        return [
            'not a spreadsheet at all' => ['junk.txt', "hello world, this is not a workbook\n"],
            'xlsx extension, plain text inside' => ['fake.xlsx', "Log Sheet No,Date\n1,2026-01-01\n"],
            'xlsx extension, truncated zip' => ['truncated.xlsx', "PK\x03\x04\x14\x00\x00\x00\x08\x00 truncated"],
            'zero bytes' => ['empty.xlsx', ''],
            'csv extension, binary noise' => ['noise.csv', "\x00\x01\x02\xFF\xFE garbage \x00"],
            'html page saved as csv' => ['page.csv', "<html><body><h1>Login</h1></body></html>"],
            'json saved as xlsx' => ['payload.xlsx', '{"error":"unauthorised"}'],
            'php script saved as xlsx' => ['shell.xlsx', "<?php echo 'x'; ?>\r\nLog Sheet No,Date\r\n"],
            'header row only' => ['headers-only.xlsx', "Log Sheet No,Date,Invoice No,Invoice Date\n"],
            'title banner only' => ['title-only.csv', "SLS TRANSPORT - CONSIGNMENT SHEET\r\n"],
            'single stray value' => ['stray.xlsx', "0.00"],
            'all blank lines' => ['blank.csv', "\r\n\r\n\r\n   \r\n\r\n"],
        ];
    }

    // ---------------------------------------------------------------- tests

    /**
     * @dataProvider badFileProvider
     */
    public function test_a_bad_file_is_reported_without_a_server_error(string $name, string $contents): void
    {
        $path = $this->write($name, $contents);

        $response = $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, $name, null, null, true),
        ]);

        // A 500 means an exception escaped; 302 back to the form with a message
        // is the contract.
        $this->assertContains($response->getStatusCode(), [302, 422], "{$name} produced ".$response->getStatusCode());

        // Either the file never got past validation, or it was read and
        // rejected — both must leave the user with something to read.
        $this->assertTrue(
            session()->has('error') || session()->has('errors'),
            "{$name} was rejected without saying why"
        );

        $this->assertNothingLeaked($name);
    }

    /**
     * @dataProvider badFileProvider
     */
    public function test_a_bad_file_imports_nothing_and_stores_no_file(string $name, string $contents): void
    {
        $path = $this->write($name, $contents);

        $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, $name, null, null, true),
        ]);

        $this->assertSame(0, Logsheet::withTrashed()->count(), "{$name} created a log sheet");
        $this->assertSame(0, LogsheetRawRow::count(), "{$name} created a raw row");

        $imports = LogsheetImport::all();

        foreach ($imports as $import) {
            $this->assertSame(
                'invalid',
                $import->status,
                "{$name} left an import row in status {$import->status}"
            );
            $this->assertSame(0, (int) $import->row_count, "{$name} reported imported rows");
            $this->assertNull($import->file_path, "{$name} kept a path to a file it never stored");
            $this->assertNotEmpty($import->warnings['error'] ?? null, "{$name} recorded no reason");
        }

        // Nothing at all on the public disk.
        $this->assertSame([], Storage::disk('public')->allFiles(), "{$name} wrote to the public disk");
    }

    public function test_a_wrong_extension_is_refused_by_validation(): void
    {
        $path = $this->write('report.pdf', "%PDF-1.4\n% a perfectly readable file, wrong type\n");

        $response = $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, 'report.pdf', 'application/pdf', null, true),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, Logsheet::count());
        $this->assertSame(0, LogsheetImport::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_corrupt_xlsx_is_reported_not_thrown(): void
    {
        // A file that starts like a real zip container and then falls apart: the
        // reader gets going and fails deep inside, which is the shape of bug this
        // is guarding against. The reader throws; the user must still see a form
        // with a message, never a stack trace and never a 500.
        $path = $this->write('corrupt.xlsx', "PK\x03\x04".str_repeat("\x00", 400)."\xFF\xFF garbage");

        $response = $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, 'corrupt.xlsx', null, null, true),
        ]);

        $response->assertStatus(302);
        $this->assertTrue(
            session()->has('error') || session()->has('errors'),
            'a corrupt workbook was rejected silently'
        );

        $this->assertSame(0, Logsheet::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNothingLeaked('corrupt.xlsx');
    }

    public function test_the_service_reports_a_corrupt_workbook_through_the_controller_contract(): void
    {
        $path = $this->write('corrupt-direct.xlsx', "PK\x03\x04".str_repeat("\x00", 400)."\xFF garbage");

        // At the service boundary the reader's exception is allowed to surface —
        // it is logged and translated by the controller, not swallowed here.
        $caught = null;

        try {
            app(LogsheetImportService::class)->import(
                new UploadedFile($path, 'corrupt-direct.xlsx', null, null, true)
            );
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\Throwable::class, $caught, 'the reader should refuse a corrupt zip');

        // And even so, nothing is left behind.
        $this->assertSame(0, Logsheet::count());
        $this->assertSame(0, LogsheetRawRow::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_header_only_workbook_imports_nothing_but_is_not_an_error(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$this->headerOnly()]);

        $path = $this->tempDir.DIRECTORY_SEPARATOR.'header-only.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $response = $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, 'header-only.xlsx', null, null, true),
        ]);

        $response->assertStatus(302);
        $this->assertSame(0, Logsheet::count(), 'a header-only file has no rows to import');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_workbook_missing_a_required_column_names_the_missing_one(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Log Sheet No', 'Date', 'Actual Amount'],
            ['9001', '2026-09-01', '100.00'],
        ]);

        $path = $this->tempDir.DIRECTORY_SEPARATOR.'missing-column.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $response = $this->post(route('logsheets.store'), [
            'file' => new UploadedFile($path, 'missing-column.xlsx', null, null, true),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('Invoice No', $error);
        $this->assertStringContainsString('Invoice Date', $error);
        $this->assertSame(0, Logsheet::count());
    }

    // --------------------------------------------------------------- helpers

    /**
     * The user must never see a stack trace, a file path, or a driver message.
     */
    protected function assertNothingLeaked(string $name): void
    {
        $error = (string) (session('error') ?? '');
        $messages = array_merge($error ? [$error] : [], (array) session('errors', []));

        foreach ($messages as $message) {
            $text = is_array($message) ? implode(' ', array_map('strval', $message)) : (string) $message;

            $this->assertStringNotContainsString($this->tempDir, $text, "{$name} leaked a server path");
            $this->assertStringNotContainsString('vendor/', $text, "{$name} leaked a vendor path");
            $this->assertStringNotContainsString('#0 ', $text, "{$name} leaked a stack trace");
            $this->assertStringNotContainsString('SQLSTATE', $text, "{$name} leaked a driver message");
            $this->assertStringNotContainsString('PhpSpreadsheet', $text, "{$name} leaked a library class");
        }
    }
}