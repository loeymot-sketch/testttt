// POS-app bundle freshness sentinel
// Prevents silent ship of stale `public/js/pos-app.js` (POS V4 dedicated entry
// at /admin/pos-v4) when sources change without `npm run development`.
// Critical because pos-app.js statically embeds DefaultComponent +
// BackendNavbarComponent + Vuex store (incl. auth.js) + shared helpers
// (phoneDisplay.js) — a stale bundle here = stale POS screen for cashiers.
//
// Trigger 1: anchor Vue (BackendNavbarComponent.vue) statically imported by
//           DefaultComponent, itself imported by resources/js/pos-app.js
//           (the mix entry-point).
// Trigger 2: store/modules/auth.js + helpers/phoneDisplay.js — both consumed
//           by Vuex modules + components shipped inside pos-app. Verified
//           rebuilt in Wave A1 (62f90b2b4) and Wave B1 (272dfdffa).
// Trigger 3: i18n catalogs fr.json / en.json / ar.json.
//
// Fix: npm run development (dev) or npm run production (prod-style)
//
// Sibling of kdsBundleFreshnessSentinel.spec.js (Q12 P-OWNER 2026-05-21).
// Inlines assertBundleFresh locally rather than reading from
// globalThis.__assertBundleFresh: vitest worker isolation makes a globalThis
// export from another spec file unreliable when this spec is run standalone.
// The function logic is byte-equivalent to the KDS sentinel (no new helper).
//
// @FK-ID  FK-V1-0-2-WAVE-B2-UR1-003-POS-APP
// @source UR-1 architect audit 2026-05-25 + Wave A1/B1 (pos-app.js rebuilt)
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

describe('Build integrity — pos-app bundle freshness (FK-V1-0-2-WAVE-B2)', () => {
    const root = process.cwd();
    const bundlePath = resolve(root, 'public/js/pos-app.js');

    // --- Group 1: entrypoint + admin chrome statically embedded in pos-app ---
    // resources/js/pos-app.js imports DefaultComponent which imports
    // BackendNavbarComponent → both ship inline in pos-app.js (no chunk).
    const entrypoint = [
        resolve(root, 'resources/js/pos-app.js'),
        resolve(root, 'resources/js/components/DefaultComponent.vue'),
        resolve(root, 'resources/js/components/layouts/backend/BackendNavbarComponent.vue'),
    ];

    // --- Group 2: Vuex store + shared helpers transitively embedded ---
    // Wave A1 (62f90b2b4) confirmed pos-app.js rebuild on auth.js change.
    // Wave B1 (272dfdffa) confirmed pos-app.js rebuild on phoneDisplay.js change.
    const transitive = [
        resolve(root, 'resources/js/store/modules/auth.js'),
        resolve(root, 'resources/js/helpers/phoneDisplay.js'),
    ];

    // --- Group 3: i18n catalogs ---
    const i18nDir = resolve(root, 'resources/js/languages');
    const i18nSources = ['fr', 'en', 'ar'].map((l) => resolve(i18nDir, `${l}.json`));

    const sourceGroups = [
        { label: 'pos-app-entrypoint', paths: entrypoint },
        { label: 'pos-app-transitive', paths: transitive },
        { label: 'i18n-catalogs', paths: i18nSources },
    ];

    it('discovers pos-app anchor source files (smoke — guards against empty-set bug)', () => {
        for (const p of [...entrypoint, ...transitive, ...i18nSources]) {
            expect(existsSync(p), `missing anchor source ${p}`).toBe(true);
        }
    });

    it('pos-app.js bundle exists', () => {
        expect(
            existsSync(bundlePath),
            `bundle ${bundlePath} missing — run 'npm run development' or 'npm run production'`,
        ).toBe(true);
    });

    it('pos-app.js bundle is not stale relative to its anchor sources', () => {
        const result = assertBundleFresh(bundlePath, sourceGroups, 'pos-app bundle');
        expect(result.message, result.message ?? 'bundle fresh').toBeNull();
    });
});
