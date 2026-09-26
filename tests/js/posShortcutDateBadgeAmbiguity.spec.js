/**
 * [ULTRA-AUDIT 2026-09-26 · P0-18/A2] `queue_number` ("N°A0043") est un compteur QUOTIDIEN
 * par branche (OrderService::allocateQueueNumber — remise à zéro chaque business_date, PAR
 * CONCEPTION). Rapport externe (Codex, 24/09) : une commande non encaissée depuis plusieurs
 * jours reste visible dans la file "à encaisser" en même temps qu'une commande fraîche
 * portant le MÊME numéro court — les cartes de ce panneau n'affichaient QUE ce numéro, sans
 * date, aucun moyen de les distinguer sans ouvrir chaque commande (risque : encaisser/remettre
 * la mauvaise commande). Ce spec prouve que shortcutDateBadge() lève l'ambiguïté.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

describe('PosComponent.shortcutDateBadge — désambiguïsation du numéro court quotidien', () => {
    let realDate;
    beforeEach(() => {
        realDate = Date;
        // Fige "aujourd'hui" au 26/09/2026 pour un test déterministe.
        function MockDate(...args) {
            if (args.length) return new realDate(...args);
            return new realDate('2026-09-26T12:00:00+02:00');
        }
        MockDate.prototype = realDate.prototype;
        global.Date = MockDate;
    });
    afterEach(() => { global.Date = realDate; });

    const call = (o) => PosComponent.methods.shortcutDateBadge.call(null, o);

    it('commande du jour même heure tardive → aucun badge (pas de bruit visuel)', () => {
        expect(call({ id: 1, created_at: '2026-09-26T23:55:00+02:00' })).toBe('');
    });

    it('commande de la veille → badge "25/09" (lève l\'ambiguïté)', () => {
        expect(call({ id: 2, created_at: '2026-09-25T17:58:00+02:00' })).toBe('25/09');
    });

    it('commande vieille de plusieurs jours (le cas réel du rapport : 22/09 vs 24/09) → badge non vide et distinct par jour', () => {
        const badge22 = call({ id: 3, created_at: '2026-09-22T14:21:00+02:00' });
        const badge24 = call({ id: 4, created_at: '2026-09-24T00:00:00+02:00' });
        expect(badge22).not.toBe('');
        expect(badge24).not.toBe('');
        expect(badge22).not.toBe(badge24);
    });

    it('sans created_at → chaîne vide, jamais une exception qui casserait le rendu', () => {
        expect(call({ id: 5 })).toBe('');
        expect(call(null)).toBe('');
    });

    it('deux commandes MÊME queue_number, jours différents → deviennent visuellement distinctes', () => {
        const stale = { id: 10, queue_number: 'A0043', created_at: '2026-09-22T14:21:00+02:00' };
        const fresh = { id: 11, queue_number: 'A0043', created_at: '2026-09-26T09:00:00+02:00' };
        expect(stale.queue_number).toBe(fresh.queue_number); // le numéro court est bien identique (comportement voulu, compteur quotidien)
        expect(call(stale)).not.toBe(call(fresh)); // mais le badge de date les distingue désormais
        expect(call(fresh)).toBe(''); // la commande du jour ne montre pas de bruit
        expect(call(stale)).not.toBe(''); // la commande périmée est signalée
    });
});
