// FoodKing Mobile App E2E — audit Wave B (Menu + 13 cats + 3 wizard P0 flows)
// 2026-05-11. Mobile app served on http://127.0.0.1:8081/index.html.
//
// Scope (~36 states):
//   01     home authed entry
//   02-14  menu screen + 13 filter chips (one per category)
//   15-25  Tacos XXL full 9-step cascade (menu='full' + drink + cheddar + bbq)
//   26-31  Le Terminator sandwich (menu='none')
//   32-36  Cheese Burger (menu='none')
//
// Auth bootstrap : storage.js uses NS='lecayenne.'. Two flags decide boot:
//   - lecayenne.onboarding_seen → true
//   - lecayenne.auth            → { token, phone, user_id }
// If isAuthenticated() returns true at App mount, initialScreen='home'.
//
// Critical pricing targets verified against mobile/data/menu.js priceFor :
//   Tacos XXL = 12.50 + 0.50 (2nd sauce) + 1.00 (Œuf supplement)
//             + 3.00 (menu 'full') + 1.00 (Cheddar fondu) + 0 (1st frites sauce)
//             = 18.00 €
//   Terminator = 9.00 + 0 (1 sauce free) + sans formule = 9.00 €
//   Cheese Burger = 6.00 + 0 (1 sauce free) + sans formule = 6.00 €
//
// Gotchas baked in :
//   - WizardCTA "Suivant" uses aria-disabled="true" (not the disabled attr).
//     Each transition gates on aria-disabled='false' before clicking.
//   - Home featured card shows "Tacos XXL" too; the menu list card uses the
//     full name "Tacos XXL (4 Viandes)" which is the disambiguating handle.
//   - Mobile viewport must be 390×844 (iOS frame), NOT kiosk dimensions.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-mobile-wave-B');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

// Category chips order (after the implicit "Tout" chip). Index 0 = Tout, then
// the 13 categories follow window.CATS order (sort 1..13). Chip accessible
// names include the leading emoji + space ("🍳 Ojja", "🥖 Nos Sandwichs", ...)
// so the regex must NOT anchor with ^…$.
const CATS = [
  { state: '03-cat-tacos',          chip: /Nos Tacos/i },
  { state: '04-cat-sandwichs',      chip: /Nos Sandwichs/i },
  { state: '05-cat-burgers',        chip: /Nos Burgers/i },
  { state: '06-cat-assiettes',      chip: /Nos Assiettes/i },
  { state: '07-cat-ojja',           chip: /Ojja/i },
  { state: '08-cat-omelettes',      chip: /Omelettes/i },
  { state: '09-cat-salades',        chip: /Nos Salades/i },
  { state: '10-cat-wings',          chip: /Poulet croustillant/i },
  { state: '11-cat-menus-enfants',  chip: /Nos Menus Enfants/i },
  { state: '12-cat-frites',         chip: /Frites/i },
  { state: '13-cat-desserts',       chip: /Nos Desserts/i },
  { state: '14-cat-boissons',       chip: /Nos Boissons/i },
];

// Helper : guarantee aria-disabled='false' on the wizard CTA before tap.
async function clickNext(page, name = /Suivant|Ajouter au panier/i) {
  const cta = page.locator('button.lc-btn').filter({ hasText: name }).last();
  await expect(cta).toBeVisible({ timeout: 10_000 });
  await expect(cta).toHaveAttribute('aria-disabled', 'false', { timeout: 10_000 });
  await cta.click();
  // Small settle for React re-render + step focus transition (useEffect on heading).
  await page.waitForTimeout(180);
}

// Helper : extract the price from the wizard sticky CTA (last span = currency).
async function readCtaPrice(page) {
  return await page.evaluate(() => {
    const btns = document.querySelectorAll('button.lc-btn');
    const last = btns[btns.length - 1];
    if (!last) return null;
    const spans = last.querySelectorAll('span');
    return spans[spans.length - 1].textContent.trim();
  });
}

test.describe('Mobile app Wave B — Menu + categories + wizard P0', () => {
  test.setTimeout(360_000);

  test('Wave B : 36 sequential states (menu + 13 cats + 3 wizards)', async ({ browser }) => {
    // Mobile viewport — match the iOS frame target documented at the top of
    // screens-item-steps.jsx ("adapted to a 390×844 mobile viewport").
    const ctx = await browser.newContext({
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
    });

    // Pre-seed auth + onboarding so the App boots straight to 'home'.
    await ctx.addInitScript(() => {
      localStorage.setItem('lecayenne.onboarding_seen', JSON.stringify(true));
      localStorage.setItem('lecayenne.auth', JSON.stringify({
        token: 'mock-v0',
        phone: '+33642799884',
        user_id: 12345,
      }));
    });

    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const observations = [];

    try {
      // ---------------------------------------------------------------
      // STATE 01 — home entry, authed
      // ---------------------------------------------------------------
      await page.goto(MOBILE_URL, { waitUntil: 'domcontentloaded' });
      // App boot is gated on Babel transforming all <script type="text/babel">
      // children — wait until ScreenHome renders.
      await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible({ timeout: 30_000 });
      await page.waitForTimeout(400);
      observations.push('state01: home rendered (boot via lecayenne.auth seed → ScreenHome)');
      await snap('01-home-entry');

      // ---------------------------------------------------------------
      // STATE 02 — menu screen via MENU tab (lc-tab nth(1))
      // ---------------------------------------------------------------
      await page.locator('.lc-tabs .lc-tab').nth(1).click();
      await expect(page.locator('[data-screen-label="08 Menu"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(300);
      observations.push('state02: menu rendered, all 13 cats + items list visible');
      await snap('02-menu-screen');

      // ---------------------------------------------------------------
      // STATES 03-14 — filter chips, one per category
      // ---------------------------------------------------------------
      for (const cat of CATS) {
        // Filter chips render as <button> with label = `${icon} ${label}`.
        // Scope to menu screen to avoid hitting any future tab match.
        const chip = page.locator('[data-screen-label="08 Menu"]').getByRole('button', { name: cat.chip }).first();
        await expect(chip).toBeVisible({ timeout: 5_000 });
        await chip.click();
        await page.waitForTimeout(250);
        await snap(cat.state);
      }
      // Reset to "Tout" so next item navigation (Tacos XXL) is unaffected by
      // a stale filter that might hide its section.
      await page.locator('[data-screen-label="08 Menu"]').getByRole('button', { name: /^Tout$/i }).first().click();
      await page.waitForTimeout(200);

      // ===============================================================
      // WIZARD P0 #1 — Tacos XXL (4 viandes) full 9-step cascade
      // Target total at recap : 18,00 €
      // Steps : viandes → sauce → crudites → supplements → menu →
      //         drink → fritesStyle → fritesSauce → recap
      // ===============================================================
      // Open the menu-list card with the disambiguating "(4 Viandes)" suffix.
      const tacosCard = page.locator('[data-screen-label="08 Menu"]').getByText('Tacos XXL (4 Viandes)', { exact: false }).first();
      await expect(tacosCard).toBeVisible({ timeout: 5_000 });
      await tacosCard.click();
      // Wizard mounts with viandes step (template 'tacos' + item.viandes=4 > 0).
      await expect(page.locator('[data-screen-label^="09 Item Wizard"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(300);

      // STATE 15 — viandes empty (CTA disabled)
      observations.push('state15: viandes step rendered, expect aria-disabled=true on Suivant');
      const ctaInit = page.locator('button.lc-btn').filter({ hasText: /Suivant/i }).last();
      await expect(ctaInit).toHaveAttribute('aria-disabled', 'true');
      await snap('15-tacos-step-viandes-empty');

      // STATE 16 — pick 4 meats (CTA enabled)
      for (const meat of ['Merguez', 'Kefta', 'Cordon Bleu', 'Tenders']) {
        // ChoiceCard renders ariaRole='checkbox' for meats.
        await page.getByRole('checkbox', { name: new RegExp(meat, 'i') }).first().click();
      }
      await page.waitForTimeout(150);
      await expect(ctaInit).toHaveAttribute('aria-disabled', 'false');
      await snap('16-tacos-step-viandes-full');
      await clickNext(page);

      // STATE 17 — sauce : pick Ketchup + Mayonnaise (2nd = +0,50€)
      await expect(page.locator('[data-screen-label="09 Item Wizard Sauce"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /^Ketchup$/i }).first().click();
      await page.getByRole('checkbox', { name: /^Mayonnaise$/i }).first().click();
      await page.waitForTimeout(150);
      await snap('17-tacos-step-sauce');
      await clickNext(page);

      // STATE 18 — crudités : default ON, remove Oignon
      await expect(page.locator('[data-screen-label="09 Item Wizard Crudités"]')).toBeVisible({ timeout: 5_000 });
      // Crudités cards use ariaRole='checkbox' with accent green.
      await page.getByRole('checkbox', { name: /Oignon/i }).first().click();
      await page.waitForTimeout(150);
      await snap('18-tacos-step-crudites');
      await clickNext(page);

      // STATE 19 — suppléments : toggle Œuf (+1€)
      await expect(page.locator('[data-screen-label="09 Item Wizard Suppléments"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /^Œuf/i }).first().click();
      await page.waitForTimeout(150);
      await snap('19-tacos-step-supplements');
      await clickNext(page);

      // STATE 20 — menu : pick "Menu complet" radio (full)
      await expect(page.locator('[data-screen-label="09 Item Wizard Faire un menu"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('radio', { name: /Menu complet/i }).first().click();
      await page.waitForTimeout(150);
      await snap('20-tacos-step-menu');
      await clickNext(page);

      // STATE 21 — drink : Coca-Cola 33cl
      await expect(page.locator('[data-screen-label="09 Item Wizard Boisson"]')).toBeVisible({ timeout: 5_000 });
      // Drink card has emoji + name in same ChoiceCard; use getByText scoped to radiogroup.
      await page.getByRole('radio', { name: /Coca-Cola 33cl/i }).first().click();
      await page.waitForTimeout(150);
      await snap('21-tacos-step-drink');
      await clickNext(page);

      // STATE 22 — frites style : Cheddar fondu (+1€)
      await expect(page.locator('[data-screen-label="09 Item Wizard Style de frites"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('radio', { name: /^Cheddar fondu/i }).first().click();
      await page.waitForTimeout(150);
      await snap('22-tacos-step-fritesStyle');
      await clickNext(page);

      // STATE 23 — frites sauce : Barbecue (1st free)
      await expect(page.locator('[data-screen-label="09 Item Wizard Sauce frites"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /^Barbecue$/i }).first().click();
      await page.waitForTimeout(150);
      await snap('23-tacos-step-fritesSauce');
      await clickNext(page);

      // STATE 24 — recap : composition complète + total = 18,00 €
      await expect(page.locator('[data-screen-label="09 Item Wizard Récapitulatif"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);
      const tacosPrice = await readCtaPrice(page);
      observations.push(`state24: tacos recap CTA price="${tacosPrice}" (expect 18,00 €)`);
      expect(tacosPrice).toMatch(/18,00\s*€/);
      await snap('24-tacos-step-recap');

      // STATE 25 — add to cart, land on cart screen (1 line @ 18 €)
      await clickNext(page, /Ajouter au panier/i);
      await expect(page.locator('[data-screen-label="10 Cart"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(400);
      observations.push('state25: tacos added → cart screen (10 Cart)');
      await snap('25-tacos-cart-after-add');

      // ===============================================================
      // WIZARD P0 #2 — Le Terminator (sandwich, 2 viandes, sans formule)
      // Target total at recap : 9,00 €
      // ===============================================================
      // Cart screen hides the bottom TabBar (NO_TAB_SCREENS in index.html
      // includes 'cart'). Reload the app — auth + onboarding seed restore
      // initialScreen='home' and cart persists via storage.setCart, but for
      // this audit we only care about reaching the menu list again.
      await page.goto(MOBILE_URL, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible({ timeout: 15_000 });
      await page.locator('.lc-tabs .lc-tab').nth(1).click();
      await expect(page.locator('[data-screen-label="08 Menu"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);
      const terminatorCard = page.locator('[data-screen-label="08 Menu"]').getByText('Le Terminator', { exact: false }).first();
      await expect(terminatorCard).toBeVisible({ timeout: 5_000 });
      await terminatorCard.click();
      await expect(page.locator('[data-screen-label^="09 Item Wizard"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);

      // STATE 26 — viandes : pick 2 meats
      await expect(page.locator('[data-screen-label="09 Item Wizard Viandes"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /^Kefta$/i }).first().click();
      await page.getByRole('checkbox', { name: /Viande Hachée/i }).first().click();
      await page.waitForTimeout(150);
      await snap('26-terminator-step-viandes');
      await clickNext(page);

      // STATE 27 — sauce : Algérienne
      await expect(page.locator('[data-screen-label="09 Item Wizard Sauce"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /Algérienne/i }).first().click();
      await page.waitForTimeout(150);
      await snap('27-terminator-step-sauce');
      await clickNext(page);

      // STATE 28 — crudites : keep defaults
      await expect(page.locator('[data-screen-label="09 Item Wizard Crudités"]')).toBeVisible({ timeout: 5_000 });
      await snap('28-terminator-step-crudites');
      await clickNext(page);

      // STATE 29 — suppléments : skip (optional)
      await expect(page.locator('[data-screen-label="09 Item Wizard Suppléments"]')).toBeVisible({ timeout: 5_000 });
      await snap('29-terminator-step-supplements');
      await clickNext(page);

      // STATE 30 — menu : pick "Sans formule"
      await expect(page.locator('[data-screen-label="09 Item Wizard Faire un menu"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('radio', { name: /Sans formule/i }).first().click();
      await page.waitForTimeout(150);
      await snap('30-terminator-step-menu-sans-formule');
      await clickNext(page);

      // STATE 31 — recap : total = 9,00 €
      await expect(page.locator('[data-screen-label="09 Item Wizard Récapitulatif"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);
      const terminatorPrice = await readCtaPrice(page);
      observations.push(`state31: terminator recap CTA price="${terminatorPrice}" (expect 9,00 €)`);
      expect(terminatorPrice).toMatch(/9,00\s*€/);
      await snap('31-terminator-step-recap');

      // ===============================================================
      // WIZARD P0 #3 — Cheese Burger (burger template, no viandes step)
      // Target total at recap : 6,00 €
      // Steps : sauce → crudites → supplements → menu → recap
      // ===============================================================
      // 'item' screen hides the bottom TabBar — same trick as state 25→26.
      // Reload to land back on 'home' (auth seed persists), then go to menu.
      await page.goto(MOBILE_URL, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible({ timeout: 15_000 });
      await page.locator('.lc-tabs .lc-tab').nth(1).click();
      await expect(page.locator('[data-screen-label="08 Menu"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);
      const cheeseCard = page.locator('[data-screen-label="08 Menu"]').getByText('Cheese Burger', { exact: false }).first();
      await expect(cheeseCard).toBeVisible({ timeout: 5_000 });
      await cheeseCard.click();
      await expect(page.locator('[data-screen-label^="09 Item Wizard"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);

      // STATE 32 — sauce (no viandes step on burger template)
      await expect(page.locator('[data-screen-label="09 Item Wizard Sauce"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('checkbox', { name: /Samouraï/i }).first().click();
      await page.waitForTimeout(150);
      await snap('32-burger-step-sauce');
      await clickNext(page);

      // STATE 33 — crudites
      await expect(page.locator('[data-screen-label="09 Item Wizard Crudités"]')).toBeVisible({ timeout: 5_000 });
      await snap('33-burger-step-crudites');
      await clickNext(page);

      // STATE 34 — supplements (skip)
      await expect(page.locator('[data-screen-label="09 Item Wizard Suppléments"]')).toBeVisible({ timeout: 5_000 });
      await snap('34-burger-step-supplements');
      await clickNext(page);

      // STATE 35 — menu : "Sans formule"
      await expect(page.locator('[data-screen-label="09 Item Wizard Faire un menu"]')).toBeVisible({ timeout: 5_000 });
      await page.getByRole('radio', { name: /Sans formule/i }).first().click();
      await page.waitForTimeout(150);
      await snap('35-burger-step-menu-sans-formule');
      await clickNext(page);

      // STATE 36 — recap : 6,00 €
      await expect(page.locator('[data-screen-label="09 Item Wizard Récapitulatif"]')).toBeVisible({ timeout: 5_000 });
      await page.waitForTimeout(250);
      const burgerPrice = await readCtaPrice(page);
      observations.push(`state36: cheese burger recap CTA price="${burgerPrice}" (expect 6,00 €)`);
      expect(burgerPrice).toMatch(/6,00\s*€/);
      await snap('36-burger-step-recap');

      // Emit observation log for adversarial supervisor.
      console.log('\n[wave-B observations]\n  ' + observations.join('\n  '));
    } finally {
      dispose();
      await ctx.close();
    }
  });
});
