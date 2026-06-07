// HEAL-WEBRECEIPT — on-screen receipt per-LINE tax suppression (NF525 coherence).
//
// Context: HEAL-H7 netted the on-screen receipt's per-RATE TVA *summary* +
// header to equal the signed Z (post-discount base). But the per-LINE tax
// (item.tax_currency_amount) is GROSS (pre-discount). On a discounted order the
// per-line taxes summed ABOVE the netted summary (0,64 + 0,27 = 0,91 vs summary
// 0,73) — a screen-internal contradiction. The physical ESC/POS ticket
// (PosReceiptEscPosRenderer) prints NO per-line tax, only the netted per-rate
// summary. HEAL-WEBRECEIPT suppresses the per-line tax block on the on-screen
// receipt components (PosOrderReceiptComponent + ReceiptComponent) to mirror the
// paper: line = description + qty + price; the legally required per-RATE
// ventilation (order.tax_lines, netted == Z) stays in the totals block.
//
// /admin/pos-orders/show/{id} renders <PosOrderReceiptComponent> in a #print
// modal. (ReceiptComponent.vue — the live POS post-payment modal — is NOT
// exercised by this route; it receives the identical edit + Vitest coverage and
// is verified by build, not by this e2e.)
//
// Run on the DISPOSABLE clone only: PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766
// (DB foodking_e2e). #4225 = discounted (subtotal 10,00 / discount 2,00 / total
// 8,00, VAT-10) → the ONLY discriminating case (its per-line tax 0,64/0,27
// differs from its summary 0,73). #4160 = non-discount control (1,50 TTC) — its
// per-line tax 0,14 equals its summary 0,14, so string assertions cannot prove
// suppression there; it only confirms summary + line price still render.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'heal-webreceipt-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });
test.describe.configure({ mode: 'serial', timeout: 120_000 });

async function readPrintTicket(page, id, tag) {
  await loginAsAdmin(page);
  await page.goto(`/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
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
  console.log(`[WEBRECEIPT #${id} ${tag}] print-ticket="${text}"`);
  await Promise.race([
    page.screenshot({ path: path.join(OUT, `ticket-${id}-${tag}.png`), fullPage: true }),
    new Promise((r) => setTimeout(r, 7000)),
  ]).catch(() => {});
  await page.emulateMedia({ media: 'screen' });
  return text;
}

test('discounted #4225: per-LINE tax suppressed, netted summary + line prices intact', async ({ page }) => {
  const text = await readPrintTicket(page, 4225, 'discounted');

  // Per-LINE gross tax amounts MUST be GONE (the contradiction values).
  // Sandwich Cayenne line tax = 0,64 ; Sprite 33cl line tax = 0,27.
  expect(text, 'per-line gross tax 0,64 (Sandwich) must be suppressed').not.toContain('0,64');
  expect(text, 'per-line gross tax 0,27 (Sprite) must be suppressed').not.toContain('0,27');

  // The netted per-rate ventilation summary (H7) MUST remain == signed Z.
  expect(text, 'netted per-rate TVA 0,73 must remain').toContain('0,73');
  expect(text, 'netted base HT 7,27 must remain').toContain('7,27');

  // Line PRICES MUST remain (description + qty + price kept, unchanged).
  expect(text, 'Sandwich line price 6,36 must remain').toContain('6,36');
  expect(text, 'Sprite line price 2,73 must remain').toContain('2,73');

  // Discount + total still render.
  expect(text, 'Remise 2,00 must remain').toContain('2,00');
  expect(text, 'Total 8,00 must remain').toContain('8,00');

  // No "VAT (10.00 %)" per-line tax label should appear in the items table.
  // NOTE (format coupling): this negative assertion is safe ONLY because the
  // KEPT netted summary renders "VAT (10%)" (no ".00"). If anyone later
  // reformats the summary label to "10.00%", this would false-fail — that's a
  // format change, not a suppression regression. The per-line label this guards
  // against is the "VAT (10.00 %)" string that the removed per-line block emitted.
  expect(text, 'per-line "VAT (10.00 %)" label must be suppressed').not.toContain('VAT (10.00 %)');
});

test('non-discount control #4160: summary + line price still render', async ({ page }) => {
  const text = await readPrintTicket(page, 4160, 'control');

  // Per-line tax 0,14 == summary 0,14 on a non-discount order, so the value
  // cannot distinguish removed-from-present. We only confirm the summary + the
  // line price still render (no regression to the non-discount layout).
  expect(text, 'control summary TVA 0,14 must render').toContain('0,14');
  expect(text, 'control line/subtotal HT 1,36 must render').toContain('1,36');
  expect(text, 'control total 1,50 must render').toContain('1,50');
  // The per-line "VAT (10.00 %)" label is suppressed on the control too.
  expect(text, 'per-line "VAT (10.00 %)" label suppressed on control').not.toContain('VAT (10.00 %)');
});
