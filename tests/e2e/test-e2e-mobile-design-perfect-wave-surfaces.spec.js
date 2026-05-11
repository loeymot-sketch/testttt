// Wave-Surfaces — mobile-design-perfect-2026-05-11 round-1
// 16 surfaces hors-wizard. Cross-validates cluster-1..4 fixes still closed +
// hunts S-001 (RGPD opt-out lies — POINTS card not gated, balance not zeroed).

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('../e2e/helpers/mega-audit-snap');

const OUT = path.resolve(__dirname, '__screenshots__/test-e2e-mobile-design-perfect-wave-surfaces');

async function bootstrapMobile(page, { authed = true } = {}) {
  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage && !!window.LC.menu, { timeout: 15_000 });
  await page.evaluate((authed) => {
    window.LC.storage.clearAuth();
    if (authed) {
      window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
      window.LC.storage.markOnboardingSeen();
    }
    window.LC.storage.clearCart();
  }, authed);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 15_000 });
}

async function gotoTab(page, tabRegex) {
  await page.evaluate((r) => {
    const tab = Array.from(document.querySelectorAll('.lc-tab')).find(el => new RegExp(r, 'i').test(el.textContent || ''));
    if (tab) tab.click();
  }, tabRegex.source);
  await page.waitForTimeout(150);
}

async function gotoLoyalty(page) {
  await gotoTab(page, /Profil/);
  await page.waitForTimeout(300);
  // React doesn't render onClick as DOM attribute. Locate by visible text + style.cursor=pointer.
  await page.locator('text=/VOIR MON QR/i').first().click({ timeout: 5_000 });
  await page.waitForSelector('[data-testid="loyalty-screen"]', { timeout: 5_000 });
}

test.describe('Wave-Surfaces — 16 surfaces hors-wizard', () => {
  let recorder;
  test.beforeAll(async () => { fs.mkdirSync(OUT, { recursive: true }); });
  test.beforeEach(async ({ page }) => { recorder = attachMegaAuditRecorder(page, OUT); });
  test.afterEach(async () => { if (recorder?.dispose) recorder.dispose(); });

  test('S01 — Splash visible', async ({ page }) => {
    await page.goto('/index.html');
    await page.waitForSelector('[data-screen-label]', { timeout: 5_000 });
    await recorder.snap('01-splash');
  });

  test('S02 — Onboarding 1', async ({ page }) => {
    await page.goto('/index.html');
    await page.evaluate(() => {
      window.LC.storage.clearAuth();
      localStorage.removeItem('lecayenne.onboarding_seen');
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2200);
    await recorder.snap('02-onb1');
  });

  test('S03 — Login phone screen', async ({ page }) => {
    await bootstrapMobile(page, { authed: false });
    await page.evaluate(() => window.LC.storage.markOnboardingSeen());
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-screen-label*="Login"], [data-screen-label*="05"]', { timeout: 5_000 });
    await recorder.snap('03-login');
  });

  test('S04 — Home greeting + featured + tabbar', async ({ page }) => {
    await bootstrapMobile(page);
    await page.waitForSelector('[data-screen-label*="Home"], [data-screen-label*="07"]', { timeout: 5_000 });
    await recorder.snap('04-home');
  });

  test('S05 — Home "Envies du moment" carousel (S-005 hunt)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.waitForSelector('[data-screen-label*="Home"], [data-screen-label*="07"]');
    // Scroll to envies section
    await page.evaluate(() => window.scrollBy(0, 600));
    await page.waitForTimeout(200);
    // Check if envies-du-moment carousel has items
    const hasFeatured = await page.evaluate(() => {
      const featured = window.LC.menu.items.filter(i => i.is_featured);
      return { count: featured.length, slugs: featured.map(i => i.slug) };
    });
    await recorder.snap('05-home-envies-du-moment');
    // S-005: is_featured never set → expect 0 items in seed; record as evidence
    fs.writeFileSync(path.join(OUT, '05-home-envies-du-moment.evidence.json'), JSON.stringify({ hasFeatured }, null, 2));
  });

  test('S06 — Menu 13 cats + filter chips', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Menu/);
    await page.waitForSelector('[data-screen-label*="Menu"]');
    await recorder.snap('06-menu-13-cats');
  });

  test('S07 — Cart 1-line composition display', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => {
      const tacos = window.LC.menu.findItem('tacos-xxl');
      window.LC.storage.setCart([{ ...tacos, qty: 1, unitPrice: 18, lineTotal: 18, sups: ['Œuf'], composition_summary: 'Bœuf · Sauce Ketchup · 🍽 Menu · 🥤 Coca' }]);
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoTab(page, /Menu/);
    const cartBtn = page.locator('button:has-text("Voir le panier"), button:has-text("VOIR LE PANIER")').first();
    if (await cartBtn.count() > 0) await cartBtn.click();
    else await page.evaluate(() => { /* fallback nav */ });
    await page.waitForTimeout(400);
    await recorder.snap('07-cart-1-line');
  });

  test('S08 — Cart empty state', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => window.LC.storage.clearCart());
    await page.reload({ waitUntil: 'domcontentloaded' });
    // Navigate to cart screen — go via Menu tab then if no cart shortcut visible the empty state is on home
    await gotoTab(page, /Menu/);
    await recorder.snap('08-cart-empty');
  });

  test('S09 — Confirm screen (S-006 ETA hardcoded hunt)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => {
      const tacos = window.LC.menu.findItem('tacos-xxl');
      const cart = [{ ...tacos, qty: 1, unitPrice: 12.5, lineTotal: 12.5, sups: [], composition_summary: '' }];
      window.LC.storage.setCart(cart);
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    // Walk through cart → pay → counter → confirm if possible
    await gotoTab(page, /Menu/);
    await page.locator('button:has-text("Voir le panier"), button:has-text("VOIR LE PANIER")').first().click().catch(() => {});
    await page.waitForTimeout(400);
    const payBtn = page.locator('button:has-text("Payer"), button:has-text("Valider")').first();
    if (await payBtn.count() > 0) {
      await payBtn.click();
      await page.waitForTimeout(400);
      const counterBtn = page.locator('button:has-text("caisse"), button:has-text("Payer à la caisse")').first();
      if (await counterBtn.count() > 0) {
        await counterBtn.click();
        await page.waitForTimeout(800);
      }
    }
    // Check S-006: ETA "~12 MIN" hardcoded
    const eta12 = await page.locator('text=/~12\\s*MIN/').count();
    fs.writeFileSync(path.join(OUT, '09-confirm.evidence.json'), JSON.stringify({ eta_12_min_present: eta12 > 0 }, null, 2));
    await recorder.snap('09-confirm');
  });

  test('S10 — Orders active tab', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Commande/);
    await page.waitForTimeout(300);
    await recorder.snap('10-orders-active');
  });

  test('S11 — Orders historique tab', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Commande/);
    await page.waitForTimeout(200);
    const histTab = page.locator('button:has-text("Historique"), button:has-text("Passées")').first();
    if (await histTab.count() > 0) await histTab.click();
    await page.waitForTimeout(200);
    await recorder.snap('11-orders-history');
  });

  test('S12 — Profile screen', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Profil/);
    await page.waitForTimeout(300);
    await recorder.snap('12-profile');
  });

  test('S13 — Loyalty hero + QR + balance card', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => {
      window.LC.dev.seedAccount({ balance: 347, lifetime_earned: 1247 });
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoLoyalty(page);
    await recorder.snap('13-loyalty-hero');
  });

  test('S14 — Loyalty opt-out modal opens', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => window.LC.dev.seedAccount({ balance: 147, lifetime_earned: 200 }));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoLoyalty(page);
    const optOutBtn = page.locator('[data-testid="loyalty-opt-out-btn"]');
    await optOutBtn.scrollIntoViewIfNeeded();
    await optOutBtn.click();
    await page.waitForSelector('[data-modal-kind="opt-out-confirm"]', { timeout: 3_000 });
    await recorder.snap('14-loyalty-optout-modal');
  });

  test('S15 — Loyalty opt-out APPLIED — POINTS card gating check (S-001 critical)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => window.LC.dev.seedAccount({ balance: 147, lifetime_earned: 200 }));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoLoyalty(page);
    await page.locator('[data-testid="loyalty-opt-out-btn"]').scrollIntoViewIfNeeded();
    await page.locator('[data-testid="loyalty-opt-out-btn"]').click();
    await page.click('[data-testid="opt-out-confirm-btn"]');
    await page.waitForTimeout(500);
    // S-001 evidence: balance card visible AFTER opt-out?
    const balanceVisible = await page.locator('[data-testid="loyalty-balance"]').isVisible().catch(() => false);
    const balanceText = balanceVisible ? await page.locator('[data-testid="loyalty-balance"]').textContent() : null;
    const consent = await page.evaluate(() => window.LC.storage.getConsent());
    fs.writeFileSync(path.join(OUT, '15-loyalty-optout-applied.evidence.json'), JSON.stringify({
      consent_after_optout: consent,
      balance_card_visible: balanceVisible,
      balance_text: balanceText,
      verdict: balanceVisible ? 'S-001 CONFIRMED — POINTS card not gated' : 'S-001 fixed',
    }, null, 2));
    await recorder.snap('15-loyalty-optout-applied');
  });

  test('S16 — WizardRedeem 3-step flow accessible', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => window.LC.dev.seedAccount({ balance: 347 }));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoLoyalty(page);
    await page.click('[data-testid="loyalty-tab-rewards"]');
    await page.waitForTimeout(200);
    const unlocked = page.locator('[data-testid^="reward-redeem-btn-"]:not([disabled])').first();
    if (await unlocked.count() > 0) {
      await unlocked.click();
      await page.waitForSelector('[data-testid="modal"][data-modal-kind="redeem-wizard"], [data-modal-kind*="redeem"]', { timeout: 3_000 }).catch(() => {});
    }
    await recorder.snap('16-wizard-redeem-step1');
  });
});
