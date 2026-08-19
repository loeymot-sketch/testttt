import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * [T-B ALERTE-WEB 2026-08-16 · GOAL owner] Owner : « les commandes du site web,
 * la caisse n'arrête pas de sonner pendant au minimum 30 secondes... je serais
 * alerté... elle sonne 3 fois chaque 10 secondes, façon Uber Eats ».
 *
 * Réalité mesurée : UN SEUL bip synthétisé de ~0,4s (Web Audio, pas 30s en
 * continu) — trop discret, raté dans le bruit ambiant d'un comptoir ("je ne
 * détecte même pas qu'il y a une commande site web"). Fix : 3 bips espacés de
 * 10s pour une commande WEB spécifiquement (comptoir/téléphone/borne/Uber
 * gardent le bip unique existant — non demandé par l'owner).
 *
 * Même correctif appliqué en miroir dans 2 fichiers (motif dupliqué déjà
 * existant avant ce fix, cf. commentaire "[CAISSE-WEB-INTEL] Miroir exact du
 * beep PosComponent") : PosOrdersTrackerComponent.vue (écran "Suivi commandes")
 * et PosComponent.vue (écran caisse principal, 2 points d'entrée : Echo
 * temps-réel + secours polling).
 *
 * [OWNER 2026-08-19] LE RÉGIME EST DÉSORMAIS LE MÊME POUR TOUS LES CANAUX D'ARRIVÉE.
 * Le 16/08, seul le WEB avait été demandé et la BORNE gardait son bip unique — une
 * limitation assumée à l'époque, pas une règle métier. Le propriétaire a tranché depuis :
 * « 3 sonneries espacées, puis stop » pour toute commande qui arrive. Le cas BORNE
 * ci-dessous a donc changé d'attendu VOLONTAIREMENT ; ce n'est pas une régression.
 *
 * Le rythme lui-même ne vit plus ici : il est dans `helpers/orderArrivalChime.js`, partagé
 * avec l'écran cuisine et l'écran de statut, et couvert par `orderArrivalChime.spec.js`.
 */
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';

describe('PosOrdersTrackerComponent — 3 bips espacés 10s pour une commande WEB', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    function ctx(overrides = {}) {
        return {
            _notifiedOrderIds: new Set(),
            _webOrderAlertTimers: [],
            _playNewOrderBeep: vi.fn(),
            _newOrderSoundEnabled: () => true,
            sourceOf: PosOrdersTrackerComponent.methods.sourceOf,
            _sonnerieArrivee: PosOrdersTrackerComponent.methods._sonnerieArrivee,
            ...overrides,
        };
    }

    it('commande WEB : le bip joue 3 fois, à t=0, t=10s, t=20s — jamais plus vite', () => {
        const c = ctx();
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 1, source_surface: 'web' });

        expect(c._playNewOrderBeep, 'immédiat à la réception').toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(9999);
        expect(c._playNewOrderBeep, 'pas encore à 9,999s').toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(1);
        expect(c._playNewOrderBeep, '2e bip pile à 10s').toHaveBeenCalledTimes(2);

        vi.advanceTimersByTime(10000);
        expect(c._playNewOrderBeep, '3e bip pile à 20s').toHaveBeenCalledTimes(3);

        vi.advanceTimersByTime(30000);
        expect(c._playNewOrderBeep, 'jamais un 4e bip').toHaveBeenCalledTimes(3);
    });

    it('commande KIOSK (borne) : MÊME régime que le web depuis l\'arbitrage owner du 19/08', () => {
        const c = ctx();
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 2, source_surface: 'kiosk' });

        expect(c._playNewOrderBeep, 'immédiat à la réception').toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(10000);
        expect(c._playNewOrderBeep, '2e sonnerie à 10s').toHaveBeenCalledTimes(2);
        vi.advanceTimersByTime(10000);
        expect(c._playNewOrderBeep, '3e sonnerie à 20s').toHaveBeenCalledTimes(3);
        vi.advanceTimersByTime(30000);
        expect(c._playNewOrderBeep, 'puis stop — jamais une 4e').toHaveBeenCalledTimes(3);
    });

    /**
     * LE DÉFAUT DE L'ANCIENNE VERSION : elle empilait ses minuteries sans borne. Cinq
     * commandes en une minute donnaient quinze bips entrelacés — un bruit continu qu'on
     * finit par ignorer, c'est-à-dire l'inverse du but.
     */
    it('deux arrivées rapprochées ne s\'empilent pas : 3 sonneries après la DERNIÈRE', () => {
        const c = ctx();
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 10, source_surface: 'web' });
        vi.advanceTimersByTime(2000);
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 11, source_surface: 'kiosk' });

        expect(c._playNewOrderBeep, 'une sonnerie immédiate par arrivée').toHaveBeenCalledTimes(2);
        vi.advanceTimersByTime(60000);
        expect(c._playNewOrderBeep, '2 immédiates + les 2 restantes de la dernière, PAS 6').toHaveBeenCalledTimes(4);
    });

    it('commande COMPTOIR (pos) : aucune notification du tout (comportement existant préservé)', () => {
        const c = ctx();
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 3, source_surface: 'pos' });

        vi.advanceTimersByTime(30000);
        expect(c._playNewOrderBeep).not.toHaveBeenCalled();
    });

    it('le son désactivé (réglage) bloque toujours toute la séquence, pas juste le 1er bip', () => {
        const c = ctx({ _newOrderSoundEnabled: () => false });
        PosOrdersTrackerComponent.methods._maybeNotifyIncomingOrder.call(c, { id: 4, source_surface: 'web' });

        vi.advanceTimersByTime(30000);
        expect(c._playNewOrderBeep).not.toHaveBeenCalled();
    });
});
