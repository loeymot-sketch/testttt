// [RED-Visual C OSS — Round 3 2026-05-18] Visual gate spec.
// Surfaces:
//   1. /admin/order-status-screen authenticated as admin @ 1920x1080
//   2. /admin/order-status-screen with seeded orders (best-effort via existing DB)
//   3. /oss-order public mode (no auth) @ 1920x1080
//   4. PRÊT badge close-up @ 1920x1080 (zoomed on ready column)
// Goal: Read screenshots + analyze (Layout, raw labels, contrast).
// READ-ONLY: only captures + console listeners.

const { test, expect } = require('@playwright/test');

const OUT = '/tmp/foodking-goal-round3/red-c-oss';
const ADMIN_USER = 'admin@lecayenne.fr';
const ADMIN_PASS = '123456';
const BASE = 'http://127.0.0.1:8000';

test.use({ viewport: { width: 1920, height: 1080 } });

// Capture console messages for chime/audio related warnings
function attachConsole(page, label) {
    const messages = [];
    page.on('console', (msg) => {
        const text = msg.text();
        if (/audio|chime|autoplay|AudioContext|playReady|pointerdown/i.test(text)) {
            messages.push(`[${label}] ${msg.type()}: ${text}`);
        }
    });
    page.on('pageerror', (err) => {
        messages.push(`[${label}] pageerror: ${err.message}`);
    });
    return messages;
}

async function loginAdmin(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    // The admin login uses email/password
    await page.fill('input[name="email"], input[type="email"]', ADMIN_USER);
    await page.fill('input[name="password"], input[type="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {}),
        page.click('button[type="submit"]'),
    ]);
    // After login, give app a moment to settle.
    await page.waitForTimeout(2000);
}

test('A1 operator OSS authenticated 1920x1080', async ({ page }) => {
    const msgs = attachConsole(page, 'A1-OPERATOR');
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/order-status-screen`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000); // Let Vue mount + Vuex hydrate + initial poll
    await page.screenshot({ path: `${OUT}/A1-operator-oss-1920x1080.png`, fullPage: false });
    console.log('A1_CONSOLE:', JSON.stringify(msgs));
});

test('A2 operator OSS with realistic state (post-login)', async ({ page }) => {
    const msgs = attachConsole(page, 'A2-OPERATOR-STATE');
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/order-status-screen`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000); // Longer settle to let polling fetch any seeded data
    // Inject branchId probe + dump UI state into console
    const probe = await page.evaluate(() => {
        const cols = document.querySelectorAll('[role="region"]');
        const prepared = document.querySelectorAll('.text-\\[\\#0E7C3A\\]');
        const baseline = document.querySelectorAll('.text-\\[\\#2AC769\\]');
        return {
            cols: cols.length,
            preparedItemsApplied: prepared.length,
            baselineLeaked: baseline.length,
            html: document.body.innerHTML.length,
            authBranchIdGuess: window.foodkingConfig?.authBranchId ?? 'unknown',
        };
    });
    console.log('A2_PROBE:', JSON.stringify(probe));
    await page.screenshot({ path: `${OUT}/A2-operator-oss-state-1920x1080.png`, fullPage: false });
    console.log('A2_CONSOLE:', JSON.stringify(msgs));
});

test('B1 public OSS unauthenticated TV wall 1920x1080', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    const msgs = attachConsole(page, 'B1-PUBLIC');
    // Visit public OSS endpoint as anonymous (no login, no cookies)
    await page.goto(`${BASE}/oss-order`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);
    const probe = await page.evaluate(() => {
        const cols = document.querySelectorAll('[role="region"]');
        const prepared = document.querySelectorAll('.text-\\[\\#0E7C3A\\]');
        // Verify NO pointerdown listener attached (heuristic via DOM check)
        return {
            cols: cols.length,
            preparedItemsApplied: prepared.length,
            authIndicator: !!document.cookie.match(/laravel_session|XSRF/),
        };
    });
    console.log('B1_PROBE:', JSON.stringify(probe));
    await page.screenshot({ path: `${OUT}/B1-public-oss-1920x1080.png`, fullPage: false });
    console.log('B1_CONSOLE:', JSON.stringify(msgs));
    await ctx.close();
});

test('C1 PRÊT badge close-up contrast verification', async ({ page }) => {
    const msgs = attachConsole(page, 'C1-CONTRAST');
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/order-status-screen`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);
    // Find the PRÊT column (second [role="region"]) and screenshot it
    const readyColumn = await page.locator('[role="region"]').nth(1).boundingBox();
    if (readyColumn) {
        await page.screenshot({
            path: `${OUT}/C1-pret-column-closeup.png`,
            clip: readyColumn,
        });
    } else {
        await page.screenshot({ path: `${OUT}/C1-pret-column-closeup.png` });
    }
    // Live computed-style verification of any text in PRÊT column
    const computed = await page.evaluate(() => {
        // Find any element in second region with the green color class
        const region = document.querySelectorAll('[role="region"]')[1];
        if (!region) return { error: 'No second region found' };
        // Find a styled <li>
        const li = region.querySelector('ul li, [class*="text-[#"]');
        if (!li) return { region: 'found', li: 'none', empty: region.textContent?.trim() };
        const style = getComputedStyle(li);
        return {
            color: style.color,
            background: style.backgroundColor,
            fontSize: style.fontSize,
            fontWeight: style.fontWeight,
            text: li.textContent?.trim(),
        };
    });
    console.log('C1_COMPUTED:', JSON.stringify(computed));
    console.log('C1_CONSOLE:', JSON.stringify(msgs));
});
