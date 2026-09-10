<?php
declare(strict_types=1);
$path = tempnam(sys_get_temp_dir(), 'staff-test-'); putenv('DATABASE=' . $path);
require __DIR__ . '/src/support.php'; require __DIR__ . '/src/staff.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn): void { try { $fn(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Expected validation failure'); }
try {
    initialize_staff();
    $person = ['name'=>'Ada Example','email'=>'ada@example.com','role'=>'Engineer','department'=>'Engineering','status'=>'Active'];
    save_staff($person); check((int)run('SELECT COUNT(*) FROM staff')->fetchColumn() === 1, 'Create');
    rejects(fn()=>save_staff($person));
    rejects(fn()=>save_staff([...$person, 'email'=>'invalid']));
    rejects(fn()=>save_staff([...$person, 'email'=>['bad']]));
    rejects(fn()=>save_staff([...$person, 'status'=>'invalid']));
    save_staff([...$person, 'id'=>1, 'version'=>1, 'status'=>'Away']);
    check(run('SELECT status FROM staff WHERE id=1')->fetchColumn()==='Away', 'Update');
    rejects(fn()=>save_staff([...$person, 'id'=>1, 'version'=>1]));
    rejects(fn()=>save_staff([...$person, 'id'=>999, 'version'=>1]));
    check(csv_safe('=HYPERLINK("bad")')[0] === "'", 'CSV injection');
    check(csv_safe('  +bad')[0] === "'", 'CSV whitespace injection');
    check(e('<script>')==='&lt;script&gt;', 'Escape');
    run('DELETE FROM staff WHERE id=?',[1]); check((int)run('SELECT COUNT(*) FROM staff')->fetchColumn()===0, 'Delete');
    echo "PASS: create, unique email, validation, update, stale-write rejection, delete, escaping and CSV safety.\n";
} finally { unlink($path); }
