/**
 * [P3 breadth-audit heal 2026-06-07] Static-source sentinel for the structural
 * contract of the P3 cosmetic heals. Follows the established static-grep pattern
 * used by sister kiosk/KDS sentinels (assert source wiring without a heavy mount
 * of components that depend on Vuex/router/axios/hardware bridges).
 *
 * Covers:
 *   - KDS-UI-05: legacy KDS lanes no longer carry hardcoded FR literals; they
 *     use label.kds_lane_empty_* + button.kds_print_ticket i18n keys.
 *   - KIOSK-OFFLINE-PLANB-01: KioskPaymentComponent renders a dedicated
 *     offline-queued state that takes precedence over the Plan-B counter-route
 *     screen, and processCashPayment short-circuits to it on an offline_ id
 *     instead of navigating to the cash-instruction screen (which would show
 *     a blank "#—").
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const read = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8');

describe('KDS-UI-05 — legacy lanes are i18n, no hardcoded FR literals', () => {
    const src = read('resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');

    it('contains NO hardcoded "Aucune commande …" lane empty-state literals', () => {
        expect(src).not.toMatch(/Aucune commande/);
    });

    it('contains NO hardcoded "Imprimer ticket" button literal', () => {
        // Only the i18n binding form `{{ $t('button.kds_print_ticket') }}` is allowed;
        // the bare text node must be gone.
        expect(src).not.toMatch(/>\s*Imprimer ticket\s*</);
    });

    it('uses the four distinct lane empty-state i18n keys', () => {
        for (const key of ['kds_lane_empty_dinein', 'kds_lane_empty_online', 'kds_lane_empty_takeaway', 'kds_lane_empty_borne']) {
            expect(src).toMatch(new RegExp(`\\$t\\(\\s*['"]label\\.${key}['"]\\s*\\)`));
        }
    });

    it('uses button.kds_print_ticket on all four legacy print buttons', () => {
        const matches = src.match(/\$t\(\s*['"]button\.kds_print_ticket['"]\s*\)/g) || [];
        // dine-in + online + takeaway + borne = 4 lanes.
        expect(matches.length).toBe(4);
    });
});

describe('KIOSK-OFFLINE-PLANB-01 — dedicated offline-queued state', () => {
    const src = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

    it('declares an offlineQueued data flag (default false)', () => {
        expect(src).toMatch(/offlineQueued:\s*false/);
    });

    it('renders a dedicated offline-queued screen with i18n title + sub', () => {
        expect(src).toMatch(/v-if="offlineQueued"/);
        expect(src).toMatch(/data-testid="kiosk-payment-offline-queued"/);
        expect(src).toMatch(/kiosk\.pay_screen\.offline_queued_title/);
        expect(src).toMatch(/kiosk\.pay_screen\.offline_queued_sub/);
    });

    it('the offline-queued screen takes precedence over the Plan-B counter-route', () => {
        // Counter-route block must now be gated off when offlineQueued is set,
        // otherwise the dead-end Plan-B screen (no button, no spinner) would show.
        expect(src).toMatch(/v-if="paymentRouteAllToCounter\s*&&\s*!offlineQueued"/);
    });

    it('processCashPayment short-circuits to the offline state on an offline_ id (no #— cash-instruction)', () => {
        // Slice processCashPayment body and assert the offline guard sets the flag
        // and returns BEFORE the router push.
        const m = src.match(/async processCashPayment\([^)]*\)\s*\{([\s\S]*?)\n {4}\},/);
        expect(m, 'processCashPayment body should be parseable').not.toBeNull();
        const body = m[1];
        expect(body).toMatch(/startsWith\(\s*['"]offline_['"]\s*\)/);
        expect(body).toMatch(/this\.offlineQueued\s*=\s*true/);
        // The guard's `return` must appear before the unconditional router push so
        // the offline path never lands on the blank-"#—" cash-instruction screen.
        const guardIdx = body.indexOf('this.offlineQueued = true');
        const pushIdx = body.indexOf('this.$router.push(navTarget)');
        expect(guardIdx).toBeGreaterThan(-1);
        expect(pushIdx).toBeGreaterThan(-1);
        expect(guardIdx).toBeLessThan(pushIdx);
    });

    it('still keeps the cash-instruction navTarget for the ONLINE cash path (sister contract)', () => {
        // The Plan-B/counter cash path for online orders must remain wired to
        // kiosk.cash-instruction — only the offline branch is diverted.
        expect(src).toMatch(/name:\s*['"]kiosk\.cash-instruction['"]/);
    });

    it('the offline-queued terminal is NOT a dead-end — it auto-returns to kiosk.idle', () => {
        // The global idle timer is disabled on kiosk.payment (noTimerRoutes) and
        // this screen has no CTA, so it MUST schedule its own return to idle or it
        // strands the next customer. Lock the recovery wiring.
        expect(src).toMatch(/OFFLINE_QUEUED_RETURN_MS:\s*\d+/);
        const m = src.match(/_scheduleOfflineQueuedReturn\s*\(\s*\)\s*\{([\s\S]*?)\n {4}\},/);
        expect(m, '_scheduleOfflineQueuedReturn body should be parseable').not.toBeNull();
        const body = m[1];
        expect(body).toMatch(/setTimeout\(/);
        expect(body).toMatch(/name:\s*['"]kiosk\.idle['"]/);
        // It must read the configurable delay (so the value is real, not 0/undefined).
        expect(body).toMatch(/this\.\$options\.OFFLINE_QUEUED_RETURN_MS/);
        // The offline branch in processCashPayment must arm it.
        expect(src).toMatch(/this\.offlineQueued\s*=\s*true[\s\S]{0,400}_scheduleOfflineQueuedReturn\(\)/);
        // And beforeUnmount must clear it (no stray navigation after the screen is gone).
        expect(src).toMatch(/clearTimeout\(this\._offlineQueuedTimer\)/);
    });
});
