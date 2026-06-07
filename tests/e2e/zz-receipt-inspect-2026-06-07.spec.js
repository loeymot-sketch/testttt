// FoodKing — RECEIPT/TICKET content inspection (headless capture)
// 2026-06-07. Clone (branch legal now set):
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-receipt-inspect-2026-06-07.spec.js
//
// Opens the POS-order detail + receipt for #4160 (POS) and #4161 (kiosk) and
// captures the rendered ticket so its NF525 fields can be visually inspected.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'receipt-inspect-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 120_000 });

for (const id of [4160, 4161]) {
  test(`receipt for #${id}`, async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { window.print = () => {}; }); // neutralize blocking print dialog
    await page.waitForTimeout(3500);
    await page.screenshot({ path: path.join(OUT, `${id}-show.png`), fullPage: true });

    // Try to open a receipt/ticket modal if it isn't inline.
    const triggers = [
      'button:has-text("Imprimer La Facture")', 'button:has-text("Facture")',
      'button:has-text("Imprimer")', 'button:has-text("Reçu")', 'button:has-text("Ticket")',
      '[data-testid*="receipt"]',
    ];
    for (const sel of triggers) {
      const el = page.locator(sel).first();
      if (await el.isVisible().catch(() => false)) { await el.click().catch(() => {}); await page.waitForTimeout(1500); break; }
    }
    // The #print receipt content is in the DOM (hidden except @media print).
    // Read its text directly (visibility-independent) + render it for a screenshot.
    const txt = await page.evaluate(() => {
      const el = document.querySelector('#print') || document.querySelector('#receiptModal .modal-dialog');
      return el ? el.innerText : null;
    });
    console.log(`[RECEIPT ${id}] ticket text >>>\n${txt || '(#print not found in DOM)'}\n<<<`);

    await page.emulateMedia({ media: 'print' }).catch(() => {});
    await page.waitForTimeout(500);
    const print = page.locator('#print, #receiptModal .modal-dialog').first();
    if (await print.count()) {
      await print.screenshot({ path: path.join(OUT, `${id}-ticket.png`) }).catch(async () => {
        await page.screenshot({ path: path.join(OUT, `${id}-ticket-page.png`), fullPage: true });
      });
    }
    await page.emulateMedia({ media: 'screen' }).catch(() => {});
  });
}
