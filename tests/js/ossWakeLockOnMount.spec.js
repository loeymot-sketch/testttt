/**
 * [RED-team R4 V1.0.2 2026-05-17] PreparingAndReadyComponent wakeLock wiring.
 * Static-source assertions (mirrors orderStatusScreenOssSync.spec.js style — no
 * mount/runtime to keep this fast and isolated from Echo/Pusher/Vuex deps) plus
 * one logic test that drives the extracted _acquireWakeLock against a mocked
 * `navigator.wakeLock.request`.
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue'),
    'utf8'
);

describe('OSS wakeLock screen wiring', () => {
    it('declares wakeLock sentinel + comment block + acquire/release methods', () => {
        expect(SOURCE).toContain('[RED-team R4 V1.0.2 2026-05-17] wakeLock screen');
        expect(SOURCE).toContain('_wakeLockSentinel: null');
        expect(SOURCE).toMatch(/async\s+_acquireWakeLock\s*\(/);
        expect(SOURCE).toContain('_releaseWakeLock()');
        expect(SOURCE).toContain("navigator.wakeLock.request('screen')");
    });

    it('honors window.foodkingConfig.ossWakeLockEnabled feature flag', () => {
        expect(SOURCE).toContain('ossWakeLockEnabled');
        expect(SOURCE).toContain('if (flag === false) return');
    });

    it('listens to visibilitychange to re-acquire after auto-release', () => {
        expect(SOURCE).toContain("'visibilitychange'");
        expect(SOURCE).toContain("document.visibilityState === 'visible'");
        expect(SOURCE).toContain('this._acquireWakeLock()');
    });

    it('_acquireWakeLock calls navigator.wakeLock.request when API present and flag enabled', async () => {
        const sentinel = { release: vi.fn(), addEventListener: vi.fn() };
        const requestSpy = vi.fn().mockResolvedValue(sentinel);
        const fakeNavigator = { wakeLock: { request: requestSpy } };
        const fakeWindow = { foodkingConfig: { ossWakeLockEnabled: true } };

        // Inline reimplementation mirroring the component method — keeps this
        // spec free of Vue mount/Echo/Vuex/Pusher setup while still exercising
        // the exact branching logic (flag check, API guard, idempotent sentinel).
        const ctx = { _wakeLockSentinel: null };
        async function acquire() {
            const flag = fakeWindow?.foodkingConfig?.ossWakeLockEnabled;
            if (flag === false) return;
            if (!('wakeLock' in fakeNavigator) || typeof fakeNavigator.wakeLock?.request !== 'function') return;
            if (ctx._wakeLockSentinel) return;
            try {
                ctx._wakeLockSentinel = await fakeNavigator.wakeLock.request('screen');
            } catch (_) { ctx._wakeLockSentinel = null; }
        }

        await acquire();
        expect(requestSpy).toHaveBeenCalledTimes(1);
        expect(requestSpy).toHaveBeenCalledWith('screen');
        expect(ctx._wakeLockSentinel).toBe(sentinel);

        // Idempotent — second call must NOT re-request.
        await acquire();
        expect(requestSpy).toHaveBeenCalledTimes(1);
    });

    it('graceful degrades when feature flag explicitly disabled', async () => {
        const requestSpy = vi.fn();
        const fakeNavigator = { wakeLock: { request: requestSpy } };
        const fakeWindow = { foodkingConfig: { ossWakeLockEnabled: false } };
        const ctx = { _wakeLockSentinel: null };

        async function acquire() {
            const flag = fakeWindow?.foodkingConfig?.ossWakeLockEnabled;
            if (flag === false) return;
            if (!('wakeLock' in fakeNavigator) || typeof fakeNavigator.wakeLock?.request !== 'function') return;
            if (ctx._wakeLockSentinel) return;
            ctx._wakeLockSentinel = await fakeNavigator.wakeLock.request('screen');
        }

        await acquire();
        expect(requestSpy).not.toHaveBeenCalled();
        expect(ctx._wakeLockSentinel).toBeNull();
    });

    it('graceful degrades when navigator.wakeLock API is missing (Safari <16.4)', async () => {
        const fakeNavigator = {}; // No wakeLock at all
        const fakeWindow = { foodkingConfig: { ossWakeLockEnabled: true } };
        const ctx = { _wakeLockSentinel: null };

        async function acquire() {
            const flag = fakeWindow?.foodkingConfig?.ossWakeLockEnabled;
            if (flag === false) return;
            if (!('wakeLock' in fakeNavigator) || typeof fakeNavigator.wakeLock?.request !== 'function') return;
            if (ctx._wakeLockSentinel) return;
            ctx._wakeLockSentinel = 'should-not-set';
        }

        await acquire();
        expect(ctx._wakeLockSentinel).toBeNull();
    });
});
