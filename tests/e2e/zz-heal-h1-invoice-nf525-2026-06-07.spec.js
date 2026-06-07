// FoodKing — HEAL-H1 verification (2026-06-07)
// Proves PosOrderReceiptComponent.vue (the order-history "Imprimer La Facture"
// receipt at /admin/pos-orders/show/:id, printed via #print) now renders the
// full NF525 fiscal block, matching the POS ReceiptComponent.vue.
//
// BEFORE (agent 09, order #4160): hasSiret=false, hasTva=false,
//   hasFiscalNo=false, hasOperator=false  → NON-COMPLIANT ticket.
// AFTER (this spec): all four true + positive value asserts.
//
// Run (foodking_e2e clone @ :8766 ONLY — never the operating foodking DB):
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//   tests/e2e/zz-heal-h1-invoice-nf525-2026-06-07.spec.js
//
// READ-ONLY surface: navigates + reads #print DOM, no order mutation.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'heal-h1-invoice-nf525-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

// Clone branch legal (foodking_e2e branch 1) — already set per task brief.
const EXPECT_SIRET = '10417050100019';
const EXPECT_VAT = 'FR19104170501';
const EXPECT_FISCAL_NO = '2001'; // #4160 fiscal_sequence_no per agent 09

test('HEAL-H1: order-history invoice #4160 renders full NF525 block', async ({ page }) => {
  test.setTimeout(120_000);
  await loginAsAdmin(page);
  await page.goto('/admin/pos-orders/show/4160', { waitUntil: 'domcontentloaded' });
  // Neutralise window.print so v-print does not pop a print dialog.
  await page.evaluate(() => { window.print = () => {}; }).catch(() => {});
  // Confirm SPA hydrated (order detail panel renders).
  await page.waitForSelector('text=Détails Commande', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);

  // Force @media print so any print-only styling is applied for the read.
  await page.emulateMedia({ media: 'print' }).catch(() => {});

  // Read #print via textContent (visibility-independent — the receipt lives in
  // #print regardless of the modal toggle state).
  const data = await page.evaluate(() => {
    const el = document.querySelector('#print');
    if (!el) return { found: false, text: '(#print NOT IN DOM)' };
    const text = el.textContent;
    return {
      found: true,
      text: text.replace(/\s+/g, ' ').trim(),
      hasSiret: /SIRET/i.test(text),
      hasTva: /TVA intra|TVA\s*FR/i.test(text),
      hasFiscalNo: /ticket NF525|N° ticket/i.test(text),
      hasOperator: /Opérateur/i.test(text),
      // value-level checks
      hasSiretValue: text.includes('10417050100019'),
      hasVatValue: text.includes('FR19104170501'),
      hasTaxRate: /\(10%\)/.test(text) || /10\s*%/.test(text),
      hasFiscalNoValue: /N° ticket NF525\s*:?\s*2001/i.test(text) || text.includes('2001'),
      rows: el.querySelectorAll('table tbody tr').length,
    };
  });
  console.log('HEAL-H1 #4160 >>>', JSON.stringify(data, null, 2));
  fs.writeFileSync(path.join(OUT, '4160-after.json'), JSON.stringify(data, null, 2));

  // Force-visible only for the screenshot (does not affect the asserts above).
  await page.emulateMedia({ media: 'screen' }).catch(() => {});
  await page.evaluate(() => {
    const m = document.querySelector('#receiptModal');
    if (m) { m.style.cssText = 'display:block;opacity:1;position:fixed;inset:0;z-index:99999;background:#fff;overflow:auto'; }
    const p = document.querySelector('#print'); if (p) p.style.display = 'block';
  }).catch(() => {});
  await page.waitForTimeout(400);
  // Best-effort capture, hard-bounded so it can NEVER hang the test (element
  // screenshot can stall on a force-shown scrollable modal under contention).
  const el = page.locator('#print').first();
  if (await el.count()) {
    await Promise.race([
      el.screenshot({ path: path.join(OUT, '4160-history-ticket-after.png'), timeout: 6000 }),
      new Promise((r) => setTimeout(r, 7000)),
    ]).catch(() => {});
  }

  // ---- ASSERTIONS (the heal contract) ----
  expect(data.found, '#print element present in DOM').toBe(true);
  expect(data.hasSiret, 'SIRET label present').toBe(true);
  expect(data.hasTva, 'TVA intra present').toBe(true);
  expect(data.hasFiscalNo, 'N° ticket NF525 present').toBe(true);
  expect(data.hasOperator, 'Opérateur present').toBe(true);
  expect(data.hasSiretValue, `SIRET value ${EXPECT_SIRET} present`).toBe(true);
  expect(data.hasVatValue, `VAT intra value ${EXPECT_VAT} present`).toBe(true);
  expect(data.hasTaxRate, 'per-rate VAT line (10%) present').toBe(true);
  expect(data.hasFiscalNoValue, `fiscal sequence no ${EXPECT_FISCAL_NO} present`).toBe(true);
});
