// FoodKing E2E — AUDIT KDS CYCLE D3 (2026-05-07)
// Real-time sync POS↔KDS via Pusher / Echo (smoke + source-side sentinels).
// Vérifie :
//   1. KitchenDisplaySystemComponent.vue souscrit aux 4 events broadcast attendus
//      (OrderCreated, OrderStatusChanged, OrderPaidAtCounter, ItemAvailabilityChanged)
//      + OrderTableChanged.
//   2. Route /api/admin/kds-order/sync existe (KdsSyncController).
//   3. KdsSyncService instancié dans le composant (import + start()).
//   4. Aucun crash JS au mount du composant KDS.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kds-2026-05-07/cycle3');
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
  await page.screenshot({ path: fp, fullPage: true }).catch((e) => console.warn(`[D3] snap ${fn}: ${e.message}`));
  return path.relative(process.cwd(), fp);
}

const KDS_VUE_PATH = path.join(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
const ROUTES_API_PATH = path.join(process.cwd(), 'routes/api.php');

test.describe('AUDIT KDS CYCLE D3 — Real-time sync (Pusher / Echo)', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('D3-01 — Source sentinel : 4 broadcastAs souscrits (OrderCreated/StatusChanged/PaidAtCounter/ItemAvailabilityChanged)', async () => {
    expect(fs.existsSync(KDS_VUE_PATH)).toBe(true);
    const src = fs.readFileSync(KDS_VUE_PATH, 'utf8');

    const subs = {
      OrderCreated: src.includes("broadcastAs: 'OrderCreated'"),
      OrderStatusChanged: src.includes("broadcastAs: 'OrderStatusChanged'"),
      OrderPaidAtCounter: src.includes("broadcastAs: 'OrderPaidAtCounter'"),
      ItemAvailabilityChanged: src.includes("broadcastAs: 'ItemAvailabilityChanged'"),
      OrderTableChanged: src.includes("broadcastAs: 'OrderTableChanged'"),
    };

    record('D3-01', 'broadcast-subscriptions', 'source-check',
      Object.values(subs).every(Boolean) ? 'OK' : 'P1',
      `Souscriptions broadcast détectées : ${JSON.stringify(subs)}`, null);

    expect(subs.OrderCreated).toBe(true);
    expect(subs.OrderStatusChanged).toBe(true);
    expect(subs.OrderPaidAtCounter).toBe(true);
    expect(subs.ItemAvailabilityChanged).toBe(true);
  });

  test('D3-02 — Source sentinel : KdsSyncService importé + démarré', async () => {
    const src = fs.readFileSync(KDS_VUE_PATH, 'utf8');

    const hasImport = /import\s+kdsSyncService\s+from\s+["']\.\.\/\.\.\/\.\.\/services\/KdsSyncService["']/.test(src);
    const hasStart = /kdsSyncService\.start\s*\(/.test(src);
    const hasOnSync = /kdsSyncService\.on\s*\(\s*['"]sync['"]/.test(src);
    const hasStop = /kdsSyncService\.stop\s*\(/.test(src);

    record('D3-02', 'kds-sync-service', 'source-check',
      (hasImport && hasStart && hasOnSync) ? 'OK' : 'P1',
      `import=${hasImport}, start()=${hasStart}, on('sync')=${hasOnSync}, stop()=${hasStop}`, null);

    expect(hasImport).toBe(true);
    expect(hasStart).toBe(true);
    expect(hasOnSync).toBe(true);
  });

  test('D3-03 — Route /api/admin/kds-order/sync existe (KdsSyncController)', async () => {
    expect(fs.existsSync(ROUTES_API_PATH)).toBe(true);
    const routes = fs.readFileSync(ROUTES_API_PATH, 'utf8');

    const hasController = /use\s+App\\Http\\Controllers\\Admin\\KdsSyncController;/.test(routes);
    const hasRoute = /Route::get\s*\(\s*['"]\/sync['"]\s*,\s*\[KdsSyncController::class\s*,\s*['"]sync['"]\]/.test(routes);
    const hasPrefix = /Route::prefix\s*\(\s*['"]kds-order['"]\s*\)/.test(routes);

    record('D3-03', 'kds-sync-route', 'source-check',
      (hasController && hasRoute && hasPrefix) ? 'OK' : 'P0',
      `controller-import=${hasController}, sync-route=${hasRoute}, kds-order-prefix=${hasPrefix}`, null);

    expect(hasController).toBe(true);
    expect(hasRoute).toBe(true);
    expect(hasPrefix).toBe(true);
  });

  test('D3-04 — KdsSyncController.php existe (fichier physique)', async () => {
    const ctrl = path.join(process.cwd(), 'app/Http/Controllers/Admin/KdsSyncController.php');
    expect(fs.existsSync(ctrl)).toBe(true);

    const src = fs.readFileSync(ctrl, 'utf8');
    const hasNamespace = /namespace\s+App\\Http\\Controllers\\Admin/.test(src);
    const hasSyncMethod = /function\s+sync\s*\(/.test(src);

    record('D3-04', 'kds-sync-controller-file', 'source-check',
      (hasNamespace && hasSyncMethod) ? 'OK' : 'P0',
      `namespace=${hasNamespace}, sync()-method=${hasSyncMethod}`, null);

    expect(hasNamespace).toBe(true);
    expect(hasSyncMethod).toBe(true);
  });

  test('D3-05 — Mount KDS sans crash (smoke runtime)', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsChefOperator(page);
    await page.waitForTimeout(4_000);

    const shot = await snap(page, 5, 'mount-smoke', 'loaded');

    const critical = pageErrors.filter((m) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(m),
    );

    record('D3-05', 'mount-smoke', 'runtime',
      critical.length === 0 ? 'OK' : 'P1',
      `Page errors total=${pageErrors.length}, critical=${critical.length}. Sample: ${pageErrors.slice(0, 3).join(' | ')}`, shot);

    expect(critical.length).toBe(0);
  });
});
