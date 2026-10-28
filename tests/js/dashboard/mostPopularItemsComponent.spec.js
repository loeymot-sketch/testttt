import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import MostPopularItemsComponent from '../../../resources/js/components/admin/dashboard/MostPopularItemsComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] Carte « Produits les plus vendus ».
 *
 * MÊME DÉFAUT QUE `FeaturedItemsComponent`, et c'est ce qui le rend intéressant : ce
 * n'est pas un accident isolé, c'est un patron copié.
 *
 *  1. `popular_items: {}` — objet initial, donc `.length` vaut `undefined` et la carte se
 *     rend vide avant toute réponse.
 *  2. `.catch` muet : il coupe le voile de chargement et jette l'erreur.
 *
 * Conséquence concrète pour un exploitant : le jour où le classement des ventes ne se
 * charge pas — droits révoqués, service en erreur, réseau coupé — il lit « aucun produit
 * populaire » et peut en conclure que sa journée est atone. Le classement des ventes est
 * précisément la carte qu'on regarde pour décider quoi mettre en avant : s'y tromper a un
 * coût.
 */
const PRODUITS = [
    { id: 7, name: 'Tacos Mixte', category_name: 'Tacos', currency_price: '9,50 €', thumb: '/img/t.jpg' },
    { id: 9, name: 'Sandwich Poulet', category_name: 'Sandwichs', currency_price: '7,00 €', thumb: '/img/s.jpg' },
];

function monter(dispatch) {
    return mount(MostPopularItemsComponent, {
        global: {
            mocks: { $store: { dispatch }, $t: (k) => k },
            stubs: { LoadingComponent: true },
        },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('MostPopularItemsComponent — classement des ventes', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('succès : rend une ligne par produit, avec catégorie et prix', async () => {
        const w = monter(succes(PRODUITS));
        await flushPromises();

        expect(w.findAll('[data-testid^="popular-item-"]')).toHaveLength(2);
        expect(w.text()).toContain('Tacos Mixte');
        expect(w.text()).toContain('9,50 €');
        expect(w.find('[data-testid="popular-items-error"]').exists()).toBe(false);
        expect(w.find('[data-testid="popular-items-empty"]').exists()).toBe(false);
    });

    it('liste vide : le dit explicitement', async () => {
        const w = monter(succes([]));
        await flushPromises();

        expect(w.find('[data-testid="popular-items-empty"]').exists()).toBe(true);
        expect(w.find('[data-testid="popular-items-error"]').exists()).toBe(false);
    });

    /** LE CAS QUI COMPTE : erreur ≠ vide. */
    it("403 : l'erreur ne se déguise PAS en « aucun produit populaire »", async () => {
        const w = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        expect(
            w.find('[data-testid="popular-items-error"]').exists(),
            'un classement qu\'on n\'a pas pu lire n\'est pas un classement vide',
        ).toBe(true);
        expect(w.find('[data-testid="popular-items-empty"]').exists()).toBe(false);
    });

    it('500 : état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="popular-items-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="popular-items-empty"]').exists()).toBe(false);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur, et le voile de chargement retombe', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="popular-items-error"]').exists()).toBe(true);
        expect(w.vm.loading.isActive).toBe(false);
    });

    it('part d\'un tableau, pas d\'un objet', () => {
        const initial = MostPopularItemsComponent.data();
        expect(Array.isArray(initial.popular_items)).toBe(true);
        expect(initial.popular_items.length).toBe(0);
    });

    // ---- T5.2 : accessibilité ----

    it('la clé de boucle est l\'identifiant du produit, pas l\'objet lui-même', async () => {
        const w = monter(succes(PRODUITS));
        await flushPromises();

        const avant = w.find('[data-testid="popular-item-7"]').element;
        await w.setData({ popular_items: [...PRODUITS].reverse() });

        expect(w.find('[data-testid="popular-item-7"]').element).toBe(avant);
    });

    it('ne pose aucun minuteur : rien à nettoyer au démontage', async () => {
        const pose = vi.spyOn(global, 'setInterval');
        const w = monter(succes(PRODUITS));
        await flushPromises();

        expect(pose).not.toHaveBeenCalled();
        w.unmount();
        expect(w.exists()).toBe(false);
    });

    it('une réponse qui arrive APRÈS le démontage ne fait rien exploser', async () => {
        let resoudre;
        const dispatch = vi.fn(() => new Promise((r) => { resoudre = r; }));
        const w = monter(dispatch);
        w.unmount();

        resoudre({ data: { data: PRODUITS } });
        await expect(flushPromises()).resolves.not.toThrow();
    });
});
