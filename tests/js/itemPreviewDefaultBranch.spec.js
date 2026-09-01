import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ItemPreviewComponent from '../../resources/js/components/admin/items/ItemPreviewComponent.vue';
import fr from '../../resources/js/languages/fr.json';

const localeFr = fr?.default ?? fr;

/**
 * [CHEF 2026-09-01] L'APERÇU DOIT S'OUVRIR SUR LA SUCCURSALE EXPLOITÉE.
 *
 * Défaut corrigé : le composant faisait `this.branches[0]?.id`. Or
 * `BranchService::list()` trie par défaut en `id desc` — la liste arrive donc
 * 10, 9, 8, 7, 2, 1, et « Le Cayenne (principal) » (id 1) est EN DERNIER.
 * L'aperçu s'ouvrait sur une succursale héritée du jeu de test, où rien n'est
 * publié, et affichait « Article non disponible sur la caisse pour cette
 * succursale » — pour un produit parfaitement en vente.
 *
 * Le fixture reproduit exactement cet ordre : c'est lui le piège, pas le nombre
 * de succursales. Un test qui passerait une liste déjà triée ne prouverait rien.
 *
 * `Status::ACTIVE = 5` : seule la principale porte ce statut, les autres sont à
 * `status = 1`, valeur qui ne correspond à aucun statut du domaine.
 */
vi.mock('axios', () => ({
    default: { get: vi.fn(() => Promise.resolve({ data: { categories: [] } })) },
}));

// Ordre RÉEL renvoyé par l'API (id desc) : la principale arrive en dernier.
const BRANCHES_ID_DESC = [
    { id: 10, name: 'Collier and Sons Branch', status: 1 },
    { id: 9, name: 'Skiles-Johns Branch', status: 1 },
    { id: 8, name: 'Brekke, Kub and Reichert Branch', status: 1 },
    { id: 7, name: 'Shields Inc Branch', status: 1 },
    { id: 2, name: 'Stiedemann and Sons Branch', status: 1 },
    { id: 1, name: 'Le Cayenne (principal)', status: 5 },
];

function monter(branches) {
    const i18n = createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });
    return mount(ItemPreviewComponent, {
        props: { item: { id: 22, name: 'Cayenne' }, branches },
        global: { plugins: [i18n] },
    });
}

describe("aperçu article — succursale par défaut", () => {
    it("s'ouvre sur la succursale ACTIVE même si l'API la renvoie en dernier", () => {
        const w = monter(BRANCHES_ID_DESC);
        expect(w.vm.selectedBranchId).toBe(1);
        // Garde anti-test-vide : le fixture doit bien piéger l'ancien code.
        expect(BRANCHES_ID_DESC[0].id).not.toBe(1);
    });

    it('retombe sur la première succursale si aucune n’est active', () => {
        const w = monter([
            { id: 7, name: 'Shields Inc Branch', status: 1 },
            { id: 2, name: 'Stiedemann and Sons Branch', status: 1 },
        ]);
        expect(w.vm.selectedBranchId).toBe(7);
    });

    it('ne choisit rien quand la liste est vide, sans lever', () => {
        const w = monter([]);
        expect(w.vm.selectedBranchId).toBeNull();
    });
});
