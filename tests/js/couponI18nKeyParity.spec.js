/**
 * [abuse-e2e B10 2026-06-16] The LIVE coupon create/list screens referenced label.* keys absent from
 * fr.json → vue-i18n returned the raw key string (truthy), so `$t('label.monday_short') || 'Mon'`
 * never fell through and the day chips rendered "label.monday_short" etc. to the operator.
 *
 * Heal: the missing label.* keys were added to fr.json (+ en.json). This spec extends the parity
 * sentinel coverage to the coupons/ dir (which labelKeyParityFrontend.spec.js does NOT scan) and is
 * mutation-resistant — remove a key from fr.json → RED.
 */
import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../..');
const COUPON_DIR = path.join(REPO_ROOT, 'resources/js/components/admin/coupons');
const fr = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'resources/js/languages/fr.json'), 'utf-8'));

function get(obj, dotted) {
    return dotted.split('.').reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), obj);
}

function vueFiles(dir) {
    return fs.readdirSync(dir).filter((f) => f.endsWith('.vue')).map((f) => path.join(dir, f));
}

describe('coupon i18n parity (B10)', () => {
    it('every $t("label.*") used in coupons/ resolves to a non-empty fr.json value', () => {
        const re = /\$t\(\s*['"](label\.[a-z0-9_.]+)['"]\s*[,)]/g;
        const missing = new Set();
        for (const file of vueFiles(COUPON_DIR)) {
            const src = fs.readFileSync(file, 'utf-8');
            let m;
            while ((m = re.exec(src)) !== null) {
                const v = get(fr, m[1]);
                if (v === undefined || v === null || typeof v === 'object' || v === '') {
                    missing.add(m[1]);
                }
            }
        }
        expect([...missing].sort()).toEqual([]);
    });

    it('the 7 day-short keys (the original raw-render bug) are present + non-empty', () => {
        for (const d of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']) {
            const v = get(fr, `label.${d}_short`);
            expect(typeof v === 'string' && v.length > 0, `label.${d}_short must exist`).toBe(true);
            expect(v).not.toMatch(/^label\./); // never the raw key
        }
    });
});
