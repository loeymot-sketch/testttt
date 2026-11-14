/**
 * [F-ITEM-SAVE-MUET 2026-09-03]
 *
 * Sentinelle — un refus d'enregistrement doit se VOIR.
 *
 * Le formulaire produit affiche ses erreurs sous le champ concerné, donc en HAUT
 * du panneau, alors que le bouton « Enregistrer » est tout en BAS. Un commerçant
 * qui enregistre depuis le bas ne voyait donc rien du tout : pas d'alerte, pas de
 * déplacement, le panneau restait ouvert, inchangé. Le défaut vécu, mot pour mot :
 * « je modifie un produit, ça ne s'enregistre jamais ».
 *
 * Reproduit en production le 2026-09-03 : modifier « Sandwich Classique » renvoyait
 * 422 (« nom déjà utilisé », à cause d'une fiche homonyme DÉSACTIVÉE), et l'écran
 * n'en disait rien.
 *
 * Ce banc échoue si l'on retire l'alerte : c'est sa raison d'être.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

const alertServiceMock = vi.hoisted(() => ({
    error: vi.fn(),
    success: vi.fn(),
    successFlip: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
}));

const appServiceMock = vi.hoisted(() => ({
    sideDrawerShow: vi.fn(),
    sideDrawerHide: vi.fn(),
    modalShow: vi.fn(),
    modalHide: vi.fn(),
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: alertServiceMock,
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: appServiceMock,
}));
vi.mock(
    '../../resources/js/components/admin/components/buttons/SmSidebarModalCreateComponent',
    () => ({
        default: { name: 'SmSidebarModalCreateComponent', template: '<div />' },
    }),
);
vi.mock('../../resources/js/components/admin/components/LoadingComponent', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));

import ItemCreateComponent from '../../resources/js/components/admin/items/ItemCreateComponent.vue';

const MESSAGE_SERVEUR =
    'Le nom « Sandwich Classique » est deja porte par le produit #25 (DESACTIVE). Renommez l\'un des deux.';

const monterFormulaire = ({ refus }) => {
    const propsObj = {
        form: {
            name: 'Sandwich Classique',
            price: '7.40',
            description: '',
            caution: '',
            is_featured: 1,
            is_upsell: 0,
            item_type: 1,
            item_category_id: 1,
            tax_id: 3,
            status: 1,
        },
        search: { keyword: '' },
    };

    const storeMock = {
        getters: new Proxy(
            {
                'item/temp': { temp_id: 119 },
                'itemCategory/lists': [],
                'tax/lists': [],
            },
            {
                get(cible, propriete) {
                    return propriete in cible ? cible[propriete] : [];
                },
            },
        ),
        dispatch: vi.fn((action) => {
            if (action === 'item/save') {
                return Promise.reject(refus);
            }
            return Promise.resolve();
        }),
        commit: vi.fn(),
    };

    const TestComponent = { ...ItemCreateComponent, mounted() {} };

    const wrapper = shallowMount(TestComponent, {
        props: { props: propsObj },
        global: {
            stubs: {
                SmSidebarModalCreateComponent: true,
                LoadingComponent: true,
                'vue-select': true,
            },
            mocks: {
                $store: storeMock,
                $t: (cle) => cle,
                $route: { params: {}, query: {}, name: '' },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });

    return { wrapper, storeMock };
};

describe('Formulaire produit — un refus d\'enregistrement doit se voir', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.foodkingConfig = { features: { wizard_per_item_demo: false } };
    });

    it('affiche une alerte visible quand le serveur refuse un champ (422)', async () => {
        const { wrapper } = monterFormulaire({
            refus: {
                response: {
                    status: 422,
                    data: { message: 'Erreur de validation.', errors: { name: [MESSAGE_SERVEUR] } },
                },
            },
        });

        wrapper.vm.save();
        await flushPromises();

        // Le message reste attaché au champ, comme avant…
        expect(wrapper.vm.errors.name[0]).toBe(MESSAGE_SERVEUR);
        // …mais il est AUSSI annoncé de façon visible, où que se trouve le regard.
        expect(alertServiceMock.error).toHaveBeenCalledWith(MESSAGE_SERVEUR);
    });

    it('ramène le panneau sur le premier champ fautif', async () => {
        const marqueur = document.createElement('small');
        marqueur.className = 'db-field-alert';
        marqueur.scrollIntoView = vi.fn();
        document.body.appendChild(marqueur);

        const { wrapper } = monterFormulaire({
            refus: {
                response: {
                    status: 422,
                    data: { errors: { name: [MESSAGE_SERVEUR] } },
                },
            },
        });

        wrapper.vm.save();
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(marqueur.scrollIntoView).toHaveBeenCalled();
        marqueur.remove();
    });

    it('annonce aussi les erreurs sans détail par champ', async () => {
        const { wrapper } = monterFormulaire({
            refus: {
                response: { status: 500, data: { message: 'Le serveur a renoncé.' } },
            },
        });

        wrapper.vm.save();
        await flushPromises();

        expect(alertServiceMock.error).toHaveBeenCalledWith('Le serveur a renoncé.');
    });

    it('ne laisse pas le formulaire bloqué en « chargement » après un refus', async () => {
        const { wrapper } = monterFormulaire({
            refus: {
                response: { status: 422, data: { errors: { name: [MESSAGE_SERVEUR] } } },
            },
        });

        wrapper.vm.save();
        await flushPromises();

        expect(wrapper.vm.loading.isActive).toBe(false);
    });
});
