/**
 * Wave C (round-improve-1) — isolated Playwright CLI capture.
 * Login admin, /admin/items/studio, hard reload of Mix admin-shell.7f763657.js.
 * Stay on « Toutes les catégories ». Read-only. No create. No kiosk.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);
const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-2026-08-29/round-improve-1/wave-C');
const EMAIL = 'admin@lecayenne.fr';
const PASS = '123456';
const EXPECTED_SHELL = 'admin-shell.7f763657.js';

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

function isNetworkNoise(url) {
  return /\/_debugbar|\/clockwork|pusher|websocket|sockjs|hot-update|__vite/i.test(url || '');
}

async function extractStudio(page) {
  return page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.catalog-studio__category-row'));
    const names = [];
    for (const row of rows) {
      const strong = row.querySelector('button.catalog-studio__category strong');
      const name = ((strong && strong.textContent) || '').replace(/\s+/g, ' ').trim();
      if (name) names.push(name);
    }

    const allCatBtn = Array.from(document.querySelectorAll('button.catalog-studio__category')).find((b) => {
      const label = ((b.querySelector('strong') && b.querySelector('strong').textContent) || b.textContent || '');
      return /Toutes les cat[eé]gories/i.test(label);
    }) || null;
    const allCatStrong = allCatBtn
      ? (((allCatBtn.querySelector('strong') && allCatBtn.querySelector('strong').textContent) || '').replace(/\s+/g, ' ').trim())
      : null;
    const allCatSmall = allCatBtn
      ? (((allCatBtn.querySelector('small') && allCatBtn.querySelector('small').textContent) || '').replace(/\s+/g, ' ').trim())
      : null;
    const allCatText = allCatBtn ? String(allCatBtn.innerText || '').replace(/\s+/g, ' ').trim() : null;
    const allCatActive = allCatBtn ? allCatBtn.classList.contains('catalog-studio__category--active') : false;
    const countMatch = (allCatSmall || allCatText || '').match(/(\d+)/);
    const allCatArticleCount = countMatch ? Number(countMatch[1]) : null;

    const products = Array.from(document.querySelectorAll('article.catalog-studio__product')).map((el) => {
      const h4 = el.querySelector('h4');
      const p = el.querySelector('p');
      return {
        name: ((h4 && h4.textContent) || '').replace(/\s+/g, ' ').trim(),
        category: ((p && p.textContent) || '').replace(/\s+/g, ' ').trim(),
      };
    });
    const e2eProducts = products.filter((p) => /E2E_PLAYWRIGHT/i.test(`${p.name} ${p.category}`));
    const gridEl = document.querySelector('[data-testid="catalog-studio-products-grid"]');
    const gridText = gridEl ? String(gridEl.innerText || '') : '';
    const bodyText = String((document.body && document.body.innerText) || '');

    const breadcrumbItems = Array.from(document.querySelectorAll('.db-breadcrumb-list li, .db-breadcrumb li, nav[aria-label*="breadcrumb" i] li'))
      .map((li) => String(li.innerText || '').replace(/\s+/g, ' ').trim())
      .filter(Boolean);
    const breadcrumbLinks = Array.from(document.querySelectorAll('.db-breadcrumb-link, .db-breadcrumb a'))
      .map((a) => String(a.textContent || '').replace(/\s+/g, ' ').trim())
      .filter(Boolean);

    const scripts = Array.from(document.querySelectorAll('script[src]')).map((s) => s.getAttribute('src') || '');
    const adminShell = scripts.find((s) => /admin-shell/i.test(s)) || null;
    const counterEl = document.querySelector('.catalog-studio__counter');

    return {
      category_count: names.length,
      category_names: names,
      sidebar_counter: counterEl ? String(counterEl.textContent || '').trim() : null,
      all_categories_label: allCatStrong,
      all_categories_small: allCatSmall,
      all_categories_text: allCatText,
      all_categories_active: allCatActive,
      all_categories_article_count: allCatArticleCount,
      product_count_grid: products.length,
      product_names: products.map((p) => p.name),
      e2e_playwright_in_grid: e2eProducts.length > 0 || /E2E_PLAYWRIGHT/i.test(gridText),
      e2e_playwright_in_body: /E2E_PLAYWRIGHT/i.test(bodyText),
      e2e_products: e2eProducts,
      breadcrumb_items: breadcrumbItems,
      breadcrumb_links: breadcrumbLinks,
      breadcrumb_joined: breadcrumbItems.join(' / '),
      breadcrumb_first: breadcrumbItems[0] || breadcrumbLinks[0] || null,
      admin_shell_src: adminShell,
      scripts,
    };
  });
}

(async () => {
  mkdirp(OUT);
  clearRateLimits();

  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  page.on('dialog', (d) => d.dismiss());

  const consoleBuf = [];
  const networkBuf = [];
  const shellHits = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      consoleBuf.push({ type: msg.type(), text: msg.text(), loc: msg.location(), ts: Date.now() });
    }
  });
  page.on('pageerror', (err) => consoleBuf.push({ type: 'pageerror', text: String(err), ts: Date.now() }));
  page.on('requestfailed', (req) => {
    const url = req.url();
    if (isNetworkNoise(url)) return;
    networkBuf.push({
      kind: 'failed',
      url,
      method: req.method(),
      failure: req.failure()?.errorText,
      ts: Date.now(),
    });
  });
  page.on('response', (res) => {
    const url = res.url();
    if (/admin-shell/i.test(url)) {
      shellHits.push({ url, status: res.status(), fromCache: res.fromServiceWorker(), ts: Date.now() });
    }
    if (res.status() < 400) return;
    if (isNetworkNoise(url)) return;
    networkBuf.push({
      kind: 'http',
      url,
      method: res.request().method(),
      status: res.status(),
      ts: Date.now(),
    });
  });

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
  await page.waitForTimeout(800);
  if (/\/admin\/pos(\/|$|\?)/.test(page.url())) {
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }

  await page.goto(`${BASE}/admin/items/studio`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  });
  await page.locator('article.catalog-studio__product').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});

  // HARD RELOAD — pick up Mix admin-shell.7f763657.js, cache disabled via CDP.
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  });
  await page.locator('article.catalog-studio__product').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(700);

  const allCat = page.locator('button.catalog-studio__category').filter({
    has: page.locator('strong', { hasText: /Toutes les cat[eé]gories/i }),
  }).first();
  if ((await allCat.count()) > 0) {
    await allCat.click();
    await page.waitForTimeout(400);
  }

  await page.evaluate(() => {
    const header = document.querySelector('.catalog-studio__header');
    if (header) header.scrollIntoView({ block: 'start', inline: 'nearest' });
    const root = document.scrollingElement || document.documentElement;
    if (root) root.scrollTop = 0;
    const sidebar = document.querySelector('.catalog-studio__sidebar');
    if (sidebar) sidebar.scrollTop = 0;
  });
  await page.waitForTimeout(250);

  const extract = await extractStudio(page);

  const png = path.join(OUT, '01-studio.png');
  const domPath = path.join(OUT, '01-studio.dom.html');
  const consolePath = path.join(OUT, '01-studio.console.json');
  const networkPath = path.join(OUT, '01-studio.network.json');
  const notesPath = path.join(OUT, '01-studio.notes.json');

  await page.screenshot({ path: png, fullPage: true, animations: 'disabled' });
  fs.writeFileSync(domPath, await page.content(), 'utf8');
  fs.writeFileSync(consolePath, JSON.stringify(consoleBuf, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(networkBuf, null, 2), 'utf8');

  const breadcrumbString = extract.breadcrumb_joined || extract.breadcrumb_first || '';
  const tableauDeBordExact = (extract.breadcrumb_items || []).some((t) => t === 'Tableau de bord')
    || (extract.breadcrumb_links || []).some((t) => t === 'Tableau de bord');
  const tableauDeBordTitle = (extract.breadcrumb_items || []).some((t) => t === 'Tableau De Bord')
    || (extract.breadcrumb_links || []).some((t) => t === 'Tableau De Bord');

  const notes = {
    wave: 'C',
    round: 'round-improve-1',
    base_url: BASE,
    name: 'idle-studio-hard-reload-toutes-categories',
    url: page.url(),
    title: await page.title().catch(() => ''),
    png,
    dom: domPath,
    console: consolePath,
    network: networkPath,
    expected_shell: EXPECTED_SHELL,
    admin_shell_src: extract.admin_shell_src,
    admin_shell_loaded_expected: Boolean(extract.admin_shell_src && extract.admin_shell_src.includes(EXPECTED_SHELL)),
    shell_network_hits: shellHits,
    category_count: extract.category_count,
    sidebar_counter: extract.sidebar_counter,
    category_names: extract.category_names,
    all_categories_label: extract.all_categories_label,
    all_categories_small: extract.all_categories_small,
    all_categories_text: extract.all_categories_text,
    all_categories_active: extract.all_categories_active,
    all_categories_article_count: extract.all_categories_article_count,
    product_count_grid: extract.product_count_grid,
    product_names: extract.product_names,
    e2e_playwright_in_grid: extract.e2e_playwright_in_grid,
    e2e_playwright_in_body: extract.e2e_playwright_in_body,
    e2e_products: extract.e2e_products,
    breadcrumb_string: breadcrumbString,
    breadcrumb_items: extract.breadcrumb_items,
    breadcrumb_links: extract.breadcrumb_links,
    breadcrumb_first: extract.breadcrumb_first,
    breadcrumb_tableau_de_bord: tableauDeBordExact,
    breadcrumb_tableau_de_bord_title_case: tableauDeBordTitle,
    console_error_count: consoleBuf.filter((c) => c.type === 'error' || c.type === 'pageerror').length,
    console_warning_count: consoleBuf.filter((c) => c.type === 'warning').length,
    network_interesting_count: networkBuf.length,
    notes: 'Logged in as admin, opened /admin/items/studio, hard-reloaded with cache disabled, stayed on Toutes les catégories. No items created. No kiosk.',
  };
  fs.writeFileSync(notesPath, JSON.stringify(notes, null, 2), 'utf8');
  fs.writeFileSync(path.join(OUT, 'notes.json'), JSON.stringify(notes, null, 2), 'utf8');

  console.log(JSON.stringify({
    png,
    url: page.url(),
    category_count: extract.category_count,
    all_categories_article_count: extract.all_categories_article_count,
    all_categories_small: extract.all_categories_small,
    product_count_grid: extract.product_count_grid,
    breadcrumb_string: breadcrumbString,
    breadcrumb_first: extract.breadcrumb_first,
    breadcrumb_items: extract.breadcrumb_items,
    breadcrumb_links: extract.breadcrumb_links,
    e2e_playwright_in_grid: extract.e2e_playwright_in_grid,
    e2e_playwright_in_body: extract.e2e_playwright_in_body,
    e2e_products: extract.e2e_products,
    admin_shell_src: extract.admin_shell_src,
    admin_shell_loaded_expected: notes.admin_shell_loaded_expected,
    category_names: extract.category_names,
    console_error_count: notes.console_error_count,
    network_interesting_count: notes.network_interesting_count,
  }, null, 2));

  await browser.close();
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
