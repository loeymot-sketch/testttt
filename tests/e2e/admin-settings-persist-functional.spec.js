/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — real persistence proof,
 * single-form Settings pages (companion to admin-settings-crud-functional
 * which covers list-based Currency/Tax). Same technique already proven live
 * on Mail (change value -> save -> reload -> value persisted): applied here
 * to Social Media and Loyalty setup. Each test mutates a real field, saves,
 * reloads to prove persistence (not a stale client-side echo), then
 * restores the original value so the page is left exactly as found.
 *
 * (Site settings was attempted and dropped: its form mixes plain inputs with
 * custom multiselect components for required fields, which need dedicated
 * interaction handling beyond this generic technique — not a defect, just
 * out of scope for this pass.)
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

function tinkerExec(php) {
  execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 15_000,
  });
}

test.describe.serial('Real persistence proof — Social Media, Loyalty setup (single-form Settings)', () => {
  test.setTimeout(180_000);
  let page;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (page) await page.context().close().catch(() => {});
  });

  test('Social Media: facebook URL edit -> save -> reload -> persisted -> restored', async () => {
    // social_media_facebook is validated as ['nullable', 'url'] (SocialMediaRequest.php:27) —
    // the mutated value must itself be a valid URL, not an arbitrary appended marker.
    const url = '/admin/settings/social-media';
    const fieldId = 'social_media_facebook';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `https://facebook.com/e2e-test-${Date.now()}`;
    await field.fill(mutated);
    await field.press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Social Media: field mutated, saved, reload confirms persistence.');

    await page.locator(`#${fieldId}`).fill(original);
    await page.locator(`#${fieldId}`).press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Social Media: restored to original value.');
  });

  test('Loyalty setup: points-per-euro edit -> save -> reload -> persisted -> restored', async () => {
    const url = '/admin/settings/loyalty-setup';
    const fieldId = 'loyalty_points_per_euro';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = String((parseFloat(original) || 0) + 1);
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Loyalty setup: ${original} -> ${mutated}, reload confirms persistence.`);

    await page.locator(`#${fieldId}`).fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Loyalty setup: restored to original value.');
  });

  test('Company: website edit -> save -> reload -> persisted -> restored', async () => {
    // company_website (id="website") is a plain nullable text field, unlike
    // company_name (id="name") which writes to .env -- deliberately mutating
    // the non-.env field here to avoid touching that sensitive write path.
    const url = '/admin/settings/company';
    const fieldId = 'website';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `https://lecayenne-e2e-test-${Date.now()}.example.com`;
    await field.fill(mutated);
    await field.press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Company: website field mutated, saved, reload confirms persistence.');

    await page.locator(`#${fieldId}`).fill(original);
    await page.locator(`#${fieldId}`).press('Tab');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Company: restored to original value.');
  });

  test('Cookies: summary edit -> save -> reload -> persisted -> restored', async () => {
    // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] REAL FINDING, not a
    // test artifact: cookies_details_page_id (a required vue-select
    // referencing the Pages/CMS table) was found LIVE to be unset in this
    // environment's actual settings data -- the whole Cookies form 422s on
    // ANY save attempt while it's empty, with only a small red asterisk
    // hinting why. This settings page may never have been successfully
    // saved by a real admin. Setup/teardown below populates and then
    // restores it via a direct DB write (not the UI -- the required
    // vue-select can't be reliably cleared back to empty once set, so
    // driving it through the UI would leave a permanent side effect), so
    // the round-trip proves cookies_summary itself actually persists once
    // the blocking field is satisfied, without altering production state.
    const pageId = 1; // verified live: at least 1 CMS Page exists in this DB.
    tinkerExec(`\\Smartisan\\Settings\\Facades\\Settings::group('cookies')->set(['cookies_details_page_id' => ${pageId}]);`);

    try {
      const url = '/admin/settings/cookies';
      const fieldId = 'cookies_summary';
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle').catch(() => {});
      const field = page.locator(`#${fieldId}`);
      await expect(field).toBeVisible({ timeout: 10_000 });

      const original = await field.inputValue();
      console.log(`[CRUD-FUNCTIONAL] Cookies: original summary value was ${JSON.stringify(original)}.`);
      const mutated = `E2E test cookie banner text ${Date.now()}`;
      await field.fill(mutated);
      await page.click('button[type="submit"]');
      await page.waitForTimeout(1500);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle').catch(() => {});
      await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
      console.log('[CRUD-FUNCTIONAL] Cookies: summary mutated, saved, reload confirms persistence.');
      // No UI-driven restore here: cookies_summary is ALSO validated
      // `required|string` (CookiesRequest.php), so re-saving it back to the
      // true original empty string would be correctly rejected by the same
      // validation this test just proved works -- restoring to that exact
      // pre-existing (already-invalid-by-current-rules) production state can
      // only be done at the DB level, same as cookies_details_page_id below.
    } finally {
      // Always run, pass or fail: leave production settings exactly as found.
      tinkerExec(`\\Smartisan\\Settings\\Facades\\Settings::group('cookies')->set(['cookies_summary' => '', 'cookies_details_page_id' => null]);`);
    }
  });

  test('Order setup: food preparation time edit -> save -> reload -> persisted -> restored', async () => {
    // order_setup_food_preparation_time is one of the CONFIRMED-WIRED fields
    // on this page per the wave-3 adversarial audit (WaitEstimateService
    // actually reads it) -- deliberately NOT touching the sibling delivery-
    // charge fields on this same form, which that same audit found are
    // dead/cosmetic (never read anywhere) and are documented as an open
    // owner-decision item, not something to silently validate as if fine.
    const url = '/admin/settings/order-setup';
    const fieldId = 'order_setup_food_preparation_time';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = String((parseFloat(original) || 30) + 1);
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Order setup: food prep time ${original} -> ${mutated}, reload confirms persistence.`);

    await page.locator(`#${fieldId}`).fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Order setup: restored to original value.');
  });

  test('Otp: digit-limit vue-select edit -> save -> reload -> persisted -> restored', async () => {
    // otp_digit_limit is a vue3-select-component (not a plain <select>), same
    // proven pattern as the Employee role_id field: label[for=X] -> parent
    // group -> .vue-select-header (click to open) -> li.vue-dropdown-item
    // (click to pick). Enum is a closed 3-value set (4/6/8) per
    // resources/js/enums/modules/otpDigitLimitEnum.js -- live prod value
    // verified via tinker as "4" before this test, so toggling to "6" and
    // back stays inside the valid enum the whole time.
    const url = '/admin/settings/otp';
    const fieldId = 'otp_digit_limit';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // The searchable vue-select renders its selected value as the
    // PLACEHOLDER attribute of an inner <input>, not as visible text content
    // -- confirmed via a failed run's accessibility snapshot (combobox >
    // textbox with /placeholder: "6"). innerText()/toContainText() on the
    // header see empty string; must read/assert the placeholder attribute.
    async function selectedValue(scope) {
      return scope.locator('.vue-select-header input').getAttribute('placeholder');
    }

    const group = page.locator(`label[for="${fieldId}"]`).locator('xpath=..');
    await expect(group.locator('.vue-select')).toBeVisible({ timeout: 10_000 });

    const original = (await selectedValue(group)) || '4';
    const target = original === '6' ? '4' : '6';

    await group.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await group.locator('li.vue-dropdown-item[role="option"]', { hasText: target }).first().click();
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    let reloadedGroup = page.locator(`label[for="${fieldId}"]`).locator('xpath=..');
    await expect(reloadedGroup.locator('.vue-select-header input')).toHaveAttribute('placeholder', target, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Otp: digit limit ${original} -> ${target}, reload confirms persistence.`);

    await reloadedGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await reloadedGroup.locator('li.vue-dropdown-item[role="option"]', { hasText: original }).first().click();
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    reloadedGroup = page.locator(`label[for="${fieldId}"]`).locator('xpath=..');
    await expect(reloadedGroup.locator('.vue-select-header input')).toHaveAttribute('placeholder', original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Otp: restored to original value.');
  });

  test('Notification Alert: mail toggle for "Order Pending Message" -> save -> reload -> persisted -> restored', async () => {
    // NotificationAlert (id=1, "Order Pending Message") is a real seeded
    // production row -- its mail/sms/push_notification columns gate whether
    // a real customer email/SMS/push actually fires for that event
    // (switchEnum ON=5/OFF=10). This is the same risk class as Printers/
    // PaymentTerminals (real operational config), so the DB value is read
    // via tinker BEFORE touching the UI and hard-restored via tinker in a
    // finally block -- never left to a UI round-trip that could silently
    // fail partway and leave a real customer channel toggled.
    const before = execFileSync(
      'php',
      ['artisan', 'tinker', '--execute=echo \\App\\Models\\NotificationAlert::find(1)->mail;'],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    const originalOn = before === '5';

    try {
      await page.goto('/admin/settings/notification-alert', { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle').catch(() => {});
      const checkbox = page.locator('#mail1');
      await expect(checkbox).toBeAttached({ timeout: 10_000 });
      await checkbox.click();
      await page.click('#formElem_mail0 button[type="submit"]');
      await page.waitForTimeout(1500);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle').catch(() => {});
      const afterToggle = execFileSync(
        'php',
        ['artisan', 'tinker', '--execute=echo \\App\\Models\\NotificationAlert::find(1)->mail;'],
        { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
      ).trim();
      expect(afterToggle).not.toBe(before);
      console.log(`[CRUD-FUNCTIONAL] Notification Alert: mail column ${before} -> ${afterToggle} in DB, reload confirms persistence.`);
    } finally {
      tinkerExec(`\\App\\Models\\NotificationAlert::where('id', 1)->update(['mail' => ${originalOn ? 5 : 10}]);`);
      const restored = execFileSync(
        'php',
        ['artisan', 'tinker', '--execute=echo \\App\\Models\\NotificationAlert::find(1)->mail;'],
        { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
      ).trim();
      expect(restored).toBe(before);
      console.log('[CRUD-FUNCTIONAL] Notification Alert: DB-verified restore to original value.');
    }
  });
});
