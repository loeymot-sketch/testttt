/**
 * Sentinel — Wave 3c P1 / KDS-ADV3C-07 + KDS-ADV3C-08
 *
 * PosSyncService._runtimeConfig().intervalMsWhenDisconnected and
 * OssSyncService._runtimeConfig().{intervalMsWhenConnected, intervalMsWhenDisconnected}
 * must clamp owner-misconfig to [CADENCE_FLOOR_MS, CADENCE_CEILING_MS] so a
 * runaway value (e.g. 999999999) cannot silently freeze the catalog refresh
 * or the customer wall for ~11.5 days during a WS outage.
 *
 * Symmetric to tests/js/kdsCadenceFloor.spec.js (Wave 2c KDS heal).
 *
 * Audit: reports/audit/critical-focus-2026-05-18/wave-3c/adv-1-kds-heals-r3.md
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { PosSyncService, CADENCE_CEILING_MS as POS_CEILING, CADENCE_FLOOR_MS as POS_FLOOR } from '../../resources/js/services/PosSyncService.js';
import { OssSyncService, CADENCE_CEILING_MS as OSS_CEILING, CADENCE_FLOOR_MS as OSS_FLOOR } from '../../resources/js/services/OssSyncService.js';

const setPosCfg = (overrides) => {
    globalThis.window = globalThis.window || {};
    globalThis.window.foodkingConfig = {
        posFallbackPolling: { enabled: true, ...overrides },
    };
};

const setOssCfg = (overrides) => {
    globalThis.window = globalThis.window || {};
    globalThis.window.foodkingConfig = {
        ossFallbackPolling: { enabled: true, ...overrides },
    };
};

describe('PosSyncService cadence cap (Wave 3c KDS-ADV3C-07 P1)', () => {
    let savedWindow;
    let savedCfg;

    beforeEach(() => {
        savedWindow = globalThis.window;
        savedCfg = savedWindow?.foodkingConfig;
    });

    afterEach(() => {
        if (savedWindow) {
            globalThis.window = savedWindow;
            globalThis.window.foodkingConfig = savedCfg;
        }
    });

    it('exposes CADENCE_FLOOR_MS=250 and CADENCE_CEILING_MS=60_000', () => {
        expect(POS_FLOOR).toBe(250);
        expect(POS_CEILING).toBe(60_000);
    });

    it('clamps a 999999999ms silent-blind owner-misconfig down to 60_000ms', () => {
        setPosCfg({ intervalMsWhenDisconnected: 999999999 });
        const svc = new PosSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenDisconnected).toBe(POS_CEILING);
    });

    it('clamps a 1ms owner-misconfig up to 250ms (DDoS floor)', () => {
        setPosCfg({ intervalMsWhenDisconnected: 1 });
        const svc = new PosSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenDisconnected).toBe(POS_FLOOR);
    });

    it('preserves a 30_000ms value (between floor and ceiling)', () => {
        setPosCfg({ intervalMsWhenDisconnected: 30_000 });
        const svc = new PosSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenDisconnected).toBe(30_000);
    });

    it('falls back to default (30_000ms) when value is non-numeric garbage', () => {
        setPosCfg({ intervalMsWhenDisconnected: 'abc' });
        const svc = new PosSyncService();
        const cfg = svc._runtimeConfig();
        // 'abc' → NaN → fallback DEFAULTS.intervalMsWhenDisconnected (30000)
        expect(cfg.intervalMsWhenDisconnected).toBe(30_000);
    });
});

describe('OssSyncService cadence cap (Wave 3c KDS-ADV3C-08 P1)', () => {
    let savedWindow;
    let savedCfg;

    beforeEach(() => {
        savedWindow = globalThis.window;
        savedCfg = savedWindow?.foodkingConfig;
    });

    afterEach(() => {
        if (savedWindow) {
            globalThis.window = savedWindow;
            globalThis.window.foodkingConfig = savedCfg;
        }
    });

    it('exposes CADENCE_FLOOR_MS=250 and CADENCE_CEILING_MS=60_000', () => {
        expect(OSS_FLOOR).toBe(250);
        expect(OSS_CEILING).toBe(60_000);
    });

    it('clamps a 999999999ms silent-blind owner-misconfig down to 60_000ms (connected)', () => {
        setOssCfg({
            intervalMsWhenConnected: 999999999,
            intervalMsWhenDisconnected: 2_000,
        });
        const svc = new OssSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenConnected).toBe(OSS_CEILING);
    });

    it('clamps a 999999999ms silent-blind owner-misconfig down to 60_000ms (disconnected)', () => {
        setOssCfg({
            intervalMsWhenConnected: 60_000,
            intervalMsWhenDisconnected: 999999999,
        });
        const svc = new OssSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenDisconnected).toBe(OSS_CEILING);
    });

    it('clamps a 1ms owner-misconfig up to 250ms (DDoS floor)', () => {
        setOssCfg({
            intervalMsWhenConnected: 1,
            intervalMsWhenDisconnected: 1,
        });
        const svc = new OssSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenConnected).toBe(OSS_FLOOR);
        expect(cfg.intervalMsWhenDisconnected).toBe(OSS_FLOOR);
    });

    it('preserves a 2_000ms disconnected default value', () => {
        setOssCfg({
            intervalMsWhenConnected: 60_000,
            intervalMsWhenDisconnected: 2_000,
        });
        const svc = new OssSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenDisconnected).toBe(2_000);
    });

    it('falls back to defaults when values are non-numeric garbage', () => {
        setOssCfg({
            intervalMsWhenConnected: 'abc',
            intervalMsWhenDisconnected: null,
        });
        const svc = new OssSyncService();
        const cfg = svc._runtimeConfig();
        expect(cfg.intervalMsWhenConnected).toBe(60_000);
        expect(cfg.intervalMsWhenDisconnected).toBe(2_000);
    });
});
