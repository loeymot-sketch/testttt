#!/usr/bin/env node
/**
 * [POS-V4 W1-C 2026-04-26] Lazy-load admin classique router modules.
 *
 * Converts static `import XxxComponent from ".../XxxComponent"` into
 * `const XxxComponent = () => import(/_* webpackChunkName: "<chunk>" *_/ ".../XxxComponent")`.
 *
 * Why a script (vs hand-editing 25 files):
 *  - Reproducible / auditable (git diff shows exact mechanical transform).
 *  - Single-source-of-truth chunk grouping (CHUNK_MAPPING below).
 *  - Safe-by-default: regex matches only well-formed SFC imports
 *    (relative path under ../../components, ends with .vue or no ext).
 *  - DRY-RUN by default — pass --apply to write changes.
 *
 * Excluded modules (must remain statically imported — boot critical or SEO):
 *  - authRoutes.js          (login = boot critical)
 *  - frontendRoutes.js      (vitrine = SEO + first-paint critical)
 *  - posRoutes.js           (already split → pos-shell, W1-A)
 *  - kioskRoutes.js         (already split → kiosk-shell, pre-existing)
 *  - cdsRoutes.js           (orphaned, not referenced in router/index.js)
 *
 * Usage:
 *   node tools/refactor/lazy_router_modules.mjs           # dry-run preview
 *   node tools/refactor/lazy_router_modules.mjs --apply   # write changes
 *
 * Audit trail after run:
 *   git diff --stat resources/js/router/modules/
 *   npm run production && du -h public/js/admin-*.js public/js/app.js
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const MODULES_DIR = path.join(ROOT, 'resources/js/router/modules');

const APPLY = process.argv.includes('--apply');

const EXCLUDED = new Set([
    'authRoutes.js',
    'frontendRoutes.js',
    'posRoutes.js',
    'kioskRoutes.js',
    'cdsRoutes.js',
]);

// Chunk mapping: file basename → webpack chunk name.
// Strategy: 1 chunk per surface family to balance request count vs cache reuse.
const CHUNK_MAPPING = {
    'kitchenDisplaySystemRoutes.js': 'admin-kds',
    'orderStatusScreenRoutes.js':    'admin-oss',
    'salesReportRoutes.js':          'admin-reports',
    'itemsReportRoutes.js':          'admin-reports',
    'creditBalanceReportRoutes.js':  'admin-reports',
    // Default for everything else not excluded
    DEFAULT: 'admin-shell',
};

// Match: import IDENT from "../../components/.../XxxComponent" (with or without .vue)
// Captures: 1=identifier, 2=quote char, 3=path
const STATIC_SFC_IMPORT_RE =
    /^import\s+(\w+)\s+from\s+(["'])(\.\.\/\.\.\/components\/[^"']+?)\2;?\s*$/gm;

const HEADER_TAG = '[POS-V4 W1-C 2026-04-26]';

// Safety contract: STATIC_SFC_IMPORT_RE only matches imports under
// `../../components/...` (relative path of 25 admin route modules). That is the
// real allowlist — there is no second filter. If a future module imports a
// non-component module via that exact path, this script will lazy-wrap it; in
// practice, the `../../components/` prefix has been stable across the codebase.
// (Removed `shouldSkipImport` stub flagged by Claude W1-C audit R1 — it always
// returned false, gave false sense of additional filtering.)

function transformContent(filename, content) {
    const chunk = CHUNK_MAPPING[filename] || CHUNK_MAPPING.DEFAULT;
    let convertedCount = 0;

    const transformed = content.replace(STATIC_SFC_IMPORT_RE, (match, ident, quote, importPath) => {
        convertedCount++;
        return `const ${ident} = () => import(/* webpackChunkName: "${chunk}" */ ${quote}${importPath}${quote});`;
    });

    if (convertedCount === 0) return { transformed: content, convertedCount, chunk };

    // Add header banner once (only if not already present)
    if (!transformed.includes(HEADER_TAG)) {
        const banner =
            `// ${HEADER_TAG} Lazy-load all SFC imports into webpack chunk "${chunk}".\n` +
            `// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by\n` +
            `// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint\n` +
            `// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).\n`;
        return { transformed: banner + transformed, convertedCount, chunk };
    }

    return { transformed, convertedCount, chunk };
}

function main() {
    const allFiles = fs.readdirSync(MODULES_DIR).filter(f => f.endsWith('.js'));
    const targets = allFiles.filter(f => !EXCLUDED.has(f));

    console.log(`[lazy_router_modules] ${APPLY ? 'APPLY MODE' : 'DRY-RUN MODE (use --apply to write)'}`);
    console.log(`[lazy_router_modules] modules dir: ${MODULES_DIR}`);
    console.log(`[lazy_router_modules] targeting ${targets.length}/${allFiles.length} files`);
    console.log(`[lazy_router_modules] excluded: ${[...EXCLUDED].join(', ')}\n`);

    let totalConverted = 0;
    const summary = [];

    for (const filename of targets) {
        const filePath = path.join(MODULES_DIR, filename);
        const content = fs.readFileSync(filePath, 'utf8');
        const { transformed, convertedCount, chunk } = transformContent(filename, content);

        if (convertedCount === 0) {
            summary.push({ filename, converted: 0, chunk: '-', note: 'no static SFC import found' });
            continue;
        }

        summary.push({ filename, converted: convertedCount, chunk });
        totalConverted += convertedCount;

        if (APPLY) {
            fs.writeFileSync(filePath, transformed, 'utf8');
        }
    }

    console.log('FILE                                      | CHUNK            | IMPORTS CONVERTED');
    console.log('------------------------------------------+------------------+-------------------');
    for (const s of summary) {
        const fn = s.filename.padEnd(42);
        const ch = String(s.chunk).padEnd(16);
        const cnt = String(s.converted);
        console.log(`${fn}| ${ch} | ${cnt}`);
    }
    console.log('------------------------------------------+------------------+-------------------');
    console.log(`TOTAL imports converted: ${totalConverted}`);

    if (!APPLY) {
        console.log('\nDRY-RUN: no files written. Re-run with --apply to write.');
    } else {
        console.log('\nAPPLIED. Review with: git diff --stat resources/js/router/modules/');
        console.log('Then build: npm run production');
    }
}

main();
