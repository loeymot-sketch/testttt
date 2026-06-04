// Bug repro round 2 — wizard-output cart line shape (with item_addons/role)
// Simpler approach: inject cart lines server-side built, then test Valider.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'bug-kiosk-valider');

test.describe('BUG repro round-2 — kiosk wizard-shape cart 2026-05-21', () => {
  test.setTimeout(120_000);

  test('inject wizard-shape lines and click Valider', async ({ page }) => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    await page.setViewportSize({ width: 1080, height: 1920 });

    const quoteResponses = [];
    page.on('response', async (resp) => {
      try {
        const url = resp.url();
        if (!/\/frontend\/order\/quote/.test(url)) return;
        let body = null;
        try { body = await resp.text(); } catch (_) {}
        quoteResponses.push({
          url,
          status: resp.status(),
          requestBody: (() => { try { return resp.request().postData() || null; } catch (_) { return null; } })(),
          responseBody: body,
        });
      } catch (_) {}
    });

    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_500);
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    await expect(takeaway).toBeVisible({ timeout: 15_000 });
    await takeaway.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);

    // Snapshot menu so we know which IDs to use
    const menu = await page.evaluate(() => {
      const root = document.getElementById('app');
      const vm = root && root.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const items = (store?.state?.kioskMenu?.items || []).slice(0, 30).map((i) => ({
        id: parseInt(i.id, 10),
        name: i.name,
        convert_price: parseFloat(i.convert_price ?? i.price ?? 0),
        item_category_id: i.item_category_id,
        is_available: i.is_available,
      }));
      return items;
    });
    console.log('[BUG-R2] First 30 menu items:', JSON.stringify(menu.slice(0, 12), null, 2));

    const findItem = (rx) => menu.find((i) => rx.test(i.name)) || null;
    const burger = findItem(/burger/i);
    const tacos = findItem(/tacos/i);
    const bowl = findItem(/bol|bowl/i);
    const chicken = findItem(/chicken|poulet/i);
    const supplement = findItem(/jambon|cheddar/i);
    const sandwich = findItem(/sandwich|panini|baguette/i);

    console.log('[BUG-R2] Selected probes:', { burger, tacos, bowl, chicken, supplement, sandwich });

    const shapes = [];
    if (burger) {
      shapes.push({
        name: 'A-burger-no-addons',
        lines: [{
          item_id: burger.id, name: burger.name, quantity: 1,
          convert_price: burger.convert_price,
          item_variation_total: 0, item_extra_total: 0,
          item_variations: [], item_extras: [],
          total: burger.convert_price, instruction: '',
        }],
      });
      shapes.push({
        name: 'B-burger-with-menu_full-fake-addon',
        lines: [{
          item_id: burger.id, name: burger.name, quantity: 1,
          convert_price: burger.convert_price,
          item_variation_total: 3.0, item_extra_total: 0,
          item_variations: [],
          item_extras: [],
          item_addons: [{ id: 999, addon_item_id: 999, role: 'menu_full', name: 'Menu' }],
          total: burger.convert_price + 3.0, instruction: '',
        }],
      });
    }
    if (bowl) {
      shapes.push({
        name: 'C-bowl-with-fake-variations',
        lines: [{
          item_id: bowl.id, name: bowl.name, quantity: 1,
          convert_price: bowl.convert_price,
          item_variation_total: 0, item_extra_total: 0,
          item_variations: [{ id: 999, variation_name: 'Sauce', name: 'Algérienne' }],
          item_extras: [], total: bowl.convert_price, instruction: '',
        }],
      });
    }
    if (tacos) {
      shapes.push({
        name: 'D-tacos-no-addons',
        lines: [{
          item_id: tacos.id, name: tacos.name, quantity: 1,
          convert_price: tacos.convert_price,
          item_variation_total: 0, item_extra_total: 0,
          item_variations: [], item_extras: [],
          total: tacos.convert_price, instruction: '',
        }],
      });
    }
    if (burger && supplement) {
      shapes.push({
        name: 'E-multi-cart-burger+supplement',
        lines: [
          { item_id: burger.id, name: burger.name, quantity: 2, convert_price: burger.convert_price, item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [], total: burger.convert_price * 2, instruction: '' },
          { item_id: supplement.id, name: supplement.name, quantity: 1, convert_price: supplement.convert_price, item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [], total: supplement.convert_price, instruction: '' },
        ],
      });
    }
    if (sandwich) {
      shapes.push({
        name: 'F-sandwich-no-addons',
        lines: [{
          item_id: sandwich.id, name: sandwich.name, quantity: 1,
          convert_price: sandwich.convert_price,
          item_variation_total: 0, item_extra_total: 0,
          item_variations: [], item_extras: [],
          total: sandwich.convert_price, instruction: '',
        }],
      });
    }
    if (chicken) {
      shapes.push({
        name: 'G-chicken-no-addons',
        lines: [{
          item_id: chicken.id, name: chicken.name, quantity: 1,
          convert_price: chicken.convert_price,
          item_variation_total: 0, item_extra_total: 0,
          item_variations: [], item_extras: [],
          total: chicken.convert_price, instruction: '',
        }],
      });
    }

    console.log('[BUG-R2] Will test', shapes.length, 'shapes');
    const results = [];

    for (const shape of shapes) {
      // Reset cart
      await page.evaluate(() => {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        store?.dispatch('kioskCart/reset');
        store?.dispatch('kioskCart/setOrderType', 10);
      });

      // Inject lines
      await page.evaluate((linesToInject) => {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const store = vm && vm.config?.globalProperties?.$store;
        linesToInject.forEach((line) => store.dispatch('kioskCart/addItem', line));
      }, shape.lines);

      // Go to cart
      await page.evaluate(() => {
        const root = document.getElementById('app');
        const vm = root && root.__vue_app__;
        const router = vm && vm.config?.globalProperties?.$router;
        router?.push({ name: 'kiosk.cart' });
      });
      await page.waitForTimeout(1500);

      const quoteCountBefore = quoteResponses.length;
      const valider = page.locator('[data-testid="kiosk-cart-checkout"]').first();
      let urlAfter = null;
      let inlineErr = null;
      if (await valider.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await valider.click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
        urlAfter = page.url();
        inlineErr = await page.locator('[data-testid="kiosk-cart-quote-error"]').first().textContent({ timeout: 500 }).catch(() => null);
      }
      const newQuotes = quoteResponses.slice(quoteCountBefore);
      const newStatus = newQuotes.map((r) => r.status).join(',');
      results.push({ shape: shape.name, urlAfter, inlineErr, quoteStatuses: newStatus, newQuotes });
      console.log(`[BUG-R2] ${shape.name} → status=[${newStatus}] url=${urlAfter} inlineErr=${inlineErr}`);
      if (newQuotes.length > 0) {
        newQuotes.forEach((q, i) => {
          console.log(`  Quote ${i}: ${q.status} body=${(q.responseBody || '').substring(0, 300)}`);
        });
      }

      // Navigate back to cart if we left
      if (urlAfter && !/\/kiosk\/cart/.test(urlAfter)) {
        await page.evaluate(() => {
          const root = document.getElementById('app');
          const vm = root && root.__vue_app__;
          const router = vm && vm.config?.globalProperties?.$router;
          router?.push({ name: 'kiosk.cart' });
        });
        await page.waitForTimeout(800);
      }
    }

    await snap('BUG-06-shapes-complete');

    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'BUG-R2-quote-responses.json'),
      JSON.stringify(quoteResponses, null, 2),
    );
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'BUG-R2-results.json'),
      JSON.stringify({ menu: menu.slice(0, 30), results }, null, 2),
    );

    console.log('\n========= BUG R2 FINAL SUMMARY =========');
    console.log('Total quote requests:', quoteResponses.length);
    results.forEach((r) => {
      const ok = r.quoteStatuses.includes('200');
      const flag = ok ? 'OK ' : 'FAIL';
      console.log(`${flag} ${r.shape} status=[${r.quoteStatuses}] inlineErr=${r.inlineErr}`);
    });
    console.log('============================\n');
  });
});
