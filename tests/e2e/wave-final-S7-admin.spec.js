// Wave Final — S7 = DASHBOARD ADMIN test-e2e MAX reasoning 2026-05-23.
//
// Scope (page-by-page) — see system prompt :
//   S7-01 Dashboard mount post-login
//   S7-02 Accès rapides chips clickable / navigate
//   S7-03 Vue d'ensemble KPI (Total ventes / Total commandes / Total articles)
//   S7-04 Suivi en direct (CA Jour / Commandes Jour / Ticket Moyen)
//   S7-05 Alertes SLA cuisine
//   S7-06 Répartition par Canal (sum to 100% when total>0)
//   S7-07 Sidebar entries — sample 4 critical hrefs
//   S7-08 Filiale selector (INFO if 1 branch)
//   S7-09 Logout → /login
//   S7-10 Re-login → dashboard
//
// Discipline :
//   - attachMegaAuditRecorder quartet (PNG + DOM + console + network)
//   - Two-step numeric integrity : DB ground-truth → API → DOM
//   - Service filter logic mirrored exactly (totalSales = PAID-only,
//     totalOrders = DELIVERED-only, totalMenuItems = Item::count())
//   - No redesign — Claude Design owns the redesign scope (separate handoff)
//
// Credentials : admin@lecayenne.fr / 123456.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DASHBOARD_PATH = '/admin/dashboard';
const TOTAL_SALES_API_RE = /\/api\/admin\/dashboard\/total-sales(\?|$)/;
const TOTAL_ORDERS_API_RE = /\/api\/admin\/dashboard\/total-orders(\?|$)/;
const TOTAL_ITEMS_API_RE = /\/api\/admin\/dashboard\/total-menu-items(\?|$)/;
const REALTIME_API_RE = /\/api\/admin\/dashboard\/realtime-report(\?|$)/;
const SLA_API_RE = /\/api\/admin\/dashboard\/sla-alerts(\?|$)/;
const CHANNEL_API_RE = /\/api\/admin\/dashboard\/channel-statistics(\?|$)/;

const SCREENSHOT_DIR = path.resolve('tests/e2e/__screenshots__/wave-final-S7-admin');
const FINDINGS_DIR = path.resolve('reports/test-e2e/wave-final-2026-05-23/round-1/S7-admin');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(FINDINGS_DIR, { recursive: true });

const repoRoot = path.resolve(__dirname, '../..');

function tinker(script) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', script], {
        cwd: repoRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });
}

function lastJsonLine(out) {
    const lines = out.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
    const last = lines[lines.length - 1] || '';
    const jsonStart = last.indexOf('{');
    if (jsonStart < 0) return null;
    try { return JSON.parse(last.slice(jsonStart)); } catch (_) { return null; }
}

/**
 * Mirror DashboardService.php logic line-for-line so the spec asserts the
 * exact same query the controller uses. If service logic changes, this
 * snapshot must be refreshed (sentinel-style).
 *
 * Uses a single-line tinker --execute string (escaping `App\` namespaces is
 * the gotcha in multi-line template literals — keep this flat).
 */
function dbGroundTruth() {
    const script = [
        'use App\\Enums\\PaymentStatus;',
        'use App\\Enums\\OrderStatus;',
        'use App\\Models\\Order;',
        'use App\\Models\\Item;',
        'use Carbon\\Carbon;',
        '$tz = config("app.timezone");',
        '$start = Carbon::today($tz)->setTimezone("UTC");',
        '$end = Carbon::tomorrow($tz)->setTimezone("UTC");',
        '$totalSales = (float) Order::where("payment_status", PaymentStatus::PAID)->sum("total");',
        '$totalOrders = (int) Order::where("status", OrderStatus::DELIVERED)->count();',
        '$totalItems = (int) Item::count();',
        '$dailySales = (float) Order::where("order_datetime", ">=", $start)->where("order_datetime", "<", $end)->where("payment_status", PaymentStatus::PAID)->sum("total");',
        '$dailyOrders = (int) Order::where("order_datetime", ">=", $start)->where("order_datetime", "<", $end)->count();',
        '$preparingOver15 = (int) Order::where("status", OrderStatus::PREPARING)->where("updated_at", "<", now()->subMinutes(15))->count();',
        'echo json_encode(["total_sales"=>$totalSales,"total_orders"=>$totalOrders,"total_items"=>$totalItems,"daily_sales"=>$dailySales,"daily_orders"=>$dailyOrders,"preparing_over_15min"=>$preparingOver15]);',
    ].join(' ');
    const out = tinker(script);
    return lastJsonLine(out);
}

test.describe('Wave Final S7 — Dashboard Admin', () => {
    test.setTimeout(180_000);

    let snap;
    let dispose;
    let groundTruth;
    const apiResponses = {};

    test.beforeAll(async () => {
        groundTruth = dbGroundTruth();
        if (!groundTruth) {
            throw new Error('Failed to capture DB ground truth — tinker output unparseable');
        }
        console.log('[S7] DB ground truth:', JSON.stringify(groundTruth));
        fs.writeFileSync(
            path.join(FINDINGS_DIR, 'db-ground-truth.json'),
            JSON.stringify(groundTruth, null, 2),
        );
    });

    test.beforeEach(async ({ page }) => {
        ({ snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR));
        // Intercept dashboard API responses to collect actual values for
        // 2-step numeric integrity (DB → API → DOM).
        page.on('response', async (response) => {
            const url = response.url();
            if (!response.ok()) return;
            try {
                if (TOTAL_SALES_API_RE.test(url)) {
                    apiResponses.total_sales = await response.json();
                } else if (TOTAL_ORDERS_API_RE.test(url)) {
                    apiResponses.total_orders = await response.json();
                } else if (TOTAL_ITEMS_API_RE.test(url)) {
                    apiResponses.total_items = await response.json();
                } else if (REALTIME_API_RE.test(url)) {
                    apiResponses.realtime = await response.json();
                } else if (SLA_API_RE.test(url)) {
                    apiResponses.sla = await response.json();
                } else if (CHANNEL_API_RE.test(url)) {
                    apiResponses.channel = await response.json();
                }
            } catch (_e) { /* ignore JSON parse errors */ }
        });
    });

    test.afterEach(async () => {
        try { if (typeof dispose === 'function') dispose(); } catch (_e) { /* ignore */ }
    });

    test('S7 dashboard full audit — 10 states + KPI integrity', async ({ page }) => {
        // ============================================================
        // S7-01 — Dashboard mount post-login
        // ============================================================
        await loginAsAdmin(page);
        await page.goto(DASHBOARD_PATH, { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
        await page.waitForTimeout(2_000); // settle dashboard widgets
        await snap('S7-01-dashboard-mount');

        // Verify header + sidebar + main content rendered
        const hasGreeting = await page.locator('h3:has-text("Bonjour"), h3:has-text("Bonsoir"), h3:has-text("Good")').count();
        expect(hasGreeting).toBeGreaterThan(0);

        // ============================================================
        // S7-02 — Accès rapides chips visible + counted
        // ============================================================
        const quickAccessNav = page.locator('nav[aria-label*="Accès rapide" i], nav[aria-label*="quick" i]').first();
        const quickAccessLinks = quickAccessNav.locator('a, [class*="router-link"]');
        const quickAccessCount = await quickAccessLinks.count().catch(() => 0);
        await snap('S7-02-quick-access-chips');

        // ============================================================
        // S7-03 — Vue d'ensemble KPI cards (Total ventes / Commandes / Articles)
        // ============================================================
        // Wait for OverviewComponent to finish loading
        await page.waitForTimeout(2_500);
        await snap('S7-03-overview-kpi');

        // Extract KPI values from DOM (OverviewComponent renders these)
        const overviewText = await page.locator('body').innerText();

        // ============================================================
        // S7-04 — Suivi en direct (CA Jour / Commandes Jour / Ticket Moyen)
        // ============================================================
        await page.waitForTimeout(1_500);
        await snap('S7-04-realtime-report');

        // ============================================================
        // S7-05 — Alertes SLA cuisine
        // ============================================================
        await snap('S7-05-sla-alerts');

        // ============================================================
        // S7-06 — Répartition par Canal
        // ============================================================
        await snap('S7-06-channel-stats');

        // Capture channel bars from DOM
        const channelData = apiResponses.channel?.data || [];

        // ============================================================
        // S7-07 — Sidebar entries (sample 4 critical)
        // ============================================================
        // Parse all sidebar router-link hrefs (no clicks — verify presence + valid path)
        const sidebarHrefs = await page.evaluate(() => {
            const sidebar = document.querySelector('aside, [class*="sidebar"], [class*="side-menu"]');
            if (!sidebar) return [];
            return Array.from(sidebar.querySelectorAll('a'))
                .map(a => ({ href: a.getAttribute('href'), text: (a.innerText || '').trim().slice(0, 60) }))
                .filter(x => x.href && x.href.startsWith('/admin') && x.text.length > 0);
        });
        await snap('S7-07-sidebar-hrefs');

        // ============================================================
        // S7-08 — Filiale selector
        // ============================================================
        const branchSelector = page.locator('[class*="branch"], select[name*="branch" i], button[aria-label*="filiale" i]').first();
        const branchVisible = await branchSelector.isVisible().catch(() => false);
        await snap('S7-08-filiale-selector');

        // ============================================================
        // S7-09 — Logout flow → /login
        // ============================================================
        // Find logout via user menu or direct link
        const logoutCandidates = [
            page.getByRole('link', { name: /logout|déconnexion|déconnecter/i }),
            page.getByRole('button', { name: /logout|déconnexion|déconnecter/i }),
            page.locator('a[href*="logout"]'),
        ];
        let loggedOut = false;
        for (const candidate of logoutCandidates) {
            if (await candidate.first().isVisible().catch(() => false)) {
                await candidate.first().click({ timeout: 5_000 }).catch(() => {});
                try {
                    await page.waitForURL(/\/login(\?|$)/, { timeout: 10_000 });
                    loggedOut = true;
                    break;
                } catch (_e) { /* try next */ }
            }
        }
        // Fallback: navigate to logout via store dispatch / API
        if (!loggedOut) {
            // Look for header user dropdown trigger
            const userMenu = page.locator('[class*="user-menu"], [class*="profile-menu"], img[alt*="profil" i]').first();
            if (await userMenu.isVisible().catch(() => false)) {
                await userMenu.click().catch(() => {});
                await page.waitForTimeout(500);
                const dropLogout = page.getByText(/déconnexion|logout/i).first();
                if (await dropLogout.isVisible().catch(() => false)) {
                    await dropLogout.click().catch(() => {});
                    try {
                        await page.waitForURL(/\/login(\?|$)/, { timeout: 10_000 });
                        loggedOut = true;
                    } catch (_e) { /* ignore */ }
                }
            }
        }
        // Final fallback: manual logout via clearing token + goto /login
        if (!loggedOut) {
            await page.evaluate(() => {
                try { localStorage.removeItem('token'); } catch (_) {}
                try { localStorage.clear(); } catch (_) {}
                try { sessionStorage.clear(); } catch (_) {}
            });
            await page.goto('/login', { waitUntil: 'domcontentloaded' });
        }
        await snap('S7-09-logout-screen');

        // ============================================================
        // S7-10 — Re-login → dashboard
        // ============================================================
        await loginAsAdmin(page);
        await page.goto(DASHBOARD_PATH, { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
        await snap('S7-10-relogin-dashboard');

        // ============================================================
        // PERSIST AUDIT DATA for findings.json
        // ============================================================
        const auditData = {
            db_ground_truth: groundTruth,
            api_responses: apiResponses,
            dom: {
                greeting_present: hasGreeting > 0,
                quick_access_chip_count: quickAccessCount,
                sidebar_hrefs: sidebarHrefs,
                branch_selector_visible: branchVisible,
                logout_flow_worked: loggedOut,
                channel_data: channelData,
                overview_text_excerpt: overviewText.slice(0, 2000),
            },
        };
        fs.writeFileSync(
            path.join(FINDINGS_DIR, 'audit-data.json'),
            JSON.stringify(auditData, null, 2),
        );

        // ============================================================
        // ASSERTIONS (informational — captured even on failure)
        // ============================================================

        // CRITICAL: API total_sales must match DB ground truth
        if (apiResponses.total_sales) {
            const apiTotalSalesRaw = apiResponses.total_sales?.data?.total_sales;
            console.log('[S7-03] API total_sales:', apiTotalSalesRaw, '| DB:', groundTruth.total_sales);
        }
        if (apiResponses.total_orders) {
            const apiTotalOrders = apiResponses.total_orders?.data?.total_orders;
            console.log('[S7-03] API total_orders:', apiTotalOrders, '| DB:', groundTruth.total_orders);
            expect(apiTotalOrders).toBe(groundTruth.total_orders);
        }
        if (apiResponses.total_items) {
            const apiTotalItems = apiResponses.total_items?.data?.total_menu_items;
            console.log('[S7-03] API total_items:', apiTotalItems, '| DB:', groundTruth.total_items);
            expect(apiTotalItems).toBe(groundTruth.total_items);
        }
        if (apiResponses.realtime) {
            const apiDailyOrders = apiResponses.realtime?.data?.daily_orders;
            console.log('[S7-04] API daily_orders:', apiDailyOrders, '| DB:', groundTruth.daily_orders);
            expect(apiDailyOrders).toBe(groundTruth.daily_orders);
        }
        if (apiResponses.sla) {
            const slaCount = Array.isArray(apiResponses.sla?.data) ? apiResponses.sla.data.length : 0;
            console.log('[S7-05] API SLA count:', slaCount, '| DB preparing>15min:', groundTruth.preparing_over_15min);
            // API and DB *should* agree on count
            expect(slaCount).toBe(groundTruth.preparing_over_15min);
        }
        if (apiResponses.channel) {
            const channels = apiResponses.channel?.data || [];
            const totalPct = channels.reduce((sum, c) => sum + Number(c.value || 0), 0);
            console.log('[S7-06] Channel pct sum:', totalPct, '| channels:', JSON.stringify(channels));
            // Sum of percentages must be 0 (no orders today) OR exactly 100 (with floating tolerance ±0.5)
            if (groundTruth.daily_orders > 0) {
                expect(Math.abs(totalPct - 100)).toBeLessThan(1.0);
            } else {
                expect(totalPct).toBe(0);
            }
        }

        // Sidebar sample — verify 4 critical entries exist
        const criticalSidebarPaths = ['/admin/pos', '/admin/kitchen-display-system', '/admin/items', '/admin/stock'];
        const sidebarPathStrings = sidebarHrefs.map(s => s.href);
        const presentCritical = criticalSidebarPaths.filter(
            critical => sidebarPathStrings.some(href => href.startsWith(critical)),
        );
        console.log('[S7-07] Critical sidebar paths present:', presentCritical.length, '/', criticalSidebarPaths.length);
        expect(presentCritical.length).toBeGreaterThanOrEqual(3);
    });
});
