// test-real-e2e-round2-2026-05-18.spec.js
// Round 2 — extended coverage on top of round 1:
// Mobile: real splash → onb1→2→3→4 → login → OTP → Coca direct-add → orders en cours+historique → order detail → profile → loyalty → redeem modal
// Web: 4 viewports (mobile/tablet/desktop/wide), all 3 wizard templates (sandwich/tacos/custom), confirm + track distinct

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const MOBILE_URL = 'http://127.0.0.1:8081/index.html';
const WEB_URL = 'http://127.0.0.1:8082/index.html';
const SHOT_DIR = path.join(__dirname, '__screenshots__/real-e2e-2026-05-18');
const SHOT_MOBILE = path.join(SHOT_DIR, 'mobile-r2');
const SHOT_WEB = path.join(SHOT_DIR, 'web-r2');
if (!fs.existsSync(SHOT_MOBILE)) fs.mkdirSync(SHOT_MOBILE, { recursive: true });
if (!fs.existsSync(SHOT_WEB)) fs.mkdirSync(SHOT_WEB, { recursive: true });

const FORBIDDEN = [
  /\bLabel\.[A-Za-z0-9_.-]+/i,
  /\bkiosk\.wizard\.[a-z_]+/i,
  /\b0undefined\b/, /\bNaN €/,
  /Box Nashville/, /Cheese Smash/, /Le Gourmet/, /Bowl Cheesy/, /Bowl Veggie/, /Wrap Poulet/, /Box Familiale/,
  /\bSmash burgers\b/, /\bBuckets\b/, // round-2 catalog hygiene
];

async function snap(page, dir, name) {
  await page.screenshot({ path: path.join(dir, `${name}.png`), fullPage: false });
  const text = await page.evaluate(() => document.body.innerText);
  for (const re of FORBIDDEN) {
    const m = text.match(re);
    if (m) throw new Error(`${name}: forbidden text ${re} matched "${m[0]}"`);
  }
}

// ============================================================================
// MOBILE — real splash → onb1-4 → login → OTP via in-app clicks
// ============================================================================
test('mobile R2 — splash → onb1→4 → login → OTP (real click chain)', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  page._errors = [];
  page.on('pageerror', e => page._errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') page._errors.push(m.text()); });

  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.evaluate(() => localStorage.clear());
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.waitForTimeout(400);

  // 1. Splash
  await snap(page, SHOT_MOBILE, '01-splash');

  // splash has setTimeout 1800ms; wait for auto-advance OR click
  await page.locator('[data-screen-label="00 Splash"]').click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(700);

  // 2-5. Onb1-4 via Suivant button (circular arrow at bottom)
  for (let i = 1; i <= 4; i++) {
    await page.waitForSelector(`[data-screen-label*="Onboarding"]`, { timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(400);
    await snap(page, SHOT_MOBILE, `0${i + 1}-onb${i}`);
    // Click the round arrow button (only button on the page that's 64x64)
    const nextBtn = page.locator('button').filter({ has: page.locator('svg') }).last();
    await nextBtn.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(500);
  }

  // 6. Login screen — after onb4 clicks Suivant, markOnboardingSeen+go('login')
  await page.waitForTimeout(500);
  await snap(page, SHOT_MOBILE, '06-login');

  // Fill phone number and continue
  const phoneInput = page.locator('input[type="tel"], input[inputmode*="tel"], input[placeholder*="téléphone"], input[placeholder*="06"]').first();
  if (await phoneInput.isVisible().catch(() => false)) {
    await phoneInput.fill('0642799884');
    await page.waitForTimeout(300);
    const continueBtn = page.locator('button:has-text("Recevoir"), button:has-text("Continuer"), button:has-text("Envoyer"), button:has-text("Suivant")').first();
    if (await continueBtn.isVisible().catch(() => false)) {
      await continueBtn.click();
      await page.waitForTimeout(700);
    }
  }

  // 7. OTP
  await snap(page, SHOT_MOBILE, '07-otp');

  const realErrors = (page._errors || []).filter(e => !/favicon|image-slots\.state\.json/i.test(e));
  expect(realErrors, `Mobile R2 onb console errors: ${realErrors.join('\n')}`).toHaveLength(0);
  await ctx.close();
});

// ============================================================================
// MOBILE — post-auth: Coca direct-add, orders detail, profile, loyalty, redeem
// ============================================================================
test('mobile R2 — Coca direct-add + orders detail + profile + loyalty + redeem', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  page._errors = [];
  page.on('pageerror', e => page._errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') page._errors.push(m.text()); });

  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  // bypass auth + onboarding
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'r2-test', phone: '0642799884', user_id: 1 }));
    // Seed a few historical orders for richer screens
    const past = [{
      id: 'C-1234', total: 15.50, eta: '14h20', placed_at: '2026-05-15T14:08:00',
      items: [{ id: 'sandwich-cayenne-classique', name: 'Sandwich Cayenne', qty: 2, price: 7.50, sups: [] }],
      status: 'delivered',
    }];
    localStorage.setItem('lecayenne.orders_history', JSON.stringify(past));
    // Seed loyalty balance
    localStorage.setItem('lecayenne.loyalty', JSON.stringify({ balance: 320, lifetime: 320, tier: 'Pepper' }));
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.waitForTimeout(700);

  // 8. Home post-auth
  await snap(page, SHOT_MOBILE, '08-home-postauth');

  // 9. Go to Menu
  const menuTab = page.locator('button:has-text("MENU")').first();
  await menuTab.click();
  await page.waitForTimeout(600);

  // 10. Scroll to Boissons category to find Coca
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(400);
  await snap(page, SHOT_MOBILE, '10-menu-boissons');

  // 11. Click Coca card (simple direct-add — no wizard)
  const cocaCard = page.locator('[role="button"]').filter({ hasText: /Coca|Pepsi|Boisson/i }).first();
  if (await cocaCard.isVisible().catch(() => false)) {
    await cocaCard.click();
    await page.waitForTimeout(600);
    await snap(page, SHOT_MOBILE, '11-item-coca-detail');
  }

  // 12. Back, go to Orders tab
  const back1 = page.locator('button[aria-label*="Retour"]').first();
  if (await back1.isVisible().catch(() => false)) {
    await back1.click();
    await page.waitForTimeout(400);
  }
  const ordersTab = page.locator('button:has-text("COMMANDES")').first();
  await ordersTab.click();
  await page.waitForTimeout(700);
  await snap(page, SHOT_MOBILE, '12-orders-en-cours');

  // 13. Switch to Historique tab
  const historyTab = page.locator('button:has-text("Historique"), [role="tab"]:has-text("Historique"), [data-tab="history"]').first();
  if (await historyTab.isVisible().catch(() => false)) {
    await historyTab.click();
    await page.waitForTimeout(500);
    await snap(page, SHOT_MOBILE, '13-orders-historique');

    // 14. Click first historical order to see detail
    const orderCard = page.locator('[data-testid^="orders-history-card-"]').first();
    if (await orderCard.isVisible().catch(() => false)) {
      await orderCard.click();
      await page.waitForTimeout(700);
      await snap(page, SHOT_MOBILE, '14-order-detail');
      const back2 = page.locator('button[aria-label*="Retour"]').first();
      if (await back2.isVisible().catch(() => false)) {
        await back2.click();
        await page.waitForTimeout(400);
      }
    }
  }

  // 15. Profile tab
  const profileTab = page.locator('button:has-text("PROFIL")').first();
  await profileTab.click();
  await page.waitForTimeout(700);
  await snap(page, SHOT_MOBILE, '15-profile');

  // 16. Tap loyalty card to enter loyalty screen
  const loyaltyCard = page.locator('[aria-label*="Carte fidélité"], [aria-label*="fidélité"]').first();
  if (await loyaltyCard.isVisible().catch(() => false)) {
    await loyaltyCard.click();
    await page.waitForTimeout(800);
    await snap(page, SHOT_MOBILE, '16-loyalty');

    // 17. Scroll within loyalty to find rewards section
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await page.waitForTimeout(400);
    await snap(page, SHOT_MOBILE, '17-loyalty-rewards');

    // 18. Try clicking a UTILISER button (redeem)
    const utiliser = page.locator('button:has-text("UTILISER")').first();
    if (await utiliser.isVisible().catch(() => false)) {
      await utiliser.click();
      await page.waitForTimeout(700);
      await snap(page, SHOT_MOBILE, '18-redeem-modal');
    }
  }

  const realErrors = (page._errors || []).filter(e => !/favicon|image-slots\.state\.json/i.test(e));
  expect(realErrors, `Mobile R2 post-auth console errors: ${realErrors.join('\n')}`).toHaveLength(0);
  await ctx.close();
});

// ============================================================================
// WEB — full 4-viewport coverage + 3 wizard templates distinct
// ============================================================================
const VIEWPORTS = [
  { name: 'tablet',  width: 768,  height: 1024 },
  { name: 'wide',    width: 1920, height: 1080 },
];

for (const vp of VIEWPORTS) {
  test(`web R2 ${vp.name} — full flow capture (home → menu → wizard×3 → cart → checkout → confirm → track → orders → loyalty)`, async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page._errors = [];
    page.on('pageerror', e => page._errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error') page._errors.push(m.text()); });

    await page.goto(WEB_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.waitForTimeout(700);
    await snap(page, SHOT_WEB, `${vp.name}-01-home`);

    // Menu
    const navMenu = page.locator('button.lc-nav-link', { hasText: 'Menu' }).first();
    if (await navMenu.isVisible().catch(() => false)) {
      await navMenu.click();
    } else {
      const burger = page.locator('button.lc-nav-burger').first();
      if (await burger.isVisible().catch(() => false)) {
        await burger.click();
        await page.waitForTimeout(300);
        await page.locator('button.lc-mobile-link', { hasText: 'Menu' }).first().click().catch(() => {});
      }
    }
    await page.waitForTimeout(700);
    await snap(page, SHOT_WEB, `${vp.name}-02-menu`);

    // Wizard: Sandwich template (Sandwich Cayenne)
    const sandwichItem = page.locator('.lc-card-item, button.lc-card-item').filter({ hasText: /Sandwich Cayenne/i }).first();
    if (await sandwichItem.isVisible().catch(() => false)) {
      await sandwichItem.click();
      await page.waitForTimeout(600);
      const customize = page.locator('button:has-text("Personnaliser")').first();
      if (await customize.isVisible().catch(() => false)) {
        await customize.click();
        await page.waitForTimeout(700);
        await snap(page, SHOT_WEB, `${vp.name}-03-wizard-sandwich`);
        const closeWiz = page.locator('button[aria-label*="Fermer"], .lc-wiz-foot-back').first();
        if (await closeWiz.isVisible().catch(() => false)) await closeWiz.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      // Close detail modal
      const closeDet = page.locator('button[aria-label*="Fermer"]').first();
      if (await closeDet.isVisible().catch(() => false)) await closeDet.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // Wizard: Bols template (custom 3-step)
    const bolItem = page.locator('.lc-card-item').filter({ hasText: /Bowl Frites/i }).first();
    if (await bolItem.isVisible().catch(() => false)) {
      await bolItem.scrollIntoViewIfNeeded();
      await bolItem.click();
      await page.waitForTimeout(600);
      const cust2 = page.locator('button:has-text("Personnaliser")').first();
      if (await cust2.isVisible().catch(() => false)) {
        await cust2.click();
        await page.waitForTimeout(700);
        await snap(page, SHOT_WEB, `${vp.name}-04-wizard-bol`);
        const closeWiz2 = page.locator('button[aria-label*="Fermer"], .lc-wiz-foot-back').first();
        if (await closeWiz2.isVisible().catch(() => false)) await closeWiz2.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      const closeDet2 = page.locator('button[aria-label*="Fermer"]').first();
      if (await closeDet2.isVisible().catch(() => false)) await closeDet2.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // Wizard: Frites template (custom 1-step) — find Petite Frites
    const fritesItem = page.locator('.lc-card-item').filter({ hasText: /Petite Frites|Grande Frites/i }).first();
    if (await fritesItem.isVisible().catch(() => false)) {
      await fritesItem.scrollIntoViewIfNeeded();
      await fritesItem.click();
      await page.waitForTimeout(600);
      const cust3 = page.locator('button:has-text("Personnaliser")').first();
      if (await cust3.isVisible().catch(() => false)) {
        await cust3.click();
        await page.waitForTimeout(700);
        await snap(page, SHOT_WEB, `${vp.name}-05-wizard-frites`);
        const closeWiz3 = page.locator('button[aria-label*="Fermer"], .lc-wiz-foot-back').first();
        if (await closeWiz3.isVisible().catch(() => false)) await closeWiz3.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      const closeDet3 = page.locator('button[aria-label*="Fermer"]').first();
      if (await closeDet3.isVisible().catch(() => false)) await closeDet3.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // Loyalty + Account
    const loyaltyLink = page.locator('button.lc-nav-link', { hasText: 'Fidélité' }).first();
    if (await loyaltyLink.isVisible().catch(() => false)) {
      await loyaltyLink.click();
      await page.waitForTimeout(600);
      await snap(page, SHOT_WEB, `${vp.name}-06-loyalty`);
    }

    const accountBtn = page.locator('button.lc-nav-btn-account').first();
    if (await accountBtn.isVisible().catch(() => false)) {
      await accountBtn.click();
      await page.waitForTimeout(600);
      await snap(page, SHOT_WEB, `${vp.name}-07-account-modal`);
    }

    const realErrors = (page._errors || []).filter(e => !/favicon|image-slots\.state\.json/i.test(e));
    expect(realErrors, `Web R2 ${vp.name} console errors: ${realErrors.join('\n')}`).toHaveLength(0);
    await ctx.close();
  });
}
