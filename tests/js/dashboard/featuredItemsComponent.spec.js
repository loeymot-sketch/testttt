import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FeaturedItemsComponent from '../../../resources/js/components/admin/dashboard/FeaturedItemsComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] Carte « Produits mis en avant » du tableau de bord.
 *
 * DÉFAUT MESURÉ AVANT CORRECTIF, en deux temps :
 *
 *  1. `featured_items` était initialisé à `{}` — un OBJET. Le `v-if="featured_items.length > 0"`
 *     du template lit donc `undefined`, qui est faux : la carte se rend vide. Tant que la
 *     requête n'a pas répondu, et pour toujours si elle échoue, l'écran affiche une carte
 *     avec son titre et rien dessous.
 *  2. Le `.catch` ne faisait que couper le voile de chargement (`loading.isActive = false`).
 *     Aucune trace de l'échec, aucun message. Une 403 (rôle sans droit), une 500 et une
 *     journée sans produit mis en avant produisaient donc **le même pixel**.
 *
 * Une carte vide qui peut vouloir dire « rien à montrer » OU « je n'ai rien pu lire » ne
 * dit rien du tout. Ce banc exige que les deux soient distinctes à l'écran.
 */
const PRODUITS = [
    { id: 12, name: 'Tacos Poulet', thumb: '/img/tacos.jpg' },
    { id: 31, name: 'Bols César', thumb: '/img/bol.jpg' },
];

function monter(dispatch) {
    return mount(FeaturedItemsComponent, {
        global: {
            mocks: { $store: { dispatch }, $t: (k) => k },
            stubs: { LoadingComponent: true },
        },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('FeaturedItemsComponent — produits mis en avant', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('succès : rend un article par produit, avec son nom', async () => {
        const w = monter(succes(PRODUITS));
        await flushPromises();

        expect(w.findAll('[data-testid^="featured-item-"]')).toHaveLength(2);
        expect(w.text()).toContain('Tacos Poulet');
        expect(w.find('[data-testid="featured-items-error"]').exists()).toBe(false);
        expect(w.find('[data-testid="featured-items-empty"]').exists()).toBe(false);
    });

    it('liste vide : le dit explicitement, sans prétendre à une panne', async () => {
        const w = monter(succes([]));
        await flushPromises();

        expect(w.find('[data-testid="featured-items-empty"]').exists()).toBe(true);
        expect(w.find('[data-testid="featured-items-error"]').exists()).toBe(false);
    });

    /** LE CAS QUI COMPTE : erreur ≠ vide. */
    it("403 : l'erreur ne se déguise PAS en « aucun produit mis en avant »", async () => {
        const w = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        expect(
            w.find('[data-testid="featured-items-error"]').exists(),
            'un refus de droits doit être annoncé, pas rendu comme une liste vide',
        ).toBe(true);
        expect(w.find('[data-testid="featured-items-empty"]').exists()).toBe(false);
    });

    it('500 : état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="featured-items-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="featured-items-empty"]').exists()).toBe(false);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur, et le voile de chargement retombe', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="featured-items-error"]').exists()).toBe(true);
        expect(w.vm.loading.isActive, 'un écran bloqué en chargement est une autre façon de mentir').toBe(false);
    });

    /**
     * `featured_items: {}` — un objet là où le template attend un tableau. `.length` y vaut
     * `undefined`, donc la carte se rendait vide sans que rien ne soit encore arrivé.
     */
    it('part d\'un tableau, pas d\'un objet : `.length` doit être lisible avant toute réponse', () => {
        const initial = FeaturedItemsComponent.data();
        expect(Array.isArray(initial.featured_items)).toBe(true);
        expect(initial.featured_items.length).toBe(0);
    });

    // ---- T5.2 : accessibilité ----

    it('la clé de boucle est l\'identifiant du produit, pas l\'objet lui-même', async () => {
        const w = monter(succes(PRODUITS));
        await flushPromises();

        const avant = w.find('[data-testid="featured-item-12"]').element;
        await w.setData({ featured_items: [...PRODUITS].reverse() });

        expect(
            w.find('[data-testid="featured-item-12"]').element,
            'clé instable : le nœud du produit 12 a été recyclé',
        ).toBe(avant);
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
