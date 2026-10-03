<?php

namespace Tests\Feature;

use App\Exports\LogsheetsSheetExport;
use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\User;
use App\Services\LogsheetHeaderMapper;
use App\Services\LogsheetImportService;
use App\Services\LogsheetValueParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use Tests\TestCase;

/**
 * FX-5: every real file dropped into tests/fixtures/logsheets/ is imported end to
 * end and every screen that shows the result is fetched.
 *
 * The expected numbers are DERIVED FROM THE FILE with the same reference
 * services the importer uses (LogsheetHeaderMapper + LogsheetValueParser), not
 * hard-coded, so dropping a new client workbook into the folder needs no test
 * change: if the importer and the reference disagree, that is the bug this test
 * exists to find.
 *
 * The folder is optional. When it is empty or absent every case is skipped with
 * a message saying so — an absent fixture never fails the suite.
 */
class LogsheetFixtureImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        $dir = __DIR__.'/../fixtures/logsheets';
        $files = is_dir($dir) ? glob($dir.'/*') : [];

        $cases = [];
        foreach ($files as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                continue;
            }

            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_a_real_fixture_workbook_imports_and_renders_everywhere(string $path): void
    {
        if (! is_file($path)) {
            $this->markTestSkipped("Fixture {$path} is not present; nothing to import.");
        }

        $expected = $this->deriveExpectations($path);

        $this->assertNotNull(
            $expected,
            basename($path).' has no header row resolving the four required columns, so it is not an importable fixture.'
        );

        $temp = tempnam(sys_get_temp_dir(), 'fixture_');
        copy($path, $temp);

        try {
            $summary = app(LogsheetImportService::class)->import(
                new UploadedFile($temp, basename($path), null, null, true)
            );
        } finally {
            @unlink($temp);
        }

        $import = LogsheetImport::findOrFail($summary['import_id']);
        $name = basename($path);

        $this->assertSame('completed', $import->status, "{$name} should import cleanly");
        $this->assertSame(
            $expected['rows'],
            (int) $import->row_count,
            "{$name}: row count must equal the source rows that carry a Log Sheet No"
        );
        $this->assertSame(
            0,
            (int) $import->invalid_count,
            "{$name}: no source row should be rejected as invalid"
        );
        $this->assertSame(
            $expected['total'],
            number_format((float) $import->total_amount, 2, '.', ''),
            "{$name}: total must equal the sum of Actual Amount"
        );
        $this->assertSame(
            $expected['log_sheets'],
            Logsheet::withTrashed()->where('last_import_id', $import->id)->count(),
            "{$name}: consolidated log sheet count"
        );

        $logsheet = Logsheet::where('last_import_id', $import->id)->firstOrFail();

        // Every screen that shows the import has to render.
        $this->get('/logsheets')->assertStatus(200);
        $this->get('/logsheets/records')->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);
        $this->get(route('logsheets.show', $logsheet))->assertStatus(200);

        // And the export has to be a readable workbook with the 20 headings.
        $this->assertExportIsReadable($import, $name);
    }

    public function test_the_fixture_folder_is_actually_exercised(): void
    {
        $files = self::fixtureProvider();

        $this->assertNotEmpty(
            $files,
            'tests/fixtures/logsheets/ holds no .xlsx/.xls/.csv, so every fixture case was skipped. '
            .'Add a real client workbook there to have it imported and rendered automatically.'
        );
    }

    /**
     * Read the fixture the way the importer reads it and work out what a
     * correct import must produce.
     *
     * @return array{rows: int, total: string, log_sheets: int}|null
     */
    protected function deriveExpectations(string $path): ?array
    {
        $sheets = Excel::toArray([], new UploadedFile($path, basename($path), null, null, true));

        if (! is_array($sheets) || $sheets === []) {
            return null;
        }

        $picked = LogsheetHeaderMapper::pickSheet($sheets);

        if ($picked === null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            // Mirror the importer's CSV recovery so the expectation is derived
            // from the same parse the import will use.
            $sheets = $this->recoverCsv($path, $sheets);
            $picked = LogsheetHeaderMapper::pickSheet($sheets);
        }

        if ($picked === null) {
            return null;
        }

        $canonical = $picked['mapped']['canonical'];
        if ($picked['mapped']['missing_required'] !== []) {
            return null;
        }

        $sheet = array_values($sheets[$picked['sheet']]);
        $dataRows = array_slice($sheet, $picked['header_row'] + 1);

        $rows = 0;
        $logSheets = [];
        $total = '0.00';

        foreach ($dataRows as $line) {
            if (! is_array($line) || $this->isBlank($line)) {
                continue;
            }

            $number = LogsheetValueParser::logSheetNo($line[$canonical['log_sheet_no']] ?? null);
            if ($number === null) {
                continue;
            }

            $rows++;
            $logSheets[$number] = true;

            // Actual Amount is totalled at 2 decimals by the importer.
            $actual = LogsheetValueParser::number(
                $line[$canonical['actual_amount'] ?? -1] ?? null,
                2
            );

            if ($actual !== null) {
                $total = bcadd($total, $actual, 2);
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'log_sheets' => count($logSheets),
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function recoverCsv(string $path, array $rows): array
    {
        if (LogsheetHeaderMapper::pickSheet($rows) !== null) {
            return $rows;
        }

        $reader = new CsvReader;
        $reader->setDelimiter($this->detectDelimiter($path));

        $spreadsheet = $reader->load($path);
        $retry = [];
        foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
            $retry[$index] = $sheet->toArray();
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $retry;
    }

    protected function detectDelimiter(string $path): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        $handle = fopen($path, 'rb');
        $lines = 0;

        while ($lines < 25 && ($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $lines++;

            foreach (array_keys($candidates) as $candidate) {
                $candidates[$candidate] += substr_count($line, $candidate);
            }
        }

        fclose($handle);

        $best = ',';
        foreach ($candidates as $candidate => $count) {
            if ($count > $candidates[$best]) {
                $best = $candidate;
            }
        }

        return $best;
    }

    protected function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value)) {
                if (! $this->isBlank($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function assertExportIsReadable(LogsheetImport $import, string $name): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture_export_').'.xlsx';

        file_put_contents($path, Excel::raw(
            new LogsheetsSheetExport(Logsheet::query(), 'pending'),
            ExcelFormat::XLSX
        ));

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $expectedHeadings = (new LogsheetsSheetExport(Logsheet::query(), 'pending'))->headings();
        $this->assertCount(20, $expectedHeadings, 'the export keeps exactly 20 headings');

        foreach ($expectedHeadings as $index => $heading) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $this->assertSame(
                $heading,
                $sheet->getCell($letter.'1')->getValue(),
                "{$name}: heading in column {$letter} is wrong"
            );
        }

        $this->assertNull(
            $sheet->getCell(Coordinate::stringFromColumnIndex(21).'1')->getValue(),
            "{$name}: nothing may be written past the twentieth heading"
        );

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        @unlink($path);
    }
}
