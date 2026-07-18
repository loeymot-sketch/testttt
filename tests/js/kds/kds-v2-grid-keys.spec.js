import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsV2Grid from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import { ORDER_STATUS } from '../../../resources/js/helpers/kdsState.js';

/**
 * [P2-k 2026-07-18 REGISTRE_FINAL] KDS — the [A]–[H] keyboard shortcut must only
 * bump a card that is ACTUALLY ON SCREEN.
 *
 * Bug: the grid renders `visibleActiveOrders = activeOrders.slice(0, 3)` (three
 * full-height cards A/B/C, KDS-3CARDS c70b1e518) while any 4th+ active order
 * waits behind the « +N en attente » chip. But `onKey` used to index the FULL
 * `activeOrders` queue (up to 8 letters A–H) and bound on `activeOrders.length`.
 * So pressing [D]–[H] selected an order that was NOT rendered and dispatched a
 * real transition (change-status → ACCEPT→PREPARING… server-side) plus a client
 * notification on an invisible ticket.
 *
 * Fix (KdsV2Grid.onKey): index `visibleActiveOrders` and bound on its length, so
 * a key beyond the 3 visible cards is a no-op.
 *
 * This test mounts the real component (real computeds: FIFO sort → activeOrders
 * filter → slice(0,3) → real onCtaTap emit) and dispatches real `keydown` events
 * on `window` (the component binds its listener there in mounted()).
 */
describe('KDS — [A]–[H] never bumps an off-screen order (P2-k)', () => {
    let wrapper;

    afterEach(() => {
        // Unmount removes the window keydown listener so instances never leak
        // across tests (each test mounts a fresh single-listener grid).
        if (wrapper) {
            wrapper.unmount();
            wrapper = null;
        }
    });

    // Five ACTIVE (ACCEPT) orders, FIFO by created_at_iso ascending so the slot
    // order is deterministic: A=id100, B=id101, C=id102 (visible) then
    // D=id103, E=id104 (overflow, behind the +N chip — NOT rendered).
    function makeActiveOrders(n = 5) {
        const base = Date.UTC(2026, 6, 18, 10, 0, 0);
        return Array.from({ length: n }, (_, i) => ({
            id: 100 + i,
            queue_number: `A00${i + 1}`,
            status: ORDER_STATUS.ACCEPT,
            created_at_iso: new Date(base + i * 60_000).toISOString(),
        }));
    }

    function mountGrid(orders) {
        return mount(KdsV2Grid, {
            props: { orders },
            global: {
                mocks: { $t: (k) => k },
                // Stub the children — we exercise the grid's own keyboard logic,
                // not the card/banner rendering.
                stubs: { KdsOrderCard: true, KdsStatusBanner: true },
            },
        });
    }

    function press(key) {
        window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    }

    it('renders exactly 3 active cards and reports the overflow (baseline)', () => {
        wrapper = mountGrid(makeActiveOrders(5));
        expect(wrapper.vm.activeOrders.length).toBe(5);
        expect(wrapper.vm.visibleActiveOrders.length).toBe(3);
        expect(wrapper.vm.overflowActiveCount).toBe(2);
    });

    it('pressing [A]/[B]/[C] bumps the corresponding VISIBLE order', async () => {
        wrapper = mountGrid(makeActiveOrders(5));

        press('A');
        press('B');
        press('C');
        await wrapper.vm.$nextTick();

        const emitted = wrapper.emitted('change-status') || [];
        expect(emitted.length).toBe(3);
        // A→id100, B→id101, C→id102 (ACCEPT→PREPARING = status 7).
        expect(emitted[0][0]).toMatchObject({ orderId: 100, status: ORDER_STATUS.PREPARING });
        expect(emitted[1][0]).toMatchObject({ orderId: 101, status: ORDER_STATUS.PREPARING });
        expect(emitted[2][0]).toMatchObject({ orderId: 102, status: ORDER_STATUS.PREPARING });
    });

    it('pressing [D]–[H] (beyond the 3 visible cards) emits NOTHING', async () => {
        wrapper = mountGrid(makeActiveOrders(5));

        // Sanity: an overflow order DOES exist at activeOrders[3]/[4] — the
        // pre-fix code would have targeted it. It must never be bumped.
        expect(wrapper.vm.activeOrders[3]?.id).toBe(103);
        expect(wrapper.vm.activeOrders[4]?.id).toBe(104);

        press('D');
        press('E');
        press('F');
        press('G');
        press('H');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('change-status')).toBeUndefined();
    });

    it('a key with only 1 visible card ignores [B]–[H] but honors [A]', async () => {
        wrapper = mountGrid(makeActiveOrders(1));
        expect(wrapper.vm.visibleActiveOrders.length).toBe(1);

        press('B'); // no 2nd card
        press('C');
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('change-status')).toBeUndefined();

        press('A');
        await wrapper.vm.$nextTick();
        const emitted = wrapper.emitted('change-status') || [];
        expect(emitted.length).toBe(1);
        expect(emitted[0][0]).toMatchObject({ orderId: 100 });
    });

    it('an unrelated key (e.g. Z / empty grid) is a no-op', async () => {
        wrapper = mountGrid(makeActiveOrders(5));
        press('Z');
        press('1');
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('change-status')).toBeUndefined();
    });
});
