import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-2.2.2] Fiche produit : l'échec d'enregistrement se VOIT près d'« Enregistrer ».
 *
 * Mémoire `formulaire-admin-echec-muet` (2026-09-03) : 42 fiches sur 70 ne s'enregistraient pas et rien ne bougeait à
 * l'écran — les erreurs de champ sont en haut du tiroir, le bouton tout en bas. Le correctif de septembre a ajouté un
 * toast + un défilement ; ce test verrouille en plus un message PERSISTANT à côté du bouton (`item-save-error`,
 * `role="alert"`), même règle que le formulaire de code promo (`couponEnregistrementErreurVisible.spec.js`).
 */
const alertes = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), successFlip: vi.fn(), info: vi.fn(), warning: vi.fn() }));
vi.mock('../../resources/js/services/alertService', () => ({ default: alertes }));
vi.mock('../../resources/js/services/appService', () => ({ default: { sideDrawerShow: vi.fn(), sideDrawerHide: vi.fn(), modalShow: vi.fn(), modalHide: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/buttons/SmSidebarModalCreateComponent', () => ({ default: { name: 'SmSidebarModalCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));

import ItemCreateComponent from '../../resources/js/components/admin/items/ItemCreateComponent.vue';

function monter(rejet) {
    const form = { name: 'Cayenne', price: '9.50', description: '', caution: '', is_featured: 10, is_upsell: 10, item_type: 1, item_category_id: 1, tax_id: null, status: 5, channels: [], allergen_flags: [], kds_station: 'none', order: 1 };
    const store = {
        getters: new Proxy({ 'item/temp': { temp_id: 12 }, 'itemCategory/lists': [], 'tax/lists': [] }, { get: (t, p) => (p in t ? t[p] : []) }),
        dispatch: vi.fn((action) => (action === 'item/save' ? Promise.reject(rejet) : Promise.resolve())),
        commit: vi.fn(),
    };
    const w = shallowMount({ ...ItemCreateComponent, mounted() {} }, {
        props: { props: { form, search: { keyword: '' } } },
        global: {
            stubs: { SmSidebarModalCreateComponent: true, LoadingComponent: true, 'vue-select': true },
            mocks: { $store: store, $t: (k) => k, $route: { params: {}, query: {}, name: '' }, $router: { push: vi.fn(), replace: vi.fn() } },
        },
    });
    return { w, store };
}

describe('admin — fiche produit : l\'échec d\'enregistrement se voit près du bouton', () => {
    beforeEach(() => vi.clearAllMocks());

    it('422 : message persistant près d\'« Enregistrer », rôle alert, toast en plus', async () => {
        const { w } = monter({ response: { status: 422, data: { message: 'The given data was invalid.', errors: { is_featured: ['Valeur de mise en avant invalide.'] } } } });
        await w.vm.save();
        await flushPromises();
        const msg = w.find('[data-testid="item-save-error"]');
        expect(msg.exists()).toBe(true);
        expect(msg.attributes('role')).toBe('alert');
        expect(msg.text()).toContain('pas enregistré');
        expect(alertes.error).toHaveBeenCalledWith('Valeur de mise en avant invalide.');
    });

    it('réseau coupé : message lisible, pas de plantage ; une nouvelle tentative efface l\'ancien message', async () => {
        const { w, store } = monter(new Error('Network Error'));
        await w.vm.save();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').text()).toContain('Vérifiez la connexion');

        store.dispatch.mockImplementation(() => new Promise(() => {})); // enregistrement en cours
        w.vm.save();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(false);
    });

    it('refus serveur avec message (403, 500) : le message du serveur est repris tel quel', async () => {
        const { w } = monter({ response: { status: 403, data: { message: 'Vous n\'avez pas le droit de modifier ce produit.' } } });
        await w.vm.save();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').text()).toBe('Vous n\'avez pas le droit de modifier ce produit.');
    });

    it('fermer la fiche efface le message : la fiche suivante s\'ouvre sans le refus de la précédente', async () => {
        const { w } = monter({ response: { status: 422, data: { message: 'x', errors: { name: ['Le nom est obligatoire.'] } } } });
        await w.vm.save();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(true);
        w.vm.reset(); // « Fermer »
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(false);
        // Ouverture d'une autre fiche : ItemListComponent.edit remplace `props.form` d'un bloc SANS passer par reset.
        await w.vm.save();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(true);
        await w.setProps({ props: { form: { ...w.props('props').form, name: 'Autre fiche' }, search: { keyword: '' } } });
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(false);
        w.vm.saveError = 'reste';
        w.vm.addReset();
        await flushPromises();
        expect(w.find('[data-testid="item-save-error"]').exists()).toBe(false);
    });
});
