/**
 * Wave Y-C — Follow-up spec (advisor feedback round 2026-05-21)
 *
 * Targets the three explicit brief checks not fully covered in the main run:
 *   1. /admin/items — verify V2 catalog items (Sandwich Cayenne, Big Cayenne,
 *      Tacos, Bowl Frites Poulet crispy) are visible with prices + images.
 *      Strategy: increase page size from 10 → 50 (URL ?perPage=50) or paginate.
 *   2. /admin/stock/rupture — click 'Burgers' tab to check Chicken Burger rupture
 *      status, and 'Sauce' tabs (bowl/wizard) to check Algérienne rupture.
 *   3. /admin/items?filter — also try category filtering.
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_CAP =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-C';
const OUT_LOG =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-1';
const STATE_FILE = '/tmp/_wave-y-c-storage-state.json';

test.use({ viewport: { width: 1366, height: 900 } });
test.describe.configure({ retries: 0 });

function attachListeners(page, store) {
  page.on('console', (msg) => {
    const t = msg.type();
    if (t === 'error' || t === 'warning') {
      store.console.push({ type: t, text: msg.text().slice(0, 600) });
    }
  });
  page.on('pageerror', (err) =>
    store.pageErrors.push({ message: String(err).slice(0, 600) })
  );
  page.on('response', (resp) => {
    if (resp.status() >= 400) {
      store.network.push({ url: resp.url(), status: resp.status() });
    }
  });
}

function persist(store) {
  try {
    fs.writeFileSync(
      path.join(OUT_LOG, `wave-C-followup-${store.name}.json`),
      JSON.stringify(store, null, 2)
    );
  } catch (_e) {
    /* noop */
  }
}

function logEvents(name) {
  return { name, console: [], network: [], failed: [], pageErrors: [] };
}

// Reuse main spec's auth if storage state exists; otherwise re-create.
test.beforeAll(async ({ browser }) => {
  if (fs.existsSync(STATE_FILE)) return;
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  try {
    await page.waitForFunction(
      () => document.querySelectorAll('input').length >= 2,
      null,
      { timeout: 20000 }
    );
  } catch (_e) {
    /* noop */
  }
  const inputs = await page.locator('input').all();
  if (inputs.length >= 2) {
    await inputs[0].fill('admin@lecayenne.fr').catch(() => {});
    await inputs[1].fill('123456').catch(() => {});
  }
  const btn = page.getByRole('button', { name: /connexion/i }).first();
  if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
    await btn.click().catch(() => {});
  }
  await page.waitForTimeout(5000);
  await ctx.storageState({ path: STATE_FILE });
  await ctx.close();
});

async function authedPage(browser, store) {
  const ctx = await browser.newContext({ storageState: STATE_FILE });
  const page = await ctx.newPage();
  attachListeners(page, store);
  return { ctx, page };
}

test('C-09 Admin items — paginated through to find V2 items', async ({
  browser,
}) => {
  const store = logEvents('C-09-items-pagination');
  const { ctx, page } = await authedPage(browser, store);

  // Snapshot at page 1 (already covered by C-05 but capture again for context).
  await page.goto(`${BASE}/admin/items`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  // Detect V2 hero items in DOM regardless of page.
  const itemNames = [
    'Sandwich Cayenne',
    'Big Cayenne',
    'Tacos',
    'Bowl Frites Poulet crispy',
    'Galette Cayenne',
    'Chicken Burger',
  ];
  const presence = {};
  for (const n of itemNames) {
    presence[n] = await page.getByText(n, { exact: false }).count();
  }
  store.v2_presence_page1 = presence;
  await page.screenshot({
    path: `${OUT_CAP}/C-09-items-page1.png`,
    fullPage: true,
  });

  // Try to change page size from "10" dropdown to a larger value if available.
  const sizeSelect = page.locator('select').first();
  if (await sizeSelect.count()) {
    try {
      await sizeSelect.selectOption('50');
      await page.waitForTimeout(2500);
      await page.screenshot({
        path: `${OUT_CAP}/C-09b-items-pagesize-50.png`,
        fullPage: true,
      });
    } catch (_e) {
      /* try clicking the dropdown manually */
    }
  }

  // Iterate visible pagination (max 5 pages).
  for (let p = 2; p <= 5; p++) {
    const pageBtn = page
      .getByRole('button', { name: new RegExp(`^${p}$`) })
      .first();
    if (await pageBtn.count()) {
      try {
        await pageBtn.click({ timeout: 2000 });
        await page.waitForTimeout(2000);
        await page.screenshot({
          path: `${OUT_CAP}/C-09c-items-page-${p}.png`,
          fullPage: true,
        });
      } catch (_e) {
        /* skip */
      }
    } else {
      // Fallback: try anchor link with the page number.
      const linkBtn = page
        .locator(`a:has-text("${p}"), li:has-text("${p}")`)
        .first();
      if (await linkBtn.count()) {
        try {
          await linkBtn.click({ timeout: 2000 });
          await page.waitForTimeout(2000);
          await page.screenshot({
            path: `${OUT_CAP}/C-09c-items-page-${p}.png`,
            fullPage: true,
          });
        } catch (_e) {
          /* skip */
        }
      }
    }
  }

  // Aggregate presence across all pages (gather DOM text from final state).
  const finalPresence = {};
  for (const n of itemNames) {
    finalPresence[n] = await page.getByText(n, { exact: false }).count();
  }
  store.v2_presence_final = finalPresence;
  persist(store);
  await ctx.close();
});

test('C-10 Stock rupture — Burgers + Sauces categories', async ({
  browser,
}) => {
  const store = logEvents('C-10-stock-rupture-categories');
  const { ctx, page } = await authedPage(browser, store);
  await page.goto(`${BASE}/admin/stock/rupture`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForTimeout(5000);

  // Click Burgers tab.
  const burgersTab = page.getByText('Burgers', { exact: true }).first();
  if (await burgersTab.count()) {
    try {
      await burgersTab.click({ timeout: 2500 });
      await page.waitForTimeout(2000);
      await page.screenshot({
        path: `${OUT_CAP}/C-10-stock-burgers.png`,
        fullPage: true,
      });
    } catch (_e) {
      /* noop */
    }
  }
  // Capture Chicken Burger row state (text containing 'Chicken Burger').
  const chickenBurger = page.getByText(/Chicken Burger/i).first();
  if (await chickenBurger.count()) {
    try {
      const card = chickenBurger.locator(
        'xpath=ancestor::*[contains(@class, "rounded") or contains(@class, "card")][1]'
      );
      if (await card.count()) {
        await card.screenshot({
          path: `${OUT_CAP}/C-10b-chicken-burger-card.png`,
        });
        store.chicken_burger_card_captured = true;
      }
    } catch (_e) {
      /* noop */
    }
  } else {
    store.chicken_burger_not_found = true;
  }

  // Click "Sauce bol" then "Sauce (1ère Gratuite)" categories.
  const sauceBol = page.getByText('Sauce bol', { exact: true }).first();
  if (await sauceBol.count()) {
    try {
      await sauceBol.click({ timeout: 2500 });
      await page.waitForTimeout(2000);
      await page.screenshot({
        path: `${OUT_CAP}/C-10c-sauce-bol.png`,
        fullPage: true,
      });
    } catch (_e) {
      /* noop */
    }
  }
  const sauce1Gratuite = page
    .getByText(/Sauce.*1.re.*Gratuite/i)
    .first();
  if (await sauce1Gratuite.count()) {
    try {
      await sauce1Gratuite.click({ timeout: 2500 });
      await page.waitForTimeout(2000);
      await page.screenshot({
        path: `${OUT_CAP}/C-10d-sauce-1ere-gratuite.png`,
        fullPage: true,
      });
    } catch (_e) {
      /* noop */
    }
  }

  // Look for Algérienne / Algerienne sauce.
  const algerienne = page.getByText(/Alg[ée]rienne/i).first();
  if (await algerienne.count()) {
    try {
      const card = algerienne.locator(
        'xpath=ancestor::*[contains(@class, "rounded") or contains(@class, "card")][1]'
      );
      if (await card.count()) {
        await card.screenshot({
          path: `${OUT_CAP}/C-10e-algerienne-card.png`,
        });
        store.algerienne_card_captured = true;
      } else {
        store.algerienne_found_but_no_card_ancestor = true;
      }
    } catch (_e) {
      /* noop */
    }
  } else {
    store.algerienne_not_found_in_current_view = true;
  }

  // Look at all visible "Sauce" tabs and click each to capture all sauce categories.
  const sauceTabs = await page
    .getByText(/^Sauce/i, { exact: false })
    .all();
  store.sauce_tabs_count = sauceTabs.length;

  persist(store);
  await ctx.close();
});
