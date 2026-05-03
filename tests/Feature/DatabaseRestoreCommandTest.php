<?php

namespace Tests\Feature;

use App\Console\Commands\DatabaseBackupCommand;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseRestoreCommandTest extends TestCase
{
    private string $dumpName = '';

    protected function tearDown(): void
    {
        if ($this->dumpName !== '') {
            $path = DatabaseBackupCommand::dumpPath($this->dumpName);
            if (is_file($path.'/@.json')) {
                unlink($path.'/@.json');
            }
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    private function makeDump(string $name): string
    {
        $this->dumpName = $name;
        $path = DatabaseBackupCommand::dumpPath($name);
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        file_put_contents($path.'/@.json', '{}');

        return $path;
    }

    public function test_fails_when_dump_directory_does_not_exist(): void
    {
        $this->artisan('db:restore', ['name' => 'nonexistent-'.uniqid()])
            ->expectsOutputToContain('Dump not found')
            ->assertFailed();
    }

    public function test_fails_when_connection_is_not_mysql(): void
    {
        config(['database.connections.sqlite_test' => ['driver' => 'sqlite']]);

        $this->artisan('db:restore', ['name' => 'whatever', '--connection' => 'sqlite_test'])
            ->expectsOutputToContain("Connection 'sqlite_test' is not a MySQL connection.")
            ->assertFailed();
    }

    public function test_invokes_mysqlsh_load_dump_with_defer_indexes_and_skip_binlog_by_default(): void
    {
        Process::fake([
            '*mysqlsh*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $name = 'restore-'.uniqid();
        $path = $this->makeDump($name);

        $this->artisan('db:restore', ['name' => $name, '--threads' => 4])->assertSuccessful();

        Process::assertRan(function ($process) use ($path) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'mysqlsh')
                && str_contains($cmd, 'util')
                && str_contains($cmd, 'load-dump')
                && str_contains($cmd, $path)
                && str_contains($cmd, '--threads=4')
                && str_contains($cmd, '--defer-table-indexes=all')
                && str_contains($cmd, '--skip-binlog=true');
        });
    }

    public function test_target_option_redirects_load_to_a_different_schema(): void
    {
        Process::fake([
            '*mysqlsh*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $name = 'restore-target-'.uniqid();
        $this->makeDump($name);

        $this->artisan('db:restore', ['name' => $name, '--target' => 'edcs_clone'])
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, '--schema=edcs_clone');
        });
    }

    public function test_target_option_is_omitted_when_not_set(): void
    {
        Process::fake([
            '*mysqlsh*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $name = 'restore-notarget-'.uniqid();
        $this->makeDump($name);

        $this->artisan('db:restore', ['name' => $name])->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return ! str_contains($cmd, '--schema=');
        });
    }

    public function test_no_defer_indexes_flag_disables_deferral(): void
    {
        Process::fake([
            '*mysqlsh*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $name = 'restore-nodefer-'.uniqid();
        $this->makeDump($name);

        $this->artisan('db:restore', ['name' => $name, '--no-defer-indexes' => true])
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, '--defer-table-indexes=off');
        });
    }

    public function test_fails_when_mysqlsh_returns_nonzero(): void
    {
        Process::fake([
            'mysqlsh*' => Process::result(output: 'boom', exitCode: 1),
        ]);

        $name = 'restore-fail-'.uniqid();
        $this->makeDump($name);

        $this->artisan('db:restore', ['name' => $name])
            ->expectsOutputToContain('mysqlsh load failed.')
            ->assertFailed();
    }
}
