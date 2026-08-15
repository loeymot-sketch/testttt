import { describe, it, expect, vi } from 'vitest';

/**
 * [T-6.2 BORNE-OFFLINE-SANS-REFERENCE 2026-08-15 · GOAL_CONFORT_MAX]
 * KioskCashInstructionComponent affichait "#—" quand `orderNumber` était vide
 * (borne hors-ligne : aucun numéro de file attribué par le serveur pas encore
 * synchronisé) — le client n'avait RIEN à dire au comptoir pour se faire
 * identifier. `KioskAppComponent.vue`/`KioskWizardComponent.vue` sont FROZEN
 * (CLAUDE.md §7, porte G5 requise pour tout changement de logique) — ce fix
 * reste entièrement contenu dans KioskCashInstructionComponent.vue (non gelé),
 * sans toucher à la façon dont orderNumber/orderId sont calculés en amont.
 */
vi.mock('axios', () => ({ default: { post: vi.fn().mockResolvedValue({}) } }));
vi.mock('../../resources/js/services/kioskHardware', () => ({
    default: { isKioskBridge: () => false },
}));
vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
    printReceipt: vi.fn(),
    buildReceiptData: vi.fn(),
    reportPrinterFailure: vi.fn(),
    isLocalBridgeAvailable: vi.fn().mockResolvedValue(false),
    markPrintedOnce: vi.fn(),
    printServerTicketsViaBridge: vi.fn(),
}));

import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

const { displayOrderNumber } = KioskCashInstructionComponent.computed;
const mountedHook = KioskCashInstructionComponent.mounted;

function ctx(overrides = {}) {
    return {
        orderNumber: '',
        orderId: null,
        localFallbackRef: '',
        autoRedirectSeconds: 0,
        logEvent: vi.fn(),
        startCountdown: vi.fn(),
        $nextTick: (fn) => Promise.resolve().then(fn),
        ...overrides,
    };
}

describe('KioskCashInstructionComponent.displayOrderNumber — jamais "—" sans info', () => {
    it('orderNumber présent : utilisé tel quel', () => {
        const c = ctx({ orderNumber: '42' });
        expect(displayOrderNumber.call(c)).toBe('42');
    });

    it('orderNumber absent, orderId présent : 4 derniers chiffres de orderId', () => {
        const c = ctx({ orderNumber: '', orderId: 123456789 });
        expect(displayOrderNumber.call(c)).toBe('6789');
    });

    it('orderNumber ET orderId absents (borne offline) : retombe sur le repli local — jamais vide', () => {
        const c = ctx({ orderNumber: '', orderId: null, localFallbackRef: 'T04213' });
        expect(displayOrderNumber.call(c)).toBe('T04213');
    });
});

describe('KioskCashInstructionComponent mounted() — génère un repli SEULEMENT si vraiment rien', () => {
    it('orderNumber présent : localFallbackRef reste vide (jamais généré inutilement)', async () => {
        const c = ctx({ orderNumber: '42' });
        await mountedHook.call(c);
        expect(c.localFallbackRef).toBe('');
    });

    it('orderNumber ET orderId absents : un repli "T" + 5 chiffres est généré', async () => {
        const c = ctx({ orderNumber: '', orderId: null });
        await mountedHook.call(c);
        expect(c.localFallbackRef).toMatch(/^T\d{5}$/);
    });
});
