import { describe, it, expect, vi } from 'vitest';
import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

// On teste la LOGIQUE de décision d'auto-impression (méthode maybeAutoPrintClient)
// en l'appelant avec un `this` simulé — pas besoin de monter le composant complet.
const autoPrint = ReceiptComponent.methods.maybeAutoPrintClient;

function ctx(overrides = {}) {
    return {
        clearCartOnClose: true,        // reçu d'un encaissement frais (PaymentComponent)
        _autoPrintedOrderId: null,
        handlePrintClientClick: vi.fn(),
        $nextTick: (cb) => cb(),
        ...overrides,
    };
}

describe('ReceiptComponent — impression auto du ticket client en fin de commande', () => {
    it('imprime AUTO le ticket UNE fois pour un encaissement frais (clearCartOnClose=true)', () => {
        const c = ctx();
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    });

    it('n\'imprime PAS deux fois pour le même order id (garde anti-double NF525)', () => {
        const c = ctx();
        autoPrint.call(c, 5176);
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    });

    it('n\'imprime PAS pour un re-print (clearCartOnClose=false, ex. tracker)', () => {
        const c = ctx({ clearCartOnClose: false });
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });

    it('n\'imprime PAS sans order id valide', () => {
        const c = ctx();
        autoPrint.call(c, null);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });
});
