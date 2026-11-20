import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import AvailabilityTogglePanel from '../../resources/js/components/admin/shared/AvailabilityTogglePanel.vue';
import statusEnum from '../../resources/js/enums/modules/statusEnum';

/**
 * Le panneau rupture (86) de la caisse et de la cuisine ne liste que ce qui SE VEND.
 *
 * Le défaut, établi sur la base d'exploitation puis confirmé par lecture du code : depuis
 * ONB-11 l'endpoint `admin/item` renvoie AUSSI les articles désactivés (sans quoi le
 * commerçant ne pouvait plus jamais en réactiver un). Le panneau l'appelait sans filtre de
 * statut. Mesuré à l'époque : 53 lignes « En rupture » dont 52 sur des produits
 * DÉSACTIVÉS — le cuisinier et le caissier voyaient 52 produits qu'ils ne peuvent pas vendre
 * présentés comme des ruptures à traiter. Aujourd'hui les données ont été nettoyées et le
 * chiffre est revenu à 3, mais la cause structurelle est intacte : le moindre article
 * désactivé en rupture réapparaîtrait, et les désactivés polluent la liste « disponibles ».
 *
 * Mettre en rupture un produit qui n'est pas au menu n'a aucun sens : le panneau doit
 * demander le même périmètre que la carte, c'est-à-dire les articles ACTIFS.
 */
function monterAvecListe(articles) {
    const listsAction = vi.fn().mockResolvedValue({ data: { data: articles } });
    const store = createStore({
        state: { auth: { authBranchId: 1 } },
        modules: {
            item: { namespaced: true, actions: { lists: listsAction, details: vi.fn() } },
            itemAvailability: {
                namespaced: true,
                actions: { toggle: vi.fn(), toggleExtra: vi.fn(), toggleVariation: vi.fn() },
            },
        },
    });
    const wrapper = mount(AvailabilityTogglePanel, {
        props: { visible: true },
        global: {
            plugins: [store],
            mocks: { $t: (k) => k },
            stubs: { teleport: true },
        },
    });
    return { wrapper, listsAction };
}

describe('panneau rupture — périmètre', () => {
    it('demande uniquement les articles ACTIFS au serveur', async () => {
        const { wrapper, listsAction } = monterAvecListe([
            { id: 1, name: 'Tacos', is_available: true },
        ]);
        await wrapper.setProps({ visible: false });
        await wrapper.setProps({ visible: true });
        await flushPromises();

        expect(listsAction).toHaveBeenCalled();
        const payload = listsAction.mock.calls.at(-1)[1];
        expect(
            Number(payload.status),
            "Le panneau doit passer `status: ACTIVE` : sans lui, `admin/item` renvoie aussi les "
                + 'articles désactivés, qui apparaissent comme des ruptures à traiter.',
        ).toBe(Number(statusEnum.ACTIVE));
    });

    it('garde le reste de la requête intacte (branche, pagination large, tri)', async () => {
        const { wrapper, listsAction } = monterAvecListe([]);
        await wrapper.setProps({ visible: false });
        await wrapper.setProps({ visible: true });
        await flushPromises();

        const payload = listsAction.mock.calls.at(-1)[1];
        expect(payload.branch_id).toBe(1);
        expect(payload.per_page).toBe(500);
        expect(payload.order_column).toBe('name');
    });
});
