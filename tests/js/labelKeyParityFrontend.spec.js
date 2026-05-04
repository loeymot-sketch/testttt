import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../..');
const FR_PATH = path.join(REPO_ROOT, 'resources/js/languages/fr.json');
const SCAN_DIRS = [
    path.join(REPO_ROOT, 'resources/js/components/admin/items'),
    path.join(REPO_ROOT, 'resources/js/components/admin/ingredients'),
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

describe('label.* i18n parity sentinel - admin catalog surfaces', () => {
    it('all $t("label.*") references in admin/items and admin/ingredients must exist in fr.json', () => {
        const fr = JSON.parse(fs.readFileSync(FR_PATH, 'utf-8'));
        const files = SCAN_DIRS.flatMap((dir) => (fs.existsSync(dir) ? walkVueFiles(dir) : []));
        const re = /\$t\(\s*['"](label\.[a-z0-9_.]+)['"]\s*\)/g;
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
            throw new Error(`Missing label.* keys in fr.json:\n  ${list}`);
        }

        expect(missing.size).toBe(0);
    });
});
