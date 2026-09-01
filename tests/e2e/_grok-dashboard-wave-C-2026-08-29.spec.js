/**
 * Grok dashboard WAVE C capture — Catalogue studio (read-only).
 * Does not create / edit / delete items or categories.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

test.describe.configure({ timeout: 180_000, retries: 0 });

const OUT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/grok-dashboard-2026-08-29/round-1/wave-C',
);

const REAL_PRODUCTS = ['Big Burger', 'Tacos XL', 'Galette Classique', 'Bol Frites'];
const PREFERRED_CATEGORIES = ['Tacos', 'Sandwichs'];
const JUNK_CATEGORY_RE =
  /^(E2E\b|E2E_|E2ECategory|AUDIT-|Aliquam|Deleniti|Rerum|Ducimus|Tempore|Vitae|Unde|Ut$|Ipsum|Consequatur|Exercitationem|Qui$|Reiciendis|Minima|Eligendi|Ad$|Quos|Nostrum|Quia|Sed$|Numquam)/i;
const RAW_I18N_RE = /^[a-z]+(\.[a-z0-9_]+){1,5}$/;

function attachRecorder(page) {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  let consoleBuffer = [];
  let networkBuffer = [];

  const onConsole = (msg) => {
    try {
      const text = String(msg.text() || '');
      if (/WebSocket connection to 'ws/i.test(text) || /^Pusher\s*:/i.test(text)) return;
      consoleBuffer.push({
        level: msg.type(),
        text: text.substring(0, 2000),
        location: msg.location(),
        ts: Date.now(),
      });
    } catch (_e) { /* ignore */ }
  };
  const onPageError = (err) => {
    consoleBuffer.push({
      level: 'pageerror',
      text: String(err.message || err).substring(0, 2000),
      stack: String(err.stack || '').substring(0, 4000),
      ts: Date.now(),
    });
  };
  const onResponse = (resp) => {
    try {
      const status = resp.status();
      const req = resp.request();
      if (status >= 400 || status === 0) {
        networkBuffer.push({
          url: resp.url().substring(0, 400),
          method: req.method(),
          status,
          ts: Date.now(),
        });
      }
    } catch (_e) { /* ignore */ }
  };
  const onRequestFailed = (req) => {
    try {
      networkBuffer.push({
        url: req.url().substring(0, 400),
        method: req.method(),
        status: 0,
        failure: req.failure() ? req.failure().errorText : 'requestfailed',
        ts: Date.now(),
      });
    } catch (_e) { /* ignore */ }
  };

  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);
  page.on('requestfailed', onRequestFailed);

  async function restoreStudioTop() {
    await page.evaluate(() => {
      const header = document.querySelector('.catalog-studio__header');
      if (header) header.scrollIntoView({ block: 'start', inline: 'nearest' });
      const root = document.scrollingElement || document.documentElement;
      if (root) root.scrollTop = 0;
      document.querySelectorAll('*').forEach((el) => {
        if (el.scrollTop > 0 && el !== document.body) {
          const style = window.getComputedStyle(el);
          if (/(auto|scroll)/.test(style.overflowY) || /(auto|scroll)/.test(style.overflow)) {
            if (el.classList.contains('catalog-studio__sidebar')) return;
            el.scrollTop = 0;
          }
        }
      });
    });
    await page.waitForTimeout(250);
  }

  async function snap(name) {
    await restoreStudioTop();
    const base = path.join(OUT_DIR, name);
    await page.screenshot({ path: `${base}.png`, fullPage: true, animations: 'disabled' });
    const html = await page.content();
    fs.writeFileSync(`${base}.dom.html`, html.substring(0, 2_000_000));
    fs.writeFileSync(`${base}.console.json`, JSON.stringify(consoleBuffer, null, 2));
    fs.writeFileSync(`${base}.network.json`, JSON.stringify(networkBuffer, null, 2));
    consoleBuffer = [];
    networkBuffer = [];
  }

  function dispose() {
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
    page.off('requestfailed', onRequestFailed);
  }

  return { snap, dispose, restoreStudioTop };
}

async function collectVisibleText(page) {
  return page.evaluate(() => (document.body && document.body.innerText) || '');
}

function scanI18n(text) {
  const tokens = String(text || '').split(/\s+/).filter(Boolean);
  const rawKeys = [...new Set(tokens.filter((tok) => {
    const clean = tok.replace(/[.,;:!?)]+$/, '');
    if (clean.includes('@')) return false;
    return RAW_I18N_RE.test(clean);
  }))];
  const naN = [...new Set(String(text || '').match(/\b(NaN|undefined|null)\b/g) || [])];
  return { rawKeys, naN };
}

async function readCategoryNames(page) {
  return page.locator('.catalog-studio__category-row button.catalog-studio__category strong').allInnerTexts();
}

async function readProductNames(page) {
  return page.locator('article.catalog-studio__product h4').allInnerTexts();
}

function classifyCategories(names) {
  const trimmed = names.map((n) => String(n || '').trim()).filter(Boolean);
  const junk = trimmed.filter((n) => JUNK_CATEGORY_RE.test(n) || /E2E Cat|E2ECategory|AUDIT-KIOSK/i.test(n));
  const realPreferred = PREFERRED_CATEGORIES.filter((c) => trimmed.some((n) => n === c));
  return { all: trimmed, junk, realPreferred };
}

test('WAVE C — catalogue studio list + real category click', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const rec = attachRecorder(page);
  const states = [];

  try {
    await loginAsAdmin(page);

    await page.goto('/admin/items/studio', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/items\/studio/, { timeout: 30_000 });
    await expect(page.getByTestId('catalog-studio-page')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-testid^="catalog-studio-category-row-"]').first()).toBeVisible({
      timeout: 30_000,
    });
    await page.locator('article.catalog-studio__product').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});

    const listCats = classifyCategories(await readCategoryNames(page));
    const listProducts = await readProductNames(page);
    const listText = await collectVisibleText(page);
    const listI18n = scanI18n(listText);
    await rec.snap('studio-list');
    states.push({
      name: 'studio-list',
      url: page.url(),
      category_names: listCats.all,
      junk_categories: listCats.junk,
      product_names_sample: listProducts.slice(0, 40),
      product_count: listProducts.length,
      real_products_visible: REAL_PRODUCTS.filter((p) => listProducts.includes(p)),
      i18n: listI18n,
    });

    for (const catName of PREFERRED_CATEGORIES) {
      const row = page.locator('.catalog-studio__category-row').filter({
        has: page.locator('button.catalog-studio__category strong', { hasText: new RegExp(`^${catName}$`) }),
      });
      const present = (await row.count()) > 0;
      const stateName = `studio-category-${catName.toLowerCase()}`;
      if (!present) {
        states.push({
          name: stateName,
          url: page.url(),
          notes: `category "${catName}" not present in sidebar — skipped click`,
          category_names: listCats.all,
          junk_categories: listCats.junk,
        });
        continue;
      }
      // JS click: Playwright's locator.click() scrollIntoView hid the product grid
      // because junk categories push Tacos far below the 900px layout viewport.
      await row.evaluate((el) => {
        const btn = el.querySelector('button.catalog-studio__category');
        if (btn) btn.click();
      });
      await expect(page.getByTestId('catalog-studio-category-wizard-entry')).toBeVisible({ timeout: 10_000 });
      await expect(page.getByTestId('catalog-studio-category-wizard-entry')).toContainText(catName);
      await rec.restoreStudioTop();
      await page.locator('article.catalog-studio__product').first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
      const products = await readProductNames(page);
      const cats = classifyCategories(await readCategoryNames(page));
      const text = await collectVisibleText(page);
      const i18n = scanI18n(text);
      await rec.snap(stateName);
      states.push({
        name: stateName,
        url: page.url(),
        clicked: catName,
        category_names: cats.all,
        junk_categories: cats.junk,
        product_names: products,
        product_count: products.length,
        real_products_visible: REAL_PRODUCTS.filter((p) => products.includes(p)),
        i18n,
      });
    }

    fs.writeFileSync(path.join(OUT_DIR, 'notes.json'), JSON.stringify({
      wave: 'C',
      base_url: process.env.PLAYWRIGHT_BASE_URL || '',
      captured_at: new Date().toISOString(),
      states,
    }, null, 2));
  } finally {
    rec.dispose();
  }
});
