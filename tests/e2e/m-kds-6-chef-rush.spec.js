// M-KDS-6 — Chef-rush empirical capture at N=5/6/7/8/9/12 orders, viewport 1920x1080.
// Uses pre-seeded orders (run seed-orders.php between tests via exec).
// Captures screenshots + DOM measurements + writes per-N JSON cell to the matrix.

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const OUT_DIR = path.resolve(__dirname, '../../reports/test-e2e/goal-2026-05-23/phase-m/M-KDS-6-captures');
const MATRIX_PATH = path.join(OUT_DIR, 'matrix.json');
const VIEWPORT = { width: 1920, height: 1080 };

// Make sure output dir exists
fs.mkdirSync(OUT_DIR, { recursive: true });

// Load (or init) matrix
function loadMatrix() {
    if (fs.existsSync(MATRIX_PATH)) {
        try { return JSON.parse(fs.readFileSync(MATRIX_PATH, 'utf8')); } catch (_e) { /* fall through */ }
    }
    return { cells: {} };
}
function saveMatrix(m) {
    fs.writeFileSync(MATRIX_PATH, JSON.stringify(m, null, 2));
}

function seedOrders(n, depth) {
    const cmd = `php artisan tinker --execute="require 'reports/test-e2e/goal-2026-05-23/phase-m/M-KDS-6-captures/seed-orders.php'; print_r(seed_kds(${n}, '${depth}'));"`;
    const out = execSync(cmd, { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    return out;
}

async function loginAsAdmin(page) {
    await page.goto('/login');
    // SPA Vue form — fields don't have type=email/password, use label/placeholder
    await page.waitForSelector('input', { timeout: 10000 });
    const allInputs = await page.$$('input');
    // First two visible non-checkbox inputs = email + password
    let emailIn = null, passIn = null;
    for (const inp of allInputs) {
        const t = await inp.getAttribute('type');
        if (t === 'checkbox' || t === 'hidden') continue;
        if (!emailIn) emailIn = inp;
        else if (!passIn) { passIn = inp; break; }
    }
    if (!emailIn || !passIn) {
        throw new Error('login form inputs not found');
    }
    await emailIn.fill('admin@lecayenne.fr');
    await passIn.fill('123456');
    // Submit via Enter (button text varies; safer than CSS)
    await passIn.press('Enter');
    // Wait for redirect away from /login
    await page.waitForURL((url) => !String(url).includes('/login'), { timeout: 20000 }).catch(() => {});
}

async function captureKdsState(page, n, depth, label) {
    // Navigate to the actual KDS route (Vue router; the /kds redirect lives client-side
    // but goto resolves to the final URL — bypass any flake by going direct).
    await page.goto('/admin/kitchen-display-system?v2=1');
    // Wait for grid OR empty state (the .kds-v2__* selectors live inside the V2 branch)
    try {
        await page.waitForSelector('.kds-v2__grid, .kds-v2__empty, .kds-v2', { timeout: 20000 });
    } catch (_e) {
        // V2 not enabled or routing went elsewhere — try without v2 flag
        await page.goto('/admin/kitchen-display-system');
        await page.waitForSelector('.kds-v2__grid, .kds-v2__empty, .kds-v2, .kds-board, .kds', { timeout: 20000 });
    }
    // Wait for at least one card OR confirm empty
    await page.waitForTimeout(3000); // settle data-fetch + render

    const dom = await page.evaluate(() => {
        const viewport = { w: window.innerWidth, h: window.innerHeight };
        const grid = document.querySelector('.kds-v2__grid');
        const empty = document.querySelector('.kds-v2__empty');
        const cards = Array.from(document.querySelectorAll('.kds-card'));
        const ctas = Array.from(document.querySelectorAll('.kds-card__cta, .kds-card__action-btn, button.kds-card__cta'));
        // Fallback: every CTA-like button inside a card
        const ctaButtons = cards.map((c) => {
            const b = c.querySelector('button');
            return b ? { text: (b.textContent || '').trim().slice(0, 40), bottom: b.getBoundingClientRect().bottom, top: b.getBoundingClientRect().top } : null;
        });
        return {
            viewport,
            page_scroll: {
                scrollHeight: document.documentElement.scrollHeight,
                clientHeight: document.documentElement.clientHeight,
                overflows: document.documentElement.scrollHeight > document.documentElement.clientHeight + 1,
            },
            grid: grid ? {
                top: grid.getBoundingClientRect().top,
                bottom: grid.getBoundingClientRect().bottom,
                height: grid.getBoundingClientRect().height,
                computed_cols: getComputedStyle(grid).gridTemplateColumns,
                computed_rows: getComputedStyle(grid).gridTemplateRows,
            } : null,
            empty_state_present: !!empty,
            cards_rendered: cards.length,
            cta_buttons: ctaButtons,
            ctas_below_fold: ctaButtons.filter((b) => b && b.bottom > viewport.h).length,
            ctas_above_fold: ctaButtons.filter((b) => b && b.bottom <= viewport.h).length,
            cards_with_inner_scroll: cards.map((c) => {
                const body = c.querySelector('.kds-card__body, .kds-card__items, [class*="body"]');
                if (!body) return null;
                return body.scrollHeight > body.clientHeight + 1;
            }).filter((v) => v === true).length,
            overflow_chip_present: !!document.querySelector('[class*="overflow"], [class*="pending-chip"], [class*="kds-overflow"]'),
            cards_summary: cards.slice(0, 15).map((c) => {
                const rect = c.getBoundingClientRect();
                return {
                    top: Math.round(rect.top),
                    bottom: Math.round(rect.bottom),
                    height: Math.round(rect.height),
                    text_first_80: (c.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 80),
                };
            }),
        };
    });

    const screenshotPath = path.join(OUT_DIR, `kds-N${n}-${depth}-${label}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: false });

    const matrix = loadMatrix();
    matrix.cells[`N${n}_${depth}`] = {
        n,
        depth,
        label,
        viewport: VIEWPORT,
        screenshot: path.relative(path.resolve(__dirname, '../..'), screenshotPath),
        timestamp_iso: new Date().toISOString(),
        dom,
    };
    saveMatrix(matrix);

    return dom;
}

// NOTE: not using serial mode — we want every scenario to run even if one fails,
// because each cell of the matrix is independently informative.

test.describe('M-KDS-6 chef-rush empirical capture', () => {
    test.use({ viewport: VIEWPORT });
    // Bound each test to 2 minutes — login(20s) + seed(10s) + render(30s) + measure(20s)
    test.setTimeout(120_000);

    test.beforeAll(() => {
        // Reset matrix at start
        saveMatrix({ cells: {}, viewport: VIEWPORT, started_at_iso: new Date().toISOString() });
    });

    const scenarios = [
        { n: 5, depth: 'short' },
        { n: 6, depth: 'short' },
        { n: 7, depth: 'short' },
        { n: 8, depth: 'short' },
        { n: 9, depth: 'short' },
        { n: 12, depth: 'short' },
        // Realistic chef-rush content (the scenario the proposal warns about)
        { n: 5, depth: 'realistic' },
        { n: 6, depth: 'realistic' },
        { n: 8, depth: 'realistic' },
        { n: 5, depth: 'long' },
        { n: 8, depth: 'long' },
    ];

    for (const sc of scenarios) {
        test(`N=${sc.n} depth=${sc.depth}`, async ({ page }) => {
            seedOrders(sc.n, sc.depth);
            await loginAsAdmin(page);
            const dom = await captureKdsState(page, sc.n, sc.depth, `${VIEWPORT.width}x${VIEWPORT.height}`);
            // Soft assertions — we ARE the assertor, no hard expect to avoid stopping the matrix
            console.log(`[M-KDS-6] N=${sc.n} depth=${sc.depth}: cards=${dom.cards_rendered}, ctas_below=${dom.ctas_below_fold}, page_overflow=${dom.page_scroll.overflows}, inner_scroll_count=${dom.cards_with_inner_scroll}, chip=${dom.overflow_chip_present}, cols=${dom.grid?.computed_cols}`);
        });
    }
});
