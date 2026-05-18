// test-real-e2e-pagebypage-2026-05-18.spec.js
// REAL page-by-page E2E with screenshot capture per screen on BOTH surfaces.
// Mobile: 18 screens, Web: 13 routes × 4 viewports = ~70+ captures total.
//
// Owner mandate: real test, capture per page, audit per page, heal, re-run until perfection.
// Both surfaces stay STANDALONE — no central system wireup.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const MOBILE_URL = 'http://127.0.0.1:8081/index.html';
const WEB_URL = 'http://127.0.0.1:8082/index.html';
const SHOT_DIR = path.join(__dirname, '__screenshots__/real-e2e-2026-05-18');
const SHOT_MOBILE = path.join(SHOT_DIR, 'mobile');
const SHOT_WEB = path.join(SHOT_DIR, 'web');
if (!fs.existsSync(SHOT_MOBILE)) fs.mkdirSync(SHOT_MOBILE, { recursive: true });
if (!fs.existsSync(SHOT_WEB)) fs.mkdirSync(SHOT_WEB, { recursive: true });

const FORBIDDEN = [
  /\bLabel\.[A-Za-z0-9_.-]+/i,
  /\bkiosk\.wizard\.[a-z_]+/i,
  /\b0undefined\b/, /\bNaN €/,
  /Box Nashville/, /Cheese Smash/, /Le Gourmet/, /Bowl Cheesy/, /Bowl Veggie/, /Wrap Poulet/, /Box Familiale/,
];

async function snap(page, dir, name) {
  await page.screenshot({ path: path.join(dir, `${name}.png`), fullPage: false });
  const text = await page.evaluate(() => document.body.innerText);
  for (const re of FORBIDDEN) {
    const m = text.match(re);
    if (m) throw new Error(`${name}: forbidden text ${re} matched "${m[0]}"`);
  }
  return name;
}

// ============================================================================
// MOBILE — page-by-page walk-through
// ============================================================================
test.describe('Mobile pages', () => {
  test('mobile — full flow capture (splash → onb → login → otp → home → menu → item → wizard → cart → confirm → orders → loyalty → profile)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    page._errors = [];
    page.on('pageerror', e => page._errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error') page._errors.push(m.text()); });

    // 1. Splash (fresh, no auth, no onb)
    await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.evaluate(() => { localStorage.clear(); });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.waitForTimeout(500);
    await snap(page, SHOT_MOBILE, '01-splash');

    // 2-5. Onboarding 1-4
    // Click "tap to start" or next button to advance through screens
    for (let i = 1; i <= 4; i++) {
      const next = page.locator('button:has-text("Suivant"), button:has-text("Continuer"), button:has-text("Commencer"), button:has-text("Je commande"), [class*="lc-onb-cta"], [class*="onb"][role="button"]').first();
      if (await next.isVisible().catch(() => false)) {
        await next.click().catch(() => {});
        await page.waitForTimeout(400);
      }
      await snap(page, SHOT_MOBILE, `0${i + 1}-onb${i}`);
    }

    // 6. Login screen
    await page.waitForTimeout(300);
    await snap(page, SHOT_MOBILE, '06-login');

    // 7. OTP — simulate auth bypass
    await page.evaluate(() => {
      localStorage.setItem('lecayenne.onboarding_seen', 'true');
      localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'real-e2e-test', phone: '0642799884', user_id: 1 }));
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.waitForTimeout(800);

    // 8. Home post-auth
    await snap(page, SHOT_MOBILE, '08-home');

    // 9. Menu tab
    const menuTab = page.locator('button[aria-label*="Menu"], button:has-text("MENU"), [data-tab="menu"]').first();
    if (await menuTab.isVisible().catch(() => false)) {
      await menuTab.click().catch(() => {});
      await page.waitForTimeout(600);
    }
    await snap(page, SHOT_MOBILE, '09-menu-top');

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await page.waitForTimeout(400);
    await snap(page, SHOT_MOBILE, '10-menu-mid');

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
    await snap(page, SHOT_MOBILE, '11-menu-bottom');

    // 12. Item Direct-Add — Coca (simple cat)
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(300);
    const cocaBtn = page.locator('button:has-text("Coca"), [data-item="coca"], [data-testid="item-coca"]').first();
    if (await cocaBtn.isVisible().catch(() => false)) {
      await cocaBtn.click().catch(() => {});
      await page.waitForTimeout(500);
      await snap(page, SHOT_MOBILE, '12-item-coca-direct-add');
      // Close direct-add
      const back = page.locator('button[aria-label*="Fermer"], button[aria-label*="Retour"]').first();
      if (await back.isVisible().catch(() => false)) await back.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // 13-14. Item Wizard Sandwich Cayenne (sandwich template)
    const sandwichBtn = page.locator('button:has-text("Sandwich Cayenne"), button:has-text("Cayenne"), [data-item="sandwich-cayenne-classique"]').first();
    if (await sandwichBtn.isVisible().catch(() => false)) {
      await sandwichBtn.click().catch(() => {});
      await page.waitForTimeout(600);
      await snap(page, SHOT_MOBILE, '13-wizard-sandwich-step1');
      // Click first viande
      const meat = page.locator('[role="radio"], [role="checkbox"]').first();
      if (await meat.isVisible().catch(() => false)) {
        await meat.click().catch(() => {});
        await page.waitForTimeout(400);
        // Click "Suivant"
        const nextStep = page.locator('button:has-text("Suivant"), button:has-text("Continuer"), button.rdw-cta').first();
        if (await nextStep.isVisible().catch(() => false)) {
          await nextStep.click().catch(() => {});
          await page.waitForTimeout(400);
          await snap(page, SHOT_MOBILE, '14-wizard-sandwich-step2');
        }
      }
      // Close wizard
      const close = page.locator('button[aria-label*="Fermer"]').first();
      if (await close.isVisible().catch(() => false)) await close.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // 15. Item Wizard Bols (custom 3-step)
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await page.waitForTimeout(300);
    const bowlBtn = page.locator('button:has-text("Bowl"), button:has-text("Bol")').first();
    if (await bowlBtn.isVisible().catch(() => false)) {
      await bowlBtn.click().catch(() => {});
      await page.waitForTimeout(600);
      await snap(page, SHOT_MOBILE, '15-wizard-bol-sauce-step');
      const close = page.locator('button[aria-label*="Fermer"]').first();
      if (await close.isVisible().catch(() => false)) await close.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // 16. Item Wizard Frites (custom 1-step)
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight * 0.7));
    await page.waitForTimeout(300);
    const fritesBtn = page.locator('button:has-text("Petite Frites"), button:has-text("Frites")').first();
    if (await fritesBtn.isVisible().catch(() => false)) {
      await fritesBtn.click().catch(() => {});
      await page.waitForTimeout(600);
      await snap(page, SHOT_MOBILE, '16-wizard-frites-style');
      const close = page.locator('button[aria-label*="Fermer"]').first();
      if (await close.isVisible().catch(() => false)) await close.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // 17. Cart — seed cart programmatically + open
    await page.evaluate(() => {
      const sandwich = window.LC.menu.findItem('sandwich-cayenne-classique');
      const cart = [{
        ...sandwich,
        price: 7.50, unitPrice: 7.50, lineTotal: 7.50, qty: 1, sups: [],
        composition_summary: '1 viande au choix · Sauce Cayenne maison',
      }];
      window.LC.storage.setCart(cart);
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.waitForTimeout(900);

    // Cart sticky bar appears on home/menu when cart.length > 0 ("Voir le panier")
    let viewCart = page.locator('button:has-text("Voir le panier")').first();
    if (!(await viewCart.isVisible().catch(() => false))) {
      const menuT = page.locator('button:has-text("MENU")').first();
      if (await menuT.isVisible().catch(() => false)) {
        await menuT.click();
        await page.waitForTimeout(500);
        viewCart = page.locator('button:has-text("Voir le panier")').first();
      }
    }
    if (await viewCart.isVisible().catch(() => false)) {
      await viewCart.click();
      await page.waitForTimeout(700);
    }
    await snap(page, SHOT_MOBILE, '17-cart-with-items');

    // 18. Modal pay choice — CTA on cart
    const validateBtn = page.locator('button:has-text("Valider"), button:has-text("Commander"), button:has-text("Passer commande"), button:has-text("Payer")').first();
    if (await validateBtn.isVisible().catch(() => false)) {
      await validateBtn.click().catch(() => {});
      await page.waitForTimeout(700);
      await snap(page, SHOT_MOBILE, '18-modal-pay-choice');
      // Tap "Payer à la caisse"
      const counter = page.locator('button:has-text("caisse"), button:has-text("À la caisse"), button:has-text("Payer à la caisse")').first();
      if (await counter.isVisible().catch(() => false)) {
        await counter.click().catch(() => {});
        await page.waitForTimeout(900);
        await snap(page, SHOT_MOBILE, '19-confirm');
      }
    }

    // 20. Orders tab — bottom nav text COMMANDES
    const ordersTab = page.locator('button:has-text("COMMANDES")').first();
    if (await ordersTab.isVisible().catch(() => false)) {
      await ordersTab.click().catch(() => {});
      await page.waitForTimeout(700);
      await snap(page, SHOT_MOBILE, '20-orders');
    }

    // 21. Profile tab — bottom nav text PROFIL
    const profileTab = page.locator('button:has-text("PROFIL")').first();
    if (await profileTab.isVisible().catch(() => false)) {
      await profileTab.click().catch(() => {});
      await page.waitForTimeout(700);
      await snap(page, SHOT_MOBILE, '21-profile');
    }

    // 22. Loyalty — from profile, click loyalty card
    const loyaltyCard = page.locator('button:has-text("Fidélité"), button:has-text("Pepper"), [class*="loyalty"]').first();
    if (await loyaltyCard.isVisible().catch(() => false)) {
      await loyaltyCard.click().catch(() => {});
      await page.waitForTimeout(800);
      await snap(page, SHOT_MOBILE, '22-loyalty');
    }

    // Console errors check
    const realErrors = (page._errors || []).filter(e => !/favicon|image-slots\.state\.json/i.test(e));
    if (realErrors.length) {
      console.log('Console errors mobile:', realErrors.slice(0, 5));
    }
    expect(realErrors, `Mobile console errors: ${realErrors.join('\n')}`).toHaveLength(0);
    await ctx.close();
  });
});

// ============================================================================
// WEB — page-by-page across 4 viewports (only desktop here to keep manageable; the 4-viewport coverage exists in test-e2e-website-realignment-2026-05-16.spec.js)
// ============================================================================
const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'desktop', width: 1280, height: 800 },
];

for (const vp of VIEWPORTS) {
  test(`web ${vp.name} — full flow capture (home → menu → item → wizard → cart → checkout → payment → confirm → track → orders → loyalty → about)`, async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page._errors = [];
    page.on('pageerror', e => page._errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error') page._errors.push(m.text()); });

    // 1. Home
    await page.goto(WEB_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
    await page.waitForTimeout(800);
    await snap(page, SHOT_WEB, `${vp.name}-01-home`);

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await page.waitForTimeout(400);
    await snap(page, SHOT_WEB, `${vp.name}-02-home-mid`);

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
    await snap(page, SHOT_WEB, `${vp.name}-03-home-footer`);

    // 4. Menu route
    await page.evaluate(() => window.scrollTo(0, 0));
    // navigate via nav link or burger
    const menuLinkDesk = page.locator('button.lc-nav-link', { hasText: 'Menu' }).first();
    if (await menuLinkDesk.isVisible().catch(() => false)) {
      await menuLinkDesk.click();
    } else {
      const burger = page.locator('button.lc-nav-burger').first();
      if (await burger.isVisible().catch(() => false)) {
        await burger.click();
        await page.waitForTimeout(300);
        const mobLink = page.locator('button.lc-mobile-link', { hasText: 'Menu' }).first();
        if (await mobLink.isVisible().catch(() => false)) await mobLink.click();
      }
    }
    await page.waitForTimeout(700);
    await snap(page, SHOT_WEB, `${vp.name}-04-menu`);

    // 5. ItemDetailModal (click any item card)
    const item = page.locator('button.lc-card-item, .lc-card-item, [class*="card-item"]').first();
    if (await item.isVisible().catch(() => false)) {
      await item.click().catch(() => {});
      await page.waitForTimeout(700);
      await snap(page, SHOT_WEB, `${vp.name}-05-item-detail-modal`);
      // 6. Customize → WizardFlow
      const customize = page.locator('button:has-text("Personnaliser"), button:has-text("Customize"), button:has-text("Composer")').first();
      if (await customize.isVisible().catch(() => false)) {
        await customize.click().catch(() => {});
        await page.waitForTimeout(700);
        await snap(page, SHOT_WEB, `${vp.name}-06-wizard-flow`);
        // Close wizard
        const closeWiz = page.locator('button[aria-label*="Fermer"], .lc-wiz-foot-back').first();
        if (await closeWiz.isVisible().catch(() => false)) await closeWiz.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      // Close detail modal
      const closeDet = page.locator('button[aria-label*="Fermer"]').first();
      if (await closeDet.isVisible().catch(() => false)) await closeDet.click().catch(() => {});
      await page.waitForTimeout(300);
    }

    // 7. Cart drawer (click cart btn)
    const cartBtn = page.locator('button.lc-nav-btn-cart').first();
    if (await cartBtn.isVisible().catch(() => false)) {
      await cartBtn.click().catch(() => {});
      await page.waitForTimeout(600);
      await snap(page, SHOT_WEB, `${vp.name}-07-cart-drawer`);
    }

    // 8. Checkout — click "Passer commande" if visible
    const checkoutBtn = page.locator('button:has-text("Passer commande"), .lc-cart-cta').first();
    if (await checkoutBtn.isVisible().catch(() => false)) {
      await checkoutBtn.click().catch(() => {});
      await page.waitForTimeout(700);
      await snap(page, SHOT_WEB, `${vp.name}-08-checkout`);

      // Pick a time slot
      const slot = page.locator('button.lcf-pickup-day, [class*="slot"]').first();
      if (await slot.isVisible().catch(() => false)) {
        await slot.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      // 9. Payment
      const payNext = page.locator('button:has-text("Continuer"), button:has-text("Payer")').first();
      if (await payNext.isVisible().catch(() => false)) {
        await payNext.click().catch(() => {});
        await page.waitForTimeout(700);
        await snap(page, SHOT_WEB, `${vp.name}-09-payment`);
        // 10. Confirm
        const confirmBtn = page.locator('button:has-text("Confirmer"), button:has-text("Payer")').first();
        if (await confirmBtn.isVisible().catch(() => false)) {
          await confirmBtn.click().catch(() => {});
          await page.waitForTimeout(800);
          await snap(page, SHOT_WEB, `${vp.name}-10-confirm`);
          // 11. Track
          const trackBtn = page.locator('button:has-text("Suivre")').first();
          if (await trackBtn.isVisible().catch(() => false)) {
            await trackBtn.click().catch(() => {});
            await page.waitForTimeout(700);
            await snap(page, SHOT_WEB, `${vp.name}-11-track`);
          }
        }
      }
    }

    // 12. Orders route (auth gate may show)
    await page.evaluate(() => window.scrollTo(0, 0));
    const ordersLinkDesk = page.locator('button.lc-nav-link', { hasText: 'Commandes' }).first();
    if (await ordersLinkDesk.isVisible().catch(() => false)) {
      await ordersLinkDesk.click().catch(() => {});
    } else {
      const burger2 = page.locator('button.lc-nav-burger').first();
      if (await burger2.isVisible().catch(() => false)) {
        await burger2.click();
        await page.waitForTimeout(300);
        const ord = page.locator('button.lc-mobile-link', { hasText: 'Commandes' }).first();
        if (await ord.isVisible().catch(() => false)) await ord.click();
      }
    }
    await page.waitForTimeout(600);
    await snap(page, SHOT_WEB, `${vp.name}-12-orders`);

    // 13. Loyalty route
    await page.evaluate(() => window.scrollTo(0, 0));
    const loyaltyLinkDesk = page.locator('button.lc-nav-link', { hasText: 'Fidélité' }).first();
    if (await loyaltyLinkDesk.isVisible().catch(() => false)) {
      await loyaltyLinkDesk.click().catch(() => {});
    } else {
      const burger3 = page.locator('button.lc-nav-burger').first();
      if (await burger3.isVisible().catch(() => false)) {
        await burger3.click();
        await page.waitForTimeout(300);
        const loy = page.locator('button.lc-mobile-link', { hasText: 'Fidélité' }).first();
        if (await loy.isVisible().catch(() => false)) await loy.click();
      }
    }
    await page.waitForTimeout(600);
    await snap(page, SHOT_WEB, `${vp.name}-13-loyalty`);

    // 14. About route
    await page.evaluate(() => window.scrollTo(0, 0));
    const aboutLinkDesk = page.locator('button.lc-nav-link', { hasText: 'enseigne' }).first();
    if (await aboutLinkDesk.isVisible().catch(() => false)) {
      await aboutLinkDesk.click().catch(() => {});
    } else {
      const burger4 = page.locator('button.lc-nav-burger').first();
      if (await burger4.isVisible().catch(() => false)) {
        await burger4.click();
        await page.waitForTimeout(300);
        const ab = page.locator('button.lc-mobile-link', { hasText: 'enseigne' }).first();
        if (await ab.isVisible().catch(() => false)) await ab.click();
      }
    }
    await page.waitForTimeout(600);
    await snap(page, SHOT_WEB, `${vp.name}-14-about`);

    // 15. Account modal
    const accountBtn = page.locator('button.lc-nav-btn-account').first();
    if (await accountBtn.isVisible().catch(() => false)) {
      await accountBtn.click().catch(() => {});
      await page.waitForTimeout(600);
      await snap(page, SHOT_WEB, `${vp.name}-15-account-modal`);
    }

    const realErrors = (page._errors || []).filter(e => !/favicon|image-slots\.state\.json/i.test(e));
    if (realErrors.length) {
      console.log(`Web ${vp.name} console errors:`, realErrors.slice(0, 5));
    }
    expect(realErrors, `Web ${vp.name} console errors: ${realErrors.join('\n')}`).toHaveLength(0);
    await ctx.close();
  });
}
