# People & Co. Batch Handoff

One-time implementation and review record. Only `staff-management-system` was edited by its implementation worker. The initial worktree was clean; normal databases and previews were not opened or modified. Publication is coordinated after review; no deployment or scheduling change is part of this release.

## Delivered

One coherent intake-to-recovery workflow: strict CSV validation with row errors, exact server-side preview, session-bound 15-minute review, generation checks, atomic confirmation and durable retries; transactional before/after lifecycle history; reversible archive/restore; private operator backup/verify/restore with a tested pre-restore recovery copy and session invalidation. Old staff records migrate without losing IDs, versions, dates or fields. The established PHP/SQLite stack and green/DM visual identity remain.

Emergency/contact-access grants were deliberately not implemented. No claims are made for individual identities, encrypted storage, public hosting or real-user validation.

## Exact Verification

Run from `/Users/billioncodestv/Documents/projects/showcase-rebuilds/staff-management-system`:

| Command | Actual local result |
| --- | --- |
| `php tests.php` | 13 core workflow cases passed |
| `find src public bin -name '*.php' -exec php -l {} \;` | All 8 source files passed |
| `PLAYWRIGHT_CHANNEL=chrome npm run test:e2e` | 16 passed, 14.2 seconds; desktop 1440x1100 and emulated iPhone 13 |
| `npm audit --json` | 0 vulnerabilities across all dependencies |
| `git diff --check` | Passed |

Browser tests use isolated synthetic databases and only ports 5305/5306. They include automated WCAG A/AA checks, viewport overflow and actual keyboard horizontal scrolling of the mobile preview. Core cases include injected mid-import and audit failures, exact expiry, two concurrent confirmation processes, legacy migration, real CLI backups, restore refusal while an app connection holds its lock, restore rollback/recovery copy, corrupted input and mismatched schema rejection. Browser cases cover existing login/create/edit/search/status/export routes, legacy delete-to-archive compatibility, lifecycle history, restore invalidation, CSRF/Host/Origin, session expiry and login throttling.

Actual screenshots, visually inspected (all data synthetic):

- `docs/intake-desktop.png` and `docs/intake-mobile.png`
- `docs/directory-desktop.png` and `docs/directory-mobile.png`
- `docs/history-desktop.png` and `docs/history-mobile.png`

## Changed Paths

- `src/support.php`, `src/staff.php`: session/host protection, migration, transactions and lifecycle audit.
- `src/intake.php`, `src/intake-view.php`, `src/history-view.php`: validated CSV workflow and private review/history screens.
- `src/recovery.php`, `bin/workspace.php`: operator snapshot, validation and recoverable restore.
- `public/index.php`, `public/style.css`: retained directory plus intake/history/archive navigation, responsive views and safe legacy action compatibility.
- `tests.php`, `e2e/workflows.spec.ts`, `playwright.config.ts`: isolated core and browser regression suites.
- `package.json`, `package-lock.json`, `.github/workflows/verify.yml`, `.gitignore`: test-only dependencies and CI configuration.
- `README.md`, `docs/REVIEW.md`, the six PNGs above: setup, limits and actual evidence. Existing `docs/preview.webp` is unchanged.

## Parent QA Notes

Existing `/`, `?edit=ID`, `?export=1`, search/status controls and `APP_PASSWORD` (12+ characters) remain. Old pre-upgrade sessions must sign in again. UI **Remove** is now **Archive**, with a working Restore in the archive. Old POST `action=delete` also archives; it never physically deletes staff. Archived emails stay reserved. CSV export still has the original six columns and formula protection; the five-column intake has its own template.

Use an isolated `DATABASE` and a synthetic `APP_PASSWORD` for shared QA. The test runner refuses occupied ports rather than attaching to another server. Do not reuse normal staff data for screenshots.

The operator restore CLI requires a healthy readable current version-1 workspace, a trusted same-schema version-1 backup no larger than 256 MiB, DELETE journal mode and no sidecars. Stop all writers; its lock coordinates only this app/CLI. A pre-restore copy is retained and reported so logical rollback is itself reversible. Automatic recovery of an already missing/corrupt live file, scheduled/offsite backups and retention/purge are outside this release. Backups and history contain personal data and are not encrypted. Audit actor labels are shared management-session references, not individual accountability.

## Suggested Portfolio Description

People & Co. is a private PHP/SQLite staff desk with reviewed, atomic CSV intake, lifecycle history, reversible archiving and verified operator backup/restore. A tested local single-operator pilot, not a public HR platform.
