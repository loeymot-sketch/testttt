// DISPUTE round-1 Vague C — C1 commande complexe + intégrité numérique bout-en-bout.
// 2 composés (Tacos 26 cat5 ; Galette Normale 23 cat2) + 1 simple (Coca 52 cat10)
// → qty au panier (composé→2, simple→2) → suppr 1 ligne (Galette) → promo BORNEAUDIT5
// → fidélité 0612345678 → upsell → payment → confirme → cash-instruction.
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c1-log.txt', m);
const { browser, page, sink } = await boot();

const tok = await kioskBoot(page);
L(`kiosk token: ${tok}`);
await startFlow(page);
L(`startFlow → ${page.url().replace(BASE, '')}`);

// --- ajout produits
const r1 = await addProduct(page, 5, 26);   L(`add Tacos(26): ${JSON.stringify(r1)}`);
await snap(page, sink, 'c1-01-after-tacos');
const r2 = await addProduct(page, 2, 23);   L(`add Galette Normale(23): ${JSON.stringify(r2)}`);
const r3 = await addProduct(page, 10, 52);  L(`add Coca(52): ${JSON.stringify(r3)}`);
L(`cart state: ${JSON.stringify(await cartState(page))}`);

// --- panier
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await snap(page, sink, 'c1-02-cart-initial');
const names = [];
const nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
for (let i = 0; i < nLines; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText()).trim());
L(`cart lines: ${JSON.stringify(names)}`);
L(`totals initial: ${JSON.stringify(await readCartTotals(page))}`);

// idx du Tacos (composé) et du Coca (simple), Galette à supprimer
const idxOf = (frag) => names.findIndex(n => n.toLowerCase().includes(frag));
const iTacos = idxOf('tacos'), iGalette = idxOf('galette'), iCoca = idxOf('coca');
L(`idx tacos=${iTacos} galette=${iGalette} coca=${iCoca}`);

// qty>1 sur le COMPOSÉ (Tacos → 2)
if (iTacos >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iTacos}"]`).click(); await page.waitForTimeout(900); }
// qty 2 sur le simple
if (iCoca >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iCoca}"]`).click(); await page.waitForTimeout(900); }
await snap(page, sink, 'c1-03-cart-qty-bumped');
L(`totals après qty+: ${JSON.stringify(await readCartTotals(page))}`);

// suppression de la ligne Galette
if (iGalette >= 0) {
  await page.locator(`[data-testid="kiosk-cart-item-remove-${iGalette}"]`).click();
  await page.waitForTimeout(700);
  // modale de confirmation éventuelle
  for (const sel of ['[data-testid="kiosk-cart-remove-yes"]', 'button:has-text("Supprimer")', 'button:has-text("Oui")']) {
    const b = page.locator(sel).first();
    if (await b.isVisible().catch(() => false)) { await b.click().catch(() => {}); break; }
  }
  await page.waitForTimeout(900);
}
await snap(page, sink, 'c1-04-cart-line-removed');
L(`cart après suppr: ${JSON.stringify(await cartState(page))}`);
L(`totals après suppr: ${JSON.stringify(await readCartTotals(page))}`);

// --- code promo BORNEAUDIT5
const promoInput = page.locator('[data-testid="kiosk-cart-promo-input"]');
if (await promoInput.isVisible().catch(() => false)) {
  await promoInput.fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2000);
} else L('PROMO INPUT INVISIBLE');
await snap(page, sink, 'c1-05-cart-promo-applied');
L(`totals après promo: ${JSON.stringify(await readCartTotals(page))}`);
const promoErr = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText().catch(() => null);
if (promoErr) L(`PROMO ERROR AFFICHÉE: ${promoErr}`);

// --- fidélité (même commande)
const loyBtn = page.locator('[data-testid="kiosk-cart-loyalty-btn"]');
if (await loyBtn.isVisible().catch(() => false)) {
  await loyBtn.click();
  await page.waitForTimeout(1500);
  await snap(page, sink, 'c1-06-loyalty-screen');
  // saisir le téléphone via numpad (input tactile)
  const input = page.locator('.kiosk-loyalty-input').first();
  let typed = false;
  if (await input.isVisible().catch(() => false)) {
    try { await input.fill('0612345678'); typed = true; } catch {}
  }
  if (!typed) {
    for (const d of '0612345678') {
      await page.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click().catch(() => {});
      await page.waitForTimeout(120);
    }
  }
  await page.waitForTimeout(300);
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(2500);
  await snap(page, sink, 'c1-07-loyalty-balance');
  // option redeem si présente (1re option verte)
  const opt = page.locator('.kiosk-loyalty-option').first();
  if (await opt.isVisible().catch(() => false)) { await opt.click(); await page.waitForTimeout(1500); }
  await snap(page, sink, 'c1-08-after-loyalty-choice');
  L(`après loyalty → ${page.url().replace(BASE, '')}`);
} else L('LOYALTY BTN INVISIBLE');

// retour panier si pas déjà
if (!page.url().includes('/cart')) { await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1500); }
const cartFinal = await readCartTotals(page);
L(`TOTALS PANIER FINAL: ${JSON.stringify(cartFinal)}`);
await snap(page, sink, 'c1-09-cart-final-promo-loyalty');

// --- checkout → (loyalty skip éventuel) → upsell → payment
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
L(`après checkout → ${page.url().replace(BASE, '')}`);
const loySkip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip');
if (page.url().includes('/loyalty') && await loySkip.first().isVisible().catch(() => false)) {
  await snap(page, sink, 'c1-10-loyalty-interstitial');
  await loySkip.first().click(); await page.waitForTimeout(1500);
}
if (page.url().includes('/upsell')) {
  await snap(page, sink, 'c1-11-upsell');
  const upTotals = await page.evaluate(() => document.body.innerText.match(/Total[^\n]*/gi)?.slice(0, 4) || []);
  L(`upsell totals visibles: ${JSON.stringify(upTotals)}`);
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
L(`avant payment → ${page.url().replace(BASE, '')}`);
await page.waitForTimeout(800);
await snap(page, sink, 'c1-12-payment');
const payTotal = await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText().catch(() => 'ABSENT');
const payBody = await page.evaluate(() => {
  const t = document.body.innerText;
  const m = t.match(/(Sous-total|Remise|Fidélité|Promo|Total)[^\n]*/gi);
  return m ? m.slice(0, 10) : [];
});
L(`PAYMENT total affiché: ${payTotal.replace(/\n/g, ' | ')}`);
L(`PAYMENT lignes montants: ${JSON.stringify(payBody)}`);

// --- confirme (route comptoir Plan B)
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4000);
L(`après confirm → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c1-13-cash-instruction');
const cashNum = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText().catch(() => 'ABSENT');
const cashAmt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText().catch(() => 'ABSENT');
L(`CASH-INSTRUCTION: numero="${cashNum}" montant="${cashAmt}"`);
L(`ORDERS POST: ${JSON.stringify(sink.orders)}`);

fs.writeFileSync(OUT + '_c1-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C1 done');
