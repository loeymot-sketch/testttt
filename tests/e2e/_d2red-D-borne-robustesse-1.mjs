// ADVERSARIAL R2 vague D — probe indépendante 1 :
//   A) OFFLINE → /kiosk/error/network REND (capture PRISE OFFLINE)
//   B) Plan B bout-en-bout → cash-instruction : panier VIDÉ + CTA retour accueil → idle
//   C) D-R2-A1 : RÉESSAYER online → re-land erreur ? + échappatoire chip « Mon panier » exercée
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, gotoPayment } from './_d2-D-helper.mjs';

const OUTRED = OUT; // mêmes captures dossier round-2/D-borne-robustesse, préfixe d2red
const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const body = () => p.evaluate(() => document.body.innerText).catch(() => '(KO)');

// ---------- A) écran erreur rend OFFLINE ----------
await kioskBoot(p);
await enterFromIdle(p);
await ctx.setOffline(true);
await p.waitForTimeout(400);
// navigation SPA directe vers la route erreur, réseau COUPÉ
await p.evaluate(() => { window.history.pushState({}, '', '/kiosk/error/network'); window.dispatchEvent(new PopStateEvent('popstate')); });
await p.waitForTimeout(2500);
let t = await body();
L(`A) OFFLINE direct-route → url=${p.url().replace(BASE, '')} rend="Connexion perdue"=${/Connexion perdue/.test(t)} retry=${/RÉESSAYER|Réessayer/.test(t)}`);
await rec.snap('d2red-A-error-network-offline');
const chunkErr = rec.consoleLog.filter(l => /ChunkLoadError|Loading chunk/.test(l));
L(`A) ChunkLoadError dans console: ${chunkErr.length} | pageerror: ${rec.consoleLog.filter(l => l.startsWith('[pageerror]')).length}`);
await ctx.setOffline(false);
await p.waitForTimeout(1500);

// ---------- B) Plan B bout-en-bout ----------
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
await enterFromIdle(p);
const added = await addSimpleProduct(p);
L(`B) produit ajouté: ${added} cart=${JSON.stringify(await cartState(p))}`);
let url = await gotoPayment(p);
if (!url.includes('/payment')) { await p.waitForTimeout(2500); url = await gotoPayment(p); }
L(`B) payment: ${url.replace(BASE, '')}`);
// bouton retour panier présent ?
const backBtn = await p.locator('[data-testid="kiosk-payment-counter-back"]').isVisible().catch(() => false);
L(`B) bouton « Retour au panier » visible sur payment Plan B: ${backBtn}`);
// confirm
let orderStatus = null;
p.on('response', r => { if (r.url().includes('/frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote')) orderStatus = r.status(); });
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(6000);
t = await body();
const onCash = p.url().includes('cash-instruction') || /Rendez-vous en caisse/.test(t);
const cs = await cartState(p);
L(`B) après confirm → ${p.url().replace(BASE, '')} ORDER-POST=${orderStatus} cash-instruction=${onCash}`);
L(`B) PANIER POST-COMMANDE: items=${cs?.items?.length} idemKey=${cs?.idemKey} promo=${cs?.promoCode}`);
L(`B) CTA retour accueil présent: ${/RETOUR À L'ACCUEIL|Retour à l'accueil/i.test(t)}`);
await rec.snap('d2red-B-cash-instruction');
// clic CTA
await p.locator('[data-testid="kiosk-cash-cta-understood"]').click().catch(() => {});
await p.waitForTimeout(2000);
L(`B) après CTA → ${p.url().replace(BASE, '')}`);
await rec.snap('d2red-B-after-cta');

// ---------- C) D-R2-A1 retry loop + échappatoire ----------
await enterFromIdle(p);
await addSimpleProduct(p);
url = await gotoPayment(p);
if (!url.includes('/payment')) { await p.waitForTimeout(2500); url = await gotoPayment(p); }
await ctx.setOffline(true);
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(4500);
L(`C) confirm offline → ${p.url().replace(BASE, '')}`);
await ctx.setOffline(false);
await p.waitForTimeout(2500);
// RÉESSAYER online
await p.locator('[data-testid="kiosk-error-network-cta-retry"]').first().click().catch(() => {});
await p.waitForTimeout(9000);
t = await body();
const stillError = p.url().includes('/kiosk/error/network') && /Connexion perdue/.test(t);
L(`C) après RÉESSAYER online (+9 s): url=${p.url().replace(BASE, '')} écran erreur re-rendu=${stillError}`);
await rec.snap('d2red-C-retry-online-still-error');
// 2e RÉESSAYER pour prouver la boucle
await p.locator('[data-testid="kiosk-error-network-cta-retry"]').first().click().catch(() => {});
await p.waitForTimeout(9000);
t = await body();
L(`C) après 2e RÉESSAYER: url=${p.url().replace(BASE, '')} erreur=${/Connexion perdue/.test(t)} → BOUCLE=${p.url().includes('/kiosk/error/network')}`);
// échappatoire chip Mon panier
const chip = p.locator('text=Mon panier').first();
const chipVisible = await chip.isVisible().catch(() => false);
L(`C) chip « Mon panier » visible: ${chipVisible}`);
if (chipVisible) {
  await chip.click().catch(() => {});
  await p.waitForTimeout(2500);
  const cs2 = await cartState(p);
  L(`C) après clic chip → ${p.url().replace(BASE, '')} cart items=${cs2?.items?.length}`);
  await rec.snap('d2red-C-escape-via-cart-chip');
}

fs.writeFileSync(`${OUTRED}/_d2red-D-probe1-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2RED-1');
