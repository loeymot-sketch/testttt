import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => true),
        statusClass: vi.fn(() => 'status-active'),
        requestHandler: vi.fn(() => ''),
        destroyConfirmation: vi.fn(() => Promise.resolve()),
    },
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        successFlip: vi.fn(),
        error: vi.fn(),
    },
}));

import CatalogStudioComponent from '../../resources/js/components/admin/items/CatalogStudioComponent.vue';

const categories = [
    { id: 42, name: 'Tacos', product_count: 2 },
    { id: 99, name: 'Burgers', product_count: 1 },
];

const products = [
    {
        id: 7,
        name: 'Tacos M',
        item_category_id: 42,
        category_name: 'Tacos',
        flat_price: '9.90',
        status: 5,
        thumb: '/uploads/tacos.jpg',
        is_available: true,
    },
];

function mountStudio(selectedCategoryId = 42) {
    const dispatch = vi.fn(() => Promise.resolve());
    const resolve = vi.fn((route) => ({
        href: route.name === 'admin.categories.composer'
            ? `/admin/categories/${route.params.id}/composer`
            : `/admin/items/show/${route.params.id}/composer`,
    }));

    const wrapper = mount(CatalogStudioComponent, {
        global: {
            stubs: {
                LoadingComponent: true,
                AvailabilityToggleComponent: true,
                RouterLink: {
                    props: ['to'],
                    template: '<a><slot /></a>',
                },
            },
            mocks: {
                $t: (key, params) => {
                    if (key === 'studio.products_count') {
                        return `${params.n} produits`;
                    }
                    return key;
                },
                $route: { query: {} },
                $router: { resolve, push: vi.fn() },
                $store: {
                    dispatch,
                    getters: {
                        'itemCategory/lists': categories,
                        'item/lists': products,
                        'tax/lists': [],
                    },
                },
            },
        },
    });

    wrapper.vm.selectedCategoryId = selectedCategoryId;
    return { wrapper, resolve };
}

describe('CatalogStudio category wizard entry', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // [ONB-03 2026-08-28] Le drapeau `wizard_per_item_demo` n'etait pas pose : ce
        // banc mesurait donc le Studio dans un etat ou le bouton composeur ne devrait
        // PAS s'afficher, puisque le routeur redirige quand il est eteint. On le pose
        // ici — c'est l'etat d'une installation ou la fonctionnalite est activee, et
        // c'est bien ce que ce banc veut verifier : un bouton par produit.
        window.foodkingConfig = { features: { wizard_per_item_demo: true } };
    });

    it('shows the category wizard button when a category is selected', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').text()).toContain('Wizard de la catégorie');
    });

    it('hides the category wizard button on all categories view', async () => {
        const { wrapper } = mountStudio(null);
        await flushPromises();

        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').exists()).toBe(false);
    });

    it('opens the composer drawer with the selected category route', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        await wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').trigger('click');

        expect(wrapper.find('[data-testid="catalog-studio-composer-overlay"]').exists()).toBe(true);
        expect(wrapper.vm.composerDrawerUrl).toBe('/admin/categories/42/composer');
        // [ONB 2026-08-28] Cette phrase affirmait « Ce wizard s'applique à TOUS les
        // produits de cette catégorie. » C'est FAUX : `createForCategory()` écrit
        // `item_id => null`, une contrainte SQL l'impose, et les cinq lecteurs de
        // production interrogent tous `whereIn('item_id', …)`. Les deux méthodes
        // capables de résoudre une catégorie n'ont aucun appelant.
        //
        // Le corriger exige de toucher `PricingService` (zone gelée §7) : le dossier
        // d'arbitrage est monté (`docs/gates/GATE_WIZARD_CATEGORIE_JAMAIS_LU_2026-08-28.md`).
        // Mais retirer une affirmation fausse de l'écran n'est pas une décision
        // d'architecture — un bouton qui ment coûte plus cher qu'un bouton absent.
        //
        // On vérifie donc que l'écran porte l'avertissement, pas la promesse.
        expect(wrapper.text()).toContain("n'est PAS encore appliqué à la borne ni à la caisse");
        expect(wrapper.text()).not.toContain("s'applique à TOUS les produits");
    });

    it('exposes per-product composer buttons on product cards when catalog compose is allowed', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        const productWizardButtons = wrapper.findAll('[data-testid^="catalog-studio-product-wizard-"]');
        expect(productWizardButtons.length).toBe(products.length);
    });

    // [ONB-03 2026-08-28] Le pendant, qui manquait : drapeau eteint, aucun bouton.
    // Il l'est PAR DEFAUT (config/catalog_v15.php, .env.example), et le routeur
    // redirige alors vers le catalogue. Un bouton visible qui ne mene nulle part est
    // pire qu'un bouton absent : le commercant croit avoir rate quelque chose.
    it('hides the per-product composer button when the demo flag is off', async () => {
        window.foodkingConfig = { features: { wizard_per_item_demo: false } };

        const { wrapper } = mountStudio(42);
        await flushPromises();

        const productWizardButtons = wrapper.findAll('[data-testid^="catalog-studio-product-wizard-"]');
        expect(productWizardButtons.length).toBe(0);
    });
});
