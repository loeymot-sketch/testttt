import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID GOAL-F2-HEAL-01 | @source Phase F.12 finding F12-FIND-01 (P2)
 * @reason
 *   Without a global axios timeout, browser default (~5min OS-level TCP
 *   keepalive) made stalled FPM responses appear to "hang" to the cashier
 *   with no escape hatch besides page reload (which orphans the in-flight
 *   promise). Critical mutating writes — kiosk submitOrder, POS
 *   PosCounterCollectModal onConfirm, KioskPaymentComponent confirmPayment —
 *   all rode the browser default. 30s is generous for fiscal_sequence
 *   allocation under contention (Cache::lock TTL 5s + DB FOR UPDATE) while
 *   still surfacing pathological hangs to the operator within useful time.
 *
 * Sentinel locks the line in resources/js/bootstrap.js. Per-call overrides
 * via `{ timeout: N }` in `axios.post(...)` remain possible — this is a
 * default, not a ceiling.
 */
describe('axios — global timeout default (GOAL-F2-HEAL-01 / F12-FIND-01)', () => {
    const bootstrap = readFileSync(
        resolve(process.cwd(), 'resources/js/bootstrap.js'),
        'utf8',
    );

    it('bootstrap.js sets window.axios.defaults.timeout = 30000', () => {
        // Tolerant of whitespace variations (e.g. window . axios) but locks
        // the literal numeric value 30000 so a future "loosening" to e.g.
        // 300000 (5min — back to browser default territory) flags here.
        expect(bootstrap).toMatch(
            /window\.axios\.defaults\.timeout\s*=\s*30000\s*;/,
        );
    });

    it('bootstrap.js attaches the timeout AFTER window.axios = axios', () => {
        // Order matters: if the timeout assignment lands before the global
        // assignment, it silently mutates the local import and any code
        // that captured `axios` earlier won't inherit it.
        const idxGlobal = bootstrap.indexOf('window.axios = axios');
        const idxTimeout = bootstrap.search(
            /window\.axios\.defaults\.timeout\s*=\s*30000/,
        );
        expect(idxGlobal).toBeGreaterThan(-1);
        expect(idxTimeout).toBeGreaterThan(idxGlobal);
    });

    it('bootstrap.js documents the 30s choice (audit trail for future devs)', () => {
        // Non-strict comment marker — keeps the rationale discoverable so a
        // future dev doesn't blindly "tighten" the value without thinking
        // about Cache::lock TTL + DB FOR UPDATE contention.
        expect(bootstrap).toMatch(/GOAL-F2-HEAL-01|fiscal_sequence|Cache::lock/);
    });
});
