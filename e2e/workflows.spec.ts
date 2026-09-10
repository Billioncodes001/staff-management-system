import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const password = 'synthetic-people-test-password-only';
const header = 'name,email,role,department,status\n';
const unique = () => Math.random().toString(16).slice(2);
async function login(page: Page) {
  await page.goto('/');
  await page.getByLabel('Workspace password').fill(password);
  await page.getByRole('button', { name: 'Open workspace' }).click();
  await expect(page.getByRole('link', { name: 'CSV intake', exact: true })).toBeVisible();
}
async function add(page: Page, name: string, email: string) {
  await page.goto('/'); await page.getByLabel('Full name').fill(name);
  await page.getByLabel('Work email').fill(email); await page.getByLabel('Job title').fill('Coordinator');
  await page.getByLabel('Department', { exact: true }).selectOption('Operations');
  await page.getByRole('button', { name: 'Add team member' }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}
async function csrf(page: Page) { return page.locator('input[name=csrf]').first().inputValue(); }
async function quality(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
}

test('authentication, CSRF, host and origin boundaries', async ({ page }) => {
  for (const url of ['/?view=intake', '/?view=history', '/?export=1', '/?template=1', '/data/app.sqlite']) {
    const response = await page.goto(url); expect(response?.headers()['cache-control']).toContain('no-store');
    await expect(page.getByLabel('Workspace password')).toBeVisible();
  }
  await quality(page);
  const missing = await page.request.post('/', { form: { action: 'save', name: 'Unauthorized' } }); expect(missing.status()).toBe(403);
  const host = await page.request.get('/', { headers: { Host: 'attacker.example' } }); expect(host.status()).toBe(403);
  await login(page);
  const origin = await page.request.post('/', { headers: { Origin: 'https://attacker.example' }, form: { action: 'logout', csrf: await csrf(page) } }); expect(origin.status()).toBe(403);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByLabel('Workspace password')).toBeVisible();
  await page.goto('/?view=history'); await expect(page.getByLabel('Workspace password')).toBeVisible();
});

test('CSV file review, row errors, atomic confirmation and repeat receipt', async ({ page }, info) => {
  await login(page); await page.getByRole('link', { name: 'CSV intake', exact: true }).click();
  await page.getByLabel('Or paste CSV text').fill(header + 'Invalid,bad,R,Design,Active\nShort,row\n');
  await page.getByRole('button', { name: 'Validate and preview' }).click();
  await expect(page.getByRole('alert')).toContainText('Record 2'); await expect(page.getByRole('alert')).toContainText('Record 3');
  await expect(page.getByRole('button', { name: /^Confirm/ })).toHaveCount(0);
  await quality(page);
  await page.getByLabel('Or paste CSV text').fill('');
  const id = unique();
  await page.getByLabel('Upload CSV').setInputFiles({ name: 'synthetic-team.csv', mimeType: 'text/csv', buffer: Buffer.from(header + `Jordan Example,jordan-${id}@example.test,People Partner,People,Active\nMorgan Example,morgan-${id}@example.test,Designer,Design,Away\n\"Avery, Example\",avery-${id}@example.test,Engineer,Engineering,Active\n`) });
  await page.getByRole('button', { name: 'Validate and preview' }).click();
  await expect(page.getByRole('heading', { name: '3 people. One atomic import.' })).toBeVisible();
  const table = page.getByRole('region', { name: 'Validated staff preview' });
  if (info.project.name === 'mobile') {
    await table.focus(); await page.keyboard.press('ArrowRight');
    await expect.poll(() => table.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
    await table.evaluate(el => { el.scrollLeft = 0; });
  }
  await quality(page);
  await page.screenshot({ path: resolve(`docs/intake-${info.project.name}.png`), fullPage: true });
  const token = await page.locator('input[name=token]').inputValue(); const currentCsrf = await csrf(page);
  await page.getByRole('button', { name: 'Confirm 3 team members' }).click();
  await expect(page.getByRole('status')).toContainText('3 team members imported');
  await expect(page.getByRole('heading', { name: 'Jordan Example', exact: true })).toHaveCount(1);
  const retry = await page.request.post('/', { form: { action: 'confirm', token, csrf: currentCsrf } }); expect(await retry.text()).toContain('3 team members imported');
  await page.goto('/'); await quality(page);
  await page.screenshot({ path: resolve(`docs/directory-${info.project.name}.png`), fullPage: true });
});

test('lifecycle edit history archive and restore', async ({ page }, info) => {
  await login(page); const name = `Lifecycle Example ${unique()}`;
  await add(page, name, `life-${unique()}@example.test`);
  await page.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
  await page.getByLabel('Status', { exact: true }).selectOption('Away');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await page.getByRole('link', { name: `History for ${name}`, exact: true }).click();
  await expect(page.getByRole('heading', { name: /status changed/ })).toBeVisible();
  await page.getByText('Inspect before and after', { exact: true }).first().click();
  await expect(page.getByRole('cell', { name: 'Active', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Away', exact: true })).toBeVisible();
  await quality(page); await page.screenshot({ path: resolve(`docs/history-${info.project.name}.png`), fullPage: true });
  await page.goto('/'); await page.getByRole('button', { name: `Archive ${name}`, exact: true }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toHaveCount(0);
  await page.getByRole('link', { name: 'Archive', exact: true }).click(); await quality(page);
  await page.getByRole('button', { name: `Restore ${name}`, exact: true }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
});

test('stale previews and cross-session tokens cannot import', async ({ page, browser }) => {
  await login(page); await page.goto('/?view=intake');
  const csv = header + `Stale Example,stale-${unique()}@example.test,Designer,Design,Active\n`;
  await page.getByLabel('Or paste CSV text').fill(csv); await page.getByRole('button', { name: 'Validate and preview' }).click();
  const token = await page.locator('input[name=token]').inputValue();
  const context = await browser.newContext({ baseURL: new URL(page.url()).origin }); const other = await context.newPage();
  await login(other);
  const denied = await other.request.post('/', { form: { action: 'confirm', csrf: await csrf(other), token } });
  expect(await denied.text()).toContain('unavailable in this session');
  await add(other, 'Concurrent Example', `concurrent-${unique()}@example.test`);
  await page.getByRole('button', { name: 'Confirm 1 team members' }).click();
  await expect(page.getByRole('status')).toContainText('directory changed');
  await page.goto('/'); await expect(page.getByRole('heading', { name: 'Stale Example', exact: true })).toHaveCount(0);
  await context.close();
});

test('stale edit retains a draft without overwriting another revision', async ({ page }) => {
  await login(page); const name = `Conflict Example ${unique()}`;
  await add(page, name, `conflict-${unique()}@example.test`);
  await page.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
  const stale = await page.context().newPage(); await stale.goto(page.url());
  await page.getByLabel('Job title').fill('Saved role'); await page.getByRole('button', { name: 'Save changes' }).click();
  await stale.getByLabel('Job title').fill('Unsaved draft'); await stale.getByRole('button', { name: 'Save changes' }).click();
  await expect(stale.getByRole('status')).toContainText('record changed');
  await expect(stale.getByLabel('Job title')).toHaveValue('Unsaved draft');
  await page.goto('/'); await page.getByRole('link', { name: `Edit ${name}`, exact: true }).click(); await expect(page.getByLabel('Job title')).toHaveValue('Saved role');
  await stale.close();
});

test('operator restore invalidates browser sessions and preserves recoverability', async ({ page }, info) => {
  await login(page);
  const database = String(info.project.metadata.database); const backup = database + '.snapshot.sqlite';
  const env = { ...process.env, DATABASE: database };
  execFileSync('php', ['bin/workspace.php', 'backup', backup], { env });
  const name = `After snapshot ${unique()}`; await add(page, name, `snapshot-${unique()}@example.test`);
  const result = JSON.parse(execFileSync('php', ['bin/workspace.php', 'restore', backup, '--confirm'], { env, encoding: 'utf8' }));
  expect(result.sessions).toBe('invalidated'); expect(result.recovery_copy).toContain('.before-restore-');
  await page.goto('/'); await expect(page.getByLabel('Workspace password')).toBeVisible();
  await login(page); await expect(page.getByRole('heading', { name, exact: true })).toHaveCount(0);
  await page.goto('/?view=history'); await expect(page.getByRole('heading', { name: /backup restored/ })).toBeVisible();
});

test('legacy directory search status export and delete alias remain safe', async ({ page }) => {
  await login(page); const name = `Export Example ${unique()}`;
  await add(page, name, `export-${unique()}@example.test`);
  await page.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
  await page.getByLabel('Job title').fill('=1+1'); await page.getByLabel('Status', { exact: true }).selectOption('Away');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await page.getByLabel('Search team', { exact: true }).fill(name);
  await page.getByLabel('Filter status').selectOption('Away'); await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('article.record')).toHaveCount(1);
  await page.getByLabel('Filter status').selectOption('Active'); await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'No matches this time.' })).toBeVisible();
  const exported = await page.request.get('/?export=1');
  expect(exported.headers()['content-type']).toContain('text/csv');
  expect(exported.headers()['cache-control']).toContain('no-store');
  expect(await exported.text()).toContain('ID,Name,Email,Role,Department,Status');
  expect(await exported.text()).toContain("'=1+1");
  await page.goto('/'); await page.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
  const id = await page.locator('input[name=id]').first().inputValue();
  const version = await page.locator('input[name=version]').first().inputValue();
  const archived = await page.request.post('/', { form: { action: 'delete', id, version, csrf: await csrf(page) } });
  expect(await archived.text()).toContain('Team member archived');
  expect(await (await page.request.get('/?export=1')).text()).not.toContain(name);
  await page.goto('/?view=archive'); await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
});

test('session expiry and login throttling remain enforced', async ({ page }) => {
  await login(page);
  const cookie = (await page.context().cookies()).find(c => c.name.startsWith('workspace_'))!;
  expect(cookie.httpOnly).toBe(true); expect(cookie.sameSite).toBe('Lax');
  execFileSync('php', ['-r', 'session_name($argv[1]); session_id($argv[2]); session_start(); $_SESSION["expires"]=time()-1; session_write_close();', cookie.name, cookie.value]);
  await page.goto('/?view=history'); await expect(page.getByLabel('Workspace password')).toBeVisible();
  const token = await csrf(page);
  for (let i = 0; i < 10; i++) {
    const wrong = await page.request.post('/', { form: { action: 'login', csrf: token, password: 'synthetic-wrong-password' } });
    expect(await wrong.text()).toContain('That password is not correct');
  }
  const blocked = await page.request.post('/', { form: { action: 'login', csrf: token, password } });
  expect(blocked.status()).toBe(429);
});
