// Admin-reports bundle freshness sentinel
// Prevents silent ship of stale `public/js/admin-reports.js` where source/i18n
// changes are not reflected in the compiled output (raw label.X leaks, missing
// PENDING_ sanitization, etc.) on credit-balance / sales-report screens.
//
// Trigger 1: anchor Vue files lazy-imported into the admin-reports chunk
//           (CreditBalanceReportComponent) whose mtime is greater than
//           public/js/admin-reports.js.
// Trigger 2: shared helpers consumed by report components
//           (helpers/phoneDisplay.js).
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
// @FK-ID  FK-V1-0-2-WAVE-B2-UR1-003-ADMIN-REPORTS
// @source UR-1 architect audit 2026-05-25 + Wave B1 (admin-reports.js rebuilt)
import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, relative, join } from 'node:path';

// [CONTENTHASH 2026-07-27] Les chunks lazy sont désormais hashés (`js/[name].[contenthash:8].js`,
// webpack.mix.js — fix borne chunk périmé). Le sentinel résout le nom réel par motif ;
// introuvable → chemin legacy (message d'échec explicite conservé).
function resolveHashedBundle(root, base) {
    const dir = resolve(root, 'public/js');
    if (existsSync(dir)) {
        const rx = new RegExp('^' + base + '\\.[0-9a-f]{8}\\.js$');
        const hit = readdirSync(dir).find((f) => rx.test(f) || f === base + '.js');
        if (hit) return join(dir, hit);
    }
    return resolve(root, 'public/js/' + base + '.js');
}


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

describe('Build integrity — admin-reports bundle freshness (FK-V1-0-2-WAVE-B2)', () => {
    const root = process.cwd();
    const bundlePath = resolveHashedBundle(root, 'admin-reports');

    // --- Group 1: anchor Vue components lazy-imported into admin-reports
    // creditBalanceReportRoutes.js + salesReportRoutes.js + cashSessionReportRoutes.js
    // all use webpackChunkName: "admin-reports". CreditBalanceReport is the
    // anchor verified rebuilt in Wave B1 commit 272dfdffa.
    const anchorVue = [
        resolve(
            root,
            'resources/js/components/admin/creditBalanceReport/CreditBalanceReportComponent.vue',
        ),
    ];

    // --- Group 2: shared helpers transitively embedded ---
    // phoneDisplay.js was Wave B1 (272dfdffa) and triggered admin-reports rebuild.
    const transitive = [
        resolve(root, 'resources/js/helpers/phoneDisplay.js'),
    ];

    // [GOAL-2026-05-29] Group 3 (i18n catalogs) REMOVED: admin-reports.js resolves
    // i18n at RUNTIME against the catalog compiled into the entry bundle (app.js),
    // so a .json key change NEVER alters admin-reports.js content — a freshness
    // trigger on i18n is a phantom dependency (false positive on every i18n commit).
    // The genuine freshness invariant (anchor .vue + transitive helper vs bundle mtime) stays.

    const sourceGroups = [
        { label: 'admin-reports-anchor-vue', paths: anchorVue },
        { label: 'admin-reports-transitive', paths: transitive },
    ];

    it('discovers admin-reports anchor source files (smoke — guards against empty-set bug)', () => {
        for (const p of [...anchorVue, ...transitive]) {
            expect(existsSync(p), `missing anchor source ${p}`).toBe(true);
        }
    });

    it('admin-reports.js bundle exists', () => {
        expect(
            existsSync(bundlePath),
            `bundle ${bundlePath} missing — run 'npm run development' or 'npm run production'`,
        ).toBe(true);
    });

    it('admin-reports.js bundle is not stale relative to its anchor sources', () => {
        const result = assertBundleFresh(bundlePath, sourceGroups, 'admin-reports bundle');
        expect(result.message, result.message ?? 'bundle fresh').toBeNull();
    });
});
