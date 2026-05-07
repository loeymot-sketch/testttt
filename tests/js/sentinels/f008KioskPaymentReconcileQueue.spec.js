import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID F-008 — Payment Confirm Reconciliation Queue (frontend structural)
 * @source plans/PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md
 * @sprint S4
 *
 * Verrou structural anti-régression : KioskPaymentComponent.vue DOIT exposer
 * la machinerie F-008 :
 *   - confirmBackendPayment retry exhaustion → _appendPendingReconcile
 *   - boot-time _reconcilePendingPayments (mounted hook + setInterval 60s)
 *   - localStorage key 'pending_payment_confirms'
 *   - borne 30 min sur entries (pas de retry indéfini)
 *   - borne 50 entries (anti-explosion localStorage)
 *   - kiosk-event observability via type 'sync_failed' (whitelisted)
 *   - aucun PAN ni info bancaire persisté (PCI-DSS)
 */
describe('F-008 — Kiosk payment reconcile queue (frontend structural)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
        'utf8',
    );

    it('confirmBackendPayment appends to localStorage on retry exhaustion', () => {
        // After the 3-retry loop fails, the TPE-approved tx must be persisted.
        expect(source).toMatch(/_appendPendingReconcile\s*\(/);
        // The append must happen INSIDE the catch/exhaustion path of confirmBackendPayment.
        expect(source).toMatch(/confirmBackendPayment[\s\S]{0,2000}?_appendPendingReconcile/);
    });

    it('localStorage key contract is `pending_payment_confirms`', () => {
        // Key MUST be stable — analytics + ops dashboards rely on it.
        expect(source).toMatch(/['"]pending_payment_confirms['"]/);
    });

    it('mounted hook triggers boot-time reconcile + 60s interval', () => {
        expect(source).toMatch(/mounted\s*\(\)\s*\{[\s\S]{0,2000}?_reconcilePendingPayments/);
        expect(source).toMatch(/setInterval\s*\([\s\S]{0,200}?_reconcilePendingPayments[\s\S]{0,200}?60000/);
    });

    it('beforeUnmount clears the reconcile interval', () => {
        // No leak — clearInterval must mirror setInterval.
        expect(source).toMatch(/beforeUnmount\s*\(\)\s*\{[\s\S]{0,1500}?clearInterval[\s\S]{0,200}?_reconcileInterval/);
    });

    it('reconcile entry shape contains transaction_id + amount_cents (no PAN)', () => {
        // PCI-DSS : only transaction_id + label card_type + integer payment_method.
        // PAN ('card_number'), CVV/CVC, IBAN must NEVER be persisted as a key.
        expect(source).toMatch(/transaction_id:\s*payload\.transaction_id/);
        expect(source).toMatch(/amount_cents:\s*payload\.amount_cents/);
        // Word-bounded check on field/key names only (avoid false positives on
        // 'span' / 'pan-french-words'). Look for actual key:value patterns.
        const forbiddenKeys = /(['"`])(?:card_number|cvv|cvc|iban|customer_email|customer_phone)\1\s*:/i;
        expect(source).not.toMatch(forbiddenKeys);
    });

    it('expired entries are bounded at 30 minutes', () => {
        // 30 * 60 * 1000 = 1800000 ; either form acceptable.
        expect(source).toMatch(/30\s*\*\s*60\s*\*\s*1000|1800000/);
        expect(source).toMatch(/_isPendingReconcileExpired/);
    });

    it('localStorage list bounded at 50 entries (anti-explosion)', () => {
        expect(source).toMatch(/slice\s*\(\s*0\s*,\s*50\s*\)/);
    });

    it('observability uses kiosk-event whitelisted type sync_failed', () => {
        // ALLOWED_TYPES whitelist (KioskEventController) does NOT include
        // 'payment_confirm_retry_exhausted' nor 'payment_reconcile_expired'.
        // We MUST piggyback on the whitelisted 'sync_failed' type with a
        // dedicated subtype — otherwise observability silently 422s.
        expect(source).toMatch(/type:\s*['"]sync_failed['"]/);
        expect(source).toMatch(/payment_confirm_retry_exhausted/);
        expect(source).toMatch(/payment_reconcile_expired/);
    });

    it('reconcile POSTs to /frontend/payment/reconcile-pending with entries array', () => {
        expect(source).toMatch(/frontend\/payment\/reconcile-pending/);
        expect(source).toMatch(/entries\s*\}/);
    });

    it('reconciled transactions are removed from localStorage on success', () => {
        // Filter results by status reconciled OR already_paid → drop those tx_ids.
        expect(source).toMatch(/['"]reconciled['"]/);
        expect(source).toMatch(/['"]already_paid['"]/);
        expect(source).toMatch(/_writePendingReconcile/);
    });

    it('AUDIT-F-008 traceability marker present', () => {
        // Helps grep the codebase for F-008 surface area during audit cycles.
        expect(source).toMatch(/AUDIT-F-008/);
    });
});
