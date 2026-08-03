/**
 * [BRAIN TESTER 2026-08-03 · GAP P1 du deploy-diff d945570b0] Verrou de régression :
 * l'ouverture de tiroir « sans vente » DOIT poster la trace serveur
 * (POST admin/pos/cash-drawer/open → mouvement TYPE_DRAWER_OPEN, chaîne d'audit NF525)
 * et NE JAMAIS afficher « tracé » quand la trace n'est pas partie (no_sale_untraced).
 * Avant d945570b0 le geste était 100 % client (0 mouvement, promesse mensongère) —
 * sans ce spec, la ligne axios peut re-disparaître en silence.
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
    });

    it('poste la trace serveur AVANT d’ouvrir le tiroir et confirme (no_sale_done)', async () => {
        axios.post.mockResolvedValue({ data: { status: true } });
        const vm = makeVm();
        await vm.triggerNoSaleOpenDrawer();
        expect(axios.post).toHaveBeenCalledWith('admin/pos/cash-drawer/open', {});
        expect(openDrawer).toHaveBeenCalled();
        expect(alertService.info).toHaveBeenCalledWith('pos.no_sale_done');
        expect(alertService.error).not.toHaveBeenCalled();
        expect(vm.noSaleBusy).toBe(false);
    });

    it('trace NON partie (réseau/refus) → jamais « tracé » : no_sale_untraced', async () => {
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
});
