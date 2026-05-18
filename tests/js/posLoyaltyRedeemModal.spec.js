/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] PosLoyaltyRedeemModal Vitest spec.
 *
 * Covers :
 *   1. Renders with proper a11y attributes (role=dialog, aria-modal).
 *   2. Apply button disabled until both code (>=4 chars) and points (>0) entered.
 *   3. Submitting calls /api/admin/pos-order/{id}/redeem-loyalty with idempotency
 *      header + correct payload.
 *   4. Success path : emits 'applied' with discount_eur, balance_after.
 *   5. Error path : maps backend error_code to localized i18n message.
 *   6. Esc key emits close.
 *   7. Backdrop click emits close.
 *   8. Discount preview computes correctly (points / rate).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Mock axios BEFORE importing the component under test.
vi.mock('axios', () => {
    return {
        default: {
            post: vi.fn(),
        },
    };
});
import axios from 'axios';
import PosLoyaltyRedeemModal from '../../resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue';

const messages = {
    'pos.loyalty.redeem.title': 'Appliquer une réduction fidélité',
    'pos.loyalty.redeem.balance': 'Solde actuel',
    'pos.loyalty.redeem.input_code': 'Code fidélité ou téléphone',
    'pos.loyalty.redeem.input_points': 'Points à utiliser',
    'pos.loyalty.redeem.apply': 'Appliquer',
    'pos.loyalty.redeem.cancel': 'Annuler',
    'pos.loyalty.redeem.close': 'Fermer',
    'pos.loyalty.redeem.success': 'Réduction appliquée : -{amount}',
    'pos.loyalty.redeem.rate_hint': '{rate} points = 1 €',
    'pos.loyalty.redeem.preview_discount': 'Réduction prévue',
    'pos.loyalty.redeem.errors.insufficient_balance': 'Solde insuffisant pour cette réduction.',
    'pos.loyalty.redeem.errors.customer_not_found': 'Code fidélité introuvable.',
    'pos.loyalty.redeem.errors.already_redeemed': 'Une réduction fidélité a déjà été appliquée à cette commande.',
    'pos.loyalty.redeem.errors.order_already_finalized': 'Cette commande est déjà payée ou clôturée — réduction impossible.',
    'pos.loyalty.redeem.errors.points_not_multiple': 'Les points doivent être un multiple de {rate}.',
    'pos.loyalty.redeem.errors.discount_exceeds_subtotal': 'Le montant de la réduction dépasse le sous-total.',
    'pos.loyalty.redeem.errors.permission_denied': "Vous n'avez pas le droit d'appliquer une réduction fidélité.",
    'pos.loyalty.redeem.errors.generic': "Impossible d'appliquer la réduction.",
};

function tWithParams(key, params) {
    let msg = messages[key] || key;
    if (params) {
        Object.keys(params).forEach((k) => {
            msg = msg.replace(new RegExp(`\\{${k}\\}`, 'g'), String(params[k]));
        });
    }
    return msg;
}

function mountModal({ open = true, orderId = 42, rate = 100 } = {}) {
    return mount(PosLoyaltyRedeemModal, {
        props: { open, orderId, rate },
        global: {
            mocks: {
                $t: tWithParams,
            },
        },
    });
}

describe('PosLoyaltyRedeemModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders with role=dialog + aria-modal when open', async () => {
        const wrapper = mountModal({ open: true });
        await flushPromises();

        const overlay = wrapper.find('[data-testid="pos-loyalty-redeem-overlay"]');
        expect(overlay.exists()).toBe(true);
        expect(overlay.attributes('role')).toBe('dialog');
        expect(overlay.attributes('aria-modal')).toBe('true');
        expect(overlay.attributes('aria-label')).toBe('Appliquer une réduction fidélité');
    });

    it('does NOT render when open=false', () => {
        const wrapper = mountModal({ open: false });
        expect(wrapper.find('[data-testid="pos-loyalty-redeem-overlay"]').exists()).toBe(false);
    });

    it('disables apply button until both code and points provided', async () => {
        const wrapper = mountModal({ open: true });
        await flushPromises();

        const apply = wrapper.find('[data-testid="pos-loyalty-redeem-apply"]');
        expect(apply.attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="pos-loyalty-redeem-code-input"]').setValue('ABCD1234');
        await flushPromises();
        expect(apply.attributes('disabled')).toBeDefined(); // points still 0

        await wrapper.find('[data-testid="pos-loyalty-redeem-points-input"]').setValue(100);
        await flushPromises();
        expect(apply.attributes('disabled')).toBeUndefined();
    });

    it('shows discount preview computed from points/rate', async () => {
        const wrapper = mountModal({ open: true, rate: 100 });
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-points-input"]').setValue(250);
        await flushPromises();

        const preview = wrapper.find('[data-testid="pos-loyalty-redeem-preview"]');
        expect(preview.exists()).toBe(true);
        expect(preview.text()).toMatch(/2\.50/);
    });

    it('shows the rate hint with the rate prop value', async () => {
        const wrapper = mountModal({ open: true, rate: 50 });
        await flushPromises();
        const hint = wrapper.find('[data-testid="pos-loyalty-redeem-rate-hint"]');
        expect(hint.exists()).toBe(true);
        expect(hint.text()).toContain('50 points');
    });

    it('submits to /api/admin/pos-order/{id}/redeem-loyalty with idempotency header and emits applied on success', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                status: true,
                data: {
                    discount_eur: 1.0,
                    balance_after: 400,
                    order: { id: 42, subtotal: 25, discount: 1, total: 24 },
                    transaction_id: 17,
                },
            },
        });

        const wrapper = mountModal({ open: true, orderId: 42 });
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-code-input"]').setValue('TESTCUST');
        await wrapper.find('[data-testid="pos-loyalty-redeem-points-input"]').setValue(100);
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-apply"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, payload, options] = axios.post.mock.calls[0];
        expect(url).toBe('/api/admin/pos-order/42/redeem-loyalty');
        expect(payload).toEqual({ points: 100, loyalty_code: 'TESTCUST' });
        expect(options.headers['X-Idempotency-Key']).toMatch(/^pos-redeem-42-TESTCUST-100-/);

        // Success band visible.
        expect(wrapper.find('[data-testid="pos-loyalty-redeem-success"]').exists()).toBe(true);

        // Emitted 'applied' with payload.
        const events = wrapper.emitted('applied');
        expect(events).toBeTruthy();
        expect(events[0][0]).toEqual({
            discount_eur: 1.0,
            balance_after: 400,
            order: { id: 42, subtotal: 25, discount: 1, total: 24 },
            transaction_id: 17,
        });

        // Customer balance now displayed.
        expect(wrapper.find('[data-testid="pos-loyalty-redeem-balance"]').text()).toContain('400');
    });

    it('maps backend error_code to localized message', async () => {
        axios.post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: { status: false, code: 'INSUFFICIENT_BALANCE', message: 'whatever' },
            },
        });

        const wrapper = mountModal({ open: true, orderId: 42 });
        await flushPromises();
        await wrapper.find('[data-testid="pos-loyalty-redeem-code-input"]').setValue('TESTCUST');
        await wrapper.find('[data-testid="pos-loyalty-redeem-points-input"]').setValue(100);
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-apply"]').trigger('click');
        await flushPromises();

        const errorBand = wrapper.find('[data-testid="pos-loyalty-redeem-error"]');
        expect(errorBand.exists()).toBe(true);
        expect(errorBand.text()).toContain('Solde insuffisant');
        // 'applied' must NOT have been emitted on failure.
        expect(wrapper.emitted('applied')).toBeUndefined();
    });

    it('maps 403 to permission-denied message', async () => {
        axios.post.mockRejectedValueOnce({
            response: {
                status: 403,
                data: { status: false, message: 'Non autorise' },
            },
        });

        const wrapper = mountModal({ open: true, orderId: 42 });
        await flushPromises();
        await wrapper.find('[data-testid="pos-loyalty-redeem-code-input"]').setValue('TESTCUST');
        await wrapper.find('[data-testid="pos-loyalty-redeem-points-input"]').setValue(100);
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-apply"]').trigger('click');
        await flushPromises();

        const errorBand = wrapper.find('[data-testid="pos-loyalty-redeem-error"]');
        expect(errorBand.exists()).toBe(true);
        expect(errorBand.text()).toContain("Vous n'avez pas le droit");
    });

    it('emits close on backdrop click', async () => {
        const wrapper = mountModal({ open: true });
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-overlay"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('emits close on Esc keydown', async () => {
        const wrapper = mountModal({ open: true });
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-overlay"]').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('emits close when cancel button clicked', async () => {
        const wrapper = mountModal({ open: true });
        await flushPromises();

        await wrapper.find('[data-testid="pos-loyalty-redeem-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('close')).toBeTruthy();
    });
});
