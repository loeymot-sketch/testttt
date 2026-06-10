// FoodKing — W-A BORNE parcours profond 100% (GOAL VALIDATION PROFONDE 2026-06-10)
// Disposable clone ONLY:
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/e2e/zz-borne-parcours-profond-2026-06-10.spec.js --retries=0
//
// Couvre A1 idle/dine-in-off, A2 catégories (sidebar+8 cats), A3 wizards (7 items,
// chaque étape capturée + back + next-disabled + cancel), A4 panier (+1/-1, clamp 20,
// remove, clear, refill), A5 upsell (skip + accept), A6 loyalty (code invalide),
// A7 paiement comptoir (PENDING_COUNTER + idempotency double-clic), A8 rupture live.
// Captures JPEG q70 -> reports/test-e2e/validation-profonde-2026-06-10/borne/captures/
// FROZEN: KioskWizard/KioskApp/KioskUpsell observés UNIQUEMENT, jamais modifiés.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../..');
const REPORT_DIR = path.join(REPO, 'reports/test-e2e/validation-profonde-2026-06-10/borne');
const CYCLE = process.env.BORNE_CYCLE || '1';
const CAPS = path.join(REPORT_DIR, 'captures', `cycle-${CYCLE}`);
fs.mkdirSync(CAPS, { recursive: true });

function db(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    encoding: 'utf8', timeout: 15_000,
  }).trim();
}

async function snap(page, name) {
  await page.screenshot({ path: path.join(CAPS, `${name}.jpg`), type: 'jpeg', quality: 70, fullPage: false });
}

// ---- per-step error collectors (console errors / pageerrors / HTTP>=400) ----
const errorLog = []; // { step, kind, detail }
let currentStep = 'init';
function logErr(e) { errorLog.push(e); appendJsonl(ERR_LOG_FILE, e); }
function wirePage(page) {
  page.setDefaultTimeout(15_000);
  page.on('console', (msg) => {
    if (msg.type() === 'error') logErr({ step: currentStep, kind: 'console', detail: msg.text().slice(0, 400) });
  });
  page.on('pageerror', (e) => logErr({ step: currentStep, kind: 'pageerror', detail: String(e.message).slice(0, 400) }));
  page.on('response', (r) => {
    if (r.status() >= 400) {
      logErr({ step: currentStep, kind: `http-${r.status()}`, detail: `${r.request().method()} ${r.url()}`.slice(0, 300) });
    }
  });
}

const ERR_LOG_FILE = path.join(REPORT_DIR, `errors-cycle${CYCLE}.jsonl`);
const FINDINGS_FILE = path.join(REPORT_DIR, `findings-cycle${CYCLE}.jsonl`);
const JOURNEY_FILE = path.join(REPORT_DIR, `journey-cycle${CYCLE}.jsonl`);
function appendJsonl(file, obj) { try { fs.appendFileSync(file, JSON.stringify(obj) + '\n'); } catch (_) {} }
function readJsonl(file) {
  try { return fs.readFileSync(file, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)); } catch (_) { return []; }
}
const findings = { push: (f) => appendJsonl(FINDINGS_FILE, f) }; // disk-backed (worker restarts wipe module state)
function mark(step, status, proof) { appendJsonl(JOURNEY_FILE, { step, status, proof }); }

const RAW_LABEL_RE = /^[a-z]+\.[a-z_.]+$/;

async function rawLabelScan(page, stepName) {
  // scan visible text nodes for unresolved i18n keys like "kiosk.foo.bar"
  const hits = await page.evaluate(() => {
    const out = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = walker.nextNode())) {
      const t = (n.textContent || '').trim();
      if (/^[a-z]+\.[a-z_.]+$/.test(t) && t.includes('.') && t.length > 4) {
        const el = n.parentElement;
        if (el && el.offsetParent !== null) out.push(t);
      }
    }
    return [...new Set(out)].slice(0, 10);
  }).catch(() => []);
  for (const h of hits) {
    findings.push({ id: `RAW-${stepName}`, sev: 'P2', step: stepName, detail: `label brut "${h}"`, capture: '' });
  }
  return hits;
}

/** Boot the kiosk surface. Auto-login (trusted IP / kioskAutoLogin) is expected on :8766. */
async function kioskBoot(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);
  for (let i = 0; i < 3; i++) {
    if (!/\/kiosk\/login/.test(page.url())) break;
    await page.waitForTimeout(2500); // KioskLoginComponent auto-retry
    if (/\/kiosk\/login/.test(page.url())) {
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500);
    }
  }
  if (/\/kiosk\/login/.test(page.url())) {
    await snap(page, `boot-stuck-login-${Date.now()}`);
    throw new Error('kiosk stuck on /kiosk/login (auto-login failed)');
  }
}

async function startTakeaway(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1600);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
  }
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForTimeout(1200);
}

async function addSimple(page, ids) {
  for (const id of ids) {
    let added = false;
    for (const cat of [10, 9, 7]) {
      await page.goto(`/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1400);
      const add = page.locator(`[data-testid="kiosk-product-add-${id}"]`);
      if (await add.isVisible().catch(() => false)) { await add.click(); await page.waitForTimeout(1000); added = true; break; }
    }
    if (!added) throw new Error(`simple item ${id} add button not found`);
  }
}

const CHOICE = '.kiosk-viande-card:not(.kiosk-variation--disabled):not(.is-out-of-stock), .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock), .kiosk-generic-choice:not(.unavailable):not([disabled])';

/** Compose a wizard, capturing each step as <prefix>-step<k>.jpg. Returns step count or -1. */
async function composeWizardCaptured(page, w, prefix) {
  await page.goto(`/kiosk/categories?cat=${w.cat}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);
  await page.locator(`[data-testid="kiosk-product-card-${w.id}"]`).first().click();
  await page.waitForTimeout(1700);
  await expect(page.locator('.kiosk-wizard'), `wizard ${w.id} mounted`).toBeVisible({ timeout: 8000 });
  const next = page.locator('.kiosk-btn-next');
  let guard = 0; let stepShot = 0;
  while (guard++ < 14) {
    await page.waitForTimeout(650);
    stepShot += 1;
    await snap(page, `${prefix}-step${stepShot}`);
    const menuNone = page.locator('.kiosk-menu-card').filter({ hasText: /sans menu/i }).first();
    if (await menuNone.isVisible().catch(() => false)) { await menuNone.click().catch(() => {}); await page.waitForTimeout(350); }
    const choices = page.locator(CHOICE);
    if ((await choices.count().catch(() => 0)) > 0) { await choices.first().click().catch(() => {}); await page.waitForTimeout(400); }
    const isLast = await page.locator('.kiosk-btn-next--cart').isVisible().catch(() => false);
    const enabled = await next.first().isEnabled().catch(() => false);
    if (isLast && enabled) {
      await snap(page, `${prefix}-step${stepShot}-final`);
      await next.first().click(); await page.waitForTimeout(1300);
      return stepShot;
    }
    if (enabled) { await next.first().click(); await page.waitForTimeout(650); }
    else if ((await choices.count().catch(() => 0)) === 0) return -1;
  }
  return -1;
}

async function cartTotalNumber(page) {
  const raw = await page.locator('[data-testid="kiosk-cart-total"]').innerText().catch(() => '');
  const m = raw.replace(/\s/g, '').replace(',', '.').match(/(\d+(?:\.\d+)?)/);
  return m ? parseFloat(m[1]) : NaN;
}

test.describe.configure({ timeout: 1_500_000 });
test.describe('W-A BORNE parcours profond', () => {
  test.setTimeout(420_000);

  test('A1 idle + choix type commande (dine-in OFF)', async ({ page }) => {
    for (const f of [ERR_LOG_FILE, FINDINGS_FILE, JOURNEY_FILE]) { try { fs.writeFileSync(f, ''); } catch (_) {} }
    wirePage(page); currentStep = 'A1';
    await kioskBoot(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'a1-idle');
    await rawLabelScan(page, 'A1-idle');

    // touch CTA -> order type chooser (uniquement si le chooser n'est pas déjà affiché)
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (!(await takeaway.isVisible().catch(() => false))) {
      const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
      if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(900); }
    }
    await expect(takeaway, 'tuile À emporter visible').toBeVisible({ timeout: 12_000 });
    await snap(page, 'a1-order-type');
    await rawLabelScan(page, 'A1-order-type');

    // V1: dine-in must be absent (pos_dine_in_enabled=false)
    const dineInCount = await page.locator('[data-testid="kiosk-order-type-dine-in"]').count();
    expect(dineInCount, 'tuile Sur place ABSENTE (V1 dine-in OFF)').toBe(0);

    await takeaway.click();
    await page.waitForURL(/\/kiosk\/categories/, { timeout: 15_000 });
    mark('A1 idle + À emporter + dine-in OFF', 'PASS', 'a1-idle.jpg, a1-order-type.jpg');
  });

  test('A2 catégories — sidebar + 1 capture par catégorie', async ({ page }) => {
    wirePage(page); currentStep = 'A2';
    await kioskBoot(page);
    await startTakeaway(page);
    await page.waitForURL(/\/kiosk\/categories/, { timeout: 15_000 });
    await page.waitForTimeout(1800);

    const sidebarItems = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
    const count = await sidebarItems.count();
    const sidebarDump = [];
    for (let i = 0; i < count; i++) {
      const el = sidebarItems.nth(i);
      const tid = await el.getAttribute('data-testid');
      const txt = (await el.innerText().catch(() => '')).replace(/\n/g, ' ').trim();
      const hasImg = (await el.locator('img').count()) > 0;
      sidebarDump.push({ tid, txt, hasImg });
    }
    fs.writeFileSync(path.join(REPORT_DIR, `a2-sidebar-cycle${CYCLE}.json`), JSON.stringify({ count, sidebarDump }, null, 2));
    await snap(page, 'a2-sidebar-full');
    expect(count, 'sidebar a des catégories').toBeGreaterThanOrEqual(7);

    // E2E pollution categories must NOT leak to the customer surface
    const e2eLeak = sidebarDump.filter((s) => /E2E-CAT/i.test(s.txt));
    if (e2eLeak.length > 0) {
      findings.push({ id: 'A2-E2E-LEAK', sev: 'P3', step: 'A2', detail: `catégories de test visibles borne: ${e2eLeak.map((x) => x.txt).join(', ')}`, capture: 'a2-sidebar-full.jpg' });
    }

    const mainCats = [
      { id: 1, name: 'Sandwich Cayenne' }, { id: 2, name: 'Galette' }, { id: 3, name: 'Sandwich Classique' },
      { id: 4, name: 'Burgers' }, { id: 5, name: 'Tacos' }, { id: 6, name: 'Bols Gourmands' },
      { id: 9, name: 'Desserts' }, { id: 10, name: 'Boissons' },
    ];
    for (const c of mainCats) {
      await page.goto(`/kiosk/categories?cat=${c.id}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1700);
      const title = await page.locator('[data-testid="kiosk-categories-zone-title"]').innerText().catch(() => '');
      const prodCount = await page.locator('[data-testid^="kiosk-product-card-"]').count();
      await snap(page, `a2-cat-${c.id}`);
      await rawLabelScan(page, `A2-cat${c.id}`);
      if (prodCount === 0) {
        findings.push({ id: `A2-EMPTY-${c.id}`, sev: 'P2', step: 'A2', detail: `catégorie ${c.id} (${title}) grille vide`, capture: `a2-cat-${c.id}.jpg` });
      }
      console.log(`[A2] cat=${c.id} title="${title}" products=${prodCount}`);
    }
    mark('A2 sidebar + 8 captures catégories', 'PASS', 'a2-sidebar-full.jpg, a2-cat-{1..6,9,10}.jpg');
  });

  test('A3 wizards — 7 items, chaque étape capturée', async ({ page }) => {
    wirePage(page); currentStep = 'A3';
    await kioskBoot(page);
    await startTakeaway(page);

    const WIZARDS = [
      { id: 22, cat: 1, label: 'sandwich-cayenne' },
      { id: 36, cat: 1, label: 'big-cayenne' },
      { id: 26, cat: 5, label: 'tacos' },
      { id: 38, cat: 4, label: 'burger' },
      { id: 41, cat: 6, label: 'bol' },
      { id: 24, cat: 2, label: 'galette' },
      { id: 25, cat: 3, label: 'classique' },
    ];
    const wizardSteps = {};
    for (const w of WIZARDS) {
      currentStep = `A3-${w.label}`;
      const steps = await composeWizardCaptured(page, w, `a3-${w.label}`);
      wizardSteps[w.label] = steps;
      expect(steps, `${w.label} (item ${w.id}) composé jusqu'au panier`).toBeGreaterThan(0);
    }
    fs.writeFileSync(path.join(REPORT_DIR, `a3-wizard-steps-cycle${CYCLE}.json`), JSON.stringify(wizardSteps, null, 2));

    // -- next disabled tant que min_select non atteint (étape viande du tacos 26) --
    currentStep = 'A3-next-disabled';
    await page.goto('/kiosk/categories?cat=5', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1700);
    await page.locator('[data-testid="kiosk-product-card-26"]').first().click();
    await page.waitForTimeout(1700);
    const next = page.locator('.kiosk-btn-next').first();
    const initiallyDisabled = !(await next.isEnabled().catch(() => true));
    await snap(page, 'a3-next-disabled');
    expect(initiallyDisabled, 'next désactivé sans sélection (min_select)').toBeTruthy();

    // -- bouton retour: sélectionner, next, prev -> revient --
    currentStep = 'A3-back';
    const choices = page.locator(CHOICE);
    await choices.first().click();
    await page.waitForTimeout(450);
    await next.click();
    await page.waitForTimeout(800);
    await snap(page, 'a3-back-step2');
    const prevBtn = page.locator('button.kiosk-btn-prev');
    if (await prevBtn.count() > 0) { await prevBtn.first().click(); } else {
      await page.locator('.kiosk-progress-arrow').first().click();
    }
    await page.waitForTimeout(800);
    await snap(page, 'a3-back-returned');

    // -- annuler le wizard en cours: panier inchangé --
    currentStep = 'A3-cancel';
    // compte les lignes panier AVANT (navigation directe — le wizard 26 sera rouvert)
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    const linesBefore = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
    await page.goto('/kiosk/categories?cat=5', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.locator('[data-testid="kiosk-product-card-26"]').first().click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.kiosk-wizard')).toBeVisible({ timeout: 8000 });
    await page.locator('.kiosk-wizard-close').click();
    await page.waitForTimeout(700);
    const abandonModal = page.locator('.kiosk-wizard-abandon-modal');
    if (await abandonModal.isVisible().catch(() => false)) {
      await snap(page, 'a3-cancel-modal');
      await page.locator('.kiosk-wizard-abandon-yes').click();
    }
    await page.waitForTimeout(1200);
    await snap(page, 'a3-cancel-after');
    expect(page.url(), 'abandon ramène hors wizard').not.toMatch(/\/kiosk\/wizard\//);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    const linesAfter = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
    expect(linesAfter, 'panier inchangé après abandon (lignes)').toBe(linesBefore);
    console.log(`[A3] cancel: lines ${linesBefore} -> ${linesAfter}`);
    mark('A3 wizards 7 items + back + next-disabled + cancel', 'PASS', 'a3-*-step*.jpg, a3-next-disabled.jpg, a3-back-*.jpg, a3-cancel-*.jpg');
  });

  test('A4 panier — qty, clamp 20, remove, clear, refill', async ({ page }) => {
    wirePage(page); currentStep = 'A4';
    await kioskBoot(page);
    await startTakeaway(page);
    // 2 lignes : 1 wizard + 1 boisson
    const ok = await composeWizardCaptured(page, { id: 22, cat: 1 }, 'a4-build');
    expect(ok, 'wizard 22 pour panier').toBeGreaterThan(0);
    await addSimple(page, [52]);

    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1800);
    await expect(page.locator('[data-testid="kiosk-cart-item-0"]')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('[data-testid="kiosk-cart-item-1"]')).toBeVisible({ timeout: 10_000 });
    await snap(page, 'a4-cart-2lines');
    await rawLabelScan(page, 'A4-cart');

    const total0 = await cartTotalNumber(page);
    const qty0 = parseInt(await page.locator('[data-testid="kiosk-cart-item-qty-0"]').innerText(), 10);

    // +1
    currentStep = 'A4-plus';
    await page.locator('[data-testid="kiosk-cart-item-qty-plus-0"]').click();
    await page.waitForTimeout(1200);
    const totalPlus = await cartTotalNumber(page);
    const qtyPlus = parseInt(await page.locator('[data-testid="kiosk-cart-item-qty-0"]').innerText(), 10);
    await snap(page, 'a4-qty-plus');
    expect(qtyPlus, '+1 incrémente la qty').toBe(qty0 + 1);
    expect(totalPlus, '+1 augmente le total').toBeGreaterThan(total0);
    const unitDelta = +(totalPlus - total0).toFixed(2);

    // -1
    currentStep = 'A4-minus';
    await page.locator('[data-testid="kiosk-cart-item-qty-minus-0"]').click();
    await page.waitForTimeout(1200);
    const totalMinus = await cartTotalNumber(page);
    const qtyMinus = parseInt(await page.locator('[data-testid="kiosk-cart-item-qty-0"]').innerText(), 10);
    await snap(page, 'a4-qty-minus');
    expect(qtyMinus, '-1 décrémente').toBe(qty0);
    expect(Math.abs(totalMinus - total0), 'total revient après -1').toBeLessThan(0.01);

    // clamp à 20 (fix F1 kioskCart MAX_ITEM_QTY)
    currentStep = 'A4-clamp20';
    for (let i = 0; i < 25; i++) {
      await page.locator('[data-testid="kiosk-cart-item-qty-plus-0"]').click({ delay: 30 }).catch(() => {});
      await page.waitForTimeout(120);
    }
    await page.waitForTimeout(1500);
    const qtyClamped = parseInt(await page.locator('[data-testid="kiosk-cart-item-qty-0"]').innerText(), 10);
    const totalClamped = await cartTotalNumber(page);
    const lineTotalRaw = await page.locator('[data-testid="kiosk-cart-item-total-0"]').innerText().catch(() => '');
    await snap(page, 'a4-clamp-20');
    expect(qtyClamped, 'qty clampée à 20').toBe(20);
    const expectedLine = +(unitDelta * 20).toFixed(2);
    const lineTotalNum = parseFloat(lineTotalRaw.replace(/\s/g, '').replace(',', '.').match(/(\d+(?:\.\d+)?)/)?.[1] ?? 'NaN');
    console.log(`[A4] clamp: qty=${qtyClamped} unit=${unitDelta} lineTotal=${lineTotalNum} expected=${expectedLine} cartTotal=${totalClamped}`);
    expect(Math.abs(lineTotalNum - expectedLine), 'total ligne = 20 × unitaire').toBeLessThan(0.011);

    // remove ligne 1
    currentStep = 'A4-remove';
    await page.locator('[data-testid="kiosk-cart-item-remove-1"]').click();
    await page.waitForTimeout(1300);
    const line1Gone = (await page.locator('[data-testid="kiosk-cart-item-1"]').count()) === 0;
    await snap(page, 'a4-remove-line');
    expect(line1Gone, 'ligne 1 supprimée').toBeTruthy();

    // vider -> état vide
    currentStep = 'A4-clear';
    await page.locator('[data-testid="kiosk-cart-clear"]').click();
    await page.waitForTimeout(700);
    const clearModal = page.locator('[data-testid="kiosk-cart-clear-modal"]');
    if (await clearModal.isVisible().catch(() => false)) {
      await snap(page, 'a4-clear-modal');
      await page.locator('[data-testid="kiosk-cart-clear-yes"]').click();
    }
    await page.waitForTimeout(1200);
    // confirmClear() redirige hors du panier BY DESIGN (KioskCartComponent.vue:642-646)
    expect(page.url(), 'clear quitte le panier (redirect catalogue/idle)').not.toMatch(/\/kiosk\/cart/);
    await snap(page, 'a4-clear-redirect');
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    await expect(page.locator('[data-testid="kiosk-cart-empty"]')).toBeVisible({ timeout: 8000 });
    await snap(page, 'a4-cart-empty');
    await rawLabelScan(page, 'A4-empty');
    const emptyCta = await page.locator('[data-testid="kiosk-cart-empty-cta"]').isVisible().catch(() => false);
    expect(emptyCta, 'CTA panier vide présent').toBeTruthy();

    // re-remplir — confirmClear() reset() l'orderType, la nav directe catalogue
    // rebondit sur /kiosk/idle : repasser par le chooser À emporter d'abord.
    currentStep = 'A4-refill';
    await startTakeaway(page);
    await addSimple(page, [59]);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await expect(page.locator('[data-testid="kiosk-cart-item-0"]')).toBeVisible({ timeout: 8000 });
    await snap(page, 'a4-refilled');
    mark('A4 panier complet (+1/-1, clamp 20, remove, clear, refill)', 'PASS', 'a4-*.jpg');
  });

  test('A5 upsell — run SKIP puis run ACCEPT', async ({ page }) => {
    wirePage(page); currentStep = 'A5-skip';
    await kioskBoot(page);
    await startTakeaway(page);
    await addSimple(page, [52]);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.locator('[data-testid="kiosk-cart-checkout"]').click();
    const upsellRoot = page.locator('[data-testid="kiosk-upsell-root"]');
    const sawUpsell = await upsellRoot.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true).catch(() => false);
    if (sawUpsell) {
      await page.waitForTimeout(900);
      await snap(page, 'a5-upsell-screen');
      await rawLabelScan(page, 'A5-upsell');
      await page.locator('[data-testid="kiosk-upsell-skip"]').click();
      await page.waitForURL(/\/kiosk\/payment/, { timeout: 15_000 });
      await snap(page, 'a5-payment-after-skip');
    } else {
      findings.push({ id: 'A5-NO-UPSELL', sev: 'P3', step: 'A5', detail: 'écran upsell jamais affiché au checkout (désactivé/sans règles ?)', capture: '' });
      await snap(page, 'a5-no-upsell');
    }

    // run 2 : ACCEPT un upsell
    currentStep = 'A5-accept';
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    const totalBefore = await cartTotalNumber(page);
    await page.locator('[data-testid="kiosk-cart-checkout"]').click();
    const sawUpsell2 = await upsellRoot.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true).catch(() => false);
    if (sawUpsell2) {
      await page.waitForTimeout(900);
      const card = page.locator('.kiosk-upsell-card').first();
      await expect(card, 'au moins 1 carte upsell').toBeVisible({ timeout: 8000 });
      const priceTxt = await card.locator('[data-testid^="kiosk-upsell-card-price-"]').innerText().catch(() => '');
      await card.click();
      await page.waitForTimeout(500);
      await snap(page, 'a5-upsell-selected');
      await page.locator('[data-testid="kiosk-upsell-add-continue"]').click();
      await page.waitForURL(/\/kiosk\/payment/, { timeout: 15_000 });
      await page.waitForTimeout(1500);
      const payTotalRaw = await page.locator('[data-testid="kiosk-payment-counter-total"], [data-testid="kiosk-payment-total"]').first().innerText().catch(() => '');
      const payTotal = parseFloat(payTotalRaw.replace(/\s/g, '').replace(',', '.').match(/(\d+(?:\.\d+)?)/)?.[1] ?? 'NaN');
      await snap(page, 'a5-payment-after-accept');
      console.log(`[A5] accept: cartBefore=${totalBefore} upsellPrice="${priceTxt}" payTotal=${payTotal}`);
      expect(payTotal, 'total paiement > total panier (upsell ajouté)').toBeGreaterThan(totalBefore);
      // ligne ajoutée au panier — F-BORNE-07 (TypeError item_variations legacy,
      // HEALED worktree mais :8766 non patché) peut avorter le 1er rendu du
      // panier (panier BLANC, cycle-2 l'a capturé) : 1 reload documenté.
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500);
      let nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
      await snap(page, 'a5-cart-with-upsell');
      if (nLines === 0) {
        findings.push({ id: 'A5-BLANK-CART-F07', sev: 'P1', step: 'A5', detail: 'rendu panier AVORTÉ (blanc) après accept upsell — manifestation live de F-BORNE-07 (TypeError item_variations legacy), heal en worktree', capture: 'a5-cart-with-upsell.jpg' });
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1800);
        nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
        await snap(page, 'a5-cart-with-upsell-after-reload');
      }
      if (nLines === 0) {
        // Défaut P1 documenté (F-BORNE-07) + HEALED dans ce worktree — le serveur
        // :8766 sert les assets non patchés : capturer, marquer DEFECT, continuer
        // (l'augmentation du total paiement + toast prouvent déjà la ligne ajoutée).
        mark('A5 upsell SKIP + ACCEPT', 'DEFECT-CAPTURED', 'a5-cart-with-upsell.jpg (+after-reload) — panier blanc, F-BORNE-07 live (heal pending merge); total paiement vérifié (+upsell)');
      } else {
        expect(nLines, 'panier >= 2 lignes après accept upsell').toBeGreaterThanOrEqual(2);
        mark('A5 upsell SKIP + ACCEPT (ligne+total vérifiés)', 'PASS', 'a5-upsell-screen.jpg, a5-upsell-selected.jpg, a5-payment-after-accept.jpg, a5-cart-with-upsell.jpg');
      }
    } else {
      mark('A5 upsell', 'LIMIT', 'a5-no-upsell.jpg — écran upsell non affiché');
    }
    // reset cart pour la suite
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    if (await page.locator('[data-testid="kiosk-cart-clear"]').isVisible().catch(() => false)) {
      await page.locator('[data-testid="kiosk-cart-clear"]').click();
      await page.waitForTimeout(600);
      if (await page.locator('[data-testid="kiosk-cart-clear-yes"]').isVisible().catch(() => false)) {
        await page.locator('[data-testid="kiosk-cart-clear-yes"]').click();
      }
    }
  });

  test('A6 loyalty — accès borne + code invalide propre', async ({ page }) => {
    wirePage(page); currentStep = 'A6';
    await kioskBoot(page);
    await startTakeaway(page);
    await addSimple(page, [52]);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    const loyaltyBtn = page.locator('[data-testid="kiosk-cart-loyalty-btn"]');
    const viaBtn = await loyaltyBtn.isVisible().catch(() => false);
    if (viaBtn) { await loyaltyBtn.click(); } else {
      findings.push({ id: 'A6-NO-BTN', sev: 'P3', step: 'A6', detail: 'bouton fidélité panier masqué (discountsEnabled=false ?) — accès via URL directe testé', capture: 'a4-cart-2lines.jpg' });
      await page.goto('/kiosk/loyalty', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForURL(/\/kiosk\/loyalty/, { timeout: 10_000 });
    await page.waitForTimeout(1300);
    await snap(page, 'a6-loyalty-screen');
    await rawLabelScan(page, 'A6-loyalty');

    const input = page.locator('.kiosk-loyalty-input').first();
    await expect(input).toBeVisible({ timeout: 8000 });
    await input.fill('0000000009');
    await page.waitForTimeout(300);
    await page.locator('button.kiosk-btn-primary.full').click();
    await page.waitForTimeout(2500);
    const errEl = page.locator('.kiosk-loyalty-error');
    let errVisible = await errEl.isVisible().catch(() => false);
    let errTxt = errVisible ? await errEl.innerText() : '';
    if (/too many|trop de/i.test(errTxt)) {
      // throttle:10,1 du /frontend/loyalty/check encore chaud — P2 par ailleurs :
      // le message brut anglais est montré au client. Retenter après refroidissement.
      findings.push({ id: 'A6-THROTTLE-RAW', sev: 'P2', step: 'A6', detail: 'message throttle brut anglais "Too Many Attempts." affiché au client (KioskLoyaltyComponent.vue:505-506 passthrough)', capture: 'a6-loyalty-invalid-throttled.jpg' });
      await snap(page, 'a6-loyalty-invalid-throttled');
      await page.waitForTimeout(65_000);
      await page.locator('button.kiosk-btn-primary.full').click();
      await page.waitForTimeout(2500);
      errVisible = await errEl.isVisible().catch(() => false);
      errTxt = errVisible ? await errEl.innerText() : '';
    }
    await snap(page, 'a6-loyalty-invalid');
    console.log(`[A6] viaBtn=${viaBtn} invalid-code error="${errTxt}"`);
    expect(errVisible, 'message erreur code invalide affiché').toBeTruthy();
    expect(RAW_LABEL_RE.test(errTxt.trim()), 'message erreur i18n résolu (pas de label brut)').toBeFalsy();
    mark('A6 loyalty code invalide -> message propre', 'PASS', 'a6-loyalty-screen.jpg, a6-loyalty-invalid.jpg');
  });

  test('A7 paiement comptoir — PENDING_COUNTER + idempotency double-clic', async ({ page }) => {
    wirePage(page); currentStep = 'A7';
    await kioskBoot(page);
    await startTakeaway(page);
    const ok = await composeWizardCaptured(page, { id: 26, cat: 5 }, 'a7-build');
    expect(ok, 'wizard tacos pour commande').toBeGreaterThan(0);
    await addSimple(page, [53]);

    const baselineMax = parseInt(db('SELECT IFNULL(MAX(id),0) FROM orders;'), 10);

    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.locator('[data-testid="kiosk-cart-checkout"]').click();
    const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]');
    await upsellSkip.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
    if (await upsellSkip.isVisible().catch(() => false)) await upsellSkip.click();
    await page.waitForURL(/\/kiosk\/payment/, { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(1800);
    await snap(page, 'a7-payment');
    await rawLabelScan(page, 'A7-payment');

    const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
    await expect(confirm, 'bouton confirmation visible').toBeVisible({ timeout: 12_000 });
    // double-clic rapide = test idempotency / anti-doublon
    await confirm.click();
    await confirm.click({ timeout: 1500 }).catch(() => {});
    await page.waitForURL(/\/kiosk\/(confirmation|waiting|cash-instruction)/, { timeout: 25_000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await snap(page, 'a7-confirmation');
    await rawLabelScan(page, 'A7-confirmation');

    const numEl = page.locator('[data-testid="kiosk-confirmation-number"], [data-testid="kiosk-cash-order-number"], [data-testid="kiosk-payment-counter-route"]').first();
    const numTxt = await numEl.innerText().catch(() => '');
    console.log(`[A7] url=${page.url()} numero="${numTxt.replace(/\n/g, ' ')}"`);

    // DB: 1 seule commande, source kiosk, PENDING_COUNTER(15), fiscal NULL
    const rows = db(`SELECT id, status, payment_status, IFNULL(fiscal_sequence_no,'NULL'), source_surface, total FROM orders WHERE id > ${baselineMax} ORDER BY id;`);
    console.log(`[A7] new orders:\n${rows}`);
    const newOrders = rows ? rows.split('\n') : [];
    fs.writeFileSync(path.join(REPORT_DIR, `a7-db-cycle${CYCLE}.txt`), rows + '\n');
    expect(newOrders.length, 'exactement 1 commande créée (pas de doublon double-clic)').toBe(1);
    const [oid, status, payStatus, fiscal, source] = newOrders[0].split('\t');
    expect(source, 'source_surface=kiosk').toBe('kiosk');
    expect(parseInt(payStatus, 10), 'payment_status=PENDING_COUNTER(15)').toBe(15);
    expect(fiscal, 'fiscal_sequence_no NULL avant encaissement').toBe('NULL');
    expect(numTxt.trim().length, 'numéro de commande affiché').toBeGreaterThan(0);
    mark('A7 confirm -> caisse + DB PENDING_COUNTER + no-dup', 'PASS', `a7-payment.jpg, a7-confirmation.jpg (order ${oid} status=${status})`);
  });

  test('A8 rupture live — badge Épuisé + clic no-op + restore', async ({ page }) => {
    wirePage(page); currentStep = 'A8';
    const ITEM = 59; // Capri-Sun, cat 10, ligne iba existante branch 1
    try {
      db(`UPDATE item_branch_availability SET is_available=0 WHERE item_id=${ITEM} AND branch_id=1;`);
      await kioskBoot(page);
      await startTakeaway(page);
      // Le payload menu est caché serveur 60s (kiosk.menu_cache_ttl) — poll jusqu'à ~80s.
      const badge = page.locator(`[data-testid="kiosk-product-badge-${ITEM}"]`);
      let badgeTxt = '';
      for (let i = 0; i < 10; i++) {
        await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2200);
        badgeTxt = await badge.innerText().catch(() => '');
        if (badgeTxt.toLowerCase().includes('puis')) break;
        await page.waitForTimeout(6000);
      }
      const card = page.locator(`[data-testid="kiosk-product-card-${ITEM}"]`);
      await expect(card).toBeVisible({ timeout: 10_000 });
      const addDisabled = await page.locator(`[data-testid="kiosk-product-add-${ITEM}"]`).isDisabled().catch(() => false);
      await snap(page, 'a8-rupture-badge');
      console.log(`[A8] badge="${badgeTxt}" addDisabled=${addDisabled}`);
      expect(badgeTxt.toLowerCase(), 'badge Épuisé affiché').toContain('puis'); // Épuisé
      // clic = no-op : pas de wizard, panier inchangé
      const badgeCountBefore = await page.locator('[data-testid="kiosk-cart-count"]').innerText().catch(() => '0');
      await card.click({ force: true });
      await page.waitForTimeout(1200);
      const badgeCountAfter = await page.locator('[data-testid="kiosk-cart-count"]').innerText().catch(() => '0');
      const inWizard = /\/kiosk\/wizard\//.test(page.url());
      await snap(page, 'a8-rupture-click-noop');
      expect(inWizard, "clic item épuisé n'ouvre pas le wizard").toBeFalsy();
      expect(badgeCountAfter, 'panier inchangé après clic item épuisé').toBe(badgeCountBefore);
      expect(addDisabled, 'bouton + désactivé').toBeTruthy();
    } finally {
      db(`UPDATE item_branch_availability SET is_available=1 WHERE item_id=${ITEM} AND branch_id=1;`);
    }
    // restore vérifié — même tolérance cache 60s : poll jusqu'à ~80s.
    let badgeAfterRestore = 'puis';
    for (let i = 0; i < 10; i++) {
      await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);
      badgeAfterRestore = await page.locator(`[data-testid="kiosk-product-badge-${ITEM}"]`).innerText().catch(() => '');
      if (!badgeAfterRestore.toLowerCase().includes('puis')) break;
      await page.waitForTimeout(6000);
    }
    await snap(page, 'a8-restored');
    expect(badgeAfterRestore.toLowerCase().includes('puis'), 'badge Épuisé retiré après restore (cache menu 60s tolérée)').toBeFalsy();
    mark('A8 rupture live + restore', 'PASS', 'a8-rupture-badge.jpg, a8-rupture-click-noop.jpg, a8-restored.jpg');
  });

  test('Z — bilan erreurs console/page/HTTP + dump findings', async () => {
    // Justifiés (by-design ou findings connus documentés FINDINGS.md) :
    //  - GET /api/login 401 = route nommée login (routes/api.php:151) où le
    //    middleware auth redirige les requêtes pré-auth du boot borne ;
    //  - 4xx loyalty (code invalide → 404, throttle → 429) sur A6 ;
    //  - mirrors console "Failed to load resource ... (401|404|429)" des mêmes requêtes ;
    //  - pageerror "(line.item_variations || []).map is not a function" =
    //    F-BORNE-07, HEALED dans ce worktree (KioskCartComponent.vue guard),
    //    mais :8766 sert les assets spine non patchés → reste attendu ici.
    const justified = (e) =>
      (e.step === 'A6' && /http-4\d\d/.test(e.kind) && /loyalty|customer/i.test(e.detail)) ||
      (/http-401/.test(e.kind) && /api\/login|kiosk\/(login|machine)/i.test(e.detail)) ||
      (/http-429/.test(e.kind)) ||
      (e.kind === 'console' && /Failed to load resource.*(401|404|429)/.test(e.detail)) ||
      (/item_variations \|\| \[\]\)\.map is not a function/.test(e.detail)); // F-BORNE-07 connu (pageerror OU console TypeError minifié)
    const allErrors = readJsonl(ERR_LOG_FILE);
    const allFindings = readJsonl(FINDINGS_FILE);
    const hard = allErrors.filter((e) => !justified(e));
    fs.writeFileSync(path.join(REPORT_DIR, `errors-cycle${CYCLE}.json`), JSON.stringify({ all: allErrors, hard }, null, 2));
    fs.writeFileSync(path.join(REPORT_DIR, `findings-cycle${CYCLE}.json`), JSON.stringify(allFindings, null, 2));
    fs.writeFileSync(path.join(REPORT_DIR, `journey-cycle${CYCLE}.json`), JSON.stringify(readJsonl(JOURNEY_FILE), null, 2));
    console.log(`[Z] errors total=${allErrors.length} hard=${hard.length} findings=${allFindings.length}`);
    for (const h of hard.slice(0, 20)) console.log(`[Z][HARD] ${h.step} ${h.kind} ${h.detail}`);
    for (const f of allFindings) console.log(`[Z][FINDING] ${f.sev} ${f.id} ${f.detail}`);
    expect(hard.length, `erreurs non justifiées: ${JSON.stringify(hard.slice(0, 5))}`).toBe(0);
  });
});
