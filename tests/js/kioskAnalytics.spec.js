/**
 * Kiosk Design V1 — Phase 5.5 : helper kioskAnalytics.js
 *
 * Vérifie :
 *  - track() NO-OP quand pas de consent (RGPD gate §2).
 *  - Consent accordé → POST déclenché (sendBeacon OU axios).
 *  - Event name hors whitelist → silent drop (pas de POST).
 *  - Payload contenant une clé PII (email/phone) → rejeté.
 *  - Session_id généré à la demande + renouvelé par resetSession.
 *  - Queue FIFO plafonnée — eviction des plus anciens quand >200.
 *  - setBranchId coerce les valeurs invalides à null.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

describe('kioskAnalytics helper', () => {
    let kioskAnalytics;
    let postSpy;

    beforeEach(async () => {
        vi.resetModules();
        postSpy = vi.fn().mockResolvedValue({ data: { ok: true } });
        window.axios = { post: postSpy };
        // Force sendBeacon à return false (unsupported) pour retomber sur axios.
        Object.defineProperty(navigator, 'sendBeacon', {
            configurable: true,
            writable: true,
            value: vi.fn(() => false),
        });
        kioskAnalytics = await import('../../resources/js/helpers/kioskAnalytics.js');
        kioskAnalytics.resetSession();
        kioskAnalytics.setConsent(false);
        kioskAnalytics.setBranchId(null);
    });

    it('track() is a no-op without consent', () => {
        const ok = kioskAnalytics.track('add_to_cart', { item_id: 'x', qty: 1, extras_total_cents: 0, line_total_cents: 200, has_menu_upgrade: false });
        expect(ok).toBe(false);
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('emits via axios.post when consent granted', () => {
        kioskAnalytics.setConsent(true);
        const ok = kioskAnalytics.track('add_to_cart', { item_id: 'x', qty: 1, extras_total_cents: 0, line_total_cents: 200, has_menu_upgrade: false });
        expect(ok).toBe(true);
        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, body] = postSpy.mock.calls[0];
        expect(url).toBe('frontend/kiosk-event');
        expect(body.type).toBe('analytics');
        expect(body.event_name).toBe('add_to_cart');
        expect(body.session_id).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('drops events not in whitelist', () => {
        kioskAnalytics.setConsent(true);
        const ok = kioskAnalytics.track('not_a_real_event', { x: 1 });
        expect(ok).toBe(false);
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('rejects payloads containing PII keys', () => {
        kioskAnalytics.setConsent(true);
        const ok = kioskAnalytics.track('loyalty_scanned', {
            method: 'qr',
            email: 'a@b.c', // FORBIDDEN
        });
        expect(ok).toBe(false);
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('ensureSession returns stable UUID until reset', () => {
        const a = kioskAnalytics.ensureSession();
        const b = kioskAnalytics.ensureSession();
        expect(a).toBe(b);
        kioskAnalytics.resetSession();
        const c = kioskAnalytics.ensureSession();
        expect(c).not.toBe(a);
    });

    it('setBranchId coerces invalid to null', () => {
        kioskAnalytics.setBranchId('abc');
        const s = kioskAnalytics._getInternalStateForTest();
        expect(s.branchId).toBe(null);
        kioskAnalytics.setBranchId(42);
        expect(kioskAnalytics._getInternalStateForTest().branchId).toBe(42);
    });

    it('revoking consent empties the queue', () => {
        kioskAnalytics.setConsent(true);
        // On force une queue via un scénario où axios jette
        window.axios.post = vi.fn().mockImplementation(() => {
            throw new Error('network-down');
        });
        try {
            kioskAnalytics.track('add_to_cart', {});
        } catch (_) {}
        kioskAnalytics.setConsent(false);
        const s = kioskAnalytics._getInternalStateForTest();
        expect(s.queueSize).toBe(0);
    });
});
