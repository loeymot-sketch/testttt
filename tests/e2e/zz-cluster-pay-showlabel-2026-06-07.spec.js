// AGENT CLUSTER-PAY — check (4) via the pos-orders SHOW page inline label
// (PosOrderShowComponent renders "Type de paiement: {label}" inline, no modal).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'cluster-pay-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });
test.describe.configure({ mode: 'serial', timeout: 90_000 });

const CASES = [
  { id: 4213, tag: 'card' },
  { id: 4212, tag: 'ticket' },
  { id: 4211, tag: 'mobile' },
];

for (const c of CASES) {
  test(`show-page payment label #${c.id} (${c.tag})`, async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/admin/pos-orders/show/${c.id}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const txt = await page.evaluate(() => document.body.innerText);
    // Extract the "Type de paiement" line for clarity
    const line = (txt.split('\n').find((l) => /Type de paiement/i.test(l)) || '').trim();
    console.log(`[CP-SHOW ${c.id} ${c.tag}] payment-line="${line}"`);
    await Promise.race([
      page.screenshot({ path: path.join(OUT, `show-${c.id}-${c.tag}.png`), fullPage: false }),
      new Promise((r) => setTimeout(r, 7000)),
    ]).catch(() => {});
  });
}
