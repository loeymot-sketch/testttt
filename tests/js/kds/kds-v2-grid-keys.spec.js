import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsV2Grid from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import { ORDER_STATUS } from '../../../resources/js/helpers/kdsState.js';

/**
 * [P2-k 2026-07-18 REGISTRE_FINAL] KDS — the [A]–[H] keyboard shortcut must only
 * bump a card that is ACTUALLY ON SCREEN.
 *
 * [KDS-6CARDS GOAL-8AXES 2026-08-05] La grille rend désormais TOUTES les
 * commandes actives (flux horizontal, 6 par écran — révocation owner du mandat
 * 3-cartes). L'invariant P2-k demeure : un raccourci ne bumpe QUE ce qui est
 * garanti à l'écran SANS scroll → `shortcutOrders = activeOrders.slice(0, 6)`.
 * [G]/[H] (au-delà des 6 garanties visibles) = no-op.
 *
 * This test mounts the real component (real computeds: FIFO sort → activeOrders
 * filter → shortcut slice(0,6) → real onCtaTap emit) and dispatches real
 * `keydown` events on `window` (the component binds its listener there).
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

    it('renders ALL active cards, 6 shortcut slots, overflow beyond 6 (baseline)', () => {
        wrapper = mountGrid(makeActiveOrders(8));
        expect(wrapper.vm.activeOrders.length).toBe(8);
        // [KDS-6CARDS] toutes rendues (flux horizontal)…
        expect(wrapper.vm.visibleActiveOrders.length).toBe(8);
        // …mais 6 slots de raccourcis (garantis à l'écran) et +2 en pastille.
        expect(wrapper.vm.shortcutOrders.length).toBe(6);
        expect(wrapper.vm.overflowActiveCount).toBe(2);
    });

    it('pressing [A]–[F] bumps the corresponding guaranteed-visible order', async () => {
        wrapper = mountGrid(makeActiveOrders(8));

        for (const k of ['A', 'B', 'C', 'D', 'E', 'F']) press(k);
        await wrapper.vm.$nextTick();

        const emitted = wrapper.emitted('change-status') || [];
        expect(emitted.length).toBe(6);
        // A→id100 … F→id105 (ACCEPT→PREPARING).
        emitted.forEach((e, i) => {
            expect(e[0]).toMatchObject({ orderId: 100 + i, status: ORDER_STATUS.PREPARING });
        });
    });

    it('pressing [G]/[H] (beyond the 6 guaranteed-visible cards) emits NOTHING', async () => {
        wrapper = mountGrid(makeActiveOrders(8));

        // Sanity: overflow orders DO exist at activeOrders[6]/[7] — they must
        // never be bumped by a shortcut (P2-k).
        expect(wrapper.vm.activeOrders[6]?.id).toBe(106);
        expect(wrapper.vm.activeOrders[7]?.id).toBe(107);

        press('G');
        press('H');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('change-status')).toBeUndefined();
    });

    it('a key with only 1 visible card ignores [B]–[H] but honors [A]', async () => {
        wrapper = mountGrid(makeActiveOrders(1));
        expect(wrapper.vm.shortcutOrders.length).toBe(1);

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
