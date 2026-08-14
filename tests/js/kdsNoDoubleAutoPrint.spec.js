import { describe, it, expect, vi } from 'vitest';

import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

/**
 * [DOUBLE-IMPRESSION 2026-08-14 owner] « toutes les impressions de cuisine en double ».
 *
 * Cause : le watcher `orders` de ce composant ET KitchenTicketPrintListener.vue (monté
 * globalement, TOUJOURS présent avec cet écran — cf. DefaultComponent.vue theme==='backend')
 * imprimaient tous les deux, indépendamment, chaque commande sur le MÊME pont
 * 127.0.0.1:9101 — deux dé-dup qui ne se voient jamais (localStorage ici, table serveur
 * kitchen_ticket_claims là-bas). Ce test verrouille que le watcher ne redéclenche plus
 * l'impression automatique (le listener global en est désormais la SEULE source), tout en
 * gardant le son de nouvelle commande.
 */
const ordersWatcher = KitchenDisplaySystemComponent.watch.orders;

function ctx(overrides = {}) {
    return {
        _kdsOrdersHydrated: true,
        playKdsNewOrderSound: vi.fn(),
        autoPrintNewKitchenTickets: vi.fn(),
        ...overrides,
    };
}

describe('KDS — plus de double impression automatique (watcher orders)', () => {
    it('une nouvelle commande joue le son MAIS ne redéclenche PLUS autoPrintNewKitchenTickets', () => {
        const c = ctx();
        const oldVal = [{ id: 1 }];
        const newVal = [{ id: 1 }, { id: 2 }];

        ordersWatcher.call(c, newVal, oldVal);

        expect(c.playKdsNewOrderSound).toHaveBeenCalledTimes(1);
        expect(c.autoPrintNewKitchenTickets).not.toHaveBeenCalled();
    });

    it('ne fait rien avant hydratation (oldVal undefined) — inchangé', () => {
        const c = ctx();
        ordersWatcher.call(c, [{ id: 1 }], undefined);

        expect(c.playKdsNewOrderSound).not.toHaveBeenCalled();
        expect(c.autoPrintNewKitchenTickets).not.toHaveBeenCalled();
    });

    it('aucune commande nouvelle → ni son ni tentative d\'impression', () => {
        const c = ctx();
        ordersWatcher.call(c, [{ id: 1 }], [{ id: 1 }]);

        expect(c.playKdsNewOrderSound).not.toHaveBeenCalled();
        expect(c.autoPrintNewKitchenTickets).not.toHaveBeenCalled();
    });
});
