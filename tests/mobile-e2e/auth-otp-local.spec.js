// Local-only auth flow: real guest-signup OTP → returned dev_code → verify.
// Run with: npx playwright test --config=tests/mobile-e2e/playwright.config.js tests/mobile-e2e/auth-otp-local.spec.js
'use strict';

const { test, expect } = require('@playwright/test');

test('local OTP: cooldown blocks duplicates, expiry permits one resend, dev_code verifies', async ({ page }) => {
  test.setTimeout(75_000);
  const otpRequests = [];
  page.on('request', request => {
    if (request.method() === 'POST' && request.url().includes('/api/auth/guest-signup/otp')) otpRequests.push(request);
  });

  await page.goto('/index.html?dev', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage && !!window.LC.dev, { timeout: 15_000 });
  await page.evaluate(() => {
    window.LC.dev.clearAll();
    window.LC.storage.clearAuth();
    window.LC.storage.markOnboardingSeen();
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label="05 Login"]');

  const phone = '0699' + String(Date.now()).slice(-6);
  await page.locator('input[inputmode="tel"]').fill(phone);
  await page.getByRole('button', { name: /Recevoir le code/i }).click();
  await page.waitForSelector('[data-screen-label="06 OTP"]');
  await page.waitForSelector('[data-testid="otp-dev-code"]', { timeout: 15_000 });
  expect(otpRequests).toHaveLength(1);

  const resend = page.locator('[data-testid="otp-resend"]');
  await expect(resend).toBeDisabled();
  await page.evaluate(() => {
    const button = document.querySelector('[data-testid="otp-resend"]');
    button && (button.click(), button.click());
  });
  await page.waitForTimeout(250);
  expect(otpRequests).toHaveLength(1);

  await page.waitForTimeout(30_500);
  await expect(resend).toBeEnabled();
  const resendResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/api/auth/guest-signup/otp'));
  await resend.click();
  expect((await resendResponse).status()).toBe(200);
  await expect.poll(() => otpRequests.length, { timeout: 5_000 }).toBe(2);
  await expect(page.locator('[data-testid="otp-dev-code"]')).toBeVisible();

  const devCode = await page.locator('[data-testid="otp-dev-code"]').innerText();
  expect(devCode).toMatch(/\d{4}$/);
  await page.locator('[data-testid="otp-dev-code"]').click();
  await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible({ timeout: 15_000 });
});
