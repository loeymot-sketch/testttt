import { describe, it, expect, vi, beforeEach } from 'vitest';

// [RECEIPT-NO-AUTO 2026-07-24] À l'encaissement (file « À encaisser »), le reçu
// CLIENT ne s'imprime PLUS automatiquement : onEncaisseConfirmed ne déclenche le
// pont ESC/POS QUE si le flag OPT-IN est ON (défaut FALSE). Les boutons manuels de
// la modale (PosCounterCollectModal.printTicket, couverts par posCounterCollectPrint.spec)
// restent l'unique voie par défaut. On teste la LOGIQUE (méthode) sans monter le composant.
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
    printEscPosViaCaisseBridge: vi.fn(),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

import axios from 'axios';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';

const onConfirmed = EncaissementComponent.methods.onEncaisseConfirmed;

function ctx(autoPrintClientReceipt) {
    return {
        autoPrintClientReceipt,
        encaisseOrder: { id: 99 },
        $t: (k) => k,
        fetchPending: vi.fn(),
    };
}

describe('EncaissementComponent.onEncaisseConfirmed — gate auto-impression reçu client', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: { escpos_b64: 'QUJD' } });
    });

    it('flag OFF (défaut) : NE POSTe PAS au pont (aucun GET /escpos) — pas d\'auto-print', async () => {
        const c = ctx(false);
        await onConfirmed.call(c, { orderId: 99 });
        expect(axios.get).not.toHaveBeenCalled();
        expect(c.fetchPending).toHaveBeenCalledTimes(1); // le refresh de la file reste
        expect(c.encaisseOrder).toBe(null);              // la modale se ferme
    });

    it('flag ON : imprime le ticket client via le pont (GET /escpos ticket=client)', async () => {
        const c = ctx(true);
        await onConfirmed.call(c, { orderId: 99 });
        await (c._lastEncaissePrint || Promise.resolve());
        expect(axios.get).toHaveBeenCalledWith('admin/pos/orders/99/escpos', { params: { ticket: 'client' } });
        expect(c.fetchPending).toHaveBeenCalledTimes(1);
    });

    it('flag ON mais payload sans orderId : pas d\'impression (garde orderId)', async () => {
        const c = ctx(true);
        await onConfirmed.call(c, {});
        expect(axios.get).not.toHaveBeenCalled();
        expect(c.fetchPending).toHaveBeenCalledTimes(1);
    });

    it('computed autoPrintClientReceipt : clé absente → false ; flag injecté → true', () => {
        const read = EncaissementComponent.computed.autoPrintClientReceipt;
        const prev = global.window;
        try {
            global.window = {};
            expect(read.call({})).toBe(false);
            global.window = { foodkingConfig: { printing: { autoPrintClientReceipt: true } } };
            expect(read.call({})).toBe(true);
        } finally { global.window = prev; }
    });
});
