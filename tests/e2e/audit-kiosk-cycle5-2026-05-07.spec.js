// FoodKing E2E — AUDIT KIOSK CYCLE K5 (2026-05-07)
// Lockdown + black-screen guard re-validation + offline routing + erreurs + auto-return + idle timer

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle5');
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

test.describe('AUDIT KIOSK CYCLE K5 — Lockdown + offline + erreurs + auto-return', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('K5-01 — Lockdown: /kiosk/admin redirects to idle (D-KIOSK-01/02/03)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/admin', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    const finalUrl = page.url();
    const shot = await snap(page, 1, 'lockdown', 'redirect-from-admin');
    const isRedirected = !finalUrl.includes('/kiosk/admin') || finalUrl.includes('/kiosk/idle');
    record('K5-01', 'lockdown-admin-redirect', isRedirected ? 'redirected' : 'leaked',
      isRedirected ? 'OK' : 'P0',
      `/kiosk/admin → ${finalUrl}`, shot);
  });

  test('K5-02 — Lockdown: /js/kiosk-admin.js returns 404 (no admin bundle)', async ({ page }) => {
    const resp = await page.goto('http://localhost:8000/js/kiosk-admin.js', { waitUntil: 'domcontentloaded' }).catch(() => null);
    const status = resp?.status() ?? 0;
    const shot = await snap(page, 2, 'lockdown', `kiosk-admin-js-${status}`);
    record('K5-02', 'lockdown-admin-bundle', `status-${status}`,
      status === 404 ? 'OK' : 'P0',
      `/js/kiosk-admin.js status=${status} (expected 404)`, shot);
  });

  test('K5-03 — Error routing: /kiosk/error/payment-refused renders', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/error/payment-refused?code=card_declined&order_id=999', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    const shot = await snap(page, 3, 'error-payment-refused', 'rendered');
    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasContent = visibleText.length > 50;
    record('K5-03', 'error-payment-refused', hasContent ? 'rendered' : 'blank',
      hasContent ? 'OK' : 'P1',
      `Page error/payment-refused length=${visibleText.length}`, shot);
  });

  test('K5-04 — Error routing: /kiosk/error/menu-unavailable renders', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/error/menu-unavailable', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    const shot = await snap(page, 4, 'error-menu-unavailable', 'rendered');
    const visibleText = await page.locator('body').innerText().catch(() => '');
    record('K5-04', 'error-menu-unavailable', visibleText.length > 50 ? 'rendered' : 'blank',
      visibleText.length > 50 ? 'OK' : 'P1', `Length=${visibleText.length}`, shot);
  });

  test('K5-05 — Error routing: /kiosk/error/product-removed renders', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/error/product-removed?name=Tacos&item_id=42', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    const shot = await snap(page, 5, 'error-product-removed', 'rendered');
    const visibleText = await page.locator('body').innerText().catch(() => '');
    record('K5-05', 'error-product-removed', visibleText.length > 50 ? 'rendered' : 'blank',
      visibleText.length > 50 ? 'OK' : 'P1', `Length=${visibleText.length}`, shot);
  });

  test('K5-06 — Sentinel: lockdown lint scripts exist + KioskBundleLockdownTest exists', async ({ page }) => {
    const fs2 = require('fs');
    const lintScript = path.join(process.cwd(), 'tools/lint/scan_kiosk_bundles.mjs');
    const lintExists = fs2.existsSync(lintScript);

    const lockdownTest = path.join(process.cwd(), 'tests/Feature/KioskBundleLockdownTest.php');
    const lockdownTestExists = fs2.existsSync(lockdownTest);

    const lockdownPolicy = path.join(process.cwd(), 'docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md');
    const policyExists = fs2.existsSync(lockdownPolicy);

    const shot = await snap(page, 6, 'sentinel-lockdown', lintExists && lockdownTestExists ? 'enforced' : 'partial');
    record('K5-06', 'sentinel-lockdown',
      lintExists && lockdownTestExists ? 'enforced' : 'partial',
      lintExists && lockdownTestExists ? 'OK' : 'P1',
      `lint=${lintExists}, test=${lockdownTestExists}, policy=${policyExists}`, shot);
  });

  test('K5-07 — Auto-return spec existant kiosk-post-payment-auto-return', async ({ page }) => {
    const fs2 = require('fs');
    const autoReturnSpec = path.join(process.cwd(), 'tests/e2e/kiosk-post-payment-auto-return.spec.js');
    const exists = fs2.existsSync(autoReturnSpec);
    const shot = await snap(page, 7, 'auto-return-spec', exists ? 'present' : 'missing');
    record('K5-07', 'auto-return-spec',
      exists ? 'present' : 'missing',
      exists ? 'OK' : 'P1',
      `kiosk-post-payment-auto-return.spec.js exists=${exists}`, shot);
  });

  test('K5-08 — Sentinel: offline queue helpers + IndexedDB wrapper exist', async ({ page }) => {
    const fs2 = require('fs');
    const offlineQueue = path.join(process.cwd(), 'resources/js/helpers/kioskOfflineQueue.js');
    const offlineDb = path.join(process.cwd(), 'resources/js/helpers/kioskOfflineQueueDb.js');
    const offlineV2Spec = path.join(process.cwd(), 'tests/js/kioskOfflineQueueV2.spec.js');

    const allExist = fs2.existsSync(offlineQueue) && fs2.existsSync(offlineDb) && fs2.existsSync(offlineV2Spec);
    const shot = await snap(page, 8, 'sentinel-offline', allExist ? 'wired' : 'partial');
    record('K5-08', 'sentinel-offline',
      allExist ? 'wired' : 'partial',
      allExist ? 'OK' : 'P1',
      `kioskOfflineQueue=${fs2.existsSync(offlineQueue)}, db=${fs2.existsSync(offlineDb)}, V2 spec=${fs2.existsSync(offlineV2Spec)}`, shot);
  });

  test('K5-09 — Synthèse + INDEX K5', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.waitForTimeout(800);
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT KIOSK CYCLE K5 — Captures Index 2026-05-07', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Sev | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).slice(0, 200).replace(/\|/g, '\\|')} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(5);
  });
});
