<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require dirname(__DIR__) . '/src/support.php';
require dirname(__DIR__) . '/src/staff.php';
require dirname(__DIR__) . '/src/recovery.php';
try {
    $command = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if ($command === 'backup' && count($argv) === 3) {
        if (!is_file(database_path())) throw new InvalidArgumentException('Workspace not found. Open the configured app once, or migrate an existing database.');
        private_path(database_path());
        initialize_staff();
        $result = snapshot_database(database(), $path);
    } elseif ($command === 'verify' && count($argv) === 3) {
        $path = private_path($path);
        if (!is_file($path)) throw new InvalidArgumentException('Backup not found.');
        $result = [...verify_database(recovery_db($path)), 'sha256' => hash_file('sha256', $path)];
    } elseif ($command === 'restore' && count($argv) === 4 && $argv[3] === '--confirm') {
        $result = restore_database($path);
    } else throw new InvalidArgumentException('Usage: php bin/workspace.php backup|verify /absolute/copy.sqlite OR restore /absolute/copy.sqlite --confirm. Set DATABASE to the private workspace path. Stop the server before restore.');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) { fwrite(STDERR, 'Recovery operation failed: ' . $error->getMessage() . "\n"); exit(1); }
