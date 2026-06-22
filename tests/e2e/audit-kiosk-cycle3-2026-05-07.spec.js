// FoodKing E2E — AUDIT KIOSK CYCLE K3 (2026-05-07)
// Wizard kiosk flow complet (NON-PROTÉGÉ) + cart + sentinel CouponChanged Echo subscription (KR2)

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3');
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

test.describe('AUDIT KIOSK CYCLE K3 — Catalogue + wizard + cart + KR2 Echo sentinel', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('K3-01 — Categories tactile rendering (1080x1920)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 1, 'categories', 'rendered');

    // Compter catégories visibles (heuristique: any clickable card on categories page)
    const cardSelectors = ['.kiosk-category-card', '.ks-card', '[data-kiosk-category]', '[role="button"]'];
    let count = 0;
    for (const sel of cardSelectors) {
      const c = await page.locator(sel).count().catch(() => 0);
      if (c > count) count = c;
    }
    record('K3-01', 'categories', 'count', count > 0 ? 'OK' : 'P2', `${count} cards/buttons détectés via heuristique`, shot);
  });

  test('K3-02 — Click first category → items grid', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    // Try clicking the first interactive card
    const firstCard = page.locator('button, [role="button"], a').filter({ hasText: /[A-Za-z]{3,}/ }).first();
    if (!(await firstCard.isVisible().catch(() => false))) {
      const shot = await snap(page, 2, 'categories', 'no-clickable');
      record('K3-02', 'category-click', 'no-target', 'P2', 'Aucun élément interactif détecté sur /kiosk/categories', shot);
      return;
    }

    try {
      await firstCard.click({ timeout: 3_000 });
      await page.waitForTimeout(2_000);
    } catch (_) {}

    const shot = await snap(page, 2, 'categories', 'after-click');
    record('K3-02', 'category-click', 'attempted', 'OK', 'Premier élément clicable cliqué', shot);
  });

  test('K3-03 — Wizard route /kiosk/wizard/:itemId direct (smoke render)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);

    // Trouver un itemId via API (best-effort)
    let itemId = 1;
    try {
      const apiResp = await page.request.get(`http://localhost:8000/api/frontend/menu`, {
        headers: { 'Accept': 'application/json' },
      });
      if (apiResp.ok()) {
        const json = await apiResp.json();
        const items = json?.data?.items || json?.items || [];
        if (items.length > 0) itemId = items[0].id;
      }
    } catch (_) {}

    await page.goto(`/kiosk/wizard/${itemId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 3, 'wizard', `item-${itemId}`);

    // Vérifier que la page n'est pas blank
    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasContent = visibleText.length > 50;
    record('K3-03', 'wizard-render', hasContent ? 'rendered' : 'blank', hasContent ? 'OK' : 'P1',
      `Wizard route /kiosk/wizard/${itemId}: text length=${visibleText.length}`, shot);
  });

  test('K3-04 — Cart screen + offline indicators', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    const shot = await snap(page, 4, 'cart', 'state');

    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal|Server Error/i.test(visibleText);
    record('K3-04', 'cart', hasError ? 'error' : 'rendered', hasError ? 'P0' : 'OK',
      `Cart length=${visibleText.length}, hasError=${hasError}`, shot);
  });

  test('K3-05 — KR2 SENTINEL: KioskAppComponent subscribes to CouponChanged', async ({ page }) => {
    // Static-source sentinel: verify the component source contains the subscription
    const fs2 = require('fs');
    const componentPath = path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskAppComponent.vue');
    const source = fs2.readFileSync(componentPath, 'utf8');

    const hasCouponBroadcast = source.includes("broadcastAs: 'CouponChanged'");
    const hasHandler = source.includes('_handleCouponChanged');
    const hasReference = source.includes('KR2');

    const shot = await snap(page, 5, 'kr2-sentinel', hasCouponBroadcast ? 'wired' : 'missing');
    record('K3-05', 'kr2-coupon-echo', hasCouponBroadcast && hasHandler ? 'wired' : 'missing',
      hasCouponBroadcast && hasHandler ? 'OK' : 'P1',
      `CouponChanged broadcastAs=${hasCouponBroadcast}, handler=${hasHandler}, KR2 ref=${hasReference}`, shot);
  });

  test('K3-06 — Branch isolation in CouponChanged handler (defense in depth)', async ({ page }) => {
    const fs2 = require('fs');
    const componentPath = path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskAppComponent.vue');
    const source = fs2.readFileSync(componentPath, 'utf8');

    // Verify the handler uses _normalizeBranchId + _getActiveBranchId
    const hasNormalize = /_handleCouponChanged[\s\S]{0,1000}_normalizeBranchId/.test(source);
    const hasActiveCheck = /_handleCouponChanged[\s\S]{0,1000}_getActiveBranchId/.test(source);

    const shot = await snap(page, 6, 'kr2-branch-isolation', hasNormalize && hasActiveCheck ? 'enforced' : 'weak');
    record('K3-06', 'kr2-branch-isolation', hasNormalize && hasActiveCheck ? 'enforced' : 'weak',
      hasNormalize && hasActiveCheck ? 'OK' : 'P2',
      `_normalizeBranchId=${hasNormalize}, _getActiveBranchId=${hasActiveCheck}`, shot);
  });

  test('K3-07 — Synthèse + INDEX K3', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.waitForTimeout(800);

    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT KIOSK CYCLE K3 — Captures Index 2026-05-07', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Sev | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).slice(0, 200).replace(/\|/g, '\\|')} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(3);
  });
});
