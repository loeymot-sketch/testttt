// DISPUTE round-2 Vague C — C-20 : ADV-F-P1-2 — SKU techniques hors grille client
// MAIS upsell/formule toujours fonctionnels (vérifié écran upsell + ADD).
import { boot, snap2, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, log2, OUT2 } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c20-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();
const SKUS = ['Boisson Seule', 'Frites Seules', 'Menu (Frites + Boisson)'];

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

// 1) Grille Sandwich Cayenne (cat=1)
await page.goto(BASE + '/kiosk/categories?cat=1', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
const gridTxt = await page.evaluate(() => document.querySelector('#app')?.innerText || '');
for (const s of SKUS) L(`grille cat1 contient "${s}" ? ${gridTxt.includes(s) ? 'OUI ⚠️' : 'NON ✅'}`);
const prods = await page.locator('[data-testid^="kiosk-product-add-"]').evaluateAll(els => els.map(e => e.getAttribute('data-testid')));
L(`boutons add visibles cat1: ${JSON.stringify(prods)}`);
const sidebar = await page.evaluate(() => [...document.querySelectorAll('.kiosk-cat-item, [data-testid^="kiosk-cat-"]')].map(e => e.innerText.replace(/\n/g, ' ').trim()).slice(0, 30));
L(`sidebar catégories: ${JSON.stringify(sidebar)}`);
L(`sidebar contient "Technique" ? ${JSON.stringify(sidebar).includes('Technique') ? 'OUI ⚠️' : 'NON ✅'}`);
await snap2(page, sink, 'c20-01-grille-sandwich-cayenne');

// 2) Toutes catégories visibles : le SKU ne doit apparaître nulle part
const cats = await page.evaluate(() => [...document.querySelectorAll('.kiosk-cat-item, [data-testid^="kiosk-cat-"]')].length);
L(`nb catégories sidebar: ${cats}`);

// 3) Upsell toujours fonctionnel : Sandwich Cayenne (22) avec formule menu via wizard
const r = await addProduct(page, 1, 22);
L(`add Sandwich Cayenne(22): ${JSON.stringify(r).slice(0, 200)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
const cs = await cartState(page);
L(`cart: ${JSON.stringify(cs)}`);
const lineTxt = await page.evaluate(() => document.querySelector('[data-testid^="kiosk-cart-item-name-"]')?.closest('div.kiosk-cart-line, .kiosk-cart-item, li, article')?.innerText?.replace(/\n/g, ' | ').slice(0, 300) || document.querySelector('#app').innerText.slice(0, 400));
L(`ligne panier: ${lineTxt}`);
L(`T0: ${JSON.stringify(await readCartTotals(page))}`);
await snap2(page, sink, 'c20-02-cart-sandwich');

// checkout → écran upsell : capturer et INTERAGIR
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2400);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
}
L(`après checkout → ${page.url().replace(BASE, '')}`);
if (page.url().includes('/upsell')) {
  const upTxt = await page.evaluate(() => document.querySelector('#app')?.innerText?.replace(/\n+/g, ' | ').slice(0, 600));
  L(`écran upsell: ${upTxt}`);
  await snap2(page, sink, 'c20-03-upsell-screen');
  // tenter l'ADD de l'offre upsell
  const addBtn = page.locator('[data-testid="kiosk-upsell-accept"], [data-testid="kiosk-upsell-add"], .kiosk-upsell-accept, .kiosk-upsell-add-btn').first();
  if (await addBtn.isVisible().catch(() => false)) {
    await addBtn.click();
    await page.waitForTimeout(2200);
    L(`après ADD upsell → ${page.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(page))}`);
    await snap2(page, sink, 'c20-04-after-upsell-add');
  } else {
    const btns = await page.evaluate(() => [...document.querySelectorAll('button')].map(b => (b.getAttribute('data-testid') || b.innerText.replace(/\n/g, ' ').trim()).slice(0, 60)).filter(Boolean).slice(0, 12));
    L(`boutons upsell visibles: ${JSON.stringify(btns)}`);
    const accept = page.locator('button', { hasText: /ajouter|oui|accepter/i }).first();
    if (await accept.isVisible().catch(() => false)) {
      await accept.click(); await page.waitForTimeout(2200);
      L(`après ADD(texte) → ${page.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(page))}`);
      await snap2(page, sink, 'c20-04-after-upsell-add');
    } else L('AUCUN bouton accept upsell trouvé');
  }
} else {
  L(`PAS d'écran upsell (skip auto ?) url=${page.url().replace(BASE, '')}`);
  await snap2(page, sink, 'c20-03-no-upsell');
}
// pas de commande ici — abandonner proprement (retour idle, reset au prochain flux)
fs.writeFileSync(OUT2 + '_c20-grid.json', JSON.stringify({ prods, sidebar }, null, 1));
await browser.close();
L('C20 done');
