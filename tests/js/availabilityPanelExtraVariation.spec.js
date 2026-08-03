import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import AvailabilityTogglePanel from '../../resources/js/components/admin/shared/AvailabilityTogglePanel.vue';

/**
 * [GOAL PARITE-SYNC 2026-07-18 / chantier 2 D1] Le panneau rupture partagé
 * (caisse + cuisine) permet de mettre en rupture (86) un EXTRA ou une VARIATION
 * précis, pas seulement l'item entier. On prouve que :
 *  - déplier un item charge ses options via l'endpoint EXISTANT item/details,
 *  - basculer un extra/variation appelle le bon endpoint (extraId/variationId,
 *    branch_id, is_available, reason whitelisté), met à jour l'UI et émet `changed`,
 *  - la réactivation d'une option en rupture n'envoie PAS de reason.
 */
function buildStore({ detailsData } = {}) {
    const listsAction = vi.fn().mockResolvedValue({
        data: { data: [
            { id: 10, name: 'Tacos', is_available: true },
            { id: 11, name: 'Burger', is_available: true },
        ] },
    });

    const details = detailsData ?? {
        extras: [
            { id: 100, name: 'Sauce Algérienne', group_label: 'sauce', is_available: true },
            { id: 101, name: 'Cheddar', group_label: 'supplément', is_available: false },
        ],
        variations: {
            5: [
                { id: 200, name: 'Maxi', item_attribute: { name: 'Taille' }, is_available: true },
            ],
        },
    };
    const detailsAction = vi.fn().mockResolvedValue({ data: { data: details } });

    const toggleExtra = vi.fn().mockResolvedValue({ data: { ok: true } });
    const toggleVariation = vi.fn().mockResolvedValue({ data: { ok: true } });

    const store = createStore({
        state: { auth: { authBranchId: 1 } },
        modules: {
            item: {
                namespaced: true,
                actions: { lists: listsAction, details: detailsAction },
            },
            itemAvailability: {
                namespaced: true,
                actions: {
                    toggle: vi.fn().mockResolvedValue({ data: { ok: true } }),
                    toggleExtra,
                    toggleVariation,
                },
            },
        },
    });

    return { store, listsAction, detailsAction, toggleExtra, toggleVariation };
}

async function mountPanel(ctx) {
    const wrapper = mount(AvailabilityTogglePanel, {
        props: { visible: false },
        global: {
            plugins: [ctx.store],
            mocks: { $t: (key) => key },
        },
    });
    await wrapper.setProps({ visible: true }); // déclenche fetchItems (watch)
    await flushPromises();
    return wrapper;
}

describe('AvailabilityTogglePanel — rupture ciblée extra/variation (D1)', () => {
    let ctx;
    beforeEach(() => {
        ctx = buildStore();
    });

    it('déplie un item et charge ses options via item/details (endpoint existant)', async () => {
        const wrapper = await mountPanel(ctx);

        await wrapper.find('[data-testid="availability-options-10"]').trigger('click');
        await flushPromises();

        expect(ctx.detailsAction).toHaveBeenCalledTimes(1);
        // branch-aware : l'appel porte l'id + le branch_id de la session.
        expect(ctx.detailsAction.mock.calls[0][1]).toEqual({ id: 10, branch_id: 1 });

        // extras + variations rendus avec leurs boutons de bascule.
        expect(wrapper.find('[data-testid="availability-extra-toggle-100"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="availability-extra-toggle-101"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="availability-variation-toggle-200"]').exists()).toBe(true);
    });

    it('met un EXTRA disponible en rupture avec reason whitelisté + émet changed', async () => {
        const wrapper = await mountPanel(ctx);
        await wrapper.find('[data-testid="availability-options-10"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="availability-extra-toggle-100"]').trigger('click');
        await flushPromises();

        expect(ctx.toggleExtra).toHaveBeenCalledTimes(1);
        expect(ctx.toggleExtra.mock.calls[0][1]).toEqual({
            extraId: 100,
            branchId: 1,
            isAvailable: false,
            reason: 'out_of_stock_manual',
        });

        const changed = wrapper.emitted('changed');
        expect(changed).toBeTruthy();
        expect(changed[changed.length - 1][0]).toEqual({
            kind: 'extra', id: 100, itemId: 10, isAvailable: false,
        });
        // état local mis à jour (dispo -> rupture)
        expect(wrapper.vm.choices[10].extras.find((e) => e.id === 100).is_available).toBe(false);
    });

    it('RÉACTIVE un extra déjà en rupture SANS reason', async () => {
        const wrapper = await mountPanel(ctx);
        await wrapper.find('[data-testid="availability-options-10"]').trigger('click');
        await flushPromises();

        // extra 101 est is_available:false au chargement -> clic = réactivation
        await wrapper.find('[data-testid="availability-extra-toggle-101"]').trigger('click');
        await flushPromises();

        expect(ctx.toggleExtra).toHaveBeenCalledTimes(1);
        expect(ctx.toggleExtra.mock.calls[0][1]).toEqual({
            extraId: 101,
            branchId: 1,
            isAvailable: true,
            reason: null,
        });
        expect(wrapper.vm.choices[10].extras.find((e) => e.id === 101).is_available).toBe(true);
    });

    it('met une VARIATION en rupture via le bon endpoint', async () => {
        const wrapper = await mountPanel(ctx);
        await wrapper.find('[data-testid="availability-options-10"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="availability-variation-toggle-200"]').trigger('click');
        await flushPromises();

        expect(ctx.toggleVariation).toHaveBeenCalledTimes(1);
        expect(ctx.toggleVariation.mock.calls[0][1]).toEqual({
            variationId: 200,
            branchId: 1,
            isAvailable: false,
            reason: 'out_of_stock_manual',
        });
        const changed = wrapper.emitted('changed');
        expect(changed[changed.length - 1][0]).toEqual({
            kind: 'variation', id: 200, itemId: 10, isAvailable: false,
        });
    });

    it('affiche « aucune option » pour un item sans extra/variation', async () => {
        ctx = buildStore({ detailsData: { extras: [], variations: {} } });
        const wrapper = await mountPanel(ctx);

        await wrapper.find('[data-testid="availability-options-11"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('availability.options_none');
    });
});
