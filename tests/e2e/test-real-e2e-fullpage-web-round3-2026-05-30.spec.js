// test-real-e2e-fullpage-web-round3-2026-05-30 — STANDALONE WEB site FULL-PAGE sweep (round 3)
//
// Owner mandate: "the website must be aligned with ALL the pages until the payment page —
// all pages, even the direct or hidden ones."
//
// Round-1 (test-real-e2e-pagebypage-abuse-web-2026-05-30.spec.js) covered home/menu/wizards/cart/
// checkout/payment-stop. This round-3 EXTENDS coverage to the surfaces round-1 does NOT touch:
//   - orders, loyalty (guest + authed), about
//   - confirm, track (the "hidden/direct" pages — only reachable past the payment stop)
//   - AccountFlow (login + register), ItemDetailModal
//   - menu-formule CASCADE (round-1's driveWizardToRecap clicks first option = "Sans formule",
//     so the cascade drink/frites/sauce steps never fire — exercised explicitly here)
//   - the 5 static legal HTML pages (cgv / cookies / mentions / privacy / allergens)
//
// FULL-PAGE captures (fullPage:true) so below-fold defects on long static pages are caught.
// CAPTURE + AUDIT — does NOT modify web app source. Findings accumulated, never throws mid-sweep.
//
// App = React SPA (Babel-standalone, no build) at /Users/1millnonstop/Downloads/web/, served on 8095.
// The App route state machine (index.html): home|menu|orders|loyalty|about|checkout|payment|confirm|track.
// We drive routes via the visible WebNav links where possible, and via direct in-page React-state
// helpers for the hidden post-payment pages (confirm/track) and the account/detail overlays.
//
// Run:
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-real-e2e-fullpage-web-round3-2026-05-30.spec.js \
//     --project mobile --project tablet --project desktop \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ baseURL: 'http://127.0.0.1:8095', actionTimeout: 8000 });

const SHOT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/frontends-abuse-2026-05-30/screenshots/web-fullpage';
const FIND_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/frontends-abuse-2026-05-30/round-3';
fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(FIND_DIR, { recursive: true });

const FINDINGS = [];
function record(severity, viewport, state, png, observed) {
  FINDINGS.push({ severity, viewport, state, png, observed });
}

const RAW_LABEL_FORBIDDEN = [
  { re: /\bLabel\.[A-Za-z0-9_.-]+/, name: 'Label.xxx i18n key leak' },
  { re: /\bkiosk\.[a-z_]+\.[a-z_]+/i, name: 'kiosk.* i18n key leak' },
  { re: /(?<!@)\blecayenne\.[a-z_]+\.[a-z_]+/i, name: 'lecayenne.* i18n key leak' },
  { re: /\b0undefined\b/, name: '0undefined concat bug' },
  { re: /undefined\s*€/, name: 'undefined € price' },
  { re: /\bNaN\b/, name: 'NaN value' },
];

async function bootWeb(page) {
  page._errors = [];
  page._imgErrors = [];
  page.on('pageerror', err => page._errors.push(err.message));
  page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
  page.on('response', r => {
    try {
      if (r.request().resourceType() === 'image' && r.status() >= 400) page._imgErrors.push(`${r.status()} ${r.url()}`);
    } catch (e) { /* ignore */ }
  });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}

// FULL-PAGE screenshot (durable) THEN audit. Accumulates into FINDINGS, never throws mid-sweep.
async function capture(page, vp, state, fullPage = true) {
  const png = `web-${vp}-${state}.png`;
  await page.screenshot({ path: path.join(SHOT_DIR, png), fullPage }).catch(async () => {
    // some overlay states can't fullPage; fall back to viewport
    await page.screenshot({ path: path.join(SHOT_DIR, png), fullPage: false }).catch(() => {});
  });

  const text = await page.evaluate(() => document.body.innerText);
  for (const { re, name } of RAW_LABEL_FORBIDDEN) {
    const m = text.match(re);
    if (m) {
      const idx = text.search(re);
      const ctx = text.substring(Math.max(0, idx - 30), Math.min(text.length, idx + m[0].length + 30)).replace(/\s+/g, ' ');
      record('P1', vp, state, png, `RAW LABEL [${name}] matched "${m[0]}" — context: "...${ctx}..."`);
    }
  }

  const overflow = await page.evaluate(() => {
    const d = document.documentElement;
    return { scrollW: d.scrollWidth, innerW: window.innerWidth };
  });
  if (overflow.scrollW > overflow.innerW + 1) {
    record('P1', vp, state, png, `HORIZONTAL OVERFLOW scrollWidth=${overflow.scrollW} > innerWidth=${overflow.innerW} (Δ${overflow.scrollW - overflow.innerW}px)`);
  }

  const realErrors = (page._errors || []).filter(e => !/favicon|image-slots/i.test(e));
  for (const e of realErrors) record('P1', vp, state, png, `CONSOLE ERROR: ${e}`);
  page._errors = []; // reset so we attribute errors to the state that produced them

  const realImgErrors = (page._imgErrors || []).filter(e => !/favicon|image-slots/i.test(e));
  for (const e of [...new Set(realImgErrors)]) record('P1', vp, state, png, `IMAGE 404/ERROR: ${e}`);
  page._imgErrors = [];

  // blank/white-screen guard (P0): root has < 40 visible chars => likely a crash/blank
  if (!text || text.replace(/\s/g, '').length < 40) {
    record('P0', vp, state, png, `BLANK/EMPTY render — innerText length ${text ? text.replace(/\s/g,'').length : 0}`);
  }
  return { text };
}

// Navigate via the WebNav (desktop link or mobile burger).
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
// ORDERS page (route 'orders') — guest re-order screen
// ============================================================================
test('orders page', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Commandes');           // nav id 'orders', label 'Commandes'
  const onOrders = await page.evaluate(() => /command/i.test(document.body.innerText));
  await capture(page, vp, 'orders');
  if (!onOrders) record('INFO', vp, 'orders', `web-${vp}-orders.png`, 'orders route may not be linked in nav (captured whatever rendered)');
});

// ============================================================================
// LOYALTY page — guest state
// ============================================================================
test('loyalty guest', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Fidélité');
  await capture(page, vp, 'loyalty-guest');
});

// ============================================================================
// ABOUT page (L'enseigne) — long static page, full-page
// ============================================================================
test('about page', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, "L'enseigne");
  await capture(page, vp, 'about');
});

// ============================================================================
// ACCOUNT FLOW overlay — login + register (drive open via nav account button)
// ============================================================================
test('account flow login + register', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  // Open the account flow: the nav account button (guest → opens AccountFlow modal)
  const acct = page.locator('button.lc-nav-btn-account, button:has-text("Se connecter")').first();
  if (await acct.isVisible().catch(() => false)) {
    await acct.click().catch(() => {});
  } else {
    // mobile: open burger, then account
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) { await burger.click().catch(() => {}); await page.waitForTimeout(300); }
    await page.locator('button:has-text("Compte"), button:has-text("Connexion")').first().click().catch(() => {});
  }
  await page.waitForTimeout(500);
  await capture(page, vp, 'account-login', false);
  // Switch to register tab if present
  const reg = page.locator('button:has-text("Créer"), button:has-text("Inscription"), button:has-text("S\'inscrire")').first();
  if (await reg.isVisible().catch(() => false)) {
    await reg.click().catch(() => {});
    await page.waitForTimeout(400);
    await capture(page, vp, 'account-register', false);
  } else {
    record('INFO', vp, 'account-register', '-', 'register tab/button not found in AccountFlow (login-only state captured)');
  }
});

// ============================================================================
// LOYALTY authed — log in first (any email/pwd in this mock), then loyalty renders authed
// ============================================================================
test('loyalty authed', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  // open account modal and submit to flip isAuth → loyalty (App.onAuthed → setRoute('loyalty'))
  const acct = page.locator('button.lc-nav-btn-account, button:has-text("Se connecter")').first();
  if (await acct.isVisible().catch(() => false)) { await acct.click().catch(() => {}); }
  else {
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) { await burger.click().catch(() => {}); await page.waitForTimeout(300); }
    await page.locator('button:has-text("Compte"), button:has-text("Connexion")').first().click().catch(() => {});
  }
  await page.waitForTimeout(400);
  // fill any visible inputs and submit the primary CTA
  const inputs = page.locator('.lc-modal input');
  const n = await inputs.count().catch(() => 0);
  for (let i = 0; i < n; i++) {
    const t = await inputs.nth(i).getAttribute('type').catch(() => '');
    await inputs.nth(i).fill(t === 'password' ? 'Password123' : (t === 'email' ? 'test@lecayenne.fr' : 'Test User')).catch(() => {});
  }
  const submit = page.locator('.lc-modal button.lc-btn--orange, .lc-modal button[type="submit"]').first();
  if (await submit.isVisible().catch(() => false)) { await submit.click().catch(() => {}); await page.waitForTimeout(700); }
  await capture(page, vp, 'loyalty-authed');
  const authed = await page.evaluate(() => /points|fidélité|récompense|pts/i.test(document.body.innerText));
  if (!authed) record('INFO', vp, 'loyalty-authed', `web-${vp}-loyalty-authed.png`, 'authed loyalty markers not detected (auth submit may differ in this mock)');
});

// ============================================================================
// ITEM DETAIL modal — open a product detail (full board photo + price)
// ============================================================================
test('item detail modal', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  // click first product card → ItemDetailModal
  const card = page.locator('button.lc-card-item').first();
  if (await card.isVisible().catch(() => false)) {
    await card.scrollIntoViewIfNeeded().catch(() => {});
    await card.click().catch(() => {});
    await page.waitForTimeout(500);
    await capture(page, vp, 'item-detail', false);
    const txt = await page.evaluate(() => document.body.innerText);
    if (!/€/.test(txt)) record('P2', vp, 'item-detail', `web-${vp}-item-detail.png`, 'no € price visible in detail modal');
  } else {
    record('P2', vp, 'item-detail', '-', 'no product card found to open detail modal');
  }
});

// ============================================================================
// MENU-FORMULE CASCADE — open a sandwich, customize, pick FULL formule (NOT "Sans formule")
// so cascade_drink / cascade_frites_style / cascade_frites_sauce fire. Capture each cascade step.
// ============================================================================
test('menu-formule cascade', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  // open sandwich-cayenne-classique detail then Personnaliser → wizard
  const info = await page.evaluate(() => {
    const it = window.W_ITEMS.find(i => i.slug === 'sandwich-cayenne-classique' || i.id === 'sandwich-cayenne-classique');
    return it ? it.name : null;
  });
  if (!info) { record('P2', vp, 'cascade', '-', 'sandwich-cayenne-classique not in W_ITEMS'); return; }
  const card = page.locator('button.lc-card-item', { hasText: info }).first();
  await card.scrollIntoViewIfNeeded().catch(() => {});
  await card.click().catch(() => {});
  await page.waitForTimeout(400);
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);

  // Step through: for each step, if it's the MENU/formule step, pick the FULL formule
  // (option text contains "Menu" / "Frites + Boisson" / has a price), else pick first choice.
  // Detect the formule step by the presence of an option labelled with "formule"/"Menu complet".
  let firedCascade = false;
  for (let i = 0; i < 12; i++) {
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    if (/Ajouter au panier/i.test(btnTxt)) {
      await capture(page, vp, 'cascade-recap');
      const totalTxt = ((await page.locator('.lc-wiz-foot-next-total').first().innerText().catch(() => '')) || '').trim();
      if (/NaN|undefined/i.test(totalTxt) || !/\d/.test(totalTxt)) {
        record('P0', vp, 'cascade-recap', `web-${vp}-cascade-recap.png`, `CASCADE RECAP TOTAL INVALID: "${totalTxt}"`);
      } else {
        record('INFO', vp, 'cascade-recap', `web-${vp}-cascade-recap.png`, `cascade recap total "${totalTxt}" (cascadeFired=${firedCascade})`);
      }
      break;
    }
    // Is this the formule/menu step? look for a "Menu" / "formule" / "Frites + Boisson" option.
    const formuleOpt = page.locator('.lc-wiz-choice, .lc-wiz-options button').filter({ hasText: /menu complet|formule|frites \+ boisson|\+ \d|sandwich \+/i }).first();
    const isFormuleStep = await formuleOpt.isVisible().catch(() => false);
    if (isFormuleStep) {
      await formuleOpt.click().catch(() => {});   // pick the FULL formule (triggers cascade)
      firedCascade = true;
      await page.waitForTimeout(350);
      await capture(page, vp, `cascade-step${i + 1}-formule`);
    } else {
      // capture cascade sub-steps explicitly by title
      const title = (await page.locator('.lc-wiz-title, .lc-wiz-step-title, h2, h3').first().innerText().catch(() => '')) || '';
      if (/boisson|frites|sauce/i.test(title) && firedCascade) {
        await capture(page, vp, `cascade-${title.toLowerCase().replace(/[^a-z]/g, '').slice(0, 12)}`);
      }
      if (await next.isDisabled().catch(() => true)) {
        await page.locator('.lc-wiz-choice, .lc-wiz-options button').first().click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(300);
      }
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  if (!firedCascade) record('P2', vp, 'cascade', '-', 'menu-formule step not reached / full formule not selected — cascade not exercised');
});

// ============================================================================
// CONFIRM + TRACK — the "hidden/direct" post-payment pages.
// Drive the real flow: cart → checkout → payment → Confirmer la commande → confirm → track.
// ============================================================================
test('confirm + track (hidden post-payment pages)', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  // Open cart (seeded item present) → Passer commande → checkout
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click().catch(() => {}); await page.waitForTimeout(500); }
  const order = page.locator('button.lc-btn--orange', { hasText: /Passer commande/ }).first();
  if (await order.isVisible().catch(() => false)) { await order.click().catch(() => {}); await page.waitForTimeout(700); }
  await capture(page, vp, 'checkout');

  // checkout → payment (continue)
  const toPay = page.locator('button.lcf-cta-bar-next, button:has-text("Continuer"), button:has-text("Paiement"), button:has-text("Suivant")').first();
  if (await toPay.isVisible().catch(() => false)) { await toPay.click().catch(() => {}); await page.waitForTimeout(700); }
  await capture(page, vp, 'payment');

  // payment → confirm: counter method is default-recommended; click "Confirmer la commande"
  const counter = page.locator('button.lcf-paymethod', { hasText: /caisse/i }).first();
  if (await counter.isVisible().catch(() => false)) { await counter.click().catch(() => {}); await page.waitForTimeout(300); }
  const confirmBtn = page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer|Payer/ }).first();
  if (await confirmBtn.isVisible().catch(() => false)) { await confirmBtn.click().catch(() => {}); await page.waitForTimeout(800); }
  const { text: confirmTxt } = await capture(page, vp, 'confirm');
  if (!/command|confirm|merci|reçu|ticket|numéro/i.test(confirmTxt)) {
    record('P1', vp, 'confirm', `web-${vp}-confirm.png`, 'confirmation page did not render expected confirmation markers');
  }

  // confirm → track (Suivre / Suivi)
  const track = page.locator('button:has-text("Suivre"), button:has-text("Suivi"), button.lc-btn--orange').first();
  if (await track.isVisible().catch(() => false)) { await track.click().catch(() => {}); await page.waitForTimeout(700); }
  const { text: trackTxt } = await capture(page, vp, 'track');
  if (!/suivi|préparation|prête|en cours|statut|minute|min/i.test(trackTxt)) {
    record('INFO', vp, 'track', `web-${vp}-track.png`, 'tracking page markers not detected (button path may differ)');
  }
});

// ============================================================================
// LEGAL static HTML pages — direct nav, full-page (cgv/cookies/mentions/privacy/allergens)
// ============================================================================
for (const legal of ['mentions', 'cgv', 'privacy', 'cookies', 'allergens']) {
  test(`legal ${legal}`, async ({ page }, testInfo) => {
    const vp = testInfo.project.name;
    page._errors = []; page._imgErrors = [];
    page.on('pageerror', err => page._errors.push(err.message));
    page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
    page.on('response', r => {
      try { if (r.request().resourceType() === 'image' && r.status() >= 400) page._imgErrors.push(`${r.status()} ${r.url()}`); } catch (e) {}
    });
    const resp = await page.goto(`/legal/${legal}.html`, { waitUntil: 'networkidle' }).catch(() => null);
    await page.waitForTimeout(400);
    if (resp && resp.status() >= 400) {
      record('P1', vp, `legal-${legal}`, '-', `legal page HTTP ${resp.status()}`);
    }
    await capture(page, vp, `legal-${legal}`);
  });
}

test.afterAll(async ({}, testInfo) => {
  const vp = testInfo.project.name;
  const out = path.join(FIND_DIR, `_findings-${vp}.json`);
  fs.writeFileSync(out, JSON.stringify(FINDINGS, null, 2));
});
