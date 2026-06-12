// DISPUTE R2 vague D — vérif heals D-001 (écran erreur offline REND, imports eager) + D-002 (toast checkout offline FR)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, gotoPayment } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const chunkRequests = [];
p.on('request', r => { if (r.url().includes('kiosk-errors')) chunkRequests.push(`${r.method()} ${r.url()}`); });

const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(innerText KO)');

await kioskBoot(p);

// ---------- 1a. IDLE online : baseline + preuve structure eager (plus de chunk kiosk-errors requis) ----------
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(3000);
L(`requêtes chunk kiosk-errors depuis boot: ${chunkRequests.length} ${JSON.stringify(chunkRequests)}`);
await rec.snap('d2D1-01-idle-online-baseline');

// ---------- 1b. OFFLINE depuis idle → push kiosk.error.network → l'écran DOIT rendre (D-001) ----------
await ctx.setOffline(true);
L('OFFLINE=true (depuis idle)');
const nav = await p.evaluate(() => {
  try {
    const app = document.querySelector('#app')?.__vue_app__;
    const r = app?.config?.globalProperties?.$router;
    if (!r) return 'NO-ROUTER';
    r.push({ name: 'kiosk.error.network' });
    return 'PUSHED';
  } catch (e) { return 'ERR ' + String(e); }
});
L(`router.push(kiosk.error.network) offline → ${nav}`);
await p.waitForTimeout(2500);
L(`URL après push offline: ${p.url().replace(BASE, '')}`);
const errTxt = (await bodyText()).slice(0, 700).replace(/\n+/g, ' | ');
L(`TEXTE écran erreur offline: "${errTxt}"`);
L(`requêtes chunk kiosk-errors cumulées: ${chunkRequests.length}`);
await rec.snap('d2D1-02-error-network-offline-renders');
// CTA Réessayer présent ?
const retryVisible = await p.locator('button:has-text("Réessayer"), [data-testid*="retry"]').first().isVisible().catch(() => false);
L(`CTA Réessayer visible offline: ${retryVisible}`);
await ctx.setOffline(false);
await p.waitForTimeout(1200);
const retry = p.locator('button:has-text("Réessayer"), [data-testid*="retry"]').first();
if (await retry.isVisible().catch(() => false)) {
  await retry.click().catch(() => {});
  await p.waitForTimeout(2500);
  L(`après Réessayer online → ${p.url().replace(BASE, '')}`);
  await rec.snap('d2D1-03-error-network-retry-online');
}

// ---------- 1c. Catalogue → panier → checkout OFFLINE : toast FR (D-002), panier conservé ----------
await enterFromIdle(p);
const added = await addSimpleProduct(p);
L(`produit ajouté: ${added} cart=${JSON.stringify(await cartState(p))}`);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
const before = await cartState(p);
L(`CART avant offline: ${JSON.stringify(before)}`);
await ctx.setOffline(true);
L('OFFLINE=true (panier) — clic Valider ma commande');
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(3200);
const t = (await bodyText());
const hasNetworkErrorEN = /Network Error|Request failed/i.test(t);
const hasFR = /Connexion perdue|panier est conservé/i.test(t);
L(`après checkout offline → ${p.url().replace(BASE, '')}`);
L(`toast EN brut présent: ${hasNetworkErrorEN} · message FR présent: ${hasFR}`);
L(`TEXTE: "${t.slice(0, 700).replace(/\n+/g, ' | ')}"`);
await rec.snap('d2D1-04-cart-checkout-offline-toastFR');
await ctx.setOffline(false);
await p.waitForTimeout(2500);
L(`recovery: url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2D1-05-cart-recovery');

// ---------- 1d. PAYMENT Plan B → confirm OFFLINE : message FR + route erreur réseau REND (D-001 en flux réel) ----------
await gotoPayment(p);
await rec.snap('d2D1-06-payment-online');
await ctx.setOffline(true);
L('OFFLINE=true (payment) — clic Confirmer ma commande');
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(4500);
const t2 = await bodyText();
L(`après confirm offline → ${p.url().replace(BASE, '')}`);
L(`TEXTE: "${t2.slice(0, 700).replace(/\n+/g, ' | ')}"`);
L(`requêtes chunk kiosk-errors cumulées: ${chunkRequests.length}`);
await rec.snap('d2D1-07-payment-confirm-offline');
await ctx.setOffline(false);
await p.waitForTimeout(2500);
L(`OFFLINE=false — url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2D1-08-payment-recovery');

fs.writeFileSync(`${OUT}/_d2D-1-offline-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D1');
