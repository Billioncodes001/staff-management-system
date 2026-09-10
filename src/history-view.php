<?php
$staffId = max(0, (int)($_GET['staff'] ?? 0));
$beforeId = max(0, (int)($_GET['before'] ?? 0));
$events = run('SELECT * FROM audit WHERE (CAST(? AS INTEGER)=0 OR staff_id=?) AND (CAST(? AS INTEGER)=0 OR id<?) ORDER BY id DESC LIMIT 31', [$staffId, $staffId, $beforeId, $beforeId])->fetchAll();
$more = count($events) > 30; $events = array_slice($events, 0, 30);
?>
<section class="hero"><p class="eyebrow">PEOPLE OPERATIONS / A CLEARER RECORD</p><h1>Every change.<br>A little more context.</h1><p>Before-and-after history for additions, edits, status changes, archiving and restoration. Times are UTC. Legacy records begin with a baseline, not invented history.</p></section>
<nav class="tabs" aria-label="Workspace"><a href="/">Directory</a><a href="/?view=intake">CSV intake</a><a class="active" aria-current="page" href="/?view=history">Lifecycle history</a><a href="/?view=archive">Archive</a></nav>
<div class="toolbar"><h2><?= $staffId ? 'Record #' . e($staffId) . ' / history' : 'The change journal.' ?></h2><?php if ($staffId): ?><a href="/?view=history">All records</a><?php endif ?></div><p class="hint">Management labels identify a sign-in session, not an individual employee. History and snapshots remain private to this shared management workspace.</p>
<section class="history-list" aria-label="Audit events">
<?php foreach ($events as $event): $old = json_decode($event['before_json'] ?? 'null', true); $new = json_decode($event['after_json'] ?? 'null', true); ?>
<article class="panel audit-event"><div class="toolbar"><div><p class="eyebrow">EVENT #<?= e($event['id']) ?> / <?= e($event['created']) ?> UTC</p><h3><?= e($new['name'] ?? $old['name'] ?? 'Workspace') ?> <span class="secondary">/ <?= e($event['action']) ?></span></h3></div><span class="badge"><?= e($event['actor']) ?></span></div>
<?php if ($event['batch']): ?><p class="hint">Intake batch <?= e($event['batch']) ?></p><?php endif ?>
<details><summary>Inspect before and after</summary><div class="table-scroll" role="region" aria-label="Event <?= e($event['id']) ?> changes" tabindex="0"><table><thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead><tbody><?php foreach ([...STAFF_FIELDS, 'archived', 'version'] as $key): if (($old[$key] ?? null) === ($new[$key] ?? null)) continue; ?><tr><th scope="row"><?= e(ucfirst($key)) ?></th><td><?= e($old[$key] ?? 'Not recorded') ?></td><td><?= e($new[$key] ?? 'Not recorded') ?></td></tr><?php endforeach ?></tbody></table></div></details></article>
<?php endforeach ?>
<?php if (!$events): ?><div class="empty"><h3>A clean page.</h3><p>Saved changes will appear here.</p></div><?php endif ?>
</section><?php if ($more): ?><p><a class="button quiet" href="/?view=history&amp;staff=<?= $staffId ?>&amp;before=<?= e(end($events)['id']) ?>">Older events</a></p><?php endif ?>
