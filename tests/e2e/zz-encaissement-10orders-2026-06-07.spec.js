// FoodKing — 10 consecutive encaissements (headless, flow-level gap-free proof)
// 2026-06-07. Disposable clone only:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-encaissement-10orders-2026-06-07.spec.js
//
// Owner requirement: "10 commandes / zéro numéro manquant" at the UI-flow level.
// Encashes 10 pending borne orders one-by-one through the real collect modal and
// logs each (orderId, fiscalSeq-after via API echo not available -> verified by Bash).
// The surrounding Bash harness asserts the 10 allocated fiscal numbers are
// perfectly consecutive (gap-free, no duplicates) and the chain stays OK.

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const N = 10;

test.describe.configure({ mode: 'serial', timeout: 300_000 });

test(`encaisser ${N} pending orders sequentially (CASH)`, async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsAdmin(page);

  const orderIds = [];
  for (let i = 0; i < N; i++) {
    await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
    const firstBtn = page.locator('.enc-collect-btn').first();
    await expect(firstBtn, `ticket #${i + 1} visible`).toBeVisible({ timeout: 25_000 });
    await firstBtn.click();

    const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
    await expect(modal).toBeVisible({ timeout: 15_000 });

    const cashBlock = page.locator('[data-testid="pos-counter-collect-cash-block"]');
    if (!(await cashBlock.isVisible().catch(() => false))) {
      const cashMode = page.locator('[data-testid="pos-counter-collect-mode-CASH"], [data-testid="pos-counter-collect-mode-cash"]').first();
      if (await cashMode.isVisible().catch(() => false)) await cashMode.click();
    }
    await expect(cashBlock).toBeVisible({ timeout: 8_000 });

    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill('999');
    await page.waitForTimeout(200);

    const confirm = page.locator('[data-testid="pos-counter-collect-confirm"]');
    await expect(confirm).toBeEnabled({ timeout: 8_000 });
    const apiResp = page.waitForResponse(
      (r) => /\/admin\/pos\/counter-collect\/\d+\/confirm/i.test(r.url()) && r.request().method() === 'POST',
      { timeout: 25_000 },
    );
    await confirm.click();
    const resp = await apiResp;
    const m = resp.url().match(/counter-collect\/(\d+)\/confirm/);
    const orderId = m ? m[1] : '?';
    orderIds.push(orderId);
    console.log(`[ENC10] ${i + 1}/${N} order=${orderId} status=${resp.status()}`);
    expect([200, 201], `confirm #${i + 1}`).toContain(resp.status());

    await expect(modal).toBeHidden({ timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(600);
  }

  console.log(`[ENC10] DONE order_ids=${orderIds.join(',')}`);
  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
  expect(orderIds.filter((x) => x !== '?')).toHaveLength(N);
});
