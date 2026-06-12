// ADVERSARIAL R2 — C-ADV-02 : la promo survit-elle au reload (restauration re-validée serveur) ?
import { boot, kioskBoot, startFlow, addProduct, readCartTotals, BASE } from './_d1-C-lib.mjs';
import { log2, snap2 } from './_d2-C-lib.mjs';

const L = (m) => log2('_d2red-promoreload-log.txt', m);
const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);
const r = await addProduct(page, 2, 23); L(`add Galette(23): ${JSON.stringify(r).slice(0, 120)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1400);
let applied = false;
for (let a = 1; a <= 3 && !applied; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2400);
  const t = await readCartTotals(page);
  if (t.promo) { applied = true; L(`T1 promo appliquée: ${JSON.stringify(t)}`); break; }
  const err = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 1000 }).catch(() => null);
  L(`essai ${a} échec: "${err}" — attente 30s`); await page.waitForTimeout(30000);
}
if (applied) {
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const t2 = await readCartTotals(page);
  L(`T2 après reload: ${JSON.stringify(t2)}`);
  L(`C-ADV-02 ${t2.promo ? 'FERMÉ (promo restaurée)' : 'SURVIVANT (promo perdue au reload)'}`);
  await snap2(page, sink, 'd2r-e-cart-promo-after-reload');
}
// nettoyage: vider le panier (pas de commande créée)
await page.locator('button:has-text("Vider le panier")').first().click().catch(() => {});
await page.waitForTimeout(700);
await page.locator('button:has-text("Vider"), button:has-text("Oui"), button:has-text("Confirmer")').first().click().catch(() => {});
await page.waitForTimeout(700);
await browser.close();
