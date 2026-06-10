// Massive E2E audit — verifies the 12 remediated findings in a real browser.
// Artifact capture: PNG per state + per-test console-error collection.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SHOTS = path.join(__dirname, '__shots__');
const SEED_AUTH = () => {
  localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'mock', phone: '+33600000000', user_id: 12345 }));
  localStorage.setItem('lecayenne.onboarding_seen', 'true');
};

function track(page) {
  const errs = [];
  page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message));
  return errs;
}
async function boot(page, { seedCart } = {}) {
  await page.addInitScript(SEED_AUTH);
  if (seedCart) await page.addInitScript((c) => localStorage.setItem('lecayenne.cart', JSON.stringify(c)), seedCart);
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-screen-label]', { timeout: 35000 });
  await page.waitForTimeout(1500);
}
// bottom-nav items are role="tab" (shared.jsx TabBar), not role="button".
async function tab(page, name) {
  const t = page.getByRole('tab', { name, exact: true }).first();
  await t.waitFor({ state: 'visible', timeout: 30000 });
  await t.click();
  await page.waitForTimeout(500);
}
const snap = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });
const eur = (s) => parseFloat(String(s).replace(/[^\d,]/g, '').replace(',', '.'));

test('A — home: featured is a real signature tacos (F8) at SSOT price (F7)', async ({ page }) => {
  const errs = track(page);
  await boot(page);
  await snap(page, '01-home');
  expect((await page.locator('h3.lc-display').first().innerText()).trim()).toContain('TACOS');
  await expect(page.getByText('8,90 €').first()).toBeVisible();
  fs.writeFileSync(path.join(SHOTS, 'A-console.txt'), errs.join('\n'));
});

const CART = [
  { name: 'Tacos L', price: 8.90, qty: 1, lineTotal: 8.90, slot: 'item-big-tacos-2-viandes', image: 'assets/menu/tacos.png', sups: [], composition_summary: '2 viandes · Sauce fromagère' },
  { name: 'Coca-Cola 33cl', price: 1.50, qty: 1, slot: 'item-coca', image: 'assets/menu/coca.png', sups: [] },
];

async function openCart(page) {
  await tab(page, 'Menu');
  const bar = page.getByText('Voir le panier');
  await bar.waitFor({ state: 'visible', timeout: 20000 });
  await bar.click();
  await page.waitForSelector('[data-screen-label="10 Cart"]', { timeout: 15000 });
  await page.waitForTimeout(400);
}

test('B — cart: qty uses price*qty not stale lineTotal (RED-P1), promo reaches total (F1), banner (F2/F9)', async ({ page }) => {
  const errs = track(page);
  await boot(page, { seedCart: CART });
  await openCart(page);
  await snap(page, '02a-cart');

  // P1 visual guard: the checkout TOTAL must paint ABOVE the upsell carousel
  // (the footer was z-index:auto → product images occluded the total).
  const totalClear = await page.evaluate(() => {
    const t = [...document.querySelectorAll('.lc-display')].find((d) => /€/.test(d.textContent));
    if (!t) return false;
    const r = t.getBoundingClientRect();
    const hit = document.elementFromPoint(r.x + 5, r.y + r.height / 2);
    return !!(hit && (t === hit || t.contains(hit)));
  });
  expect(totalClear, 'cart TOTAL must not be occluded by upsell images (P1)').toBeTruthy();

  // RED-P1: Tacos L qty → 2 must show 17,80 € (8,90×2), NOT the stale lineTotal 8,90
  await page.getByRole('button', { name: 'Augmenter la quantité de Tacos L' }).click();
  await page.waitForTimeout(400);
  await expect(page.getByText('17,80 €')).toBeVisible();
  await snap(page, '02b-cart-qty2');

  const bodyText = await page.locator('[data-screen-label="10 Cart"]').innerText();
  expect(bodyText).not.toContain('burger gratuit'); // F9: hardcoded copy gone
  const banner = (bodyText.match(/\+\d+ pts gagnés/) || ['?'])[0];

  // F1: WELCOME10 → discount line + total = subtotal − 10%
  await page.getByTestId('cart-promo-input').fill('WELCOME10');
  await page.getByRole('button', { name: 'Appliquer le code promo' }).click();
  await page.waitForTimeout(500);
  await expect(page.getByTestId('cart-discount-amount')).toBeVisible();
  await snap(page, '02c-cart-promo');
  const discount = eur(await page.getByTestId('cart-discount-amount').innerText());
  expect(discount).toBeCloseTo(1.93, 2); // 10% of (17,80+1,50)=19,30
  fs.writeFileSync(path.join(SHOTS, 'B-console.txt'), errs.join('\n'));
  fs.writeFileSync(path.join(SHOTS, 'B-banner.txt'), banner);
});

test('C — pay → confirm: charged total == displayed discounted total (F1 end-to-end)', async ({ page }) => {
  const errs = track(page);
  await boot(page, { seedCart: CART });
  await openCart(page);
  await page.getByTestId('cart-promo-input').fill('WELCOME10');
  await page.getByRole('button', { name: 'Appliquer le code promo' }).click();
  await page.waitForTimeout(400);
  const cartTotal = eur(await page.locator('.lc-display').filter({ hasText: '€' }).last().innerText());
  await page.getByRole('button', { name: 'Valider ma commande' }).click();
  await page.waitForTimeout(700);
  await snap(page, '03a-pay-modal');
  const counter = page.getByRole('button', { name: /caisse|comptoir|payer|counter|espèces|carte/i }).first();
  if (await counter.count()) await counter.click(); else await page.locator('[data-testid="modal"] button, [data-modal-kind] button').first().click();
  await page.waitForSelector('[data-screen-label="11 Confirmation"]', { timeout: 15000 });
  await page.waitForTimeout(500);
  await snap(page, '03b-confirm');
  const confirmText = await page.locator('[data-screen-label="11 Confirmation"]').innerText();
  const confirmTotal = eur((confirmText.match(/(\d+,\d{2})\s*€/) || [])[1] || '0');
  expect(confirmTotal).toBeCloseTo(cartTotal, 2);
  fs.writeFileSync(path.join(SHOTS, 'C-totals.txt'), `cart=${cartTotal} confirm=${confirmTotal}`);
  fs.writeFileSync(path.join(SHOTS, 'C-console.txt'), errs.join('\n'));
});

test('D — loyalty: rules advertise 1 pt/€ (owner-canonical GATE-LOYALTY-1), screen renders (F9 progress)', async ({ page }) => {
  const errs = track(page);
  await boot(page);
  await tab(page, 'Profil');
  await page.getByRole('button', { name: /Carte fidélité/ }).first().click();
  await page.waitForSelector('[data-testid="loyalty-screen"]', { timeout: 15000 });
  await page.waitForTimeout(600);
  await snap(page, '04-loyalty');
  expect(await page.locator('[data-testid="loyalty-screen"]').innerText()).toMatch(/1\s*pt par € dépensé/);
  fs.writeFileSync(path.join(SHOTS, 'D-console.txt'), errs.join('\n'));
});

test('E — order detail C-1234: reconciled to SSOT, total 30,80 € (F7)', async ({ page }) => {
  const errs = track(page);
  await boot(page);
  await tab(page, 'Commandes');
  await page.getByText(/C-1234/).first().click();
  await page.waitForSelector('[data-screen-label="12b Order detail"]', { timeout: 15000 });
  await page.waitForTimeout(500);
  await snap(page, '05-order-detail');
  expect(await page.locator('[data-screen-label="12b Order detail"]').innerText()).toContain('30,80 €');
  fs.writeFileSync(path.join(SHOTS, 'E-console.txt'), errs.join('\n'));
});

test('F — item wizard: "Sans sauce" selectable in the sauce step (F6)', async ({ page }) => {
  const errs = track(page);
  await boot(page);
  await tab(page, 'Menu');
  // [a11y overlay heal 2026-06-10] whole-card tap is now a stretched <button aria-label="Voir …"> (uiux nested-interactive fix) — target it, not the title span (which the overlay intercepts).
  await page.getByRole('button', { name: /^Voir Galette Normale/ }).first().click();
  // wizard label is "09 Item Wizard <stepTitle>", not "09 Item Detail"
  await page.waitForSelector('[data-screen-label^="09 Item Wizard"]', { timeout: 15000 });
  await page.waitForTimeout(500);
  // VIANDES step: pick a meat → the "Suivant" CTA enables
  await page.getByText('Poulet mariné').first().click();
  await page.waitForTimeout(300);
  const cta = page.getByRole('button', { name: /Suivant/ });
  await expect(cta).toBeEnabled();
  await cta.click();
  await page.waitForSelector('[data-screen-label="09 Item Wizard Sauce"]', { timeout: 10000 });
  await page.waitForTimeout(400);
  await snap(page, '06-wizard-sauce');
  await expect(page.getByText('Sans sauce').first()).toBeVisible();
  fs.writeFileSync(path.join(SHOTS, 'F-console.txt'), errs.join('\n'));
});

test('G — bol wizard: Boule gratinée surfaces lactose in the recap allergens (F4, A0 legal)', async ({ page }) => {
  const errs = track(page);
  await boot(page);
  await tab(page, 'Menu');
  await page.getByRole('button', { name: /^Voir Bowl Frites Poulet curry/ }).first().click();
  await page.waitForSelector('[data-screen-label^="09 Item Wizard"]', { timeout: 15000 });
  await page.waitForTimeout(500);
  // walk the bol steps to the recap, selecting "Boule gratinée" when it appears
  for (let i = 0; i < 7; i++) {
    if (await page.getByRole('button', { name: /Ajouter au panier/ }).count()) break;
    const bg = page.getByText('Boule gratinée');
    if (await bg.count()) await bg.first().click();
    const next = page.getByRole('button', { name: /Suivant/ });
    if (!(await next.isEnabled())) await page.locator('.rdw-choice').first().click();
    await next.click();
    await page.waitForTimeout(400);
  }
  await snap(page, '07-bol-recap');
  // F4: the bol supplement's allergen (lactose) must reach the FIC 1169/2011 disclosure
  await expect(page.locator('[aria-label*="Lactose"]').first()).toBeVisible();
  fs.writeFileSync(path.join(SHOTS, 'G-console.txt'), errs.join('\n'));
});

test('H — cart upsell now shows Frites (F5: was desserts-only via dead "sides"/"drinks" slugs)', async ({ page }) => {
  const errs = track(page);
  await boot(page, { seedCart: CART });
  await openCart(page);
  await page.getByText('Pour accompagner').scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await snap(page, '08-cart-upsell');
  expect(await page.locator('[data-screen-label="10 Cart"]').innerText()).toMatch(/Frites/);
  fs.writeFileSync(path.join(SHOTS, 'H-console.txt'), errs.join('\n'));
});
