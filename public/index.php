<?php
declare(strict_types=1);
if (PHP_SAPI === 'cli-server') {
    $asset = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($asset === '/style.css' || (is_string($asset) && preg_match('~^/fonts/[a-z]+\.woff2$~', $asset) && is_file(__DIR__ . $asset))) return false;
}
require dirname(__DIR__) . '/src/support.php';
require dirname(__DIR__) . '/src/staff.php';
boot('People & Co.', 'A little clarity. A stronger team.');
initialize_staff();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'save') { save_staff($_POST); notice('Team member saved.'); }
        elseif (($_POST['action'] ?? '') === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !$version || !run('DELETE FROM staff WHERE id=? AND version=?', [$id, $version])->rowCount()) throw new InvalidArgumentException('This record changed. Reload before deleting.');
            notice('Team member removed.');
        } else fail(400, 'Unknown action.');
    } catch (InvalidArgumentException $error) { notice($error->getMessage()); }
    go();
}
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="team-directory.csv"');
    $file = fopen('php://output', 'w');
    fputcsv($file, ['ID','Name','Email','Role','Department','Status'], ',', '"', '');
    foreach (run('SELECT id,name,email,role,department,status FROM staff ORDER BY name')->fetchAll() as $row)
        fputcsv($file, array_map(fn($v) => csv_safe((string)$v), array_values($row)), ',', '"', '');
    fclose($file); exit;
}
$q = input_text($_GET, 'q', 160); $status = input_text($_GET, 'status', 30);
$rows = run("SELECT * FROM staff WHERE instr(lower(name || ' ' || email || ' ' || role || ' ' || department),lower(?))>0 AND (?='' OR status=?) ORDER BY name COLLATE NOCASE", [$q,$status,$status])->fetchAll();
$editing = isset($_GET['edit']) ? run('SELECT * FROM staff WHERE id=?', [(int)$_GET['edit']])->fetch() : false;
$totals = run("SELECT COUNT(*) total, COALESCE(SUM(status='Active'),0) active, COUNT(DISTINCT department) departments FROM staff")->fetch();
page_start('People & Co.', 'Team directory');
?>
<section class="hero"><p class="eyebrow">PEOPLE OPERATIONS / YOUR TEAM, TOGETHER</p><h1>Great work starts<br>with your people.</h1><p>A calm, considered space for the people behind the work. Keep your team directory clear, current and connected.</p><div class="stats"><p><strong><?= e($totals['total']) ?></strong>Team members</p><p><strong><?= e($totals['active']) ?></strong>Active right now</p><p><strong><?= e($totals['departments']) ?></strong>Departments</p></div></section>
<div class="workspace"><form class="panel" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>"><input type="hidden" name="version" value="<?= e($editing['version'] ?? 0) ?>"><p class="eyebrow">THE PEOPLE DESK</p><h2><?= $editing ? 'Edit team member.' : 'Make an introduction.' ?></h2>
<?php field('name','Full name','text',$editing['name'] ?? ''); field('email','Work email','email',$editing['email'] ?? ''); field('role','Job title','text',$editing['role'] ?? ''); choice('department','Department',['Engineering','Design','Operations','People','Sales'],$editing['department'] ?? ''); choice('status','Status',['Active','Away','Inactive'],$editing['status'] ?? ''); ?>
<button><?= $editing ? 'Save changes' : 'Add team member' ?> &rarr;</button><?php if ($editing): ?><p><a href="/">Cancel editing</a></p><?php endif ?><p class="hint">Your directory is private and stored locally. Export a copy whenever you need it.</p></form>
<section><div class="toolbar"><h2>Meet the team.</h2><a class="button quiet" href="/?export=1">Export CSV &nearr;</a></div><form class="toolbar" method="get"><label class="sr-only" for="q">Search team</label><input id="q" name="q" placeholder="Find a name, role or department..." value="<?= e($q) ?>"><label class="sr-only" for="filter-status">Filter status</label><select id="filter-status" name="status"><option value="">All statuses</option><?php foreach (['Active','Away','Inactive'] as $value): ?><option <?= $status === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach ?></select><button class="quiet">Search</button></form>
<div class="list"><?php foreach ($rows as $row): ?><article class="record"><div class="identity"><span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($row['name'],0,1))) ?></span><div><h3><?= e($row['name']) ?></h3><p><?= e($row['role']) ?> / <?= e($row['department']) ?></p><p><?= e($row['email']) ?></p></div></div><span class="badge"><?= e($row['status']) ?></span><div class="actions"><a class="button quiet" href="/?edit=<?= e($row['id']) ?>" aria-label="Edit <?= e($row['name']) ?>">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="version" value="<?= e($row['version']) ?>"><button class="quiet danger" aria-label="Remove <?= e($row['name']) ?>">Remove</button></form></div></article><?php endforeach ?>
<?php if (!$rows): ?><div class="empty"><h3><?= $q || $status ? 'No matches this time.' : 'People make the difference.' ?></h3><p><?= $q || $status ? 'Try another search or clear your filters.' : 'Add your first team member to start your directory.' ?></p></div><?php endif ?></div></section></div>
<?php page_end(); ?>
