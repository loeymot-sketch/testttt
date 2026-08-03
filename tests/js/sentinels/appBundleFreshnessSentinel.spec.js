// Main `app.js` bundle freshness sentinel
// Prevents silent ship of stale `public/js/app.js` (default Vue SPA entry
// loaded by every admin/customer-frontend page) when sources change without
// `npm run development`. Stale app.js = stale Vuex store / helpers / admin
// chrome across the whole site.
//
// Trigger 1: the resources/js/app.js entrypoint itself + statically imported
//           DefaultComponent + BackendNavbarComponent (admin chrome shared
//           across many routes).
// Trigger 2: Vuex store/modules/auth.js + helpers/phoneDisplay.js — both
//           consumed at app boot. Wave A1 (62f90b2b4) and Wave B1
//           (272dfdffa) confirm these belong to the app.js dependency graph.
// Trigger 3: FrontendOrderReceiptComponent.vue — statically imported via
//           frontendRoutes.js (NOT a webpackChunkName lazy import) → ships
//           inline in app.js. Touch-test confirms rebuild propagation.
// Trigger 4: i18n catalogs fr.json / en.json / ar.json.
//
// Fix: npm run development (dev) or npm run production (prod-style)
//
// Sibling of kdsBundleFreshnessSentinel.spec.js (Q12 P-OWNER 2026-05-21).
// Inlines assertBundleFresh locally rather than reading from
// globalThis.__assertBundleFresh: vitest worker isolation makes a globalThis
// export from another spec file unreliable when this spec is run standalone.
// The function logic is byte-equivalent to the KDS sentinel (no new helper).
//
// @FK-ID  FK-V1-0-2-WAVE-B2-UR1-003-APP
// @source UR-1 architect audit 2026-05-25 + Wave A1/B1 (5 bundles affected)
import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, relative, join } from 'node:path';

function walkFiles(dir, matchFn, acc = []) {
    if (!existsSync(dir)) return acc;
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const abs = join(dir, entry.name);
        if (entry.isDirectory()) {
            walkFiles(abs, matchFn, acc);
        } else if (entry.isFile() && matchFn(entry.name, abs)) {
            acc.push(abs);
        }
    }
    return acc;
}

function assertBundleFresh(bundlePath, sourceGroups, bundleLabel = 'bundle') {
    if (!existsSync(bundlePath)) {
        return {
            bundleMtime: 0,
            oldestStaleSource: null,
            message:
                `Bundle missing: ${bundlePath} does not exist. ` +
                `Run 'npm run development' or 'npm run production' to build it.`,
        };
    }
    const bundleStat = statSync(bundlePath);
    const bundleMtime = bundleStat.mtimeMs;
    let newestStale = null;
    for (const group of sourceGroups) {
        for (const p of group.paths) {
            if (!existsSync(p)) continue;
            const st = statSync(p);
            if (st.mtimeMs > bundleMtime) {
                if (!newestStale || st.mtimeMs > newestStale.mtimeMs) {
                    newestStale = { path: p, mtimeMs: st.mtimeMs, group: group.label };
                }
            }
        }
    }
    if (!newestStale) {
        return { bundleMtime, oldestStaleSource: null, message: null };
    }
    const fmt = (ms) => new Date(ms).toISOString();
    const message =
        `${bundleLabel} stale: source ${relative(process.cwd(), newestStale.path)} ` +
        `(mtime ${fmt(newestStale.mtimeMs)}, group "${newestStale.group}") ` +
        `is newer than ${relative(process.cwd(), bundlePath)} ` +
        `(mtime ${fmt(bundleMtime)}). ` +
        `Run 'npm run development' or 'npm run production' to rebuild.`;
    return { bundleMtime, oldestStaleSource: newestStale, message };
}

describe('Build integrity — app bundle freshness (FK-V1-0-2-WAVE-B2)', () => {
    const root = process.cwd();
    const bundlePath = resolve(root, 'public/js/app.js');

    // --- Group 1: entrypoint + admin chrome statically embedded in app.js ---
    const entrypoint = [
        resolve(root, 'resources/js/app.js'),
        resolve(root, 'resources/js/components/DefaultComponent.vue'),
        resolve(root, 'resources/js/components/layouts/backend/BackendNavbarComponent.vue'),
    ];

    // --- Group 2: Vuex store + shared helpers ---
    // Wave A1 + Wave B1 confirm these source files live in the app.js
    // dependency graph (rebuilt alongside admin-shell/pos-app/admin-reports).
    const transitive = [
        resolve(root, 'resources/js/store/modules/auth.js'),
        resolve(root, 'resources/js/helpers/phoneDisplay.js'),
    ];

    // --- Group 3: customer-frontend receipt (NF525 archive surface) ---
    // resources/js/router/modules/frontendRoutes.js statically imports
    // OrderDetailsComponent which statically imports FrontendOrderReceiptComponent.
    // No webpackChunkName → ships inline in app.js. Anchor against Wave A3
    // (35151864d) regression: PENDING_ phone sanitization in 6-year archive.
    const frontendReceipt = [
        resolve(
            root,
            'resources/js/components/frontend/account/myOrder/FrontendOrderReceiptComponent.vue',
        ),
    ];

    // --- Group 4: i18n catalogs ---
    const i18nDir = resolve(root, 'resources/js/languages');
    const i18nSources = ['fr', 'en', 'ar'].map((l) => resolve(i18nDir, `${l}.json`));

    const sourceGroups = [
        { label: 'app-entrypoint', paths: entrypoint },
        { label: 'app-transitive', paths: transitive },
        { label: 'app-frontend-receipt', paths: frontendReceipt },
        { label: 'i18n-catalogs', paths: i18nSources },
    ];

    it('discovers app anchor source files (smoke — guards against empty-set bug)', () => {
        for (const p of [...entrypoint, ...transitive, ...frontendReceipt, ...i18nSources]) {
            expect(existsSync(p), `missing anchor source ${p}`).toBe(true);
        }
    });

    it('app.js bundle exists', () => {
        expect(
            existsSync(bundlePath),
            `bundle ${bundlePath} missing — run 'npm run development' or 'npm run production'`,
        ).toBe(true);
    });

    it('app.js bundle is not stale relative to its anchor sources', () => {
        const result = assertBundleFresh(bundlePath, sourceGroups, 'app bundle');
        expect(result.message, result.message ?? 'bundle fresh').toBeNull();
    });
});
