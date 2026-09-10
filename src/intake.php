<?php
declare(strict_types=1);

const CSV_MAX_BYTES = 131072;
const CSV_MAX_ROWS = 200;
const PREVIEW_TTL = 900;

function parse_csv(string $csv): array {
    if (strlen($csv) > CSV_MAX_BYTES || !preg_match('//u', $csv) || str_contains($csv, "\0")) throw new InvalidArgumentException('Use UTF-8 CSV up to 128 KiB without NUL bytes.');
    if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
    $rows = []; $row = []; $field = ''; $state = 'start'; $ended = true;
    for ($i = 0, $n = strlen($csv); $i < $n; $i++) {
        $c = $csv[$i]; $ended = false;
        if ($state === 'quoted') {
            if ($c === '"') {
                if (($csv[$i + 1] ?? '') === '"') { $field .= '"'; $i++; }
                else $state = 'closed';
            } else $field .= $c;
            continue;
        }
        if ($c === ',' || $c === "\n" || $c === "\r") {
            $row[] = $field; $field = ''; $state = 'start';
            if ($c !== ',') {
                $rows[] = $row; $row = []; $ended = true;
                if ($c === "\r" && ($csv[$i + 1] ?? '') === "\n") $i++;
                if (count($rows) > CSV_MAX_ROWS + 1) throw new InvalidArgumentException('Use at most 200 staff rows per intake.');
            }
        } elseif ($c === '"' && $state === 'start') $state = 'quoted';
        elseif ($c === '"' || $state === 'closed') throw new InvalidArgumentException('Malformed CSV quoting near record ' . (count($rows) + 1) . '.');
        else { $field .= $c; $state = 'plain'; }
    }
    if ($state === 'quoted') throw new InvalidArgumentException('CSV has an unclosed quoted field.');
    if (!$ended) { $row[] = $field; $rows[] = $row; }
    if (count($rows) > CSV_MAX_ROWS + 1) throw new InvalidArgumentException('Use at most 200 staff rows per intake.');
    if (array_shift($rows) !== STAFF_FIELDS) throw new InvalidArgumentException('Header must be exactly: name,email,role,department,status');
    if (!$rows) throw new InvalidArgumentException('Include at least one staff row.');
    return $rows;
}
function preview_intake(string $csv, string $owner, ?int $now = null): array {
    $now ??= time(); $rawRows = parse_csv($csv);
    return atomic(function () use ($rawRows, $owner, $now): array {
        $rows = []; $errors = []; $seen = [];
        foreach ($rawRows as $index => $raw) {
            $number = $index + 2;
            try {
                if (count($raw) !== 5) throw new InvalidArgumentException('Expected exactly five columns.');
                $data = validate_staff(array_combine(STAFF_FIELDS, $raw));
                $email = strtolower($data['email']);
                if (isset($seen[$email])) throw new InvalidArgumentException('Duplicate email; first appears at record ' . $seen[$email] . '.');
                $seen[$email] = $number;
                if (run('SELECT 1 FROM staff WHERE email=?', [$data['email']])->fetchColumn()) throw new InvalidArgumentException('Email already exists, including archived records. No overwrites are allowed.');
                $rows[] = $data;
            } catch (InvalidArgumentException $error) { $errors[] = ['record' => $number, 'message' => $error->getMessage()]; }
        }
        if ($errors) return ['rows' => $rows, 'errors' => $errors];
        run('DELETE FROM intake WHERE confirmed=0 AND (expires<=? OR owner=?)', [$now, $owner]);
        $token = bin2hex(random_bytes(32));
        run('INSERT INTO intake(token,owner,generation,rows_json,expires) VALUES(?,?,?,?,?)', [
            hash('sha256', $token), $owner, run('SELECT generation FROM workspace_state WHERE id=1')->fetchColumn(),
            json_encode($rows, JSON_THROW_ON_ERROR), $now + PREVIEW_TTL,
        ]);
        return ['rows' => $rows, 'errors' => [], 'token' => $token, 'expires' => $now + PREVIEW_TTL];
    });
}
function intake_receipt(string $token, string $owner): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Invalid intake token. Create a new preview.');
    $preview = run('SELECT * FROM intake WHERE token=? AND owner=?', [hash('sha256', $token), $owner])->fetch();
    if (!$preview) throw new InvalidArgumentException('This preview is unavailable in this session. Preview again.');
    return $preview;
}
function confirm_intake(string $token, string $owner, ?int $now = null): int {
    return atomic(function () use ($token, $owner, $now): int {
        $preview = intake_receipt($token, $owner);
        $rows = json_decode($preview['rows_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($preview['confirmed']) return count($rows);
        if ($preview['expires'] <= ($now ?? time())) throw new InvalidArgumentException('This preview expired. Preview the CSV again.');
        if ((int)$preview['generation'] !== (int)run('SELECT generation FROM workspace_state WHERE id=1')->fetchColumn()) throw new InvalidArgumentException('The directory changed after this preview. Preview the CSV again; nothing was imported.');
        foreach ($rows as $row) insert_staff(validate_staff($row), 'CSV imported', substr($preview['token'], 0, 16));
        run('UPDATE intake SET confirmed=1,rows_json=? WHERE token=?', [json_encode(array_fill(0, count($rows), null)), $preview['token']]);
        return count($rows);
    });
}
