import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { creerRappelPeriodique, INTERVALLE_RAPPEL_MS } from '../../resources/js/helpers/orderArrivalChime.js';

/**
 * [RAPPEL-COMMANDES 2026-10-19 · owner] « Parfois on les oublie. » Les 3 sonneries d'arrivée
 * s'arrêtent au bout de 20 s ; une commande web laissée « à traiter » devenait muette. Le rappel
 * revient toutes les 2 minutes TANT qu'il en reste, et se tait tout seul à zéro.
 */
describe('rappel périodique des commandes à traiter', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('ne sonne pas tant que rien ne reste à traiter', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();
        r.actualiser(0, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS * 5);
        expect(jouer).not.toHaveBeenCalled();
        expect(r.actif()).toBe(false);
    });

    it('rappelle toutes les 2 minutes tant qu’il reste une commande, avec le nombre à jour', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();

        r.actualiser(2, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS - 1);
        expect(jouer).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(jouer).toHaveBeenLastCalledWith(2);

        r.actualiser(3, jouer); // une 3e arrive : même minuterie, nombre à jour
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS);
        expect(jouer).toHaveBeenCalledTimes(2);
        expect(jouer).toHaveBeenLastCalledWith(3);
    });

    it('ne cumule JAMAIS les minuteries : actualiser dix fois = un seul rappel par échéance', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();
        for (let i = 0; i < 10; i += 1) r.actualiser(1, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS);
        expect(jouer).toHaveBeenCalledTimes(1);
    });

    it('se tait dès que le compteur retombe à zéro — plus aucun rappel, minuterie libérée', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();
        r.actualiser(1, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS);
        expect(jouer).toHaveBeenCalledTimes(1);

        r.actualiser(0);
        expect(r.actif()).toBe(false);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS * 10);
        expect(jouer).toHaveBeenCalledTimes(1);
    });

    it('un son bloqué (autoplay) ne tue pas les rappels suivants', () => {
        const jouer = vi.fn(() => { throw new Error('autoplay bloqué'); });
        const r = creerRappelPeriodique();
        r.actualiser(1, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS * 3);
        expect(jouer).toHaveBeenCalledTimes(3);
    });

    it('arreter() (démontage de l’écran) coupe tout', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();
        r.actualiser(4, jouer);
        r.arreter();
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS * 3);
        expect(jouer).not.toHaveBeenCalled();
    });

    it('valeurs absurdes : compteur négatif ou NaN = rien à traiter', () => {
        const jouer = vi.fn();
        const r = creerRappelPeriodique();
        r.actualiser(-3, jouer);
        r.actualiser(Number.NaN, jouer);
        vi.advanceTimersByTime(INTERVALLE_RAPPEL_MS * 2);
        expect(jouer).not.toHaveBeenCalled();
    });
});

describe('câblage dans le suivi des commandes', () => {
    const SRC = fs.readFileSync(
        path.resolve(__dirname, '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'), 'utf8'
    );

    it('le compteur « web à traiter » arme le rappel, et le démontage le coupe', () => {
        expect(SRC).toMatch(/webActionableCount\(n\)\s*\{[\s\S]{0,600}this\._actualiserRappel\(n\)/);
        expect(SRC).toMatch(/_rappelCommandes\?\.arreter\?\.\(\)/);
        expect(SRC).toContain('creerRappelPeriodique');
    });

    it('le rappel respecte le réglage son de la caisse', () => {
        const bloc = SRC.slice(SRC.indexOf('_actualiserRappel(n) {'));
        expect(bloc.slice(0, 900)).toMatch(/_newOrderSoundEnabled\(\)/);
    });
});
