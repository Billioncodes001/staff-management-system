<?php
$preview = false; $previewRows = [];
if (isset($_SESSION['intake_token'])) {
    try {
        $preview = intake_receipt($_SESSION['intake_token'], $owner);
        if ($preview['confirmed']) $preview = false;
        else $previewRows = json_decode($preview['rows_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException) { unset($_SESSION['intake_token']); }
}
?>
<section class="hero"><p class="eyebrow">THE PEOPLE DESK / REVIEW BEFORE COMMITTING</p><h1>A thoughtful welcome.<br>Not a blind import.</h1><p>Validate your spreadsheet, review every person, then add the whole batch in one step. Existing records are never overwritten.</p><div class="stats"><p><strong>01</strong>Validate</p><p><strong>02</strong>Review</p><p><strong>03</strong>Confirm</p></div></section>
<nav class="tabs" aria-label="Workspace"><a href="/">Directory</a><a class="active" aria-current="page" href="/?view=intake">CSV intake</a><a href="/?view=history">Lifecycle history</a><a href="/?view=archive">Archive</a></nav>
<?php if ($preview): ?>
<section class="panel intake-review"><div class="toolbar"><div><p class="eyebrow">READY FOR YOUR REVIEW</p><h2><?= count($previewRows) ?> people. One atomic import.</h2></div><span class="badge">Nothing saved to the directory yet</span></div>
<p class="hint">Review expires <?= e(gmdate('H:i:s', (int)$preview['expires'])) ?> UTC. Any staff change requires a new preview. Names and values below are the exact trimmed values to be saved.</p>
<p class="hint">On a narrow screen, scroll the table sideways to inspect every field before confirming.</p>
<div class="table-scroll" role="region" aria-label="Validated staff preview" tabindex="0"><table><thead><tr><th scope="col">Record</th><?php foreach (STAFF_FIELDS as $key): ?><th scope="col"><?= e(ucfirst($key)) ?></th><?php endforeach ?></tr></thead><tbody><?php foreach ($previewRows as $index => $row): ?><tr><th scope="row"><?= $index + 2 ?></th><?php foreach ($row as $value): ?><td><?= e($value) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table></div>
<div class="intake-confirm"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= e($_SESSION['intake_token']) ?>"><button>Confirm <?= count($previewRows) ?> team members</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_intake"><button class="quiet">Discard preview</button></form></div></section>
<?php else: ?>
<div class="workspace intake-workspace"><aside class="panel"><p class="eyebrow">INTAKE CHECKLIST</p><h2>A small, safe batch.</h2><p>Up to 200 people and 128 KiB. UTF-8 CSV only; quoted commas and escaped quotes are supported.</p><p>Use single-line values, each up to 160 bytes. Emails must be unique, including archived people.</p><p class="hint">Departments: Engineering, Design, Operations, People, Sales.<br>Statuses: Active, Away, Inactive.</p><a class="button quiet" href="/?template=1">Download synthetic template</a><p class="hint">Do not upload emergency contacts or sensitive notes. This schema only accepts the five directory fields.</p></aside>
<form class="panel" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="preview"><p class="eyebrow">01 / START WITH YOUR SOURCE</p><h2>Bring the team together.</h2>
<?php if (!empty($_SESSION['intake_errors'])): ?><div class="error-summary" role="alert"><h3>Fix these records first</h3><ul><?php foreach ($_SESSION['intake_errors'] as $error): ?><li>Record <?= e($error['record']) ?>: <?= e($error['message']) ?></li><?php endforeach ?></ul><p>No records were imported.</p></div><?php endif ?>
<label for="csv_file">Upload CSV</label><input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv"><label for="csv">Or paste CSV text</label><textarea id="csv" name="csv" maxlength="131072" rows="10" spellcheck="false" placeholder="name,email,role,department,status"><?= e($_SESSION['intake_draft'] ?? '') ?></textarea><p class="hint">Required header, in this order: <code>name,email,role,department,status</code>. Record numbers include the header as record 1.</p><button>Validate and preview</button></form></div>
<?php endif ?>
