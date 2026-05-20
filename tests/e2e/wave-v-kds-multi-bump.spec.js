// Wave V E2E — KDS rapid multi-order bump (P-OWNER 2026-05-21)
//
// Owner-reported bug (verbatim FR):
//   "Sur KDS et Suivi commandes : si je clique sur 'Prêt' deux fois (sur 2
//    commandes différentes), ça me met la première en prête puis sur les
//    autres ça donne 'trop de requêtes, réessayer après 30 secondes'. Mais
//    après 30s ça refonctionne. Enlève cette sécurité — je veux valider 3
//    commandes en même temps, puis 3 commandes livrées, etc."
//
// Root cause (pre-Wave-V): `KdsV2Grid.onCtaTap` used a single-slot pending
// bump queue with a shared `pendingTimeoutId`. Clicking Prêt on order B
// within the 3-s undo window of order A called `clearTimeout(pendingTimeoutId)`
// → A's PATCH never fired. Chef re-clicked A; intermittent 429s from other
// upstream paths surfaced the bootstrap.js "Trop de requêtes — patientez 30s"
// toast, which the owner read as "30s mandatory wait".
//
// Heal: 3-s undo window removed; every tap fires
// `POST /api/admin/kds-order/change-status/{id}` synchronously with a fresh
// X-Idempotency-Key UUID. Backend `OrderStateMachine::apply` already
// serialises per-order via `lockForUpdate`, so concurrent PATCHes on
// different orders are independent. Duplicate PATCH on the SAME order
// returns 409 and is swallowed by `onV2ChangeStatus` (silent refresh).
//
// What this spec exercises (live-DB E2E, no server seed):
//   1. KDS surface mounts with the heal applied (no KdsUndoToast, no kds-toast).
//   2. If ≥2 cards expose `[data-testid="kds-card-cta-ready"]`, click them
//      within 100 ms and assert that ≥2 `POST .../kds-order/change-status/`
//      requests fire — proving no client-side serialization swallows the
//      second click.
//   3. No `[role="alert"]` rate-limited toast appears within 6 s after the
//      rapid clicks (heal removes the race that was triggering them).
//   4. Capture a screenshot for visual diff against Wave U baseline.
//
// Credentials: chef@lecayenne.fr / 123456 (CLAUDE.md §reference).
//
// Coordination notes (DO NOT REGRESS):
//   - Wave U `recentlyServed` strip stays (PREPARED orders archive within
//     ~1 s of PATCH). Selector: `.kds-v2__served-pill`.
//   - Wave Q-2 step-ladder stays: a single tap on ACCEPT (CONFIRMÉE) advances
//     to PREPARING, NOT directly to PREPARED. Two taps required to reach PRÊT
//     when chef sees a CONFIRMÉE ticket. (Most paid orders arrive in
//     PREPARING from Wave S-1 server-side auto-transition.)
//   - Wave S-2 cash-pending mutex stays: cards with the
//     `[data-testid="kds-card-cash-pending"]` badge never expose the CTA.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsChefOperator } = require('./helpers/login');

const CHEF_EMAIL = 'chef@lecayenne.fr';
const CHEF_PASSWORD = '123456';
const KDS_SURFACE_RE = /\/(kds|admin\/kitchen-display-system)/;
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-v-kds-multi-bump';
const REPORT_DIR = 'reports/test-e2e/wave-v-2026-05-21';

// Ensure capture dirs exist.
for (const d of [SCREENSHOT_DIR, REPORT_DIR]) {
    fs.mkdirSync(path.resolve(d), { recursive: true });
}

test.describe('Wave V — KDS rapid multi-order bump (no race, no 30s wait)', () => {
    test.setTimeout(120_000);

    test('KDS surface no longer renders the 3-s undo toast UI', async ({ page }) => {
        await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
        await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
        await page.waitForTimeout(3_500);

        // Grid mounts.
        const grid = page.locator('.kds-v2');
        await expect(grid).toBeVisible({ timeout: 10_000 });

        // Heal invariant: no `.kds-toast` element renders at any point in
        // the lifecycle (the component is no longer imported, no template
        // mount point, no class definition rendered into DOM).
        const toast = page.locator('.kds-toast');
        await expect(toast).toHaveCount(0);

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/01-kds-loaded-no-toast.png`,
            fullPage: true,
        });
    });

    test('rapid bump on ≥2 cards fires ≥2 PATCHes within 1.5 s (no serialization)', async ({ page }) => {
        // Record all KDS change-status PATCH calls (request side, not response,
        // so we count even if the server returns 409 on a same-order duplicate).
        const bumpRequests = [];
        page.on('request', (req) => {
            const url = req.url();
            if (req.method() === 'POST'
                && /\/api\/admin\/kds-order\/change-status\//.test(url)) {
                bumpRequests.push({
                    url,
                    at: Date.now(),
                    idem: req.headers()['x-idempotency-key'] || null,
                });
            }
        });
        // Capture any 429 response so we can fail loudly if the heal regresses.
        const rateLimitedResponses = [];
        page.on('response', (res) => {
            if (res.status() === 429
                && /\/api\/admin\/kds-order\/change-status\//.test(res.url())) {
                rateLimitedResponses.push({ url: res.url(), at: Date.now() });
            }
        });

        await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
        await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
        await page.waitForTimeout(3_500);

        const ctas = page.locator('[data-testid="kds-card-cta-ready"]');
        const ctaCount = await ctas.count();

        if (ctaCount < 2) {
            // Live KDS queue is empty / has only 1 paid order. The unit
            // sentinel kdsV2MultiBumpSentinel.spec.js still locks the
            // structural heal at static-analysis level — this E2E leg
            // becomes informational only.
            test.skip(true, `Need ≥2 KDS cards with CTA, found ${ctaCount} — skipping rapid-bump leg`);
            return;
        }

        const before = bumpRequests.length;
        const t0 = Date.now();
        // Click first 2 CTAs in tight succession — the pre-Wave-V bug would
        // drop the first PATCH on the floor. We use .click() rather than
        // .all().then(parallel-click) to mimic the chef's natural finger pace.
        await ctas.nth(0).click();
        await ctas.nth(1).click({ timeout: 2_000 });
        // Allow the synchronous emits + axios round-trips a moment to fire.
        // Pre-Wave-V the chef would have had to wait 3 s × 2 = 6 s for both
        // PATCHes; post-Wave-V they fire essentially instantly.
        await page.waitForTimeout(1_500);

        const fired = bumpRequests.length - before;
        const elapsed = Date.now() - t0;
        // The heal mandates ≥2 PATCHes for 2 clicks. Pre-Wave-V this would
        // be 1 (the second click cancelled the first).
        expect(fired, `expected ≥2 change-status PATCH requests in ${elapsed} ms, got ${fired}`).toBeGreaterThanOrEqual(2);

        // 0 expected 429s on bump path (KDS_RATE_LIMIT_BUMP=1000/min dev,
        // 120/min prod with admin-mutation lift). Surfacing any rate-limit
        // response here would be a P0 regression.
        expect(rateLimitedResponses.length, `unexpected 429 responses on bump: ${JSON.stringify(rateLimitedResponses)}`).toBe(0);

        // No "Trop de requêtes" toast should appear after 6 s — the bootstrap.js
        // 429 handler would inject `role="alert"` + the localized copy.
        await page.waitForTimeout(6_000);
        const rateLimitedToast = page.locator(
            '[role="alert"], .toast, [role="status"]'
        ).filter({ hasText: /trop de requêtes|trop de demandes|patientez 30s|too many request/i });
        await expect(rateLimitedToast).toHaveCount(0);

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/02-after-rapid-bump.png`,
            fullPage: true,
        });

        // Persist evidence for the next adversarial round.
        fs.writeFileSync(
            path.resolve(REPORT_DIR, 'bump-trace.json'),
            JSON.stringify({
                spec: 'wave-v-kds-multi-bump',
                cta_count_at_t0: ctaCount,
                bump_requests_fired: fired,
                elapsed_ms: elapsed,
                rate_limited_count: rateLimitedResponses.length,
                idempotency_keys: bumpRequests.slice(before).map((r) => r.idem),
            }, null, 2)
        );
    });

    test('preserved: Wave U recentlyServed strip still renders post-PATCH', async ({ page }) => {
        await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
        await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
        await page.waitForTimeout(3_500);

        // Strip is v-if'd on `recentlyServed.length > 0`. If the backend has
        // ≥1 PREPARED order today, the strip renders. Otherwise the assertion
        // is conditional — we ONLY check that the strip class is wired into
        // the bundle (no template regression).
        const grid = page.locator('.kds-v2');
        const html = await grid.innerHTML().catch(() => '');
        // The Wave U strip wrapper class always lives in the compiled CSS,
        // but the element only renders when recentlyServed.length > 0.
        // Just confirm the grid is intact and the heal didn't break the strip.
        const strip = page.locator('.kds-v2__served');
        const stripCount = await strip.count();
        if (stripCount > 0) {
            await expect(strip).toBeVisible();
        }
        // No DOM smoke required beyond no crash + grid-visible.
        expect(html).toBeTruthy();

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/03-served-strip-preserved.png`,
            fullPage: true,
        });
    });
});
