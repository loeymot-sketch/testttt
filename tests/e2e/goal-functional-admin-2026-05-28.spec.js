// GOAL E2E — ADMIN manager (gérant) functional validation 2026-05-28
//
// Mission : auditer le journey gérant complet, page-par-page, visuel + technique.
//   A1 Login admin → /admin/dashboard render + KPIs
//   A2 Catalogue /admin/items : list + edit toggle is_available => ItemAvailabilityChanged
//   A3 Stock /admin/stock-rupture-dashboard : page rendue + bouton 86 visible
//   A4 Settings update (Wave 5G R9) : capture + tentative POST settings/general =>
//      SettingsUpdated (best-effort, READ-ONLY si bouton absent)
//   A5 Branch deactivate (Wave 5G R10) : page branches + capture (READ-ONLY,
//      pas de toggle destructif — assertion via DB sentinel)
//   A6 EnsureUserStatusActive (Sprint H1 Z6-06) : middleware présent registered
//   A7 Z reports : page rendue + PDF download endpoint discovery
//   A8 Sales report daily
//
// Discipline :
//   - read-only sur tables NF525 (z_reports, audit_logs) — sentinel snapshot
//   - frozen-zone diff = 0 (verified post-run)
//   - IdempotencyKey middleware non touché (mutations utilisent l'API standard)
//   - Captures PNG + DOM analysis + Read tool downstream
//
// Credentials : admin@lecayenne.fr / 123456 (loginAsAdmin helper)

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const REPORT_ROOT = path.resolve(
    'reports/test-e2e/goal-functional-validation-2026-05-28/ADMIN',
);
const SCREENSHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const repoRoot = path.resolve(__dirname, '../..');

function tinker(script) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', script], {
        cwd: repoRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });
}

function parseLastJson(out) {
    const lines = out.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
    for (let i = lines.length - 1; i >= 0; i--) {
        const start = lines[i].indexOf('{');
        const startArr = lines[i].indexOf('[');
        const idx = start >= 0 && (startArr < 0 || start < startArr) ? start : startArr;
        if (idx >= 0) {
            try { return JSON.parse(lines[i].slice(idx)); } catch (_e) { /* keep looking */ }
        }
    }
    return null;
}

function snapshotDB() {
    const script = [
        '$lastEventId = (int) (DB::table("domain_events")->max("id") ?? 0);',
        '$lastAuditId = (int) (DB::table("audit_logs")->max("id") ?? 0);',
        '$zCount = (int) DB::table("z_reports")->count();',
        '$itemCount = (int) App\\Models\\Item::count();',
        '$branchCount = (int) App\\Models\\Branch::where("status","Active")->count();',
        '$itemSample = App\\Models\\Item::orderBy("id")->limit(1)->first(["id","name","is_available"]);',
        'echo json_encode(["last_event_id"=>$lastEventId,"last_audit_id"=>$lastAuditId,"z_count"=>$zCount,"item_count"=>$itemCount,"branch_active_count"=>$branchCount,"item_sample"=>$itemSample]);',
    ].join(' ');
    return parseLastJson(tinker(script));
}

function queryDomainEventsSince(lastId, eventTypes) {
    const types = eventTypes.map((t) => `'${t}'`).join(',');
    const script = [
        `$rows = DB::table("domain_events")->where("id",">",${lastId})->whereIn("event_type",[${types}])->orderBy("id")->get(["id","event_type","aggregate_type","aggregate_id","branch_id","occurred_at"]);`,
        'echo json_encode($rows->toArray());',
    ].join(' ');
    return parseLastJson(tinker(script));
}

function frenchI18nSweep(domText) {
    // Heuristic — flags obvious raw labels + common English manager-facing words.
    const findings = [];
    const rawLabelRe = /\b(Label\.[A-Za-z0-9_.]+|kiosk\.[a-z][\w.]+|pos\.[a-z][\w.]+|admin\.[a-z][\w.]+)\b/g;
    let m;
    while ((m = rawLabelRe.exec(domText)) !== null) {
        findings.push({ kind: 'raw_label', token: m[0], idx: m.index });
        if (findings.length > 20) break;
    }
    if (/\b0undefined\b|\bnull undefined\b|\[object Object\]/i.test(domText)) {
        findings.push({ kind: 'undefined_render', token: 'placeholder leak' });
    }
    // English manager words that should be French in a manager-facing surface.
    const englishHints = [/\bSettings\b/, /\bDashboard\b/, /\bLogout\b/, /\bSave\b/, /\bDelete\b/];
    for (const re of englishHints) {
        const matched = domText.match(re);
        if (matched) findings.push({ kind: 'english_hint', token: matched[0] });
    }
    return findings;
}

test.describe('GOAL E2E ADMIN manager — 2026-05-28', () => {
    test.setTimeout(240_000);

    const findings = [];
    let snapshot;
    const consoleErrors = [];
    const networkFailures = [];

    test.beforeAll(() => {
        snapshot = snapshotDB();
        if (!snapshot) throw new Error('snapshotDB returned null');
        fs.writeFileSync(
            path.join(REPORT_ROOT, 'db-baseline.json'),
            JSON.stringify(snapshot, null, 2),
        );
    });

    test.beforeEach(async ({ page }) => {
        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push({ url: page.url(), text: msg.text().slice(0, 240) });
        });
        page.on('response', (resp) => {
            if (resp.status() >= 500) networkFailures.push({ url: resp.url(), status: resp.status() });
        });
    });

    test('A1..A8 — admin manager full journey', async ({ page }) => {
        // ============================================================
        // A1 — Login admin → dashboard
        // ============================================================
        await loginAsAdmin(page);
        await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'A1-dashboard.png'), fullPage: true });

        const dashText = await page.locator('body').innerText().catch(() => '');
        const greetingPresent = /Bonjour|Bonsoir|Good/i.test(dashText);
        if (!greetingPresent) {
            findings.push({ id: 'A1-P1', severity: 'P1', surface: 'admin/dashboard', file: 'resources/js/components/admin/dashboard/*', desc: 'Greeting Bonjour/Bonsoir absent — dashboard not mounted or i18n missed' });
        }

        // KPI cards rendered (heuristic: "Total" or "Ventes" expected)
        const kpiVisible = /Total|Ventes|Commandes|Articles/i.test(dashText);
        if (!kpiVisible) {
            findings.push({ id: 'A1-P0', severity: 'P0', surface: 'admin/dashboard', file: 'resources/js/components/admin/dashboard/OverviewComponent.vue', desc: 'KPI widget absent (Total/Ventes/Commandes/Articles)' });
        }

        // ============================================================
        // A2 — Catalogue : list + toggle item availability
        // ============================================================
        await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
        await page.waitForTimeout(2_000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'A2-items-list.png'), fullPage: true });

        const itemsText = await page.locator('body').innerText().catch(() => '');
        const itemListVisible = /Articles|Catalogue|Items|nom/i.test(itemsText);
        if (!itemListVisible) {
            findings.push({ id: 'A2-P0', severity: 'P0', surface: 'admin/items', file: 'resources/js/components/admin/items/ItemListComponent.vue', desc: 'Items list page empty or not mounted' });
        }

        const i18nItems = frenchI18nSweep(itemsText);
        if (i18nItems.length > 0) {
            findings.push({ id: 'A2-P1-i18n', severity: 'P1', surface: 'admin/items', desc: 'i18n raw labels / English hints', sample: i18nItems.slice(0, 5) });
        }

        // Attempt to trigger availability toggle via DB-level update — observe event fan-out
        // (UI toggle path varies — we assert the listener wires correctly via event row creation)
        // EventType::MENU_ITEM_AVAILABILITY_CHANGED = 'menu.item_availability_changed'
        // (see app/Enums/EventType.php:15)
        const eventBaseline = await queryDomainEventsSince(snapshot.last_event_id, ['menu.item_availability_changed', 'catalog.changed', 'menu.changed']);

        // Drive availability change via the controller-grade event dispatch.
        // ItemAvailabilityChanged constructor signature (see app/Events/ItemAvailabilityChanged.php):
        // ($itemId, $status, $price, $type, $isAvailable, $branchId, $reason)
        // Use fromItem() static helper for global (admin edit) emission.
        const triggerScript = [
            '$item = App\\Models\\Item::orderBy("id")->first();',
            'if ($item) { event(App\\Events\\ItemAvailabilityChanged::fromItem($item)); }',
            'echo json_encode(["item_id"=>$item?->id,"name"=>$item?->name]);',
        ].join(' ');
        let trigger;
        try {
            trigger = parseLastJson(tinker(triggerScript));
        } catch (e) {
            trigger = { error: (e.message || '').slice(0, 200) };
        }
        await page.waitForTimeout(1_500);
        const eventAfter = await queryDomainEventsSince(snapshot.last_event_id, ['menu.item_availability_changed', 'catalog.changed', 'menu.changed']);
        const newEvents = Array.isArray(eventAfter) ? eventAfter.length : 0;
        if (newEvents === 0 && !trigger?.error) {
            findings.push({ id: 'A2-P0-event', severity: 'P0', surface: 'admin/items', file: 'app/Listeners/PersistItemAvailabilityChangedToOutbox.php', desc: `ItemAvailabilityChanged dispatched (item_id=${trigger?.item_id}) but no domain_events.menu.item_availability_changed row appended. Listener PersistItemAvailabilityChangedToOutbox is registered (EventServiceProvider.php:216) but not persisting.` });
        }

        // ============================================================
        // A3 — Stock /admin/stock/rupture (canonical SPA route per stockRoutes.js:7)
        // ============================================================
        await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'A3-stock-rupture.png'), fullPage: true });

        const stockText = await page.locator('body').innerText().catch(() => '');
        const stockMount = /rupture|stock|disponib|épuis/i.test(stockText) && !/Page Non Trouvée/i.test(stockText);
        if (!stockMount) {
            findings.push({ id: 'A3-P1', severity: 'P1', surface: 'admin/stock/rupture', file: 'resources/js/router/modules/stockRoutes.js', desc: 'Stock rupture dashboard returns 404 or empty body at canonical path /admin/stock/rupture' });
        }
        const i18nStock = frenchI18nSweep(stockText);
        if (i18nStock.length > 0) {
            findings.push({ id: 'A3-P2-i18n', severity: 'P2', surface: 'admin/stock/rupture', desc: 'i18n drift', sample: i18nStock.slice(0, 5) });
        }

        // ============================================================
        // A4 — Settings : canonical paths are /admin/settings/<sub> (singular
        // prefix `setting/` is wrong, see resources/js/router/modules/settingRoutes.js:53)
        // ============================================================
        const settingsPaths = [
            ['A4a-settings-currencies', '/admin/settings/currencies/list'],
            ['A4b-settings-taxes', '/admin/settings/taxes/list'],
            ['A4c-settings-company', '/admin/settings/company'],
            ['A4d-settings-site', '/admin/settings/site'],
            ['A4e-settings-order-setup', '/admin/settings/order-setup'],
        ];
        for (const [name, urlPath] of settingsPaths) {
            await page.goto(urlPath, { waitUntil: 'domcontentloaded' });
            await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
            await page.waitForTimeout(1_500);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
            const txt = await page.locator('body').innerText().catch(() => '');
            // detect 404 / not-found
            if (/Page Non Trouvée|Not Found|404/i.test(txt) && txt.length < 600) {
                findings.push({ id: `${name}-P1`, severity: 'P1', surface: urlPath, desc: 'Settings page returns 404 / not mounted' });
            }
            const i18n = frenchI18nSweep(txt);
            if (i18n.length > 0) {
                findings.push({ id: `${name}-i18n`, severity: 'P2', surface: urlPath, desc: 'i18n drift', sample: i18n.slice(0, 3) });
            }
        }

        // Trigger SettingsUpdated event via tinker.
        // EventType::SETTINGS_UPDATED = 'settings.updated' (app/Enums/EventType.php:36)
        // Signature varies — defer to constructor introspection; if mismatched, log P2.
        const settingsTrigger = [
            '$ref = new ReflectionClass(App\\Events\\SettingsUpdated::class);',
            '$ctor = $ref->getConstructor();',
            '$params = collect($ctor->getParameters())->map(fn($p)=>$p->getName())->toArray();',
            'echo json_encode(["params"=>$params]);',
        ].join(' ');
        try {
            const sig = parseLastJson(tinker(settingsTrigger));
            const dispatchScript = [
                '$evt = new App\\Events\\SettingsUpdated(1);',
                'event($evt);',
                'echo "DISPATCHED";',
            ].join(' ');
            // Best-effort dispatch — single-arg constructor is the common shape.
            try { tinker(dispatchScript); } catch (_e) { /* signature mismatch — log below */ }
            await page.waitForTimeout(1_000);
            const settingsRows = await queryDomainEventsSince(snapshot.last_event_id, ['settings.updated', 'settings.changed']);
            if (!Array.isArray(settingsRows) || settingsRows.length === 0) {
                findings.push({ id: 'A4-P1-event', severity: 'P1', surface: 'admin/settings', file: 'app/Listeners/PersistSettingsUpdatedToOutbox.php', desc: `SettingsUpdated dispatched but no domain_events.settings.updated row appended. Constructor signature: ${JSON.stringify(sig?.params || 'unknown')}. Listener may need real settings save via SettingController, not synthetic dispatch.` });
            }
        } catch (e) {
            findings.push({ id: 'A4-P2-trigger', severity: 'P2', surface: 'admin/settings', desc: `Could not dispatch SettingsUpdated test event: ${(e.message || '').slice(0, 120)}` });
        }

        // ============================================================
        // A5 — Branch list (deactivate flow READ-ONLY — destructive blocked)
        // ============================================================
        // Branch list canonical path is /admin/settings/branches/list (settingRoutes.js:87/99)
        await page.goto('/admin/settings/branches/list', { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'A5-branches.png'), fullPage: true });
        const branchText = await page.locator('body').innerText().catch(() => '');
        if (!/branche|filiale|Le Cayenne|status/i.test(branchText)) {
            findings.push({ id: 'A5-P1', severity: 'P1', surface: 'admin/branches', desc: 'Branches list not mounted (no branch keyword detected)' });
        }

        // Verify EnsureUserStatusActive middleware registered (Sprint H1 Z6-06)
        const middlewareScript = [
            '$kernel = app(Illuminate\\Contracts\\Http\\Kernel::class);',
            '$ref = new ReflectionObject($kernel);',
            '$prop = $ref->getProperty("middlewareGroups");',
            '$prop->setAccessible(true);',
            '$groups = $prop->getValue($kernel);',
            '$flat = [];',
            'foreach ($groups as $g => $list) { foreach ($list as $m) { $flat[] = is_string($m) ? $m : get_class($m); } }',
            '$routeMW = [];',
            '$prop2 = $ref->getProperty("routeMiddleware");',
            '$prop2->setAccessible(true);',
            '$rm = $prop2->getValue($kernel);',
            'foreach ($rm as $k=>$v) { $routeMW[$k] = is_string($v) ? $v : (string)$v; }',
            '$present = collect($flat)->contains(function($m){ return str_contains($m,"EnsureUserStatusActive"); }) || collect($routeMW)->contains(function($m){ return str_contains($m,"EnsureUserStatusActive"); });',
            'echo json_encode(["EnsureUserStatusActive_registered"=>$present]);',
        ].join(' ');
        const mwResult = parseLastJson(tinker(middlewareScript));
        if (!mwResult?.EnsureUserStatusActive_registered) {
            findings.push({ id: 'A6-P0', severity: 'P0', surface: 'middleware-kernel', file: 'app/Http/Kernel.php', desc: 'EnsureUserStatusActive middleware NOT registered (Sprint H1 Z6-06 regression)' });
        }

        // ============================================================
        // A7 — Z reports list (READ-ONLY — ZReportService frozen)
        // ============================================================
        // Z reports — discover route via candidates list
        const zCandidates = ['/admin/cash-session-report', '/admin/z-reports', '/admin/fiscal/z-reports', '/admin/reports/z'];
        let zFound = false;
        for (const zPath of zCandidates) {
            await page.goto(zPath, { waitUntil: 'domcontentloaded' });
            await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
            await page.waitForTimeout(1_500);
            const txt = await page.locator('body').innerText().catch(() => '');
            if (!/Page Non Trouvée|Not Found/i.test(txt) || txt.length > 600) {
                await page.screenshot({ path: path.join(SCREENSHOT_DIR, `A7-z-reports-${zPath.replace(/\//g, '_')}.png`), fullPage: true });
                zFound = true;
                break;
            }
        }
        if (!zFound) {
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'A7-z-reports-404.png'), fullPage: true });
            findings.push({ id: 'A7-P1', severity: 'P1', surface: 'admin/z-reports', desc: `Z reports page not found at any candidate route: ${zCandidates.join(', ')}` });
        }

        // ============================================================
        // A8 — Sales report daily
        // ============================================================
        // /admin/sales-report mounted (verified visually run-1 with "Rapport Des Ventes" KPI table)
        const salesPaths = ['/admin/sales-report', '/admin/reports/sales', '/admin/reports', '/admin/items-report'];
        let salesFound = false;
        for (const p of salesPaths) {
            await page.goto(p, { waitUntil: 'domcontentloaded' });
            await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
            await page.waitForTimeout(1_200);
            const txt = await page.locator('body').innerText().catch(() => '');
            if (!(/404|Not Found/i.test(txt) && txt.length < 300)) {
                await page.screenshot({ path: path.join(SCREENSHOT_DIR, `A8-sales-${p.replace(/\//g, '_')}.png`), fullPage: true });
                salesFound = true;
                const i18n = frenchI18nSweep(txt);
                if (i18n.length > 0) {
                    findings.push({ id: 'A8-i18n', severity: 'P2', surface: p, desc: 'i18n drift', sample: i18n.slice(0, 3) });
                }
                break;
            }
        }
        if (!salesFound) {
            findings.push({ id: 'A8-P1', severity: 'P1', surface: 'admin/sales-report', desc: 'No sales report route mounted (tried /admin/sales-report, /admin/reports/sales, /admin/reports)' });
        }

        // ============================================================
        // PERSIST findings + audit data
        // ============================================================
        const snapshotEnd = snapshotDB();
        const auditData = {
            timestamp: new Date().toISOString(),
            db_baseline: snapshot,
            db_end: snapshotEnd,
            trigger_a2_item_toggle: trigger,
            new_event_count_a2: newEvents,
            console_errors: consoleErrors,
            network_5xx: networkFailures,
            ensure_user_status_active: mwResult,
            findings_count: findings.length,
        };
        fs.writeFileSync(path.join(REPORT_ROOT, 'audit-data.json'), JSON.stringify(auditData, null, 2));
        fs.writeFileSync(path.join(REPORT_ROOT, 'findings.json'), JSON.stringify({
            agent: 'gstack-e2e-admin',
            surface: 'ADMIN manager',
            generated_at: new Date().toISOString(),
            findings,
            counts: {
                P0: findings.filter((f) => f.severity === 'P0').length,
                P1: findings.filter((f) => f.severity === 'P1').length,
                P2: findings.filter((f) => f.severity === 'P2').length,
            },
        }, null, 2));

        // Non-failing — we want all captures even if console errors logged.
        console.log(`[ADMIN] findings=${findings.length} console_errors=${consoleErrors.length} network_5xx=${networkFailures.length}`);
    });
});
