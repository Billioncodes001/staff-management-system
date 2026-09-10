<?php
declare(strict_types=1);

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function database_path(): string {
    $path = getenv('DATABASE') ?: dirname(__DIR__) . '/data/app.sqlite';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $parent = realpath(dirname($path));
    if (!$parent || is_link($path)) throw new RuntimeException('Use a database in a private directory, not a symlink.');
    $path = $parent . '/' . basename($path);
    $public = realpath(dirname(__DIR__) . '/public');
    if (str_starts_with($path, $public . '/')) throw new RuntimeException('DATABASE must be outside public/.');
    return $path;
}
function workspace_lock(int $mode = LOCK_SH): mixed {
    $path = database_path();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, $mode | LOCK_NB)) throw new RuntimeException('Workspace is busy. Retry after the current request or recovery operation.');
    return $lock;
}
function database(): PDO {
    static $db, $lock;
    if ($db) return $db;
    umask(0077);
    $path = database_path();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $lock = workspace_lock();
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT PRIMARY KEY, attempts INTEGER NOT NULL, started INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS workspace_state (id INTEGER PRIMARY KEY CHECK(id=1), generation INTEGER NOT NULL, epoch TEXT NOT NULL)');
    $stmt = $db->prepare('INSERT OR IGNORE INTO workspace_state VALUES(1,0,?)');
    $stmt->execute([bin2hex(random_bytes(32))]);
    return $db;
}
function workspace_epoch(): string { return (string)run('SELECT epoch FROM workspace_state WHERE id=1')->fetchColumn(); }
function run(string $sql, array $params = []): PDOStatement {
    $statement = database()->prepare($sql); $statement->execute($params); return $statement;
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function notice(string $message): void { $_SESSION['notice'] = $message; }
function go(string $path = '/'): never { header('Location: ' . $path, true, 303); exit; }
function fail(int $status, string $message): never {
    http_response_code($status); echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Request could not be completed</title><body><h1>' . $status . '</h1><p>' . e($message) . '</p><a href="/">Return to workspace</a></body></html>'; exit;
}
function boot(string $name, string $intro): void {
    umask(0077);
    set_exception_handler(function (Throwable $error): never {
        if ($error instanceof InvalidArgumentException) fail(400, $error->getMessage());
        error_log($error->getMessage());
        fail(500, 'An unexpected error occurred. Please retry.');
    });
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{1,5})?$/D', $host)
        || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) fail(403, 'This workspace is loopback-only.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
        || (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $host)))) fail(403, 'Cross-origin forms are not accepted.');
    if (strlen((string)getenv('APP_PASSWORD')) < 12) fail(503, 'Set APP_PASSWORD to at least 12 characters before starting.');
    session_name('workspace_' . substr(hash('sha256', __DIR__), 0, 12));
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => getenv('COOKIE_SECURE') === 'true', 'use_strict_mode' => true]);
    if (!empty($_SESSION['authenticated']) && ((int)($_SESSION['expires'] ?? 0) <= time()
        || !hash_equals((string)($_SESSION['password_binding'] ?? ''), hash('sha256', (string)getenv('APP_PASSWORD')))
        || !hash_equals((string)($_SESSION['epoch'] ?? ''), workspace_epoch()))) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 524288) fail(413, 'This request is too large.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) fail(403, 'This form expired. Reload and try again.');
        if (($_POST['action'] ?? '') === 'logout') { $_SESSION = []; session_regenerate_id(true); go(); }
        if (($_POST['action'] ?? '') === 'login') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; $now = time();
            run('DELETE FROM login_attempts WHERE started < ?', [$now - 60]);
            $count = run('SELECT attempts FROM login_attempts WHERE ip = ?', [$ip])->fetchColumn();
            if ($count !== false && $count >= 10) fail(429, 'Too many login attempts. Wait one minute.');
            run('INSERT INTO login_attempts VALUES (?,1,?) ON CONFLICT(ip) DO UPDATE SET attempts=attempts+1', [$ip, $now]);
            if (is_string($_POST['password'] ?? null) && hash_equals(getenv('APP_PASSWORD'), $_POST['password'])) {
                session_regenerate_id(true); $_SESSION['authenticated'] = true;
                $_SESSION['expires'] = time() + 28800; $_SESSION['epoch'] = workspace_epoch();
                $_SESSION['password_binding'] = hash('sha256', (string)getenv('APP_PASSWORD'));
                $_SESSION['actor'] = 'Management / ' . substr(bin2hex(random_bytes(8)), 0, 12);
                $_SESSION['csrf'] = bin2hex(random_bytes(32)); run('DELETE FROM login_attempts WHERE ip = ?', [$ip]); go();
            }
            notice('That password is not correct.'); go();
        }
    }
    if (empty($_SESSION['authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(401, 'Please sign in.');
        page_start($name, 'Private workspace');
        echo '<section class="hero"><p class="eyebrow">A BILLIONCODES WORKSPACE</p><h1>' . e($intro) . '</h1><p>Sign in to your private workspace to get started.</p></section><form class="panel login" method="post">' . csrf_field() . '<input type="hidden" name="action" value="login"><h2>Welcome back.</h2><label for="password">Workspace password</label><input id="password" name="password" type="password" required autocomplete="current-password"><button>Open workspace &rarr;</button></form>';
        page_end(); exit;
    }
}
function page_start(string $name, string $section): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($name) . ' | ' . e($section) . '</title><link rel="stylesheet" href="/style.css"></head><body><div class="shell"><header><a href="/" class="brand">' . e($name) . '<span> / </span></a><span class="edition">' . e($section) . '</span>';
    if (!empty($_SESSION['authenticated'])) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form>';
    echo '</header><main>';
    if (isset($_SESSION['notice'])) { echo '<p class="notice" role="status">' . e($_SESSION['notice']) . '</p>'; unset($_SESSION['notice']); }
}
function page_end(): void { echo '</main><footer>Built with intention.<span>Billioncodes / Independent software</span></footer></div></body></html>'; }
function field(string $name, string $label, string $type = 'text', string $value = '', bool $required = true): void {
    echo '<label for="' . e($name) . '">' . e($label) . '</label><input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" maxlength="160" ' . ($required ? 'required' : '') . '>';
}
function choice(string $name, string $label, array $options, string $selected = ''): void {
    echo '<label for="' . e($name) . '">' . e($label) . '</label><select id="' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $option) echo '<option' . ($option === $selected ? ' selected' : '') . '>' . e($option) . '</option>';
    echo '</select>';
}
function input_text(array $input, string $key, int $limit = 160): string {
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen(trim($value)) > $limit || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Invalid ' . $key . '. Use single-line UTF-8 text within ' . $limit . ' bytes.');
    return trim($value);
}
