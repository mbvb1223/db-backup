<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

readonly class MysqlDumper
{
    public function __construct(private Config $config)
    {
    }

    public function dump(Database $db, string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException("cannot create $dir");
        }
        $file = "$dir/{$db->name}_" . date('Ymd_His') . '.sql.gz';

        $credentialsFile = $this->writeCredentialsFile($db->server);
        try {
            $mysqldump = [$this->config->mysqldump, "--defaults-extra-file=$credentialsFile", ...$this->config->mysqldumpOptions];

            $commands = [];
            if ($db->excludeData) {
                $commands[] = [...$mysqldump, '--no-data', '--skip-routines', '--skip-events', $db->name, ...$db->excludeData];
            }
            $ignoredTables = array_map(fn (string $table) => "--ignore-table=$db->name.$table", [...$db->exclude, ...$db->excludeData]);
            $commands[] = [...$mysqldump, ...$ignoredTables, $db->name, ...$db->include];

            $this->runGzipped($commands, $file);
        } finally {
            unlink($credentialsFile);
        }

        return $file;
    }

    private function writeCredentialsFile(array $server): string
    {
        $lines = ['[client]'];
        foreach (['host', 'port', 'socket', 'user', 'password'] as $key) {
            if (($server[$key] ?? '') !== '') {
                $lines[] = $key . '="' . addcslashes((string) $server[$key], '"\\') . '"';
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'dbbackup');
        chmod($path, 0600);
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function runGzipped(array $commands, string $file): void
    {
        $part = "$file.part";
        $gzip = gzopen($part, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException("cannot write $part");
        }

        try {
            foreach ($commands as $command) {
                $this->run($command, $gzip);
            }
        } catch (RuntimeException $e) {
            gzclose($gzip);
            unlink($part);
            throw $e;
        }

        if (!gzclose($gzip)) {
            unlink($part);
            throw new RuntimeException("cannot write $part (disk full?)");
        }
        rename($part, $file);
    }

    private function run(array $command, $gzip): void
    {
        $stderr = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if ($process === false) {
            throw new RuntimeException("cannot start {$this->config->mysqldump}");
        }

        $copied = stream_copy_to_stream($pipes[1], $gzip);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        if ($copied === false) {
            throw new RuntimeException('cannot write the dump (disk full?)');
        }
        if ($exitCode !== 0) {
            rewind($stderr);
            throw new RuntimeException("mysqldump exit $exitCode: " . trim(stream_get_contents($stderr)));
        }
    }
}
