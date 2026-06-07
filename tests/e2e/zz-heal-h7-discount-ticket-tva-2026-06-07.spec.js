// HEAL-H7 / CP-1 — printed-ticket TVA discount netting (NF525).
// The /admin/pos-orders/show/{id} page embeds <PosOrderReceiptComponent>
// inside a #print modal (vue3-print-nb). The modal renders order.tax_lines
// (per-rate base_ht_currency + tax_currency) and header total_tax /
// subtotal_without_tax — all fed by OrderDetailsResource. Before HEAL-H7 a
// discounted order's per-rate line showed the GROSS pre-discount 0,91 / 9,09;
// after the fix it must show the netted, collected, Z-consistent 0,73 / 7,27.
//
// Run on the DISPOSABLE clone only: PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766
// (DB foodking_e2e). #4225 = discounted (subtotal 10,00 / discount 2,00 /
// total 8,00, VAT-10). #4160 = non-discounted control (1,50 TTC, VAT-10).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'heal-h7-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });
test.describe.configure({ mode: 'serial', timeout: 120_000 });

// Read the hidden #print modal's text after emulating print media (the
// printed-ticket DOM). Returns the full textContent for assertion + logging.
async function readPrintTicket(page, id, tag) {
  await loginAsAdmin(page);
  await page.goto(`/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
  // give the SPA time to fetch the order detail and render the receipt modal
  await page.waitForFunction(
    () => {
      const el = document.querySelector('#print');
      return el && /\d/.test(el.textContent || '');
    },
    { timeout: 30_000 },
  ).catch(() => {});
  await page.emulateMedia({ media: 'print' });
  await page.waitForTimeout(800);
  const text = await page.evaluate(() => {
    const el = document.querySelector('#print');
    return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '(#print not found)';
  });
  console.log(`[H7 #${id} ${tag}] print-ticket="${text.slice(0, 600)}"`);
  await Promise.race([
    page.screenshot({ path: path.join(OUT, `ticket-${id}-${tag}.png`), fullPage: true }),
    new Promise((r) => setTimeout(r, 7000)),
  ]).catch(() => {});
  await page.emulateMedia({ media: 'screen' });
  return text;
}

test('discounted #4225 printed ticket nets per-rate TVA to 0,73 / HT 7,27 (not 0,91 / 9,09)', async ({ page }) => {
  const text = await readPrintTicket(page, 4225, 'discounted');

  // The netted, collected, Z-consistent figures MUST appear.
  expect(text, 'per-rate netted TVA 0,73 must appear on the discounted ticket').toContain('0,73');
  expect(text, 'per-rate netted base HT 7,27 must appear on the discounted ticket').toContain('7,27');

  // The GROSS pre-discount figures MUST NOT appear (the CP-1 defect values).
  expect(text, 'gross pre-discount TVA 0,91 must NOT appear (CP-1 defect)').not.toContain('0,91');
  expect(text, 'gross pre-discount base HT 9,09 must NOT appear (CP-1 defect)').not.toContain('9,09');
  // And the spurious subtotal − netted-tax (9,27) must not leak either.
  expect(text, 'spurious 9,27 HT must NOT appear').not.toContain('9,27');
});

test('non-discounted control #4160 is unchanged (0,14 on 1,50)', async ({ page }) => {
  const text = await readPrintTicket(page, 4160, 'control');

  expect(text, 'control ticket per-rate TVA 0,14 must still appear').toContain('0,14');
  // ratio=1.0 → no netting drift; the gross == net so 0,14 is correct.
});
