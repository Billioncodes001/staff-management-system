<?php
declare(strict_types=1);

const STAFF_FIELDS = ['name', 'email', 'role', 'department', 'status'];
const DEPARTMENTS = ['Engineering', 'Design', 'Operations', 'People', 'Sales'];
const STATUSES = ['Active', 'Away', 'Inactive'];

function atomic(callable $operation): mixed {
    $db = database(); $db->exec('BEGIN IMMEDIATE');
    try { $result = $operation(); $db->exec('COMMIT'); return $result; }
    catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}
function initialize_staff(): void {
    if ((int)database()->query('PRAGMA user_version')->fetchColumn() === 1) return;
    atomic(function (): void {
        $version = (int)database()->query('PRAGMA user_version')->fetchColumn();
        if ($version === 1) return;
        if ($version !== 0) throw new RuntimeException('Unsupported database version.');
        database()->exec("CREATE TABLE IF NOT EXISTS staff (
            id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            role TEXT NOT NULL, department TEXT NOT NULL, status TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $columns = array_column(run('PRAGMA table_info(staff)')->fetchAll(), 'name');
        if (!in_array('archived', $columns, true)) database()->exec('ALTER TABLE staff ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
        database()->exec("CREATE TABLE audit (
            id INTEGER PRIMARY KEY, staff_id INTEGER, action TEXT NOT NULL, actor TEXT NOT NULL,
            before_json TEXT, after_json TEXT, batch TEXT, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
            CREATE INDEX audit_staff ON audit(staff_id,id);
            CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit BEGIN SELECT RAISE(ABORT,'Audit is append-only'); END;
            CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit BEGIN SELECT RAISE(ABORT,'Audit is append-only'); END;
            CREATE TABLE intake (
                token TEXT PRIMARY KEY, owner TEXT NOT NULL, generation INTEGER NOT NULL,
                rows_json TEXT NOT NULL, expires INTEGER NOT NULL, confirmed INTEGER NOT NULL DEFAULT 0);");
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            database()->exec('CREATE TRIGGER staff_generation_' . strtolower($event) . ' AFTER ' . $event . ' ON staff BEGIN UPDATE workspace_state SET generation=generation+1 WHERE id=1; END');
        }
        foreach (run('SELECT * FROM staff ORDER BY id')->fetchAll() as $row) audit_staff('legacy baseline', null, $row, null, 'Migration / prior history unknown');
        database()->exec('PRAGMA user_version=1');
    });
}
function validate_staff(array $input): array {
    $data = [];
    foreach (STAFF_FIELDS as $key) {
        $data[$key] = input_text($input, $key);
        if ($data[$key] === '') throw new InvalidArgumentException('Complete ' . $key . '.');
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!in_array($data['status'], STATUSES, true)) throw new InvalidArgumentException('Choose Active, Away or Inactive.');
    if (!in_array($data['department'], DEPARTMENTS, true)) throw new InvalidArgumentException('Choose Engineering, Design, Operations, People or Sales.');
    return $data;
}
function audit_staff(string $action, ?array $before, ?array $after, ?string $batch = null, ?string $actor = null): void {
    run('INSERT INTO audit(staff_id,action,actor,before_json,after_json,batch) VALUES(?,?,?,?,?,?)', [
        $after['id'] ?? $before['id'] ?? null, $action, $actor ?? ($_SESSION['actor'] ?? 'Operator CLI'),
        $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), $batch,
    ]);
}
function staff_row(int $id): array|false { return run('SELECT * FROM staff WHERE id=?', [$id])->fetch(); }
function checked_staff(int $id, int $version): array {
    $row = staff_row($id);
    if (!$row || $id < 1 || $version < 1 || (int)$row['version'] !== $version) throw new InvalidArgumentException('This record changed or was removed. Reload it before saving.');
    return $row;
}
function insert_staff(array $data, string $action = 'created', ?string $batch = null): int {
    run('INSERT INTO staff(name,email,role,department,status) VALUES (:name,:email,:role,:department,:status)', $data);
    $id = (int)database()->lastInsertId();
    audit_staff($action, null, staff_row($id), $batch);
    return $id;
}
function save_staff(array $input): int {
    $data = validate_staff($input);
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    $version = filter_var($input['version'] ?? 0, FILTER_VALIDATE_INT);
    if ($id === false || $id < 0 || $version === false) throw new InvalidArgumentException('Invalid staff ID or version.');
    try {
        return atomic(function () use ($data, $id, $version): int {
            if (!$id) return insert_staff($data);
            $before = checked_staff($id, $version);
            if ($before['archived']) throw new InvalidArgumentException('Restore this archived record before editing.');
            if (array_intersect_key($before, $data) === $data) return $id;
            run('UPDATE staff SET name=:name,email=:email,role=:role,department=:department,status=:status,version=version+1 WHERE id=:id', [...$data, 'id' => $id]);
            audit_staff($before['status'] !== $data['status'] ? 'status changed' : 'updated', $before, staff_row($id));
            return $id;
        });
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'UNIQUE')) throw new InvalidArgumentException('That email belongs to an existing or archived team member.');
        throw $error;
    }
}
function archive_staff(int $id, int $version, bool $archive): void {
    atomic(function () use ($id, $version, $archive): void {
        $before = checked_staff($id, $version);
        if ((bool)$before['archived'] === $archive) return;
        run('UPDATE staff SET archived=?,version=version+1 WHERE id=?', [(int)$archive, $id]);
        audit_staff($archive ? 'archived' : 'restored', $before, staff_row($id));
    });
}
function csv_safe(string $value): string {
    return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
}
