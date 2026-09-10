import { defineConfig, devices } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve('.test-data');
mkdirSync(root, { recursive: true, mode: 0o700 });
process.env.PEOPLE_E2E_RUN ??= `${process.pid}-${Date.now()}`;
const previews = [
  { name: 'desktop', port: 5305 },
  { name: 'mobile', port: 5306 },
].map(p => ({ ...p, database: resolve(root, `browser-${process.env.PEOPLE_E2E_RUN}-${p.name}.sqlite`) }));

export default defineConfig({
  testDir: './e2e', workers: 1, timeout: 60000, reporter: 'list',
  use: { channel: process.env.PLAYWRIGHT_CHANNEL || undefined, trace: 'retain-on-failure' },
  webServer: previews.map(p => ({
    command: `php -S 127.0.0.1:${p.port} -t public public/index.php`,
    env: { DATABASE: p.database, APP_PASSWORD: 'synthetic-people-test-password-only' },
    url: `http://127.0.0.1:${p.port}`, timeout: 30000, reuseExistingServer: false,
    stderr: 'ignore',
  })),
  projects: previews.map(p => ({
    name: p.name, metadata: { database: p.database },
    use: {
      ...(p.name === 'desktop' ? { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1100 } } : { ...devices['iPhone 13'], defaultBrowserType: 'chromium' as const }),
      baseURL: `http://127.0.0.1:${p.port}`,
    },
  })),
});
