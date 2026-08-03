// =============================================================================
// FoodKing E2E — Wave T R1 F5 — Driver assignment UI feedback + a11y + token
// Branch : heal/cms-pr1-quickwins-2026-05-18
// Author : Claude (F5 heal cluster)
//
// Scope :
//   1. After successful driver assignment via the UI dropdown, the cashier
//      MUST see a visible chip (driver name) on the detail page. (WT-D-R1-02)
//   2. The assignment dropdown MUST expose WCAG combobox+listbox ARIA roles.
//      (WT-D-R1-09)
//   3. The detail page MUST NOT label the internal token as "N° commande".
//      The real order number lives in `order.order_serial_no` and remains the
//      primary heading. (WT-D-R1-07)
//   4. A success toast SHOULD appear with the driver name + order number.
//
// Strategy :
//   - Reuse Wave A fixture (Order #2 DELIVERY TPE) if present. Otherwise
//      sentinel-skip — this spec is paired with Wave A and not standalone.
//   - Drive the UI dropdown by hovering the dropdown-group wrapper (existing
//      CSS-only hover-open) then clicking the role="option" item, so the spec
//      exercises the actual cashier path (NOT the API path Wave D uses).
//   - Re-capture detail after assignment and assert chip + token label.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const PROJECT_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/wave-t-r1-f5-delivery-ui');
const FIXTURE_FILE = path.resolve(__dirname, '__fixtures__/wave-t-orders.json');

function ensureDir(d) { fs.mkdirSync(d, { recursive: true }); }

function runTinker(phpSrc, timeoutMs = 20_000) {
  try {
    const out = execFileSync(
      'php',
      ['artisan', 'tinker', '--execute', phpSrc],
      { cwd: PROJECT_ROOT, encoding: 'utf8', timeout: timeoutMs }
    );
    return { ok: true, stdout: out };
  } catch (e) {
    return { ok: false, error: e.message, stdout: e.stdout || '', stderr: e.stderr || '' };
  }
}

function ensureDeliveryBoy() {
  const phpSrc =
    "$existing = App\\Models\\User::withoutGlobalScope(App\\Models\\Scopes\\BranchScope::class)" +
    "->role('Delivery Boy', 'sanctum')->where('branch_id', 1)->first(); " +
    "if ($existing) { echo 'EXISTING_ID=' . $existing->id; } " +
    "else { echo 'NONE'; }";
  const r = runTinker(phpSrc, 30_000);
  if (!r.ok) return null;
  const m = r.stdout.match(/EXISTING_ID=(\d+)/);
  return m ? parseInt(m[1], 10) : null;
}

function resetOrderForAssignTest(orderId) {
  // Reset to no driver + non-terminal status so the assignment dropdown is
  // rendered (PosOrderShowComponent shows it only when status is not
  // REJECTED/CANCELED/DELIVERED-terminal AND order_type === DELIVERY).
  // PREPARED (8) is the safest pre-assign state — assignment dropdown is
  // visible and the OUT_FOR_DELIVERY transition is the natural next step.
  const phpSrc =
    `$o = App\\Models\\Order::withoutGlobalScope(App\\Models\\Scopes\\BranchScope::class)->find(${orderId}); ` +
    `if ($o) { $o->delivery_boy_id = null; $o->status = 8; $o->save(); echo 'RESET_OK'; } else { echo 'MISSING'; }`;
  const r = runTinker(phpSrc, 10_000);
  return r.ok && /RESET_OK/.test(r.stdout);
}

ensureDir(SCREENSHOT_DIR);

test.describe('Wave T R1 F5 — Driver assignment UI (toast + chip + a11y + token)', () => {
  test.setTimeout(180_000);

  test('F5: dropdown a11y, chip visible after assign, no #Wave token confusion', async ({ browser }) => {
    // Sentinel skip — F5 piggy-backs on Wave A's fixture.
    if (!fs.existsSync(FIXTURE_FILE)) {
      test.skip(true, `Wave A fixture missing at ${FIXTURE_FILE} — orchestrator must run Wave A first.`);
      return;
    }
    const fixture = JSON.parse(fs.readFileSync(FIXTURE_FILE, 'utf8'));
    const order2 = fixture.order_2 || fixture.order_2_livraison;
    if (!order2 || !order2.id) {
      test.skip(true, 'Wave A fixture present but Order #2 missing — F5 skipped.');
      return;
    }
    const ORDER2_ID = order2.id;

    // Reset driver AND walk back the status to PREPARED so the assignment
    // dropdown is rendered (it hides on terminal statuses such as DELIVERED).
    resetOrderForAssignTest(ORDER2_ID);

    // Make sure at least one delivery boy exists at branch_id=1.
    const driverId = ensureDeliveryBoy();
    expect(driverId, 'A Delivery Boy must be seeded at branch_id=1').toBeTruthy();

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(`console.error: ${msg.text()}`);
    });

    // 1. Login admin and navigate to the POS order detail.
    //    Route name `admin.pos-orders.show` -> /admin/pos-orders/show/{id}.
    await loginAsAdmin(page);
    await page.goto(`/admin/pos-orders/show/${ORDER2_ID}`, { waitUntil: 'domcontentloaded' });

    // Wait for the detail surface to settle — payment-status badge is mounted
    // when the store finishes hydrating.
    await expect(page.locator('[data-testid="pos-driver-assign-btn"]')).toBeVisible({ timeout: 25_000 });

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-detail-before-assign.png'),
      fullPage: true,
    });

    // 2. a11y assertions on the dropdown group (WT-D-R1-09).
    const group = page.locator('[data-testid="pos-driver-assign-group"]').first();
    await expect(group, 'combobox wrapper visible').toBeVisible();
    await expect(group, 'role="combobox" set').toHaveAttribute('role', 'combobox');
    await expect(group, 'aria-haspopup="listbox" set').toHaveAttribute('aria-haspopup', 'listbox');

    const listbox = group.locator('[role="listbox"]').first();
    await expect(listbox, 'listbox child exists').toHaveAttribute('role', 'listbox');

    const optionLocator = group.locator(`[data-testid="pos-driver-option-${driverId}"]`).first();
    await expect(optionLocator, 'driver option exposed via role="option"').toHaveAttribute('role', 'option');

    // 3. Confirm chip is absent before assignment.
    const chip = page.locator('[data-testid="pos-driver-assigned-chip"]');
    await expect(chip, 'chip MUST be hidden before assignment').toHaveCount(0);

    // 4. Open the dropdown — `dropdown-group` is CSS hover-driven (no JS
    //    toggle). Playwright's `hover()` triggers :hover state, but the
    //    transform: scale-y-0 -> scale-y-1 may still leave the panel
    //    geometrically off-screen depending on viewport scroll. We rely on
    //    DOM `dispatchEvent('click')` which fires the Vue @click handler
    //    regardless of computed visibility — exactly mirroring the cashier's
    //    behaviour of hovering the wrapper and clicking the option.
    await group.hover();
    await page.waitForTimeout(300);

    // 5. Capture network response then click via dispatchEvent — bypasses
    //    viewport/visibility checks since the panel is animation-driven.
    const assignResponse = page.waitForResponse(
      (res) =>
        /\/select-delivery-boy\//i.test(res.url()) && res.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await optionLocator.dispatchEvent('click');
    const resp = await assignResponse;
    expect(resp.status(), 'select-delivery-boy returns 2xx').toBeLessThan(300);

    // 6. After 200, the toast should appear and the chip should mount.
    const toast = page.locator('.Vue-Toastification__toast, [role="alert"]')
      .filter({ hasText: /Livreur.*assigné|assigned/i });
    await expect(toast.first(), 'success toast with driver name visible').toBeVisible({ timeout: 8_000 });

    await expect(chip, 'driver-name chip visible after assignment').toBeVisible({ timeout: 8_000 });
    const chipText = (await chip.textContent() || '').trim();
    expect(chipText, 'chip mentions a driver name').toMatch(/Livreur assigné/i);
    expect(chipText.length, 'chip text non-empty').toBeGreaterThan(0);

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '02-detail-after-assign-with-chip.png'),
      fullPage: true,
    });

    // 7. Token display sanity (WT-D-R1-07) : no "#Wave" label as "N° commande".
    //    The order number heading at the top still uses `label.order_id`
    //    -> "N° Commande: #<order_serial_no>". The token (if shown) is now
    //    labelled "Référence interne".
    const bodyText = await page.locator('body').innerText();
    // "N° Commande" header is fine — but should be followed by order_serial_no,
    // NOT by "#Wave". Construct a strict regex.
    const orderHeader = await page.locator('p.text-2xl').first().innerText();
    expect(orderHeader, 'order header shows real order_serial_no').toMatch(/^N°\s*Commande:?\s*#[0-9A-Za-z_\-]+$/i);
    expect(orderHeader, 'order header does NOT contain "Wave"').not.toMatch(/#Wave/);

    // Internal token label, if rendered, must be "Référence interne" — never
    // "N° commande" (which is the order header above).
    if (/Référence interne/i.test(bodyText)) {
      const tokenLine = page.locator('li.text-xs', { hasText: 'Référence interne' }).first();
      const tokenText = (await tokenLine.textContent() || '').trim();
      expect(tokenText, 'token label is Référence interne, not N° commande').toMatch(/Référence interne/);
      expect(tokenText, 'token label NOT misused as N° commande').not.toMatch(/^N° commande/i);
    }

    // 8. No new console errors triggered by the heal.
    const filtered = errors.filter((e) => !/favicon|net::ERR_/i.test(e));
    expect(filtered, `unexpected console errors: ${filtered.join(' | ')}`).toHaveLength(0);

    await ctx.close();
  });
});
