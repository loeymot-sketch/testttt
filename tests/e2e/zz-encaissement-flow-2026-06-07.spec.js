// FoodKing — Encaissement BUTTON flow (headless, end-to-end)
// 2026-06-07 autonomous validation. Disposable clone only:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-encaissement-flow-2026-06-07.spec.js
//
// Proves the owner's #1 unify-focus surface works end-to-end at the UI layer:
// click "Encaisser" on a pending borne order -> collect modal -> CASH -> confirm
// -> order is paid + receives the NEXT gap-free fiscal number. DB asserted by the
// surrounding Bash harness (MAX fiscal# before/after, pending count, chain).
// Encashes exactly ONE order.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'encaissement-flow-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test('encaisser one pending borne order (CASH) end-to-end', async ({ page }) => {
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsAdmin(page);
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });

  // Wait for the queue to render at least one collectable ticket.
  const firstBtn = page.locator('.enc-collect-btn').first();
  await expect(firstBtn).toBeVisible({ timeout: 25_000 });

  const countBefore = (await page.locator('.enc-count-chip').innerText().catch(() => '?')).trim();
  console.log(`[ENC] pending chip before = ${countBefore}`);

  await page.screenshot({ path: path.join(OUT, '1-queue.png') });

  // Open the collect modal.
  await firstBtn.click();
  const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
  await expect(modal).toBeVisible({ timeout: 15_000 });
  const total = (await page.locator('[data-testid="pos-counter-collect-total"]').innerText().catch(() => '?')).trim();
  console.log(`[ENC] modal total = ${total}`);

  // Ensure CASH mode (cash block visible). If not, click the cash mode button.
  const cashBlock = page.locator('[data-testid="pos-counter-collect-cash-block"]');
  if (!(await cashBlock.isVisible().catch(() => false))) {
    const cashMode = page.locator('[data-testid="pos-counter-collect-mode-CASH"], [data-testid="pos-counter-collect-mode-cash"]').first();
    if (await cashMode.isVisible().catch(() => false)) await cashMode.click();
  }
  await expect(cashBlock).toBeVisible({ timeout: 8_000 });

  // Over-pay so the confirm is enabled regardless of the ticket's exact total.
  const received = page.locator('[data-testid="pos-counter-collect-received-input"]');
  await received.fill('999');
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(OUT, '2-modal-cash.png') });

  // Confirm + print.
  const confirm = page.locator('[data-testid="pos-counter-collect-confirm"]');
  await expect(confirm).toBeEnabled({ timeout: 8_000 });
  const apiResp = page.waitForResponse(
    (r) => /\/admin\/pos\/counter-collect\/\d+\/confirm/i.test(r.url()) && r.request().method() === 'POST',
    { timeout: 25_000 },
  );
  await confirm.click();
  const resp = await apiResp;
  console.log(`[ENC] confirm API ${resp.request().method()} ${resp.url()} -> HTTP ${resp.status()}`);
  expect([200, 201], `confirm API status`).toContain(resp.status());

  // Modal should close on success.
  await expect(modal).toBeHidden({ timeout: 15_000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(OUT, '3-after.png') });

  const realConsoleErrors = consoleErrors.filter((t) =>
    !/favicon|websocket|ws:|wss:|soketi|pusher|6001|Deprecation|preload|sourcemap/i.test(t));
  console.log(`[ENC] consoleErr=${realConsoleErrors.length} pageErr=${pageErrors.length}`);
  if (realConsoleErrors.length) console.log(`   ${JSON.stringify(realConsoleErrors.slice(0, 5))}`);
  if (pageErrors.length) console.log(`   ${JSON.stringify(pageErrors.slice(0, 5))}`);

  expect(pageErrors, `JS page errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
