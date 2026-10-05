#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configFile = getenv('DB_BACKUP_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config not found: $configFile (copy config.example.php to config.php)\n");
    exit(1);
}
$config = require $configFile;

$backupDir = rtrim($config['backup_dir'], '/');
$keepDays = (int) ($config['keep_days'] ?? 0);
$mysqldump = $config['mysqldump'] ?? 'mysqldump';
$options = $config['options'] ?? [];
$only = array_slice($argv, 1);

if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
    fwrite(STDERR, "Cannot create $backupDir\n");
    exit(1);
}

$lock = fopen("$backupDir/.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logLine('Another backup run is in progress, exiting');
    exit(1);
}

$ok = $failed = 0;
foreach ($config['connections'] as $connName => $conn) {
    foreach ($conn['databases'] ?? [] as $db => $rules) {
        // Allow plain list form: 'databases' => ['a', 'b']
        if (is_int($db)) {
            [$db, $rules] = [$rules, []];
        }
        if ($only && !in_array($db, $only, true) && !in_array("$connName/$db", $only, true)) {
            continue;
        }

        $started = microtime(true);
        try {
            $file = backup($conn, $db, $rules, "$backupDir/$connName/$db", $mysqldump, $options);
            $ok++;
            logLine(sprintf(
                'OK   %s/%s -> %s (%s, %.1fs)',
                $connName, $db, $file, formatBytes(filesize($file)), microtime(true) - $started
            ));
            if ($keepDays > 0) {
                prune(dirname($file), $keepDays);
            }
        } catch (Throwable $e) {
            $failed++;
            logLine("FAIL $connName/$db: " . $e->getMessage());
        }
    }
}

logLine("Done: $ok ok, $failed failed");
exit($failed > 0 ? 1 : 0);

function backup(array $conn, string $db, array $rules, string $dir, string $mysqldump, array $options): string
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

    $cnf = writeCredentials($conn);
    try {
        // --defaults-extra-file must be the first argument
        $cmd = [$mysqldump, "--defaults-extra-file=$cnf", ...$options];
        foreach ($exclude as $table) {
            $cmd[] = "--ignore-table=$db.$table";
        }
        $cmd[] = $db;
        array_push($cmd, ...$include);

        dumpTo($cmd, $file);
    } finally {
        unlink($cnf);
    }

    return $file;
}

// Credentials go in a temp option file so the password never shows up in `ps`.
function writeCredentials(array $conn): string
{
    $lines = ['[client]'];
    foreach (['host', 'port', 'socket', 'user', 'password'] as $key) {
        if (isset($conn[$key]) && $conn[$key] !== '') {
            $lines[] = $key . '="' . addcslashes((string) $conn[$key], '"\\') . '"';
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'dbbackup');
    chmod($path, 0600);
    file_put_contents($path, implode("\n", $lines) . "\n");

    return $path;
}

function dumpTo(array $cmd, string $file): void
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

function prune(string $dir, int $keepDays): void
{
    $cutoff = time() - $keepDays * 86400;
    foreach (glob("$dir/*.sql.gz") ?: [] as $f) {
        if (filemtime($f) < $cutoff) {
            unlink($f);
            logLine("DEL  $f");
        }
    }
}

function logLine(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 1) . ' ' . $units[$i];
}
