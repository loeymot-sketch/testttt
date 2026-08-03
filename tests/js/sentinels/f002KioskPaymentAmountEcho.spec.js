import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID F-002 — TPE Amount Echo Verification (frontend half)
 * @source plans/PLAN_AUDIT_F002_TPE_AMOUNT_ECHO_2026-05-07.md
 * @sprint S1
 *
 * Verrou structural anti-régression : KioskPaymentComponent.vue DOIT envoyer
 * `amount_cents` dans confirmBackendPayment (sinon backend retourne 422 puisque
 * F-002 a rendu le champ required dans PaymentConfirmRequest::rules()).
 *
 * Le _invokeTpe stub DOIT echo `amount_cents_approved` mirroir pour que la chaîne
 * end-to-end fonctionne sans hardware réel (mode bypass + dev).
 */
describe('F-002 — Kiosk payment amount_cents echo (frontend)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
        'utf8',
    );

    it('confirmBackendPayment payload includes amount_cents key', () => {
        // Pattern: amount_cents inside the confirmBackendPayment(...) call payload object
        expect(source).toMatch(/confirmBackendPayment\([\s\S]{0,300}?amount_cents:/);
    });

    it('amount_cents source is paymentResult.amount_cents_approved with fallback to expected cents', () => {
        expect(source).toMatch(/paymentResult\.amount_cents_approved/);
        expect(source).toMatch(/Number\.isInteger\(paymentResult\.amount_cents_approved\)/);
    });

    it('stub _invokeTpe echoes amount_cents_approved (mirror amountCents)', () => {
        // The stub branch (no kiosk bridge) must include amount_cents_approved in the return
        expect(source).toMatch(/!kioskHardware\.isKioskBridge\(\)[\s\S]{0,500}?amount_cents_approved:\s*amountCents/);
    });

    it('real bridge branch extracts amount_cents_approved from raw response or fallback', () => {
        expect(source).toMatch(/Number\.isInteger\(raw\.amount_cents_approved\)/);
        expect(source).toMatch(/amount_cents_approved:\s*echoedAmount/);
    });

    it('comment trail references AUDIT-F-002 for traceability', () => {
        expect(source).toContain('AUDIT-F-002');
    });
});
