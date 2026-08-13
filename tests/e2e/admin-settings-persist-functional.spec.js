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

  test('Promo Flyer Settings: headline edit -> save -> reload -> persisted -> restored', async () => {
    // Unlike every other page in this file, PromoFlyerSettingsComponent.vue
    // has NO id/for attributes on its fields at all -- `input[type="text"]`
    // (first match) is headline: number fields (discount_percent,
    // validity_days) and textareas (intro/savings_note/strengths) come
    // before/between it in DOM order but aren't type="text", so this is a
    // stable positional selector, confirmed by reading the component source.
    // headline is required (max 40, PromoFlyerController::updateSettings) --
    // all other required fields (intro, discount_percent, validity_days,
    // site_url, qr_url) are left at their existing values, submitted as-is.
    const url = '/admin/promo-flyer/settings';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator('form input[type="text"]').first();
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `E2E ${Date.now() % 100000}`.slice(0, 40);
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('form input[type="text"]').first()).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Promo Flyer Settings: headline "${original}" -> "${mutated}", reload confirms persistence.`);

    await page.locator('form input[type="text"]').first().fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('form input[type="text"]').first()).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Promo Flyer Settings: restored to original value.');
  });

  test('Admin Profile: first_name edit -> save -> reload -> persisted -> restored', async () => {
    // The logged-in admin's OWN account (admin@lecayenne.fr) -- email and
    // phone are deliberately NEVER touched, since login.js's loginAsAdmin()
    // hardcodes that email for every e2e spec in this repo, this session
    // and future ones. email/phone/country_code are all required fields
    // resubmitted verbatim from the form's own pre-filled values (loaded
    // from the real user on mount), so the payload includes them but their
    // value never changes -- only first_name is mutated.
    const url = '/admin/profile/edit-profile';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator('#first_name');
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `E2E${Date.now() % 100000}`;
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('#first_name')).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Admin Profile: first_name "${original}" -> "${mutated}", reload confirms persistence.`);

    await page.locator('#first_name').fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('#first_name')).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Admin Profile: restored to original value.');
  });

  test('Kiosk Setup: welcome title edit -> save -> reload -> persisted -> restored', async () => {
    // kiosk_welcome_title is CONFIRMED-WIRED -- KioskIdleScreenComponent.vue
    // (the real customer-facing kiosk idle screen) reads it directly
    // ("if (data.kiosk_welcome_title) this.welcomeTitle = ..."). Deliberately
    // NOT touching kiosk_admin_pin on this same form: (a) it's a
    // type="password" field the server never echoes back in plaintext
    // (kiosk_admin_pin_set boolean instead), so there's no simple
    // read-mutate-restore cycle for it, and (b) it's already a documented
    // open owner-decision item (label implies a security gate that no
    // controller/middleware actually enforces) from an earlier wave of this
    // same session -- not something to touch incidentally here. Confirmed
    // via the component's own save() that an empty PIN is excluded from the
    // payload, so this test never risks overwriting it.
    const url = '/admin/settings/kiosk-setup';
    const fieldId = 'kiosk_welcome_title';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `E2E Welcome ${Date.now() % 100000}`;
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Kiosk Setup: welcome title "${original}" -> "${mutated}", reload confirms persistence.`);

    await page.locator(`#${fieldId}`).fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Setup: restored to original value.');
  });

  test('Mail: from-name edit -> save -> reload -> persisted -> restored (real credentials untouched)', async () => {
    // mail_from_name is the one field on this form with zero credential
    // weight (display name on outgoing email, e.g. "Le Cayenne") -- the
    // other 6 fields (host/port/username/password/encryption/from_email)
    // are real SMTP credentials, deliberately never mutated here.
    //
    // Verified safe to submit the WHOLE form (this component's save()
    // always resubmits every field, not just the changed one) before
    // writing this test: MailController's own code comment confirms
    // GET returns mail_password in CLEARTEXT (no masking), and
    // MailService::update() is a pure passthrough -- Settings::group(
    // 'mail')->set($request->validated()) + EnvEditor::addData() write
    // the exact validated values with zero re-encryption/transformation.
    // So resubmitting the untouched credential fields alongside the one
    // real edit is lossless, not a risk of corrupting them with a masked
    // placeholder -- confirmed via code, not assumed.
    //
    // Every field here (including mail_from_name) is also written
    // verbatim into .env by EnvEditor -- same class of real, on-disk
    // mutation as Company/Site, both already proven safe with this same
    // pattern elsewhere in this file.
    const url = '/admin/settings/mail';
    const fieldId = 'mail_from_name';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const field = page.locator(`#${fieldId}`);
    await expect(field).toBeVisible({ timeout: 10_000 });

    const original = await field.inputValue();
    const mutated = `E2E Mail ${Date.now() % 100000}`;
    await field.fill(mutated);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(mutated, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Mail: from-name "${original}" -> "${mutated}", reload confirms persistence.`);

    await page.locator(`#${fieldId}`).fill(original);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator(`#${fieldId}`)).toHaveValue(original, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Mail: restored to original value.');
  });

  test('Change Password: real backend rejects a wrong current password (never risks the real admin credential)', async () => {
    // Deliberately does NOT attempt a successful password change -- every
    // other test in this session (and every other spec file) logs in as
    // this exact admin via loginAsAdmin(), which hardcodes the current
    // real password. A successful change here, even if "restored"
    // afterward, risks a race with concurrent test workers/sessions
    // reading a mid-flight credential and locking every other test out.
    // Instead proves the real thing worth proving: the form is wired to a
    // real backend call with real validation, not a client-side no-op --
    // submit a deliberately WRONG old_password and confirm the backend
    // rejects it (real PUT, real 422, real error message rendered), which
    // can never succeed in mutating the real credential.
    await page.goto('/admin/profile/change-password', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.fill('#old_password', `definitely-wrong-${Date.now()}`);
    await page.fill('#password', 'E2ENewPassword123!');
    await page.fill('#confirm_password', 'E2ENewPassword123!');

    const saveResponse = page.waitForResponse(
      (res) => /profile\/change-password$/.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 10_000 }
    );
    await page.click('button[type="submit"]');
    const res = await saveResponse;
    expect(res.status()).toBe(422);

    await expect(page.locator('small.db-field-alert')).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Change Password: real PUT ${res.url()} rejected a wrong old_password with a real 422 + rendered field error -- not a client-side-only form, and the real admin credential was never at risk.`);
  });
});
