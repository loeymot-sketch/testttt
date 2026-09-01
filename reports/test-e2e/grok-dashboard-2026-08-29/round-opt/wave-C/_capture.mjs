/**
 * Wave C (round-opt) — isolated Playwright CLI capture.
 * Login admin, /admin/items/studio, hard reload. Read-only. No items created. No kiosk.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const require = createRequire('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-opt/wave-C');
const EMAIL = 'admin@lecayenne.fr';
const PASS = '123456';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function phpExecute(code) {
  const r = spawnSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: REPO,
    encoding: 'utf8',
    timeout: 20_000,
  });
  return { status: r.status, stdout: (r.stdout || '').trim(), stderr: (r.stderr || '').trim() };
}

function clearRateLimits() {
  const r = phpExecute(`
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    $ids = \\App\\Models\\User::whereIn('email', ['admin@lecayenne.fr'])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, [
      '127.0.0.1','::1','localhost',
      'admin@lecayenne.fr|127.0.0.1','admin@lecayenne.fr|::1',
    ]));
    foreach (['api','login-lockout'] as $name) {
      foreach ($keys as $key) { $limiter->clear(md5($name.$key)); }
    }
    echo 'ok';
  `);
  if (r.status !== 0) console.warn('rate-limit clear failed', r.stderr || r.stdout);
}

function dbCategories() {
  const r = phpExecute(`
    $q = \\App\\Models\\ItemCategory::query();
    if (in_array('deleted_at', (new \\App\\Models\\ItemCategory())->getFillable(), true) || method_exists(\\App\\Models\\ItemCategory::class, 'bootSoftDeletes')) {
      // keep default
    }
    $names = $q->orderBy('id')->pluck('name')->all();
    echo json_encode($names, JSON_UNESCAPED_UNICODE);
  `);
  try {
    const line = (r.stdout || '').split('\n').filter(Boolean).pop();
    return JSON.parse(line);
  } catch {
    return { error: r.stderr || r.stdout };
  }
}

async function extractCategories(page) {
  return page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.catalog-studio__category-row'));
    const vw = { w: window.innerWidth, h: window.innerHeight };
    const names = [];
    const inViewport = [];
    for (const row of rows) {
      const strong = row.querySelector('button.catalog-studio__category strong');
      const name = ((strong && strong.textContent) || '').replace(/\s+/g, ' ').trim();
      if (!name) continue;
      names.push(name);
      const r = row.getBoundingClientRect();
      const visibleCss = !!(row.offsetParent || row.getClientRects().length);
      const intersects = r.bottom > 0 && r.right > 0 && r.top < vw.h && r.left < vw.w && r.width > 0 && r.height > 0;
      if (visibleCss && intersects) inViewport.push(name);
    }
    return { names, inViewport, rowCount: rows.length, viewport: vw };
  });
}

(async () => {
  mkdirp(OUT);
  clearRateLimits();
  const dbCats = dbCategories();

  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  page.on('dialog', (d) => d.dismiss());

  const consoleBuf = [];
  const networkBuf = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      consoleBuf.push({ type: msg.type(), text: msg.text(), loc: msg.location(), ts: Date.now() });
    }
  });
  page.on('pageerror', (err) => consoleBuf.push({ type: 'pageerror', text: String(err), ts: Date.now() }));
  page.on('requestfailed', (req) => {
    networkBuf.push({
      kind: 'failed',
      url: req.url(),
      method: req.method(),
      failure: req.failure()?.errorText,
      ts: Date.now(),
    });
  });
  page.on('response', (res) => {
    if (res.status() >= 400) {
      networkBuf.push({
        kind: 'http',
        url: res.url(),
        method: res.request().method(),
        status: res.status(),
        ts: Date.now(),
      });
    }
  });

  // Login
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASS);
  const loginResponse = page.waitForResponse(
    (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
    { timeout: 25_000 },
  );
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  const resp = await loginResponse;
  if (resp.status() !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Login API failed: HTTP ${resp.status()} ${body.slice(0, 400)}`);
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1200);
  if (/\/admin\/pos(\/|$|\?)/.test(page.url())) {
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }

  // Studio
  await page.goto(`${BASE}/admin/items/studio`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  });

  // Hard reload (cache disabled via CDP)
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  });
  await page.locator('article.catalog-studio__product').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(600);

  // Act like a user: scroll the category sidebar, then restore top (do not click junk).
  const sidebar = page.locator('.catalog-studio__sidebar');
  if ((await sidebar.count()) > 0) {
    await sidebar.evaluate((el) => {
      el.scrollTop = Math.min(el.scrollHeight, 400);
    });
    await page.waitForTimeout(250);
    await sidebar.evaluate((el) => {
      el.scrollTop = 0;
    });
  }
  await page.evaluate(() => {
    const header = document.querySelector('.catalog-studio__header');
    if (header) header.scrollIntoView({ block: 'start', inline: 'nearest' });
    const root = document.scrollingElement || document.documentElement;
    if (root) root.scrollTop = 0;
  });
  await page.waitForTimeout(300);

  const cats = await extractCategories(page);
  const names = cats.names || [];
  const flags = {
    sandwichs: names.includes('Sandwichs'),
    tacos: names.includes('Tacos'),
    auditKioskMulti: names.some((n) => /AUDIT-KIOSK-MULTI/i.test(n)),
    e2eCat: names.some((n) => /E2E Cat/i.test(n)),
    e2eCategory: names.some((n) => /E2ECategory/i.test(n)),
    auditKioskMultiMatches: names.filter((n) => /AUDIT-KIOSK-MULTI/i.test(n)),
    e2eCatMatches: names.filter((n) => /E2E Cat/i.test(n)),
    e2eCategoryMatches: names.filter((n) => /E2ECategory/i.test(n)),
  };

  // Keep Sandwichs/Tacos if present: select Sandwichs (real), never junk, never create.
  let clicked = null;
  if (flags.sandwichs) {
    const row = page.locator('.catalog-studio__category-row').filter({
      has: page.locator('button.catalog-studio__category strong', { hasText: /^Sandwichs$/ }),
    });
    if ((await row.count()) > 0) {
      await row.evaluate((el) => {
        const btn = el.querySelector('button.catalog-studio__category');
        if (btn) btn.click();
      });
      await page.getByTestId('catalog-studio-category-wizard-entry').waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
      clicked = 'Sandwichs';
      await page.waitForTimeout(400);
      await page.evaluate(() => {
        const header = document.querySelector('.catalog-studio__header');
        if (header) header.scrollIntoView({ block: 'start', inline: 'nearest' });
        const root = document.scrollingElement || document.documentElement;
        if (root) root.scrollTop = 0;
      });
    }
  } else if (flags.tacos) {
    const row = page.locator('.catalog-studio__category-row').filter({
      has: page.locator('button.catalog-studio__category strong', { hasText: /^Tacos$/ }),
    });
    if ((await row.count()) > 0) {
      await row.evaluate((el) => {
        const btn = el.querySelector('button.catalog-studio__category');
        if (btn) btn.click();
      });
      clicked = 'Tacos';
      await page.waitForTimeout(400);
    }
  }

  const png = path.join(OUT, '01-studio.png');
  const domPath = path.join(OUT, '01-studio.dom.html');
  const consolePath = path.join(OUT, '01-studio.console.json');
  const networkPath = path.join(OUT, '01-studio.network.json');
  const notesPath = path.join(OUT, '01-studio.notes.json');

  await page.screenshot({ path: png, fullPage: true, animations: 'disabled' });
  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');

  const notes = {
    wave: 'C',
    base_url: BASE,
    name: 'idle-studio-hard-reload',
    url: page.url(),
    title: await page.title().catch(() => ''),
    clicked,
    png,
    dom: domPath,
    console: consolePath,
    network: networkPath,
    category_count: names.length,
    category_names: names,
    category_names_first20: names.slice(0, 20),
    in_viewport_count: (cats.inViewport || []).length,
    in_viewport_names: cats.inViewport || [],
    sandwichs_present: flags.sandwichs,
    tacos_present: flags.tacos,
    AUDIT_KIOSK_MULTI_present: flags.auditKioskMulti,
    E2E_Cat_present: flags.e2eCat,
    E2ECategory_present: flags.e2eCategory,
    junk_matches: {
      'AUDIT-KIOSK-MULTI': flags.auditKioskMultiMatches,
      'E2E Cat': flags.e2eCatMatches,
      E2ECategory: flags.e2eCategoryMatches,
    },
    db_categories_count: Array.isArray(dbCats) ? dbCats.length : null,
    db_categories_first20: Array.isArray(dbCats) ? dbCats.slice(0, 20) : dbCats,
    console_error_count: consoleBuf.filter((c) => c.type === 'error' || c.type === 'pageerror').length,
    console_warning_count: consoleBuf.filter((c) => c.type === 'warning').length,
    network_interesting_count: networkBuf.length,
    notes: 'Logged in as admin, opened /admin/items/studio, hard-reloaded with cache disabled, scrolled category sidebar then restored top, clicked Sandwichs if present. No items created. No kiosk.',
  };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  fs.writeFileSync(path.join(OUT, 'notes.json'), JSON.stringify(notes, null, 2), 'utf8');

  console.log(JSON.stringify({
    png,
    category_count: names.length,
    category_names_first20: names.slice(0, 20),
    sandwichs: flags.sandwichs,
    tacos: flags.tacos,
    AUDIT_KIOSK_MULTI: flags.auditKioskMulti,
    E2E_Cat: flags.e2eCat,
    E2ECategory: flags.e2eCategory,
    url: page.url(),
  }, null, 2));

  await browser.close();
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
