import { describe, it, expect, vi } from 'vitest';
import axios from 'axios';
import { installInFlightGetDedupe } from '../../resources/js/shared/inflight-dedupe';
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

    it('une fenêtre pleine annoncée à 47 s (mesurée sur le vrai serveur) est franchie en 2 essais, sans erreur', async () => {
        // Le serveur local a répondu `Retry-After: 47` après saturation ; la valeur diminue à chaque essai.
        const waits = [];
        const inst = axios.create();
        const annonces = ['47', '17'];
        let n = 0;
        inst.defaults.adapter = (config) => {
            n += 1;
            if (n <= annonces.length) {
                const err = new Error('429');
                err.config = config;
                err.response = { status: 429, headers: { 'retry-after': annonces[n - 1] }, data: {}, config };
                return Promise.reject(err);
            }
            return Promise.resolve({ status: 200, data: { ok: true }, headers: {}, config });
        };
        installRateLimitRetry(inst, { sleep: async (ms) => { waits.push(ms); } });
        const res = await inst.post('/api/admin/kds-order/change-status/1', { a: 1 });
        expect(res.data.ok).toBe(true);
        expect(waits).toHaveLength(2);
        expect(waits[0]).toBeGreaterThanOrEqual(MAX_WAIT_MS);       // 47 s annoncées → plafonné à 30 s
        expect(waits[1]).toBeGreaterThanOrEqual(17000);             // puis l'attente restante
        expect(waits[0] + waits[1]).toBeGreaterThanOrEqual(47000);  // total ≥ fin de la fenêtre annoncée
    });

    it('8 GET identiques concurrents (dédoublonnés) : UN seul refus 429 ne fait échouer AUCUN appelant', async () => {
        const inst = axios.create();
        let n = 0;
        inst.defaults.adapter = (config) => {
            n += 1;
            if (n === 1) {
                const err = new Error('429');
                err.config = config;
                err.response = { status: 429, headers: { 'retry-after': '1' }, data: {}, config };
                return Promise.reject(err);
            }
            return Promise.resolve({ status: 200, data: { ok: true }, headers: {}, config });
        };
        installInFlightGetDedupe(inst);
        installRateLimitRetry(inst, { sleep: async () => {} });
        const res = await Promise.allSettled(Array.from({ length: 8 }, () => inst.get('/api/admin/kds-order/items')));
        expect(res.filter((r) => r.status === 'rejected')).toHaveLength(0);
    });

    it('un verrou annoncé à 3600 s n\'est PAS rejoué (pas de gel de 2 minutes)', () => {
        expect(shouldRetry({ response: { status: 429, headers: { 'retry-after': '3600' } }, config: { url: '/api/admin/pos' } })).toBe(false);
        expect(shouldRetry({ response: { status: 429, headers: { 'retry-after': '47' } }, config: { url: '/api/admin/pos' } })).toBe(true);
    });

    it('seul le chemin compte : ?search=pin ne bloque pas le rejeu, /unlock reste exclu', () => {
        expect(shouldRetry({ response: { status: 429, headers: {} }, config: { url: '/api/admin/items?search=pin&q=reset' } })).toBe(true);
        expect(shouldRetry({ response: { status: 429, headers: {} }, config: { url: '/api/admin/wheel/unlock?x=1' } })).toBe(false);
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
