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

/**
 * [2026-09-02] Deux défauts constatés en navigateur sur le MÊME composant, corrigés ici.
 *
 * 1. `stepsForChannel()` ne filtrait que `visible_on`, alors que le runtime filtre d'abord
 *    `is_active` (ComposerProfileProjection.php:36). Le rayon Bols annonçait 5 pages dont
 *    « Choisis ton pain » — pour un bol — parce que `pain`, `viande` et `garnitures` sont
 *    INACTIVES dans le profil #33 mais restaient affichées.
 * 2. `formatPrice()` était un passe-plat : « 6.9 » au lieu de « 6,90 € », sur un écran dont
 *    l'onglet voisin affiche déjà le format FR. ADR-007.
 */
describe('aperçu article — étapes et prix', () => {
    function apercu(steps) {
        const i18n = createI18n({ legacy: true, locale: 'fr', messages: { fr: localeFr }, silentFallbackWarn: true });
        return mount(ItemPreviewComponent, {
            props: { item: { id: 45, name: 'Bol Riz' }, branches: BRANCHES_ID_DESC, steps },
            global: { plugins: [i18n] },
        });
    }

    // Le profil Bols réel : trois pages désactivées, deux actives.
    const ETAPES_BOLS = [
        { id: 1, step_key: 'pain', label: 'Choisis ton pain', is_active: 0 },
        { id: 2, step_key: 'viande', label: 'Choisis ta viande', is_active: 0 },
        { id: 3, step_key: 'sauce', label: 'Choisis ta sauce', is_active: 1 },
        { id: 4, step_key: 'garnitures', label: 'Choisis tes garnitures', is_active: false },
        { id: 5, step_key: 'supplements', label: 'Suppléments', is_active: true },
    ];

    it("n'annonce pas les pages désactivées du profil", () => {
        const rendues = apercu(ETAPES_BOLS).vm.stepsForChannel('pos').map((e) => e.step_key);
        expect(rendues).toEqual(['sauce', 'supplements']);
        // Garde anti-test-vide : le fixture doit bien contenir des pages à écarter.
        expect(ETAPES_BOLS.filter((e) => !e.is_active).length).toBe(3);
    });

    it("garde l'étape quand is_active est absent — on ne masque pas ce qu'on ne peut pas juger", () => {
        const rendues = apercu([
            { id: 9, step_key: 'brouillon', label: 'Étape en cours' },
            { id: 10, step_key: 'nul', label: 'Sans colonne', is_active: null },
        ]).vm.stepsForChannel('pos').map((e) => e.step_key);
        expect(rendues).toEqual(['brouillon', 'nul']);
    });

    it("respecte toujours visible_on en plus de is_active", () => {
        const rendues = apercu([
            { id: 11, step_key: 'borne_seule', label: 'Borne', is_active: 1, visible_on: ['kiosk'] },
            { id: 12, step_key: 'partout', label: 'Partout', is_active: 1 },
        ]).vm.stepsForChannel('pos').map((e) => e.step_key);
        expect(rendues).toEqual(['partout']);
    });

    it('formate les prix en français, avec centimes et symbole', () => {
        const vm = apercu([]).vm;
        // \u202f / \u00a0 : Intl insère une espace insécable avant le symbole.
        expect(vm.formatPrice(6.9).replace(/\s/g, ' ')).toBe('6,90 €');
        expect(vm.formatPrice(8).replace(/\s/g, ' ')).toBe('8,00 €');
        expect(vm.formatPrice('1.9').replace(/\s/g, ' ')).toBe('1,90 €');
    });

    it('ne fabrique pas de prix à partir de rien', () => {
        const vm = apercu([]).vm;
        expect(vm.formatPrice(null)).toBe('');
        expect(vm.formatPrice(undefined)).toBe('');
        expect(vm.formatPrice('')).toBe('');
        expect(vm.formatPrice('sur devis')).toBe('sur devis');
    });
});
