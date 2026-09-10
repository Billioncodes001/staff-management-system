<?php
declare(strict_types=1);
require __DIR__ . '/src/support.php';
require __DIR__ . '/src/staff.php';
require __DIR__ . '/src/intake.php';
require __DIR__ . '/src/recovery.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn, string $contains = ''): void {
    try { $fn(); } catch (Throwable $error) {
        if ($contains && !str_contains($error->getMessage(), $contains)) throw $error;
        return;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}
function person(string $email = 'ada@example.test'): array { return ['name'=>'Ada Example','email'=>$email,'role'=>'Engineer','department'=>'Engineering','status'=>'Active']; }
function csv(string $email = 'intake@example.test'): string { return "name,email,role,department,status\n\"Example, Avery\",$email,Coordinator,Operations,Active\n"; }
function count_rows(string $table): int { return (int)run('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
function cli(array $args, ?string $path = null): array {
    $pipes = []; $process = proc_open([PHP_BINARY, __DIR__ . '/bin/workspace.php', ...$args], [1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__, [...getenv(), 'DATABASE' => $path ?? database_path()]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['status'=>proc_close($process), 'out'=>$out, 'err'=>$err];
}
$cases = [
    'legacy migration preserves rows and baseline' => function (): void {
        $old = new PDO('sqlite:' . database_path());
        $old->exec("CREATE TABLE staff (id INTEGER PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL COLLATE NOCASE UNIQUE,role TEXT NOT NULL,department TEXT NOT NULL,status TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
            INSERT INTO staff VALUES(41,'Legacy Example','legacy@example.test','Designer','Design','Away',7,'2024-01-02 03:04:05')");
        $old = null; initialize_staff();
        $row = staff_row(41); check($row['version'] === 7 && $row['created'] === '2024-01-02 03:04:05' && $row['archived'] === 0, 'Preserve legacy values');
        check(run('SELECT action FROM audit')->fetchColumn() === 'legacy baseline', 'Honest baseline');
        initialize_staff(); check(count_rows('audit') === 1, 'Migration idempotent');
    },
    'validation uniqueness and escaping' => function (): void {
        initialize_staff(); save_staff(person());
        foreach ([person(), [...person(),'email'=>'ADA@example.test'], [...person(),'email'=>['bad']], [...person(),'email'=>'bad'], [...person(),'name'=>"Bad\nName"], [...person(),'status'=>'Other'], [...person(),'department'=>'Other'], [...person(),'name'=>str_repeat('x',161)]] as $data) rejects(fn()=>save_staff($data));
        check(count_rows('staff') === 1 && count_rows('audit') === 1, 'Invalid writes leave no trace');
        check(e('<script>') === '&lt;script&gt;' && csv_safe('  +bad')[0] === "'" && csv_safe('=1+1')[0] === "'", 'Output protection');
    },
    'lifecycle no-op and optimistic concurrency' => function (): void {
        initialize_staff(); $id = save_staff(person());
        save_staff([...person(),'id'=>$id,'version'=>1]); check(count_rows('audit')===1 && staff_row($id)['version']===1, 'No-op');
        save_staff([...person(),'id'=>$id,'version'=>1,'status'=>'Away']);
        rejects(fn()=>save_staff([...person(),'id'=>$id,'version'=>1]), 'changed');
        archive_staff($id,2,true); check(staff_row($id)['archived']===1,'Archive');
        rejects(fn()=>save_staff([...person(),'id'=>$id,'version'=>3]), 'archived');
        rejects(fn()=>save_staff(person()),'archived');
        rejects(fn()=>archive_staff($id,2,false),'changed'); archive_staff($id,3,false);
        check(count_rows('audit')===4 && staff_row($id)['version']===4,'Restore audited');
        $event=run("SELECT * FROM audit WHERE action='status changed'")->fetch();
        check(json_decode($event['before_json'],true)['status']==='Active' && json_decode($event['after_json'],true)['status']==='Away','Before and after');
    },
    'audit failure rolls staff mutation back' => function (): void {
        initialize_staff(); save_staff(person());
        database()->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
        rejects(fn()=>save_staff([...person(),'id'=>1,'version'=>1,'status'=>'Away']),'synthetic audit failure');
        check(staff_row(1)['version']===1 && staff_row(1)['status']==='Active','Rollback update');
        rejects(fn()=>archive_staff(1,1,true),'synthetic audit failure'); check(staff_row(1)['archived']===0,'Rollback archive');
    },
    'audit is append only' => function (): void {
        initialize_staff(); save_staff(person());
        rejects(fn()=>run('DELETE FROM audit'),'append-only'); rejects(fn()=>run("UPDATE audit SET action='changed'"),'append-only');
    },
    'CSV grammar BOM CRLF escaped quotes and limits' => function (): void {
        initialize_staff();
        $p = preview_intake("\xEF\xBB\xBFname,email,role,department,status\r\n\"A \"\"B\"\", C\",a@example.test,Engineer,Engineering,Away\r\n",'owner');
        check(!$p['errors'] && $p['rows'][0]['name']==='A "B", C','CSV grammar');
        foreach (["", "name,email,role,department,status\n", 'name,email,role,department,status' . "\n\"open", csv().'a"bad,b,c,d,e', str_repeat('a',CSV_MAX_BYTES+1), "\xff", "name,email,role,department,status\n" . str_repeat("A,a@example.test,R,Design,Active\n",201)] as $text) rejects(fn()=>parse_csv($text));
        $p=preview_intake("name,email,role,department,status\n\"Two\nLines\",two@example.test,R,Design,Active",'owner'); check(count($p['errors'])===1,'Multiline field gets row validation error');
    },
    'CSV all row errors and duplicate rejection' => function (): void {
        initialize_staff(); save_staff(person()); archive_staff(1,1,true);
        $p=preview_intake(csv('ada@example.test')."A,new@example.test,R,Design,Active\nB,NEW@example.test,R,Design,Active\nShort,row\nBad,bad,R,Design,Active\n",'owner');
        check(count($p['errors'])===4 && !isset($p['token']) && count_rows('staff')===1 && count_rows('intake')===0,'All errors, no writes');
    },
    'CSV atomic confirm durable retry and session ownership' => function (): void {
        initialize_staff(); $p=preview_intake(csv(),'owner',1000);
        check(count_rows('staff')===0,'Preview is not import');
        rejects(fn()=>confirm_intake($p['token'],'other',1001),'unavailable');
        rejects(fn()=>confirm_intake(str_repeat('a',64),'owner',1001),'unavailable');
        check(confirm_intake($p['token'],'owner',1001)===1,'Confirm');
        check(confirm_intake($p['token'],'owner',9000)===1 && count_rows('staff')===1 && count_rows('audit')===1,'Durable receipt');
        check(run('SELECT rows_json FROM intake')->fetchColumn()==='[null]','Receipt omits staff PII');
    },
    'concurrent processes share a durable intake receipt' => function (): void {
        initialize_staff(); $p=preview_intake(csv(),'owner');
        $code='require "src/support.php"; require "src/staff.php"; require "src/intake.php"; initialize_staff(); echo confirm_intake($argv[1],"owner");';
        $children=[];
        for ($i=0;$i<2;$i++) {
            $pipes=[]; $process=proc_open([PHP_BINARY,'-r',$code,$p['token']],[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
            $children[]=[$process,$pipes];
        }
        foreach ($children as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($process)===0 && $out==='1','Concurrent confirm: '.$err);
        }
        check(count_rows('staff')===1 && count_rows('audit')===1,'One committed batch across processes');
    },
    'CSV exact expiry and changed generation rejection' => function (): void {
        initialize_staff(); $p=preview_intake(csv(),'owner',1000);
        rejects(fn()=>confirm_intake($p['token'],'owner',1900),'expired');
        $p=preview_intake(csv(),'owner',2000); save_staff(person());
        rejects(fn()=>confirm_intake($p['token'],'owner',2001),'directory changed');
        check(count_rows('staff')===1,'No stale import');
    },
    'CSV mid-batch failure rolls back rows audit and receipt' => function (): void {
        initialize_staff(); $p=preview_intake(csv('first@example.test')."B,second@example.test,R,Design,Active\n",'owner');
        database()->exec("CREATE TRIGGER fail_second BEFORE INSERT ON staff WHEN NEW.email='second@example.test' BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
        rejects(fn()=>confirm_intake($p['token'],'owner'),'synthetic failure');
        check(count_rows('staff')===0 && count_rows('audit')===0 && !(int)run('SELECT confirmed FROM intake')->fetchColumn(),'All rollback');
        database()->exec('DROP TRIGGER fail_second'); check(confirm_intake($p['token'],'owner')===2,'Retry after rollback');
    },
    'real CLI backup integrity permissions and no overwrite' => function (): void {
        initialize_staff(); save_staff(person());
        $dest=dirname(database_path()).'/snapshot.sqlite'; $r=cli(['backup',$dest]); check($r['status']===0,$r['err']);
        $report=json_decode($r['out'],true); check($report['staff']===1 && $report['audit']===1 && $report['sha256']===hash_file('sha256',$dest),'Backup report');
        check((fileperms($dest)&0777)===0600,'Private mode');
        check(cli(['verify',$dest])['status']===0,'Verify CLI'); check(cli(['backup',$dest])['status']===1,'No overwrite');
        check(cli(['backup',__DIR__.'/public/unsafe.sqlite'])['status']===1,'No public backup');
        $r=cli(['restore',$dest,'--confirm']); check($r['status']===1 && str_contains($r['err'],'busy'),'Active connection blocks restore');
    },
    'real CLI restore recovery copy session invalidation and corruption refusal' => function (): void {
        initialize_staff(); save_staff(person()); $base=dirname(database_path());
        $backup=$base.'/backup.sqlite'; $target=$base.'/restore.sqlite';
        check(cli(['backup',$backup])['status']===0,'Create backup'); copy($backup,$target);
        $db=recovery_db($target); $oldEpoch=$db->query('SELECT epoch FROM workspace_state')->fetchColumn();
        $db->exec("UPDATE staff SET status='Inactive',version=version+1"); $db=null;
        check(cli(['restore',$backup],$target)['status']===1,'Confirmation required');
        $r=cli(['restore',$backup,'--confirm'],$target); check($r['status']===0,$r['err']);
        $report=json_decode($r['out'],true); $restored=recovery_db($target); $safety=recovery_db($report['recovery_copy']);
        check($restored->query('SELECT status FROM staff')->fetchColumn()==='Active','Restored backup');
        check($safety->query('SELECT status FROM staff')->fetchColumn()==='Inactive','Recovery copy preserves replaced state');
        check($restored->query('SELECT epoch FROM workspace_state')->fetchColumn()!==$oldEpoch,'Session invalidation');
        check($restored->query("SELECT COUNT(*) FROM audit WHERE action='backup restored'")->fetchColumn()===1,'Restore audited');
        $restored=null; $safety=null;
        $bad=$base.'/bad.sqlite'; file_put_contents($bad,'not sqlite'); $hash=hash_file('sha256',$target);
        check(cli(['restore',$bad,'--confirm'],$target)['status']===1 && hash_file('sha256',$target)===$hash,'Corruption never touches target');
        copy($backup,$bad); $db=recovery_db($bad); $db->exec('CREATE TABLE unexpected (secret TEXT)'); $db=null;
        check(cli(['restore',$bad,'--confirm'],$target)['status']===1 && hash_file('sha256',$target)===$hash,'Unknown schema rejected');
    },
];
if (isset($argv[1])) {
    $name = $argv[1]; $dir = __DIR__ . '/.test-data/core-' . bin2hex(random_bytes(6)); mkdir($dir,0700,true); putenv('DATABASE='.$dir.'/app.sqlite');
    try { $cases[$name](); echo 'PASS: ' . $name . "\n"; }
    catch (Throwable $error) { fwrite(STDERR,$name.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); }
    finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
    exit;
}
foreach (array_keys($cases) as $name) {
    $process=proc_open([PHP_BINARY,__FILE__,$name],[1=>STDOUT,2=>STDERR],$pipes); if (proc_close($process)!==0) exit(1);
}
echo count($cases)." core workflow cases passed.\n";
