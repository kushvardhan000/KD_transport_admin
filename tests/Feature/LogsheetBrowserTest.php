<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FX-5: the import flow in a real browser.
 *
 * The browser suite runs against its own throwaway database and its own
 * `php artisan serve` process, so nothing it does can touch development data.
 * It is skipped — loudly — when the machine cannot host a browser at all,
 * because "the browser test did not run" must never read as "the browser test
 * passed".
 */
class LogsheetBrowserTest extends TestCase
{
    protected string $database = '';

    /** @var resource|null */
    protected $serverProcess = null;

    /** @var array<int, resource> */
    protected array $serverPipes = [];

    protected int $port = 0;

    protected string $serverLog = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessBrowserAvailable();
    }

    protected function tearDown(): void
    {
        $this->stopServer();

        if ($this->database !== '' && $this->canReachMysql()) {
            $this->runAgainstMysql('DROP DATABASE IF EXISTS `'.$this->database.'`');
        }

        parent::tearDown();
    }

    public function test_the_import_flow_works_in_a_real_browser(): void
    {
        $this->prepareThrowawayDatabase();

        $this->startServer();

        $script = base_path('tests/Browser/logsheet-import.puppeteer.mjs');
        $this->assertFileExists($script, 'the Puppeteer scenario script is missing');

        $environment = array_merge($this->childEnvironment(), [
            'BASE_URL' => $this->baseUrl(),
            'EMAIL' => 'puppeteer@transport.test',
            'PASSWORD' => 'password123',
            'FIXTURE_DIR' => base_path('tests/fixtures/logsheets'),
            'DOWNLOAD_DIR' => storage_path('app/puppeteer-downloads'),
        ]);

        $this->assertFileExists($script, 'the Puppeteer scenario script is missing');

        [$status, $output] = $this->runNode($script, $environment);

        $this->assertSame(
            0,
            $status,
            "the browser run failed (exit {$status}):\n".$output
        );

        $this->assertStringContainsString('browser checks passed', $output);
    }

    // -------------------------------------------------------------- harness

    /**
     * Run the browser scenario and return [exit code, output].
     *
     * Output is captured to a file rather than a pipe: the scenario can print
     * more than a pipe buffer holds, and a blocked child would hang the suite
     * instead of failing it.
     *
     * @param  array<string, string>  $environment
     * @return array{0: int, 1: string}
     */
    protected function runNode(string $script, array $environment): array
    {
        $log = storage_path('logs'.DIRECTORY_SEPARATOR.'puppeteer.log');
        @mkdir(dirname($log), 0777, true);
        file_put_contents($log, '');

        $descriptors = [
            0 => ['file', $this->serverLog ?: $log, 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = proc_open(
            ['node', $script],
            $descriptors,
            $pipes,
            base_path(),
            $environment
        );

        if (! is_resource($process)) {
            return [127, "could not start node\n".(string) @file_get_contents($log)];
        }

        $status = proc_close($process);

        $output = (string) @file_get_contents($log);
        @unlink($log);

        return [$status, $output];
    }

    protected function skipUnlessBrowserAvailable(): void
    {
        if (! $this->commandExists('node')) {
            $this->markTestSkipped('node is not installed, so the browser suite cannot run');
        }

        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('puppeteer is not installed (run npm install)');
        }

        if (! $this->canReachMysql()) {
            $this->markTestSkipped('MySQL is not reachable, so the browser suite has no database');
        }
    }

    protected function commandExists(string $command): bool
    {
        $path = trim((string) shell_exec('where '.escapeshellarg($command).' 2>'.$this->nullDevice()));

        return $path !== '';
    }

    protected function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    protected function canReachMysql(): bool
    {
        try {
            $this->runAgainstMysql('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run one statement on the server itself, so the throwaway database can be
     * created and dropped without touching the configured connection.
     */
    protected function runAgainstMysql(string $sql): void
    {
        $config = config('database.connections.mysql');

        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec($sql);
    }

    protected function prepareThrowawayDatabase(): void
    {
        $this->database = 'logsheet_browser_'.Str::lower(Str::random(8));

        $this->runAgainstMysql('DROP DATABASE IF EXISTS `'.$this->database.'`');
        $this->runAgainstMysql(
            'CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        // Migrate the throwaway database from this process, then hand the same
        // name to the web server through the environment.
        Config::set('database.connections.mysql.database', $this->database);
        DB::purge('mysql');

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), 'the throwaway database did not migrate');

        User::create([
            'name' => 'Browser Admin',
            'email' => 'puppeteer@transport.test',
            'password' => 'password123',
            'is_active' => true,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->assertSame(1, User::count(), 'the throwaway database has exactly the browser admin');
    }

    protected function startServer(): void
    {
        $this->port = $this->freePort();

        $command = sprintf(
            'php artisan serve --host=127.0.0.1 --port=%d',
            $this->port
        );

        $this->serverLog = storage_path('logs'.DIRECTORY_SEPARATOR.'serve-'.$this->port.'.log');
        @mkdir(dirname($this->serverLog), 0777, true);
        file_put_contents($this->serverLog, '');

        // Output goes to a file rather than a pipe: a pipe nobody drains can fill
        // up and stall the server mid-request.
        $descriptors = [
            0 => ['file', $this->serverLog, 'r'],
            1 => ['file', $this->serverLog, 'a'],
            2 => ['file', $this->serverLog, 'a'],
        ];

        $this->serverProcess = proc_open($command, $descriptors, $this->serverPipes, base_path(), $this->childEnvironment());
        $this->assertIsResource($this->serverProcess, 'could not start php artisan serve');

        $this->waitForServer();
    }

    /**
     * The environment the web server and the browser run get.
     *
     * Built from the real environment rather than $_ENV: `php artisan serve`
     * itself starts a second PHP process, and that grandchild needs TMP/TEMP to
     * write its own pipes. Hand it a stripped environment and the server dies
     * with an opaque "permission denied" from the Windows temp directory.
     *
     * The testing overrides from phpunit.xml are undone on purpose. The suite
     * runs with SESSION_DRIVER=array, and a browser server that keeps sessions
     * in memory answers every login POST with a 419 — the CSRF token from the
     * form was minted in a session that no longer exists. The server under test
     * must look like the server that ships, where sessions live in the
     * database, and must point at the throwaway database rather than at
     * transport_testing.
     *
     * @return array<string, string>
     */
    protected function childEnvironment(): array
    {
        $environment = [];

        foreach (getenv() as $key => $value) {
            $environment[$key] = (string) $value;
        }

        return array_merge($environment, [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'DB_DATABASE' => $this->database,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
        ]);
    }

    protected function waitForServer(): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.5);

            if ($socket) {
                fclose($socket);
                $this->assertTrue(true);

                return;
            }

            usleep(200000);
        }

        $this->fail('php artisan serve never came up on port '.$this->port."\n".$this->serverOutput());
    }

    protected function serverOutput(): string
    {
        return $this->serverLog !== '' && is_file($this->serverLog)
            ? (string) file_get_contents($this->serverLog)
            : '';
    }

    protected function stopServer(): void
    {
        if (! is_resource($this->serverProcess)) {
            return;
        }

        proc_terminate($this->serverProcess);
        proc_close($this->serverProcess);

        $this->serverProcess = null;
        $this->serverPipes = [];

        if ($this->serverLog !== '' && is_file($this->serverLog)) {
            @unlink($this->serverLog);
        }
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            $this->fail('could not reserve a port: '.$error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    protected function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }
}