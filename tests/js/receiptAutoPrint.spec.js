import { describe, it, expect, vi } from 'vitest';
import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

// [RECEIPT-NO-AUTO 2026-07-24] Spec owner : le REÇU CLIENT ne s'imprime PLUS
// automatiquement. L'auto-impression est désormais OPT-IN via le flag
// `printing.auto_print_client_receipt` (exposé au front en
// window.foodkingConfig.printing.autoPrintClientReceipt), DÉFAUT FALSE.
// On teste la LOGIQUE de décision (maybeAutoPrintClient) en l'appelant avec un
// `this` simulé — le flag est porté par le ctx (miroir du computed du composant).
const autoPrint = ReceiptComponent.methods.maybeAutoPrintClient;

// ctx par défaut = encaissement FRAIS (clearCartOnClose=true) ; `autoPrint` = état
// du flag OPT-IN (défaut false = nouveau comportement owner : PAS d'auto).
function ctx(overrides = {}) {
    return {
        autoPrintClientReceipt: false, // défaut owner : jamais d'auto
        clearCartOnClose: true,        // reçu d'un encaissement frais (PaymentComponent)
        _autoPrintedOrderId: null,
        handlePrintClientClick: vi.fn(),
        $nextTick: (cb) => cb(),
        ...overrides,
    };
}

describe('ReceiptComponent — reçu client : PAS d\'auto-impression par défaut (spec owner)', () => {
    it('flag OFF (défaut) : n\'imprime PAS auto même pour un encaissement frais (clearCartOnClose=true)', () => {
        const c = ctx(); // autoPrintClientReceipt=false
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });

    it('flag OFF : le watcher order.id (paiement validé) ne déclenche AUCUNE impression auto', () => {
        const c = {
            order: { id: 5176, receipt_print_count: 0 },
            autoPrintClientReceipt: false,
            clearCartOnClose: true,            // = PaymentComponent:329 (encaissement frais)
            _autoPrintedOrderId: null,
            localPrintCount: 0,
            refreshBranchShowFromOrder: vi.fn(),
            handlePrintClientClick: vi.fn(),
            $nextTick: (cb) => cb(),
            maybeAutoPrintClient: ReceiptComponent.methods.maybeAutoPrintClient,
        };
        ReceiptComponent.watch['order.id'].call(c, 5176);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });
});

describe('ReceiptComponent — flag ON : auto-impression restaurée (rétro-compat)', () => {
    it('imprime AUTO le ticket UNE fois pour un encaissement frais quand le flag est ON', () => {
        const c = ctx({ autoPrintClientReceipt: true });
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    });

    it('n\'imprime PAS deux fois pour le même order id (garde anti-double NF525)', () => {
        const c = ctx({ autoPrintClientReceipt: true });
        autoPrint.call(c, 5176);
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    });

    it('n\'imprime PAS pour un re-print (clearCartOnClose=false, ex. tracker) même flag ON', () => {
        const c = ctx({ autoPrintClientReceipt: true, clearCartOnClose: false });
        autoPrint.call(c, 5176);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });

    it('n\'imprime PAS sans order id valide (flag ON)', () => {
        const c = ctx({ autoPrintClientReceipt: true });
        autoPrint.call(c, null);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });

    // CÂBLAGE RÉEL : PaymentComponent:329 rend <ReceiptComponent :order="order"
    // :clear-cart-on-close="true" />. Au succès du paiement, this.order=created →
    // le prop order change → le watcher order.id se déclenche → auto-print SI flag ON.
    it('le watcher order.id déclenche l\'auto-print quand le flag est ON', () => {
        const c = {
            order: { id: 5176, receipt_print_count: 0 },
            autoPrintClientReceipt: true,
            clearCartOnClose: true,            // = PaymentComponent:329
            _autoPrintedOrderId: null,
            localPrintCount: 0,
            refreshBranchShowFromOrder: vi.fn(),
            handlePrintClientClick: vi.fn(),
            $nextTick: (cb) => cb(),
            maybeAutoPrintClient: ReceiptComponent.methods.maybeAutoPrintClient,
        };
        ReceiptComponent.watch['order.id'].call(c, 5176);
        expect(c.handlePrintClientClick).toHaveBeenCalledTimes(1);
    });

    it('re-print depuis le tracker (clearCartOnClose défaut false) ne déclenche PAS l\'auto-print via le watcher, flag ON', () => {
        const c = {
            order: { id: 4242, receipt_print_count: 1 },
            autoPrintClientReceipt: true,
            clearCartOnClose: false,           // = PosOrdersTracker reprint
            _autoPrintedOrderId: null,
            localPrintCount: 0,
            refreshBranchShowFromOrder: vi.fn(),
            handlePrintClientClick: vi.fn(),
            $nextTick: (cb) => cb(),
            maybeAutoPrintClient: ReceiptComponent.methods.maybeAutoPrintClient,
        };
        ReceiptComponent.watch['order.id'].call(c, 4242);
        expect(c.handlePrintClientClick).not.toHaveBeenCalled();
    });
});

describe('ReceiptComponent — le computed autoPrintClientReceipt lit window.foodkingConfig (défaut false)', () => {
    const read = ReceiptComponent.computed.autoPrintClientReceipt;
    it('clé absente → false (config POS trimmée / non injectée)', () => {
        const prev = global.window;
        global.window = {};
        try { expect(read.call({})).toBe(false); } finally { global.window = prev; }
    });
    it('window.foodkingConfig.printing.autoPrintClientReceipt=true → true', () => {
        const prev = global.window;
        global.window = { foodkingConfig: { printing: { autoPrintClientReceipt: true } } };
        try { expect(read.call({})).toBe(true); } finally { global.window = prev; }
    });
});
