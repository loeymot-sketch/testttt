// ADVERSARIAL R2 vague A — close A-RED-3 R1: vérifie X-RateLimit-Limit live (config drift?)
import { boot, login, gotoPos, addItem } from './_d2-a-lib.mjs';
const { browser, page } = await boot();
let hdr = null;
page.on('response', (r) => { if (r.url().includes('/api/admin/pos/quote')) hdr = { status: r.status(), limit: r.headers()['x-ratelimit-limit'], remaining: r.headers()['x-ratelimit-remaining'] }; });
try {
  await login(page); await gotoPos(page);
  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  await page.fill('[data-testid="pos-discount-input"]', '5').catch(() => {});
  await page.fill('[data-testid="pos-discount-reason"]', 'rate hdr probe').catch(() => {});
  await page.locator('[data-testid="pos-discount-apply"]').click().catch(() => {});
  await page.waitForTimeout(1800);
  console.log('QUOTE RATE HEADER:', JSON.stringify(hdr));
} finally { await browser.close(); }
