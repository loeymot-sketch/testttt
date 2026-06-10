// CMS E2E capture — wave A (catalogue) + wave B (stock + builder)
// Run: node cms-capture.mjs  — target http://127.0.0.1:8767 (foodking_e2e disposable DB)
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const BASE = 'http://127.0.0.1:8767';
const ROOT = path.dirname(fileURLToPath(import.meta.url));
const DIR_A = path.join(ROOT, 'wave-A');
const DIR_B = path.join(ROOT, 'wave-B');
fs.mkdirSync(DIR_A, { recursive: true });
fs.mkdirSync(DIR_B, { recursive: true });

const results = []; // {state, ok, note}
let consoleBuf = [];
let networkBuf = [];

function mysql(q) {
  try {
    return execSync(`mysql -u root foodking_e2e -e ${JSON.stringify(q)}`, { encoding: 'utf8' });
  } catch (e) {
    return 'MYSQL_ERROR: ' + e.message;
  }
}

async function settle(page, ms = 1500) {
  try { await page.waitForLoadState('networkidle', { timeout: 15000 }); } catch {}
  await page.waitForTimeout(ms);
}

async function snap(page, dir, state) {
  try {
    await page.screenshot({ path: path.join(dir, `${state}.png`), fullPage: true });
  } catch (e) {
    // fullpage can fail on huge pages — fall back to viewport
    try { await page.screenshot({ path: path.join(dir, `${state}.png`) }); } catch {}
  }
  try {
    const dom = await page.evaluate(() => document.documentElement.outerHTML);
    fs.writeFileSync(path.join(dir, `${state}.dom.html`), dom);
  } catch (e) {
    fs.writeFileSync(path.join(dir, `${state}.dom.html`), `<!-- DOM capture failed: ${e.message} -->`);
  }
  fs.writeFileSync(path.join(dir, `${state}.console.json`), JSON.stringify(consoleBuf, null, 2));
  fs.writeFileSync(path.join(dir, `${state}.network.json`), JSON.stringify(networkBuf, null, 2));
  consoleBuf = [];
  networkBuf = [];
}

async function state(page, dir, name, fn) {
  try {
    await fn();
    results.push({ state: name, ok: true });
    console.log(`[OK] ${name}`);
  } catch (e) {
    results.push({ state: name, ok: false, note: e.message });
    fs.writeFileSync(path.join(dir, `${name}.error.txt`), String(e.stack || e.message));
    console.log(`[KO] ${name}: ${e.message}`);
    try { await snap(page, dir, name); } catch {}
  }
}

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 2000 } });
const page = await ctx.newPage();

page.on('console', (m) => consoleBuf.push({ type: m.type(), text: m.text().slice(0, 800) }));
page.on('pageerror', (e) => consoleBuf.push({ type: 'pageerror', text: String(e).slice(0, 800) }));
page.on('response', (r) => {
  if (r.status() >= 400) networkBuf.push({ status: r.status(), method: r.request().method(), url: r.url() });
});
page.on('requestfailed', (r) => {
  networkBuf.push({ status: 'FAILED', method: r.method(), url: r.url(), error: r.failure()?.errorText });
});

// ---------- LOGIN ----------
await page.goto(`${BASE}/login`);
await settle(page);
const email = page.getByPlaceholder(/email/i).first();
if (await email.count()) {
  await email.fill('admin@lecayenne.fr');
} else {
  await page.locator('input[type="email"], input[type="text"]').first().fill('admin@lecayenne.fr');
}
const pwd = page.getByPlaceholder(/mot de passe/i).first();
if (await pwd.count()) {
  await pwd.fill('123456');
} else {
  await page.locator('input[type="password"]').first().fill('123456');
}
await page.getByRole('button', { name: /connexion/i }).click();
await settle(page, 2500);
if (page.url().includes('/login')) {
  console.error('LOGIN FAILED — still on /login');
  await snap(page, ROOT, 'login-failed');
  await browser.close();
  process.exit(1);
}
console.log('[OK] login →', page.url());
consoleBuf = []; networkBuf = [];

// ================= WAVE A =================

// A1 — category list initial
await state(page, DIR_A, 'A1-category-list', async () => {
  await page.goto(`${BASE}/admin/settings/item-categories/list`);
  await settle(page);
  await page.waitForSelector('[data-testid="admin-category-list"]', { timeout: 10000 });
  await snap(page, DIR_A, 'A1-category-list');
});

// A2 — open create modal, parent select visible
await state(page, DIR_A, 'A2-create-modal', async () => {
  await page.locator('[data-testid="admin-category-create-open"] button').first().click();
  await page.waitForSelector('#categoryModal [data-testid="admin-category-form-parent"]', { state: 'visible', timeout: 10000 });
  await page.waitForTimeout(600);
  await snap(page, DIR_A, 'A2-create-modal');
});

// A3 — create sub-category "E2E-AUDIT Sub" parent Galette
await state(page, DIR_A, 'A3-subcategory-created', async () => {
  await page.locator('#categoryModal [data-testid="admin-category-form-name"]').fill('E2E-AUDIT Sub');
  await page.locator('#categoryModal [data-testid="admin-category-form-parent"]').selectOption({ label: 'Galette' });
  await page.locator('#categoryModal [data-testid="admin-category-form-save"]').click();
  await settle(page, 2000);
  await page.waitForSelector('[data-testid="admin-category-list"] tr:has-text("E2E-AUDIT Sub")', { timeout: 10000 });
  await snap(page, DIR_A, 'A3-subcategory-created');
});

// A4 — reopen Galette edit: parent select must offer only « Aucune »
await state(page, DIR_A, 'A4-galette-edit-parent-locked', async () => {
  // row whose name cell is exactly Galette (exclude the E2E-AUDIT Sub row that mentions Galette in its badge)
  const rows = page.locator('[data-testid^="admin-category-row-"]', { hasText: 'Galette' });
  const n = await rows.count();
  let galetteRow = null;
  for (let i = 0; i < n; i++) {
    const txt = await rows.nth(i).innerText();
    if (!txt.includes('E2E-AUDIT')) { galetteRow = rows.nth(i); break; }
  }
  if (!galetteRow) throw new Error('Galette row not found');
  await galetteRow.locator('[data-testid^="admin-category-edit-"] button').click();
  await page.waitForSelector('#categoryModal [data-testid="admin-category-form-parent"]', { state: 'visible', timeout: 10000 });
  await page.waitForTimeout(800);
  const options = await page.locator('#categoryModal [data-testid="admin-category-form-parent"] option').allInnerTexts();
  fs.writeFileSync(path.join(DIR_A, 'A4-parent-options.json'), JSON.stringify(options, null, 2));
  await snap(page, DIR_A, 'A4-galette-edit-parent-locked');
});

// close modal (Escape + close button fallback)
try {
  const closeBtn = page.locator('#categoryModal .modal-close, #categoryModal [data-modal-hide], #categoryModal button:has(.lab-close)').first();
  if (await closeBtn.count()) await closeBtn.click({ timeout: 3000 });
  else await page.keyboard.press('Escape');
} catch { await page.keyboard.press('Escape').catch(() => {}); }
await page.waitForTimeout(800);

// A5 — studio sidebar tree
await state(page, DIR_A, 'A5-studio-sidebar', async () => {
  await page.goto(`${BASE}/admin/items/studio`);
  await settle(page);
  await page.waitForSelector('[data-testid="catalog-studio-page"]', { timeout: 10000 });
  await page.waitForSelector('.catalog-studio__category-row:has-text("E2E-AUDIT Sub")', { timeout: 10000 });
  await snap(page, DIR_A, 'A5-studio-sidebar');
});

// A6 — select E2E-AUDIT Sub bucket, quick-create product
await state(page, DIR_A, 'A6-product-created', async () => {
  await page.locator('.catalog-studio__category-row:has-text("E2E-AUDIT Sub") button.catalog-studio__category').click();
  await page.waitForTimeout(1000);
  await page.locator('[data-testid="catalog-studio-add-product"]').click();
  const form = page.locator('form.catalog-studio__quick-form--product');
  await form.waitFor({ state: 'visible', timeout: 8000 });
  await form.locator('input[type="text"].db-field-control').nth(0).fill('E2E-AUDIT Prod');
  await form.locator('input[type="text"].db-field-control').nth(1).fill('5.00');
  await form.locator('button[type="submit"]').click();
  await settle(page, 2500);
  await page.waitForSelector('.catalog-studio__product:has-text("E2E-AUDIT Prod")', { timeout: 12000 });
  await snap(page, DIR_A, 'A6-product-created');
});

// A7 — cleanup: delete product via /admin/items, then category via settings list
await state(page, DIR_A, 'A7a-item-delete-confirm', async () => {
  await page.goto(`${BASE}/admin/items`);
  await settle(page);
  // open filter slide and search by name
  await page.locator('button.table-filter-btn').first().click();
  await page.waitForTimeout(800);
  await page.locator('#item-filter input#name').fill('E2E-AUDIT');
  await page.locator('#item-filter form button[type="submit"], #item-filter button:has-text("Recherche"), #item-filter button:has-text("Search")').first().click()
    .catch(async () => { await page.locator('#item-filter input#name').press('Enter'); });
  await settle(page, 2000);
  const row = page.locator('[data-testid^="admin-item-row-"]', { hasText: 'E2E-AUDIT Prod' }).first();
  await row.waitFor({ state: 'visible', timeout: 10000 });
  await row.locator('button:has-text("Supprimer"), button.db-btn-outline.danger').first().click();
  await page.waitForSelector('.swal2-confirm', { timeout: 8000 });
  await page.waitForTimeout(500);
  await snap(page, DIR_A, 'A7a-item-delete-confirm');
});

await state(page, DIR_A, 'A7b-item-deleted', async () => {
  await page.locator('.swal2-confirm').click();
  await settle(page, 2000);
  await snap(page, DIR_A, 'A7b-item-deleted');
});

await state(page, DIR_A, 'A7c-category-delete-confirm', async () => {
  await page.goto(`${BASE}/admin/settings/item-categories/list`);
  await settle(page);
  const row = page.locator('[data-testid^="admin-category-row-"]', { hasText: 'E2E-AUDIT Sub' }).first();
  await row.waitFor({ state: 'visible', timeout: 10000 });
  await row.locator('[data-testid^="admin-category-delete-"] button').click();
  await page.waitForSelector('.swal2-confirm', { timeout: 8000 });
  await page.waitForTimeout(500);
  await snap(page, DIR_A, 'A7c-category-delete-confirm');
});

await state(page, DIR_A, 'A7d-category-deleted', async () => {
  await page.locator('.swal2-confirm').click();
  await settle(page, 2000);
  await snap(page, DIR_A, 'A7d-category-deleted');
});

// DB verification of soft-deletes
{
  const itemCheck = mysql("SELECT id,name,deleted_at FROM items WHERE name LIKE 'E2E-AUDIT%'");
  const catCheck = mysql("SELECT id,name,deleted_at FROM item_categories WHERE name LIKE 'E2E-AUDIT%'");
  fs.writeFileSync(path.join(DIR_A, 'cleanup.json'), JSON.stringify({
    queried_at: new Date().toISOString(),
    items_like_E2E_AUDIT: itemCheck,
    item_categories_like_E2E_AUDIT: catCheck,
    interpretation: 'soft-deleted = row present with deleted_at NOT NULL; hard-deleted = no row',
  }, null, 2));
  console.log('[cleanup.json]', itemCheck.trim(), '|', catCheck.trim());
}

// ================= WAVE B =================

// B1 — stock rupture rail
await state(page, DIR_B, 'B1-stock-rail', async () => {
  await page.goto(`${BASE}/admin/stock/rupture`);
  await settle(page);
  await page.waitForSelector('[data-testid="stock-mgmt-rail"]', { timeout: 12000 });
  await snap(page, DIR_B, 'B1-stock-rail');
});

// B2 — Tacos bucket
await state(page, DIR_B, 'B2-tacos-products', async () => {
  await page.locator('[data-testid="stock-mgmt-bucket-cat-5"]').click();
  await settle(page, 1500);
  await snap(page, DIR_B, 'B2-tacos-products');
});

// B3 — toggle Big Tacos → RUPTURE + DB/sync proof
const syncProof = {};
await state(page, DIR_B, 'B3-rupture-toggled', async () => {
  await page.locator('[data-testid="stock-mgmt-toggle-item-27"]').first().click();
  await settle(page, 2000);
  await snap(page, DIR_B, 'B3-rupture-toggled');
  syncProof.after_rupture = {
    item_branch_availability: mysql('SELECT is_available FROM item_branch_availability WHERE item_id=27 AND branch_id=1'),
    last_domain_events: mysql('SELECT broadcast_as FROM domain_events ORDER BY id DESC LIMIT 2'),
  };
});

// B4 — toggle back EN STOCK + DB re-check
await state(page, DIR_B, 'B4-back-in-stock', async () => {
  await page.locator('[data-testid="stock-mgmt-toggle-item-27"]').first().click();
  await settle(page, 2000);
  await snap(page, DIR_B, 'B4-back-in-stock');
  syncProof.after_restore = {
    item_branch_availability: mysql('SELECT is_available FROM item_branch_availability WHERE item_id=27 AND branch_id=1'),
    last_domain_events: mysql('SELECT broadcast_as FROM domain_events ORDER BY id DESC LIMIT 2'),
  };
});
fs.writeFileSync(path.join(DIR_B, 'sync-proof.json'), JSON.stringify(syncProof, null, 2));

// B5 — studio → Tacos bucket → category wizard button → builder iframe
await state(page, DIR_B, 'B5-composer-drawer', async () => {
  await page.goto(`${BASE}/admin/items/studio`);
  await settle(page);
  await page.locator('[data-testid="catalog-studio-category-row-5"] button.catalog-studio__category').click();
  await page.waitForTimeout(1200);
  await page.locator('[data-testid="catalog-studio-category-wizard-button"]').click();
  const frameEl = page.locator('[data-testid="catalog-studio-composer-frame"]');
  await frameEl.waitFor({ state: 'visible', timeout: 12000 });
  // wait composer app inside iframe
  const frame = page.frameLocator('[data-testid="catalog-studio-composer-frame"]');
  await frame.locator('[data-testid="admin-composer-root"]').waitFor({ timeout: 20000 });
  await page.waitForTimeout(2500);
  await snap(page, DIR_B, 'B5-composer-drawer');
});

// B6 — in iframe: click step « Choisis la taille » → step panel with presets + read-only prices
await state(page, DIR_B, 'B6-step-taille-panel', async () => {
  const frame = page.frameLocator('[data-testid="catalog-studio-composer-frame"]');
  await frame.locator('[data-testid^="composer-step-select-"]', { hasText: 'Choisis la taille' }).first().click();
  await frame.locator('[data-testid="composer-step-form-panel"]').waitFor({ timeout: 10000 });
  await page.waitForTimeout(1200);
  // scroll inside the iframe so that the read-only prices panel + presets fieldset are in viewport
  const handle = await page.locator('[data-testid="catalog-studio-composer-frame"]').elementHandle();
  const innerFrame = await handle.contentFrame();
  if (innerFrame) {
    await innerFrame.evaluate(() => {
      const target = document.querySelector('[data-testid="composer-step-choice-prices"]')
        || document.querySelector('[data-testid="composer-step-form-panel"]');
      if (target) target.scrollIntoView({ block: 'start' });
    });
    await page.waitForTimeout(800);
    // save iframe DOM too (the main DOM does not include iframe content)
    const iframeDom = await innerFrame.evaluate(() => document.documentElement.outerHTML);
    fs.writeFileSync(path.join(DIR_B, 'B6-step-taille-panel.iframe.dom.html'), iframeDom);
    const visible = await innerFrame.evaluate(() => ({
      prices: !!document.querySelector('[data-testid="composer-step-choice-prices"]'),
      presetSingle: !!document.querySelector('[data-testid="composer-step-preset-single"]'),
      presetMultiple: !!document.querySelector('[data-testid="composer-step-preset-multiple"]'),
      presetCustom: !!document.querySelector('[data-testid="composer-step-preset-custom"]'),
    }));
    fs.writeFileSync(path.join(DIR_B, 'B6-panel-elements.json'), JSON.stringify(visible, null, 2));
  }
  await snap(page, DIR_B, 'B6-step-taille-panel');
});

// B7 — no publish/unpublish/delete on the Tacos wizard (production clone). Capture-only — done above.
results.push({ state: 'B7-no-mutation', ok: true, note: 'no publish/unpublish/delete clicked on Tacos wizard (capture-only rule respected)' });

await browser.close();

fs.writeFileSync(path.join(ROOT, 'capture-results.json'), JSON.stringify(results, null, 2));
console.log('\n=== RESULTS ===');
for (const r of results) console.log(`${r.ok ? 'OK' : 'KO'}  ${r.state}${r.note ? ' — ' + r.note : ''}`);
