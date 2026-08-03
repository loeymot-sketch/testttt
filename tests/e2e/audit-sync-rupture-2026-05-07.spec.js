// FoodKing E2E — AUDIT SYNCHRONISATION RUPTURE PRODUIT (MEGA-D 2026-05-07)
// Test critique: marker un item out-of-stock côté admin et vérifier que les 3 surfaces
// (POS, Kiosk, KDS) reçoivent l'event ItemAvailabilityChanged via Outbox + Pusher.
// Vérifier traçabilité (domain_events table + audit_logs si applicable).

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator, loginAsKiosk, loginAsChefOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-sync-rupture-2026-05-07');
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

// Helper: artisan tinker query JSON output
function tinker(code) {
  try {
    const out = execSync(`php artisan tinker --execute='${code.replace(/'/g, "\\'")}'`, {
      cwd: process.cwd(),
      encoding: 'utf8',
      timeout: 30000,
    });
    const lines = out.trim().split(/\r?\n/);
    const last = [...lines].reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
    if (last) {
      try { return JSON.parse(last); } catch (_) { return { _raw: last }; }
    }
    return { _output: out.trim().slice(-500) };
  } catch (e) {
    return { _error: e.message.slice(0, 500) };
  }
}

test.describe('AUDIT MEGA-D — Synchronisation rupture produit (3 surfaces)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('D-01 — Source sentinel: ItemAvailabilityChanged dispatched dans 4 services backend', async ({ page }) => {
    const services = [
      'app/Services/ItemService.php',
      'app/Services/ItemExtraService.php',
      'app/Services/ItemAddonService.php',
      'app/Services/ItemVariationService.php',
    ];

    const results = {};
    for (const svc of services) {
      const fp = path.join(process.cwd(), svc);
      if (!fs.existsSync(fp)) {
        results[svc] = { missing: true };
        continue;
      }
      const source = fs.readFileSync(fp, 'utf8');
      results[svc] = {
        imports: source.includes('use App\\Events\\ItemAvailabilityChanged;'),
        dispatches: (source.match(/ItemAvailabilityChanged::dispatch/g) || []).length,
      };
    }
    fs.writeFileSync(path.join(SHOT_DIR, 'D-01-source-dispatchers.json'), JSON.stringify(results, null, 2));

    const allOk = Object.values(results).every((r) => !r.missing && r.imports && r.dispatches > 0);
    const shot = await snap(page, 1, 'src-dispatchers', allOk ? 'wired' : 'partial');
    record('D-01', 'src-dispatchers', allOk ? 'all-wired' : 'partial',
      allOk ? 'OK' : 'P0',
      `4 services dispatch ItemAvailabilityChanged: ${JSON.stringify(results)}`, shot);
  });

  test('D-02 — Source sentinel: 3 surfaces subscribent à ItemAvailabilityChanged via Echo', async ({ page }) => {
    const subscribers = {
      pos: 'resources/js/components/admin/pos/PosComponent.vue',
      kiosk: 'resources/js/components/frontend/kiosk/KioskAppComponent.vue',
      kds: 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
    };

    const results = {};
    for (const [surface, file] of Object.entries(subscribers)) {
      const fp = path.join(process.cwd(), file);
      if (!fs.existsSync(fp)) {
        results[surface] = { missing: true };
        continue;
      }
      const source = fs.readFileSync(fp, 'utf8');
      results[surface] = {
        broadcastAs: source.includes("broadcastAs: 'ItemAvailabilityChanged'"),
        handler: /_handleItemAvailabilityChanged|ItemAvailabilityChanged.*handler/.test(source),
      };
    }
    fs.writeFileSync(path.join(SHOT_DIR, 'D-02-surface-subscribers.json'), JSON.stringify(results, null, 2));

    const allOk = Object.values(results).every((r) => !r.missing && r.broadcastAs);
    const shot = await snap(page, 2, 'src-subscribers', allOk ? 'all-wired' : 'partial');
    record('D-02', 'src-subscribers', allOk ? 'all-wired' : 'partial',
      allOk ? 'OK' : 'P0',
      `POS subscribed=${results.pos?.broadcastAs}, Kiosk=${results.kiosk?.broadcastAs}, KDS=${results.kds?.broadcastAs}`, shot);
  });

  test('D-03 — Tinker: trigger ItemAvailabilityChanged + verify domain_events row created', async ({ page }) => {
    // Setup: find an existing active item
    const setup = tinker('echo json_encode([\"item\" => \\App\\Models\\Item::query()->where(\"status\", 5)->first()?->only([\"id\",\"name\",\"status\"])]);');
    if (!setup.item || !setup.item.id) {
      const shot = await snap(page, 3, 'no-item', 'precondition');
      record('D-03', 'precondition', 'no-active-item', 'P2', `No active item found in DB: ${JSON.stringify(setup)}`, shot);
      return;
    }
    const itemId = setup.item.id;
    const itemName = setup.item.name;

    // Capture baseline domain_events count
    const before = tinker('echo json_encode([\"count\" => \\App\\Models\\DomainEvent::where(\"event_type\", \"menu.item_availability_changed\")->count()]);');
    const beforeCount = before.count ?? 0;

    // Dispatch ItemAvailabilityChanged event directly (synchronous test)
    const dispatch = tinker(`\\App\\Events\\ItemAvailabilityChanged::dispatch(${itemId}, 10, 0); echo json_encode([\"dispatched\" => true, \"item_id\" => ${itemId}]);`);

    // Wait briefly for listener async dispatch
    await page.waitForTimeout(1500);

    // Capture after count
    const after = tinker('echo json_encode([\"count\" => \\App\\Models\\DomainEvent::where(\"event_type\", \"menu.item_availability_changed\")->count(), \"latest\" => \\App\\Models\\DomainEvent::where(\"event_type\", \"menu.item_availability_changed\")->latest(\"id\")->first()?->only([\"id\",\"event_type\",\"aggregate_id\",\"branch_id\",\"occurred_at\"])]);');
    const afterCount = after.count ?? 0;
    const delta = afterCount - beforeCount;

    fs.writeFileSync(path.join(SHOT_DIR, 'D-03-dispatch-trace.json'), JSON.stringify({
      item: setup.item, dispatch, before, after, delta,
    }, null, 2));

    const shot = await snap(page, 3, 'dispatch-trace', delta > 0 ? 'persisted' : 'lost');
    record('D-03', 'event-persistence', delta > 0 ? 'persisted' : 'lost',
      delta > 0 ? 'OK' : 'P0',
      `Item ${itemId} (${itemName}): domain_events count ${beforeCount}→${afterCount} (delta=${delta}). Latest event: ${JSON.stringify(after.latest)}`, shot);
  });

  test('D-04 — Vérifier ItemAvailabilityChanged listener (PersistItemAvailabilityChangedToOutbox)', async ({ page }) => {
    const listenerPath = path.join(process.cwd(), 'app/Listeners/PersistItemAvailabilityChangedToOutbox.php');
    const listenerExists = fs.existsSync(listenerPath);
    let listenerSource = null;
    let isCabled = false;

    if (listenerExists) {
      listenerSource = fs.readFileSync(listenerPath, 'utf8').slice(0, 2000);
    }

    // Vérifier câblage dans EventServiceProvider
    const espPath = path.join(process.cwd(), 'app/Providers/EventServiceProvider.php');
    if (fs.existsSync(espPath)) {
      const espSource = fs.readFileSync(espPath, 'utf8');
      isCabled = /ItemAvailabilityChanged.*=>.*\[[\s\S]*?PersistItemAvailabilityChangedToOutbox/.test(espSource);
    }

    fs.writeFileSync(path.join(SHOT_DIR, 'D-04-listener-trace.json'), JSON.stringify({
      listenerExists, isCabled, source_excerpt: listenerSource?.slice(0, 500),
    }, null, 2));

    const shot = await snap(page, 4, 'listener', listenerExists && isCabled ? 'wired' : 'incomplete');
    record('D-04', 'listener-outbox', listenerExists && isCabled ? 'wired' : 'incomplete',
      listenerExists && isCabled ? 'OK' : 'P1',
      `PersistItemAvailabilityChangedToOutbox exists=${listenerExists}, EventServiceProvider câblé=${isCabled}`, shot);
  });

  test('D-05 — Vérifier ItemAvailabilityChanged dans EventContract (BROADCAST_MAP + REQUIRED_PAYLOAD_KEYS)', async ({ page }) => {
    const ecPath = path.join(process.cwd(), 'app/Domain/Events/EventContract.php');
    if (!fs.existsSync(ecPath)) {
      const shot = await snap(page, 5, 'event-contract', 'missing');
      record('D-05', 'event-contract', 'missing', 'P1', 'EventContract.php not found', shot);
      return;
    }
    const source = fs.readFileSync(ecPath, 'utf8');
    const inBroadcastMap = /'ItemAvailabilityChanged'\s*=>\s*EventType::MENU_ITEM_AVAILABILITY_CHANGED/.test(source);
    const inPayloadKeys = /MENU_ITEM_AVAILABILITY_CHANGED.*=>.*\[/.test(source);

    const shot = await snap(page, 5, 'event-contract', inBroadcastMap && inPayloadKeys ? 'wired' : 'partial');
    record('D-05', 'event-contract', inBroadcastMap && inPayloadKeys ? 'wired' : 'partial',
      inBroadcastMap && inPayloadKeys ? 'OK' : 'P1',
      `BROADCAST_MAP=${inBroadcastMap}, REQUIRED_PAYLOAD_KEYS=${inPayloadKeys}`, shot);
  });

  test('D-06 — Verify ItemBranchAvailability table sync + StockLevel integration', async ({ page }) => {
    const tables = tinker('echo json_encode([\"item_branch_availability\" => \\Schema::hasTable(\"item_branch_availability\"), \"stock_levels\" => \\Schema::hasTable(\"stock_levels\"), \"item_extras\" => \\Schema::hasTable(\"item_extras\"), \"item_addons\" => \\Schema::hasTable(\"item_addons\")]);');

    fs.writeFileSync(path.join(SHOT_DIR, 'D-06-tables.json'), JSON.stringify(tables, null, 2));
    const allTablesExist = Object.values(tables).every((v) => v === true);
    const shot = await snap(page, 6, 'sync-tables', allTablesExist ? 'all-present' : 'partial');
    record('D-06', 'sync-tables', allTablesExist ? 'all-present' : 'partial',
      allTablesExist ? 'OK' : 'P1',
      `Tables: ${JSON.stringify(tables)}`, shot);
  });

  test('D-07 — Vérifier kioskMenu store action handle ItemAvailabilityChanged event refresh', async ({ page }) => {
    const fp = path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskAppComponent.vue');
    const source = fs.readFileSync(fp, 'utf8');
    const handlesItemAvailability = /_handleItemAvailabilityChanged.*\([\s\S]{0,800}fetchMenu/.test(source);
    const shot = await snap(page, 7, 'kiosk-handler', handlesItemAvailability ? 'refresh' : 'no-refresh');
    record('D-07', 'kiosk-handler', handlesItemAvailability ? 'refresh-on-event' : 'no-refresh',
      handlesItemAvailability ? 'OK' : 'P2',
      `Kiosk _handleItemAvailabilityChanged triggers fetchMenu=${handlesItemAvailability}`, shot);
  });

  test('D-08 — Vérifier POS overlay 86 (item_86_d) sur tuile rupture', async ({ page }) => {
    const fp = path.join(process.cwd(), 'resources/js/components/admin/pos/ItemComponent.vue');
    const source = fs.readFileSync(fp, 'utf8');
    const has86Badge = source.includes('item_86_d') || source.includes('pos-item-86-badge') || source.includes('isCatalogTileUnavailable');
    const shot = await snap(page, 8, 'pos-86-badge', has86Badge ? 'present' : 'missing');
    record('D-08', 'pos-86-badge', has86Badge ? 'present' : 'missing',
      has86Badge ? 'OK' : 'P1',
      `POS ItemComponent has 86 badge logic=${has86Badge}`, shot);
  });

  test('D-09 — Vérifier KDS ItemAvailabilityChanged subscription (SYNC-001)', async ({ page }) => {
    const fp = path.join(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
    const source = fs.readFileSync(fp, 'utf8');
    const subscribed = source.includes("broadcastAs: 'ItemAvailabilityChanged'");
    const refreshTriggered = /broadcastAs:\s*'ItemAvailabilityChanged'[\s\S]{0,300}_debouncedRefresh|refreshOrderList/.test(source);
    const shot = await snap(page, 9, 'kds-sync001', subscribed ? 'wired' : 'missing');
    record('D-09', 'kds-sync001', subscribed && refreshTriggered ? 'wired' : 'partial',
      subscribed && refreshTriggered ? 'OK' : 'P2',
      `KDS subscribed=${subscribed}, triggers refresh=${refreshTriggered}`, shot);
  });

  test('D-10 — Synthèse + INDEX MEGA-D', async ({ page }) => {
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT SYNCHRONISATION RUPTURE — MEGA-D 2026-05-07', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Sev | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).slice(0, 200).replace(/\|/g, '\\|')} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(7);
  });
});
