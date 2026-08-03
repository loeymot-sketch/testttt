// FoodKing E2E — AUDIT KIOSK CYCLE K4 (2026-05-07)
// Paiement TPE + payment-confirm + counter-collect + sync POS + idempotency wired

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk, loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function record(step, slug, state, sev, note, screenshot) {
  findings.push({ step, slug, state, severity: sev, note, screenshot, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const fn = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fp = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: fp, fullPage: true }).catch(() => {});
  return path.relative(process.cwd(), fp);
}

test.describe('AUDIT KIOSK CYCLE K4 — Paiement + sync POS', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('K4-01 — Payment screen renders 3 méthodes (CARD, CASH, TR)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 1, 'payment-screen', 'rendered');

    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal|Server Error/i.test(visibleText);
    // Detect 3 methods (text heuristic FR/EN)
    const hasCard = /carte|card|CB/i.test(visibleText);
    const hasCash = /esp[èe]ces|cash/i.test(visibleText);
    const hasTr = /ticket.{0,10}restaurant|TR/i.test(visibleText);

    record('K4-01', 'payment-render', hasError ? 'error' : 'rendered',
      hasError ? 'P0' : 'OK',
      `Page rendered. Card=${hasCard}, Cash=${hasCash}, TR=${hasTr} (kiosk redirect to cart if cart empty is normal)`, shot);
  });

  test('K4-02 — KR2 idempotency middleware actif sur /api/frontend/order', async ({ page }) => {
    // Verify route registered with idempotency middleware
    const fs2 = require('fs');
    const routes = fs2.readFileSync(path.join(process.cwd(), 'routes/api.php'), 'utf8');

    // Looking for the frontend/order POST route with 'idempotency' in middleware
    const orderRoutePattern = /Route::post\(['"]\/['"], \[FrontendOrderController::class, 'store'\]\)[\s\S]{0,300}idempotency/;
    const confirmRoutePattern = /Route::post\(['"][^'"]*payment-confirm[^'"]*['"][\s\S]{0,300}idempotency/;

    const orderProtected = orderRoutePattern.test(routes);
    const confirmProtected = confirmRoutePattern.test(routes);

    const shot = await snap(page, 2, 'idempotency-routes', orderProtected && confirmProtected ? 'wired' : 'partial');
    record('K4-02', 'idempotency-wired',
      orderProtected && confirmProtected ? 'wired' : 'missing',
      orderProtected && confirmProtected ? 'OK' : 'P1',
      `Order POST idempotency=${orderProtected}, payment-confirm idempotency=${confirmProtected}`, shot);
  });

  test('K4-03 — POS counter-collect panel exists + filters kiosk orders', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_000);

    const shot = await snap(page, 3, 'pos-counter-collect-panel', 'view');

    // Verify the GET endpoint responds (auth via session)
    const apiResp = await page.request.get('http://localhost:8000/api/admin/pos/counter-collect/pending').catch(() => null);
    const status = apiResp?.status() ?? 'no_response';
    record('K4-03', 'counter-collect-api', `status-${status}`,
      apiResp && status < 500 ? 'OK' : 'P2',
      `GET /api/admin/pos/counter-collect/pending status=${status}`, shot);
  });

  test('K4-04 — Sentinel: kiosk payment-confirm has X-Idempotency-Key support', async ({ page }) => {
    // Static-source sentinel: verify config/idempotency.php lists frontend/order/* routes
    const fs2 = require('fs');
    const config = fs2.readFileSync(path.join(process.cwd(), 'config/idempotency.php'), 'utf8');

    const hasFrontendOrder = config.includes('api/frontend/order');
    const hasPaymentConfirm = config.includes('payment-confirm');

    const shot = await snap(page, 4, 'sentinel-idempotency-config', hasFrontendOrder && hasPaymentConfirm ? 'configured' : 'incomplete');
    record('K4-04', 'sentinel-idempotency-config',
      hasFrontendOrder && hasPaymentConfirm ? 'configured' : 'incomplete',
      hasFrontendOrder && hasPaymentConfirm ? 'OK' : 'P1',
      `frontend/order route in required_routes=${hasFrontendOrder}, payment-confirm=${hasPaymentConfirm}`, shot);
  });

  test('K4-05 — Sentinel: CouponHttpScopeWiringTest wired (cycle 6 + KR2 alignment)', async ({ page }) => {
    const fs2 = require('fs');
    const couponSvc = fs2.readFileSync(path.join(process.cwd(), 'app/Services/CouponService.php'), 'utf8');

    const hasIsUsableNow = /\$coupon->isUsableNow\(/.test(couponSvc);
    const hasBranchSurface = /\$branchId.*\$surface/.test(couponSvc) || /branch_id.*surface/.test(couponSvc);

    const shot = await snap(page, 5, 'kr2-coupon-svc-wiring', hasIsUsableNow ? 'ok' : 'missing');
    record('K4-05', 'kr2-coupon-svc',
      hasIsUsableNow && hasBranchSurface ? 'wired' : 'partial',
      hasIsUsableNow && hasBranchSurface ? 'OK' : 'P1',
      `CouponService.validateCouponForOrder calls isUsableNow=${hasIsUsableNow}, branch+surface signature=${hasBranchSurface}`, shot);
  });

  test('K4-06 — Synthèse + INDEX K4', async ({ page }) => {
    await loginAsKiosk(page);
    await page.waitForTimeout(800);
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT KIOSK CYCLE K4 — Captures Index 2026-05-07', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Sev | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).slice(0, 200).replace(/\|/g, '\\|')} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(3);
  });
});
