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
        $file = "$dir/{$db->name}_" . date('Ymd_His') . '.sql.gz';

        $credentialsFile = $this->writeCredentialsFile($db->server);
        try {
            $command = [$this->config->mysqldump, "--defaults-extra-file=$credentialsFile", ...$this->config->mysqldumpOptions];
            foreach ($db->exclude as $table) {
                $command[] = "--ignore-table=$db->name.$table";
            }
            $command = [...$command, $db->name, ...$db->include];

            $this->runGzipped($command, $file);
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

    private function runGzipped(array $command, string $file): void
    {
        $part = "$file.part";
        $gzip = gzopen($part, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException("cannot write $part");
        }

        $stderr = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if ($process === false) {
            gzclose($gzip);
            unlink($part);
            throw new RuntimeException("cannot start {$this->config->mysqldump}");
        }

        $copied = stream_copy_to_stream($pipes[1], $gzip);
        fclose($pipes[1]);
        $written = gzclose($gzip) && $copied !== false;
        $exitCode = proc_close($process);

        if (!$written) {
            unlink($part);
            throw new RuntimeException("cannot write $part (disk full?)");
        }
        if ($exitCode !== 0) {
            unlink($part);
            rewind($stderr);
            throw new RuntimeException("mysqldump exit $exitCode: " . trim(stream_get_contents($stderr)));
        }
        rename($part, $file);
    }
}
