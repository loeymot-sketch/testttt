import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsOrderCard from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';

/**
 * [KDS-SCHEDULED-CARD-MISLEADS 2026-07-22] A scheduled order released to the
 * kitchen at T-lead used to show the "ATTENTE" timer counted from created_at —
 * so an order placed hours ahead looked like a monstrous overdue delay the moment
 * it appeared. The card must instead:
 *   1. anchor the timer on the RELEASE instant (kitchen_timer_anchor_iso =
 *      scheduled_at - lead, backend-computed), max()'d with created_at;
 *   2. show a "🕐 prévue HH:MM" badge for the target time.
 * ASAP orders (no scheduled_at) are byte-for-byte unchanged (created_at anchor,
 * no badge).
 */

const NOW = 1_800_000_000_000;
const iso = (ms) => new Date(ms).toISOString();
const minsAgo = (m) => NOW - m * 60_000;
const hoursAgo = (h) => NOW - h * 3_600_000;

// Return the i18n key on every miss (mirrors vue-i18n) EXCEPT the scheduled label,
// which we resolve so the badge text is asserted end-to-end.
const $t = (k, params) => {
    if (k === 'label.kds_scheduled_for' && params && params.time) {
        return `prévue ${params.time}`;
    }
    return k;
};

function mountCard(order) {
    return mount(KdsOrderCard, {
        props: { order, now: NOW },
        global: {
            stubs: { KdsOrderLine: true },
            mocks: { $t },
        },
    });
}

describe('KdsOrderCard — scheduled order timer + badge (KDS-SCHEDULED-CARD-MISLEADS)', () => {
    it('anchors the ATTENTE timer on release (scheduled_at - lead), NOT created_at, and shows "prévue HH:MM"', () => {
        // Placed 3h ago, released to the kitchen 5 min ago (scheduled_at - lead).
        const wrapper = mountCard({
            id: 5,
            status: 4, // ACCEPT
            source_surface: 'kiosk',
            scheduled_hm: '12:30',
            kitchen_timer_anchor_iso: iso(minsAgo(5)),
            created_at_iso: iso(hoursAgo(3)),
            order_items: [],
        });

        // Timer counts from the release anchor (~5 min), not created_at (~3h).
        expect(wrapper.vm.createdMs).toBe(minsAgo(5));
        expect(wrapper.vm.elapsedSeconds).toBe(300);
        expect(wrapper.find('.kds-card__elapsed').text()).toBe('05:00');

        // "🕐 prévue 12:30" badge present.
        const badge = wrapper.find('[data-testid="kds-card-scheduled-5"]');
        expect(badge.exists()).toBe(true);
        expect(badge.text()).toContain('prévue 12:30');
    });

    it('ASAP order (no scheduled_at) keeps the created_at anchor and shows NO badge', () => {
        const wrapper = mountCard({
            id: 6,
            status: 4,
            source_surface: 'pos',
            created_at_iso: iso(hoursAgo(3)),
            order_items: [],
        });

        expect(wrapper.vm.createdMs).toBe(hoursAgo(3));
        expect(wrapper.find('[data-testid="kds-card-scheduled-6"]').exists()).toBe(false);
    });

    it('walk-in scheduled INSIDE the lead window counts from created_at (max guard, never before it existed)', () => {
        // Release would be 30 min ago, but the order was only created 10 min ago.
        const wrapper = mountCard({
            id: 7,
            status: 7, // PREPARING
            source_surface: 'pos',
            scheduled_hm: '12:10',
            kitchen_timer_anchor_iso: iso(minsAgo(30)),
            created_at_iso: iso(minsAgo(10)),
            order_items: [],
        });

        // max(release=-30m, created=-10m) = created (-10m).
        expect(wrapper.vm.createdMs).toBe(minsAgo(10));
        expect(wrapper.vm.elapsedSeconds).toBe(600);
        expect(wrapper.find('[data-testid="kds-card-scheduled-7"]').exists()).toBe(true);
    });
});
