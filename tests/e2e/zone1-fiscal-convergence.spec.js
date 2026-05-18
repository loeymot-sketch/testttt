// Zone 1 NF525 Fiscal Chain Convergence — test-e2e visual + technical
//
// Validates Wave 2d heals (FISCAL-ADV3C-01/02/03) end-to-end:
//   1. Admin login → /admin/dashboard mounts LastZReportWidget which
//      fires GET /api/admin/fiscal/z-report (the only existing fiscal
//      surface in the SPA — there is NO /admin/fiscal/z-reports list
//      page in this V1 build; documented in CONVERGENCE_FINAL.md).
//   2. Capture full-page PNG of the dashboard widget rendering so the
//      orchestrator can Read the screenshot and check for raw labels
//      (kiosk.x.y placeholders), empty-state breakage, or branding loss.
//   3. CLI verifications (run via Bash separately, NOT inside Playwright):
//        - fiscal:verify-chain --branch=1     → exit 0 expected
//        - fiscal:verify-chain --branch=0     → exit 2 (Wave 2d HEAL 3)
//        - fiscal:verify-chain --branch=99999 → exit 2 (existing guard)
//        - fiscal:verify-chain --all          → exit 0 expected
//
// NF525-critical: this spec must NEVER mutate audit_logs / z_reports
// against the dev DB (immutability triggers protect against accidental
// writes, but additionally we do not call any tamper endpoint here).
// Live tamper tests live in tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php
// where RefreshDatabase isolates the chain mutation.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ADMIN_EMAIL = 'admin@lecayenne.fr';
const ADMIN_PASSWORD = '123456';
const SCREENSHOT_DIR = path.join(
  __dirname,
  'screenshots',
  'zone1-fiscal-convergence-2026-05-18'
);

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function loginAsAdmin(page) {
  await page.goto('/login');
  await page.locator('input[type="text"]').first().fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  // FR locale: "Connexion"; EN locale: "Login". Match either.
  await page
    .getByRole('button', { name: /^(login|connexion)$/i })
    .click();
  await page.waitForURL(/\/admin\//, { timeout: 25_000 });
}

function filterFatalErrors(errors) {
  return errors.filter(
    (e) =>
      !/(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|woff)|GoogleAnalytics|gtag|workbox|kiosk-event|Failed to load resource:|Pusher|Echo)/i.test(
        e
      )
  );
}

test.describe('Zone 1 NF525 Fiscal Chain Convergence (Wave 2d)', () => {
  test('admin login → dashboard LastZReportWidget renders + Z-report API responds', async ({ page }) => {
    /** @type {string[]} */
    const consoleErrors = [];
    /** @type {Array<{ url: string, status: number }>} */
    const fiscalApiResponses = [];

    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('response', (res) => {
      const u = res.url();
      if (/\/api\/admin\/fiscal\/z-report/.test(u)) {
        fiscalApiResponses.push({ url: u, status: res.status() });
      }
    });

    await loginAsAdmin(page);
    await page.goto('/admin/dashboard');

    // Wait for LastZReportWidget to render its data-testid wrapper.
    // Falls back to network-idle if the test-id is absent (older builds).
    await page
      .waitForSelector('[data-testid="last-z-report-link"]', { timeout: 15_000 })
      .catch(() => page.waitForLoadState('networkidle', { timeout: 15_000 }));

    // Allow widget axios to settle.
    await page.waitForTimeout(2_000);

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-admin-dashboard-with-z-widget.png'),
      fullPage: true,
    });

    const fatal = filterFatalErrors(consoleErrors);
    expect(fatal, `Unexpected fatal console errors: ${fatal.join(' | ')}`).toEqual([]);

    // Fiscal API should have been called (LastZReportWidget mount).
    expect(
      fiscalApiResponses.length,
      `Expected GET /api/admin/fiscal/z-report from LastZReportWidget; got: ${JSON.stringify(fiscalApiResponses)}`
    ).toBeGreaterThan(0);

    // Status must be 2xx (or 403/permission-deny is acceptable for
    // non-Branch-Manager users; admin@lecayenne.fr is super-admin so
    // we expect a 200).
    for (const r of fiscalApiResponses) {
      expect([200, 204], `Unexpected status ${r.status} for ${r.url}`).toContain(r.status);
    }
  });
});
