<?php
declare(strict_types=1);
function initialize_staff(): void {
    database()->exec("CREATE TABLE IF NOT EXISTS staff (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE,
        role TEXT NOT NULL, department TEXT NOT NULL, status TEXT NOT NULL,
        version INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
}
function validate_staff(array $input): array {
    $data = [];
    foreach (['name', 'email', 'role', 'department', 'status'] as $key) {
        $data[$key] = input_text($input, $key);
        if ($data[$key] === '') throw new InvalidArgumentException('Complete every field.');
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!in_array($data['status'], ['Active', 'Away', 'Inactive'], true)) throw new InvalidArgumentException('Choose a valid status.');
    if (!in_array($data['department'], ['Engineering', 'Design', 'Operations', 'People', 'Sales'], true)) throw new InvalidArgumentException('Choose a valid department.');
    return $data;
}
function save_staff(array $input): void {
    $data = validate_staff($input);
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    if ($id === false || $id < 0) throw new InvalidArgumentException('Invalid staff ID.');
    try {
        if ($id) {
            $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
            if (!$version || !run('UPDATE staff SET name=:name,email=:email,role=:role,department=:department,status=:status,version=version+1 WHERE id=:id AND version=:version', [...$data, 'id' => $id, 'version' => $version])->rowCount())
                throw new InvalidArgumentException('This record changed or was deleted. Reload it before saving.');
        } else {
            run('INSERT INTO staff(name,email,role,department,status) VALUES (:name,:email,:role,:department,:status)', $data);
        }
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'UNIQUE')) throw new InvalidArgumentException('That email belongs to an existing team member.');
        throw $error;
    }
}
function csv_safe(string $value): string {
    return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
}
