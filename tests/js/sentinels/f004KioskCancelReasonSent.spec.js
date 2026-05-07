import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  F-004 — Cancel reason enforcement (frontend half)
 * @source .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md
 * @sprint S3
 *
 * Verrou structural anti-régression : tout call site frontend qui poste sur
 * `frontend/order/change-status/{id}` avec status=CANCELED DOIT joindre un
 * code `reason` whitelisté (OrderCancelReason enum côté backend).
 *
 * Backend OrderStatusRequest enforce désormais :
 *   - reason non-vide pour transitions CANCELED/REJECTED/RETURNED (universel),
 *   - reason whitelisté quand l'actor est un kiosk machine token.
 *
 * Sans reason → 422 → orphan PENDING + audit trail aveugle.
 */
describe('F-004 — Kiosk cancel sends whitelisted reason', () => {
    const paymentSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
        'utf8',
    );
    const waitingSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskWaitingComponent.vue'),
        'utf8',
    );

    it('KioskPaymentComponent never posts cancel-status without reason key', () => {
        // Find every change-status POST and assert each one carries a reason: token within
        // the same payload object (≤ 250 chars window).
        const matches = paymentSource.match(/change-status\/\$\{[^}]+\}`[\s\S]{0,250}?\{[\s\S]{0,300}?\}/g) || [];
        expect(matches.length).toBeGreaterThan(0);
        for (const m of matches) {
            expect(m).toMatch(/reason\s*:/);
        }
    });

    it('KioskPaymentComponent declined branch uses tpe_declined or tpe_timeout code', () => {
        // The processCardPayment failure branch (declined / timeout) must use one of the two
        // TPE-prefixed whitelisted codes.
        expect(paymentSource).toMatch(/tpe_declined|tpe_timeout/);
    });

    it('KioskPaymentComponent customer-cancel branch uses tpe_cancel_user code', () => {
        // cancelCardPayment voids the order with reason 'tpe_cancel_user'.
        expect(paymentSource).toMatch(/'tpe_cancel_user'/);
    });

    it('KioskWaitingComponent customer cancel sends customer_request reason', () => {
        expect(waitingSource).toMatch(/change-status\/\$\{[^}]+\}`[\s\S]{0,250}?reason\s*:\s*'customer_request'/);
    });

    it('comment trail references AUDIT-F-004 for traceability', () => {
        expect(paymentSource).toContain('AUDIT-F-004');
        expect(waitingSource).toContain('AUDIT-F-004');
    });
});
