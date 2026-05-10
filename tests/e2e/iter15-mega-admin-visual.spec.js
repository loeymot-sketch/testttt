// FoodKing E2E — iter15 Mega-Audit Wave A (Admin Visual Page-by-Page)
//
// Owner-scoped admin visual capture. For every visual state we emit the
// artifact quartet (png + dom.html + console.json + network.json) via the
// shared mega-audit recorder so Wave E reviewers can run the 12-defect
// protocol from docs/iter15-mega/REVIEWER_PROTOCOL.md.
//
// Scope (selective, do NOT drift):
//   01 /admin/dashboard                 default landing (1280x800)
//   01 /admin/dashboard                 secondary capture (1920x1080)
//   02 /admin/items                     list default
//   03 /admin/items                     first availability-toggle row visible (NO toggle)
//   04 /admin/order-status-screen       écran client default
//   05 /admin/observability (best-effort, may 404 — captured either way)
//
// We do NOT toggle availability, place orders, or interact with payment.
// That belongs to Waves B/C/D. This spec captures evidence only.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/iter15-mega-admin');

test.describe('iter15 mega — Wave A (admin visual page-by-page)', () => {
  test.setTimeout(180_000);

  test('admin pages full visual capture', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    try {
      // ---------------------------------------------------------------
      // 01 — Dashboard (default landing) @ 1280x800
      // ---------------------------------------------------------------
      await loginAsAdmin(page);
      // loginAsAdmin already lands on /admin/* ; force /admin/dashboard so
      // we capture the canonical landing regardless of role-routing quirks.
      if (!/\/admin\/dashboard(\/|$|\?)/.test(page.url())) {
        await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
      }
      // Let widget XHRs settle. Dashboard pulls multiple stats endpoints —
      // 2.5s mirrors the Wave A briefing default.
      await page.waitForTimeout(2_500);
      await snap('01-dashboard-default');

      // ---------------------------------------------------------------
      // 01b — Dashboard @ 1920x1080 (responsive proof, dashboard only)
      // ---------------------------------------------------------------
      await page.setViewportSize({ width: 1920, height: 1080 });
      await page.waitForTimeout(800);
      await snap('01-dashboard-1920');
      // Restore baseline viewport for the rest of the run.
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.waitForTimeout(300);

      // ---------------------------------------------------------------
      // 02 — Items list default
      // ---------------------------------------------------------------
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      await snap('02-items-list-default');

      // ---------------------------------------------------------------
      // 03 — First availability-toggle row in view (NO toggle action)
      // ---------------------------------------------------------------
      const toggleRow = page.locator('[data-testid^="admin-availability-toggle-"]').first();
      const toggleVisible = await toggleRow.isVisible({ timeout: 3_000 }).catch(() => false);
      if (toggleVisible) {
        await toggleRow.scrollIntoViewIfNeeded().catch(() => {});
        await page.waitForTimeout(400);
        await snap('03-items-toggle-row-visible');
      } else {
        // Capture the not-found state so reviewers can confirm the testid
        // wiring is missing rather than the spec silently skipping.
        await snap('03-items-toggle-row-not-found');
      }

      // ---------------------------------------------------------------
      // 04 — Order status screen (écran client) default
      // ---------------------------------------------------------------
      await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      await snap('04-order-status-screen-default');

      // ---------------------------------------------------------------
      // 05 — Observability / outbox dashboard (best-effort)
      // ---------------------------------------------------------------
      const obsCandidates = [
        '/admin/observability',
        '/admin/observability/outbox',
        '/admin/outbox',
      ];
      let obsCaptured = false;
      for (const url of obsCandidates) {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const status = resp ? resp.status() : null;
        const landedOnTarget = status !== null && status < 400 && !/\/login(\/|$|\?)/.test(page.url());
        if (landedOnTarget) {
          await page.waitForTimeout(1_500);
          await snap('05-observability-default');
          obsCaptured = true;
          break;
        }
      }
      if (!obsCaptured) {
        // Still emit the quartet for the not-found state so Wave E can see
        // exactly what the SPA rendered (404 page, redirect, etc.).
        await snap('05-observability-not-found');
      }

      // Sanity-check at least one artifact landed: spec must not pass
      // silently if the recorder failed (e.g. unwritable dir).
      const fs = require('fs');
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      expect(written.length).toBeGreaterThanOrEqual(4);
    } finally {
      dispose();
    }
  });
});
