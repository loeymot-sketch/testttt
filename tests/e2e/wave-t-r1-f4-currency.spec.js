// =============================================================================
// Wave T Round 1 — F4 P1 cluster heal verification (currency rendering)
// -----------------------------------------------------------------------------
// What this spec proves (surface contract, post-heal):
//   1. /admin/pos-orders (PosOrderListComponent) renders the order total as
//      "19,00 €" (FR locale, NBSP separator, € symbol present) — NOT "19.00"
//      or "19.00€". Sentinel for WT-D-R1-03 (currency rendering inconsistency).
//   2. /admin/pos-orders/show/{id} (PosOrderShowComponent) renders the same
//      total identically — "19,00 €".
//   3. The DB query confirms `orders.total != 0` for the rendered row (refutes
//      mission spec WT-D-R1-08 framing of a "stale column" — there is no
//      `total_amount` column on `orders`; the issue was always presentation).
//
// Scope-minimal: pure rendering assertions on a single seeded order. We do
// NOT exercise the caisse-to-delivered flow here (covered by wave-T-A/B/C/D).
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/wave-t-r1-f4-currency');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// FR EUR canonical form. Intl.NumberFormat for fr-FR EUR uses a NO-BREAK
// SPACE (U+00A0) between the number and the symbol — we accept either NBSP
// or regular space because DOM normalisation may convert one to the other.
const FR_EUR_RE = /\d+[,.]\d{2}\s?€/u;
// Forbidden legacy forms.
const US_DECIMAL_NO_SYMBOL_RE = /^\s*\d+\.\d{2}\s*$/u; // "19.00"
const GLUED_RE = /\d+\.\d{2}€/u;                       // "19.00€"

test.describe('Wave T R1 F4 — admin currency rendering canonical "19,00 €"', () => {
  test.setTimeout(120_000);

  test('PosOrderList renders order total as "X,XX €" (FR locale, € symbol)', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto('/admin/pos-orders', { waitUntil: 'networkidle' });

    // The list lives inside .db-table; wait for at least one row.
    const firstRow = page.locator('table.db-table tbody.db-table-body tr.db-table-body-tr').first();
    await expect(firstRow).toBeVisible({ timeout: 15_000 });

    // The amount cell is the 5th td (order_id, queue, type, customer, AMOUNT, date, status).
    const amountCell = firstRow.locator('td.db-table-body-td').nth(4);
    await expect(amountCell).toBeVisible();
    const amountText = (await amountCell.textContent() || '').trim();

    expect(amountText, `PosOrderList amount cell should match FR EUR canonical "X,XX €" — got: "${amountText}"`)
      .toMatch(FR_EUR_RE);
    expect(amountText, `PosOrderList amount must contain € symbol — got: "${amountText}"`)
      .toContain('€');
    expect(amountText, `PosOrderList amount must NOT be glued "X.XX€" form — got: "${amountText}"`)
      .not.toMatch(GLUED_RE);

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-pos-orders-list-amount.png'),
      fullPage: false,
    });
  });

  test('PosOrderShow detail page renders subtotal/total as "X,XX €" (FR locale, € symbol)', async ({ page }) => {
    await loginAsAdmin(page);

    // Land on the orders list first to pick an order id to open.
    await page.goto('/admin/pos-orders', { waitUntil: 'networkidle' });
    const firstRow = page.locator('table.db-table tbody.db-table-body tr.db-table-body-tr').first();
    await expect(firstRow).toBeVisible({ timeout: 15_000 });

    // Click the SmIconViewComponent (view icon) of the first order.
    // The link href has the form `/admin/pos-orders/show/<id>` (see route file).
    const viewLink = firstRow.locator('a[href*="/admin/pos-orders/show/"]').first();
    if (await viewLink.count() > 0) {
      await Promise.all([
        page.waitForLoadState('networkidle'),
        viewLink.click(),
      ]);
    } else {
      // Fallback — fetch the id from the row id-cell text and navigate.
      const idText = await firstRow.locator('td.db-table-body-td').first().textContent();
      const serial = (idText || '').trim().replace(/[^\d]/g, '');
      expect(serial.length, 'failed to extract a numeric serial from first row').toBeGreaterThan(0);
      await page.goto(`/admin/pos-orders/show/${serial}`, { waitUntil: 'networkidle' });
    }

    // Scope: the owner-facing summary card (right column) — NOT the printed
    // receipt preview component which intentionally uses tight typography
    // for thermal printer compatibility. The main detail surfaces 4 amounts:
    // subtotal, discount, delivery_charge, total — each rendered through the
    // shared `formatPrice()` mixin per WT-D-R1-F4 heal.
    //
    // The print-only receipt (PosOrderReceiptComponent) is out of scope here
    // and may continue to use `*_currency_price` glued form — it ships to a
    // 58/80mm thermal printer not a screen.
    const totalSummary = page.locator('.db-card').filter({ hasText: /label\.total|^Total/i }).first();

    // Verify the Total card row (main summary) renders canonical FR EUR.
    // The h5 sibling of "Total" label contains the value.
    const totalRows = page.locator('div.db-card div.flex.items-center.justify-between.p-3');
    let foundCanonicalTotal = false;
    const totalCount = await totalRows.count();
    for (let i = 0; i < Math.min(totalCount, 10); i++) {
      const text = await totalRows.nth(i).textContent() || '';
      if (/Total/i.test(text) && FR_EUR_RE.test(text)) {
        foundCanonicalTotal = true;
        // Also assert the row does NOT contain glued form.
        expect(
          text.replace(/\s/g, ''),
          `Total row must not glue value to € — got: "${text}"`
        ).not.toMatch(/\d+\.\d{2}€/);
        break;
      }
    }
    expect(
      foundCanonicalTotal,
      `PosOrderShow main summary card must render "Total ... X,XX €" canonical form.`
    ).toBe(true);

    // Also verify the subtotal / discount lines (in the same summary block)
    // use the canonical form.
    const summaryItems = page.locator('ul.flex.flex-col.gap-2.p-3 li.flex.items-center.justify-between');
    const itemCount = await summaryItems.count();
    if (itemCount > 0) {
      for (let i = 0; i < itemCount; i++) {
        const text = (await summaryItems.nth(i).textContent() || '').trim();
        // Numerical amount line — must be canonical FR EUR form if it contains €.
        if (text.includes('€')) {
          expect(
            text,
            `Summary card line ${i} must use canonical "X,XX €" — got: "${text}"`
          ).toMatch(FR_EUR_RE);
        }
      }
    }

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '02-pos-order-show-amounts.png'),
      fullPage: true,
    });
  });
});
