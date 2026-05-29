// KDS bundle freshness sentinel
// Prevents silent ship of stale bundles where source/i18n changes
// are not reflected in compiled output (e.g. raw label.X leaks).
//
// Trigger 1: any *.vue file under resources/js/components/admin/kitchenDisplaySystem/
//           with mtime > public/js/admin-kds.js
// Trigger 2: any KDS-specific helper/service/store module under
//           resources/js/{helpers,services,store/modules} that is imported
//           by the kitchenDisplaySystem chunk and whose name is prefixed by
//           `kds*` / `kitchenDisplaySystem*` / `KdsSyncService*`.
//
// [GOAL-2026-05-30] The i18n-catalogs trigger was REMOVED. fr/en/ar.json compile into
// the ENTRY chunk (app.js), NOT this lazy admin-kds.js chunk — verified by grep: the
// 397de5ff0 dashboard i18n keys appear x3 in app.js and x0 in admin-kds.js. An i18n-only
// edit re-emits app.js, not admin-kds.js, so listing the catalogs here produced a FALSE
// staleness on every label add. i18n freshness IS guarded where the catalog lives:
// appBundleFreshnessSentinel (Group-4) + posAppBundleFreshnessSentinel (Group-3), both
// green. SYSTEMIC NOTE: lazy-chunk freshness sentinels must not list entry-resident
// sources (i18n, store, navbar, shared helpers) — those hoist to the entry and only the
// entry sentinel can see them. admin-oss/admin-reports sentinels likely carry the same
// latent false-positive (V1.0.X backlog — not fixed here).
//
// Fix: npm run development (dev) or npm run production (prod-style)
//
// Scope rationale (why some imports are NOT watched):
//   KitchenDisplaySystemComponent.vue also imports cross-bundle shared
//   modules (enums/*, alertService, appService, eventContract,
//   LoadingComponent, ConnectionStatusBanner, swiper). Those live in
//   many webpack chunks (admin-pos, kiosk, oss, ...) — watching them
//   would produce false positives for the KDS bundle every time another
//   surface touched them. The KDS-specific kds* / KdsSyncService /
//   kitchenDisplaySystemOrder modules are the precise drift cluster:
//   editing them MUST be reflected in admin-kds.js or chefs see stale
//   logic / raw labels (the recurring bug owner Q12 mandated we lock).
//
// @FK-ID  FK-PHASE-2E-Q12-BUILD-INTEGRITY-001
// @source P-OWNER Q12 decision 2026-05-21
import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, relative, join } from 'node:path';

/**
 * Recursively walk a directory and return absolute paths of files whose
 * basename matches the predicate. Pure stdlib so no glob dep required.
 * Skips symlinks (statSync follows them — we use the resolved real stat).
 */
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

/**
 * Reusable freshness assertion.
 *
 * @param {string} bundlePath           absolute path to the compiled bundle
 * @param {Array<{label:string, paths:string[]}>} sourceGroups
 *        Groups of source files (already resolved to absolute paths) that
 *        must NOT be newer than the bundle. `label` is shown in the error.
 *
 * @returns {{bundleMtime:number, oldestStaleSource:object|null, message:string|null}}
 *        Either null `message` (fresh) or a literal error string matching
 *        the project-mandated template, ready to drop into expect().
 */
function assertBundleFresh(bundlePath, sourceGroups) {
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

    // Scan every source, keep the NEWEST staler-than-bundle entry so the
    // error message points at the most recent culprit (the dev's last edit).
    let newestStale = null;
    for (const group of sourceGroups) {
        for (const p of group.paths) {
            if (!existsSync(p)) continue;
            const st = statSync(p);
            if (st.mtimeMs > bundleMtime) {
                if (!newestStale || st.mtimeMs > newestStale.mtimeMs) {
                    newestStale = {
                        path: p,
                        mtimeMs: st.mtimeMs,
                        group: group.label,
                    };
                }
            }
        }
    }

    if (!newestStale) {
        return { bundleMtime, oldestStaleSource: null, message: null };
    }

    const fmt = (ms) => new Date(ms).toISOString();
    const message =
        `KDS bundle stale: source ${relative(process.cwd(), newestStale.path)} ` +
        `(mtime ${fmt(newestStale.mtimeMs)}, group "${newestStale.group}") ` +
        `is newer than ${relative(process.cwd(), bundlePath)} ` +
        `(mtime ${fmt(bundleMtime)}). ` +
        `Run 'npm run development' or 'npm run production' to rebuild.`;

    return { bundleMtime, oldestStaleSource: newestStale, message };
}

// Export the helper on globalThis so future bundles (admin-oss.js,
// admin-reports.js, etc.) can reuse it from sibling sentinels without
// refactoring this file into a separate module yet. Phase-2 chip-away.
globalThis.__assertBundleFresh = assertBundleFresh;

describe('Build integrity — KDS bundle freshness (FK-PHASE-2E-Q12)', () => {
    const root = process.cwd();
    const bundlePath = resolve(root, 'public/js/admin-kds.js');

    // --- Group 1: kitchenDisplaySystem dir Vue components -------------
    const kdsDir = resolve(root, 'resources/js/components/admin/kitchenDisplaySystem');
    const vueSources = walkFiles(kdsDir, (name) => name.endsWith('.vue'));

    // --- Group 2: KDS-specific cross-dir imports ---------------------
    // (The former i18n-catalogs group was removed — see the [GOAL-2026-05-30] header
    //  note. The catalogs are entry-resident; their freshness is the app/pos-app
    //  sentinels' job, not this lazy chunk's.)
    // Discovered via grep of KitchenDisplaySystemComponent.vue + KdsOrderCard.vue
    // + KdsV2Grid.vue + KdsHistoryDrawer.vue imports. Restrict to files
    // whose name matches kds* / KdsSyncService / kitchenDisplaySystem*
    // so cross-bundle shared modules are NOT watched (see header).
    const kdsHelperFiles = walkFiles(
        resolve(root, 'resources/js/helpers'),
        (name) => /^kds[A-Z]/.test(name) && name.endsWith('.js'),
    );
    const kdsServiceFiles = walkFiles(
        resolve(root, 'resources/js/services'),
        (name) => /^Kds/.test(name) && name.endsWith('.js'),
    );
    const kdsStoreFiles = walkFiles(
        resolve(root, 'resources/js/store/modules'),
        (name) =>
            (name === 'kds.js' ||
                name === 'kdsInflight.js' ||
                name === 'kitchenDisplaySystemOrder.js') &&
            name.endsWith('.js'),
    );

    const sourceGroups = [
        { label: 'kitchenDisplaySystem-vue', paths: vueSources },
        { label: 'kds-helpers', paths: kdsHelperFiles },
        { label: 'kds-services', paths: kdsServiceFiles },
        { label: 'kds-store', paths: kdsStoreFiles },
    ];

    it('discovers KDS source files (smoke — guards against silent empty-set bug)', () => {
        // If a refactor moved the dir, the sentinel must fail loud rather
        // than silently pass on an empty set. Locked baseline: 1+ vue.
        expect(vueSources.length, 'no .vue files found under kitchenDisplaySystem — did the dir move?').toBeGreaterThan(0);
        // KDS-specific helpers known baseline (>= 1 from grep audit).
        expect(kdsHelperFiles.length, 'no kds* helpers found — refactor drift?').toBeGreaterThan(0);
        expect(kdsServiceFiles.length, 'no Kds* services found — refactor drift?').toBeGreaterThan(0);
        expect(kdsStoreFiles.length, 'no kds* store modules found — refactor drift?').toBeGreaterThan(0);
    });

    it('admin-kds.js bundle exists', () => {
        expect(
            existsSync(bundlePath),
            `bundle ${bundlePath} missing — run 'npm run development' or 'npm run production'`,
        ).toBe(true);
    });

    it('admin-kds.js bundle is not stale relative to KDS sources', () => {
        const result = assertBundleFresh(bundlePath, sourceGroups);
        // null message == fresh bundle.
        expect(result.message, result.message ?? 'bundle fresh').toBeNull();
    });
});
