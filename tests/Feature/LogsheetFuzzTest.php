<?php

namespace Tests\Feature;

use App\Exports\LogsheetsSheetExport;
use App\Models\Logsheet;
use App\Models\LogsheetImport;
use App\Models\LogsheetRawRow;
use App\Models\User;
use App\Services\LogsheetHeaderMapper;
use App\Services\LogsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FX-5: random workbooks, and a very large one.
 *
 * The fuzzer is seeded, so a failure prints the seed that reproduces it and the
 * whole sheet can be rebuilt by hand. It asserts only what must ALWAYS be true —
 * the import does not throw, it finishes, and everything downstream renders and
 * exports. It deliberately does not assert any particular total, because a random
 * sheet has no "right" total; the fixture test is where the numbers are checked.
 */
class LogsheetFuzzTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bump this to explore a different space; the default is fixed so a reported
     * failure is reproducible.
     */
    private const SEED = 20261002;

    private const SHEETS = 30;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'logsheet_fuzz_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Release every workbook before deleting the files, otherwise
        // PhpSpreadsheet keeps the packages mapped and memory climbs towards the
        // 128M ceiling for the rest of the run.
        gc_collect_cycles();

        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function test_thirty_random_workbooks_always_import_and_render(): void
    {
        mt_srand(self::SEED);

        $failures = [];

        for ($index = 0; $index < self::SHEETS; $index++) {
            $rows = $this->randomSheet($index);

            $path = $this->tempDir.DIRECTORY_SEPARATOR."fuzz-{$index}.xlsx";
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($rows);
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            try {
                $this->assertSheetImportsAndRenders($path, $index);
            } catch (\Throwable $e) {
                $failures[] = "sheet {$index}: ".get_class($e).': '.$e->getMessage();
            }

            @unlink($path);
            gc_collect_cycles();
        }

        $this->assertSame(
            [],
            $failures,
            "Random workbooks failed (seed ".self::SEED." — rebuild with \$this->randomSheet(\$i) "
            .'and the same mt_srand sequence): '.implode(' | ', $failures)
        );
    }

    protected function assertSheetImportsAndRenders(string $path, int $index): void
    {
        $summary = null;

        try {
            $summary = app(LogsheetImportService::class)->import(
                new UploadedFile($path, "fuzz-{$index}.xlsx", null, null, true)
            );
        } catch (\Throwable $e) {
            $this->fail("seed ".self::SEED.", sheet {$index}: the import threw ".get_class($e).': '.$e->getMessage());
        }

        $this->assertIsArray($summary, "seed ".self::SEED.", sheet {$index}: no import summary");
        $this->assertArrayHasKey('import_id', $summary);

        $import = LogsheetImport::find($summary['import_id']);

$this->assertSame(
            'completed',
            $import?->status,
            "seed ".self::SEED.", sheet {$index}: a random workbook must still complete, got "
            .var_export($import?->status, true).' — '.json_encode($import?->warnings)
        );
// A sheet whose every row is unusable still completes; it just imports nothing.
        $this->assertGreaterThanOrEqual(
            1,
            (int) $import->row_count + (int) $import->invalid_count,
            "seed ".self::SEED.", sheet {$index}: the workbook's data rows vanished"
        );

        $raw = (int) LogsheetRawRow::where('import_id', $import->id)->count();
        // Every data row is kept for audit: the ones that became log sheets plus
        // the ones that were rejected. Neither may silently disappear.
        $this->assertSame(
            (int) $import->row_count + (int) $import->invalid_count,
            $raw,
            "seed ".self::SEED.", sheet {$index}: raw rows must account for every data row"
        );

        $logsheet = Logsheet::where('last_import_id', $import->id)->first();

        $this->get('/logsheets')->assertStatus(200);
        $this->get('/logsheets/records')->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);

        if ($logsheet) {
            $this->get(route('logsheets.show', $logsheet))->assertStatus(200);
        }

        $this->assertExportIsReadable($index);

        // Clean up so 30 rounds do not accumulate into a slow test.
        LogsheetRawRow::where('import_id', $import->id)->delete();
        foreach (Logsheet::withTrashed()->where('last_import_id', $import->id)->get() as $sheet) {
            $sheet->forceDelete();
        }
        $import->delete();
    }

    protected function assertExportIsReadable(int $index): void
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR."fuzz-export-{$index}.xlsx";

        file_put_contents($path, Excel::raw(
            new LogsheetsSheetExport(Logsheet::query(), 'pending'),
            ExcelFormat::XLSX
        ));

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(
            'Log Sheet No',
            $spreadsheet->getActiveSheet()->getCell('A1')->getValue(),
            "seed ".self::SEED.", sheet {$index}: the export lost its first heading"
        );
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        @unlink($path);
    }

    // ------------------------------------------------------------- generators

    /**
     * @return array<int, array<int, mixed>>
     */
    /**
     * Header spellings for one canonical column.
     *
     * Every spelling here must resolve to the same canonical, and it is derived
     * from the mapper's own alias table rather than written by hand, so a new
     * alias is picked up automatically instead of silently making the fuzzer
     * generate a header the project has never claimed to support.
     *
     * @return array<int, string>
     */
    protected function spellings(string $canonical): array
    {
        $spaced = ucwords(str_replace('_', ' ', $canonical));

        return [
            $spaced,
            strtoupper(str_replace(' ', '_', $spaced)),
            strtolower(str_replace(' ', '', $spaced)),
            $spaced.'.',
            str_replace(' ', '-', $spaced),
            '  '.$spaced.'  ',
        ];
    }

    protected function randomSheet(int $index): array
    {
        $required = [];
        foreach (LogsheetHeaderMapper::REQUIRED as $canonical) {
            $labels = $this->spellings($canonical);
            foreach (LogsheetHeaderMapper::ALIASES[$canonical] ?? [] as $alias) {
                $labels[] = strtoupper($alias);
                $labels[] = str_replace('', ' ', $alias);
            }
            $required[$canonical] = array_values(array_unique($labels));
        }

        $optional = [];
        foreach ([
            'amount', 'gross_wt', 'booked_amount', 'diff', 'volume', 'tprt_code',
            'tprt_name', 'destination', 'sap_invoice_no', 'vendor_inv_no',
            'posting_date', 'bill_date', 'town', 'payer', 'route',
        ] as $canonical) {
            $optional[$canonical] = $this->spellings($canonical);
        }

        // Headers no client would send, but a real file occasionally contains.
        // They must land in `extra` instead of breaking the import.
        $optional['junk_a'] = ['<b>Bold Header</b>', 'Weird "Quoted"', 'Ünïcödé Header', '  padded  ', ''];
        $optional['junk_b'] = ['x&y=z', '100% done', '#REF!', 'a,b', ''];

        // A random subset of the optional columns, always at least two.
        $chosenKeys = (array) array_rand($optional, mt_rand(2, count($optional)));

        $columns = [];
        foreach ($required as $key => $variants) {
            $columns[$key] = ['required' => true, 'labels' => $variants];
        }
        foreach ($chosenKeys as $key) {
            $columns[$key] = ['required' => false, 'labels' => $optional[$key]];
        }

        // Shuffle the physical column order, headers and all.
        $order = (array) array_rand($columns, count($columns));
        $columns = [];
        foreach ($order as $key) {
            $columns[$key] = [
                'required' => array_key_exists($key, $required),
                'labels' => $required[$key] ?? $optional[$key],
            ];
        }

        $header = [];
        foreach ($columns as $column) {
            $header[] = $column['labels'][mt_rand(0, count($column['labels']) - 1)];
        }

        $rows = [$header];
        $rowCount = mt_rand(1, 6);

        for ($i = 0; $i < $rowCount; $i++) {
            $row = [];
            foreach (array_keys($columns) as $key) {
                $row[] = $this->randomValue($key, $index, $i);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    protected function randomValue(string $key, int $sheet, int $row): mixed
    {
        // Blanks are common in real sheets and must be tolerated everywhere.
        if (mt_rand(1, 100) <= 18) {
            return null;
        }

        return match ($key) {
            'log_sheet_no' => $this->randomLogSheetNo($sheet, $row),
            'date', 'inv_date', 'posting_date', 'bill_date' => $this->randomDate(),
            'amount', 'gross_wt', 'booked_amount', 'diff', 'volume' => $this->randomNumber(),
            default => $this->randomText(),
        };
    }

    protected function randomLogSheetNo(int $sheet, int $row): string
    {
        $digits = (string) (900000 + $sheet * 100 + $row);

        return match (mt_rand(1, 8)) {
            1 => "'".$digits."'",
            2 => $digits.'.0',
            3 => '0'.$digits,
            4 => 'FZ-'.$sheet.'-'.$row,
            5 => (string) (int) $digits,
            default => $digits,
        };
    }

    protected function randomDate(): mixed
    {
        $day = mt_rand(1, 28);

        return match (mt_rand(1, 9)) {
            1, 2 => sprintf('2026-09-%02d', $day),
            3, 4 => sprintf('%02d.09.2026', $day),
            5, 6 => sprintf('%02d/09/2026', $day),
            7 => sprintf('%02d-Sep-26', $day),
            8 => 'not-a-date',
            default => sprintf('Sep %d, 2026', $day),
        };
    }

    protected function randomNumber(): mixed
    {
        $value = mt_rand(1, 50000) / 100;

        return match (mt_rand(1, 10)) {
            1, 2 => number_format($value, 2, '.', ''),
            3 => (string) $value,
            4 => '('.number_format($value, 2, '.', '').')',
            5 => number_format($value, 2, '.', '').'-',
            6 => 'Rs. '.number_format($value * 1000, 2, '.', ''),
            7 => '1.2.3',
            8 => '1E+5',
            9 => str_repeat('9', 40),
            default => number_format($value, 2, '.', ''),
        };
    }

    protected function randomText(): string
    {
        return match (mt_rand(1, 9)) {
            1, 2 => 'Plain text '.mt_rand(1, 999),
            3 => str_repeat('L', 300),
            4 => '<b>x</b>',
            5 => '🚚📦 delivery',
            6 => 'Ünïcödé tëxt',
            7 => '<script>alert(1)</script>',
            8 => 'quote " and \' apostrophe',
            default => 'A&B=C',
        };
    }

    // ----------------------------------------------------------- large import

    public function test_a_five_thousand_row_workbook_imports_within_the_memory_limit(): void
    {
        $rows = [['Log Sheet No', 'Date', 'Invoice No', 'Invoice Date', 'Actual Amount', 'Gross Wt', 'Tprt Code', 'Payer']];

        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = [
                '00'.(400000 + intdiv($i - 1, 3)),
                sprintf('2026-09-%02d', (($i - 1) % 28) + 1),
                'INV-'.$i,
                sprintf('2026-09-%02d', (($i - 1) % 28) + 1),
                number_format(10 + $i / 100, 2, '.', ''),
                number_format(100 + $i / 10, 3, '.', ''),
                'T'.($i % 40),
                'PAYER-'.($i % 12),
            ];
        }

        $path = $this->tempDir.DIRECTORY_SEPARATOR.'large-5000.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $rows);
        gc_collect_cycles();

        $started = microtime(true);

        $summary = app(LogsheetImportService::class)->import(
            new UploadedFile($path, 'large-5000.xlsx', null, null, true)
        );

        $elapsed = microtime(true) - $started;
        $peak = memory_get_peak_usage(true);

        $import = LogsheetImport::findOrFail($summary['import_id']);

        $this->assertSame('completed', $import->status);
        $this->assertSame(5000, (int) $import->row_count);
        $this->assertSame(0, (int) $import->invalid_count);
        $this->assertSame(1667, (int) Logsheet::where('last_import_id', $import->id)->count(), 'three rows per log sheet');
        $this->assertSame(5000, (int) LogsheetRawRow::where('import_id', $import->id)->count());

        // The import must fit in the limit the project actually runs under.
        $limit = $this->memoryLimitInBytes();
        $this->assertLessThan(
            $limit,
            $peak,
            "the import peaked at ".round($peak / 1048576).'M, over the '.round($limit / 1048576).'M limit'
        );

        $this->assertLessThan(
            120.0,
            $elapsed,
            'a 5000-row import should not take two minutes'
        );

        $this->get('/logsheets')->assertStatus(200);
        $this->get(route('logsheets.imports.show', $import))->assertStatus(200);

    }

    protected function memoryLimitInBytes(): int
    {
        $limit = ini_get('memory_limit');

        if (! is_string($limit) || $limit === '' || $limit === '-1') {
            return 128 * 1024 * 1024;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}