import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService';

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.1 · défaut mesuré, non listé par Codex]
 *
 * Les quatre cartes datées du tableau de bord passent l'objet `Date` du sélecteur
 * DIRECTEMENT dans la requête. `encodeURIComponent(new Date(...))` appelle `toString()`,
 * qui rend :
 *
 *     Sun Mar 01 2026 00:00:00 GMT+0100 (heure normale d’Europe centrale)
 *
 * Mesuré côté serveur : `Carbon::parse()` REFUSE cette chaîne (« Could not parse »). Le
 * suffixe localisé entre parenthèses la rend illisible. Autrement dit, choisir une période
 * personnalisée sur le tableau de bord ne renvoyait pas des chiffres faux — ça n'en
 * renvoyait aucun, et l'écran affichait le message d'exception interne (cf. P2-D).
 *
 * La normalisation est faite dans `requestHandler`, pas dans chacune des quatre cartes :
 * une cinquième carte écrite demain hériterait sinon du même défaut.
 *
 * Le jour est pris en heure LOCALE, jamais via `toISOString()` : le 1er mars à minuit à
 * Paris vaut `2026-02-28T23:00:00Z`, donc l'ISO reculerait la période d'un jour — le
 * défaut qu'on veut précisément éviter.
 */
describe('les dates envoyées par le tableau de bord sont des jours civils', () => {
    it('un objet Date devient AAAA-MM-JJ en heure locale', () => {
        const url = appService.requestHandler({
            first_date: new Date(2026, 2, 1),
            last_date: new Date(2026, 2, 31),
        });

        expect(decodeURIComponent(url)).toBe('?first_date=2026-03-01&last_date=2026-03-31');
    });

    it('minuit ne recule pas d’un jour (piège toISOString sur Paris)', () => {
        // 1er janvier 00:00 à Paris = 31 décembre 23:00 UTC.
        const url = appService.requestHandler({ first_date: new Date(2026, 0, 1) });
        expect(decodeURIComponent(url)).toBe('?first_date=2026-01-01');
    });

    it('le passage à l’heure d’été ne décale pas la date', () => {
        const url = appService.requestHandler({
            first_date: new Date(2026, 2, 29),
            last_date: new Date(2026, 9, 25),
        });
        expect(decodeURIComponent(url)).toBe('?first_date=2026-03-29&last_date=2026-10-25');
    });

    it('les valeurs non-Date sont inchangées', () => {
        const url = appService.requestHandler({ q: 'burger', page: 2, first_date: '2026-03-01' });
        expect(decodeURIComponent(url)).toBe('?q=burger&page=2&first_date=2026-03-01');
    });

    it('aucune date n’est envoyée quand le champ est vide', () => {
        expect(appService.requestHandler({ first_date: '', last_date: null })).toBe('');
    });
});
