import { describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />' },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: {
        currencyFormat: vi.fn((amount) => String(amount)),
        floatNumber: vi.fn(),
        modalHide: vi.fn(),
        modalShow: vi.fn(),
    },
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { error: vi.fn() },
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({
    openDrawer: vi.fn(() => Promise.resolve()),
}));
vi.mock('../../resources/js/store/modules/posCart', () => ({
    normalizeCartForApi: vi.fn((items) => items),
}));

import PaymentComponent from '../../resources/js/components/admin/pos/PaymentComponent.vue';

function retryVm(attemptResults, refreshResult = Promise.resolve({ data: { status: true } })) {
    const order = [];
    const runConfirmOrderAttempt = vi.fn(() => {
        order.push('attempt');
        const next = attemptResults.shift();
        return next instanceof Error || next?.response
            ? Promise.reject(next)
            : Promise.resolve(next);
    });
    const refreshPaymentAuth = vi.fn(() => {
        order.push('refresh');
        return refreshResult;
    });

    return {
        order,
        runConfirmOrderAttempt,
        refreshPaymentAuth,
        isUnauthorized: PaymentComponent.methods.isUnauthorized,
        sessionExpiredError: PaymentComponent.methods.sessionExpiredError,
    };
}

describe('PaymentComponent one-shot 401 retry', () => {
    it('refreshPaymentAuth uses the authcheck action and fails closed on expired session', async () => {
        const okVm = {
            $store: { dispatch: vi.fn(() => Promise.resolve({ data: { status: true } })) },
            sessionExpiredError: PaymentComponent.methods.sessionExpiredError,
        };

        await expect(PaymentComponent.methods.refreshPaymentAuth.call(okVm)).resolves.toEqual({ data: { status: true } });
        expect(okVm.$store.dispatch).toHaveBeenCalledWith('authcheck');

        const expiredVm = {
            $store: { dispatch: vi.fn(() => Promise.resolve({ data: { status: false } })) },
            sessionExpiredError: PaymentComponent.methods.sessionExpiredError,
        };

        await expect(PaymentComponent.methods.refreshPaymentAuth.call(expiredVm))
            .rejects.toThrow('Session expirée. Reconnectez-vous puis relancez le paiement.');
    });

    it('refreshes auth before retrying the payment confirm attempt once', async () => {
        const unauthorized = { response: { status: 401 } };
        const vm = retryVm([unauthorized, 'ok']);

        await expect(PaymentComponent.methods.confirmOrderWithAuthRetry.call(vm)).resolves.toBe('ok');

        expect(vm.order).toEqual(['attempt', 'refresh', 'attempt']);
        expect(vm.runConfirmOrderAttempt).toHaveBeenCalledTimes(2);
        expect(vm.refreshPaymentAuth).toHaveBeenCalledTimes(1);
    });

    it('does not retry more than once when the second attempt is also unauthorized', async () => {
        const unauthorized = { response: { status: 401 } };
        const vm = retryVm([unauthorized, unauthorized]);

        await expect(PaymentComponent.methods.confirmOrderWithAuthRetry.call(vm))
            .rejects.toThrow('Session expirée. Reconnectez-vous puis relancez le paiement.');

        expect(vm.order).toEqual(['attempt', 'refresh', 'attempt']);
        expect(vm.runConfirmOrderAttempt).toHaveBeenCalledTimes(2);
        expect(vm.refreshPaymentAuth).toHaveBeenCalledTimes(1);
    });

    it('does not refresh auth or retry for non-401 payment failures', async () => {
        const validationError = { response: { status: 422 } };
        const vm = retryVm([validationError]);

        await expect(PaymentComponent.methods.confirmOrderWithAuthRetry.call(vm)).rejects.toBe(validationError);

        expect(vm.order).toEqual(['attempt']);
        expect(vm.runConfirmOrderAttempt).toHaveBeenCalledTimes(1);
        expect(vm.refreshPaymentAuth).not.toHaveBeenCalled();
    });
});
