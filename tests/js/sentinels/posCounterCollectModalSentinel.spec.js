import { describe, expect, it } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  WAVE-X-X1 | @source plan reports/test-e2e/wave-x-2026-05-21/PLAN.md
 * @reason
 *   Wave X X1 replaces the Wave W in-template mode-picker (encaisserModal)
 *   with a NEW sibling component `PosCounterCollectModal.vue` that mirrors
 *   PaymentComponent's V5 design language. The frozen PaymentComponent.vue
 *   itself stays untouched (CLAUDE.md §7 + emits sentinel). The new modal
 *   ships:
 *     - X-Idempotency-Key on confirm POST (same minute-bucket formula as
 *       Wave W: `pos-counter-collect-{orderId}-{modeInt}-{minuteBucket}`)
 *     - 4-mode picker (CASH | CARD | MOBILE | TICKET) with full i18n
 *     - PosV5Numpad for CASH received entry + rendu calc (overpay)
 *     - Single-tap confirm for CARD/MOBILE/TICKET
 *     - Visual parity atoms (hero total card + segmented mode grid)
 *
 *   Multi-tranche split is DEFERRED to V1.0.2 (backend extension required
 *   — `PaymentService::confirmCounterPayment` is single-mode + short-
 *   circuits on PAID, naive loop would silently lose tranches 2..N).
 *
 *   This sentinel locks the structural contract so future refactors do not
 *   regress the SSOT promise or the idempotency-key shape.
 */
describe('PosCounterCollectModal — Wave X X1 SSOT counter-collect modal', () => {
    const modalPath = resolve(
        process.cwd(),
        'resources/js/components/admin/pos/PosCounterCollectModal.vue',
    );

    it('component file exists at the expected path', () => {
        expect(existsSync(modalPath)).toBe(true);
    });

    const source = existsSync(modalPath) ? readFileSync(modalPath, 'utf8') : '';

    it('declares the canonical component name', () => {
        expect(source).toMatch(/name:\s*['"]PosCounterCollectModal['"]/);
    });

    it('reuses the shared PosV5Numpad atom (visual parity with PaymentComponent)', () => {
        expect(source).toMatch(/import\s+PosV5Numpad\s+from/);
        expect(source).toMatch(/<PosV5Numpad/);
    });

    it('reuses posPaymentMethodEnum for mode mapping (no magic ints)', () => {
        expect(source).toMatch(/import\s+posPaymentMethodEnum\s+from/);
        expect(source).toMatch(/posPaymentMethodEnum\.CASH/);
        expect(source).toMatch(/posPaymentMethodEnum\.CARD/);
        expect(source).toMatch(/posPaymentMethodEnum\.MOBILE_BANKING/);
        expect(source).toMatch(/posPaymentMethodEnum\.TICKET_RESTAURANT/);
    });

    it('POSTs to /admin/pos/counter-collect/{order}/confirm (backend SSOT route)', () => {
        expect(source).toMatch(/admin\/pos\/counter-collect\/\$\{[a-zA-Z]+\}\/confirm/);
    });

    it('sends an X-Idempotency-Key header on confirm (Wave K Z7 + Wave W contract)', () => {
        expect(source).toMatch(/'X-Idempotency-Key'/);
    });

    it('idempotency key uses the canonical pos-counter-collect-{orderId}-{modeInt}-{minuteBucket} formula', () => {
        // The exact backtick-template string includes both `orderId`, `modeInt`
        // and `minuteBucket` interpolation tokens.
        expect(source).toMatch(/`pos-counter-collect-\$\{orderId\}-\$\{modeInt\}-\$\{minuteBucket\}`/);
    });

    it('minute-bucket formula uses Math.floor(Date.now() / 60000)', () => {
        expect(source).toMatch(/Math\.floor\(Date\.now\(\)\s*\/\s*60000\)/);
    });

    it('CASH path computes rendu (cashChange) when received > total', () => {
        expect(source).toMatch(/cashChange/);
        expect(source).toMatch(/cashReceivedNumber\s*-\s*this\.orderTotal/);
    });

    it('CASH path blocks confirm when received < total (canConfirm guard)', () => {
        // The canConfirm computed must enforce received >= total for CASH.
        expect(source).toMatch(/canConfirm/);
        expect(source).toMatch(/cashReceivedNumber\s*>=\s*this\.orderTotal/);
    });

    it('emits both `confirmed` and `cancel` events for parent wire-up', () => {
        expect(source).toMatch(/emits:\s*\[[^\]]*'confirmed'[^\]]*\]/);
        expect(source).toMatch(/emits:\s*\[[^\]]*'cancel'[^\]]*\]/);
        expect(source).toMatch(/\$emit\(\s*'confirmed'/);
        expect(source).toMatch(/\$emit\(\s*'cancel'/);
    });

    it('confirmed payload carries orderId + mode + modeInt + received + total', () => {
        // Must propagate the audit envelope the parent uses to refresh lists.
        expect(source).toMatch(/orderId/);
        expect(source).toMatch(/modeInt/);
        // received field must appear in the emitted payload (CASH path).
        expect(source).toMatch(/received(?:\s*[:,])/);
    });

    it('includes data-testid hooks the E2E spec depends on', () => {
        // Static testids.
        expect(source).toMatch(/data-testid="pos-counter-collect-modal"/);
        expect(source).toMatch(/data-testid="pos-counter-collect-confirm"/);
        expect(source).toMatch(/data-testid="pos-counter-collect-cancel"/);
        expect(source).toMatch(/data-testid="pos-counter-collect-received-input"/);
        expect(source).toMatch(/data-testid="pos-counter-collect-total"/);
        // Mode testids are interpolated (`pos-counter-collect-mode-${m.id}`)
        // — assert the template binding + the 4 mode ids appear in the
        // modes array literal.
        expect(source).toMatch(/:data-testid="`pos-counter-collect-mode-\$\{m\.id\}`"/);
        expect(source).toMatch(/id:\s*'CASH'/);
        expect(source).toMatch(/id:\s*'CARD'/);
        expect(source).toMatch(/id:\s*'MOBILE'/);
        expect(source).toMatch(/id:\s*'TICKET'/);
    });

    it('explicitly defers multi-tranche split to V1.0.2 (commented rationale)', () => {
        // The deferral is load-bearing: future contributors must not be
        // tempted to loop confirmCounterPayment per tranche (would silently
        // lose tranches 2..N due to PaymentService:227-230 short-circuit).
        expect(source.toLowerCase()).toMatch(/multi-tranche|tranche/);
        expect(source.toLowerCase()).toMatch(/v1\.0\.2|deferred/);
    });

    it('PaymentComponent.vue is untouched (frozen — sibling pattern only)', () => {
        // Cross-check: the new modal must NOT live inside PaymentComponent.vue.
        const paymentPath = resolve(
            process.cwd(),
            'resources/js/components/admin/pos/PaymentComponent.vue',
        );
        const paymentSource = readFileSync(paymentPath, 'utf8');
        expect(paymentSource).not.toMatch(/PosCounterCollectModal/);
        expect(paymentSource).not.toMatch(/counter-collect\/\{[^}]+\}\/confirm/);
    });
});
