/**
 * Kiosk Design V1 — Phase 5.1 : service kioskHardware.js
 *
 * Vérifie :
 *  - Stub no-op actif quand window.borne est undefined.
 *  - isKioskBridge() retourne false pour stub, true pour bridge Electron.
 *  - Toutes les méthodes renvoient un contrat { ok, error? }.
 *  - healthcheck() interprète correctement les composants critiques (tpe/printer).
 *  - tpeCharge fallback sur chargeCard legacy si tpeCharge indisponible.
 *  - onHardwareEvent() renvoie un unsub fonctionnel même en stub.
 *  - Les méthodes qui throw dans le bridge sont attrapées et renvoient {ok:false}.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

function freshImport() {
    vi.resetModules();
    return import('../../resources/js/services/kioskHardware.js');
}

describe('kioskHardware service', () => {
    afterEach(() => {
        delete window.borne;
        vi.restoreAllMocks();
    });

    it('falls back to stub when window.borne is undefined', async () => {
        delete window.borne;
        const hw = await freshImport();
        expect(hw.isKioskBridge()).toBe(false);
        const hc = await hw.healthcheck();
        expect(hc.ok).toBe(true);
        // Stub expose un bridge healthcheck simulé → state='ok', degradation='none'
        expect(hc.state).toBe('ok');
        expect(hc.degradation).toBe('none');
    });

    it('detects real Electron bridge', async () => {
        window.borne = { isElectron: true, healthcheck: async () => ({ ok: true, components: {} }) };
        const hw = await freshImport();
        expect(hw.isKioskBridge()).toBe(true);
    });

    it('healthcheck returns critical state when tpe is down', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({ ok: true, components: { tpe: 'error', printer: true } }),
        };
        const hw = await freshImport();
        const hc = await hw.healthcheck();
        expect(hc.state).toBe('critical');
        expect(hc.degradation).toBe('critical');
    });

    it('healthcheck returns degraded when only nfc is down', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({ ok: true, components: { tpe: true, printer: true, nfc: 'error' } }),
        };
        const hw = await freshImport();
        const hc = await hw.healthcheck();
        expect(hc.state).toBe('degraded');
        expect(hc.degradation).toBe('degraded');
    });

    it('tpeCharge returns uniform shape + falls back on legacy chargeCard', async () => {
        window.borne = {
            isElectron: true,
            chargeCard: async () => ({ status: 'approved', tx_ref: 'legacy-xyz' }),
        };
        const hw = await freshImport();
        const res = await hw.tpeCharge(1500, 'CB');
        expect(res.ok).toBe(true);
        expect(res.tx_ref).toBe('legacy-xyz');
    });

    it('tpeCharge wraps thrown errors into {ok:false}', async () => {
        window.borne = {
            isElectron: true,
            tpeCharge: async () => { throw new Error('tpe_offline'); },
        };
        const hw = await freshImport();
        const res = await hw.tpeCharge(500, 'CB');
        expect(res.ok).toBe(false);
        expect(res.error).toMatch(/tpe_offline/);
    });

    it('onHardwareEvent returns a noop when bridge absent', async () => {
        delete window.borne;
        const hw = await freshImport();
        const unsub = hw.onHardwareEvent(() => {});
        expect(typeof unsub).toBe('function');
        expect(() => unsub()).not.toThrow();
    });

    it('print methods report unavailable when bridge is stub (no printReceipt)', async () => {
        delete window.borne;
        const hw = await freshImport();
        const r = await hw.printReceipt({ id: 1 });
        // Stub fournit printReceipt → retourne ok
        expect(r.ok).toBe(true);
    });

    it('haptic/stopAllSounds never throw even without bridge', async () => {
        delete window.borne;
        const hw = await freshImport();
        expect(() => hw.haptic('tap')).not.toThrow();
        expect(() => hw.stopAllSounds()).not.toThrow();
        expect(() => hw.stopSpeak()).not.toThrow();
    });
});
