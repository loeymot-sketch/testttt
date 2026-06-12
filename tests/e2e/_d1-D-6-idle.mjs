// DISPUTE R1 vague D — scénario 6 : session longue, panier abandonné 3+ min → reset propre ? + carousel promo
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(`[${new Date().toISOString().slice(11, 19)}] ${m}`); console.log(m); fs.writeFileSync(`${OUT}/_d6-idle-log.txt`, log.join('\n')); };
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);

// carousel promo sur catalogue : tourne-t-il ?
await p.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1800);
const promoState = () => p.evaluate(() => {
  const c = document.querySelector('[data-testid="kiosk-promo-carousel"]');
  if (!c) return null;
  const cards = [...c.querySelectorAll('[data-testid^="kiosk-promo-card-"]')];
  return { count: cards.length, text: c.innerText.replace(/\n+/g, ' | ').slice(0, 150) };
});
const promoT0 = await promoState();
L(`promo T0: ${JSON.stringify(promoT0)}`);
await p.waitForTimeout(12000);
const promoT12 = await promoState();
L(`promo T+12s: ${JSON.stringify(promoT12)} — a tourné: ${JSON.stringify(promoT0) !== JSON.stringify(promoT12)}`);
await rec.snap('d6-01-promo-carousel');

// panier abandonné
await addSimpleProduct(p);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
L(`panier abandonné: ${JSON.stringify(await cartState(p))}`);
await rec.snap('d6-02-cart-before-idle');

// attendre sans interaction. idleMs=180000 → warning, confirmMs=30000 → reset
L('attente sans interaction (idle 180 s + countdown 30 s)…');
await p.waitForTimeout(170000);
L(`T+170s: url=${p.url().replace(BASE, '')} overlay=${await p.locator('[data-testid="kiosk-inactivity-overlay"]').isVisible().catch(() => false)}`);
await rec.snap('d6-03-T170s');
await p.waitForTimeout(20000);
const ovVisible = await p.locator('[data-testid="kiosk-inactivity-overlay"]').isVisible().catch(() => false);
const countdown = await p.locator('[data-testid="kiosk-inactivity-countdown"]').innerText().catch(() => '(absent)');
L(`T+190s: overlay=${ovVisible} countdown="${countdown}" url=${p.url().replace(BASE, '')}`);
await rec.snap('d6-04-T190s-overlay');
await p.waitForTimeout(35000);
L(`T+225s: url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d6-05-T225s-after-reset');

// reset propre ? panier vidé ? retour idle ?
const onIdle = p.url().includes('/kiosk/idle');
const cart = await cartState(p);
L(`RESET: surIdle=${onIdle} panierVide=${(cart?.items || []).length === 0}`);

// la borne est-elle réutilisable immédiatement ?
await p.locator('[data-testid="kiosk-idle-touch-btn"]').click({ force: true }).catch(() => {});
await p.waitForTimeout(900);
await p.locator('[data-testid="kiosk-order-type-takeaway"]').click().catch(() => {});
await p.waitForTimeout(1500);
L(`réutilisation post-reset: url=${p.url().replace(BASE, '')}`);
await rec.snap('d6-06-reusable-after-reset');

await browser.close();
console.log('DONE-D6');
