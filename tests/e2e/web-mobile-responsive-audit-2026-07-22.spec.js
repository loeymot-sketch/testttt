// web-mobile-responsive-audit-2026-07-22.spec.js
// Adversarial MOBILE-FIRST audit of the Le Cayenne standalone site (served :8899).
// Device: Pixel 7 (412x839, touch, chromium). Reproduces defects BEFORE any fix.
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//      npx playwright test tests/e2e/web-mobile-responsive-audit-2026-07-22.spec.js --project=chromium
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ ...devices['Pixel 7'] });
test.describe.configure({ mode: 'serial' });

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8899';
const OUT = path.join(__dirname, '..', 'captures', 'web-mobile-2026-07-22');
fs.mkdirSync(OUT, { recursive: true });

const defects = [];
function defect(sev, where, msg) {
  defects.push({ sev, where, msg });
  console.log(`  [${sev}] ${where} :: ${msg}`);
}

async function shot(page, name, full = true) {
  const p = path.join(OUT, name + '.png');
  await page.screenshot({ path: p, fullPage: full }).catch(() => {});
  return p;
}

async function overflow(page, label) {
  const r = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    iw: window.innerWidth,
  }));
  if (r.sw > r.iw + 1) defect('OVERFLOW', label, `horizontal scroll: scrollWidth ${r.sw} > innerWidth ${r.iw}`);
  return r;
}

async function activeRoute(page) {
  return page.evaluate(() => {
    const el = document.querySelector('.lc-nav-link.is-on');
    return el ? el.textContent.trim() : null;
  });
}

async function gotoHome(page) {
  await page.goto(BASE + '/?dev', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.lc-nav', { timeout: 20000 });
  await page.waitForSelector('.lc-hero, .lc-menu-grid', { timeout: 20000 });
  await page.waitForTimeout(500);
}

// ---------------------------------------------------------------------------
test('01 — burger opens mobile menu; every link tappable + navigates', async ({ page }) => {
  await gotoHome(page);
  await shot(page, '01-home', false);
  await overflow(page, 'home');

  // Burger must be visible below 1024px.
  const burger = page.locator('.lc-nav-burger');
  expect(await burger.isVisible()).toBeTruthy();
  const bb = await burger.boundingBox();
  console.log('BURGER box', JSON.stringify(bb));
  if (bb && (bb.width < 44 || bb.height < 44)) defect('TAP-TARGET', 'burger', `${Math.round(bb.width)}x${Math.round(bb.height)} < 44x44 (WCAG 2.5.5)`);

  // Open the drawer.
  await burger.tap();
  await page.waitForTimeout(350);
  await shot(page, '02-burger-open', false);

  // DIAGNOSTIC: measure the drawer + its links precisely.
  const diag = await page.evaluate(() => {
    const m = document.getElementById('lc-mobile-menu');
    if (!m) return { present: false };
    const r = m.getBoundingClientRect();
    const cs = getComputedStyle(m);
    const links = Array.prototype.slice.call(m.querySelectorAll('.lc-mobile-link')).map((l) => {
      const rr = l.getBoundingClientRect();
      return { t: Math.round(rr.top), b: Math.round(rr.bottom), h: Math.round(rr.height), w: Math.round(rr.width),
        inVP: rr.height > 0 && rr.top >= 0 && rr.bottom <= window.innerHeight + 0.5 };
    });
    return { present: true, position: cs.position, zIndex: cs.zIndex,
      rect: { t: Math.round(r.top), b: Math.round(r.bottom), h: Math.round(r.height), w: Math.round(r.width) },
      vh: window.innerHeight, links };
  });
  console.log('MENU DIAG', JSON.stringify(diag));
  if (!diag.present) {
    defect('NAV-CRITICAL', 'mobile-menu', 'drawer #lc-mobile-menu absent from DOM after burger tap');
  } else {
    if (diag.rect.h < 44) defect('NAV-CRITICAL', 'mobile-menu', `drawer collapsed to ${diag.rect.h}px tall (pos=${diag.position}, z=${diag.zIndex}) — links unusable`);
    const usable = diag.links.filter((l) => l.inVP && l.h >= 24).length;
    if (usable < diag.links.length) defect('NAV-CRITICAL', 'mobile-menu', `only ${usable}/${diag.links.length} links usable (in-viewport & tall enough)`);
  }

  // Close for a clean per-link loop.
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(150);

  // Real per-link tap test: open drawer, tap link, assert navigation via .is-on oracle.
  const linkLabels = ['Menu', 'Commandes', 'Fidélité', 'Accueil'];
  for (const label of linkLabels) {
    await gotoHome(page); // reset to a known state each iteration
    await page.locator('.lc-nav-burger').tap();
    await page.waitForTimeout(300);
    const link = page.locator('.lc-mobile-link', { hasText: label });
    let tapped = false;
    try {
      await link.tap({ timeout: 3500 });
      tapped = true;
    } catch (e) {
      defect('NAV', 'mobile-menu', `link "${label}" NOT tappable: ${String(e.message).split('\n')[0]}`);
    }
    await page.waitForTimeout(350);
    if (tapped) {
      const act = await activeRoute(page);
      if (act !== label) defect('NAV', 'mobile-menu', `tap "${label}" did not navigate (active route=${act})`);
      else console.log(`  OK nav "${label}"`);
    }
  }

  console.log('=== BURGER TEST defects so far:', defects.length);
});

// ---------------------------------------------------------------------------
test('02 — nav actions (Panier + Se connecter) reachable & tappable on mobile', async ({ page }) => {
  await gotoHome(page);

  // Cart button
  const cart = page.locator('.lc-nav-btn-cart');
  expect(await cart.isVisible()).toBeTruthy();
  const cbox = await cart.boundingBox();
  if (cbox && cbox.height < 40) defect('TAP-TARGET', 'nav-cart', `${Math.round(cbox.width)}x${Math.round(cbox.height)} too small`);
  try {
    await cart.tap({ timeout: 3500 });
    await page.waitForTimeout(400);
    const cartOpen = await page.locator('.lc-cart-drawer').isVisible().catch(() => false);
    if (!cartOpen) defect('NAV', 'nav-cart', 'tap Panier did not open the cart drawer');
    else await shot(page, '03-cart-empty', false);
    // close
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(250);
  } catch (e) {
    defect('NAV', 'nav-cart', `Panier not tappable: ${String(e.message).split('\n')[0]}`);
  }

  // Account button ("Se connecter" when not authed)
  const acct = page.locator('.lc-nav-btn-account');
  expect(await acct.isVisible()).toBeTruthy();
  try {
    await acct.tap({ timeout: 3500 });
    await page.waitForTimeout(400);
    const modalOpen = await page.locator('.lc-modal[role="dialog"]').isVisible().catch(() => false);
    if (!modalOpen) defect('NAV', 'nav-account', 'tap "Se connecter" did not open the account modal');
    else await shot(page, '04-account-modal', false);
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(250);
  } catch (e) {
    defect('NAV', 'nav-account', `"Se connecter" not tappable: ${String(e.message).split('\n')[0]}`);
  }
});

// ---------------------------------------------------------------------------
test('03 — page walk: menu, product detail, wizard, loyalty (captures + overflow)', async ({ page }) => {
  await gotoHome(page);

  // Go to MENU via the hero CTA (works regardless of burger state).
  await page.locator('.lc-hero-ctas .lc-btn--orange').first().tap();
  await page.waitForTimeout(500);
  await page.waitForSelector('.lc-cat-tab, .lc-menu-grid', { timeout: 10000 });
  await shot(page, '05-menu', false);
  await overflow(page, 'menu');

  // Tap category tabs — ensure they scroll/behave and don't overflow the row.
  const tabsInfo = await page.evaluate(() => {
    const row = document.querySelector('.lc-cat-tabs');
    if (!row) return null;
    return { scrollW: row.scrollWidth, clientW: row.clientWidth, overflowX: getComputedStyle(row).overflowX };
  });
  console.log('CAT TABS', JSON.stringify(tabsInfo));

  // Open first product detail modal.
  const firstCard = page.locator('.lc-card-item').first();
  await firstCard.scrollIntoViewIfNeeded();
  await firstCard.tap();
  await page.waitForTimeout(500);
  const detailOpen = await page.locator('.lc-modal[role="dialog"]').isVisible().catch(() => false);
  if (!detailOpen) defect('MODAL', 'item-detail', 'tapping a product card did not open the detail modal');
  else {
    await shot(page, '06-item-detail', false);
    await overflow(page, 'item-detail');
    // Try to reach the wizard (customize) if present.
    const custom = page.locator('.lc-modal button', { hasText: /personnaliser|composer|choisir|customize/i }).first();
    if (await custom.count()) {
      await custom.tap().catch(() => {});
      await page.waitForTimeout(500);
      const wizOpen = await page.locator('.lc-wiz-body, .lc-wiz-header').first().isVisible().catch(() => false);
      if (wizOpen) {
        await shot(page, '07-wizard', false);
        await overflow(page, 'wizard');
        // wizard footer must not cover its own body content (fixed footer)
        const footProbe = await page.evaluate(() => {
          const f = document.querySelector('.lc-wiz-footer');
          const b = document.querySelector('.lc-wiz-body');
          if (!f || !b) return null;
          const fr = f.getBoundingClientRect(), br = b.getBoundingClientRect();
          return { footTop: Math.round(fr.top), bodyBottom: Math.round(br.bottom), vh: window.innerHeight, footPos: getComputedStyle(f).position };
        });
        console.log('WIZ FOOTER', JSON.stringify(footProbe));
      }
    }
    // close whatever modal is on top
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(250);
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(250);
  }

  // LOYALTY via footer or re-nav: use hero CTA path — go home then loyalty CTA.
  await gotoHome(page);
  await page.locator('.lc-hero-ctas .lc-btn--ghost').first().tap();
  await page.waitForTimeout(500);
  await shot(page, '08-loyalty', false);
  await overflow(page, 'loyalty');
});

// ---------------------------------------------------------------------------
test('99 — SUMMARY', async () => {
  console.log('\n================ MOBILE AUDIT SUMMARY ================');
  console.log('Total defects:', defects.length);
  const bySev = {};
  for (const d of defects) bySev[d.sev] = (bySev[d.sev] || 0) + 1;
  console.log('By severity:', JSON.stringify(bySev));
  for (const d of defects) console.log(`  [${d.sev}] ${d.where} :: ${d.msg}`);
  console.log('Captures dir:', OUT);
  console.log('======================================================\n');
  fs.writeFileSync(path.join(OUT, '_defects.json'), JSON.stringify(defects, null, 2));
});
