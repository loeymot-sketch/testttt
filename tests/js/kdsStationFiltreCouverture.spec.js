import { describe, expect, it } from 'vitest';
import {
    normalizeKdsStation,
    orderMatchesStationFilter,
    filterOrdersByStation,
} from '../../resources/js/helpers/kdsDisplay.js';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-3.2.1 / T-3.2.2]
 *
 * LE DÉFAUT, ÉTABLI PAR LECTURE DE CODE ET MESURE EN BASE
 * --------------------------------------------------------
 * `resources/js/helpers/kdsDisplay.js` range tout `null` ou chaîne vide en `'none'`, puis :
 *
 *     orderMatchesStationFilter(order, filter)
 *         → items.some((line) => normalizeKdsStation(line.kds_station) === filter)
 *
 * Le menu déroulant du KDS (`KitchenDisplaySystemComponent.vue:309-312`) n'offre que
 * `all`, `bar`, `cuisine_chaude`, `cuisine_froide`. **Aucune option `none`.**
 *
 * Donc une commande composée uniquement d'articles `none` n'est visible que si l'opérateur est
 * resté sur « toutes les stations ». Et ce filtre est persisté par utilisateur en `localStorage`
 * (`persistKdsUiPrefs`) : une fois « bar » choisi, le choix colle d'un service à l'autre.
 *
 * MESURE EN BASE LE 2026-08-25 — 59 articles vendables :
 *     bar 8 · cuisine_chaude 37 · cuisine_froide 3 · **none 11**
 *
 * Parmi les 11 : sept boissons (#119 Coca Cherry … #125 Perrier) alors que huit boissons de même
 * nature (#52-59) sont en `bar` — un lot ajouté plus tard sans poste. Et #1 « Menu (Frites +
 * Boisson) » / #2 « Frites Seules », alors que des frites se cuisent.
 *
 * CE QUE CES TESTS FONT
 * ---------------------
 * Ils épinglent le comportement RÉEL du filtre, sans le changer. Rendre le seau `none` atteignable
 * — ou réattribuer les postes — est une décision d'exploitation du propriétaire, pas un correctif
 * d'agent (CLAUDE.md §3bis : ne jamais inventer de données menu).
 *
 * Si un jour une option `none` est ajoutée au menu déroulant, le test marqué ⚠️ ci-dessous
 * échouera : ce sera le signal que la décision a été prise, pas une régression.
 */

const POSTES_SELECTIONNABLES = ['bar', 'cuisine_chaude', 'cuisine_froide'];

const commande = (stations) => ({
    id: 1,
    order_items: stations.map((s, i) => ({ id: i + 1, kds_station: s })),
});

describe('KDS — couverture du filtre de station', () => {
    it('range toute valeur vide ou inconnue en « none »', () => {
        expect(normalizeKdsStation(null)).toBe('none');
        expect(normalizeKdsStation('')).toBe('none');
        expect(normalizeKdsStation(undefined)).toBe('none');
        expect(normalizeKdsStation('poste_inexistant')).toBe('none');
        expect(normalizeKdsStation('bar')).toBe('bar');
    });

    it('⚠️ une commande entièrement « none » est invisible sous CHAQUE poste sélectionnable', () => {
        // C'est le cœur du défaut : un client qui commande seulement un Coca Cherry (#119)
        // ou des Frites Seules (#2) disparaît de l'écran d'un opérateur filtré.
        const seulementNone = commande(['none', null, '']);

        for (const poste of POSTES_SELECTIONNABLES) {
            expect(
                orderMatchesStationFilter(seulementNone, poste),
                `Une commande 100% « none » ne devrait pas être invisible sous « ${poste} ». `
                + `Si ce test échoue, c'est que le seau « none » a été rendu atteignable — `
                + `décision propriétaire, pas régression.`,
            ).toBe(false);
        }
    });

    it('reste visible sous « toutes les stations », seul recours actuel', () => {
        const seulementNone = commande(['none']);
        expect(orderMatchesStationFilter(seulementNone, 'all')).toBe(true);
        expect(filterOrdersByStation([seulementNone], 'all')).toHaveLength(1);
    });

    it('une commande mixte reste visible dès qu’UNE ligne porte le poste', () => {
        const mixte = commande(['none', 'bar']);
        expect(orderMatchesStationFilter(mixte, 'bar')).toBe(true);
        expect(orderMatchesStationFilter(mixte, 'cuisine_chaude')).toBe(false);
    });

    it('un filtre absent ou vide ne masque rien', () => {
        const c = commande(['none']);
        expect(filterOrdersByStation([c], null)).toHaveLength(1);
        expect(filterOrdersByStation([c], '')).toHaveLength(1);
        expect(filterOrdersByStation([c], undefined)).toHaveLength(1);
    });

    it('une commande sans lignes ne fait pas planter le filtre', () => {
        // Robustesse : une commande vide ne doit pas jeter, sinon l'écran cuisine se fige.
        for (const poste of [...POSTES_SELECTIONNABLES, 'all']) {
            expect(() => filterOrdersByStation([{ id: 9 }], poste)).not.toThrow();
        }
        expect(filterOrdersByStation([{ id: 9 }], 'bar')).toHaveLength(0);
    });

    it('documente que le menu déroulant n’offre aucune option « none »', async () => {
        const fs = await import('fs');
        const source = fs.readFileSync(
            'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
            'utf8',
        );
        const options = [...source.matchAll(/<option value="([a-z_]+)"/g)].map((m) => m[1]);

        expect(options).toContain('all');
        for (const poste of POSTES_SELECTIONNABLES) expect(options).toContain(poste);
        expect(
            options,
            'Si « none » apparaît ici, le seau invisible est devenu atteignable : mettez à jour '
            + 'ce test ET le rapport KDS_STATIONS pour acter la décision.',
        ).not.toContain('none');
    });
});
