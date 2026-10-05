<?php

declare(strict_types=1);

namespace DbBackup;

use RuntimeException;

class MysqlDumper
{
    /** @param string[] $options */
    public function __construct(
        private readonly string $binary,
        private readonly array $options,
    ) {
    }

    /** @return string path of the new .sql.gz */
    public function dump(array $server, string $db, array $rules, string $dir): string
    {
        $include = $rules['include'] ?? [];
        $exclude = $rules['exclude'] ?? [];
        if ($include && $exclude) {
            throw new RuntimeException('use either include or exclude, not both');
        }

        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException("cannot create $dir");
        }
        $file = "$dir/{$db}_" . date('Ymd_His') . '.sql.gz';

        $cnf = $this->writeCredentials($server);
        try {
            // --defaults-extra-file must be the first argument
            $cmd = [$this->binary, "--defaults-extra-file=$cnf", ...$this->options];
            foreach ($exclude as $table) {
                $cmd[] = "--ignore-table=$db.$table";
            }
            $cmd[] = $db;
            array_push($cmd, ...$include);

            $this->run($cmd, $file);
        } finally {
            unlink($cnf);
        }

        return $file;
    }

    // Credentials go in a temp option file so the password never shows up in `ps`.
    private function writeCredentials(array $server): string
    {
        $lines = ['[client]'];
        foreach (['host', 'port', 'socket', 'user', 'password'] as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $lines[] = $key . '="' . addcslashes((string) $server[$key], '"\\') . '"';
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'dbbackup');
        chmod($path, 0600);
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function run(array $cmd, string $file): void
    {
        $part = "$file.part";
        $stderr = tmpfile();
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start mysqldump');
        }

        $gz = gzopen($part, 'wb6');
        $error = $gz === false ? "cannot open $part" : null;
        while ($error === null && !feof($pipes[1])) {
            $chunk = (string) fread($pipes[1], 1 << 20);
            if ($chunk !== '' && gzwrite($gz, $chunk) !== strlen($chunk)) {
                $error = "write failed: $part (disk full?)";
            }
        }
        if ($error !== null) {
            proc_terminate($proc);
        }
        fclose($pipes[1]);
        if ($gz !== false) {
            gzclose($gz);
        }
        $code = proc_close($proc);

        if ($error === null && $code !== 0) {
            rewind($stderr);
            $error = "mysqldump exit $code: " . trim(stream_get_contents($stderr));
        }
        if ($error !== null) {
            @unlink($part);
            throw new RuntimeException($error);
        }

        rename($part, $file);
    }
}
