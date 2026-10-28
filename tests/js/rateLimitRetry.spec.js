import { describe, it, expect, vi } from 'vitest';
import axios from 'axios';
import {
    installRateLimitRetry, shouldRetry, retryDelayMs, MAX_RETRIES, MAX_WAIT_MS,
} from '../../resources/js/shared/rate-limit-retry';

// Adaptateur factice : répond 429 `n` fois puis 200, en comptant les appels.
function client(refusals, { headers = {}, data = {} } = {}) {
    const inst = axios.create();
    const calls = { n: 0 };
    inst.defaults.adapter = (config) => {
        calls.n += 1;
        if (calls.n <= refusals) {
            const err = new Error('Request failed with status code 429');
            err.config = config;
            err.response = { status: 429, headers, data, config };
            return Promise.reject(err);
        }
        return Promise.resolve({ status: 200, data: { ok: true }, headers: {}, config });
    };
    return { inst, calls };
}

describe('rejeu silencieux des 429', () => {
    it('un 429 passager est rejoué puis réussit, sans erreur visible', async () => {
        const { inst, calls } = client(2);
        installRateLimitRetry(inst, { sleep: async () => {} });
        const res = await inst.post('/api/admin/kds-order/change-status/1', { a: 1 });
        expect(res.data.ok).toBe(true);
        expect(calls.n).toBe(3);
    });

    it('un blocage réel remonte après MAX_RETRIES rejeux (rien de masqué)', async () => {
        const { inst, calls } = client(99);
        installRateLimitRetry(inst, { sleep: async () => {} });
        await expect(inst.get('/api/admin/pos-order')).rejects.toMatchObject({ response: { status: 429 } });
        expect(calls.n).toBe(MAX_RETRIES + 1);
    });

    it('ne rejoue jamais une route d\'authentification (anti force brute)', async () => {
        for (const url of ['/api/auth/login', '/api/auth/otp', '/api/auth/forgot-password', '/api/admin/wheel/unlock']) {
            const { inst, calls } = client(1);
            installRateLimitRetry(inst, { sleep: async () => {} });
            await expect(inst.post(url)).rejects.toBeTruthy();
            expect(calls.n).toBe(1);
        }
    });

    it('ne rejoue pas la télémétrie tire-et-oublie', async () => {
        const { inst, calls } = client(1);
        installRateLimitRetry(inst, { sleep: async () => {} });
        await expect(inst.post('/api/frontend/kiosk/event')).rejects.toBeTruthy();
        expect(calls.n).toBe(1);
    });

    it('conserve l\'en-tête d\'idempotence à l\'identique au rejeu', async () => {
        const seen = [];
        const inst = axios.create();
        let n = 0;
        inst.defaults.adapter = (config) => {
            seen.push(config.headers['X-Idempotency-Key']);
            n += 1;
            if (n === 1) {
                const err = new Error('429');
                err.config = config;
                err.response = { status: 429, headers: {}, data: {}, config };
                return Promise.reject(err);
            }
            return Promise.resolve({ status: 200, data: {}, headers: {}, config });
        };
        installRateLimitRetry(inst, { sleep: async () => {} });
        await inst.post('/api/admin/pos', {}, { headers: { 'X-Idempotency-Key': 'abc-123' } });
        expect(seen).toEqual(['abc-123', 'abc-123']);
    });

    it('l\'échappatoire __noRateLimitRetry est respectée', () => {
        expect(shouldRetry({ response: { status: 429 }, config: { url: '/api/x', __noRateLimitRetry: true } })).toBe(false);
        expect(shouldRetry({ response: { status: 500 }, config: { url: '/api/x' } })).toBe(false);
        expect(shouldRetry({ response: { status: 429 }, config: { url: '/api/x' } })).toBe(true);
    });

    it('l\'attente suit Retry-After, bornée, avec gigue', () => {
        const rand = () => 0;
        expect(retryDelayMs({ response: { headers: { 'retry-after': '3' } } }, 0, rand)).toBe(3000);
        // Une fenêtre de 60 s ne fige jamais l'écran : attente plafonnée.
        expect(retryDelayMs({ response: { headers: { 'retry-after': '60' } } }, 0, rand)).toBe(MAX_WAIT_MS);
        // Sans en-tête : attente progressive.
        expect(retryDelayMs({ response: { headers: {} } }, 1, rand)).toBeGreaterThan(retryDelayMs({ response: { headers: {} } }, 0, rand));
        // Gigue présente.
        expect(retryDelayMs({ response: { headers: { 'retry-after': '3' } } }, 0, () => 0.99)).toBeGreaterThan(3000);
    });

    it('bootstrap.js enregistre le rejeu AVANT l\'intercepteur d\'alerte', async () => {
        const fs = await import('node:fs');
        const src = fs.readFileSync('resources/js/bootstrap.js', 'utf8');
        const iRetry = src.indexOf('installRateLimitRetry(window.axios)');
        const iAlert = src.indexOf('const TOAST_STATUSES');
        expect(iRetry).toBeGreaterThan(0);
        expect(iRetry).toBeLessThan(iAlert);
    });
});
