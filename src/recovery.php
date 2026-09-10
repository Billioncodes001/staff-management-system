<?php
declare(strict_types=1);

function private_path(string $path): string {
    if (!str_starts_with($path, '/') || is_link($path)) throw new InvalidArgumentException('Use an absolute, non-symlink path.');
    $parent = realpath(dirname($path));
    if (!$parent) throw new InvalidArgumentException('The destination directory must already exist.');
    $resolved = $parent . '/' . basename($path);
    $public = realpath(dirname(__DIR__) . '/public');
    if ($resolved === $public || str_starts_with($resolved, $public . '/')) throw new InvalidArgumentException('Database files must stay outside public/.');
    return $resolved;
}
function recovery_db(string $path): PDO {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA trusted_schema=OFF');
    return $db;
}
function schema_fingerprint(PDO $db): string {
    return hash('sha256', json_encode($db->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(), JSON_THROW_ON_ERROR));
}
function verify_database(PDO $db, ?string $schema = null): array {
    if ($schema !== null && !hash_equals($schema, schema_fingerprint($db))) throw new InvalidArgumentException('Backup schema does not match this migrated workspace.');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() !== 1) throw new InvalidArgumentException('Only version 1 People & Co. backups can be restored.');
    if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetch()) throw new InvalidArgumentException('Database integrity check failed.');
    $state = $db->query('SELECT * FROM workspace_state')->fetchAll();
    if (count($state) !== 1 || $state[0]['id'] !== 1 || !preg_match('/^[a-f0-9]{64}$/D', $state[0]['epoch'])) throw new InvalidArgumentException('Invalid workspace state.');
    return ['staff' => (int)$db->query('SELECT COUNT(*) FROM staff')->fetchColumn(), 'audit' => (int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn()];
}
function snapshot_database(PDO $db, string $destination): array {
    $destination = private_path($destination);
    if (file_exists($destination)) throw new InvalidArgumentException('Destination exists; backups never overwrite files.');
    // Reserve the name before VACUUM so concurrent operators cannot replace a snapshot.
    $reserved = fopen($destination, 'x');
    if (!$reserved) throw new RuntimeException('Cannot reserve backup destination.');
    fclose($reserved); chmod($destination, 0600);
    try {
        $db->exec('VACUUM INTO ' . $db->quote($destination));
        $copy = recovery_db($destination);
        $info = verify_database($copy, schema_fingerprint($db));
        $copy = null;
        $handle = fopen($destination, 'r+');
        if (!$handle || !fsync($handle)) throw new RuntimeException('Cannot flush backup to disk.');
        fclose($handle);
        return [...$info, 'path' => $destination, 'sha256' => hash_file('sha256', $destination)];
    } catch (Throwable $error) { unlink($destination); throw $error; }
}
function restore_database(string $source): array {
    $source = private_path($source); $target = private_path(database_path());
    if ($source === $target || !is_file($source) || !is_file($target)) throw new InvalidArgumentException('Restore needs distinct existing backup and workspace files.');
    if (filesize($source) > 268435456) throw new InvalidArgumentException('Restore is limited to 256 MiB backups.');
    $lock = workspace_lock(LOCK_EX);
    $stage = $target . '.restore-' . bin2hex(random_bytes(8));
    try {
        $current = recovery_db($target);
        verify_database($current);
        if (strtolower((string)$current->query('PRAGMA journal_mode')->fetchColumn()) !== 'delete') throw new InvalidArgumentException('Restore requires DELETE journal mode. Stop all writers and checkpoint WAL first.');
        foreach (['-wal', '-shm', '-journal'] as $suffix) if (file_exists($target . $suffix)) throw new InvalidArgumentException('SQLite sidecar files exist. Stop writers and recover/checkpoint the workspace first.');
        $schema = schema_fingerprint($current);
        if (!copy($source, $stage)) throw new RuntimeException('Cannot stage restore.');
        chmod($stage, 0600);
        $restored = recovery_db($stage);
        $info = verify_database($restored, $schema);
        if (strtolower((string)$restored->query('PRAGMA journal_mode')->fetchColumn()) !== 'delete') throw new InvalidArgumentException('Only standalone DELETE-mode snapshots are supported.');
        $safety = snapshot_database($current, $target . '.before-restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite');
        $restored->beginTransaction();
        $restored->prepare('UPDATE workspace_state SET epoch=?,generation=generation+1 WHERE id=1')->execute([bin2hex(random_bytes(32))]);
        $restored->exec('DELETE FROM intake; DELETE FROM login_attempts');
        $restored->prepare('INSERT INTO audit(action,actor,batch) VALUES(?,?,?)')->execute(['backup restored', 'Operator CLI', hash_file('sha256', $source)]);
        $restored->commit();
        verify_database($restored, $schema);
        $restored = null; $current = null;
        $handle = fopen($stage, 'r+');
        if (!$handle || !fsync($handle)) throw new RuntimeException('Cannot flush staged restore.');
        fclose($handle);
        if (!rename($stage, $target)) throw new RuntimeException('Cannot install staged restore.');
        return [...$info, 'restored' => $target, 'recovery_copy' => $safety['path'], 'sessions' => 'invalidated'];
    } finally {
        if (file_exists($stage)) unlink($stage);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
