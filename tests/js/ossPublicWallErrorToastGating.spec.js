/**
 * [OSS-01] PreparingAndReadyComponent.list() .catch toasted alertService.error on EVERY failed poll.
 * The public customer TV wall (authBranchId() <= 0) is unattended and polls every 5s, so a transient
 * blip stacked toasts no human can dismiss — diverging from this component's own gating (used by
 * _playReadySound and the wakeLock wiring). Heal: gate the toast behind authBranchId() > 0; the
 * attended staff surface keeps the identical toast, the data list is unchanged either way.
 *
 * Style: static SOURCE assertions (no Vue mount / Vuex / Echo) — mirrors ossChimePublicWall.spec.js
 * because this component is too heavy to mount in unit tests.
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue'),
    'utf8'
);

describe('OSS public-wall error-toast gating (OSS-01)', () => {
    it('list() .catch gates alertService.error behind authBranchId() > 0', () => {
        // the toast call must sit INSIDE an `if (this.authBranchId() > 0) { ... }` within the catch
        expect(SOURCE).toMatch(
            /\.catch\(\(err\)\s*=>\s*\{[\s\S]*?if\s*\(\s*this\.authBranchId\s*\(\s*\)\s*>\s*0\s*\)\s*\{[\s\S]*?alertService\.error\(/,
        );
    });

    it('preserves the toast for attended staff (gated, not removed)', () => {
        expect(SOURCE).toContain("alertService.error(err?.response?.data?.message || this.$t('message.something_wrong'))");
    });
});
