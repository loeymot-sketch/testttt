import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../..');
const FR_PATH = path.join(REPO_ROOT, 'resources/js/languages/fr.json');
const SCAN_DIRS = [
    path.join(REPO_ROOT, 'resources/js/components/admin/items'),
    path.join(REPO_ROOT, 'resources/js/components/admin/ingredients'),
    path.join(REPO_ROOT, 'resources/js/components/admin/demo'),
    path.join(REPO_ROOT, 'resources/js/components/layouts/backend'),
    path.join(REPO_ROOT, 'resources/js/components/admin/settings'),
];

function getNestedKey(obj, dottedPath) {
    return dottedPath
        .split('.')
        .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), obj);
}

function walkVueFiles(dir) {
    const out = [];

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const filePath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            out.push(...walkVueFiles(filePath));
        } else if (filePath.endsWith('.vue')) {
            out.push(filePath);
        }
    }

    return out;
}

// Tracked i18n prefixes scanned in the Vue admin/kiosk catalog surfaces.
// Healing G1 (audit indépendant 2026-05-04) : initial sentinel only matched
// `label.*`, leaving `studio.*`, `message.*`, `demo_wizard_advanced.*`, and
// `menu.*` keys (introduced in V1-PIVOT cycles 5/6 and V1-FINISH H2) silently
// removable without CI alert. Adding them here closes that hole.
const TRACKED_PREFIXES = [
    'label',
    'studio',
    'message',
    'demo_wizard_advanced',
    'menu',
];

describe('i18n parity sentinel - admin catalog surfaces (multi-prefix)', () => {
    it(`all $t("<prefix>.*") references for [${TRACKED_PREFIXES.join(', ')}] in five Vue scan dirs must exist in fr.json`, () => {
        const fr = JSON.parse(fs.readFileSync(FR_PATH, 'utf-8'));
        const files = SCAN_DIRS.flatMap((dir) => (fs.existsSync(dir) ? walkVueFiles(dir) : []));
        const prefixGroup = TRACKED_PREFIXES.join('|');
        // Healing A2 (audit technique final 2026-05-04) : the trailing `[,)]`
        // captures both `$t('key')` (no params, ends with `)`) and
        // `$t('key', { count: n })` (with pluralization params, ends with `,`).
        // The previous `\)` only matched no-param calls, leaving keys like
        // `label.ingredient.usage_count`, `studio.products_count`, and
        // `label.composer.preview_*` silently un-enforced.
        const re = new RegExp(
            String.raw`\$t\(\s*['"]((?:${prefixGroup})\.[a-z0-9_.]+)['"]\s*[,)]`,
            'g'
        );
        const missing = new Set();

        for (const file of files) {
            const content = fs.readFileSync(file, 'utf-8');
            let match;

            while ((match = re.exec(content)) !== null) {
                const key = match[1];
                const value = getNestedKey(fr, key);

                if (value === undefined || value === null || typeof value === 'object') {
                    missing.add(`${key}  (in ${path.relative(REPO_ROOT, file)})`);
                }
            }
        }

        if (missing.size > 0) {
            const list = [...missing].sort().join('\n  ');
            throw new Error(
                `Missing i18n keys in fr.json (tracked prefixes: ${TRACKED_PREFIXES.join(', ')}):\n  ${list}`
            );
        }

        expect(missing.size).toBe(0);
    });
});
