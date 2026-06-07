// AGENT CLUSTER-PAY — receipt payment-mode label check (4).
// Renders the POS-order receipt for the CARD/TICKET/MOBILE orders encashed by
// the driver spec and extracts the rendered ticket text so the payment-mode
// label can be inspected (CARD must show "Carte", NEVER "Espèces").
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'cluster-pay-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 120_000 });

// id -> expected payment label, tag
const CASES = [
  { id: 4213, tag: 'card',   expect: 'Carte',             forbid: 'Espèces' },
  { id: 4212, tag: 'ticket', expect: 'Ticket Restaurant', forbid: 'Espèces' },
  { id: 4211, tag: 'mobile', expect: 'MFS',               forbid: 'Espèces' },
];

for (const c of CASES) {
  test(`receipt label #${c.id} (${c.tag})`, async ({ page }) => {
    await loginAsAdmin(page);
    await page.evaluate(() => { window.print = () => {}; }).catch(() => {});
    await page.goto(`/admin/pos-orders/show/${c.id}`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { window.print = () => {}; });
    await page.waitForTimeout(3500);

    // Open the receipt/ticket if behind a trigger
    const triggers = [
      'button:has-text("Ticket client")', 'button:has-text("Imprimer La Facture")',
      'button:has-text("Facture")', 'button:has-text("Imprimer")', 'button:has-text("Ticket")',
      '[data-testid*="receipt"]',
    ];
    for (const sel of triggers) {
      const el = page.locator(sel).first();
      if (await el.isVisible().catch(() => false)) { await el.click().catch(() => {}); await page.waitForTimeout(1500); break; }
    }

    const txt = await page.evaluate(() => {
      const el = document.querySelector('#print') || document.querySelector('#receiptModal') || document.body;
      return el ? el.innerText : '';
    });
    console.log(`[CP-RECEIPT ${c.id} ${c.tag}] >>>\n${txt}\n<<<`);

    await page.emulateMedia({ media: 'print' }).catch(() => {});
    await page.waitForTimeout(400);
    const print = page.locator('#print, #receiptModal').first();
    if (await print.count()) {
      await Promise.race([
        print.screenshot({ path: path.join(OUT, `receipt-${c.id}-${c.tag}.png`) }),
        new Promise((r) => setTimeout(r, 7000)),
      ]).catch(() => {});
    }
    await page.emulateMedia({ media: 'screen' }).catch(() => {});

    // Assertions: the order's own payment label is present, "Espèces" is NOT.
    expect(txt, `[${c.tag}] expected label "${c.expect}" on ticket`).toContain(c.expect);
    expect(txt, `[${c.tag}] ticket must NOT show "Espèces" for a non-cash order`).not.toContain(c.forbid);
  });
}
