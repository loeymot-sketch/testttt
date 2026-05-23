// Wave Final S1 Kiosk Borne — page-by-page MAX-reasoning capture (2026-05-23).
//
// Mission (from agent brief) :
//   Capture the kiosk borne states (idle → categories → product list →
//   simple wizard → composer wizard → cart → upsell/payment → confirmation),
//   one quartet per state (PNG + DOM + console + network), via the helper
//   attachMegaAuditRecorder. NO frozen-zone file mutation. UI traversal is
//   the source of truth — kiosk-order.js helper bypasses the wizard, so we
//   drive the actual KioskApp / KioskCategories / KioskWizard / KioskCart /
//   KioskPayment / KioskConfirmation Vue components.
//
// Constraints :
//   - pos.dine_in_enabled = false in V1 → no order-type screen ; idle → category
//     directly. If the type screen appears, capture it (would mean the guard
//     regressed) but still proceed via TAKEAWAY (order_type=10).
//   - Frozen-zones §7 CLAUDE.md : KioskApp/Wizard/Upsell. We INSPECT only.
//   - paymentMethod = cash (1) — deterministic, no TPE hardware sim needed.
//   - cart items injected directly into Vuex (kioskCart) for deterministic
//     reproduction of the "Valider" pre-flight fix (commit 6f226ef67) — UI
//     path through the wizard is also exercised for S1-05/S1-06 capture.
//   - Output dir : tests/e2e/__screenshots__/wave-final-S1-kiosk/

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/wave-final-S1-kiosk',
);

// Pause to let Vue mount, axios fetches resolve, and Echo subscribe. The
// kiosk app's KioskApp.created() flow does several awaited HTTP calls
// (menu + login if not logged in + Echo subscribe) so 3-4s is the safe
// minimum between gotos.
const SETTLE_MS = 3000;

test.describe('Wave Final S1 Kiosk — page-by-page capture 2026-05-23', () => {
  test.setTimeout(420_000); // 7 min — generous for slow Vue + Echo init.

  let snap;

  test.beforeAll(async () => {
    // Output dir created by attachMegaAuditRecorder per-page, but we ensure it
    // exists ahead of time so failing-fast still leaves a directory behind.
    const fs = require('fs');
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  });

  test('S1 borne — capture quartet per state', async ({ page }) => {
    // Mobile-portrait borne viewport (mirrors the actual hardware tablet).
    await page.setViewportSize({ width: 1080, height: 1920 });

    const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    snap = recorder.snap;

    // -----------------------------------------------------------------------
    // S1-01 — Idle screen (tap-to-start)
    // -----------------------------------------------------------------------
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(SETTLE_MS);
    await snap('S1-01-kiosk-idle');

    // Expect the "tap to order" CTA OR the type-selection (depends on the
    // dine-in flag). The idle screen specifically should show the touch-to-
    // start affordance. We tolerate either by inspecting whichever the page
    // exposes — capture is what matters for the reviewer.
    //
    // Sanity check: page mounted into Vue and idle Vue route is current.
    const route = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const router = vm && vm.config?.globalProperties?.$router;
      return router ? router.currentRoute.value.name || router.currentRoute.value.path : null;
    });
    expect(route).toBeTruthy();

    // -----------------------------------------------------------------------
    // S1-02 — Order type selection (sur place vs à emporter)
    //   In V1 with pos.dine_in_enabled=false, the type screen should be
    //   bypassed and the user lands directly on /kiosk/categories with
    //   TAKEAWAY pre-selected. We capture whatever the click produces, so
    //   if the screen DOES appear (regression), we have the evidence.
    // -----------------------------------------------------------------------
    // Trigger the "tap to start" interaction. The kiosk idle component
    // listens for click anywhere on the screen — we click the centre.
    await page.evaluate(() => {
      const idle = document.querySelector('.kiosk-idle, [data-testid="kiosk-idle-tap-zone"], .kiosk-idle-screen');
      if (idle) idle.click();
      else document.body.click();
    });
    await page.waitForTimeout(2000);
    await snap('S1-02-after-tap-order-type-or-bypass');

    // Whatever rendered, force TAKEAWAY via store so the rest of the flow is
    // deterministic. If the type screen IS rendered, this is what the click
    // on "À emporter" would do internally.
    await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      if (store) {
        store.dispatch('kioskCart/setOrderType', 10); // 10 = TAKEAWAY
      }
      const router = vm && vm.config?.globalProperties?.$router;
      if (router) router.push({ name: 'kiosk.categories' }).catch(() => {});
    });
    await page.waitForTimeout(SETTLE_MS);

    // -----------------------------------------------------------------------
    // S1-03 — Category list
    // -----------------------------------------------------------------------
    await snap('S1-03-categories-list');

    // Pick the first available category to drill into (S1-04).
    // Route is `kiosk.products` with param `categoryId` (numeric id).
    const categoryId = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const cats = store?.state?.kioskMenu?.categories || [];
      // Prefer a category that has items.
      const cat = cats.find((c) => Array.isArray(c.items) && c.items.length > 0) || cats[0];
      return cat ? (cat.id ?? cat.category_id ?? null) : null;
    });
    if (categoryId != null) {
      await page.evaluate((cid) => {
        const app = document.getElementById('app');
        const vm = app && app.__vue_app__;
        const router = vm && vm.config?.globalProperties?.$router;
        return router.push({ name: 'kiosk.products', params: { categoryId: String(cid) } }).catch(() => {});
      }, categoryId);
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S1-04-product-list-in-category');

    // -----------------------------------------------------------------------
    // S1-05 — Simple product wizard (a sandwich / item without complex composer)
    //   We pick an item with no composer_profile_id or a simple wizard profile
    //   so the wizard has just the extras + sauces step.
    // -----------------------------------------------------------------------
    const simpleItem = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      // The kiosk menu module may not expose `items` as a flat array; the
      // categories-with-items shape is the canonical kiosk menu store. Walk
      // categories and flatten.
      const ks = store?.state?.kioskMenu || {};
      let flatItems = Array.isArray(ks.items) ? ks.items : [];
      if (!flatItems.length && Array.isArray(ks.categories)) {
        ks.categories.forEach((c) => {
          (c.items || []).forEach((it) => flatItems.push(it));
        });
      }
      const simple = flatItems.find((i) =>
        i.is_available !== false &&
        !i.composer_profile_id &&
        parseFloat(i.price) > 0
      );
      return simple
        ? { id: simple.id, name: simple.name, slug: simple.slug, price: simple.price, status: simple.status, available: simple.is_available, has_composer: !!simple.composer_profile_id }
        : { __debug_items_seen: flatItems.length, __sample: flatItems[0] || null };
    });
    // eslint-disable-next-line no-console
    console.log('[S1-05 simple item]', JSON.stringify(simpleItem));

    if (simpleItem) {
      await page.evaluate((it) => {
        const app = document.getElementById('app');
        const vm = app && app.__vue_app__;
        const router = vm && vm.config?.globalProperties?.$router;
        router.push({ name: 'kiosk.wizard', params: { itemId: it.id } }).catch(() => {
          router.push(`/kiosk/wizard/${it.id}`).catch(() => {});
        });
      }, simpleItem);
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S1-05-simple-wizard');

    // -----------------------------------------------------------------------
    // S1-06 — Composer wizard (multi-step item like Tacos / Bol)
    // -----------------------------------------------------------------------
    // composer_profile_id is NOT on Item — it lives in item_wizard_profiles join,
    // which the kiosk menu does not surface as a flat column. We fall back to
    // a name-match for items known to use the multi-step wizard (Tacos / Bol /
    // Sandwich Cayenne / Big Cayenne — owner V2 catalog 2026-05-21).
    const composerItem = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const ks = store?.state?.kioskMenu || {};
      let flatItems = Array.isArray(ks.items) ? ks.items : [];
      if (!flatItems.length && Array.isArray(ks.categories)) {
        ks.categories.forEach((c) => {
          (c.items || []).forEach((it) => flatItems.push(it));
        });
      }
      const composerNames = [/tacos/i, /^bol\b/i, /sandwich cayenne/i, /big cayenne/i, /assiette/i];
      const composer = flatItems.find((i) =>
        i.is_available !== false &&
        composerNames.some((rx) => rx.test(i.name || ''))
      );
      return composer
        ? { id: composer.id, name: composer.name, slug: composer.slug, price: composer.price }
        : null;
    });
    // eslint-disable-next-line no-console
    console.log('[S1-06 composer item]', JSON.stringify(composerItem));
    if (composerItem) {
      await page.evaluate((it) => {
        const app = document.getElementById('app');
        const vm = app && app.__vue_app__;
        const router = vm && vm.config?.globalProperties?.$router;
        router.push({ name: 'kiosk.wizard', params: { itemId: it.id } }).catch(() => {
          router.push(`/kiosk/wizard/${it.id}`).catch(() => {});
        });
      }, composerItem);
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S1-06-composer-wizard');

    // -----------------------------------------------------------------------
    // S1-07 — Cart populated. We inject 2 deterministic cart lines (a simple
    //   item + the composer item if available) directly into the store, then
    //   navigate to /kiosk/cart. This decouples capture from the wizard
    //   button-by-button flow (still captured visually at S1-05/06).
    // -----------------------------------------------------------------------
    const cartInject = await page.evaluate(({ simple, composer }) => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const router = vm && vm.config?.globalProperties?.$router;
      function buildLine(it) {
        if (!it) return null;
        const base = parseFloat(it.price || 5);
        return {
          item_id: parseInt(it.id, 10),
          name: it.name,
          quantity: 1,
          convert_price: base,
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: [],
          item_extras: [],
          total: base,
          instruction: '',
        };
      }
      store.dispatch('kioskCart/reset');
      store.dispatch('kioskCart/setOrderType', 10);
      const lines = [buildLine(simple), buildLine(composer)].filter(Boolean);
      lines.forEach((l) => store.dispatch('kioskCart/addItem', l));
      // Snapshot state immediately AFTER dispatch (before navigation) so we can
      // distinguish "addItem did not commit" vs "later code path emptied items".
      return {
        post_dispatch_items: (store.state.kioskCart?.items || []).map((i) => ({
          item_id: i.item_id, qty: i.quantity, total: i.total,
        })),
        order_type: store.state.kioskCart?.orderType,
        lines_tried: lines.length,
      };
    }, { simple: simpleItem, composer: composerItem });
    // eslint-disable-next-line no-console
    console.log('[S1-07 cart inject]', JSON.stringify(cartInject));

    // Now navigate after store mutations have flushed.
    await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const router = vm && vm.config?.globalProperties?.$router;
      return router.push({ name: 'kiosk.cart' }).catch(() => router.push('/kiosk/cart').catch(() => {}));
    });
    await page.waitForTimeout(SETTLE_MS);

    // Re-check state after the cart route is mounted — diff exposes any
    // beforeEnter/created() that prunes our injection.
    const cartPostMount = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const router = vm && vm.config?.globalProperties?.$router;
      return {
        post_mount_items: (store.state.kioskCart?.items || []).map((i) => ({
          item_id: i.item_id, qty: i.quantity, total: i.total,
        })),
        route: router?.currentRoute?.value?.name || null,
        order_type: store.state.kioskCart?.orderType,
      };
    });
    // eslint-disable-next-line no-console
    console.log('[S1-07 cart post-mount]', JSON.stringify(cartPostMount));
    await snap('S1-07-cart-after-add');

    // -----------------------------------------------------------------------
    // S1-08 — Click "Valider" → next screen (upsell, then payment).
    //   This is the proof-of-fix for commit 6f226ef67 (pre-flight prune of
    //   unavailable lines). Cart contains only available items so the quote
    //   should succeed and we should navigate to /kiosk/upsell or
    //   /kiosk/payment depending on UpsellRule presence.
    // -----------------------------------------------------------------------
    // The Valider button is rendered by KioskCartComponent. Try standard
    // testids, fallback to button-text match.
    const validerLocator = page
      .locator('[data-testid="kiosk-cart-validate"], [data-testid="kiosk-cart-proceed"], button:has-text("Valider")')
      .first();
    if (await validerLocator.count()) {
      await validerLocator.scrollIntoViewIfNeeded().catch(() => {});
      await validerLocator.click({ timeout: 5000 }).catch(() => {});
    }
    await page.waitForTimeout(SETTLE_MS);
    await snap('S1-08-after-valider');

    // -----------------------------------------------------------------------
    // S1-09 — Payment method selection
    //   If we landed on upsell, skip past it (No-thanks / Continue button).
    //   Then capture the payment screen.
    // -----------------------------------------------------------------------
    const skipUpsell = page
      .locator('[data-testid="kiosk-upsell-skip"], button:has-text("Non merci"), button:has-text("Continuer")')
      .first();
    if (await skipUpsell.count()) {
      await skipUpsell.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S1-09-payment-method-selection');

    // -----------------------------------------------------------------------
    // S1-10 — Confirmation (after fake cash payment)
    //   The 3 payment tiles are clickable .kiosk-payment-method-card divs (not
    //   <button> elements) — we walk the rendered cards and click the one
    //   whose label-h3 says "Espèces". Fallback to any div containing the
    //   word "Espèces".
    // -----------------------------------------------------------------------
    const especes = await page
      .locator('[data-testid="kiosk-payment-method-cash"], [data-testid="kiosk-payment-cash"], .kiosk-payment-method-card:has-text("Espèces")')
      .first();
    if (await especes.count()) {
      await especes.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
    } else {
      // Last-ditch: walk DOM and click any clickable ancestor of the text node.
      await page.evaluate(() => {
        const candidate = Array.from(document.querySelectorAll('div, button, a'))
          .find((el) => /\bEspèces\b/i.test(el.textContent || '') && el.children.length <= 8);
        if (candidate) candidate.click();
      });
      await page.waitForTimeout(1500);
    }

    // Click Confirmer to commit the payment.
    const confirmer = page
      .locator('button:has-text("Confirmer"), [data-testid="kiosk-payment-confirm"]')
      .first();
    if (await confirmer.count()) {
      await confirmer.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS * 2);
    }
    await snap('S1-10-confirmation-or-payment-progress');

    // Final route + cart snapshot for the audit ledger.
    const finalState = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const router = vm && vm.config?.globalProperties?.$router;
      const store = vm && vm.config?.globalProperties?.$store;
      return {
        route: router?.currentRoute?.value?.name || router?.currentRoute?.value?.path || null,
        cart_lines: (store?.state?.kioskCart?.items || []).length,
      };
    });
    // eslint-disable-next-line no-console
    console.log('[S1] final route:', JSON.stringify(finalState));

    // Persist the recorder buffers as side-cars so subsequent reasoning has
    // the full console+network ledger for the whole run (snap() also flushes
    // per-state buffers — this gives a session-wide rollup).
    const fs = require('fs');
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_session-rollup.json'),
      JSON.stringify({
        final_state: finalState,
        simple_item: simpleItem,
        composer_item: composerItem,
        captured_at: new Date().toISOString(),
      }, null, 2),
    );
  });
});
