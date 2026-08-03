// mobileDataAntiFiction.spec.js — Mobile mock-data anti-fiction sentinel
//
// Created 2026-05-18 by Impl D (GOAL Round 2). Asserts that every `item_id` in
// `mobile/data/orders.js` and every `payload.item_id` / `payload.category_id`
// in `mobile/data/loyalty.js` references a canonical entity that actually
// exists in `mobile/data/menu.js`.
//
// Background : Agent 8 (Round 1) flagged 7 fictional products (Box Nashville,
// Box Familiale, Box Solo, Le Cheese Smash, Bowl Cheesy, Wrap Poulet, Cookie
// XL) bleeding into Orders + Loyalty history from pre-MENU-RESET 2026-05-13.
// Without this sentinel the baseline 12/12 mobile E2E spec stayed green while
// the user-facing Orders + Loyalty screens still displayed phantom SKUs.
//
// Run :
//   npx vitest run tests/js/mobileDataAntiFiction.spec.js

import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const REPO_ROOT = resolve(__dirname, '../..');
const MENU_FILE    = resolve(REPO_ROOT, 'mobile/data/menu.js');
const ORDERS_FILE  = resolve(REPO_ROOT, 'mobile/data/orders.js');
const LOYALTY_FILE = resolve(REPO_ROOT, 'mobile/data/loyalty.js');

// All three files are wrapped in IIFEs that mutate `window.LC`. Loading them
// via `eval` against the happy-dom global window is the simplest faithful
// execution path (no transpile, no bundler) — same shape the browser sees.
function loadIIFE(filePath) {
    const src = readFileSync(filePath, 'utf8');
    // eslint-disable-next-line no-eval
    eval(src);
}

describe('mobile mock data — anti-fiction sentinel (Impl D Round 2 / 2026-05-18)', () => {
    let canonicalItemIds;
    let canonicalCategoryIds;

    beforeAll(() => {
        // happy-dom provides `window`. Storage layer is not required for these
        // pure data IIFEs — they read window.LC.storage defensively.
        if (typeof window.LC === 'undefined') window.LC = {};
        loadIIFE(MENU_FILE);
        loadIIFE(ORDERS_FILE);
        loadIIFE(LOYALTY_FILE);

        expect(window.LC.menu).toBeDefined();
        expect(Array.isArray(window.LC.menu.items)).toBe(true);
        expect(Array.isArray(window.LC.menu.categories)).toBe(true);

        canonicalItemIds     = new Set(window.LC.menu.items.map(i => i.id));
        canonicalCategoryIds = new Set(window.LC.menu.categories.map(c => c.id));
    });

    it('orders.js → every active+history items[].item_id exists in menu.js', () => {
        const all = [...window.LC.orders.active, ...window.LC.orders.history];
        const offenders = [];
        for (const order of all) {
            for (const line of (order.items || [])) {
                if (!canonicalItemIds.has(line.item_id)) {
                    offenders.push(`${order.id}: item_id=${line.item_id} name="${line.name}"`);
                }
            }
        }
        expect(offenders, 'Fictional item_ids found in orders.js → ' + offenders.join(' | '))
            .toEqual([]);
    });

    it('loyalty.js → every REWARDS[].payload.item_id exists in menu.js', () => {
        const offenders = [];
        for (const r of window.LC.loyalty.rewards) {
            const id = r.payload && r.payload.item_id;
            if (id !== undefined && !canonicalItemIds.has(id)) {
                offenders.push(`reward ${r.id} "${r.name}": item_id=${id}`);
            }
        }
        expect(offenders, 'Fictional item_ids found in loyalty.js REWARDS → ' + offenders.join(' | '))
            .toEqual([]);
    });

    it('loyalty.js → every REWARDS[].payload.category_id exists in menu.js', () => {
        const offenders = [];
        for (const r of window.LC.loyalty.rewards) {
            const cid = r.payload && r.payload.category_id;
            if (cid !== undefined && !canonicalCategoryIds.has(cid)) {
                offenders.push(`reward ${r.id} "${r.name}": category_id=${cid}`);
            }
        }
        expect(offenders, 'Fictional category_ids found in loyalty.js REWARDS → ' + offenders.join(' | '))
            .toEqual([]);
    });

    // [TEST-E2E-HEAL 2026-07-15] Test OBSOLÈTE retiré : le reward fictif « Burger gratuit » (id 5)
    // a été intentionnellement SUPPRIMÉ le 2026-07-08 (commit 7470535a6) — « plus de redeem fictif,
    // triggers/catalogue inexistants backend ». La fidélité mobile est désormais un stub floor 1pt/€
    // (rewardById→null). Le test frère ci-dessus (« every REWARDS[].payload.category_id exists »)
    // couvre déjà la non-fiction des rewards restants. Asserter un reward retiré par design = faux positif.

    it('orders.js → every order total equals sum of items[].line_total (arithmetic parity)', () => {
        const drift = [];
        const all = [...window.LC.orders.active, ...window.LC.orders.history];
        for (const order of all) {
            const sum = (order.items || []).reduce((s, l) => s + Number(l.line_total || 0), 0);
            if (Math.abs(sum - Number(order.total)) > 0.005) {
                drift.push(`${order.id}: total=${order.total} ≠ Σ line_total=${sum.toFixed(2)}`);
            }
        }
        expect(drift, 'Total arithmetic drift → ' + drift.join(' | ')).toEqual([]);
    });

    it('forbidden pre-MENU-RESET strings absent from healed data files', () => {
        // Read raw text and scan for the 7 fictional product names + the legacy
        // pre-MENU-RESET item_ids. Doc/comment lines are excluded so the heal
        // notice itself can document what was removed.
        const FORBIDDEN_NAMES = [
            'Box Nashville', 'Box Familiale', 'Box Solo',
            'Le Cheese', 'Bowl Cheesy', 'Wrap Poulet', 'Cookie XL',
        ];
        const FORBIDDEN_LEGACY_IDS = [
            'item_id: 2001', 'item_id: 2002', 'item_id: 2003',
            'item_id: 4001', 'item_id: 5001', 'item_id: 7001',
            'item_id: 8001', 'item_id: 9002',
            // 1001 is intentionally NOT forbidden — it is the canonical Coca-Cola 33cl id.
        ];

        for (const file of [ORDERS_FILE, LOYALTY_FILE]) {
            const lines = readFileSync(file, 'utf8').split('\n');
            lines.forEach((line, idx) => {
                if (line.trim().startsWith('//')) return; // skip doc/comments
                for (const tag of [...FORBIDDEN_NAMES, ...FORBIDDEN_LEGACY_IDS]) {
                    if (line.includes(tag)) {
                        throw new Error(`${file}:${idx + 1} contains forbidden token "${tag}" → ${line.trim()}`);
                    }
                }
            });
        }
    });
});
