// [ORDER-INTEGRITY-KDS-LOYALTY-20260914] Real browser regression for the
// kiosk registration dead-end. It uses only the dedicated E2E database and
// opens the actual kiosk application. The successful API contract is covered
// by the Laravel feature tests; its response is intercepted here because the
// public registration endpoint correctly rate-limits repeated local browsers.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// The test logs a real kiosk session in before exercising the browser flow,
// so its API request and page must share the configured Playwright target.
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const OUT = path.resolve(__dirname, '../../tests/captures/kiosk-loyalty-register-e2e');
fs.mkdirSync(OUT, { recursive: true });

function setInputValue(page, testId, value) {
  return page.locator(`[data-testid="${testId}"]`).evaluate((input, nextValue) => {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(input, nextValue);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

test('borne : inscription fidélité rend le solde après la réponse register, jamais une page blanche', async ({ page, request }) => {
  const report = {};
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', (error) => pageErrors.push(String(error.message || error)));
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  const suffix = String(Date.now()).slice(-7);
  const customer = {
    name: `E2E Loyalty ${suffix}`,
    phone: `069${suffix}`,
    email: `e2e-loyalty-${suffix}@example.test`,
  };

  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const loginData = await login.json();
  expect(loginData.token, 'le jeton de la borne doit être réel').toBeTruthy();

  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({
      kioskCart: {
        kioskToken: token,
        kioskMachineId: machineId,
        branchId,
        orderType: 10,
        // A cart line is sufficient for the route guard. No price is sent and
        // no checkout is attempted by this scenario.
        items: [{
          item_id: 103,
          name: 'Fixture fidélité',
          quantity: 1,
          convert_price: 0,
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: [],
          item_extras: [],
        }],
      },
    }));
  }, {
    token: loginData.token,
    machineId: loginData.kiosk?.machine_id,
    branchId: loginData.kiosk?.branch_id || 1,
  });

  await page.goto(`${BASE}/kiosk/loyalty`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.kiosk-loyalty-register-btn')).toBeVisible({ timeout: 12_000 });
  await page.locator('.kiosk-loyalty-register-btn').click();
  await expect(page.getByTestId('kiosk-loyalty-register-name')).toBeVisible();

  await setInputValue(page, 'kiosk-loyalty-register-name', customer.name);
  await setInputValue(page, 'kiosk-loyalty-register-phone', customer.phone);
  await setInputValue(page, 'kiosk-loyalty-register-email', customer.email);

  // This tests the actual browser component and its state transition while
  // retaining the server's real 5/min public anti-abuse protection. The
  // server controller contract is separately exercised against the real DB by
  // KioskRegisterKeepsEmailTest (new customer, existing phone, email clash).
  await page.route('**/api/frontend/loyalty/register', async (route) => {
    const body = route.request().postDataJSON();
    expect(body).toMatchObject(customer);
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        status: true,
        data: { name: customer.name, points: 0, loyalty_code: `E2E${suffix}` },
      }),
    });
  });
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').click();

  await expect(page.getByTestId('kiosk-consent-modal')).toBeVisible();
  await page.getByTestId('kiosk-consent-loyalty').check();
  await page.getByTestId('kiosk-consent-accept').click();

  const points = page.locator('.kiosk-loyalty-points-value');
  await expect(points).toBeVisible({ timeout: 12_000 });
  await expect(page.locator('.kiosk-loyalty-profile')).toContainText(customer.name);
  await expect(page.locator('.kiosk-loyalty-error')).toHaveCount(0);

  report.customer_name = customer.name;
  report.balance = await points.innerText();
  report.url = page.url();
  report.blank_page = await page.locator('.kiosk-loyalty-screen').count() === 0;
  report.page_errors = pageErrors;
  report.console_errors = consoleErrors;
  await page.screenshot({ path: path.join(OUT, '01-registration-balance.png'), fullPage: true });
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));

  expect(report.blank_page, 'la borne conserve l’écran fidélité après inscription').toBeFalsy();
  expect(pageErrors, 'aucune erreur de rendu ne doit vider la borne').toEqual([]);
  expect(consoleErrors.some((message) => /Invalid linked format/.test(message))).toBeFalsy();
});
