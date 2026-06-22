// FoodKing E2E — PROOF: pre-Z (in-preparation) refund is FIXED.
//
// OWNER BUG (before fix): refunding a normal, not-yet-Z-closed, in-preparation
// paid order via the "Rembourser" CTA failed with a red error
//   "Order N is not in a CLOSED Z window — operation refund-with-counter-entry
//    requires the parent to be sealed…"
// because the single /refund-with-counter-entry endpoint always ran through
// SealedOrderGuard (post-Z mirror path) which REQUIRES a closed Z window.
//
// FIX ([WI-REFUND-PREZ 2026-06-04] PosOrderController::refundWithCounterEntry):
// server-side path-selection. A NOT-sealed (pre-Z, open Z window) parent now
// routes to refundPreZ() → OrderService::changeStatus(RETURNED) — HTTP 200
// with { success:true, mode:'pre_z' }, parent flips to status RETURNED, audit
// row appended, NO "CLOSED Z window" error.
//
// THE DECISIVE PROOF is the network response: 2xx + body.mode === 'pre_z'.
// That single fact proves all three things at once — (a) no red error,
// (b) the pre-Z branch was actually taken (not a hollow counter-entry green
// from an accidentally-sealed order), and (c) the refund succeeded. The
// RETURNED status badge + closed modal corroborate visually.
//
// NOTE: this performs a REAL refund on a dev-DB test order (status→RETURNED +
// audit). Intended live proof, dev data only. The target order is selected at
// runtime from the DB (PAID + in-prep + fiscal_sequence_no NOT NULL +
// parent_order_id NULL + NOT sealed) so the spec self-heals across runs.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

test.describe.configure({ timeout: 120_000 });

const repoRoot = path.resolve(__dirname, '../..');
const SHOT_DIR = path.join(__dirname, '__screenshots__', 'refund-confirm');
const Z_WINDOW_ERROR_RE = /CLOSED Z window|refund-with-counter-entry requires|not in a CLOSED Z/i;

/**
 * Pick a pre-Z, in-preparation, PAID, refundable order straight from the DB.
 * Criteria mirror the owner's scenario exactly:
 *   status IN (ACCEPT=4, PREPARING=7, PREPARED=8)  -> in-prep, not delivered
 *   payment_status = PAID (5)
 *   fiscal_sequence_no NOT NULL                    -> a real fiscalized sale
 *   parent_order_id NULL                           -> not itself a refund mirror
 *   SealedOrderGuard::isSealed() === false         -> genuinely pre-Z (open Z)
 * Prefer PREPARING (the canonical "En Préparation"), then PREPARED, then ACCEPT.
 */
function pickPreZInPrepOrder() {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', `
    $guard = app(\\App\\Services\\Order\\SealedOrderGuard::class);
    $rows = \\App\\Models\\Order::query()
        ->whereIn('status', [4, 7, 8])
        ->where('payment_status', 5)
        ->whereNotNull('fiscal_sequence_no')
        ->whereNull('parent_order_id')
        ->orderByRaw("FIELD(status, 7, 8, 4)")
        ->orderByDesc('id')
        ->limit(40)
        ->get(['id', 'status', 'order_serial_no']);
    foreach ($rows as $r) {
        $o = \\App\\Models\\Order::find($r->id);
        if (!$guard->isSealed($o)) {
            echo json_encode(['id' => (int) $r->id, 'status' => (int) $r->status, 'serial' => (string) $r->order_serial_no]);
            break;
        }
    }
  `], { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

  const jsonStart = out.indexOf('{');
  if (jsonStart === -1) {
    throw new Error('No pre-Z in-prep PAID order found in DB — cannot prove the fix. tinker output: ' + out.slice(0, 400));
  }
  return JSON.parse(out.slice(jsonStart, out.indexOf('}', jsonStart) + 1));
}

const STATUS_LABEL = { 4: 'Acceptée', 7: 'En Préparation', 8: 'Préparée' };

test.describe('Refund pre-Z in-prep order — owner CLOSED-Z bug FIXED', () => {
  test('PAID + En Préparation order refunds → RETURNED, no "CLOSED Z window" error', async ({ page }) => {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    clearFoodKingRateLimits();

    // ── 1. Pick the pre-Z in-prep target from the DB (self-healing across runs).
    const target = pickPreZInPrepOrder();
    console.log(`[refund-prez] target order id=${target.id} status=${target.status} (${STATUS_LABEL[target.status]}) serial=${target.serial}`);

    // ── 2. Login admin (holds pos-refund + branch_id=0).
    await loginAsAdmin(page);

    // ── 3. Go straight to the order detail ("Voir" target).
    await page.goto(`/admin/pos-orders/show/${target.id}`, { waitUntil: 'domcontentloaded' });

    // ── 4. The "Rembourser" CTA must be present (permission + refundable sanity).
    const refundOpen = page.locator('[data-testid="pos-order-refund-open"]');
    await expect(refundOpen, 'Rembourser CTA must be visible for a PAID non-mirror order').toBeVisible({ timeout: 25_000 });
    await refundOpen.click();

    // ── 5. Modal opens. Type a reason and screenshot BEFORE submit.
    const overlay = page.locator('[data-testid="pos-refund-modal-overlay"]');
    await expect(overlay).toBeVisible({ timeout: 10_000 });
    const reason = page.locator('[data-testid="pos-refund-modal-reason"]');
    await reason.fill('test remboursement commande en préparation');

    const confirm = page.locator('[data-testid="pos-refund-modal-confirm"]');
    // canConfirm needs reason >= 5 chars — wait until the button is enabled.
    await expect(confirm).toBeEnabled({ timeout: 10_000 });

    await page.screenshot({ path: path.join(SHOT_DIR, 'refund-01-modal.png'), fullPage: true });

    // ── 6. Submit and capture the DECISIVE network response.
    const refundResponsePromise = page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        /\/admin\/pos-order\/\d+\/refund-with-counter-entry/i.test(res.url()),
      { timeout: 30_000 },
    );
    await confirm.click();
    const refundResponse = await refundResponsePromise;

    const status = refundResponse.status();
    let body = {};
    try { body = await refundResponse.json(); } catch (_) { /* non-JSON body */ }
    const rawBody = JSON.stringify(body);
    console.log(`[refund-prez] response HTTP ${status} body=${rawBody.slice(0, 300)}`);

    // ── 6a. DUAL GUARD — the "CLOSED Z window" error must NOT appear anywhere.
    expect(rawBody, 'response body must NOT contain the CLOSED-Z-window error (that == fix FAILED)')
      .not.toMatch(Z_WINDOW_ERROR_RE);

    // ── 6b. DECISIVE PROOF: 2xx + mode === 'pre_z'. This proves the pre-Z
    //        branch was taken AND it succeeded (not a hollow counter-entry green).
    expect(status, `refund must succeed (2xx); got ${status} body=${rawBody}`).toBeGreaterThanOrEqual(200);
    expect(status, `refund must succeed (2xx); got ${status}`).toBeLessThan(300);
    expect(body.success, 'backend must report success:true').toBe(true);
    expect(body.mode, 'must take the PRE-Z refund branch (mode=pre_z), not counter_entry').toBe('pre_z');
    // Returned parent must now be status RETURNED (22).
    expect(Number(body?.data?.status), 'refunded parent status must be RETURNED (22)').toBe(22);

    // ── 6c. Visual error band must never have surfaced the red error.
    const errorBand = page.locator('[data-testid="pos-refund-modal-error"]');
    if (await errorBand.isVisible().catch(() => false)) {
      const errText = (await errorBand.textContent()) || '';
      expect(errText, 'on-screen error band must NOT show the CLOSED-Z-window error').not.toMatch(Z_WINDOW_ERROR_RE);
    }

    // ── 7. On success the modal closes and the parent refetches. Wait for the
    //        overlay to detach, then capture the persistent RETURNED badge.
    await expect(overlay).toBeHidden({ timeout: 15_000 });

    // The status badge renders "Retournée" (label.returned) / "Remboursé"
    // (status_22) for a RETURNED order. Match loosely — the network mode is
    // the source of truth; this is corroboration.
    const refundedBadge = page.locator('text=/rembours|retourn/i').first();
    await expect(refundedBadge, 'order detail must show a Remboursé/Retournée status after refund')
      .toBeVisible({ timeout: 15_000 });

    // Belt-and-braces: the CLOSED-Z error must not be anywhere on the page.
    await expect(page.locator('body')).not.toContainText(/CLOSED Z window/i);

    await page.screenshot({ path: path.join(SHOT_DIR, 'refund-02-success.png'), fullPage: true });

    console.log(`[refund-prez] PASS — order ${target.id} refunded pre-Z, status→RETURNED, no CLOSED-Z error.`);
  });
});
