// ADVERSAIRE D1 round-1 vague F — re-vérification LIVE écran paiement borne (f07 claims)
// + scan i18n raw keys (catégorie 1 protocole, jamais exécutée par le GStack — DOM tronqué head-only)
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1080, height: 1920 });

const I18N_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;
async function i18nScan(label) {
  const leaks = await page.evaluate(() => {
    const out = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = walker.nextNode())) {
      const t = n.textContent.trim();
      if (t && /^[a-z]+(\.[a-z_]+){1,4}$/.test(t) && t.includes('.')) {
        const el = n.parentElement;
        if (el && el.offsetParent !== null) out.push(t + ' @ ' + (el.className || el.tagName).toString().slice(0, 60));
      }
    }
    return out.slice(0, 20);
  });
  console.log(`I18N-SCAN ${label}:`, leaks.length ? JSON.stringify(leaks) : 'CLEAN');
}

// boot kiosk token
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
let tok = false;
for (let i = 0; i < 10 && !tok; i++) {
  await page.waitForTimeout(1500);
  tok = await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } });
}
console.log('kiosk token:', tok);

// idle → takeaway
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
await i18nScan('idle');
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1200); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(2000); }
console.log('après order-type →', page.url().replace(BASE, ''));

// add Coca (cat 10 item 52)
await page.goto(BASE + '/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
await i18nScan('categories-boissons');
const cocaAdd = page.locator('[data-testid="kiosk-product-add-52"]');
if (await cocaAdd.isVisible().catch(() => false)) await cocaAdd.click();
else await page.locator('[data-testid^="kiosk-product-add-"]').first().click().catch(() => {});
await page.waitForTimeout(1500);

// cart → checkout
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
await i18nScan('cart');
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2500);
console.log('après checkout →', page.url().replace(BASE, ''));

// upsell éventuel → skip
if (page.url().includes('/upsell')) {
  await quartet(page, consoleLog, netLog, 'RED-F-upsell-live');
  await i18nScan('upsell');
  await page.locator('[data-testid="kiosk-upsell-skip"]').click().catch(() => {});
  await page.waitForTimeout(2500);
  console.log('après upsell-skip →', page.url().replace(BASE, ''));
}
// loyalty éventuel → skip
if (page.url().includes('/loyalty')) {
  const skip = await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button')).find((x) => /continuer|passer|sans/i.test(x.innerText));
    if (b) { b.click(); return b.innerText.trim(); }
    return null;
  });
  console.log('loyalty skip via:', skip);
  await page.waitForTimeout(2500);
}
console.log('état flux →', page.url().replace(BASE, ''));

// PAYMENT — le claim f07
if (page.url().includes('/payment')) {
  await page.waitForTimeout(2000);
  const payInfo = await page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const back = q('[data-testid="kiosk-payment-back"]');
    const counterRoot = q('[data-testid="kiosk-payment-counter-route"]');
    const total = q('[data-testid="kiosk-payment-counter-total"]');
    const btn = q('[data-testid="kiosk-payment-counter-confirm"]');
    const abandon = Array.from(document.querySelectorAll('button')).filter((b) => /abandonner|retour|annuler/i.test(b.innerText) && b.offsetParent !== null).map((b) => b.innerText.trim());
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { t: Math.round(b.top), b: Math.round(b.bottom), l: Math.round(b.left), r: Math.round(b.right), w: Math.round(b.width), h: Math.round(b.height) }; };
    const cs = btn ? getComputedStyle(btn) : null;
    const span = btn ? btn.querySelector('span') : null;
    return {
      planB: !!counterRoot,
      backBtnPresent: !!back,
      backBtnVisible: back ? back.offsetParent !== null : false,
      visibleEscapeButtons: abandon,
      totalRect: r(total), btnRect: r(btn),
      btnJustify: cs ? cs.justifyContent : null,
      btnDisplay: cs ? cs.display : null,
      spanRect: r(span),
      totalText: total ? total.innerText.replace(/\s+/g, ' ').trim() : null,
      btnText: btn ? btn.innerText.replace(/\s+/g, ' ').trim() : null,
      viewport: { w: innerWidth, h: innerHeight },
    };
  });
  console.log('PAYMENT-CLAIMS:', JSON.stringify(payInfo, null, 1));
  await i18nScan('payment');
  await quartet(page, consoleLog, netLog, 'RED-F-payment-live');
} else {
  console.log('WARN: paiement non atteint, url=', page.url());
  await quartet(page, consoleLog, netLog, 'RED-F-payment-UNREACHED');
}

await browser.close();
console.log('RED F live DONE');
