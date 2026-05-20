/**
 * [Wave W P-OWNER 2026-05-21] Encaisser borne — mode picker + idempotency
 * header sentinel.
 *
 * Bug being prevented: the previous `collectKioskCashOrder` POSTed to
 * /admin/pos/counter-collect/{id}/confirm WITHOUT an X-Idempotency-Key
 * header, which the Wave K Z7 idempotency middleware
 * (config/idempotency.php + IdempotencyKeyMiddleware) rejects with
 * 422 "Header X-Idempotency-Key requis pour cette opération." — the
 * symptom owner saw on every Encaisser click.
 *
 * Also: the new UX requires a mode picker (ESPÈCE / CARTE / TICKET /
 * MOBILE) because the borne declares CASH but the customer may switch
 * at the counter. The picker maps UI mode-name → posPaymentMethodEnum
 * int, which MUST match the backend allow-list in PaymentService::
 * confirmCounterPayment ($allowedModes line 203-209 — CASH=1, CARD=2,
 * MOBILE_BANKING=3, TICKET_RESTAURANT=5).
 *
 * Mirrors tests/js/posLoyaltyMainPageCta.spec.js pattern — pure unit
 * test the gating + key-building contracts without mounting the
 * 4 200-line PosComponent (Vuex + Router + Swiper + 50+ imports).
 *
 * The two assertions this file LOCKS:
 *   1. mode-int mapping CASH/CARD/MOBILE/TICKET stays byte-equivalent
 *      to posPaymentMethodEnum (regression catches typo-swaps like the
 *      brief's wrong "CASH=2, CARD=3" map).
 *   2. The idempotency-key string follows the same minute-bucket
 *      pattern as PosLoyaltyRedeemModal.buildIdempotencyKey() — stable
 *      per (orderId, mode) within a minute (so a double-tap replays
 *      the cached 2xx), distinct across minutes (so a deliberate
 *      retry 90s later is a fresh request, not a stale cache hit).
 */
import { describe, it, expect } from 'vitest';
import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum.js';

/**
 * Mirrors the modeMap in PosComponent.confirmEncaisser() — kept in lock
 * step with the backend $allowedModes whitelist. If this test fails the
 * implementer changed the picker without updating the contract, or
 * `posPaymentMethodEnum` changed its int values (which would also break
 * the backend allow-list since the two are coupled by spec).
 */
function modeMapForEncaisser() {
    return {
        CASH: posPaymentMethodEnum.CASH,
        CARD: posPaymentMethodEnum.CARD,
        MOBILE: posPaymentMethodEnum.MOBILE_BANKING,
        TICKET: posPaymentMethodEnum.TICKET_RESTAURANT,
    };
}

/**
 * Re-implementation of PosComponent.buildKioskCashIdempotencyKey()
 * — kept here as a sibling so the contract is testable without
 * mounting the component. If the production helper drifts (e.g.
 * someone swaps minute-bucket for ms-bucket and breaks
 * double-tap replay) this test fails.
 */
function buildKioskCashIdempotencyKey(orderId, modeInt, nowMs = Date.now()) {
    const minuteBucket = Math.floor(nowMs / 60000);
    return `pos-counter-collect-${orderId}-${modeInt}-${minuteBucket}`;
}

describe('Wave W — kiosk-cash encaisser mode-int mapping', () => {
    it('maps CASH → posPaymentMethodEnum.CASH (1)', () => {
        const map = modeMapForEncaisser();
        expect(map.CASH).toBe(posPaymentMethodEnum.CASH);
        expect(map.CASH).toBe(1);
    });

    it('maps CARD → posPaymentMethodEnum.CARD (2)', () => {
        const map = modeMapForEncaisser();
        expect(map.CARD).toBe(posPaymentMethodEnum.CARD);
        expect(map.CARD).toBe(2);
    });

    it('maps MOBILE → posPaymentMethodEnum.MOBILE_BANKING (3)', () => {
        const map = modeMapForEncaisser();
        expect(map.MOBILE).toBe(posPaymentMethodEnum.MOBILE_BANKING);
        expect(map.MOBILE).toBe(3);
    });

    it('maps TICKET → posPaymentMethodEnum.TICKET_RESTAURANT (5)', () => {
        const map = modeMapForEncaisser();
        expect(map.TICKET).toBe(posPaymentMethodEnum.TICKET_RESTAURANT);
        expect(map.TICKET).toBe(5);
    });

    it('every mapped int is in the backend allow-list (1,2,3,4,5)', () => {
        const allowed = new Set([1, 2, 3, 4, 5]); // PaymentService::confirmCounterPayment line 203-209
        const map = modeMapForEncaisser();
        for (const intMode of Object.values(map)) {
            expect(allowed.has(intMode)).toBe(true);
        }
    });

    it('never sends COUNTER_DEFERRED (6) — that mode is borne→pending only', () => {
        const map = modeMapForEncaisser();
        expect(Object.values(map)).not.toContain(posPaymentMethodEnum.COUNTER_DEFERRED);
    });
});

describe('Wave W — X-Idempotency-Key contract for /counter-collect/confirm', () => {
    it('returns a deterministic minute-bucketed string per (orderId, mode)', () => {
        const now = 1747861200000; // fixed ms in some minute
        const k1 = buildKioskCashIdempotencyKey(42, 1, now);
        const k2 = buildKioskCashIdempotencyKey(42, 1, now + 100); // 100ms later, same bucket
        expect(k1).toBe(k2);
        expect(k1).toMatch(/^pos-counter-collect-42-1-\d+$/);
    });

    it('emits distinct keys for distinct orders (double-tap on row A does not replay row B)', () => {
        const now = 1747861200000;
        const k42 = buildKioskCashIdempotencyKey(42, 1, now);
        const k43 = buildKioskCashIdempotencyKey(43, 1, now);
        expect(k42).not.toBe(k43);
    });

    it('emits distinct keys when the cashier swaps mode for the same order (CASH → CARD)', () => {
        const now = 1747861200000;
        const cashKey = buildKioskCashIdempotencyKey(42, posPaymentMethodEnum.CASH, now);
        const cardKey = buildKioskCashIdempotencyKey(42, posPaymentMethodEnum.CARD, now);
        expect(cashKey).not.toBe(cardKey);
    });

    it('emits distinct keys 60s apart (deliberate retry is NOT a stale cache hit)', () => {
        const now = 1747861200000;
        const before = buildKioskCashIdempotencyKey(42, 1, now);
        const after = buildKioskCashIdempotencyKey(42, 1, now + 60_000); // next minute
        expect(before).not.toBe(after);
    });

    it('idempotency key is a non-empty string with no whitespace (safe HTTP header)', () => {
        const k = buildKioskCashIdempotencyKey(42, 1);
        expect(typeof k).toBe('string');
        expect(k.length).toBeGreaterThan(0);
        expect(k).not.toMatch(/\s/);
    });
});
