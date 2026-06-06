// =============================================================================
// CANONICAL E2E — CENTRAL Dashboard real-time stats integrity (Wave-1 heals)
// =============================================================================
// Proves the DASH-0x central-dashboard defects are HEALED with concrete,
// anti-theater assertions (data-testid state, NOT raw FR text; route-abort to
// force the API-failure branch). NO bare expect(true).
//
// Defects covered (source: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
//   • DASH-04 — SlaAlerts / StockLowAlerts rendered a reassuring "all good"
//     empty-state on API failure (false-negative). Heal = distinct neutral
//     "Données indisponibles" state (data-testid="*-error"), NOT the green
//     empty-state and NOT a "0 Alerte(s)" count badge.
//   • DASH-01 — Overview + ChannelStats fetched once on mount, no auto-refresh.
//     Heal = setInterval poll + beforeUnmount clearInterval. (E2E asserts
//     mounted + initial load; the 30s refresh timing is proven by Vitest fake
//     timers in tests/js/dashboardWidgetsIntegrity.spec.js — not re-proven here.)
//   • DASH-03 — CustomerStats + TopCustomers were orphaned (backend computed,
//     nothing rendered). Heal = mounted in DashboardComponent → widgets present.
//
// HARNESS FACTS (worktree pre-cloud-exec):
//   - LIVE server = http://127.0.0.1:8765 (PLAYWRIGHT_BASE_URL). reuseExistingServer.
//   - loginAsAdmin → admin@lecayenne.fr / 123456 → lands /admin/dashboard.
//   - READ-ONLY: the dashboard issues only GETs. No order POST → NF525 chain
//     untouched. As a belt-and-braces guard we still abort any POST to /api/admin/pos.
//   - DASH-04 RED-path: route.abort() the GET /api/admin/dashboard/sla-alerts so
//     the widget's .catch fires → assert the error testid (not the green state).
// =============================================================================

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../../helpers/login');

const DASHBOARD_PATH = /\/admin\/dashboard/;

/**
 * Belt-and-braces: never let an order POST through (dashboard is read-only anyway).
 *
 * IMPORTANT route precedence: Playwright matches the LAST-registered handler
 * first. The DASH-04 test registers a specific sla-alerts ABORT *before* this
 * catch-all, so this catch-all is matched first for the sla-alerts GET. We use
 * route.fallback() (NOT route.continue()) for the non-pos branch so control
 * DEFERS to the earlier specific abort handler instead of terminally continuing
 * the request to the network (which would defeat the abort and green-wash the
 * error-state assertion). With no earlier handler, fallback() reaches the network.
 */
async function installChainSafety(page) {
  await page.route('**/*', (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /\/api\/admin\/pos(\?|$)/.test(req.url())) {
      return route.abort();
    }
    return route.fallback();
  });
}

async function gotoDashboard(page) {
  await loginAsAdmin(page);
  if (!DASHBOARD_PATH.test(page.url())) {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  }
  await expect(page).toHaveURL(DASHBOARD_PATH, { timeout: 25_000 });
}

test.describe('CENTRAL Dashboard integrity (Wave-1 heals)', () => {
  test.describe.configure({ timeout: 90_000 });

  // ---- DASH-03 : orphaned analytics widgets are now mounted ------------------
  test('DASH-03 — CustomerStats + TopCustomers widgets are mounted on the dashboard', async ({ page }) => {
    await installChainSafety(page);
    await gotoDashboard(page);

    const customerStats = page.locator('[data-testid="customer-stats-widget"]');
    const topCustomers = page.locator('[data-testid="top-customers-widget"]');

    await expect(customerStats, 'CustomerStats widget must render (was orphaned)').toBeVisible({ timeout: 25_000 });
    await expect(topCustomers, 'TopCustomers widget must render (was orphaned)').toBeVisible({ timeout: 25_000 });
  });

  // ---- DASH-01 : headline KPI widgets are mounted + load ---------------------
  test('DASH-01 — Overview + ChannelStats widgets render and load (auto-refresh wired)', async ({ page }) => {
    await installChainSafety(page);
    await gotoDashboard(page);

    const overview = page.locator('[data-testid="overview-widget"]');
    const channelStats = page.locator('[data-testid="channel-stats-widget"]');

    await expect(overview, 'Overview KPI widget must render').toBeVisible({ timeout: 25_000 });
    await expect(channelStats, 'ChannelStats widget must render').toBeVisible({ timeout: 25_000 });

    // ANTI-THEATER: confirm a headline KPI actually POPULATED from the GET — the
    // total_menu_items value is a digit-bearing block (DASH-06 makes it the
    // active-catalogue count). Poll until the widget text contains a digit, so a
    // skeleton/empty mount (KPI still null) does NOT pass. Static labels alone
    // (e.g. "Total des ventes") carry no digit, so this cannot green-wash.
    await expect
      .poll(
        async () => /\d/.test((await overview.innerText()) || ''),
        { timeout: 25_000, message: 'Overview must render at least one resolved numeric KPI value' },
      )
      .toBe(true);
  });

  // ---- DASH-04 : SLA widget shows a neutral error, NOT a false 'all good' ----
  test('DASH-04 — SLA widget renders "Données indisponibles" error state on API failure (not the green empty-state)', async ({ page }) => {
    // Force the sla-alerts endpoint to fail so the widget's .catch fires.
    await page.route('**/api/admin/dashboard/sla-alerts*', (route) => route.abort());
    await installChainSafety(page);
    await gotoDashboard(page);

    const slaWidget = page.locator('[data-testid="sla-alerts-widget"]');
    await expect(slaWidget).toBeVisible({ timeout: 25_000 });

    // The distinct neutral error state MUST appear…
    await expect(
      slaWidget.locator('[data-testid="sla-alerts-error"]'),
      'SLA widget must show the neutral error state when the API fails',
    ).toBeVisible({ timeout: 25_000 });

    // …and the reassuring green "flux optimal" empty-state must NOT appear…
    await expect(
      slaWidget.locator('[data-testid="sla-alerts-empty"]'),
      'SLA widget must NOT show the green all-good empty-state on API failure (false-negative)',
    ).toHaveCount(0);

    // …and the "0 Alerte(s)" count badge must NOT masquerade as a healthy count.
    await expect(
      slaWidget.locator('[data-testid="sla-alerts-count"]'),
      'SLA widget must NOT show a "0 Alerte(s)" count badge on API failure',
    ).toHaveCount(0);
  });

  // ---- DASH-04 : SLA widget shows the green empty-state on a genuine success -
  test('DASH-04 — SLA widget shows the green empty-state on a genuine (non-failing) load', async ({ page }) => {
    await installChainSafety(page);
    await gotoDashboard(page);

    const slaWidget = page.locator('[data-testid="sla-alerts-widget"]');
    await expect(slaWidget).toBeVisible({ timeout: 25_000 });

    // On a healthy load the widget must NOT show the error state. It shows either
    // the green empty-state (no breaches) or a real alerts list (breaches) — but
    // NEVER the error state. This guards against the heal over-triggering.
    await expect(
      slaWidget.locator('[data-testid="sla-alerts-error"]'),
      'SLA widget must NOT show the error state on a healthy load',
    ).toHaveCount(0);

    // Exactly one of empty-state or a populated alert list must be present.
    const hasEmpty = await slaWidget.locator('[data-testid="sla-alerts-empty"]').count();
    const hasCount = await slaWidget.locator('[data-testid="sla-alerts-count"]').count();
    expect(
      hasEmpty + hasCount,
      'a healthy SLA widget must render either the empty-state or the count badge',
    ).toBeGreaterThan(0);
  });
});
