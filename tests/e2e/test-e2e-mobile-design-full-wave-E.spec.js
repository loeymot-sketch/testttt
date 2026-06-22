// =============================================================================
// Wave E — Kiosk parity-reference capture (mobile-design audit 2026-05-11)
//
// READ-ONLY parity-reference. KioskAppComponent / KioskWizardComponent /
// KioskUpsellComponent / KioskLoyaltyComponent are FROZEN per CLAUDE.md §7.
// This spec ONLY captures the kiosk visual states so the adversarial Wave F
// reviewer can diff the mobile app against the kiosk reference. Any finding
// about the kiosk surface itself is P3 (cosmetic, parity-reference only).
// Findings about MOBILE drift vs kiosk are P1.
//
// Forked from:
//   - tests/e2e/audit-kiosk-cycle5-2026-05-07.spec.js (kiosk auth + lockdown)
//   - tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js (wizard walker)
//   - tests/e2e/test-e2e-borne-2026-05-10-wave-E.spec.js (cart + payment journey)
//
// Captures 10 states, each emitting the mega-audit quartet:
//   <state>.png + <state>.dom.html + <state>.console.json + <state>.network.json
//
// 10 states:
//   01-kiosk-idle-screen           (KioskAppComponent landing, pre-touch)
//   02-kiosk-categories-welcome    (post-start, /kiosk/categories root)
//   03-kiosk-cat-309-assiettes     (category 309 product grid)
//   04-kiosk-wizard-product-step-sauce  (wizard step containing sauce options)
//   05-kiosk-wizard-product-step-supplements (wizard step for supplement extras)
//   06-kiosk-wizard-recap          (final recap step with add-to-cart CTA)
//   07-kiosk-loyalty-input         (KioskLoyalty step='input' — code/phone)
//   08-kiosk-loyalty-register      (registration form, step='register')
//   09-kiosk-loyalty-balance       (balance display — best-effort; may fallback)
//   10-kiosk-confirmation-screen   (order success after CB happy path)
//
// Acceptance: spec MUST pass. Captures are observation-only. Findings are
// logged as sidecar JSON for adversarial review.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const { loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/test-e2e-mobile-design-full-wave-E',
);
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// -----------------------------------------------------------------------------
// Tunables
// -----------------------------------------------------------------------------
// Kiosk hardware is a 32" landscape touchscreen; physical canvas is 1080x1920
// (portrait kiosk pillar) or 1920x1080 (landscape). The production audit uses
// 1080x1920 (portrait) — kept consistent with audit-kiosk-cycle5 + borne-E.
const KIOSK_VIEWPORT = { width: 1080, height: 1920 };

// Categories (LeCayenne fixture). Verified via php artisan tinker 2026-05-11:
//   306=Tacos, 307=Sandwichs, 308=Burgers, 309=Assiettes, 310=Ojja, 311=Omelettes,
//   312=Salades, 313=Poulet, 314=Menus Enfants, 315=Frites & Accomp.,
//   316=Desserts, 317=Boissons, 318=Suppléments.
const CAT_ASSIETTES = 309;

// -----------------------------------------------------------------------------
// utils
// -----------------------------------------------------------------------------

function parsePriceText(txt) {
  if (!txt) return null;
  const m = String(txt).replace(/\s/g, '').replace(',', '.').match(/(\d+\.\d+|\d+)/);
  return m ? Number(m[1]) : null;
}

/**
 * Snapshot CSS custom properties + Anton font on a page. Persists to a sidecar
 * JSON next to the capture for adversarial diff vs mobile.
 */
async function probePaletteAndFonts(page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    const cs = window.getComputedStyle(root);
    const tokenNames = [
      // Mobile reference tokens (per project_kiosk_design_refresh_2026-05-10):
      '--orange', '--yellow', '--ink', '--paper',
      '--red', '--black', '--white',
      // FoodKing kiosk-specific tokens (may or may not exist on kiosk):
      '--fk-kiosk-primary', '--fk-kiosk-accent', '--fk-kiosk-text',
      '--fk-kiosk-bg', '--fk-kiosk-bg-soft', '--fk-kiosk-border',
      // POS bleed (these DO exist globally because pos-v4.css ships on every page):
      '--fk-pos-primary', '--fk-pos-accent', '--fk-pos-text',
      '--fk-pos-bg', '--fk-pos-bg-soft', '--fk-pos-border',
    ];
    const tokens = {};
    for (const name of tokenNames) {
      const v = cs.getPropertyValue(name);
      tokens[name] = v ? v.trim() : null;
    }
    // Font probes — look for Anton (mobile hero) and other custom font-face's.
    const fonts = [];
    try {
      const set = document.fonts;
      if (set && set.values) {
        for (const f of set.values()) {
          fonts.push({
            family: f.family,
            style: f.style,
            weight: f.weight,
            status: f.status,
          });
        }
      }
    } catch (_e) { /* unsupported */ }
    const antonPresent = fonts.some((f) => /anton/i.test(f.family));
    const bodyFont = window.getComputedStyle(document.body).fontFamily;
    const titleFont = (() => {
      const h1 = document.querySelector('h1, h2');
      if (!h1) return null;
      return window.getComputedStyle(h1).fontFamily;
    })();
    return {
      tokens,
      anton_present: antonPresent,
      body_font: bodyFont,
      title_font: titleFont,
      total_fonts_loaded: fonts.length,
      sample_fonts: fonts.slice(0, 12),
    };
  });
}

/**
 * Dismiss the kiosk-inactivity overlay if it appears.
 */
async function dismissInactivityIfPresent(page) {
  const overlay = page.getByTestId('kiosk-inactivity-overlay');
  if (await overlay.isVisible({ timeout: 100 }).catch(() => false)) {
    const stay = page.getByTestId('kiosk-inactivity-stay');
    if (await stay.isVisible({ timeout: 200 }).catch(() => false)) {
      await stay.click({ timeout: 1500 }).catch(() => {});
      await page.waitForTimeout(150);
    }
  }
}

/**
 * Boot kiosk to the idle screen (KioskAppComponent landing). Used by state 01.
 * Does NOT advance past idle — the caller chooses when to tap the chooser.
 */
async function bootKioskToIdle(page, context) {
  clearFoodKingRateLimits();
  // Wipe cookies + storage to force a clean Sanctum kiosk:order token issue.
  // Without this, a stale auth from a previous run can return 401 on
  // /api/frontend/menu and leave the categories screen empty.
  if (context) {
    try { await context.clearCookies(); } catch (_e) { /* ignore */ }
  }
  await page.addInitScript(() => {
    try { window.localStorage.clear(); } catch (_) { /* noop */ }
    try { window.sessionStorage.clear(); } catch (_) { /* noop */ }
  });
  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  // Idle screen is animated; give the entrance transition a frame to settle.
  await page.waitForTimeout(1500);
  // Wait for the kiosk shell to mount — guards against the 401-on-/menu race
  // where the idle screen is visible but the categories prefetch fails silently.
  await page
    .waitForFunction(
      () =>
        document.querySelector('[data-testid="kiosk-idle-root"]') ||
        document.querySelector('.kiosk-idle') ||
        document.querySelector('.kiosk-order-type-chooser'),
      null,
      { timeout: 10000 },
    )
    .catch(() => {});
}

/**
 * Tap-through idle → /kiosk/categories. The idle screen exposes the order-type
 * chooser (dine-in / takeaway). V1 has dine-in DISABLED so we pick takeaway.
 */
async function advanceIdleToCategories(page) {
  const takeawayBtn = page.getByTestId('kiosk-order-type-takeaway');
  if (await takeawayBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
    await takeawayBtn.click();
  } else {
    const dineInBtn = page.getByTestId('kiosk-order-type-dine-in');
    if (await dineInBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await dineInBtn.click();
    } else {
      // Fallback: tap the decorative ring to surface the chooser.
      const touch = page.getByTestId('kiosk-idle-touch-btn');
      if (await touch.isVisible({ timeout: 2000 }).catch(() => false)) {
        await touch.click().catch(() => {});
        await page.waitForTimeout(400);
        if (await takeawayBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await takeawayBtn.click();
        }
      }
    }
  }
  await page.waitForURL((u) => /\/kiosk\/categories/.test(u.pathname), { timeout: 20000 }).catch(() => {});
  await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25000 });
}

async function openCategory(page, catId) {
  await dismissInactivityIfPresent(page);
  // Wait for sidebar items to render at all — guards against a 401 race on
  // /api/frontend/menu that leaves the sidebar empty. If no sidebar items
  // appear after 8s, force-reload the categories page once.
  const anySidebar = await page
    .locator('[data-testid^="kiosk-categories-sidebar-item-"]')
    .first()
    .isVisible({ timeout: 8000 })
    .catch(() => false);
  if (!anySidebar) {
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  const sidebarItem = page.getByTestId(`kiosk-categories-sidebar-item-${catId}`);
  // Soft-wait — if cat 309 specifically isn't present, fall back to clicking
  // ANY sidebar item so the test can continue (parity-reference, not assertion).
  const found = await sidebarItem.isVisible({ timeout: 8000 }).catch(() => false);
  if (found) {
    await sidebarItem.click();
  } else {
    // Capture the unexpected sidebar state for adversarial review then
    // click whatever's first to keep the journey moving.
    const fallback = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]').first();
    if (await fallback.isVisible({ timeout: 2000 }).catch(() => false)) {
      await fallback.click().catch(() => {});
    }
  }
  await page.waitForTimeout(500);
}

/**
 * Return the first visible product card's item_id in the current category zone.
 * Returns null (soft) if no product cards render — caller MUST handle that to
 * avoid hanging the test on transient menu-fetch 401.
 */
async function firstProductIdInCurrentCategory(page) {
  const card = page.locator('[data-testid^="kiosk-product-card-"]').first();
  const visible = await card.isVisible({ timeout: 10000 }).catch(() => false);
  if (!visible) return null;
  const tid = await card.getAttribute('data-testid').catch(() => null);
  if (!tid) return null;
  const m = tid.match(/kiosk-product-card-(\d+)/);
  return m ? m[1] : null;
}

/**
 * Snapshot the wizard step name from DOM markers. The kiosk wizard composes
 * each step under .kiosk-step-content > *; the child class hints at the step
 * type (e.g. .kiosk-step-sauce, .kiosk-step-supplement, .kiosk-step-menu, etc.).
 */
async function wizardStepClassname(page) {
  return page.evaluate(() => {
    const root = document.querySelector('.kiosk-wizard');
    if (!root) return { open: false };
    const stepRoot = root.querySelector('.kiosk-step-content > *');
    const nextBtn = root.querySelector('.kiosk-btn-next');
    const isAddMode = nextBtn && nextBtn.classList.contains('kiosk-btn-next--cart');
    return {
      open: true,
      stepClassname: stepRoot ? stepRoot.className : '',
      isAddMode,
      nextDisabled: !!(nextBtn && nextBtn.hasAttribute('disabled')),
    };
  });
}

/**
 * Click the wizard's first eligible option-card (if any) then click Next.
 * No-throw. Returns true if Next was clicked.
 */
async function wizardClickFirstOptionAndAdvance(page) {
  await dismissInactivityIfPresent(page);
  const optionCard = page
    .locator('.kiosk-step-content .kiosk-option-card:not(.is-out-of-stock):not(.kiosk-variation--disabled)')
    .first();
  if (await optionCard.isVisible({ timeout: 300 }).catch(() => false)) {
    await optionCard.click({ timeout: 1500, force: true }).catch(() => {});
    await page.waitForTimeout(150);
  }
  const nextBtn = page.locator('.kiosk-wizard .kiosk-btn-next').first();
  if (await nextBtn.isVisible({ timeout: 300 }).catch(() => false)) {
    const disabled = await nextBtn.getAttribute('disabled').catch(() => null);
    if (disabled === null || disabled === 'false') {
      await nextBtn.click({ timeout: 2500 }).catch(() => {});
      await page.waitForTimeout(250);
      return true;
    }
  }
  return false;
}

// =============================================================================
// TEST — capture the 10 kiosk parity-reference states
// =============================================================================

test.describe('Wave E — kiosk parity-reference capture (mobile-design audit 2026-05-11)', () => {
  test.setTimeout(360_000);

  test('capture 10 kiosk states for mobile-vs-kiosk parity diff', async ({ page, context }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Sidecar — running record of probes per state, persisted at the end.
    const sidecar = {
      meta: {
        spec: 'tests/e2e/test-e2e-mobile-design-full-wave-E.spec.js',
        run_at: new Date().toISOString(),
        viewport: KIOSK_VIEWPORT,
        cat_assiettes: CAT_ASSIETTES,
      },
      states: {},
      catalog_reachability: {
        tacos_xxl_reachable: null,
        tacos_xxl_item_id: 366, // verified via tinker
        fallback_used: null,
      },
      loyalty: {
        balance_step_reached: null,
        balance_value_observed: null,
        seed_user: null,
      },
    };

    function writeSidecar() {
      try {
        fs.writeFileSync(
          path.join(SCREENSHOT_DIR, '_sidecar.json'),
          JSON.stringify(sidecar, null, 2),
        );
      } catch (_e) { /* ignore */ }
    }

    try {
      // ============================================================
      // STATE 01 — kiosk idle screen (pre-touch)
      // ============================================================
      await bootKioskToIdle(page, context);
      const idleProbe = await probePaletteAndFonts(page);
      sidecar.states['01-kiosk-idle-screen'] = {
        url: page.url(),
        probe: idleProbe,
      };
      await snap('01-kiosk-idle-screen');
      writeSidecar();

      // ============================================================
      // STATE 02 — categories welcome (post-start)
      // ============================================================
      await advanceIdleToCategories(page);
      // The categories root may show a welcome banner at first paint before
      // sidebar fully renders — capture immediately to mirror that frame.
      await page.waitForTimeout(800);
      const catProbe = await probePaletteAndFonts(page);
      sidecar.states['02-kiosk-categories-welcome'] = {
        url: page.url(),
        probe: catProbe,
        sidebar_items_count: await page
          .locator('[data-testid^="kiosk-categories-sidebar-item-"]')
          .count(),
      };
      await snap('02-kiosk-categories-welcome');
      writeSidecar();

      // ============================================================
      // STATE 03 — cat 309 (Assiettes) product grid
      // ============================================================
      await openCategory(page, CAT_ASSIETTES);
      await page.waitForTimeout(600);
      const cat309Probe = await page.evaluate(() => {
        const products = Array.from(
          document.querySelectorAll('[data-testid^="kiosk-product-card-"]'),
        ).map((el) => {
          const tid = el.getAttribute('data-testid') || '';
          const m = tid.match(/kiosk-product-card-(\d+)/);
          const name = el.querySelector('.kiosk-product-name, h3, h4')?.textContent?.trim() || '';
          return { item_id: m ? Number(m[1]) : null, name };
        });
        return {
          product_count: products.length,
          first_three: products.slice(0, 3),
        };
      });
      sidecar.states['03-kiosk-cat-309-assiettes'] = {
        url: page.url(),
        ...cat309Probe,
      };
      await snap('03-kiosk-cat-309-assiettes');
      writeSidecar();

      // ============================================================
      // STATES 04-06 — wizard product (sauce / supplements / recap)
      // ============================================================
      // Pick the first product in cat 309 (Assiette Poulet typical). Open the
      // wizard. Walk through up to 8 steps, capturing the FIRST step that
      // matches each target classname pattern.
      const productId = await firstProductIdInCurrentCategory(page);
      if (!productId) {
        // No product available — record fallback and capture three identical
        // "no-product" frames so the quartets exist for adversarial review.
        sidecar.states['04-kiosk-wizard-product-step-sauce'] = { fallback: 'no-product-in-category-309' };
        sidecar.states['05-kiosk-wizard-product-step-supplements'] = { fallback: 'no-product-in-category-309' };
        sidecar.states['06-kiosk-wizard-recap'] = { fallback: 'no-product-in-category-309' };
        await snap('04-kiosk-wizard-product-step-sauce');
        await snap('05-kiosk-wizard-product-step-supplements');
        await snap('06-kiosk-wizard-recap');
        writeSidecar();
      }
      const productName = productId
        ? await page
          .getByTestId(`kiosk-product-card-${productId}`)
          .locator('.kiosk-product-name, h3, h4')
          .first()
          .innerText()
          .catch(() => '?')
        : '?';
      if (productId) {
        sidecar.states['04-kiosk-wizard-product-step-sauce'] = { product_id: productId, product_name: productName };

        await page.getByTestId(`kiosk-product-add-${productId}`).click({ timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(600);
      }

      // If the wizard didn't open (direct-add product), record P3 finding and
      // fall back to capturing the "no-wizard" state for steps 04/05/06.
      const wizardVisible = productId
        ? await page
          .locator('.kiosk-wizard')
          .first()
          .isVisible({ timeout: 2500 })
          .catch(() => false)
        : false;

      if (productId && !wizardVisible) {
        sidecar.states['04-kiosk-wizard-product-step-sauce'].fallback =
          'no-wizard-for-first-product (direct-add); capturing pre-add state';
        sidecar.states['05-kiosk-wizard-product-step-supplements'] = { fallback: 'no-wizard' };
        sidecar.states['06-kiosk-wizard-recap'] = { fallback: 'no-wizard' };
        await snap('04-kiosk-wizard-product-step-sauce');
        await snap('05-kiosk-wizard-product-step-supplements');
        await snap('06-kiosk-wizard-recap');
        writeSidecar();
      } else if (productId && wizardVisible) {
        // Walk the wizard. We try to capture states that semantically match:
        //   step-sauce       (.kiosk-step-sauce | first wizard step)
        //   step-supplement  (.kiosk-step-supplement | .kiosk-step-extras)
        //   recap            (.kiosk-step-recap | add-to-cart mode = isAddMode)
        // For each step we capture once. We bound the walk to 10 iterations.
        const STEP_MATCHERS = [
          { stateName: '04-kiosk-wizard-product-step-sauce', match: (cn) => /sauce/i.test(cn) },
          { stateName: '05-kiosk-wizard-product-step-supplements', match: (cn) => /supplement|extra|extras/i.test(cn) },
          { stateName: '06-kiosk-wizard-recap', match: (cn, st) => /recap/i.test(cn) || st.isAddMode },
        ];
        const captured = new Set();

        // Snap whatever the FIRST step is as state 04 (sauce slot, regardless of
        // exact class) — kiosk wizard often opens directly on sauce.
        await page.waitForTimeout(400);
        const firstState = await wizardStepClassname(page);
        sidecar.states['04-kiosk-wizard-product-step-sauce'].first_step = firstState;
        await snap('04-kiosk-wizard-product-step-sauce');
        captured.add('04-kiosk-wizard-product-step-sauce');
        writeSidecar();

        // Walk up to 10 steps, capturing supplement + recap when encountered.
        // CRITICAL: when we hit recap we MUST also click the add-to-cart CTA
        // before breaking so the cart contains a line item — state 07-10 depend
        // on a non-empty cart (route guard requireCart redirects /kiosk/loyalty
        // back to /kiosk/cart if empty).
        for (let iter = 0; iter < 10; iter += 1) {
          const st = await wizardStepClassname(page);
          if (!st.open) break;
          // Capture any state slot not yet captured if matcher matches.
          for (const m of STEP_MATCHERS) {
            if (captured.has(m.stateName)) continue;
            if (m.match(st.stepClassname || '', st)) {
              sidecar.states[m.stateName] = sidecar.states[m.stateName] || {};
              sidecar.states[m.stateName].iter = iter;
              sidecar.states[m.stateName].stepClassname = st.stepClassname;
              sidecar.states[m.stateName].isAddMode = st.isAddMode;
              await snap(m.stateName);
              captured.add(m.stateName);
              writeSidecar();
            }
          }

          // If at recap (add mode), click add-to-cart THEN break.
          if (st.isAddMode && !st.nextDisabled) {
            // Wait for the slide-in transition to settle before clicking.
            await page.waitForTimeout(600);
            const cartBtn = page.locator('.kiosk-wizard .kiosk-btn-next--cart').first();
            const visible = await cartBtn.isVisible({ timeout: 1500 }).catch(() => false);
            if (visible) {
              await cartBtn.click({ timeout: 4000 }).catch(() => {});
            }
            // Wait for the wizard to unmount — confirms add-to-cart succeeded.
            const closed = await page
              .waitForFunction(
                () =>
                  !document.querySelector('.kiosk-wizard') &&
                  !document.querySelector('.kiosk-wizard-overlay'),
                null,
                { timeout: 6000 },
              )
              .then(() => true)
              .catch(() => false);
            sidecar.states['06-kiosk-wizard-recap'] = sidecar.states['06-kiosk-wizard-recap'] || {};
            sidecar.states['06-kiosk-wizard-recap'].add_to_cart_succeeded = closed;
            writeSidecar();
            break;
          }

          // Else, advance via option-card + Next.
          const advanced = await wizardClickFirstOptionAndAdvance(page);
          if (!advanced) {
            await page.waitForTimeout(300);
          }
        }

        // Fallback captures for any slot still missing.
        for (const m of STEP_MATCHERS) {
          if (!captured.has(m.stateName)) {
            sidecar.states[m.stateName] = sidecar.states[m.stateName] || {};
            sidecar.states[m.stateName].fallback = 'step-not-found-in-walker';
            await snap(m.stateName);
            captured.add(m.stateName);
            writeSidecar();
          }
        }
      }

      // Wait for wizard to fully unmount before navigating.
      await page
        .waitForFunction(
          () =>
            !document.querySelector('.kiosk-wizard') &&
            !document.querySelector('.kiosk-wizard-overlay'),
          null,
          { timeout: 6000 },
        )
        .catch(() => {});

      // Probe the cart indicator — record for sidecar (informational only).
      // The wizard's add-to-cart can race the .kiosk-wizard unmount; if the
      // resulting cart is empty the downstream loyalty/confirmation states
      // capture the route-guard redirect (kiosk-cart route), which is itself
      // a valid kiosk visual state worth comparing to mobile.
      const cartStateProbe = await page
        .evaluate(() => {
          const root = document.querySelector('.kiosk-categories-cart-indicator');
          const text = root ? (root.textContent || '').trim() : '';
          let storeLineCount = null;
          try {
            const app = document.querySelector('#app')?.__vue_app__;
            const store = app?.config?.globalProperties?.$store;
            if (store && store.getters && store.getters['kioskCart/items']) {
              storeLineCount = (store.getters['kioskCart/items'] || []).length;
            }
          } catch (_e) { /* ignore */ }
          return {
            indicator_text: text,
            store_line_count: storeLineCount,
          };
        })
        .catch(() => ({ indicator_text: '', store_line_count: null }));
      sidecar.cart_after_wizard = cartStateProbe;
      writeSidecar();

      // ============================================================
      // STATE 07 — kiosk-loyalty-input (step='input')
      // ============================================================
      // Navigate directly to /kiosk/loyalty. The route requires a non-empty
      // cart (see kioskRoutes.js guard at line 80). Our wizard add-to-cart at
      // state 06 satisfies that. If we're not on categories, try goto.
      await dismissInactivityIfPresent(page);

      // Some flows return to /kiosk/categories after add-to-cart; navigate
      // explicitly to ensure clean state for loyalty.
      if (!/\/kiosk\/categories/.test(page.url())) {
        // Try going back to categories first; if that fails, just goto loyalty.
        await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(500);
      }

      // The cart-loyalty-btn is exposed from the CART screen, not categories.
      // Navigate to cart first. Prefer the bottom-bar Payer CTA when ENABLED;
      // fall back to direct goto if disabled (empty cart edge case).
      const payBtn = page.getByTestId('kiosk-categories-pay');
      let cartReached = false;
      const payEnabled = await payBtn
        .isVisible({ timeout: 2000 })
        .then(async (vis) => {
          if (!vis) return false;
          const disabled = await payBtn.getAttribute('disabled').catch(() => null);
          return disabled === null || disabled === 'false';
        })
        .catch(() => false);
      if (payEnabled) {
        await payBtn.click({ timeout: 3000 }).catch(() => {});
        cartReached = await page
          .getByTestId('kiosk-cart-root')
          .isVisible({ timeout: 6000 })
          .catch(() => false);
      }
      if (!cartReached) {
        await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' }).catch(() => {});
        cartReached = await page
          .getByTestId('kiosk-cart-root')
          .isVisible({ timeout: 6000 })
          .catch(() => false);
      }

      // Trigger loyalty navigation. Prefer the in-cart CTA; fall back to direct goto.
      let loyaltyReached = false;
      if (cartReached) {
        const loyaltyBtn = page.getByTestId('kiosk-cart-loyalty-btn');
        if (await loyaltyBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await loyaltyBtn.click();
          await page
            .waitForURL((u) => /\/kiosk\/loyalty/.test(u.pathname), { timeout: 8000 })
            .catch(() => {});
          loyaltyReached = /\/kiosk\/loyalty/.test(page.url());
        }
      }
      if (!loyaltyReached) {
        await page.goto('/kiosk/loyalty', { waitUntil: 'domcontentloaded' }).catch(() => {});
        loyaltyReached = /\/kiosk\/loyalty/.test(page.url());
      }

      await page.waitForTimeout(900);
      const loyaltyInputProbe = await probePaletteAndFonts(page);
      const registerBtnProbe = await page
        .evaluate(() => {
          const btn = document.querySelector('.kiosk-loyalty-register-btn');
          if (!btn) return { found: false };
          const cs = window.getComputedStyle(btn);
          return {
            found: true,
            backgroundImage: cs.backgroundImage,
            color: cs.color,
          };
        })
        .catch(() => ({ found: false }));
      sidecar.states['07-kiosk-loyalty-input'] = {
        url: page.url(),
        loyalty_reached: loyaltyReached,
        probe: loyaltyInputProbe,
        register_btn: registerBtnProbe,
      };
      await snap('07-kiosk-loyalty-input');
      writeSidecar();

      // ============================================================
      // STATE 08 — kiosk-loyalty-register (step='register')
      // ============================================================
      const registerCta = page.locator('.kiosk-loyalty-register-btn').first();
      let registerStepShown = false;
      if (await registerCta.isVisible({ timeout: 2000 }).catch(() => false)) {
        await registerCta.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(800);
        registerStepShown = await page
          .locator('input[data-testid="kiosk-loyalty-register-name"]')
          .isVisible({ timeout: 4000 })
          .catch(() => false);
      }
      sidecar.states['08-kiosk-loyalty-register'] = {
        url: page.url(),
        register_step_shown: registerStepShown,
      };
      await snap('08-kiosk-loyalty-register');
      writeSidecar();

      // ============================================================
      // STATE 09 — kiosk-loyalty-balance (best-effort)
      // ============================================================
      // To reach the balance step we'd need a verified loyalty customer. The DB
      // has none seeded (verified 2026-05-11). We therefore:
      //   1. Go back to step='input' (← Retour from register).
      //   2. Attempt to seed a fixture user with loyalty_code='WAVEE9999' and
      //      loyalty_points=420 via php artisan tinker (best-effort soft-fail).
      //   3. Type the code, click verify, snap whatever lands.
      //   4. Record the actual displayed points in sidecar for PARITY-3-bis.
      let balanceReached = false;
      let balanceValue = null;
      let seedAttempt = null;

      // Best-effort tinker seed. If artisan is unavailable we capture the
      // input-step + error state and log a P3-informational note.
      try {
        const out = require('child_process').execFileSync(
          'php',
          ['artisan', 'tinker', '--execute', `
            use App\\Models\\User;
            $code = 'WAVEE9999';
            $existing = User::where('loyalty_code', $code)->first();
            if (!$existing) {
              $u = User::firstOrCreate(
                ['phone' => '0600009999'],
                [
                  'name' => 'Wave-E Reference Customer',
                  'email' => 'wavee.ref@lecayenne.fr',
                  'username' => 'wavee-ref-' . substr(bin2hex(random_bytes(2)), 0, 4),
                  'password' => bcrypt('placeholder'),
                  'branch_id' => 0,
                  'is_guest' => 0,
                  'status' => 1,
                  'loyalty_code' => $code,
                  'loyalty_points' => 420,
                ]
              );
              if (!$u->loyalty_code) {
                $u->loyalty_code = $code;
                $u->loyalty_points = 420;
                $u->save();
              }
              echo json_encode(['ok' => true, 'created' => true, 'id' => $u->id, 'points' => (int) $u->loyalty_points]);
            } else {
              if ((int) $existing->loyalty_points < 420) {
                $existing->loyalty_points = 420;
                $existing->save();
              }
              echo json_encode(['ok' => true, 'created' => false, 'id' => $existing->id, 'points' => (int) $existing->loyalty_points]);
            }
          `],
          {
            cwd: path.resolve(__dirname, '../..'),
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 15000,
          },
        );
        // Parse the LAST JSON line from output.
        const lines = String(out).split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
        const jsonLine = [...lines].reverse().find((s) => s.startsWith('{'));
        seedAttempt = jsonLine ? JSON.parse(jsonLine) : { ok: false, reason: 'no-json-in-output' };
      } catch (err) {
        seedAttempt = { ok: false, reason: String(err?.message || err).slice(0, 200) };
      }
      sidecar.loyalty.seed_user = seedAttempt;

      // Navigate back to input step.
      const backFromRegister = page.locator('.kiosk-loyalty-skip').first();
      if (registerStepShown && (await backFromRegister.isVisible({ timeout: 1500 }).catch(() => false))) {
        await backFromRegister.click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(500);
      }

      // Fill code and verify.
      const codeInput = page.locator('.kiosk-loyalty-input').first();
      if (await codeInput.isVisible({ timeout: 2000 }).catch(() => false)) {
        await codeInput.click({ timeout: 1500 }).catch(() => {});
        // The input ref="codeInput" doesn't have a testid but is the first
        // .kiosk-loyalty-input on the page. Fill via JS to bypass any readonly
        // attr on register fields (this one is NOT readonly per template).
        await page.evaluate(() => {
          const input = document.querySelector('.kiosk-loyalty-input');
          if (input) {
            const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            setter.call(input, 'WAVEE9999');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        await page.waitForTimeout(300);
        // Click verify (kiosk-btn-primary.full inside step=input).
        const verifyBtn = page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').first();
        if (await verifyBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
          await verifyBtn.click({ timeout: 3000 }).catch(() => {});
          await page.waitForTimeout(2500);
        }
      }

      // Inspect whether we landed on step='balance' (.kiosk-loyalty-points-value).
      balanceValue = await page
        .locator('.kiosk-loyalty-points-value')
        .first()
        .innerText({ timeout: 2000 })
        .catch(() => null);
      balanceReached = Boolean(balanceValue);

      sidecar.loyalty.balance_step_reached = balanceReached;
      sidecar.loyalty.balance_value_observed = balanceValue;
      sidecar.states['09-kiosk-loyalty-balance'] = {
        url: page.url(),
        balance_reached: balanceReached,
        balance_value: balanceValue,
        fallback: balanceReached ? null : 'balance-not-reached (seed/lookup may have failed); capture shows current step',
      };
      await snap('09-kiosk-loyalty-balance');
      writeSidecar();

      // ============================================================
      // STATE 10 — kiosk-confirmation-screen
      // ============================================================
      // Reaching confirmation requires a full CB happy-path. The borne-E spec
      // already proves this is feasible; we replicate the tail end. If any
      // step fails we fall back to capturing whatever state we're in and
      // record a sidecar note (state slot must still produce the quartet so
      // the adversarial reviewer has a known artifact set).
      let confirmationReached = false;
      let confirmationTotal = null;
      let confirmationNumber = null;

      try {
        // After applyLoyalty, KioskLoyaltyComponent.proceedToPayment routes to
        // /kiosk/payment (or /kiosk/upsell first). Determine where we are and
        // converge towards /kiosk/payment without bouncing back to /kiosk/cart
        // (the loyalty.confirm CTA already advanced past cart).
        await page.waitForTimeout(800);
        let url = page.url();
        // Handle upsell.
        if (/\/kiosk\/upsell/.test(url)) {
          const upsellSkip = page.getByTestId('kiosk-upsell-skip');
          await upsellSkip.waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
          await upsellSkip.click({ timeout: 4000, force: true }).catch(() => {});
          await page
            .waitForURL((u) => /\/kiosk\/payment/.test(u.pathname), { timeout: 8000 })
            .catch(() => {});
          url = page.url();
        }
        // If still on loyalty (balance step), click confirm to advance.
        if (/\/kiosk\/loyalty/.test(url)) {
          const confirmBtn = page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').first();
          if (await confirmBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
            // For loyalty.balance the button is "Continuer" / "Confirmer" — it
            // applies the loyalty decision (with or without redeem) and pushes
            // to /kiosk/payment via proceedToPayment().
            await confirmBtn.click({ timeout: 3000 }).catch(() => {});
            await page
              .waitForURL((u) => /\/kiosk\/(payment|upsell)/.test(u.pathname), {
                timeout: 10000,
              })
              .catch(() => {});
            url = page.url();
            if (/\/kiosk\/upsell/.test(url)) {
              const upsellSkip2 = page.getByTestId('kiosk-upsell-skip');
              await upsellSkip2.waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
              await upsellSkip2.click({ timeout: 4000, force: true }).catch(() => {});
              await page
                .waitForURL((u) => /\/kiosk\/payment/.test(u.pathname), { timeout: 8000 })
                .catch(() => {});
              url = page.url();
            }
          }
        }
        // If we're STILL not on /kiosk/payment, this is a flow-state issue
        // (cart may have been auto-cleared, route guard kicked us back). In
        // that case the test 10 capture is the fallback for adversarial review.
        if (/\/kiosk\/payment/.test(page.url())) {
          await expect(page.getByTestId('kiosk-payment-root'))
            .toBeVisible({ timeout: 8000 })
            .catch(() => {});
          // Pick CB.
          const cardBtn = page.getByTestId('kiosk-payment-method-card');
          if (await cardBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
            await cardBtn.click();
            await page.waitForTimeout(250);
            const confirmPaymentBtn = page.getByTestId('kiosk-payment-confirm');
            if (await confirmPaymentBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
              await confirmPaymentBtn.click();
              // Confirmation may take 5-30s (TPE stub + retry path).
              confirmationReached = await page
                .getByTestId('kiosk-confirmation-root')
                .isVisible({ timeout: 50000 })
                .catch(() => false);
              if (confirmationReached) {
                await page.waitForTimeout(500);
                confirmationTotal = await page
                  .getByTestId('kiosk-confirmation-total')
                  .innerText()
                  .catch(() => null);
                confirmationNumber = await page
                  .getByTestId('kiosk-confirmation-number')
                  .innerText()
                  .catch(() => null);
              }
            }
          }
        }
      } catch (err) {
        sidecar.states['10-kiosk-confirmation-screen'] = sidecar.states['10-kiosk-confirmation-screen'] || {};
        sidecar.states['10-kiosk-confirmation-screen'].error = String(err?.message || err).slice(0, 200);
      }

      sidecar.states['10-kiosk-confirmation-screen'] = {
        ...(sidecar.states['10-kiosk-confirmation-screen'] || {}),
        url: page.url(),
        confirmation_reached: confirmationReached,
        confirmation_total_text: confirmationTotal,
        confirmation_total: parsePriceText(confirmationTotal),
        confirmation_number: confirmationNumber,
        fallback: confirmationReached ? null : 'confirmation-not-reached; capture shows final state of journey',
      };
      await snap('10-kiosk-confirmation-screen');
      writeSidecar();

      // ============================================================
      // Final assertions — soft (parity-reference, must PASS regardless).
      // ============================================================
      // We require ONLY that all 10 captures emitted a PNG quartet. Any
      // visual finding goes to the adversarial reviewer.
      const expectedStates = [
        '01-kiosk-idle-screen',
        '02-kiosk-categories-welcome',
        '03-kiosk-cat-309-assiettes',
        '04-kiosk-wizard-product-step-sauce',
        '05-kiosk-wizard-product-step-supplements',
        '06-kiosk-wizard-recap',
        '07-kiosk-loyalty-input',
        '08-kiosk-loyalty-register',
        '09-kiosk-loyalty-balance',
        '10-kiosk-confirmation-screen',
      ];
      for (const stateName of expectedStates) {
        const png = path.join(SCREENSHOT_DIR, `${stateName}.png`);
        expect(fs.existsSync(png), `Expected PNG for state ${stateName}`).toBe(true);
      }
    } finally {
      // Persist final sidecar with whatever was collected.
      try {
        fs.writeFileSync(
          path.join(SCREENSHOT_DIR, '_sidecar.json'),
          JSON.stringify(sidecar, null, 2),
        );
      } catch (_e) { /* ignore */ }
      dispose();
    }
  });
});
