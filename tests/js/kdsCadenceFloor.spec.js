/**
 * Sentinel: KdsSyncService._runtimeCadenceOptions must clamp every base
 * cadence to a 250ms floor (4 req/s max per station) and every jitter to
 * 0, so an owner-misconfig of window.foodkingConfig.kdsFallbackPolling
 * cannot weaponize the polling loop into PHP-FPM saturation.
 *
 * Audit: reports/audit/critical-focus-2026-05-18/wave-1/kds-adversarial.md
 *        — finding KDS-RED-09 (Wave 3 P1)
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { KdsSyncService } from '../../resources/js/services/KdsSyncService.js';

const FLOOR_MS = 250;

const setRuntimeCfg = (overrides) => {
    globalThis.window = globalThis.window || {};
    globalThis.window.foodkingConfig = {
        kdsFallbackPolling: overrides,
    };
};

describe('KdsSyncService cadence floor (KDS-RED-09 / Wave 3 P1)', () => {
    let savedCfg;
    let savedWindow;

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

    it('clamps a 10ms owner-misconfig base cadence up to 250ms', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 10,
            degradedBaseMs: 50,
            highActivityBaseMs: 1,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(FLOOR_MS);
        expect(opts.degradedBaseMs).toBe(FLOOR_MS);
        expect(opts.highActivityBaseMs).toBe(FLOOR_MS);
    });

    it('preserves an owner value above the floor', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 5000,
            degradedBaseMs: 2000,
            highActivityBaseMs: 1500,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(5000);
        expect(opts.degradedBaseMs).toBe(2000);
        expect(opts.highActivityBaseMs).toBe(1500);
    });

    it('falls back to safe defaults when no runtime cfg present', () => {
        if (globalThis.window) {
            globalThis.window.foodkingConfig = undefined;
        }

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(10000);
        expect(opts.degradedBaseMs).toBe(5000);
        expect(opts.highActivityBaseMs).toBe(3000);
        expect(opts.disconnectedJitterMs).toBe(3000);
        expect(opts.degradedJitterMs).toBe(2000);
        expect(opts.highActivityJitterMs).toBe(1000);
    });

    it('clamps negative jitter to 0 (jitters widen, never shorten the wait)', () => {
        setRuntimeCfg({
            disconnectedJitterMs: -500,
            degradedJitterMs: -1,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedJitterMs).toBe(0);
        expect(opts.degradedJitterMs).toBe(0);
    });

    it('handles non-numeric garbage by falling back through the floor', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 'abc',
            degradedBaseMs: null,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        // 'abc' → NaN → uses default 10000 (>= floor)
        // null → parseInt(null) → NaN → default 5000 (>= floor)
        expect(opts.disconnectedBaseMs).toBe(10000);
        expect(opts.degradedBaseMs).toBe(5000);
    });

    // Wave 3b P1 / KDS-ADV3B-04 — silent-blind upper cap (symmetric to floor)
    it('clamps a 999999999ms silent-blind owner-misconfig down to 60_000ms', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 999999999,
            degradedBaseMs: 999999999,
            highActivityBaseMs: 999999999,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(60_000);
        expect(opts.degradedBaseMs).toBe(60_000);
        expect(opts.highActivityBaseMs).toBe(60_000);
    });

    it('clamps a 120_000ms base down to the 60_000ms ceiling', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 120_000,
            degradedBaseMs: 120_000,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(60_000);
        expect(opts.degradedBaseMs).toBe(60_000);
    });

    it('preserves a 30_000ms base (below ceiling)', () => {
        setRuntimeCfg({
            disconnectedBaseMs: 30_000,
            degradedBaseMs: 30_000,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedBaseMs).toBe(30_000);
        expect(opts.degradedBaseMs).toBe(30_000);
    });

    it('caps jitter above 30_000ms to the JITTER_CEILING_MS', () => {
        setRuntimeCfg({
            disconnectedJitterMs: 999999,
            degradedJitterMs: 60_000,
            highActivityJitterMs: 50_000,
        });

        const svc = new KdsSyncService();
        const opts = svc._runtimeCadenceOptions();

        expect(opts.disconnectedJitterMs).toBe(30_000);
        expect(opts.degradedJitterMs).toBe(30_000);
        expect(opts.highActivityJitterMs).toBe(30_000);
    });
});
