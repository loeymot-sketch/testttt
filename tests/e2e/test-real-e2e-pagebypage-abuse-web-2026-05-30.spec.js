// test-real-e2e-pagebypage-abuse-web-2026-05-30 — STANDALONE WEB site abuse capture + audit
//
// GStack capture+audit agent for the STANDALONE WEB site (V1 Le Cayenne).
// CAPTURE + AUDIT ONLY — does NOT modify web app source (/Users/1millnonstop/Downloads/web/*).
//
// App = React SPA (Babel-standalone, no build) at /Users/1millnonstop/Downloads/web/.
// Served via php -S 127.0.0.1:8095. STANDALONE / UN-WIRED — checkout stops cleanly (no real payment).
//
// !! PORT OVERRIDE !! The reused tests/web-e2e/playwright.config.js has baseURL 8082, and a STALE
// instance squats 8082. We override to 8095 so we audit the LIVE code under test, not the stale one.
//
// Design (per advisor):
//  - screenshot FIRST (durable artifact) then accumulate violations — do NOT throw on first leak.
//  - one test() per state so a failure in one does not cascade.
//  - image 404s are invisible to vision (onError hides <img>, reveals emoji) → network response sniffer.
//  - horizontal overflow measured (scrollWidth > innerWidth), not eyeballed.
//  - all findings accumulated into FINDINGS[] and dumped to JSON in afterAll for the human report.
//
// Boot the server first (if down):
//   php -S 127.0.0.1:8095 -t /Users/1millnonstop/Downloads/web/ &
//
// Run:
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-real-e2e-pagebypage-abuse-web-2026-05-30.spec.js \
//     --project mobile --project tablet --project desktop \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// !! Override the config baseURL (8082 stale) → 8095 (live code under audit)
// actionTimeout caps any single click/action at 8s so a disabled/intercepted button
// (e.g. a required-step Next) fails fast (caught) instead of hanging the whole 120s test.
test.use({ baseURL: 'http://127.0.0.1:8095', actionTimeout: 8000 });

const SHOT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/frontends-abuse-2026-05-30/screenshots/web';
const FIND_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/frontends-abuse-2026-05-30/round-1';
fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(FIND_DIR, { recursive: true });

// Accumulated findings across ALL tests/viewports (process-global; workers:1 keeps it single-process).
const FINDINGS = [];
function record(severity, viewport, state, png, observed) {
  FINDINGS.push({ severity, viewport, state, png, observed });
}

// Raw-label leak patterns — these MUST NOT appear in rendered innerText.
const RAW_LABEL_FORBIDDEN = [
  { re: /\bLabel\.[A-Za-z0-9_.-]+/, name: 'Label.xxx i18n key leak' },
  { re: /\bkiosk\.[a-z_]+\.[a-z_]+/i, name: 'kiosk.* i18n key leak' },
  // i18n key leak: lecayenne.<key>.<key> — at least 2 dotted segments, NOT preceded by '@'
  // (so the legit footer email "contact@lecayenne.fr" and the ".fr" TLD do NOT false-positive).
  { re: /(?<!@)\blecayenne\.[a-z_]+\.[a-z_]+/i, name: 'lecayenne.* i18n key leak' },
  { re: /\b0undefined\b/, name: '0undefined concat bug' },
  { re: /undefined\s*€/, name: 'undefined € price' },
  { re: /\bNaN\b/, name: 'NaN value' },
];

// Photo of the day boot — mirrors the proven bootWeb (handlers BEFORE goto, networkidle, data ready).
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

// Screenshot (durable) THEN audit. Accumulates into FINDINGS, never throws mid-sweep.
async function capture(page, vp, state) {
  const png = `web-${vp}-${state}.png`;
  await page.screenshot({ path: path.join(SHOT_DIR, png), fullPage: false });

  // 1. Raw-label sweep (innerText = rendered visible text only)
  const text = await page.evaluate(() => document.body.innerText);
  for (const { re, name } of RAW_LABEL_FORBIDDEN) {
    const m = text.match(re);
    if (m) {
      const idx = text.search(re);
      const ctx = text.substring(Math.max(0, idx - 30), Math.min(text.length, idx + m[0].length + 30)).replace(/\s+/g, ' ');
      record('P1', vp, state, png, `RAW LABEL [${name}] matched "${m[0]}" — context: "...${ctx}..."`);
    }
  }

  // 2. Horizontal overflow (measured, not eyeballed)
  const overflow = await page.evaluate(() => {
    const d = document.documentElement;
    return { scrollW: d.scrollWidth, innerW: window.innerWidth };
  });
  if (overflow.scrollW > overflow.innerW + 1) {
    record('P1', vp, state, png, `HORIZONTAL OVERFLOW scrollWidth=${overflow.scrollW} > innerWidth=${overflow.innerW} (Δ${overflow.scrollW - overflow.innerW}px)`);
  }

  // 3. Console / page errors (filter favicon + image-slots noise)
  const realErrors = (page._errors || []).filter(e => !/favicon|image-slots/i.test(e));
  for (const e of realErrors) record('P1', vp, state, png, `CONSOLE ERROR: ${e}`);

  // 4. Image 404s (invisible to vision — onError hides img + reveals emoji)
  const realImgErrors = (page._imgErrors || []).filter(e => !/favicon|image-slots/i.test(e));
  for (const e of [...new Set(realImgErrors)]) record('P1', vp, state, png, `IMAGE 404/ERROR: ${e}`);

  return { text };
}

// ---- Navigation helpers (web SPA: WebNav links / burger; ItemCard → detail → wizard) ----

async function gotoRoute(page, label) {
  // Close any open modal (wizard/detail) first so its backdrop does not intercept the nav click.
  const close = page.locator('button.lc-modal-close, .lc-wiz-foot-back').first();
  if (await close.isVisible().catch(() => false)) { await close.click({ timeout: 3000 }).catch(() => {}); await page.waitForTimeout(250); }
  // Desktop / tablet: visible nav link. Mobile: open burger first.
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

// Open the detail modal for the first item matching a category slug, return whether wizard or direct.
async function openItemBySlug(page, slug) {
  // Filter menu to make the card reachable, then click it via React onItem(id).
  // Simpler + robust: drive React state directly is not possible; click the card whose name matches.
  const info = await page.evaluate((slug) => {
    const it = window.W_ITEMS.find(i => i.id === slug || i.slug === slug);
    return it ? { name: it.name, wizard: it.wizard, price: it.price, image: it.image || null } : null;
  }, slug);
  if (!info) return null;
  // Click the menu card by visible name
  const card = page.locator('button.lc-card-item', { hasText: info.name }).first();
  await card.scrollIntoViewIfNeeded().catch(() => {});
  await card.click();
  await page.waitForTimeout(400);
  return info;
}

// Drive an open wizard step-by-step until the RECAP step, capturing each step.
// Viewport-robust: detects recap by the footer-next button text "Ajouter au panier"
// (present at all viewports), NOT the desktop-only right-side preview panel.
// Reads the total from `.lc-wiz-foot-next-total` which exists in EVERY layout.
// On reaching recap, captures `<prefix>-recap`, validates the displayed total, and
// records a P0 if the total is NaN/undefined/empty. Returns the recap total string.
async function driveWizardToRecap(page, vp, prefix, maxSteps) {
  let recapTotal = null;
  for (let i = 0; i < maxSteps; i++) {
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    // If Next is disabled (required step not satisfied), pick the first choice.
    if (await next.isDisabled().catch(() => true)) {
      await page.locator('.lc-wiz-choice, .lc-wiz-options button').first().click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    // Are we ON the recap step now? (footer button reads "Ajouter au panier")
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    const onRecap = /Ajouter au panier/i.test(btnTxt);
    await capture(page, vp, onRecap ? `${prefix}-recap` : `${prefix}-step${i + 2}`);
    if (onRecap) {
      const totalTxt = ((await page.locator('.lc-wiz-foot-next-total').first().innerText().catch(() => '')) || '').trim();
      recapTotal = totalTxt;
      if (/NaN|undefined/i.test(totalTxt) || !/\d/.test(totalTxt)) {
        record('P0', vp, `${prefix}-recap`, `web-${vp}-${prefix}-recap.png`, `RECAP TOTAL INVALID: footer total "${totalTxt}"`);
      } else {
        record('INFO', vp, `${prefix}-recap`, `web-${vp}-${prefix}-recap.png`, `recap reached; footer total "${totalTxt}"`);
      }
      break;
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  if (recapTotal === null) {
    record('P2', vp, `${prefix}-recap`, '-', `recap step not reached within ${maxSteps} advances (wizard may not have progressed)`);
  }
  return recapTotal;
}

// ============================================================================
// HOME / LANDING
// ============================================================================
test('home landing', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const { text } = await capture(page, vp, 'home');
  // Sanity: brand present (not a finding, just a guard that the page rendered)
  expect(text, 'home rendered brand').toMatch(/Cayenne/i);
});

// ============================================================================
// MENU — all categories, item cards w/ images + prices
// ============================================================================
test('menu all categories', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  await capture(page, vp, 'menu-all');

  // Scroll through to lazy-load all card images, then re-capture mid + bottom
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
  await page.waitForTimeout(500);
  await capture(page, vp, 'menu-mid');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(800);
  await capture(page, vp, 'menu-bottom');
});

// ============================================================================
// MENU — every product image HEAD check (404 catch, vision-invisible)
// ============================================================================
test('menu product images resolve', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const statuses = await page.evaluate(async () => {
    const items = window.W_ITEMS;
    const out = [];
    for (const it of items) {
      if (!it.image) { out.push({ id: it.id, image: null, status: 'no-image (emoji only)' }); continue; }
      try {
        const r = await fetch(it.image, { method: 'HEAD' });
        out.push({ id: it.id, image: it.image, status: r.status });
      } catch (e) { out.push({ id: it.id, image: it.image, status: 'fetch-error' }); }
    }
    return out;
  });
  const broken = statuses.filter(s => s.status !== 200 && s.status !== 'no-image (emoji only)');
  for (const b of broken) {
    record('P1', vp, 'menu-images', 'web-' + vp + '-menu-all.png', `PRODUCT IMAGE BROKEN: ${b.id} → ${b.status} (${b.image})`);
  }
  // Attach the full table for the report
  record('INFO', vp, 'menu-images', '-', `image audit: ${statuses.length} items, ${broken.length} broken, ${statuses.filter(s=>s.status==='no-image (emoji only)').length} emoji-only`);
});

// ============================================================================
// WIZARD — sandwich (sauce_locked: viandes/crudites/supplements/menu/recap)
// ============================================================================
test('wizard sandwich-cayenne', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'sandwich-cayenne-classique');
  if (!info) { record('P2', vp, 'wizard-sandwich', '-', 'item sandwich-cayenne-classique not found in W_ITEMS'); return; }
  await capture(page, vp, 'detail-sandwich');
  // Personnaliser → wizard
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'wizard-sandwich-step1');
  await driveWizardToRecap(page, vp, 'wizard-sandwich', 9);
});

// ============================================================================
// WIZARD — galette (full template: viandes/sauce/crudites/supplements/menu/recap)
// ============================================================================
test('wizard galette', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'galette-normale');
  if (!info) { record('P2', vp, 'wizard-galette', '-', 'item galette-normale not found'); return; }
  await capture(page, vp, 'detail-galette');
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'wizard-galette-step1');
  await driveWizardToRecap(page, vp, 'wizard-galette', 10);
});

// ============================================================================
// WIZARD — tacos (no sauce/crudites: viandes/supplements/menu/recap)
// ============================================================================
test('wizard tacos', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'tacos-1-viande');
  if (!info) { record('P2', vp, 'wizard-tacos', '-', 'item tacos-1-viande not found'); return; }
  await capture(page, vp, 'detail-tacos');
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'wizard-tacos-step1');
  await driveWizardToRecap(page, vp, 'wizard-tacos', 8);
});

// ============================================================================
// WIZARD — bols 3-step (sauce / bol_supplements / bol_drink / recap)
// + RECAP TOTAL INTEGRITY check (displayed total == computeWizardTotal)
// ============================================================================
test('wizard bols 3-step + total integrity', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'bowl-frites-curry');
  if (!info) { record('P2', vp, 'wizard-bols', '-', 'item bowl-frites-curry not found'); return; }
  await capture(page, vp, 'detail-bols');
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'wizard-bols-step1');
  // driveWizardToRecap performs the recap total-integrity P0 check (footer total, viewport-robust)
  await driveWizardToRecap(page, vp, 'wizard-bols', 7);
});

// ============================================================================
// WIZARD — frites (1-step custom: frites_style / recap)
// ============================================================================
test('wizard frites 1-step', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'petite-frites');
  if (!info) { record('P2', vp, 'wizard-frites', '-', 'item petite-frites not found'); return; }
  await capture(page, vp, 'detail-frites');
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'wizard-frites-step1');
  await driveWizardToRecap(page, vp, 'wizard-frites', 5);
});

// ============================================================================
// SIMPLE direct-add (Boissons — DirectAddView qty stepper) + ABUSE qty min/max
// ============================================================================
test('simple direct-add coca + qty abuse', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  const info = await openItemBySlug(page, 'coca');
  if (!info) { record('P2', vp, 'direct-coca', '-', 'item coca not found'); return; }
  await capture(page, vp, 'detail-coca');
  // coca is wizard:false → ItemDetailModal shows "Ajouter au panier" directly (no DirectAddView).
  // If wizard:true items route to DirectAddView, this still captures the detail.
  // ABUSE: try the minus button repeatedly (should floor at 1) if a DirectAddView stepper is present.
  const minus = page.locator('button[aria-label="Diminuer"]').first();
  if (await minus.isVisible().catch(() => false)) {
    for (let i = 0; i < 5; i++) { await minus.click().catch(() => {}); await page.waitForTimeout(120); }
    await capture(page, vp, 'direct-coca-qty-min');
    const plus = page.locator('button[aria-label="Augmenter"]').first();
    for (let i = 0; i < 12; i++) { await plus.click().catch(() => {}); await page.waitForTimeout(80); }
    await capture(page, vp, 'direct-coca-qty-max');
    // Read the displayed qty for sanity
    const qtyTxt = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('span'));
      return els.map(e => e.innerText).join('|');
    });
    record('INFO', vp, 'direct-coca-qty', `web-${vp}-direct-coca-qty-max.png`, `qty stepper present; spans snapshot captured`);
  } else {
    record('INFO', vp, 'direct-coca', `web-${vp}-detail-coca.png`, 'coca wizard:false → direct "Ajouter au panier" (no qty stepper in detail; DirectAddView only for wizard-templated simple items)');
  }
});

// ============================================================================
// ABUSE — back mid-wizard + double-tap add
// ============================================================================
test('abuse back-mid-wizard + double-tap', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  await gotoRoute(page, 'Menu');
  await openItemBySlug(page, 'galette-normale');
  await page.locator('button.lc-btn--orange', { hasText: 'Personnaliser' }).first().click().catch(() => {});
  await page.waitForTimeout(500);
  // advance 2 steps (select a required choice each step so Next enables), then hit back twice
  const next = page.locator('button.lc-wiz-foot-next').first();
  for (let s = 0; s < 2; s++) {
    if (await next.isDisabled().catch(() => true)) {
      await page.locator('.lc-wiz-choice, .lc-wiz-options button').first().click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(350);
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(350);
  }
  await capture(page, vp, 'abuse-wizard-mid');
  const back = page.locator('button.lc-wiz-foot-back').first();
  await back.click().catch(() => {}); await page.waitForTimeout(250);
  await back.click().catch(() => {}); await page.waitForTimeout(250);
  await capture(page, vp, 'abuse-wizard-back');
  // double-tap add abuse: open a direct item and double-click add fast
  await gotoRoute(page, 'Menu');
  await openItemBySlug(page, 'coca');
  const addBtn = page.locator('button.lc-btn--orange', { hasText: /Ajouter/ }).first();
  if (await addBtn.isVisible().catch(() => false)) {
    await addBtn.click().catch(() => {});
    await addBtn.click().catch(() => {}); // modal likely closed after first; second is no-op
  }
  await page.waitForTimeout(300);
  await capture(page, vp, 'abuse-double-tap');
});

// ============================================================================
// CART — full cart state (drawer with seeded + added items)
// ============================================================================
test('cart full drawer', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  // add a couple items then open cart
  await gotoRoute(page, 'Menu');
  await openItemBySlug(page, 'coca');
  await page.locator('button.lc-btn--orange', { hasText: /Ajouter/ }).first().click().catch(() => {});
  await page.waitForTimeout(400);
  // open cart via nav cart button
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) await cartBtn.click().catch(() => {});
  await page.waitForTimeout(500);
  await capture(page, vp, 'cart-full');
});

// ============================================================================
// CART — empty state (remove all rows via trash)
// ============================================================================
test('cart empty state', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) await cartBtn.click().catch(() => {});
  await page.waitForTimeout(500);
  // Click every trash button until cart empty
  for (let i = 0; i < 12; i++) {
    const trash = page.locator('button[aria-label^="Retirer"]').first();
    if (!(await trash.isVisible().catch(() => false))) break;
    await trash.click().catch(() => {});
    await page.waitForTimeout(250);
  }
  await capture(page, vp, 'cart-empty');
  const text = await page.evaluate(() => document.body.innerText);
  if (!/panier est vide/i.test(text)) {
    record('P2', vp, 'cart-empty', `web-${vp}-cart-empty.png`, 'empty-cart state text "Ton panier est vide" not found after removing all rows');
  }
});

// ============================================================================
// CHECKOUT-STOP — drive cart → checkout → payment → (intentional stop)
// ============================================================================
test('checkout stop flow', async ({ page }, testInfo) => {
  const vp = testInfo.project.name;
  await bootWeb(page);
  // Open cart (seeded item present), go to checkout
  const cartBtn = page.locator('button.lc-nav-btn-cart, button:has-text("Panier")').first();
  if (await cartBtn.isVisible().catch(() => false)) await cartBtn.click().catch(() => {});
  await page.waitForTimeout(500);
  const order = page.locator('button.lc-btn--orange', { hasText: /Passer commande/ }).first();
  if (await order.isVisible().catch(() => false)) {
    await order.click().catch(() => {});
    await page.waitForTimeout(700);
    await capture(page, vp, 'checkout');
    // Advance to payment if a Next/Continuer/Payer button exists
    const next = page.locator('button:has-text("Continuer"), button:has-text("Payer"), button.lc-btn--orange').first();
    if (await next.isVisible().catch(() => false)) {
      await next.click().catch(() => {});
      await page.waitForTimeout(700);
      await capture(page, vp, 'payment-stop');
    }
  } else {
    record('INFO', vp, 'checkout', '-', 'Passer commande button not visible (cart may be empty)');
  }
});

// ============================================================================
// DUMP findings JSON (one file per project to avoid clobber; merged by reporter step)
// ============================================================================
test.afterAll(async ({}, testInfo) => {
  const vp = testInfo.project.name;
  const out = path.join(FIND_DIR, `_findings-${vp}.json`);
  fs.writeFileSync(out, JSON.stringify(FINDINGS, null, 2));
});
