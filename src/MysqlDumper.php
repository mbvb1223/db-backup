<?php

declare(strict_types=1);

namespace DbBackup;

use Closure;
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
        $out = fopen($part, 'wb');
        if ($out === false) {
            throw new RuntimeException("cannot write $part");
        }
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        try {
            foreach ($commands as $command) {
                $this->run($command, fn (string $chunk) => $this->write($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH)));
            }
            $this->write($out, deflate_add($deflate, '', ZLIB_FINISH));
        } catch (RuntimeException $e) {
            fclose($out);
            unlink($part);
            throw $e;
        }

        fclose($out);
        rename($part, $file);
    }

    // gzclose()/fclose() return true even when the final write fails, so every write is checked
    private function write($out, string $data): void
    {
        if ($data !== '' && fwrite($out, $data) !== strlen($data)) {
            throw new RuntimeException('cannot write the dump (disk full?)');
        }
    }

    private function run(array $command, Closure $write): void
    {
        $stderr = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if ($process === false) {
            throw new RuntimeException("cannot start {$this->config->mysqldump}");
        }

        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException('cannot read mysqldump output');
                }
                $write($chunk);
            }
        } finally {
            fclose($pipes[1]);
            $exitCode = proc_close($process);
        }

        if ($exitCode !== 0) {
            rewind($stderr);
            throw new RuntimeException("mysqldump exit $exitCode: " . trim(stream_get_contents($stderr)));
        }
    }
}
