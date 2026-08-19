import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    creerSequenceurDeSonnerie,
    SONNERIES_PAR_ARRIVEE,
    INTERVALLE_ENTRE_SONNERIES_MS,
} from '../../resources/js/helpers/orderArrivalChime.js';

/**
 * [OWNER 2026-08-19] « 3 sonneries espacées, puis stop » — le RYTHME, verrouillé une fois pour
 * les trois surfaces (caisse, écran cuisine, écran de statut). Chacune apporte sa façon
 * d'émettre un son ; aucune ne redéfinit le rythme, sinon elles divergent comme elles avaient
 * déjà divergé (bip ×3 côté web seulement, carillon ×1 en cuisine, accord ×1 sur le statut).
 */
describe('sonnerie d’arrivée de commande', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('sonne 3 fois, espacées de 10 s, puis plus rien', () => {
        const jouer = vi.fn();
        const s = creerSequenceurDeSonnerie();

        s.declencher(jouer);
        expect(jouer, 'la 1re sonnerie part IMMÉDIATEMENT').toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(INTERVALLE_ENTRE_SONNERIES_MS - 1);
        expect(jouer, 'rien avant l’échéance').toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(1);
        expect(jouer).toHaveBeenCalledTimes(2);

        vi.advanceTimersByTime(INTERVALLE_ENTRE_SONNERIES_MS);
        expect(jouer).toHaveBeenCalledTimes(SONNERIES_PAR_ARRIVEE);

        // « puis stop » : une heure plus tard, toujours 3.
        vi.advanceTimersByTime(3600_000);
        expect(jouer, 'la séquence est BORNÉE — elle ne tourne pas en boucle').toHaveBeenCalledTimes(3);
        expect(s.enAttente()).toBe(0);
    });

    /**
     * LE DÉFAUT QUE CE MODULE CORRIGE. L'implémentation caisse empilait ses minuteries sans
     * borne : cinq commandes en une minute donnaient quinze bips entrelacés, c'est-à-dire un
     * bruit continu qu'on finit par ignorer — l'inverse du but recherché.
     */
    it('une nouvelle arrivée REMPLACE la séquence en cours au lieu de s’y empiler', () => {
        const jouer = vi.fn();
        const s = creerSequenceurDeSonnerie();

        s.declencher(jouer);                 // 1 sonnerie
        vi.advanceTimersByTime(2000);
        s.declencher(jouer);                 // arrivée n°2 → 1 sonnerie, et on repart de zéro
        expect(jouer).toHaveBeenCalledTimes(2);

        // Si les deux séquences coexistaient, on entendrait 4 sonneries de plus. Il n'en reste
        // que 2 : celles de la DERNIÈRE arrivée.
        vi.advanceTimersByTime(3600_000);
        expect(jouer).toHaveBeenCalledTimes(2 + (SONNERIES_PAR_ARRIVEE - 1));
        expect(s.enAttente()).toBe(0);
    });

    it('annuler() coupe tout — aucune minuterie ne survit au composant démonté', () => {
        const jouer = vi.fn();
        const s = creerSequenceurDeSonnerie();

        s.declencher(jouer);
        expect(s.enAttente()).toBe(SONNERIES_PAR_ARRIVEE - 1);

        s.annuler();
        expect(s.enAttente()).toBe(0);

        vi.advanceTimersByTime(3600_000);
        expect(jouer, 'une sonnerie après démontage jouerait sur un élément détruit').toHaveBeenCalledTimes(1);
    });

    /**
     * Sur une tablette dont l'autoplay est encore bloqué, `jouer()` LÈVE. Sans garde, les
     * sonneries suivantes — celles qui auraient sonné APRÈS le premier geste de l'utilisateur,
     * donc celles qui pouvaient encore sauver la commande — ne partaient jamais.
     */
    it('une sonnerie qui échoue n’interrompt pas les suivantes', () => {
        let appels = 0;
        const jouer = vi.fn(() => {
            appels += 1;
            if (appels === 1) {
                throw new Error('autoplay bloqué');
            }
        });

        const s = creerSequenceurDeSonnerie();
        expect(() => s.declencher(jouer), 'l’échec ne doit pas remonter à l’appelant').not.toThrow();

        vi.advanceTimersByTime(INTERVALLE_ENTRE_SONNERIES_MS * SONNERIES_PAR_ARRIVEE);
        expect(jouer).toHaveBeenCalledTimes(SONNERIES_PAR_ARRIVEE);
    });

    it('respecte un réglage sur mesure et refuse les valeurs absurdes', () => {
        const jouer = vi.fn();
        const s = creerSequenceurDeSonnerie({ sonneries: 2, intervalleMs: 500 });
        s.declencher(jouer);
        vi.advanceTimersByTime(500);
        expect(jouer).toHaveBeenCalledTimes(2);
        vi.advanceTimersByTime(60_000);
        expect(jouer).toHaveBeenCalledTimes(2);

        // 0 sonnerie n'a pas de sens : on retombe sur 1, jamais sur « muet ».
        const muet = vi.fn();
        creerSequenceurDeSonnerie({ sonneries: 0 }).declencher(muet);
        expect(muet, 'un réglage à 0 ne doit pas rendre la caisse silencieuse').toHaveBeenCalledTimes(1);
    });

    it('ignore un appel sans fonction de lecture plutôt que de casser la surface', () => {
        const s = creerSequenceurDeSonnerie();
        expect(() => s.declencher(undefined)).not.toThrow();
        expect(s.enAttente()).toBe(0);
    });
});
