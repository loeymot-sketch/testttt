import { describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/services/appService', () => ({ default: {} }));

import { posOrder, resolveRealtimeFallbackConfig } from '../../resources/js/store/modules/posOrder.js';

describe('POS realtime broadcast fallback', () => {
    it('exposes polling-only hint when broadcast is disabled', () => {
        const config = resolveRealtimeFallbackConfig({
            broadcastDriver: 'null',
            pollingIntervalMs: 30000,
        });

        expect(config.websocketEnabled).toBe(false);
        expect(config.pollingOnly).toBe(true);
        expect(config.pollingIntervalMs).toBe(30000);
        expect(config.hint).toContain('rafraichissement automatique toutes les 30s');
    });

    it('does not show fallback hint when pusher is configured', () => {
        const config = resolveRealtimeFallbackConfig({
            broadcastDriver: 'pusher',
            pusherKey: 'local-key',
            pollingIntervalMs: 15000,
        });

        expect(config.websocketEnabled).toBe(true);
        expect(config.pollingOnly).toBe(false);
        expect(config.hint).toBeNull();
    });

    it('stores the fallback contract through the posOrder module', () => {
        const state = {
            realtimeFallback: resolveRealtimeFallbackConfig({
                broadcastDriver: 'pusher',
                pusherKey: 'local-key',
            }),
        };

        posOrder.mutations.realtimeFallback(state, {
            broadcastDriver: 'log',
            pollingIntervalMs: 45000,
        });

        expect(posOrder.getters.realtimeFallback(state).pollingOnly).toBe(true);
        expect(posOrder.getters.realtimeFallback(state).driver).toBe('log');
        expect(posOrder.getters.realtimeFallbackHint(state)).toContain('45s');
    });
});
