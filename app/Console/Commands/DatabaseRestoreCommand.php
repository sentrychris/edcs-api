<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use PDO;

class DatabaseRestoreCommand extends Command
{
    protected $signature = 'db:restore
        {name : Name of the dump folder under storage/database/dumps.}
        {--threads=8 : Number of parallel threads mysqlsh should use.}
        {--no-defer-indexes : Build secondary indexes inline instead of after data load.}
        {--keep-binlog : Write loaded rows to the binlog (slower, replication-safe).}
        {--disable-redo-log : Disable the InnoDB redo log for the duration of the restore.}
        {--reset-progress : Discard any prior load progress and start over.}
        {--target= : Target schema name. If set, all dumped objects are loaded into this schema instead of the original.}
        {--connection= : Database connection name. Defaults to the application default.}';

    protected $description = 'Restore a MySQL Shell dump in parallel via util.loadDump.';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! $config || ($config['driver'] ?? null) !== 'mysql') {
            $this->error("Connection '{$connection}' is not a MySQL connection.");

            return self::FAILURE;
        }

        $path = DatabaseBackupCommand::dumpPath($this->argument('name'));

        if (! is_dir($path) || ! is_file($path.'/@.json')) {
            $this->error("Dump not found at {$path}");

            return self::FAILURE;
        }

        $threads = (int) $this->option('threads');
        $deferIndexes = ! $this->option('no-defer-indexes');
        $skipBinlog = ! $this->option('keep-binlog');
        $disableRedoLog = (bool) $this->option('disable-redo-log');
        $resetProgress = (bool) $this->option('reset-progress');
        $target = $this->option('target') ?: null;
        $username = $config['backup_username'] ?: $config['username'];
        $password = $config['backup_password'] ?: $config['password'];

        $this->line("Restoring dump from {$path}");
        $this->line('Threads: '.$threads
            .', user: '.$username
            .($target ? ", target schema: {$target}" : '')
            .', defer indexes: '.($deferIndexes ? 'yes' : 'no')
            .', skip binlog: '.($skipBinlog ? 'yes' : 'no')
            .', disable redo log: '.($disableRedoLog ? 'yes' : 'no'));

        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $originalLocalInfile = (int) $pdo->query('SELECT @@global.local_infile')->fetchColumn();
        if ($originalLocalInfile !== 1) {
            $this->line('Enabling local_infile on the server for the load.');
            $pdo->exec('SET GLOBAL local_infile = 1');
        }

        if ($disableRedoLog) {
            $this->warn('Disabling InnoDB redo log. Server crash during restore will require restarting from scratch.');
            $pdo->exec('ALTER INSTANCE DISABLE INNODB REDO_LOG');
        }

        try {
            $command = [
                'mysqlsh',
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$username,
                '--password='.$password,
                '--no-wizard',
                '--',
                'util',
                'load-dump',
                $path,
                '--threads='.$threads,
                '--defer-table-indexes='.($deferIndexes ? 'all' : 'off'),
                '--analyze-tables=on',
                '--skip-binlog='.($skipBinlog ? 'true' : 'false'),
                '--reset-progress='.($resetProgress ? 'true' : 'false'),
            ];

            if ($target !== null) {
                $command[] = '--schema='.$target;
            }

            $result = Process::forever()->run(
                $command,
                function (string $type, string $buffer) {
                    $this->getOutput()->write($buffer);
                },
            );

            if (! $result->successful()) {
                $this->error('mysqlsh load failed.');

                return self::FAILURE;
            }
        } finally {
            if ($disableRedoLog) {
                $this->line('Re-enabling InnoDB redo log.');
                $pdo->exec('ALTER INSTANCE ENABLE INNODB REDO_LOG');
            }

            if ($originalLocalInfile !== 1) {
                $this->line('Restoring local_infile to its original value.');
                $pdo->exec('SET GLOBAL local_infile = '.$originalLocalInfile);
            }
        }

        $this->info('Restore complete.');

        return self::SUCCESS;
    }
}
