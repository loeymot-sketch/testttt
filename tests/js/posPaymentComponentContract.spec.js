import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const posComponentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/pos/PosComponent.vue',
);
const paymentComponentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/pos/PaymentComponent.vue',
);

describe('POS payment component parent-state contract', () => {
    it('wires PaymentComponent events to PosComponent handlers', () => {
        const source = readFileSync(posComponentPath, 'utf8');

        expect(source).toContain('@payment-form:patch="patchPaymentForm"');
        expect(source).toContain('@payment-form:reset="resetPaymentForm"');
    });

    it('applies child payment patches by replacing parent-owned checkout form state', () => {
        const source = readFileSync(posComponentPath, 'utf8');
        const methodStart = source.indexOf('patchPaymentForm(patch)');
        const methodEnd = source.indexOf('resetPaymentForm()', methodStart);
        const methodSource = source.slice(methodStart, methodEnd);

        expect(methodSource).toContain('this.checkoutProps.form = {');
        expect(methodSource).toContain('...this.checkoutProps.form');
        expect(methodSource).toContain('...patch');
    });

    it('centralizes successful payment reset in PosComponent instead of PaymentComponent props', () => {
        const posSource = readFileSync(posComponentPath, 'utf8');
        const paymentSource = readFileSync(paymentComponentPath, 'utf8');
        const resetStart = posSource.indexOf('resetPaymentForm()');
        const resetEnd = posSource.indexOf('openParkedOrders()', resetStart);
        const resetSource = posSource.slice(resetStart, resetEnd);

        expect(resetSource).toContain('pos_payment_method: posPaymentMethodEnum.CASH');
        expect(resetSource).toContain('order_type: orderTypeEnum.TAKEAWAY');
        expect(resetSource).toContain('quote_token: null');
        expect(paymentSource).toContain('this.$emit("payment-form:reset")');
    });
});
