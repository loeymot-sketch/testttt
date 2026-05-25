// Admin-shell bundle freshness sentinel
// Prevents silent ship of stale `public/js/admin-shell.js` where source/i18n
// changes are not reflected in compiled output (raw label.X leaks, missing
// PENDING_ sanitization, etc.).
//
// Trigger 1: anchor Vue files lazy-imported into the admin-shell chunk
//           (MessageListComponent, ProfileEditProfileComponent) whose mtime
//           is greater than public/js/admin-shell.js.
// Trigger 2: cross-bundle layouts/auth/helpers that DefaultComponent embeds
//           in admin-shell at build time (BackendNavbarComponent.vue,
//           store/modules/auth.js, helpers/phoneDisplay.js).
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
// @FK-ID  FK-V1-0-2-WAVE-B2-UR1-003-ADMIN-SHELL
// @source UR-1 architect audit 2026-05-25 + Wave B1/A1/A3 (5 bundles affected)
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

describe('Build integrity — admin-shell bundle freshness (FK-V1-0-2-WAVE-B2)', () => {
    const root = process.cwd();
    const bundlePath = resolve(root, 'public/js/admin-shell.js');

    // --- Group 1: anchor Vue components lazy-imported into admin-shell chunk
    // Empirically rebuilt admin-shell.js in Wave B1 commit 272dfdffa.
    const anchorVue = [
        resolve(root, 'resources/js/components/admin/messages/MessageListComponent.vue'),
        resolve(root, 'resources/js/components/admin/profile/ProfileEditProfileComponent.vue'),
        resolve(root, 'resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue'),
    ];

    // --- Group 2: layouts/store/helpers embedded transitively at build time
    // BackendNavbarComponent is statically imported by DefaultComponent;
    // store/modules/auth.js + helpers/phoneDisplay.js were Wave A1/B1 fixes
    // that rebuilt admin-shell.js (verified commits 62f90b2b4 + 272dfdffa).
    const transitive = [
        resolve(root, 'resources/js/components/layouts/backend/BackendNavbarComponent.vue'),
        resolve(root, 'resources/js/store/modules/auth.js'),
        resolve(root, 'resources/js/helpers/phoneDisplay.js'),
    ];

    // --- Group 3: i18n catalogs ---
    const i18nDir = resolve(root, 'resources/js/languages');
    const i18nSources = ['fr', 'en', 'ar'].map((l) => resolve(i18nDir, `${l}.json`));

    const sourceGroups = [
        { label: 'admin-shell-anchor-vue', paths: anchorVue },
        { label: 'admin-shell-transitive', paths: transitive },
        { label: 'i18n-catalogs', paths: i18nSources },
    ];

    it('discovers admin-shell anchor source files (smoke — guards against empty-set bug)', () => {
        for (const p of [...anchorVue, ...transitive, ...i18nSources]) {
            expect(existsSync(p), `missing anchor source ${p}`).toBe(true);
        }
    });

    it('admin-shell.js bundle exists', () => {
        expect(
            existsSync(bundlePath),
            `bundle ${bundlePath} missing — run 'npm run development' or 'npm run production'`,
        ).toBe(true);
    });

    it('admin-shell.js bundle is not stale relative to its anchor sources', () => {
        const result = assertBundleFresh(bundlePath, sourceGroups, 'admin-shell bundle');
        expect(result.message, result.message ?? 'bundle fresh').toBeNull();
    });
});
