import { describe, it, expect } from 'vitest';

/**
 * [T-SUIVI-MINUIT 2026-08-19 · GOAL owner] Le propriétaire : « je n'arrive pas à
 * annuler les commandes qu'ils ont passé de certaines heures ».
 *
 * La cause principale (machine à états bloquant PRÊTE → ANNULÉE) est corrigée par
 * ailleurs. Mais un second trou reste : le tableau de suivi ne charge que le JOUR
 * CALENDAIRE courant. Le Cayenne servant tard, une commande prise à 23 h 50
 * DISPARAISSAIT du tableau à 00 h 00 — alors que la cuisine était encore dessus.
 * Le patron ne peut ni la suivre, ni l'annuler, ni la marquer livrée.
 *
 * On raisonne donc en JOURNÉE DE SERVICE : tant qu'on n'a pas franchi l'heure de
 * bascule (5 h du matin par défaut), la veille reste affichée AVEC le jour courant.
 * Le reste du temps le comportement est strictement inchangé — la décision
 * « board du jour » documentée pour des raisons de charge est préservée.
 */
import { serviceDayRange } from '../../resources/js/helpers/posServiceDay';

/** Construit une date locale sans ambiguïté de fuseau. */
const at = (y, m, d, h, min = 0) => new Date(y, m - 1, d, h, min, 0, 0);

describe('serviceDayRange — la commande de 23 h 50 ne disparaît plus à minuit', () => {
    it('en plein service (20 h) : uniquement le jour courant, comportement inchangé', () => {
        expect(serviceDayRange(at(2026, 8, 19, 20, 30))).toEqual({
            from: '2026-08-19',
            to: '2026-08-19',
        });
    });

    it('juste avant minuit (23 h 50) : jour courant', () => {
        expect(serviceDayRange(at(2026, 8, 19, 23, 50))).toEqual({
            from: '2026-08-19',
            to: '2026-08-19',
        });
    });

    it('CAS TERRAIN : à 00 h 10, la veille reste affichée', () => {
        // La commande de 23 h 50 est encore en cuisine : elle doit rester visible.
        expect(serviceDayRange(at(2026, 8, 20, 0, 10))).toEqual({
            from: '2026-08-19',
            to: '2026-08-20',
        });
    });

    it('à 04 h 59, la veille est toujours affichée', () => {
        expect(serviceDayRange(at(2026, 8, 20, 4, 59))).toEqual({
            from: '2026-08-19',
            to: '2026-08-20',
        });
    });

    it('à 05 h 00, la bascule est faite : nouvelle journée de service', () => {
        expect(serviceDayRange(at(2026, 8, 20, 5, 0))).toEqual({
            from: '2026-08-20',
            to: '2026-08-20',
        });
    });

    it('la bascule franchit correctement un changement de mois', () => {
        expect(serviceDayRange(at(2026, 9, 1, 2, 0))).toEqual({
            from: '2026-08-31',
            to: '2026-09-01',
        });
    });

    it('la bascule franchit correctement un changement d\'année', () => {
        expect(serviceDayRange(at(2027, 1, 1, 3, 0))).toEqual({
            from: '2026-12-31',
            to: '2027-01-01',
        });
    });

    it('l\'heure de bascule est réglable', () => {
        // À 5 h par défaut ; un service qui ferme à 3 h peut vouloir 4 h.
        expect(serviceDayRange(at(2026, 8, 20, 4, 30), 4)).toEqual({
            from: '2026-08-20',
            to: '2026-08-20',
        });
        expect(serviceDayRange(at(2026, 8, 20, 3, 30), 4)).toEqual({
            from: '2026-08-19',
            to: '2026-08-20',
        });
    });

    it('une heure de bascule aberrante retombe sur la valeur par défaut', () => {
        expect(serviceDayRange(at(2026, 8, 20, 2, 0), -3)).toEqual({
            from: '2026-08-19',
            to: '2026-08-20',
        });
        expect(serviceDayRange(at(2026, 8, 20, 2, 0), 99)).toEqual({
            from: '2026-08-19',
            to: '2026-08-20',
        });
    });
});
