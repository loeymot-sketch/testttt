/**
 * [BRAIN TESTER 2026-08-03 · GAP P1 du deploy-diff d945570b0] Verrou de régression :
 * l'ouverture de tiroir « sans vente » DOIT poster la trace serveur
 * (POST admin/pos/cash-drawer/open → mouvement TYPE_DRAWER_OPEN, chaîne d'audit NF525)
 * et NE JAMAIS afficher « tracé » quand la trace n'est pas partie (no_sale_untraced).
 * Avant d945570b0 le geste était 100 % client (0 mouvement, promesse mensongère) —
 * sans ce spec, la ligne axios peut re-disparaître en silence.
 *
 * [Root cause 2026-09-17, capture propriétaire "tiroir ouvert, mais l'ouverture n'a
 * PAS pu être enregistrée (session de caisse fermée ou serveur injoignable)"]
 * L'ordre a été INVERSÉ : le serveur Laravel tourne sur un VPS distinct du PC caisse,
 * sa propre sonde TCP directe vers le pont d'impression (127.0.0.1:9100) ne peut donc
 * JAMAIS aboutir sur cette topologie — l'ancien code postait la trace AVANT d'ouvrir
 * le tiroir et se fiait UNIQUEMENT à cette sonde vouée à l'échec, donc "non tracé" à
 * chaque fois, même quand le tiroir s'ouvrait réellement. Le nouveau contrat : on
 * ouvre D'ABORD via le pont local (kioskHardware.openDrawer, seul chemin qui marche
 * sur cette topologie), puis on rapporte ce constat au serveur via `client_opened:
 * true`, que le serveur combine (OU logique) avec sa propre sonde côté
 * CashDrawerController::open — voir tests/Feature/Cash/CashDrawerHardwareOpenTest.php
 * pour la preuve côté backend.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), error: vi.fn(), success: vi.fn() },
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({
    openDrawer: vi.fn(() => ({ ok: true })),
}));

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';
import { openDrawer } from '../../resources/js/services/kioskHardware';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const makeVm = () => ({
    noSaleBusy: false,
    $t: (k) => k,
    triggerNoSaleOpenDrawer: PosComponent.methods.triggerNoSaleOpenDrawer,
});

describe('POS — trace « ouverture tiroir sans vente »', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        openDrawer.mockResolvedValue({ ok: true });
    });

    it('ouvre le tiroir localement PUIS rapporte client_opened:true au serveur (no_sale_done)', async () => {
        axios.post.mockResolvedValue({ data: { status: true } });
        const vm = makeVm();
        await vm.triggerNoSaleOpenDrawer();
        expect(openDrawer).toHaveBeenCalled();
        expect(axios.post).toHaveBeenCalledWith('admin/pos/cash-drawer/open', { client_opened: true });
        expect(alertService.info).toHaveBeenCalledWith('pos.no_sale_done');
        expect(alertService.error).not.toHaveBeenCalled();
        expect(vm.noSaleBusy).toBe(false);
    });

    it('trace NON partie (réseau/refus) malgré une ouverture réelle → jamais « tracé » : no_sale_untraced', async () => {
        axios.post.mockRejectedValue(new Error('down'));
        const vm = makeVm();
        await vm.triggerNoSaleOpenDrawer();
        expect(alertService.error).toHaveBeenCalledWith('pos.no_sale_untraced');
        expect(alertService.info).not.toHaveBeenCalled();
    });

    it('réponse serveur sans status=true (ex. session fermée) → no_sale_untraced', async () => {
        axios.post.mockResolvedValue({ data: { status: false, message: 'no session' } });
        const vm = makeVm();
        await vm.triggerNoSaleOpenDrawer();
        expect(alertService.error).toHaveBeenCalledWith('pos.no_sale_untraced');
    });

    it('le tiroir physique ne s\'ouvre PAS → no_sale_error, et le serveur n\'est même pas appelé', async () => {
        openDrawer.mockResolvedValue({ ok: false });
        const vm = makeVm();
        await vm.triggerNoSaleOpenDrawer();
        expect(alertService.error).toHaveBeenCalledWith('pos.no_sale_error');
        expect(axios.post).not.toHaveBeenCalled();
    });
});
