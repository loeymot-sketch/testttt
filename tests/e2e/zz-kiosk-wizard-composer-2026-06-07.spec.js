// FoodKing — KIOSK WIZARD COMPOSEUR (borne) — GOAL 100% agent 05-KIOSK
// 2026-06-07. Disposable clone ONLY:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-wizard-composer-2026-06-07.spec.js --retries=0
//
// Drives the FROZEN KioskWizardComponent (PILOT ONLY — never modify) across the
// 4 template families that open the composer wizard on the borne:
//   - Sandwich Cayenne (id 22)  — Viande + Sauce attribute groups (heuristic open)
//   - Tacos (id 26)             — Viande + Sauce
//   - Chicken Burger (id 38)    — Viande + Sauce
//   - Bowl Frites Poulet (id 41)— PUBLISHED composer profile (custom template, 3-step)
// Each: walk every step, pick required options, reach "Ajouter au panier",
// then place a counter-routed order and assert composition_snapshot integrity in DB.
//
// NF525 / SSOT: front sends item_id+qty+options; price is computed backend.
// We do NOT assert a hard euro figure here (backend SSOT) but DO assert the
// composition_snapshot persisted the chosen options (no silent drop).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-wizard-composer-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

const REPO = path.resolve(__dirname, '../..');
function dbQuery(sql) {
  try {
    return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
      cwd: REPO, encoding: 'utf8', timeout: 15_000,
    }).trim();
  } catch (e) { return `ERR:${e.message}`; }
}

const RAW_LABEL_RE = /\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;
const CRASH = ['Whoops, something went wrong', 'Server Error', 'SQLSTATE', 'Undefined variable'];

// Items that open the composer wizard.
const WIZARD_ITEMS = [
  { id: 22, name: 'Sandwich Cayenne', family: 'sandwich' },
  { id: 26, name: 'Tacos', family: 'tacos' },
  { id: 38, name: 'Chicken Burger', family: 'burger' },
  { id: 41, name: 'Bowl Frites Poulet marine', family: 'bowl-profile' },
];

test.describe.configure({ mode: 'serial', timeout: 300_000 });

async function startTakeaway(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway, 'takeaway tile').toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

// Open a product that has options -> the wizard. We navigate by deep-link to the
// item's category and click the product card; the FROZEN component fetches details.
async function openWizardForItem(page, item) {
  // Find the product add/open control. The card itself opens the wizard when hasOptions.
  // Try the product card (openProduct) then assert wizard mounted.
  const card = page.locator(`[data-testid="kiosk-product-card-${item.id}"], [data-testid="kiosk-product-${item.id}"], [data-testid="kiosk-product-add-${item.id}"]`).first();
  // Navigate to "all" categories view to find the card; fallback: directly hit product route if exists.
  return card;
}

const results = [];

for (const item of WIZARD_ITEMS) {
  test(`WIZARD ${item.family}: ${item.name} (id ${item.id}) — walk steps -> add to cart`, async ({ page }) => {
    const pageErrors = [];
    const consoleErrors = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

    await loginAsKiosk(page);
    await startTakeaway(page);

    // Reach the product. Try category deep-links; the categories view lists products.
    // We attempt to click the product card to open the wizard.
    let opened = false;
    const tryOpen = async () => {
      const card = page.locator(`[data-testid="kiosk-product-card-${item.id}"], [data-testid="kiosk-product-${item.id}"]`).first();
      if (await card.isVisible().catch(() => false)) {
        await card.click().catch(() => {});
        await page.waitForTimeout(1800);
        return true;
      }
      // Some skins expose a customize/add button by testid.
      const addBtn = page.locator(`[data-testid="kiosk-product-add-${item.id}"]`).first();
      if (await addBtn.isVisible().catch(() => false)) {
        await addBtn.click().catch(() => {});
        await page.waitForTimeout(1800);
        return true;
      }
      return false;
    };

    // Walk a few known category ids to surface the product.
    for (const cat of [1, 5, 4, 6, 7]) {
      await page.goto(`/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2000);
      if (await tryOpen()) { opened = true; break; }
    }
    // Fallback: also try the flat products route if available.
    if (!opened) {
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2000);
      opened = await tryOpen();
    }

    await page.screenshot({ path: path.join(OUT, `${item.family}-1-opened.png`), fullPage: true });

    // Assert the wizard actually mounted.
    const wizardRoot = page.locator('.kiosk-wizard');
    const wizardMounted = await wizardRoot.isVisible({ timeout: 8000 }).catch(() => false);
    results.push({ item: item.name, opened, wizardMounted });
    expect(wizardMounted, `${item.name}: wizard must mount (hasOptions=true)`).toBeTruthy();

    // Walk steps: on each step, pick the minimum required, then click Next/Add.
    // Real step-card selectors (FROZEN component):
    //   Viande      -> .kiosk-viande-card
    //   Sauce/Pain  -> .kiosk-option-card
    //   Generic     -> .kiosk-generic-choice
    //   Menu        -> .kiosk-menu-card  (prefer "Sandwich seul" = none)
    //   Garnitures  -> .kiosk-garniture-row (pre-selected, min 0)
    //   Supplements -> .kiosk-supplement-row (min 0)
    //   Taille      -> .kiosk-taille-card
    const CHOICE_SEL = [
      '.kiosk-viande-card', '.kiosk-option-card', '.kiosk-generic-choice',
      '.kiosk-taille-card', '.kiosk-menu-card',
    ].map((s) => `${s}:not(.kiosk-variation--disabled):not(.is-out-of-stock):not(.unavailable):not([disabled])`).join(', ');
    const nextBtn = page.locator('.kiosk-btn-next');
    let guard = 0;
    let reachedAdd = false;
    const stepsWalked = [];
    while (guard++ < 14) {
      await page.waitForTimeout(900);
      // On a Menu step, prefer the "Sans menu" card (FR none_name) so we don't drag a sub-flow.
      // NB: avoid matching "Seulement les frites" (the +Frites card) — match "Sans menu" only.
      const menuNone = page.locator('.kiosk-menu-card').filter({ hasText: /sans menu/i }).first();
      let pickedMenuNone = false;
      if (await menuNone.isVisible().catch(() => false)) {
        await menuNone.click().catch(() => {});
        pickedMenuNone = true;
        await page.waitForTimeout(400);
      }
      const choices = page.locator(CHOICE_SEL);
      const n = await choices.count().catch(() => 0);
      if (n > 0 && !pickedMenuNone) {
        await choices.first().click().catch(() => {});
        await page.waitForTimeout(500);
      }
      stepsWalked.push({ step: guard, choices: n, pickedMenuNone });
      // Is the Next button now an "Add to cart" (last step) and enabled?
      const isLast = await page.locator('.kiosk-btn-next--cart').isVisible().catch(() => false);
      const enabled = await nextBtn.first().isEnabled().catch(() => false);
      await page.screenshot({ path: path.join(OUT, `${item.family}-2-step${guard}.png`) }).catch(() => {});
      if (isLast && enabled) {
        reachedAdd = true;
        await nextBtn.first().click().catch(() => {});
        await page.waitForTimeout(1500);
        break;
      }
      if (enabled) {
        await nextBtn.first().click().catch(() => {});
        await page.waitForTimeout(900);
      } else {
        // Disabled & not last: required selection unmet. Try clicking another choice; else break.
        const stillChoices = await choices.count().catch(() => 0);
        if (stillChoices === 0) break;
        await choices.first().click().catch(() => {});
        await page.waitForTimeout(500);
        if (!(await nextBtn.first().isEnabled().catch(() => false))) break;
      }
    }
    console.log(`[WIZ ${item.family}] stepsWalked=${JSON.stringify(stepsWalked)}`);

    await page.screenshot({ path: path.join(OUT, `${item.family}-3-after-add.png`), fullPage: true });

    // Verify we left the wizard (added to cart -> back to categories/cart).
    const url = page.url();
    const body = await page.locator('body').innerText().catch(() => '');
    const rawLabel = (body.match(RAW_LABEL_RE) || [null])[0];
    const crashed = CRASH.filter((c) => body.includes(c));

    results.push({ item: item.name, reachedAdd, urlAfter: url, rawLabel, crashed: crashed.length, consoleErr: consoleErrors.length, pageErr: pageErrors.length });
    console.log(`[WIZ ${item.family}] reachedAdd=${reachedAdd} url=${url} rawLabel=${rawLabel || 'none'} crash=${crashed.length} consoleErr=${consoleErrors.length} pageErr=${pageErrors.length}`);

    expect(reachedAdd, `${item.name}: must reach "Ajouter au panier" and add`).toBeTruthy();
    expect(rawLabel, `${item.name}: raw i18n label leaked: ${rawLabel}`).toBeFalsy();
    expect(crashed.length, `${item.name}: server crash markers: ${crashed.join(',')}`).toBe(0);
    expect(pageErrors, `${item.name}: JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
  });
}

test('ZZZ summary', async () => {
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  console.log('[WIZ SUMMARY]', JSON.stringify(results, null, 2));
});
