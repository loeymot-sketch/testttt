/**
 * [KDS-03 FIX] An admin account (branch_id <= 0) viewing the KDS early-returns
 * from subscribeEcho() (branch-scoped Echo channel) → ZERO live push. The WS
 * transport still reports 'connected', so wsConnected is true and NO fallback
 * banner shows → the kitchen is SILENTLY blind to the missing real-time feed.
 *
 * The existing `kdsIsCentralAdmin` banner is deliberately gated on
 * branchCount > 1 (sentinel WT-B-R1-007 — kdsVisualHealsWaveT.spec.js), so on a
 * single-branch install like Le Cayenne the admin gets NO warning at all.
 *
 * FIX (scope-minimal, non-frozen, reuses existing i18n key + child banner):
 *   - NEW computed `kdsAdminNoPush` = authBranchId() <= 0 (the TRUE degraded
 *     condition: an admin account never subscribes to the branch Echo channel,
 *     regardless of branchCount). We DO NOT touch kdsIsCentralAdmin or the
 *     branch-scoping security logic in subscribeEcho().
 *   - OR `kdsAdminNoPush` into BOTH banner surfaces:
 *       V2  : :admin-polling-hint="kdsIsCentralAdmin || kdsAdminNoPush"
 *       leg : v-if="kdsIsCentralAdmin || kdsAdminNoPush"
 *     → reuses label.kds_admin_polling_hint ("Mode admin centralisé …") which is
 *       honest (admin IS polling-only) and is NOT the legacy
 *       "multi-succursales / n'est pas abonné" copy the sentinel forbids.
 *
 * Source defect: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md
 * (KDS-03 P2). subscribeEcho early-return ~:1911-1914.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '..', '..');
const SRC = fs.readFileSync(
    path.join(ROOT, 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
    'utf8'
);

describe('KDS-03 — admin-account (branchId<=0) no-push degradation is VISIBLE', () => {
    it('declares a kdsAdminNoPush computed flagged with the [KDS-03 FIX] marker', () => {
        expect(SRC).toMatch(/\[KDS-03 FIX\]/);
        const computed = SRC.match(/kdsAdminNoPush\s*\(\)\s*\{[\s\S]*?\n\s{4,6}\},/);
        expect(computed, 'expected kdsAdminNoPush computed').not.toBeNull();
    });

    it('kdsAdminNoPush derives from authBranchId() <= 0 (no branchCount gate)', () => {
        const computed = SRC.match(/kdsAdminNoPush\s*\(\)\s*\{[\s\S]*?\n\s{4,6}\},/)[0];
        expect(computed).toMatch(/authBranchId\(\)/);
        expect(computed).toMatch(/<=\s*0/);
        // Must NOT re-gate on branchCount (that would re-hide it for single-branch).
        expect(computed).not.toMatch(/branchCount/);
    });

    it('OR-wires kdsAdminNoPush into the V2 admin-polling-hint prop', () => {
        // :admin-polling-hint="kdsIsCentralAdmin || kdsAdminNoPush"
        expect(SRC).toMatch(/:admin-polling-hint="kdsIsCentralAdmin\s*\|\|\s*kdsAdminNoPush"/);
    });

    it('OR-wires kdsAdminNoPush into the legacy admin-polling banner v-if', () => {
        // v-if="kdsIsCentralAdmin || kdsAdminNoPush"
        expect(SRC).toMatch(/v-if="kdsIsCentralAdmin\s*\|\|\s*kdsAdminNoPush"/);
    });

    it('behavioral: kdsAdminNoPush is true for an admin account (branchId 0 / -1) and false for branch staff', () => {
        // Extract the computed body and evaluate it against a stub `this`.
        const body = SRC.match(/kdsAdminNoPush\s*\(\)\s*\{([\s\S]*?)\n\s{4,6}\},/)[1];
        // eslint-disable-next-line no-new-func
        const fn = new Function(`return (function(){${body}});`)();

        expect(fn.call({ authBranchId: () => 0 })).toBe(true);
        expect(fn.call({ authBranchId: () => -1 })).toBe(true);
        expect(fn.call({ authBranchId: () => 1 })).toBe(false);
        expect(fn.call({ authBranchId: () => 5 })).toBe(false);
    });

    it('does NOT weaken kdsIsCentralAdmin (sentinel WT-B-R1-007 stays green)', () => {
        const central = SRC.match(/kdsIsCentralAdmin\s*\(\)\s*\{[\s\S]*?\n\s{4,6}\},/);
        expect(central, 'kdsIsCentralAdmin computed must still exist').not.toBeNull();
        expect(central[0]).toMatch(/branchCount/);
        expect(central[0]).toMatch(/>\s*1/);
    });

    it('does NOT alter the branch-scoping early-return in subscribeEcho', () => {
        // The security guard "if (branchId <= 0) return;" must remain untouched.
        expect(SRC).toMatch(/const branchId = this\.authBranchId\(\);\s*\n\s*if \(branchId <= 0\) return;/);
    });
});
