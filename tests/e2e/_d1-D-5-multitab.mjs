// DISPUTE R1 vague D — scénario 5 : multi-tab borne (2 pages même context) — token single-session ? UX ?
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); fs.writeFileSync(`${OUT}/_d5-multitab-log.txt`, log.join('\n')); };
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });

const pA = await ctx.newPage();
const recA = attachRecorder(pA);
await kioskBoot(pA);
await enterFromIdle(pA);
await addSimpleProduct(pA);
L(`TAB A: cart=${JSON.stringify(await cartState(pA))}`);
const tokenA = await pA.evaluate(() => { try { return JSON.parse(localStorage.vuex).kioskCart.kioskToken?.slice(0, 18); } catch { return null; } });
L(`TAB A token: ${tokenA}…`);
await recA.snap('d5-01-tabA-cart1');

// TAB B même context
const pB = await ctx.newPage();
const recB = attachRecorder(pB);
await pB.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(3000);
const tokenB = await pB.evaluate(() => { try { return JSON.parse(localStorage.vuex).kioskCart.kioskToken?.slice(0, 18); } catch { return null; } });
L(`TAB B token: ${tokenB}… (identique A: ${tokenA === tokenB})`);
L(`TAB B cart au boot: ${JSON.stringify(await cartState(pB))}`);
await recB.snap('d5-02-tabB-idle');

// TAB B entre dans le flux et regarde son panier
await pB.locator('[data-testid="kiosk-idle-touch-btn"]').click({ force: true }).catch(() => {});
await pB.waitForTimeout(900);
await pB.locator('[data-testid="kiosk-order-type-takeaway"]').click().catch(() => {});
await pB.waitForTimeout(1500);
await pB.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(1500);
const linesB = await pB.locator('[data-testid^="kiosk-cart-item-name-"]').count();
L(`TAB B panier rendu: ${linesB} lignes — vuex in-memory B voit-il le panier de A ? cart=${JSON.stringify(await cartState(pB))}`);
await recB.snap('d5-03-tabB-cart');

// TAB B ajoute un produit DIFFÉRENT → divergence ?
await pB.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(1300);
const addsB = pB.locator('[data-testid^="kiosk-product-add-"]');
await addsB.nth(1).click().catch(() => {});
await pB.waitForTimeout(1200);
L(`TAB B après ajout: cart=${JSON.stringify(await cartState(pB))}`);
await recB.snap('d5-04-tabB-added');

// retour TAB A : son panier en mémoire vs localStorage écrasé par B ?
await pA.bringToFront();
const cartAmem = await pA.evaluate(() => {
  try { const app = document.querySelector('#app').__vue_app__; return app.config.globalProperties.$store.state.kioskCart.items.map(i => i.name || i.item_name); } catch (e) { return String(e); }
});
L(`TAB A items VUEX in-memory: ${JSON.stringify(cartAmem)}`);
L(`TAB A localStorage (partagé): ${JSON.stringify(await cartState(pA))}`);
// A ajoute encore un produit → qui gagne dans localStorage ?
await pA.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await pA.waitForTimeout(1300);
await pA.locator('[data-testid^="kiosk-product-add-"]').nth(2).click().catch(() => {});
await pA.waitForTimeout(1200);
L(`TAB A après son ajout: localStorage=${JSON.stringify(await cartState(pA))}`);
await recA.snap('d5-05-tabA-after-both');

// TAB B relit son état après l'écrasement par A
const cartBmem = await pB.evaluate(() => {
  try { const app = document.querySelector('#app').__vue_app__; return app.config.globalProperties.$store.state.kioskCart.items.map(i => i.name || i.item_name); } catch (e) { return String(e); }
});
L(`TAB B items VUEX in-memory (après action A): ${JSON.stringify(cartBmem)}`);
await recB.snap('d5-06-tabB-final');

// les deux tabs peuvent-elles commander ? B tente checkout complet
await pB.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await pB.waitForTimeout(1300);
const totB = (await pB.locator('[data-testid="kiosk-cart-total"]').innerText().catch(() => '?')).replace(/\n/g, ' ');
L(`TAB B panier final affiché: ${await pB.locator('[data-testid^="kiosk-cart-item-name-"]').count()} lignes total="${totB}"`);
await recB.snap('d5-07-tabB-cart-final');

await browser.close();
console.log('DONE-D5');
