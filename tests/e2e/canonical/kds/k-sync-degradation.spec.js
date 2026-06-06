// =============================================================================
// CANONICAL E2E — KDS sync-degradation safety-net (Wave-1 audit heals)
// =============================================================================
// Proves the three KDS real-time-sync degradation defects are HEALED so the
// kitchen is NEVER silently blind. Anti-theater: concrete DOM/text assertions,
// no bare expect(true).
//
// Defects (source: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
//   • KDS-03 (P2) — admin account (branch_id<=0) on KDS gets ZERO live push
//       (subscribeEcho early-returns on the branch-scoped Echo channel) AND the
//       fallback banner stays hidden because the WS transport reports
//       'connected'. FIX = surface the "Mode admin centralisé …" / SYNC · ADMIN
//       polling hint for ANY admin account (even single-branch Le Cayenne, where
//       kdsIsCentralAdmin is suppressed by the branchCount>1 gate WT-B-R1-007).
//   • KDS-01 (P3) — adaptive-cadence poller read a non-existent wsService.state
//       (+ wrong { from, to } event field) → the WS-aware cadence ladder was
//       dead code. FIX = read getState() + normalize vocab + accept { current }.
//   • KDS-02 (P2) — fallback poller permanently HALTED on any non-5xx HTTP
//       error (401/403/404/429). FIX = reschedule on all handled error classes.
//
// COVERAGE SPLIT (anti-theater honesty):
//   - KDS-03 is user-visible (a banner) → asserted here in the browser.
//   - KDS-01 + KDS-02 are internal timer/cadence/transport logic with NO direct
//     DOM surface and require deterministic fake timers → proven at SOURCE level
//     by Vitest (kdsSyncRealTransportCadence.spec.js / kdsSyncRescheduleOnNon5xx
//     .spec.js). This spec adds a browser-level GUARD that the KDS boots clean
//     (no console error from the cadence ladder / poller) so a rebuilt bundle
//     wiring the fixes is at least smoke-validated end-to-end.
//
// HARNESS FACTS (worktree pre-cloud-exec):
//   - LIVE server = http://127.0.0.1:8765 (PLAYWRIGHT_BASE_URL). reuseExistingServer.
//   - loginAsAdmin → admin@lecayenne.fr / 123456 → branch_id=0 (the KDS-03 case).
//   - KDS route = /admin/kitchen-display-system (V2 grid is default).
//   - The admin-no-push banner is the consolidated <KdsStatusBanner> rendering
//     ".kds-banner.kds-banner--info" with text label.kds_admin_polling_hint and
//     tag "SYNC · ADMIN". Legacy (?v2=0) renders a [data-testid] info banner.
//   - CHAIN-SAFETY: abort any POST to /api/admin/* so NO order/Z ever advances.
//
// ⚠️ ORCHESTRATOR — RUN ONLY AFTER THE JS BUNDLE IS REBUILT. The fixes live in
//   source (resources/js/services/KdsSyncService.js + the KDS Vue component);
//   the app serves a precompiled Mix bundle (public/js/admin-kds.js). Running
//   this against a stale bundle gives a misleading red — the kdsBundleFreshness
//   sentinel (Vitest) is deliberately RED until the rebuild.
//
// ⚠️ KDS-03 manifests ONLY when WS is UP. With WS down, fallbackMode wins
//   (higher banner priority → "SYNC · LOCAL") and would green this test for the
//   WRONG reason. forceWsUp() below stubs isConnected()→true so we assert the
//   SYNC · ADMIN (admin-no-push) path specifically, not the fallback path.
// =============================================================================

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../../helpers/login');

const KDS_URL_V2 = '/admin/kitchen-display-system?v2=1';
const KDS_URL_LEGACY = '/admin/kitchen-display-system?v2=0';

const ADMIN_POLLING_FR = 'Mode admin centralisé';
const ADMIN_POLLING_EN = 'Central admin mode';
const SYNC_ADMIN_TAG = 'SYNC · ADMIN';

test.use({ viewport: { width: 1440, height: 900 } });
test.setTimeout(120_000);

/**
 * Force the WS transport to report CONNECTED before the KDS bundle boots, so
 * wsConnected resolves true deterministically. This isolates the KDS-03
 * admin-no-push path (the bug that exists WHILE the socket is healthy) from the
 * unrelated WS-down fallback path.
 */
async function forceWsUp(page) {
  await page.addInitScript(() => {
    const forceConnected = (svc) => {
      if (svc && typeof svc === 'object') {
        try { svc.isConnected = () => true; } catch (_e) { /* frozen — ignore */ }
        try { svc.getState = () => 'connected'; } catch (_e) { /* ignore */ }
      }
      return svc;
    };
    let _ws = window._wsService;
    forceConnected(_ws);
    try {
      Object.defineProperty(window, '_wsService', {
        configurable: true,
        get() { return _ws; },
        set(v) { _ws = forceConnected(v); },
      });
    } catch (_e) { /* property may already be locked — best effort */ }
  });
}

/** Block every real mutating POST so the NF525 chain never advances. */
async function installChainSafety(page) {
  await page.route('**/*', (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /\/api\/admin\//.test(req.url())) {
      return route.abort();
    }
    return route.continue();
  });
}

test.describe('KDS sync-degradation — kitchen is never silently blind', () => {
  // ---------------------------------------------------------------------------
  // KDS-03 — admin account on KDS (no live push) MUST see the polling hint.
  // ---------------------------------------------------------------------------
  test('KDS-03 V2: admin account + WS UP → "SYNC · ADMIN" polling hint VISIBLE', async ({ page }) => {
    await forceWsUp(page);
    await installChainSafety(page);
    await loginAsAdmin(page); // branch_id = 0 → subscribeEcho early-returns → no push
    await page.goto(KDS_URL_V2, { waitUntil: 'domcontentloaded' });

    // Prove we are on the V2 layout (grid OR its empty-state mounts).
    await expect(
      page.locator('.kds-v2__grid, .kds-v2__empty').first()
    ).toBeVisible({ timeout: 20_000 });

    // The admin gets ZERO live push but the socket is up (no fallback banner).
    // Pre-fix on single-branch Le Cayenne the kitchen saw NOTHING. The heal
    // surfaces the info banner with the SYNC · ADMIN tag.
    const adminBanner = page.locator('.kds-banner.kds-banner--info', {
      hasText: new RegExp(`${ADMIN_POLLING_FR}|${ADMIN_POLLING_EN}`),
    });
    await expect(
      adminBanner,
      'An admin account on KDS gets no branch Echo push — it MUST see the polling hint so the kitchen knows it is on a passive feed (silent degradation forbidden, owner mandate).'
    ).toBeVisible({ timeout: 15_000 });

    // Anti-theater: it must specifically be the ADMIN tag (NOT the WS-down LOCAL
    // fallback, which would mean we proved the wrong path).
    await expect(adminBanner.locator('.kds-banner__tag')).toHaveText(SYNC_ADMIN_TAG);
  });

  test('KDS-03 legacy (?v2=0): admin account + WS UP → admin polling banner VISIBLE', async ({ page }) => {
    await forceWsUp(page);
    await installChainSafety(page);
    await loginAsAdmin(page);
    await page.goto(KDS_URL_LEGACY, { waitUntil: 'domcontentloaded' });

    // The legacy template renders a plain .kds-hint-banner--info with the same
    // label.kds_admin_polling_hint copy. v-if was "kdsIsCentralAdmin" (gated on
    // branchCount>1 → hidden on Le Cayenne); the heal ORs in kdsAdminNoPush.
    const legacyAdminBanner = page.locator('.kds-hint-banner.kds-hint-banner--info', {
      hasText: new RegExp(`${ADMIN_POLLING_FR}|${ADMIN_POLLING_EN}`),
    });
    await expect(
      legacyAdminBanner,
      'Legacy KDS for an admin account must also surface the polling hint (kdsAdminNoPush OR-wire).'
    ).toBeVisible({ timeout: 15_000 });
  });

  // ---------------------------------------------------------------------------
  // KDS-01 + KDS-02 — internal cadence/poller logic (Vitest is the SoT). Here we
  // only smoke-guard that the rebuilt bundle boots the KDS without throwing from
  // the cadence ladder / fallback poller (e.g. reading undefined .state, or an
  // unhandled rejection from the halt path).
  // ---------------------------------------------------------------------------
  test('KDS-01/02 smoke: KDS boots clean (no cadence/poller console error)', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('pageerror', (err) => consoleErrors.push(String(err)));

    await forceWsUp(page);
    await installChainSafety(page);
    await loginAsAdmin(page);
    await page.goto(KDS_URL_V2, { waitUntil: 'domcontentloaded' });

    await expect(
      page.locator('.kds-v2__grid, .kds-v2__empty').first()
    ).toBeVisible({ timeout: 20_000 });

    // Let one cadence recompute + at least one drift/poll tick happen.
    await page.waitForTimeout(3000);

    // The cadence ladder / poller must not surface a runtime error. We scope to
    // the KdsSyncService surface to avoid unrelated 3rd-party noise.
    const syncErrors = consoleErrors.filter((t) =>
      /KdsSync|kds-order\/sync|currentIntervalMs|getState|cadence/i.test(t)
    );
    expect(
      syncErrors,
      `KDS sync layer must boot clean; saw: ${syncErrors.join(' | ')}`
    ).toEqual([]);
  });
});
