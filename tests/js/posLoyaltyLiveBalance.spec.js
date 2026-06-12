/**
 * [GOAL LOYALTY-SYNC L2 2026-06-11] Le modal redeem POS s'abonne à
 * LoyaltyBalanceChanged (private-branch.{id}) : un mouvement de points venu
 * d'une AUTRE surface met à jour le solde affiché — fin du solde périmé.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

const onEventsMock = vi.fn(() => ({ unsubscribe: vi.fn() }));
vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: (...args) => onEventsMock(...args),
}));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import PosLoyaltyRedeemModal from '../../resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue';

function mountModal(props = {}) {
    return mount(PosLoyaltyRedeemModal, {
        global: { mocks: { $t: (k, p) => (p ? `${k}:${JSON.stringify(p)}` : k) } },
        props: { open: true, orderId: 7, rate: 100, branchId: 1, ...props },
    });
}

describe('PosLoyaltyRedeemModal — solde live (L2)', () => {
    beforeEach(() => onEventsMock.mockClear());

    it("s'abonne à LoyaltyBalanceChanged sur la branche fournie", () => {
        mountModal();
        expect(onEventsMock).toHaveBeenCalledTimes(1);
        const [branchId, subs] = onEventsMock.mock.calls[0];
        expect(branchId).toBe(1);
        expect(subs[0].broadcastAs).toBe('LoyaltyBalanceChanged');
    });

    it('ne s\'abonne pas sans branche (dégradation propre)', () => {
        mountModal({ branchId: 0 });
        expect(onEventsMock).not.toHaveBeenCalled();
    });

    // [GOAL 2026-06-12] sur PosOrderShow la branche dérive de la COMMANDE
    // chargée en async → arrive APRÈS le mount. Sans watcher, le modal ne
    // s'abonnait jamais (live mort sur la surface canonique pour un admin).
    it("s'abonne quand la branche devient connue APRÈS le mount (watcher)", async () => {
        const wrapper = mountModal({ branchId: 0 });
        expect(onEventsMock).not.toHaveBeenCalled();
        await wrapper.setProps({ branchId: 1 });
        expect(onEventsMock).toHaveBeenCalledTimes(1);
        expect(onEventsMock.mock.calls[0][0]).toBe(1);
    });

    it('re-souscrit proprement si la branche change (unsubscribe avant resubscribe)', async () => {
        const unsub = vi.fn();
        onEventsMock.mockReturnValue({ unsubscribe: unsub });
        const wrapper = mountModal({ branchId: 1 });
        expect(onEventsMock).toHaveBeenCalledTimes(1);
        await wrapper.setProps({ branchId: 2 });
        expect(unsub).toHaveBeenCalledTimes(1);
        expect(onEventsMock).toHaveBeenCalledTimes(2);
        expect(onEventsMock.mock.calls[1][0]).toBe(2);
        onEventsMock.mockReturnValue({ unsubscribe: vi.fn() });
    });

    // [F3-01 2026-06-12] Le handler reçoit l'ENVELOPE PARSÉE du contrat
    // (eventContract.parseEvent → { type, branchId, payload: {...} }), PAS un
    // objet plat. L'ancienne spec mockait un payload plat → verte-sur-mauvais-
    // contrat pendant que le live était un no-op total en prod.
    it("met à jour le solde quand l'event CONTRAT (payload imbriqué) arrive après lookup", async () => {
        const wrapper = mountModal();
        const handler = onEventsMock.mock.calls[0][1][0].handler;
        const env = (payload) => ({
            version: 1, type: 'loyalty.balance_changed', branchId: 1, payload,
        });

        // pas de lookup → l'event est ignoré (pas d'affichage fantôme)
        handler(env({ balance_after: 999, user_id: 68 }));
        expect(wrapper.vm.customerBalance).toBe(null);

        // après lookup (client connu = 68), l'event du MÊME client rafraîchit
        wrapper.vm.customerBalance = 265;
        wrapper.vm.customerUserId = 68;
        handler(env({ balance_after: 165, user_id: 68 }));
        expect(wrapper.vm.customerBalance).toBe(165);

        // [F3-04] l'event d'un AUTRE client de la branche n'écrase PAS le solde affiché
        handler(env({ balance_after: 9999, user_id: 42 }));
        expect(wrapper.vm.customerBalance).toBe(165);

        // event sans user_id (producteur legacy) : ignoré aussi quand on connaît le client
        handler(env({ balance_after: 777 }));
        expect(wrapper.vm.customerBalance).toBe(165);

        // payload invalide ignoré
        handler(env({ balance_after: 'nope', user_id: 68 }));
        expect(wrapper.vm.customerBalance).toBe(165);
    });

    it('se désabonne au démontage', () => {
        const unsub = vi.fn();
        onEventsMock.mockReturnValueOnce({ unsubscribe: unsub });
        const wrapper = mountModal();
        wrapper.unmount();
        expect(unsub).toHaveBeenCalled();
    });

    // [GOAL 2026-06-12 T-F.3] Sentinel de câblage : les DEUX surfaces qui montent
    // le modal doivent passer :branch-id, sinon la prop reste au défaut 0 et le
    // subscribe L2 ne s'arme jamais (solde live silencieusement mort — c'était
    // le cas de la surface CANONIQUE PosOrderShowComponent).
    it('chaque surface hôte passe :branch-id au modal (sinon le live est mort-né)', () => {
        const fs = require('fs');
        const path = require('path');
        const hosts = [
            '../../resources/js/components/admin/pos/PosComponent.vue',
            '../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue',
        ];
        for (const rel of hosts) {
            const src = fs.readFileSync(path.resolve(__dirname, rel), 'utf8');
            const idx = src.indexOf('<PosLoyaltyRedeemModal');
            expect(idx, `${rel} doit monter PosLoyaltyRedeemModal`).toBeGreaterThan(-1);
            const block = src.slice(idx, src.indexOf('/>', idx) + 2);
            expect(block, `${rel} doit lier :branch-id sur le modal`).toMatch(/:branch-id=/);
        }
    });
});
