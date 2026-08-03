/**
 * [RECEIPT-NO-AUTO 2026-07-24] Borne confirmation : le REÇU CLIENT ne s'imprime
 * PLUS automatiquement au montage de /confirmation. L'auto-impression est OPT-IN
 * via window.foodkingConfig.printing.autoPrintClientReceipt (défaut FALSE). Le
 * bouton manuel « Imprimer le ticket » (:76 → printReceipt()) reste disponible
 * dans les deux cas. Le pont / bridge sont mockés « dispo » pour prouver que c'est
 * bien le FLAG (et non l'absence de pont) qui coupe l'auto-impression.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskConfirmationComponent from '../../resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const escPosPrint = vi.fn(() => Promise.resolve({ method: 'local-bridge' }));
const serverBridge = vi.fn(() => Promise.resolve(false)); // false → chemin escPosPrint

vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
    printReceipt: (...args) => escPosPrint(...args),
    buildReceiptData: vi.fn((x) => ({ ...x })),
    reportPrinterFailure: vi.fn(),
    isLocalBridgeAvailable: vi.fn(() => Promise.resolve(true)), // pont DISPO
    markPrintedOnce: vi.fn(() => true),                        // garde OK
    printServerTicketsViaBridge: (...args) => serverBridge(...args),
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({ default: { isKioskBridge: () => false } }));
vi.mock('../../resources/js/composables/useKioskSpeech', () => ({
    useKioskSpeech: () => ({ speak: vi.fn(() => Promise.resolve()), stop: vi.fn() }),
}));
vi.mock('../../resources/js/helpers/kioskReceiptPersistence', () => ({
    saveKioskReceiptSnapshot: vi.fn(),
    readKioskReceiptSnapshot: vi.fn(() => null),
    clearKioskReceiptSnapshot: vi.fn(),
}));

const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });

function mountConfirmation() {
    const store = createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                state: { items: [{ name: 'Coca', quantity: 1, total: 1.9 }], queueNumber: 'A12', loyaltyDiscount: 0, paymentMethod: 'card' },
                getters: { total: () => 1.9 },
                actions: { reset: vi.fn() },
            },
            globalState: {
                namespaced: true,
                state: { lists: { company_name: 'Le Cayenne', loyalty_points_per_euro: 0 } },
            },
        },
    });
    return mount(KioskConfirmationComponent, {
        global: { plugins: [i18n, store], mocks: { $router: { push: vi.fn().mockResolvedValue() } } },
        props: { orderNumber: 'A12', orderTotal: 1.9 },
        attachTo: document.body,
    });
}

async function flush() {
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));
}

describe('KioskConfirmationComponent — reçu client : PAS d\'auto-impression par défaut', () => {
    let prevWindow;
    beforeEach(() => { prevWindow = global.window.foodkingConfig; vi.clearAllMocks(); });
    afterEach(() => { global.window.foodkingConfig = prevWindow; });

    it('flag OFF (défaut) : aucune impression auto au montage (pont pourtant dispo)', async () => {
        global.window.foodkingConfig = { printing: { autoPrintClientReceipt: false } };
        const w = mountConfirmation();
        await flush();
        expect(serverBridge).not.toHaveBeenCalled();
        expect(escPosPrint).not.toHaveBeenCalled();
        w.unmount();
    });

    it('flag ABSENT : idem — aucune impression auto (clé non injectée = défaut owner)', async () => {
        global.window.foodkingConfig = {};
        const w = mountConfirmation();
        await flush();
        expect(escPosPrint).not.toHaveBeenCalled();
        w.unmount();
    });

    it('bouton manuel : printReceipt() imprime TOUJOURS, même flag OFF', async () => {
        global.window.foodkingConfig = { printing: { autoPrintClientReceipt: false } };
        const w = mountConfirmation();
        await flush();
        expect(escPosPrint).not.toHaveBeenCalled(); // rien en auto
        await w.vm.printReceipt();                   // = clic bouton @click="printReceipt"
        await flush();
        expect(serverBridge).toHaveBeenCalled();     // le pipeline d'impression a bien tourné
        w.unmount();
    });
});

describe('KioskConfirmationComponent — flag ON : auto-impression restaurée (rétro-compat)', () => {
    let prevWindow;
    beforeEach(() => { prevWindow = global.window.foodkingConfig; vi.clearAllMocks(); });
    afterEach(() => { global.window.foodkingConfig = prevWindow; });

    it('flag ON + pont dispo : imprime le reçu client automatiquement au montage', async () => {
        global.window.foodkingConfig = { printing: { autoPrintClientReceipt: true } };
        const w = mountConfirmation();
        await flush();
        // printReceipt(false) a tourné → printServerTicketsViaBridge appelé (puis escPosPrint car false).
        expect(serverBridge).toHaveBeenCalled();
        expect(escPosPrint).toHaveBeenCalled();
        w.unmount();
    });
});
