// test-ultraudit-visual-web-2026-05-30 — DEEP VISUAL ULTRAUDIT of STANDALONE WEB (V1 Le Cayenne)
//
// Owner complaint: "images not all aligned; buttons, posters, products, product-box not all well
// done; visual problems + interfaces." FOCUS = ALIGNMENT / ASPECT-RATIO / CROPPING / sizing /
// button-card-box-poster quality / layout / spacing / overflow. NOT photo-subject (already validated).
//
// This spec is a DUMB navigate+capture harness. The intelligence is in the human/vision pass:
// every PNG is Read afterwards with multimodal vision. We capture BOTH:
//   (a) fullPage overviews (below-fold defects), AND
//   (b) targeted native-scale element/viewport shots where FRAMING itself is the defect
//       (card thumbs, hero art, featured/special posters, product boxes) — fullPage downscaling
//       hides subtle crop/aspect defects, so these are captured at native scale.
//
// Coverage = what round3 SKIPS: home (hero/special/featured/testi/gallery/hours/app-cta),
// menu grid+sidebar, item-detail modal, wizard steps, cart EMPTY + FULL, checkout, payment,
// account login+register, loyalty, orders, confirm, track, about. (round3 already did the
// post-payment hidden pages + legal; we re-capture a few here for the visual-quality lens.)
//
// READ-ONLY: does NOT modify web app source.
//
// ⚠️ SERVER CONCURRENCY (important — learned this cycle):
//   Capturing image CONTENT requires a THREADED static server. A single-process
//   `php -S` drops some of the ~13 concurrent thumbnail connections per page,
//   firing the <img> onError emoji fallback → false "emoji instead of photo"
//   findings (connection-level drop = no HTTP status, so a 404-check misses it).
//   Launch the site with workers BEFORE running:
//     PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8095 -t /Users/1millnonstop/Downloads/web/ &
//   (verified: on a threaded server the real photos render with no wait.)
//
// Run:
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-ultraudit-visual-web-2026-05-30.spec.js \
//     --project mobile --project tablet --project desktop \
//     --reporter=list --workers=1 --timeout=120000

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ baseURL: 'http://127.0.0.1:8095', actionTimeout: 8000 });

const SHOT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/web';
fs.mkdirSync(SHOT_DIR, { recursive: true });

async function bootWeb(page) {
  page._errors = [];
  page.on('pageerror', err => page._errors.push(err.message));
  page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}

// fullPage overview (durable)
async function shotFull(page, vp, state) {
  const png = `web-${vp}-${state}.png`;
  await page.screenshot({ path: path.join(SHOT_DIR, png), fullPage: true })
    .catch(() => page.screenshot({ path: path.join(SHOT_DIR, png), fullPage: false }).catch(() => {}));
}
// native-scale viewport shot (no downscale of framing detail)
async function shotView(page, vp, state) {
  await page.screenshot({ path: path.join(SHOT_DIR, `web-${vp}-${state}.png`), fullPage: false }).catch(() => {});
}
// native-scale ELEMENT shot — for framing-defect elements (crop/aspect visible at native px)
async function shotEl(page, vp, state, selector, nth = 0) {
  const el = page.locator(selector).nth(nth);
  if (await el.isVisible().catch(() => false)) {
    await el.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(150);
    await el.screenshot({ path: path.join(SHOT_DIR, `web-${vp}-${state}.png`) }).catch(() => {});
    return true;
  }
  return false;
}

async function gotoRoute(page, label) {
  const close = page.locator('button.lc-modal-close, .lc-wiz-foot-back').first();
  if (await close.isVisible().catch(() => false)) { await close.click({ timeout: 3000 }).catch(() => {}); await page.waitForTimeout(250); }
  const deskLink = page.locator('button.lc-nav-link', { hasText: label }).first();
  if (await deskLink.isVisible().catch(() => false)) {
    await deskLink.click({ timeout: 5000 }).catch(() => {});
  } else {
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) {
      await burger.click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(300);
      const mobLink = page.locator('button.lc-mobile-link', { hasText: label }).first();
      if (await mobLink.isVisible().catch(() => false)) await mobLink.click({ timeout: 5000 }).catch(() => {});
    }
  }
  await page.waitForTimeout(600);
}

// ============================================================================
// HOME — fullPage overview + targeted native-scale shots of every framing element
// ============================================================================
test('home full + framing elements', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);

  // (a) fullPage overview
  await shotFull(page, vp, 'home-full');

  // (b) hero region native-scale (hero art SVG burger + hero text + CTAs)
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(200);
  await shotView(page, vp, 'home-hero-view');
  await shotEl(page, vp, 'home-hero-art', '.lc-hero-art');

  // special-offer poster card
  await shotEl(page, vp, 'home-special', '.lc-special');

  // featured poster (Big Cayenne + emoji art box)
  await shotEl(page, vp, 'home-featured', '.lc-featured');

  // EACH featured product card thumb at native scale — to inspect crop/aspect/framing consistency
  const cards = page.locator('.lc-menu-grid .lc-card-item');
  const n = Math.min(await cards.count().catch(() => 0), 4);
  for (let i = 0; i < n; i++) {
    await shotEl(page, vp, `home-card${i + 1}`, '.lc-menu-grid .lc-card-item', i);
  }
  // and the 4-card grid as a row to compare card heights side-by-side
  await shotEl(page, vp, 'home-cards-grid', '.lc-menu-grid', 0);

  // testimonials row, gallery tiles, hours block, app-cta phone mock
  await shotEl(page, vp, 'home-testi', '.lc-testi');
  await shotEl(page, vp, 'home-gallery', '.lc-gallery');
  await shotEl(page, vp, 'home-hours', '.lc-hours');
  await shotEl(page, vp, 'home-appcta', '.lc-app-cta');
});

// ============================================================================
// MENU — fullPage + sidebar + product grid + individual card thumbs (crop check)
// ============================================================================
test('menu full + grid + card thumbs', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  await page.waitForTimeout(500);

  await shotFull(page, vp, 'menu-full');
  await shotEl(page, vp, 'menu-sidebar', '.lc-menu-side');
  await shotEl(page, vp, 'menu-grid', '.lc-menu-grid', 0);

  // sample first 8 product card thumbs at native scale to inspect per-photo framing consistency
  const cards = page.locator('.lc-menu-grid .lc-card-item');
  const n = Math.min(await cards.count().catch(() => 0), 8);
  for (let i = 0; i < n; i++) {
    await shotEl(page, vp, `menu-card${i + 1}`, '.lc-menu-grid .lc-card-item', i);
  }
});

// ============================================================================
// ITEM DETAIL modal — full board photo + price + CTA
// ============================================================================
test('item detail modal', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const card = page.locator('button.lc-card-item').first();
  if (await card.isVisible().catch(() => false)) {
    await card.scrollIntoViewIfNeeded().catch(() => {});
    await card.click().catch(() => {});
    await page.waitForTimeout(500);
    await shotView(page, vp, 'item-detail-view');
    await shotEl(page, vp, 'item-detail-modal', '.lc-modal, .lc-detail, [class*="detail"]');
  }
});

// ============================================================================
// WIZARD steps — open a sandwich, walk steps, capture each (option grids, buttons, recap)
// ============================================================================
test('wizard steps', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await page.evaluate(() => {
    const it = window.W_ITEMS.find(i => i.id === 'sandwich-cayenne-classique');
    return it ? it.name : (window.W_ITEMS[0] ? window.W_ITEMS[0].name : null);
  });
  const card = page.locator('button.lc-card-item', { hasText: info }).first();
  if (await card.isVisible().catch(() => false)) {
    await card.scrollIntoViewIfNeeded().catch(() => {});
    await card.click().catch(() => {});
    await page.waitForTimeout(400);
  }
  const perso = page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first();
  if (await perso.isVisible().catch(() => false)) { await perso.click().catch(() => {}); await page.waitForTimeout(500); }

  for (let i = 0; i < 8; i++) {
    await shotView(page, vp, `wizard-step${i + 1}`);
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    if (/Ajouter au panier/i.test(btnTxt)) {
      await shotView(page, vp, 'wizard-recap');
      break;
    }
    if (await next.isDisabled().catch(() => true)) {
      await page.locator('.lc-wiz-choice, .lc-wiz-options button').first().click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
});

// ============================================================================
// CART empty + full
// ============================================================================
test('cart empty + full', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);

  // Empty cart first (boot may seed an item; clear via React if exposed, else just open)
  const cleared = await page.evaluate(() => {
    if (window.LC && typeof window.LC.setCart === 'function') { window.LC.setCart([]); return true; }
    return false;
  }).catch(() => false);
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  await shotView(page, vp, cleared ? 'cart-empty' : 'cart-initial');
  // close
  const close = page.locator('button.lc-modal-close, button:has-text("Continuer")').first();
  if (await close.isVisible().catch(() => false)) { await close.click().catch(() => {}); await page.waitForTimeout(300); }

  // Add an item via wizard quick-path, then open full cart
  await gotoRoute(page, 'Menu');
  const card = page.locator('button.lc-card-item').first();
  if (await card.isVisible().catch(() => false)) { await card.click().catch(() => {}); await page.waitForTimeout(400); }
  const perso = page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first();
  if (await perso.isVisible().catch(() => false)) { await perso.click().catch(() => {}); await page.waitForTimeout(400); }
  for (let i = 0; i < 8; i++) {
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    if (/Ajouter au panier/i.test(btnTxt)) { await next.click().catch(() => {}); await page.waitForTimeout(500); break; }
    if (await next.isDisabled().catch(() => true)) { await page.locator('.lc-wiz-choice, .lc-wiz-options button').first().click({ timeout: 4000 }).catch(() => {}); await page.waitForTimeout(250); }
    await next.click({ timeout: 4000 }).catch(() => {}); await page.waitForTimeout(350);
  }
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  await shotView(page, vp, 'cart-full');
});

// ============================================================================
// CART EMPTY — open cart, decrement/trash the seeded item to reach the empty state
// ============================================================================
test('cart empty state', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  // remove every line item via the trash button (aria-label "Retirer ... du panier")
  for (let guard = 0; guard < 10; guard++) {
    const trashBtn = page.locator('.lc-cart-row button[aria-label^="Retirer"]').first();
    if (!(await trashBtn.isVisible().catch(() => false))) break;
    await trashBtn.click().catch(() => {});
    await page.waitForTimeout(300);
    const rows = await page.locator('.lc-cart-row').count().catch(() => 0);
    if (rows === 0) break;
  }
  await page.waitForTimeout(300);
  await shotView(page, vp, 'cart-empty');
});

// ============================================================================
// CHECKOUT + PAYMENT (visual-quality lens)
// ============================================================================
test('checkout + payment', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  const order = page.locator('button.lc-btn--orange', { hasText: /Passer commande/ }).first();
  if (await order.isVisible().catch(() => false)) { await order.click().catch(() => {}); await page.waitForTimeout(700); }
  await shotFull(page, vp, 'checkout-full');

  const toPay = page.locator('button.lcf-cta-bar-next, button:has-text("Continuer"), button:has-text("Paiement"), button:has-text("Suivant")').first();
  if (await toPay.isVisible().catch(() => false)) { await toPay.click().catch(() => {}); await page.waitForTimeout(700); }
  await shotFull(page, vp, 'payment-full');
});

// ============================================================================
// ACCOUNT login + register overlay
// ============================================================================
test('account login + register', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const acct = page.locator('button.lc-nav-btn-account, button:has-text("Se connecter")').first();
  if (await acct.isVisible().catch(() => false)) { await acct.click().catch(() => {}); }
  else {
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) { await burger.click().catch(() => {}); await page.waitForTimeout(300); }
    await page.locator('button:has-text("Compte"), button:has-text("Connexion")').first().click().catch(() => {});
  }
  await page.waitForTimeout(500);
  await shotView(page, vp, 'account-login');
  const reg = page.locator('button:has-text("Créer"), button:has-text("Inscription"), button:has-text("S\'inscrire")').first();
  if (await reg.isVisible().catch(() => false)) { await reg.click().catch(() => {}); await page.waitForTimeout(400); await shotView(page, vp, 'account-register'); }
});

// ============================================================================
// LOYALTY (guest), ORDERS, ABOUT — fullPage (long static pages, framing in posters/cards)
// ============================================================================
test('loyalty guest', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Fidélité');
  await shotFull(page, vp, 'loyalty-full');
});

test('orders page', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Commandes');
  await shotFull(page, vp, 'orders-full');
});

test('about page', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, "L'enseigne");
  await shotFull(page, vp, 'about-full');
});

// ============================================================================
// CONFIRM + TRACK (post-payment, visual lens)
// ============================================================================
test('confirm + track', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  const order = page.locator('button.lc-btn--orange', { hasText: /Passer commande/ }).first();
  if (await order.isVisible().catch(() => false)) { await order.click().catch(() => {}); await page.waitForTimeout(700); }
  const toPay = page.locator('button.lcf-cta-bar-next, button:has-text("Continuer"), button:has-text("Paiement")').first();
  if (await toPay.isVisible().catch(() => false)) { await toPay.click().catch(() => {}); await page.waitForTimeout(700); }
  const counter = page.locator('button.lcf-paymethod', { hasText: /caisse/i }).first();
  if (await counter.isVisible().catch(() => false)) { await counter.click().catch(() => {}); await page.waitForTimeout(300); }
  const confirmBtn = page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer|Payer/ }).first();
  if (await confirmBtn.isVisible().catch(() => false)) { await confirmBtn.click().catch(() => {}); await page.waitForTimeout(800); }
  await shotFull(page, vp, 'confirm-full');
  const track = page.locator('button:has-text("Suivre"), button:has-text("Suivi")').first();
  if (await track.isVisible().catch(() => false)) { await track.click().catch(() => {}); await page.waitForTimeout(700); }
  await shotFull(page, vp, 'track-full');
});
