// ROUND-2 ADVERSARIAL VERIFY — STANDALONE WEB (V1 Le Cayenne)
// READ-ONLY capture spec. Verifies the batch of visual fixes (commit 26d0809) actually RENDER
// + hunts NEW defects the fixes may have introduced (contain letterbox, overflow, console, 404).
//
// Run:
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-ultraudit-r2-verify-web-2026-05-30.spec.js \
//     --project desktop --project tablet --reporter=list --workers=1 --timeout=120000

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ baseURL: 'http://127.0.0.1:8095', actionTimeout: 8000 });

const SHOT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/round2';
fs.mkdirSync(SHOT_DIR, { recursive: true });
const errlog = {};

async function boot(page, vp) {
  page._errs = [];
  page._404 = [];
  page.on('pageerror', e => page._errs.push('PAGEERR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') page._errs.push('CONSOLE: ' + m.text()); });
  page.on('response', r => { if (r.status() >= 400) page._404.push(r.status() + ' ' + r.url()); });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}
// wait until every <img> currently in DOM is loaded (complete && naturalWidth>0) — kills load-race
async function waitImgs(page) {
  await page.waitForFunction(() => {
    const imgs = Array.from(document.querySelectorAll('img'));
    return imgs.length === 0 || imgs.every(i => i.complete && i.naturalWidth > 0);
  }, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(250);
}
async function shot(page, vp, name, full = false) {
  await page.screenshot({ path: path.join(SHOT_DIR, `web-${vp}-${name}.png`), fullPage: full }).catch(() => {});
}
async function shotEl(page, vp, name, sel, nth = 0) {
  const el = page.locator(sel).nth(nth);
  if (await el.isVisible().catch(() => false)) {
    await el.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(200);
    await el.screenshot({ path: path.join(SHOT_DIR, `web-${vp}-${name}.png`) }).catch(() => {});
    return true;
  }
  return false;
}
async function gotoRoute(page, label) {
  const close = page.locator('button.lc-modal-close, .lc-wiz-foot-back').first();
  if (await close.isVisible().catch(() => false)) { await close.click({ timeout: 3000 }).catch(() => {}); await page.waitForTimeout(250); }
  const deskLink = page.locator('button.lc-nav-link', { hasText: label }).first();
  if (await deskLink.isVisible().catch(() => false)) await deskLink.click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(600);
}

// WV-02 home featured Big Cayenne poster + WV-03 hero badge (desktop) + WV-09 card framing
test('home: featured poster (WV-02) + hero badge (WV-03) + card framing (WV-09)', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await boot(page, vp);
  // resolve big-cayenne slug+image (distinguish "fix broken" from "data missing")
  const bc = await page.evaluate(() => {
    const i = (window.W_ITEMS || []).find(x => x.slug === 'big-cayenne');
    return i ? { found: true, slug: i.slug, image: i.image } : { found: false };
  });
  errlog['big-cayenne-data'] = bc;
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(200);
  await waitImgs(page);
  await shot(page, vp, 'home-hero-view');             // WV-03 badge in/out of box
  await shotEl(page, vp, 'home-hero-art', '.lc-hero-art');
  await shotEl(page, vp, 'home-featured', '.lc-featured'); // WV-02 real Big Cayenne photo
  await shotEl(page, vp, 'home-cards-grid', '.lc-menu-grid', 0); // WV-09 framing consistency
  await shot(page, vp, 'home-full', true);
});

// WV-05 menu grid footer bottom-align (need DIFFERENT desc lengths) + WV-09 card framing
test('menu: grid footer align (WV-05) + card framing (WV-09)', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await boot(page, vp);
  await gotoRoute(page, 'Menu');
  await page.waitForTimeout(500);
  await waitImgs(page);
  // measure footer baselines across a row to PROVE bottom-align (not eyeball)
  const footMetrics = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.lc-menu-grid .lc-card-item')).slice(0, 6);
    return cards.map(c => {
      const foot = c.querySelector('.lc-card-item-foot');
      const desc = c.querySelector('.lc-card-item-desc');
      return {
        cardBottom: Math.round(c.getBoundingClientRect().bottom),
        footBottom: foot ? Math.round(foot.getBoundingClientRect().bottom) : null,
        footTop: foot ? Math.round(foot.getBoundingClientRect().top) : null,
        descLen: desc ? (desc.textContent || '').length : 0,
        cardTop: Math.round(c.getBoundingClientRect().top),
      };
    });
  });
  errlog[`menu-foot-${vp}`] = footMetrics;
  await shotEl(page, vp, 'menu-grid', '.lc-menu-grid', 0);
  await shot(page, vp, 'menu-full', true);
});

// WV-01 item-detail board = real photo (wait for load!)
test('item detail board real photo (WV-01)', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await boot(page, vp);
  await gotoRoute(page, 'Menu');
  // open 3 different products: a sandwich (first card), then Coca, then a bowl
  const targets = ['sandwich', 'coca', 'bol'];
  for (let t = 0; t < targets.length; t++) {
    const key = targets[t];
    const opened = await page.evaluate((k) => {
      const it = (window.W_ITEMS || []).find(x => (x.slug || '').includes(k) || (x.name || '').toLowerCase().includes(k));
      return it ? it.name : null;
    }, key);
    if (!opened) { errlog[`detail-${key}-${vp}`] = 'NO_ITEM'; continue; }
    const card = page.locator('button.lc-card-item', { hasText: opened }).first();
    if (!(await card.isVisible().catch(() => false))) { errlog[`detail-${key}-${vp}`] = 'CARD_NOT_VISIBLE:' + opened; continue; }
    await card.scrollIntoViewIfNeeded().catch(() => {});
    await card.click().catch(() => {});
    await page.waitForTimeout(500);
    await waitImgs(page);
    // record what the board actually renders
    const board = await page.evaluate(() => {
      const img = document.querySelector('.lc-detail-art img');
      const emoji = document.querySelector('.lc-detail-art-emoji');
      return {
        hasImg: !!img,
        imgSrc: img ? img.getAttribute('src') : null,
        imgComplete: img ? img.complete : null,
        imgNatW: img ? img.naturalWidth : null,
        emojiDisplay: emoji ? getComputedStyle(emoji).display : null,
      };
    });
    errlog[`detail-${key}-${vp}`] = { item: opened, board };
    await shotEl(page, vp, `detail-${key}`, '.lc-detail-art');
    await shot(page, vp, `detail-${key}-modal`);
    const close = page.locator('button.lc-modal-close').first();
    if (await close.isVisible().catch(() => false)) { await close.click().catch(() => {}); await page.waitForTimeout(300); }
    await gotoRoute(page, 'Menu');
  }
});

// WV-06/07 cart + checkout recap thumbnails real photo — ADD a product that HAS an image
test('cart + checkout recap thumbs real photo (WV-06/07)', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await boot(page, vp);
  // seed cart with a known product that has an image, via window.LC if available
  const seeded = await page.evaluate(() => {
    const it = (window.W_ITEMS || []).find(x => x.image && (x.slug || '').includes('sandwich'))
            || (window.W_ITEMS || []).find(x => x.image);
    if (!it) return { ok: false };
    if (window.LC && typeof window.LC.setCart === 'function') {
      window.LC.setCart([{ name: it.name, price: it.price || it.basePrice || 9, qty: 1, emoji: it.emoji || '🌶️', image: it.image }]);
      return { ok: true, via: 'setCart', name: it.name, image: it.image };
    }
    return { ok: false, name: it.name, image: it.image, note: 'no setCart' };
  });
  errlog[`cart-seed-${vp}`] = seeded;
  await page.waitForTimeout(400);
  // open cart drawer
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  await waitImgs(page);
  const cartThumb = await page.evaluate(() => {
    const img = document.querySelector('.lc-cart-row-thumb img');
    return { hasImg: !!img, src: img ? img.getAttribute('src') : null, natW: img ? img.naturalWidth : null };
  });
  errlog[`cart-thumb-${vp}`] = cartThumb;
  await shot(page, vp, 'cart-drawer');
  await shotEl(page, vp, 'cart-row', '.lc-cart-row', 0);
  // proceed to checkout
  const checkout = page.locator('button:has-text("Commander"), button:has-text("Checkout"), button.lc-cart-checkout, a:has-text("Commander")').first();
  if (await checkout.isVisible().catch(() => false)) { await checkout.click().catch(() => {}); await page.waitForTimeout(700); }
  await waitImgs(page);
  const recapThumb = await page.evaluate(() => {
    const img = document.querySelector('.lcf-summary-row-thumb img');
    return { hasImg: !!img, src: img ? img.getAttribute('src') : null, natW: img ? img.naturalWidth : null };
  });
  errlog[`recap-thumb-${vp}`] = recapThumb;
  await shot(page, vp, 'checkout-full', true);
  await shotEl(page, vp, 'recap-row', '.lcf-summary-row', 0);
});

test.afterAll(async () => {
  fs.writeFileSync(path.join(SHOT_DIR, 'web-verify-metrics.json'), JSON.stringify(errlog, null, 2));
});
test.afterEach(async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  errlog[`errors-${testInfo.title.slice(0, 30)}-${vp}`] = { errs: page._errs || [], http4xx: page._404 || [] };
});
