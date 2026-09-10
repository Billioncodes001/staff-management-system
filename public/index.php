<?php
declare(strict_types=1);
if (PHP_SAPI === 'cli-server') {
    $asset = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($asset === '/style.css' || (is_string($asset) && preg_match('~^/fonts/[a-z]+\.woff2$~', $asset) && is_file(__DIR__ . $asset))) return false;
}
require dirname(__DIR__) . '/src/support.php';
require dirname(__DIR__) . '/src/staff.php';
require dirname(__DIR__) . '/src/intake.php';
boot('People & Co.', 'A little clarity. A stronger team.');
initialize_staff();
run('DELETE FROM intake WHERE confirmed=0 AND expires<=?', [time()]);
$owner = hash('sha256', session_id());
$view = input_text($_GET, 'view', 20);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input_text($_POST, 'action', 30);
    if ($action === 'delete') $action = 'archive';
    try {
        if ($action === 'preview') {
            unset($_SESSION['intake_token'], $_SESSION['intake_errors']);
            $csv = $_POST['csv'] ?? '';
            if (!is_string($csv)) throw new InvalidArgumentException('Choose one CSV file or paste CSV text.');
            $upload = $_FILES['csv_file'] ?? null;
            if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($csv !== '') throw new InvalidArgumentException('Choose a file OR paste CSV, not both.');
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > CSV_MAX_BYTES || !is_uploaded_file($upload['tmp_name'])) throw new InvalidArgumentException('Upload a CSV file up to 128 KiB.');
                $csv = file_get_contents($upload['tmp_name']);
            }
            $_SESSION['intake_draft'] = strlen($csv) <= CSV_MAX_BYTES ? $csv : '';
            $preview = preview_intake($csv, $owner);
            if ($preview['errors']) { $_SESSION['intake_errors'] = $preview['errors']; notice('Nothing imported. Correct every listed record, then preview again.'); }
            else { $_SESSION['intake_token'] = $preview['token']; unset($_SESSION['intake_draft']); }
            go('/?view=intake');
        } elseif ($action === 'confirm') {
            $count = confirm_intake(input_text($_POST, 'token', 64), $owner);
            unset($_SESSION['intake_token'], $_SESSION['intake_draft']);
            notice($count . ' team members imported. Repeating this confirmation will not create duplicates.');
        } elseif ($action === 'cancel_intake') {
            run('DELETE FROM intake WHERE owner=? AND confirmed=0', [$owner]);
            unset($_SESSION['intake_token'], $_SESSION['intake_draft'], $_SESSION['intake_errors']);
            go('/?view=intake');
        } elseif ($action === 'save') { save_staff($_POST); unset($_SESSION['staff_draft']); notice('Team member saved.'); }
        elseif (in_array($action, ['archive', 'restore'], true)) {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !$version) throw new InvalidArgumentException('Invalid record or version.');
            archive_staff($id, $version, $action === 'archive');
            notice($action === 'archive' ? 'Team member archived. Restore them from the archive at any time.' : 'Team member restored.');
        } else fail(400, 'Unknown action.');
    } catch (InvalidArgumentException $error) {
        notice($error->getMessage());
        if (in_array($action, ['preview', 'confirm'], true)) go('/?view=intake');
        if ($action === 'save') {
            $_SESSION['staff_draft'] = array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, array_flip([...STAFF_FIELDS, 'id', 'version'])));
            go('/?draft=1');
        }
    }
    go();
}
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="staff-intake-template.csv"');
    echo "name,email,role,department,status\nAlex Example,alex@example.test,Coordinator,Operations,Active\n"; exit;
}
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="team-directory.csv"');
    $file = fopen('php://output', 'w');
    fputcsv($file, ['ID','Name','Email','Role','Department','Status'], ',', '"', '');
    foreach (run('SELECT id,name,email,role,department,status FROM staff WHERE archived=0 ORDER BY name')->fetchAll() as $row)
        fputcsv($file, array_map(fn($v) => csv_safe((string)$v), array_values($row)), ',', '"', '');
    fclose($file); exit;
}
if (in_array($view, ['intake', 'history'], true)) {
    page_start('People & Co.', $view === 'intake' ? 'Reviewed CSV intake' : 'Lifecycle history');
    require dirname(__DIR__) . '/src/' . $view . '-view.php';
    page_end(); exit;
}
$q = input_text($_GET, 'q', 160); $status = input_text($_GET, 'status', 30);
$archived = $view === 'archive';
$rows = run("SELECT * FROM staff WHERE archived=? AND instr(lower(name || ' ' || email || ' ' || role || ' ' || department),lower(?))>0 AND (?='' OR status=?) ORDER BY name COLLATE NOCASE", [(int)$archived,$q,$status,$status])->fetchAll();
$editing = isset($_GET['edit']) ? run('SELECT * FROM staff WHERE id=? AND archived=0', [(int)$_GET['edit']])->fetch() : false;
if (isset($_GET['draft'], $_SESSION['staff_draft'])) $editing = $_SESSION['staff_draft'];
$totals = run("SELECT COUNT(*) total, COALESCE(SUM(status='Active'),0) active, COUNT(DISTINCT department) departments FROM staff WHERE archived=0")->fetch();
page_start('People & Co.', 'Team directory');
?>
<section class="hero"><p class="eyebrow">PEOPLE OPERATIONS / YOUR TEAM, TOGETHER</p><h1>Great work starts<br>with your people.</h1><p>A calm, considered space for the people behind the work. Keep your team directory clear, current and connected.</p><div class="stats"><p><strong><?= e($totals['total']) ?></strong>Team members</p><p><strong><?= e($totals['active']) ?></strong>Active right now</p><p><strong><?= e($totals['departments']) ?></strong>Departments</p></div></section>
<nav class="tabs" aria-label="Workspace"><a href="/" <?= !$archived ? 'class="active" aria-current="page"' : '' ?>>Directory</a><a href="/?view=intake">CSV intake</a><a href="/?view=history">Lifecycle history</a><a href="/?view=archive" <?= $archived ? 'class="active" aria-current="page"' : '' ?>>Archive</a></nav>
<div class="workspace"><form class="panel" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>"><input type="hidden" name="version" value="<?= e($editing['version'] ?? 0) ?>"><p class="eyebrow">THE PEOPLE DESK</p><h2><?= $editing ? 'Edit team member.' : 'Make an introduction.' ?></h2>
<?php field('name','Full name','text',$editing['name'] ?? ''); field('email','Work email','email',$editing['email'] ?? ''); field('role','Job title','text',$editing['role'] ?? ''); choice('department','Department',['Engineering','Design','Operations','People','Sales'],$editing['department'] ?? ''); choice('status','Status',['Active','Away','Inactive'],$editing['status'] ?? ''); ?>
<button><?= $editing ? 'Save changes' : 'Add team member' ?> &rarr;</button><?php if ($editing): ?><p><a href="/">Discard draft / reload directory</a></p><?php endif ?><p class="hint">Private, local storage. Changes keep an audit trail. CSV exports are not full backups.</p></form>
<section><div class="toolbar"><h2><?= $archived ? 'Room to return.' : 'Meet the team.' ?></h2><a class="button quiet" href="/?export=1">Export CSV &nearr;</a></div><form class="toolbar" method="get"><input type="hidden" name="view" value="<?= $archived ? 'archive' : '' ?>"><label class="sr-only" for="q">Search team</label><input id="q" name="q" placeholder="Find a name, role or department..." value="<?= e($q) ?>"><label class="sr-only" for="filter-status">Filter status</label><select id="filter-status" name="status"><option value="">All statuses</option><?php foreach (STATUSES as $value): ?><option <?= $status === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach ?></select><button class="quiet">Search</button></form>
<div class="list"><?php foreach ($rows as $row): ?><article class="record"><div class="identity"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($row['name'],0,1))) ?></span><div><h3><?= e($row['name']) ?></h3><p><?= e($row['role']) ?> / <?= e($row['department']) ?></p><p><?= e($row['email']) ?></p></div></div><span class="badge"><?= e($row['status']) ?></span><div class="actions"><?php if (!$archived): ?><a class="button quiet" href="/?edit=<?= e($row['id']) ?>" aria-label="Edit <?= e($row['name']) ?>">Edit</a><?php endif ?><a class="button quiet" href="/?view=history&amp;staff=<?= e($row['id']) ?>" aria-label="History for <?= e($row['name']) ?>">History</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $archived ? 'restore' : 'archive' ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="version" value="<?= e($row['version']) ?>"><button class="quiet" aria-label="<?= $archived ? 'Restore' : 'Archive' ?> <?= e($row['name']) ?>"><?= $archived ? 'Restore' : 'Archive' ?></button></form></div></article><?php endforeach ?>
<?php if (!$rows): ?><div class="empty"><h3><?= $q || $status ? 'No matches this time.' : 'People make the difference.' ?></h3><p><?= $q || $status ? 'Try another search or clear your filters.' : 'Add your first team member to start your directory.' ?></p></div><?php endif ?></div></section></div>
<?php page_end(); ?>
