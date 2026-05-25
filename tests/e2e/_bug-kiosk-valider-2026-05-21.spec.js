// Bug repro — Kiosk "Valider" button raises error at cart level
// Owner verbatim 2026-05-21 : ajout produit au panier → click Valider → erreur,
// "parfois ça disparait". We bypass the wizard step-through by injecting an
// item directly into Vuex (mimicking what KioskWizardComponent does on
// "Ajouter au panier"), then drive the cart → Valider click and capture the
// /frontend/order/quote response in full.
//
// Captures land in tests/e2e/__screenshots__/bug-kiosk-valider/

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'bug-kiosk-valider');

test.describe('BUG repro — Kiosk Valider cart error 2026-05-21', () => {
  test.setTimeout(120_000);

  test('reproduce Valider error and capture network', async ({ page }) => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    await page.setViewportSize({ width: 1080, height: 1920 });

    // Dedicated capture buffer for /frontend/order/quote response body
    const quoteResponses = [];
    page.on('response', async (resp) => {
      try {
        const url = resp.url();
        if (!/\/frontend\/order\/quote/.test(url)) return;
        const status = resp.status();
        const headers = resp.headers();
        let body = null;
        try { body = await resp.text(); } catch (_) {}
        const reqHeaders = resp.request().headers();
        quoteResponses.push({
          url,
          status,
          method: resp.request().method(),
          contentType: headers['content-type'] || null,
          authHeader: reqHeaders['authorization'] ? `PRESENT (${reqHeaders['authorization'].substring(0, 20)}…)` : 'MISSING',
          idempotencyHeader: reqHeaders['x-idempotency-key'] || null,
          requestBody: (() => {
            try { return resp.request().postData()?.substring(0, 4000) || null; } catch (_) { return null; }
          })(),
          responseBody: body ? body.substring(0, 4000) : null,
          ts: Date.now(),
        });
      } catch (_) { /* defensive */ }
    });

    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // ----- Step 1 : land on idle, wait for auto-login -----
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_500);
    await snap('BUG-01-kiosk-idle');

    // ----- Step 2 : tap "À emporter" to set orderType=10 -----
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    await expect(takeaway).toBeVisible({ timeout: 15_000 });
    await takeaway.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);
    await snap('BUG-02-categories');

    // Probe state after takeaway tap
    const after2 = await page.evaluate(() => {
      try {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        return {
          orderType: store?.state?.kioskCart?.orderType ?? null,
          kioskToken: store?.state?.kioskCart?.kioskToken ? 'PRESENT' : 'MISSING',
          itemsCount: store?.state?.kioskCart?.items?.length ?? 0,
          frontendSettingsKeys: Object.keys(store?.state?.frontendSetting?.lists || {})
            .filter((k) => /dine|order|pos/i.test(k))
            .reduce((acc, k) => { acc[k] = store.state.frontendSetting.lists[k]; return acc; }, {}),
          pos_dine_in_enabled: store?.state?.frontendSetting?.lists?.pos_dine_in_enabled,
          firstMenuItemId: store?.state?.kioskMenu?.items?.[0]?.id ?? null,
        };
      } catch (e) { return { error: String(e) }; }
    });
    console.log('[BUG] State after takeaway:', JSON.stringify(after2, null, 2));

    // ----- Step 3 : inject a synthetic cart line via Vuex bypass -----
    // We mimic what KioskWizardComponent does on "Ajouter au panier" :
    // dispatch('kioskCart/addItem', { item_id, name, convert_price, quantity, item_variations, item_extras, total })
    // We pick the first menu item that's available (status=1, is_available != false).
    const injected = await page.evaluate(() => {
      try {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        const items = store?.state?.kioskMenu?.items || [];
        // pick first available item
        const candidate = items.find((it) => {
          const status = parseInt(it.status, 10);
          return it.is_available !== false && (status === 1 || isNaN(status));
        }) || items[0];
        if (!candidate) return { ok: false, reason: 'no menu items in kioskMenu store' };
        const line = {
          item_id: parseInt(candidate.id, 10),
          name: candidate.name || 'TestProduct',
          image: candidate.image || null,
          convert_price: parseFloat(candidate.convert_price ?? candidate.price ?? 5),
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: [],
          item_extras: [],
          item_addons: [],
          quantity: 1,
          instruction: '',
        };
        line.total = (line.convert_price + line.item_variation_total + line.item_extra_total) * line.quantity;
        store.dispatch('kioskCart/addItem', line);
        return { ok: true, line: { ...line, image: line.image ? 'IMG' : null } };
      } catch (e) { return { ok: false, error: String(e) }; }
    });
    console.log('[BUG] Injected cart line:', JSON.stringify(injected));

    // ----- Step 4 : navigate to cart -----
    await page.evaluate(() => {
      try {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const router = vm && vm.config?.globalProperties?.$router;
        if (router) router.push({ name: 'kiosk.cart' });
      } catch (_) {}
    });
    await page.waitForTimeout(2_000);
    await snap('BUG-03-cart-after-inject');

    // Probe state in cart
    const stateBefore = await page.evaluate(() => {
      try {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        return {
          orderType: store?.state?.kioskCart?.orderType ?? null,
          kioskToken: store?.state?.kioskCart?.kioskToken ? 'PRESENT' : 'MISSING',
          itemsCount: store?.state?.kioskCart?.items?.length ?? 0,
          firstItem: store?.state?.kioskCart?.items?.[0] ? {
            item_id: store.state.kioskCart.items[0].item_id,
            quantity: store.state.kioskCart.items[0].quantity,
            total: store.state.kioskCart.items[0].total,
          } : null,
          url: window.location.href,
          validerVisible: !!document.querySelector('[data-testid="kiosk-cart-checkout"]'),
        };
      } catch (e) { return { error: String(e) }; }
    });
    console.log('[BUG] State BEFORE Valider click:', JSON.stringify(stateBefore, null, 2));

    // ----- Step 5 : click VALIDER -----
    const valider = page.locator('[data-testid="kiosk-cart-checkout"]').first();
    const validerVisible = await valider.isVisible({ timeout: 5_000 }).catch(() => false);
    console.log('[BUG] Valider visible?', validerVisible);
    if (validerVisible) {
      await valider.click({ timeout: 5_000 });
      await page.waitForTimeout(4_000);
    }

    await snap('BUG-04-after-valider-click');

    // Read inline error + toast
    const cartErrorText = await page.locator('[data-testid="kiosk-cart-quote-error"]').first().textContent({ timeout: 1_000 }).catch(() => null);
    const toastText = await page.locator('.Vue-Toastification__toast--error, [class*="toast"][class*="error"]').first().textContent({ timeout: 1_000 }).catch(() => null);

    const stateAfter = await page.evaluate(() => {
      try {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        return {
          orderType: store?.state?.kioskCart?.orderType ?? null,
          orderQuote: store?.state?.kioskCart?.orderQuote ? 'PRESENT' : 'NULL',
          url: window.location.href,
        };
      } catch (e) { return { error: String(e) }; }
    });
    console.log('[BUG] State AFTER Valider click:', JSON.stringify(stateAfter, null, 2));
    console.log('[BUG] Cart inline error text:', cartErrorText);
    console.log('[BUG] Toast text:', toastText);

    // Write captures to disk
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'BUG-quote-responses.json'),
      JSON.stringify(quoteResponses, null, 2),
    );
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'BUG-state-snapshot.json'),
      JSON.stringify({ after2, injected, stateBefore, stateAfter, cartErrorText, toastText }, null, 2),
    );

    // ----- Step 6 : retry Valider 2x more to test "parfois ça disparait" -----
    // The owner reported the error sometimes disappears. Click Valider 2 more
    // times with a 2s wait to capture whether the error toggles.
    for (let i = 0; i < 2; i++) {
      if (await valider.isVisible({ timeout: 500 }).catch(() => false)
          && !(await valider.isDisabled().catch(() => true))) {
        await valider.click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(3_000);
      }
    }
    await snap('BUG-05-after-retry-clicks');

    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'BUG-quote-responses-final.json'),
      JSON.stringify(quoteResponses, null, 2),
    );

    console.log('\n========= BUG SUMMARY =========');
    console.log('Quote requests captured:', quoteResponses.length);
    quoteResponses.forEach((r, i) => {
      console.log(`\n--- [${i}] ${r.method} ${r.url} → ${r.status}`);
      console.log(`Auth: ${r.authHeader}`);
      console.log(`Request body (first 400 chars): ${(r.requestBody || '').substring(0, 400)}`);
      console.log(`Response body (first 1000 chars): ${(r.responseBody || '').substring(0, 1000)}`);
    });
    console.log('\nFinal state:', JSON.stringify(stateAfter));
    console.log('Cart error:', cartErrorText);
    console.log('==============================\n');
  });
});
