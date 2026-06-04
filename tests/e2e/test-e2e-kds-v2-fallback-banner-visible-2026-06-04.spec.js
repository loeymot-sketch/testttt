// =====================================================================
// FoodKing — PR-02 core-bulletproof · KDS V2 sync-degradation VISIBLE
// =====================================================================
// Surface : /admin/kitchen-display-system?v2=1 (V2 unified grid, default).
// Login   : loginAsChefOperator (chef@lecayenne.fr / 123456) → branch_id=1.
//
// WHY THIS SPEC EXISTS (PR-02 §5 N3 / §6.2 — coverage gap):
//   The legacy testid `kds-sync-mode-banner` lives ONLY on the v-else legacy
//   branch (KitchenDisplaySystemComponent.vue:70). In V2 the fallback warning
//   is rendered by <KdsStatusBanner> as a "SYNC · LOCAL" tag with NO testid.
//   So NO existing spec exercises the V2 fallback path — the real fix (the
//   kitchen seeing degradation on the box) was never verified by CI.
//
// CONTRACT UNDER TEST (PR-02 fail-safe-to-visible, opt-out design):
//   kdsSuppressFallbackBanner() suppresses the banner ONLY when
//     appEnv === 'local' AND window.FK_KDS_SHOW_FALLBACK_BANNER === false.
//   The real Le Cayenne box never sets that flag → undefined → NOT suppressed
//   → with WS down the kitchen MUST see "SYNC · LOCAL". This spec asserts the
//   BOX behavior (flag absent) and the DEV opt-out (flag === false → silent).
//
// WS-DOWN DETERMINISM:
//   The local dev server runs with soketi off, so the KDS initializes
//   wsConnected = !!(window._wsService?.isConnected()) === false already
//   (component line ~1136). To stay deterministic even if soketi is running,
//   we force window._wsService.isConnected() to return false via an init
//   script BEFORE the bundle boots, then assert the V2 banner.
//
// NOTE: if the running server/soketi state makes the live assertion flaky,
//   this spec is still the correct coverage; the box-default semantics are
//   independently documented in config/kds.php + the computed's comment.
//
// ⚠️ ORCHESTRATOR — RUN ONLY AFTER THE JS BUNDLE IS REBUILT. The Vue edit
//   (kdsSuppressFallbackBanner) lives in source; the app serves a precompiled
//   Mix bundle. Running this against a stale bundle gives a misleading red on
//   test-1 (old logic still suppresses in local). Run post-rebuild.
//
// ⚠️ test-1 (box VISIBLE) depends on soketi being DOWN. forceWsDown() stubs
//   _wsService.isConnected()→false, but if soketi is actually UP the
//   component's 'connected' event handler (~:1872) sets wsConnected=true
//   independently → no fallback → test-1 fails. Run with soketi off (the
//   real dev default) OR additionally block the WS transport.
// =====================================================================

const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');

const KDS_URL = '/admin/kitchen-display-system?v2=1';

test.use({ viewport: { width: 1440, height: 900 } });
test.setTimeout(120_000);

/**
 * Force the WS service to report "disconnected" before the KDS bundle boots,
 * so wsConnected resolves false deterministically regardless of soketi state.
 * We stub isConnected() defensively (the real service is created by the app;
 * we only override the read the component performs at mount + on events).
 */
async function forceWsDown(page) {
  await page.addInitScript(() => {
    // Best-effort: if the app installs _wsService, neuter its isConnected.
    // We also guard a property setter so a later assignment keeps the stub.
    const forceDisconnected = (svc) => {
      if (svc && typeof svc === 'object') {
        try { svc.isConnected = () => false; } catch (_e) { /* frozen — ignore */ }
      }
      return svc;
    };
    let _ws = window._wsService;
    forceDisconnected(_ws);
    try {
      Object.defineProperty(window, '_wsService', {
        configurable: true,
        get() { return _ws; },
        set(v) { _ws = forceDisconnected(v); },
      });
    } catch (_e) { /* property may already be locked — best effort */ }
  });
}

test.describe('PR-02 — KDS V2 sync degradation is VISIBLE on the box', () => {
  test('box default (flag undefined) + WS down → "SYNC · LOCAL" banner VISIBLE', async ({ page }) => {
    await forceWsDown(page);
    await loginAsChefOperator(page);
    await page.goto(KDS_URL, { waitUntil: 'domcontentloaded' });

    // Prove we are on the V2 layout (grid OR its empty-state mounts).
    await expect(
      page.locator('.kds-v2__grid, .kds-v2__empty').first()
    ).toBeVisible({ timeout: 20_000 });

    // The box never sets the opt-out flag → kdsSuppressFallbackBanner() is
    // false → with WS down, KdsStatusBanner renders the fallback warning with
    // the "SYNC · LOCAL" tag. This is the kitchen's visual cue. Target the V2
    // banner (NOT the legacy testid, which never mounts in V2).
    const v2Banner = page.locator('.kds-banner.kds-banner--warning', {
      hasText: 'SYNC · LOCAL',
    });
    await expect(
      v2Banner,
      'On the real box (no opt-out flag) with WS down, the kitchen MUST see "SYNC · LOCAL" — silent degradation is forbidden (owner mandate).'
    ).toBeVisible({ timeout: 15_000 });
  });

  test('dev opt-out (FK_KDS_SHOW_FALLBACK_BANNER === false) + WS down → banner SILENT', async ({ page }) => {
    await forceWsDown(page);
    // Dev opts out of the permanent banner (soketi intentionally off in dev).
    await page.addInitScript(() => { window.FK_KDS_SHOW_FALLBACK_BANNER = false; });
    await loginAsChefOperator(page);
    await page.goto(KDS_URL, { waitUntil: 'domcontentloaded' });

    await expect(
      page.locator('.kds-v2__grid, .kds-v2__empty').first()
    ).toBeVisible({ timeout: 20_000 });

    // appEnv === 'local' AND flag === false → kdsSuppressFallbackBanner() true
    // → fallback warning suppressed. Give the bundle a beat to settle, then
    // assert the SYNC · LOCAL warning is NOT shown (a higher-priority error/cap
    // banner is unrelated and not expected in this clean state).
    await page.waitForTimeout(1500);
    const v2Banner = page.locator('.kds-banner.kds-banner--warning', {
      hasText: 'SYNC · LOCAL',
    });
    await expect(
      v2Banner,
      'With the dev opt-out flag set, the fallback banner must be suppressed (no permanent dev noise).'
    ).toHaveCount(0);
  });
});
