// Sentinel — Kiosk cart pre-flight pruning of unavailable lines
//
// Regression guard for the bug owner reported 2026-05-21 :
//   ItemBranchAvailability.is_available=false → persisted Vuex cart line
//   surviving the flip → /frontend/order/quote 422 → confusing toast.
//
// The fix (KioskCartComponent.proceedToUpsell pre-flight pruneUnavailableLines)
// must keep these properties :
//  1. If the cart contains ONLY unavailable lines, NO quote is fired and
//     the cart becomes empty with a warning toast.
//  2. If the cart contains a MIX of available + unavailable lines, the
//     unavailable lines are dropped, NO quote fires on the first click
//     (the user gets a notice to review), but a second click on the now-
//     clean cart succeeds (200, navigates to /kiosk/upsell).
//  3. If the cart contains only available lines, the behaviour is unchanged
//     — quote 200, nav to /kiosk/upsell.

const { test, expect } = require('@playwright/test');

test.describe('Kiosk Valider — unavailable line prune sentinel 2026-05-21', () => {
  test.setTimeout(120_000);

  async function bootKiosk(page) {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    await expect(takeaway).toBeVisible({ timeout: 15_000 });
    await takeaway.click({ timeout: 5_000 });
    await page.waitForTimeout(2_000);
  }

  async function findItems(page) {
    return await page.evaluate(() => {
      const root = document.getElementById('app');
      const vm = root && root.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const items = store?.state?.kioskMenu?.items || [];
      return {
        unavailable: items.find((i) => i.is_available === false) || null,
        available: items.find((i) => i.is_available !== false && parseInt(i.status, 10) !== 0 && parseInt(i.status, 10) !== 2) || null,
        sandwich: items.find((i) => /sandwich/i.test(i.name || '') && i.is_available !== false) || null,
      };
    });
  }

  async function inject(page, line) {
    return page.evaluate((line) => {
      const root = document.getElementById('app');
      const vm = root && root.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      store.dispatch('kioskCart/reset');
      store.dispatch('kioskCart/setOrderType', 10);
      if (Array.isArray(line)) {
        line.forEach((l) => store.dispatch('kioskCart/addItem', l));
      } else {
        store.dispatch('kioskCart/addItem', line);
      }
      const router = vm && vm.config?.globalProperties?.$router;
      router.push({ name: 'kiosk.cart' });
    }, line);
  }

  function buildLine(it, opts = {}) {
    const base = parseFloat(it.convert_price ?? it.price ?? 5);
    return {
      item_id: parseInt(it.id, 10),
      name: it.name,
      quantity: opts.quantity || 1,
      convert_price: base,
      item_variation_total: 0,
      item_extra_total: 0,
      item_variations: [],
      item_extras: [],
      total: base * (opts.quantity || 1),
      instruction: '',
    };
  }

  test('only unavailable lines → no quote fires, cart cleared', async ({ page }) => {
    const quoteResponses = [];
    page.on('response', async (resp) => {
      if (/\/frontend\/order\/quote/.test(resp.url())) {
        quoteResponses.push({ status: resp.status() });
      }
    });

    await bootKiosk(page);
    const { unavailable } = await findItems(page);
    test.skip(!unavailable, 'No unavailable item in kiosk menu — cannot sentinel');

    await inject(page, buildLine(unavailable));
    await page.waitForTimeout(1500);

    const valider = page.locator('[data-testid="kiosk-cart-checkout"]').first();
    await expect(valider).toBeVisible({ timeout: 5_000 });
    await valider.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);

    // Assertion 1 : no quote request was fired
    expect(quoteResponses.length).toBe(0);

    // Assertion 2 : cart is now empty
    const cartItemsCount = await page.evaluate(() => {
      const root = document.getElementById('app');
      const vm = root && root.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      return store?.state?.kioskCart?.items?.length ?? -1;
    });
    expect(cartItemsCount).toBe(0);
  });

  test('mixed cart → unavailable lines pruned, available line retained', async ({ page }) => {
    const quoteResponses = [];
    page.on('response', async (resp) => {
      if (/\/frontend\/order\/quote/.test(resp.url())) {
        quoteResponses.push({ status: resp.status() });
      }
    });

    await bootKiosk(page);
    const { unavailable, sandwich } = await findItems(page);
    test.skip(!unavailable || !sandwich, 'Need both unavailable and Sandwich items in menu');

    await inject(page, [buildLine(unavailable), buildLine(sandwich)]);
    await page.waitForTimeout(1500);

    const valider = page.locator('[data-testid="kiosk-cart-checkout"]').first();
    await expect(valider).toBeVisible({ timeout: 5_000 });
    await valider.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);

    // Assertion 1 : no quote fired YET (prune returned early)
    expect(quoteResponses.length).toBe(0);

    // Assertion 2 : cart now has only the Sandwich
    const cartItems = await page.evaluate(() => {
      const root = document.getElementById('app');
      const vm = root && root.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      return (store?.state?.kioskCart?.items ?? []).map((i) => i.item_id);
    });
    expect(cartItems.length).toBe(1);
    expect(cartItems[0]).toBe(parseInt(sandwich.id, 10));

    // Assertion 3 : second click now succeeds with 200
    await valider.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);
    expect(quoteResponses.length).toBeGreaterThan(0);
    expect(quoteResponses[0].status).toBe(200);
  });

  test('only available lines → unchanged behavior, quote fires 200', async ({ page }) => {
    const quoteResponses = [];
    page.on('response', async (resp) => {
      if (/\/frontend\/order\/quote/.test(resp.url())) {
        quoteResponses.push({ status: resp.status() });
      }
    });

    await bootKiosk(page);
    const { sandwich } = await findItems(page);
    test.skip(!sandwich, 'No Sandwich item available in menu');

    await inject(page, buildLine(sandwich));
    await page.waitForTimeout(1500);

    const valider = page.locator('[data-testid="kiosk-cart-checkout"]').first();
    await expect(valider).toBeVisible({ timeout: 5_000 });
    await valider.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);

    expect(quoteResponses.length).toBe(1);
    expect(quoteResponses[0].status).toBe(200);
    expect(page.url()).toMatch(/\/kiosk\/(upsell|payment)/);
  });
});
