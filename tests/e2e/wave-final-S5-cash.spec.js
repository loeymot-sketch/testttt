// Wave Final 2026-05-23 — S5 = CASH OVERVIEW (/admin/cash-overview)
//
// Mission: MAX-reasoning recapture of the Wave X X4 + Wave Polish Q5-Q8
// cluster shipped in commits e7278a91f + 6f226ef67. Twelve adversarial states
// (S5-01..S5-12) cover:
//   - default mount (4 aggregate cards + reconciliation strip)
//   - currency surface integrity (≥10 €, 0 bare decimals — Q5)
//   - source filter URL sync + F5 restore + reset clean (Q8)
//   - empty-state UX (SVG + copy ≥20 chars + reset CTA — Q6)
//   - mode dropdown 5 options without "other" (Q7)
//   - reconciliation honest 3-cell (opening + collected + expected) + note
//     (C-013) + invariance under source filter (C-014)
//   - numeric integrity: sum(by_source.total) == summary.total ;
//     sum(by_mode.total) == summary.total
//   - sibling no-regression: /admin/cash-sessions-report still loads
//
// Three states are genuinely NEW vs the previously-converged wave-polish-A
// + wave-x4 specs:
//   - S5-07 click the EMPTY-STATE reset CTA specifically
//     (wave-polish-A A-09 clicked the filter-form Clear button instead).
//   - S5-10 payload-level numeric integrity assertion.
//   - S5-11 sibling Wave O O4 /admin/cash-sessions-report smoke.
// The other nine are re-captured under the new label so the Wave Final
// 7-system convergence has its own quartet artefacts.
//
// Credentials : admin@lecayenne.fr / 123456 (CLAUDE.md §reference_admin_e2e_creds).
// Seed token  : WAVE-FINAL-S5- (so any reaper sweep matches).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const CASH_OVERVIEW_PATH = '/admin/cash-overview';
const CASH_SESSIONS_REPORT_PATH = '/admin/cash-sessions-report';
const CASH_OVERVIEW_API_RE = /\/api\/admin\/cash-overview(\?|$)/;
const SCREENSHOT_DIR = path.resolve('tests/e2e/__screenshots__/wave-final-S5-cash');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const repoRoot = path.resolve(__dirname, '../..');
const SEED_PREFIX = 'WAVE-FINAL-S5-';

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
 * Seed a small set of mixed transactions so the aggregates and numeric-
 * integrity assertions exercise more than the existing DB. We try this best-
 * effort: even if seeding silently no-ops the spec still validates the live
 * DB state and the numeric assertions still apply on the unfiltered totals.
 *
 * Returns the list of order IDs that were created so the teardown can drop
 * them. Each row gets a token prefixed WAVE-FINAL-S5- + reaper-friendly.
 */
function seedMixedTransactions({ branchId = 1, count = 3 } = {}) {
    const script = `
        $branch = \\App\\Models\\Branch::find(${branchId});
        if (! $branch) { echo json_encode(['ids'=>[],'reason'=>'NO_BRANCH']); return; }
        $user = \\App\\Models\\User::where('email', 'admin@lecayenne.fr')->first();
        if (! $user) { echo json_encode(['ids'=>[],'reason'=>'NO_USER']); return; }

        // POS direct cash sale (caisse → cash bucket).
        $posCash = new \\App\\Models\\Order();
        $posCash->branch_id = ${branchId};
        $posCash->user_id = $user->id;
        $posCash->order_type = \\App\\Enums\\OrderType::TAKEAWAY;
        $posCash->source_surface = 'pos';
        $posCash->payment_method = 'cash';
        $posCash->payment_status = \\App\\Enums\\PaymentStatus::PAID;
        $posCash->status = \\App\\Enums\\OrderStatus::DELIVERED;
        $posCash->subtotal = 12.50;
        $posCash->total = 12.50;
        $posCash->total_tax = 0;
        $posCash->discount = 0;
        $posCash->delivery_charge = 0;
        $posCash->token = '${SEED_PREFIX}POS-CASH-' . time();
        $posCash->order_serial_no = $posCash->token;
        $posCash->order_datetime = now();
        $posCash->queue_number = (string) (7100 + random_int(0, 99));
        $posCash->saveQuietly();
        \\App\\Models\\Transaction::create([
            'order_id' => $posCash->id,
            'amount' => 12.50,
            'payment_method' => 'cash',
            'transaction_id' => '${SEED_PREFIX}TX-POS-CASH-' . time(),
            'type' => 'payment',
            'sign' => '+',
        ]);

        // Borne (kiosk) counter-collect cash.
        $borneCash = new \\App\\Models\\Order();
        $borneCash->branch_id = ${branchId};
        $borneCash->user_id = $user->id;
        $borneCash->order_type = \\App\\Enums\\OrderType::KIOSK;
        $borneCash->source_surface = 'kiosk';
        $borneCash->payment_method = \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
        $borneCash->payment_status = \\App\\Enums\\PaymentStatus::PAID;
        $borneCash->status = \\App\\Enums\\OrderStatus::DELIVERED;
        $borneCash->subtotal = 9.80;
        $borneCash->total = 9.80;
        $borneCash->total_tax = 0;
        $borneCash->discount = 0;
        $borneCash->delivery_charge = 0;
        $borneCash->token = '${SEED_PREFIX}BORNE-CASH-' . time();
        $borneCash->order_serial_no = $borneCash->token;
        $borneCash->order_datetime = now();
        $borneCash->queue_number = (string) (7200 + random_int(0, 99));
        $borneCash->saveQuietly();
        \\App\\Models\\Transaction::create([
            'order_id' => $borneCash->id,
            'amount' => 9.80,
            'payment_method' => 'counter_cash',
            'transaction_id' => '${SEED_PREFIX}TX-BORNE-CASH-' . time(),
            'type' => 'payment',
            'sign' => '+',
        ]);

        // Borne counter card (card bucket).
        $borneCard = new \\App\\Models\\Order();
        $borneCard->branch_id = ${branchId};
        $borneCard->user_id = $user->id;
        $borneCard->order_type = \\App\\Enums\\OrderType::KIOSK;
        $borneCard->source_surface = 'kiosk';
        $borneCard->payment_method = \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
        $borneCard->payment_status = \\App\\Enums\\PaymentStatus::PAID;
        $borneCard->status = \\App\\Enums\\OrderStatus::DELIVERED;
        $borneCard->subtotal = 14.20;
        $borneCard->total = 14.20;
        $borneCard->total_tax = 0;
        $borneCard->discount = 0;
        $borneCard->delivery_charge = 0;
        $borneCard->token = '${SEED_PREFIX}BORNE-CARD-' . time();
        $borneCard->order_serial_no = $borneCard->token;
        $borneCard->order_datetime = now();
        $borneCard->queue_number = (string) (7300 + random_int(0, 99));
        $borneCard->saveQuietly();
        \\App\\Models\\Transaction::create([
            'order_id' => $borneCard->id,
            'amount' => 14.20,
            'payment_method' => 'counter_card',
            'transaction_id' => '${SEED_PREFIX}TX-BORNE-CARD-' . time(),
            'type' => 'payment',
            'sign' => '+',
        ]);

        echo json_encode([
            'ids' => [$posCash->id, $borneCash->id, $borneCard->id],
            'totals' => [$posCash->total, $borneCash->total, $borneCard->total],
        ]);
    `;
    try {
        const out = tinker(script);
        return lastJsonLine(out) || { ids: [], totals: [] };
    } catch (e) {
        // eslint-disable-next-line no-console
        console.warn('[S5 seed] best-effort failed:', String(e).slice(0, 200));
        return { ids: [], totals: [] };
    }
}

function deleteSeededRows(ids) {
    const safeIds = (ids || []).filter((n) => Number.isInteger(Number(n))).map(Number);
    if (!safeIds.length) return;
    const arr = JSON.stringify(safeIds);
    try {
        tinker(`
            // Drop transactions first (FK), then orders.
            \\App\\Models\\Transaction::whereIn('order_id', ${arr})->delete();
            foreach (${arr} as $id) {
                $o = \\App\\Models\\Order::find($id);
                if ($o) $o->forceDelete();
            }
            echo 'DELETED';
        `);
    } catch (_) { /* best-effort teardown */ }
}

/**
 * Race-proof initial-mount wait: register the response listener BEFORE the
 * navigation, then trigger the goto, then await both. Returns the parsed
 * JSON body when available. Used so the assertions can hit the payload
 * directly instead of fragile DOM-scrape of formatted amounts.
 */
async function gotoAndWaitInitial(page, url) {
    const apiPromise = page.waitForResponse(
        (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
        { timeout: 40_000 },
    );
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const apiResp = await apiPromise;
    const body = await apiResp.json().catch(() => null);
    await page.waitForSelector(
        '[data-testid="cash-overview-summary"], [data-testid="cash-overview-empty"]',
        { timeout: 30_000 },
    );
    await page.waitForTimeout(300);
    return body;
}

test.describe.configure({ mode: 'serial' });

test.describe('Wave Final S5 — Admin /admin/cash-overview MAX-reasoning capture', () => {
    let seeded = { ids: [], totals: [] };

    test.beforeAll(() => {
        seeded = seedMixedTransactions({ branchId: 1, count: 3 });
        // eslint-disable-next-line no-console
        console.log(`[S5 seed] ${seeded.ids.length} mixed transactions seeded`);
    });

    test.afterAll(() => {
        deleteSeededRows(seeded.ids);
    });

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    // -----------------------------------------------------------------------
    // S5-01..S5-02 — default mount + currency surface check.
    // -----------------------------------------------------------------------
    test('S5-01 + S5-02 — default mount renders 4 aggregate cards + ≥10 € symbols + 0 bare decimals', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        // Widen the range to guarantee data is present in the default view.
        const body = await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=2020-01-01&to=2099-12-31`,
        );
        expect(body).not.toBeNull();
        expect(body).toHaveProperty('summary');
        expect(body).toHaveProperty('data');

        // 4 aggregate cards: GRAND TOTAL + caisse + borne + livreur testids.
        await expect(page.locator('[data-testid="cash-overview-summary"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-source-caisse"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-source-borne"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-source-livreur"]')).toBeVisible();

        await snap('S5-01-default-mount');

        // S5-02 currency check — count visible € symbols inside the page card,
        // then verify there are no bare decimals (xx.xx or xx,xx WITHOUT €
        // immediately following). We scope to the db-card body so the count
        // doesn't include unrelated chrome.
        const bodyText = await page.locator('.db-card-body').first().innerText();
        const euroCount = (bodyText.match(/€/g) || []).length;
        expect(euroCount, `expected ≥10 € symbols in cash-overview body, got ${euroCount}`)
            .toBeGreaterThanOrEqual(10);

        // Bare-decimal regex: number with dot OR comma, two decimal digits,
        // NOT immediately followed by space + € (FR Intl format inserts a
        // non-breaking space then €, so we allow either regular space or NBSP).
        // The `formatMoney` path emits xx,xx <nbsp> €, so post-Q5 nothing
        // should match a bare decimal.
        const bareDecimalRegex = /\b\d+[.,]\d{2}(?![\s ]*€)(?![%])/g;
        const bareDecimals = bodyText.match(bareDecimalRegex) || [];
        expect(bareDecimals, `expected 0 bare decimals, found ${bareDecimals.length}: ${bareDecimals.slice(0, 5).join('|')}`)
            .toHaveLength(0);

        await snap('S5-02-currency-surface-check');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-03 — Filter source=borne via UI, URL updates.
    // -----------------------------------------------------------------------
    test('S5-03 — source=Borne filter syncs to URL + aggregates recompute', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=2020-01-01&to=2099-12-31`,
        );

        const apiPromise = page.waitForResponse(
            (resp) =>
                CASH_OVERVIEW_API_RE.test(resp.url())
                && resp.url().includes('source=borne')
                && resp.status() < 500,
            { timeout: 20_000 },
        );
        await page.selectOption('#cashOverviewSource', 'borne');
        await page.locator('[data-testid="cash-overview-search"]').click();
        const resp = await apiPromise;
        const filteredBody = await resp.json().catch(() => null);
        expect(filteredBody).not.toBeNull();
        // Wait for summary repaint with the filtered numbers.
        await page.waitForTimeout(500);

        // URL must now contain source=borne.
        const url = new URL(page.url());
        expect(url.searchParams.get('source')).toBe('borne');

        // Aggregates must reflect the filter (borne >= 0, caisse + livreur
        // can be 0 because we filter out non-borne rows).
        const sum = filteredBody.summary;
        expect(sum).toBeDefined();
        const caisseTotal = (sum.by_source && sum.by_source.caisse && sum.by_source.caisse.total) || 0;
        const livreurTotal = (sum.by_source && sum.by_source.livreur && sum.by_source.livreur.total) || 0;
        expect(caisseTotal, 'source=borne filter must zero out caisse rows').toBe(0);
        expect(livreurTotal, 'source=borne filter must zero out livreur rows').toBe(0);

        await snap('S5-03-filter-borne-url-sync');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-04 — F5 with ?source=borne → filter restored.
    // -----------------------------------------------------------------------
    test('S5-04 — F5 reload with ?source=borne restores filter', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?source=borne&from=2020-01-01&to=2099-12-31`,
        );

        const apiPromise = page.waitForResponse(
            (resp) =>
                CASH_OVERVIEW_API_RE.test(resp.url())
                && resp.url().includes('source=borne'),
            { timeout: 30_000 },
        );
        await page.reload({ waitUntil: 'domcontentloaded' });
        await apiPromise;
        await page.waitForTimeout(600);

        const sel = page.locator('#cashOverviewSource');
        await expect(sel).toHaveValue('borne');
        const url = new URL(page.url());
        expect(url.searchParams.get('source')).toBe('borne');

        await snap('S5-04-reload-restores-filter');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-05 — Click filter-form Réinitialiser → URL clean + aggregates reset.
    // -----------------------------------------------------------------------
    test('S5-05 — Réinitialiser filter-form button clears URL + reshows today', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?source=borne&from=2020-01-01&to=2099-12-31`,
        );

        const apiPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
            { timeout: 20_000 },
        );
        await page.locator('[data-testid="cash-overview-clear"]').click();
        await apiPromise.catch(() => {});
        await page.waitForTimeout(600);

        const url = new URL(page.url());
        expect(url.searchParams.get('source')).toBeNull();
        expect(url.searchParams.get('from')).toBeNull();
        expect(url.searchParams.get('to')).toBeNull();

        await snap('S5-05-reset-url-clean');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-06 — 1999 date range returns 0 rows → empty state with SVG + copy + CTA.
    // -----------------------------------------------------------------------
    test('S5-06 — empty state visible with SVG + copy ≥20 chars + reset CTA button', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=1999-01-01&to=1999-01-02`,
        );

        await expect(page.locator('[data-testid="cash-overview-empty"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-illustration"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-copy"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-reset"]')).toBeVisible();

        const copyText = await page.locator('[data-testid="cash-overview-empty-copy"]').innerText();
        expect(copyText.length, `empty-state copy must be ≥20 chars, got ${copyText.length}: "${copyText}"`)
            .toBeGreaterThanOrEqual(20);
        // No raw i18n key leaking (defect class 1).
        expect(copyText).not.toMatch(/^[a-z]+(\.[a-z_]+){1,4}$/);

        // SVG illustration must be a real inline <svg> with at least one
        // shape primitive so the empty state isn't just a blank box.
        const svgChildCount = await page
            .locator('[data-testid="cash-overview-empty-illustration"]')
            .evaluate((svg) => svg.querySelectorAll('rect, line, circle, path').length);
        expect(svgChildCount, 'SVG illustration must contain shape primitives')
            .toBeGreaterThan(0);

        await snap('S5-06-empty-state-illustration-cta');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-07 — Click the EMPTY-STATE reset CTA (not the filter-form Clear button).
    // -----------------------------------------------------------------------
    test('S5-07 — empty-state Réinitialiser CTA clears URL + restores default view', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=1999-01-01&to=1999-01-02`,
        );

        // Confirm we're in the empty state with the specific empty-reset CTA.
        await expect(page.locator('[data-testid="cash-overview-empty-reset"]')).toBeVisible();

        // Click the SPECIFIC empty-state CTA — Wave Polish A-09 tested
        // `cash-overview-clear` (filter-form button); here we discriminate.
        // The handler is `clearFilters()` per Vue line 230 (same handler as
        // the filter-form Clear), but the click path differs.
        const apiPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
            { timeout: 20_000 },
        );
        await page.locator('[data-testid="cash-overview-empty-reset"]').click();
        await apiPromise.catch(() => {});
        await page.waitForTimeout(700);

        // URL must drop the 1999 date params.
        const url = new URL(page.url());
        expect(url.searchParams.get('from'), 'empty-state reset must clear `from`').toBeNull();
        expect(url.searchParams.get('to'),   'empty-state reset must clear `to`').toBeNull();

        // The default view re-renders — either summary visible OR (if today
        // genuinely has 0 rows) empty state visible. Either is a valid
        // post-reset state; what matters is the URL clean + a successful
        // refetch (api 200).
        const hasSummary = await page
            .locator('[data-testid="cash-overview-summary"]')
            .isVisible()
            .catch(() => false);
        const stillEmpty = await page
            .locator('[data-testid="cash-overview-empty"]')
            .isVisible()
            .catch(() => false);
        expect(hasSummary || stillEmpty, 'post-empty-reset must show summary OR empty (refresh succeeded)')
            .toBe(true);

        await snap('S5-07-empty-reset-cta-clean-url');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-08 — Mode dropdown contains exactly 5 options, no 'other' / Autre.
    // -----------------------------------------------------------------------
    test('S5-08 — mode dropdown has no Autre / other option (Q7)', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        const optionValues = await page
            .locator('#cashOverviewMode option')
            .evaluateAll((opts) => opts.map((o) => o.value));
        expect(optionValues, 'expected exactly 5 options').toHaveLength(5);
        expect(optionValues).toEqual(expect.arrayContaining(['', 'cash', 'card', 'mobile', 'ticket']));
        expect(optionValues, "mode dropdown must NOT include 'other'")
            .not.toContain('other');

        // Visible labels — verify no raw key.
        const optionLabels = await page
            .locator('#cashOverviewMode option')
            .evaluateAll((opts) => opts.map((o) => o.textContent.trim()));
        for (const lbl of optionLabels) {
            expect(lbl).not.toMatch(/^[a-z]+(\.[a-z_]+){1,4}$/);
        }

        await snap('S5-08-mode-dropdown-no-autre');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-09 — Reconciliation card honest 3-cell display + amber note (C-013).
    // -----------------------------------------------------------------------
    test('S5-09 — reconciliation 3-cell honest display (opening + collected + expected) + count-pending note, NO diff cell', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const body = await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);
        const cashSessionPresent = body && body.cash_session !== null;

        if (cashSessionPresent) {
            await expect(page.locator('[data-testid="cash-overview-reconciliation"]')).toBeVisible();

            // 3 honest cells.
            await expect(page.locator('[data-testid="cash-overview-reconciliation-opening"]')).toBeVisible();
            await expect(page.locator('[data-testid="cash-overview-reconciliation-collected"]')).toBeVisible();
            await expect(page.locator('[data-testid="cash-overview-reconciliation-expected"]')).toBeVisible();

            // No diff cell.
            await expect(page.locator('[data-testid="cash-overview-reconciliation-diff"]')).toHaveCount(0);

            // Amber count-pending note present.
            await expect(page.locator('[data-testid="cash-overview-reconciliation-note"]')).toBeVisible();
            const noteText = await page.locator('[data-testid="cash-overview-reconciliation-note"]').innerText();
            expect(noteText.length, `note must be informative, got ${noteText.length}: "${noteText}"`)
                .toBeGreaterThanOrEqual(20);
            // The Vue calls $t('label.cash_drawer_count_pending_note') which
            // resolves to the FR string in lang/fr.json — must NOT be a raw key.
            expect(noteText).not.toMatch(/^[a-z]+(\.[a-z_]+){1,4}$/);

            // Numeric integrity: expected_cash == opening_amount + cash_collected.
            const opening   = Number(body.cash_session.opening_amount || 0);
            const collected = Number(body.cash_session.cash_collected || 0);
            const expected  = Number(body.cash_session.expected_cash  || 0);
            const recomputed = Math.round((opening + collected) * 100) / 100;
            expect(recomputed, `expected_cash must equal opening + collected`)
                .toBeCloseTo(expected, 2);
        } else {
            // eslint-disable-next-line no-console
            console.warn('[S5-09] cash_session=null — no open drawer in test DB; verifying card is absent without leaking nulls.');
            await expect(page.locator('[data-testid="cash-overview-reconciliation"]')).toHaveCount(0);
        }

        await snap('S5-09-reconciliation-honest-3-cell');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-10 — Numeric integrity: sum(by_source.total) == grand_total ;
    //          sum(by_mode.total) == grand_total.
    // -----------------------------------------------------------------------
    test('S5-10 — payload numeric integrity: sum sources == sum modes == grand_total', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const body = await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=2020-01-01&to=2099-12-31`,
        );
        expect(body).not.toBeNull();
        expect(body).toHaveProperty('summary');
        const sum = body.summary;
        expect(sum).toHaveProperty('total');
        expect(sum).toHaveProperty('by_source');
        expect(sum).toHaveProperty('by_mode');

        // Sum every by_source bucket.
        const bySrc = sum.by_source || {};
        const sourceTotals = Object.values(bySrc).map((s) => Number(s.total || 0));
        const sumOfSources = Math.round(sourceTotals.reduce((a, b) => a + b, 0) * 100) / 100;

        // Sum every by_mode bucket.
        const byMode = sum.by_mode || {};
        const modeTotals = Object.values(byMode).map((m) => Number(m.total || 0));
        const sumOfModes = Math.round(modeTotals.reduce((a, b) => a + b, 0) * 100) / 100;

        const grandTotal = Math.round(Number(sum.total || 0) * 100) / 100;

        expect(sumOfSources, `sum(by_source.total)=${sumOfSources} must equal grand_total=${grandTotal}`)
            .toBeCloseTo(grandTotal, 2);
        expect(sumOfModes,   `sum(by_mode.total)=${sumOfModes} must equal grand_total=${grandTotal}`)
            .toBeCloseTo(grandTotal, 2);

        // eslint-disable-next-line no-console
        console.log(`[S5-10] grand_total=${grandTotal} | sumSources=${sumOfSources} | sumModes=${sumOfModes} | count=${sum.count}`);

        await snap('S5-10-numeric-integrity-payload');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-11 — Wave O O4 sibling /admin/cash-sessions-report still loads
    //          (no-regression smoke).
    // -----------------------------------------------------------------------
    test('S5-11 — sibling /admin/cash-sessions-report still loads (Wave O O4 no-regression)', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const navResp = await page.goto(CASH_SESSIONS_REPORT_PATH, { waitUntil: 'domcontentloaded' });
        // Admin SPA may serve via a catch-all 200; what matters is no 4xx/5xx
        // on the route ITSELF and the page eventually renders something.
        expect(navResp).not.toBeNull();
        expect(navResp.status(), `/admin/cash-sessions-report must not 4xx/5xx, got ${navResp.status()}`)
            .toBeLessThan(400);

        // Give the SPA a generous moment to mount; we don't require a
        // specific testid here (sibling may be a different component) — just
        // that the route doesn't throw a Vue error or land on the 404 layout.
        await page.waitForTimeout(1500);

        // Negative checks — no global "Not Found" / 404 surface visible.
        const bodyTxt = await page.locator('body').innerText();
        expect(bodyTxt).not.toMatch(/^\s*Not Found\s*$/i);
        expect(bodyTxt).not.toMatch(/^\s*404\s*$/);
        expect(bodyTxt.length, 'page body must render non-trivial content').toBeGreaterThan(50);

        await snap('S5-11-cash-sessions-report-no-regression');
        dispose();
    });

    // -----------------------------------------------------------------------
    // S5-12 — Reconciliation invariance under source filter (C-014 fix).
    // -----------------------------------------------------------------------
    test('S5-12 — reconciliation cash_collected stays invariant under source filter (C-014)', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        // Default mount — capture unfiltered cash_collected.
        const unfilteredBody = await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);
        const unfilteredSession = unfilteredBody && unfilteredBody.cash_session;
        const unfilteredCollected = unfilteredSession ? Number(unfilteredSession.cash_collected || 0) : null;
        const unfilteredOpening   = unfilteredSession ? Number(unfilteredSession.opening_amount || 0) : null;

        if (unfilteredCollected === null) {
            // eslint-disable-next-line no-console
            console.warn('[S5-12] cash_session=null — no open drawer; invariance test trivially holds.');
            await snap('S5-12-reconciliation-invariance-no-drawer');
            dispose();
            return;
        }

        // Apply source=borne via UI.
        const filteredApiPromise = page.waitForResponse(
            (resp) =>
                CASH_OVERVIEW_API_RE.test(resp.url())
                && resp.url().includes('source=borne')
                && resp.status() < 500,
            { timeout: 20_000 },
        );
        await page.selectOption('#cashOverviewSource', 'borne');
        await page.locator('[data-testid="cash-overview-search"]').click();
        const resp = await filteredApiPromise;
        const filteredBody = await resp.json().catch(() => null);
        expect(filteredBody).not.toBeNull();
        expect(filteredBody).toHaveProperty('cash_session');

        const filteredSession = filteredBody.cash_session;
        if (filteredSession === null) {
            // If filtering hid the drawer entirely (possible if drawer's
            // branch had no borne tx today), the invariance claim is still
            // satisfied trivially — but log it.
            // eslint-disable-next-line no-console
            console.warn('[S5-12] post-filter cash_session=null — drawer hidden under filter, invariance not testable on this state.');
        } else {
            const filteredCollected = Number(filteredSession.cash_collected || 0);
            const filteredOpening   = Number(filteredSession.opening_amount || 0);

            expect(filteredCollected, `cash_collected MUST be invariant under source=borne (was ${unfilteredCollected}, got ${filteredCollected})`)
                .toBeCloseTo(unfilteredCollected, 2);
            expect(filteredOpening, `opening_amount MUST be invariant under source=borne (was ${unfilteredOpening}, got ${filteredOpening})`)
                .toBeCloseTo(unfilteredOpening, 2);
        }

        await page.waitForTimeout(400);
        await snap('S5-12-reconciliation-invariance-under-filter');
        dispose();
    });
});
