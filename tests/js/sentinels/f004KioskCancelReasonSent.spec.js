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
        // [GOAL-2026-05-29] The cancel payload is now hoisted into a NAMED const so an
        // X-Idempotency-Key can be propagated alongside it (commit 1eebd208c, NF525 §9).
        // The invariant (every change-status POST carries a whitelisted reason) is
        // unchanged — follow each POST's payload VARIABLE to its const declaration and
        // assert that const has a reason: key.
        const postVars = [...paymentSource.matchAll(/change-status\/\$\{[^}]+\}`\s*,\s*([A-Za-z_$][\w$]*)/g)].map((m) => m[1]);
        expect(postVars.length).toBeGreaterThan(0);
        for (const v of postVars) {
            expect(paymentSource).toMatch(new RegExp(`const\\s+${v}\\s*=\\s*\\{[\\s\\S]{0,200}?reason\\s*:`));
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
        // [GOAL-2026-05-29] payload hoisted to a named const (idempotency-key propagation);
        // resolve the POST's payload variable to its declaration and assert the reason.
        const m = waitingSource.match(/change-status\/\$\{[^}]+\}`\s*,\s*([A-Za-z_$][\w$]*)/);
        expect(m).not.toBeNull();
        expect(waitingSource).toMatch(new RegExp(`const\\s+${m[1]}\\s*=\\s*\\{[\\s\\S]{0,200}?reason\\s*:\\s*'customer_request'`));
    });

    it('comment trail references AUDIT-F-004 for traceability', () => {
        expect(paymentSource).toContain('AUDIT-F-004');
        expect(waitingSource).toContain('AUDIT-F-004');
    });
});
