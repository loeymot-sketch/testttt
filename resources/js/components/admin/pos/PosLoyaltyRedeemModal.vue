<template>
    <transition name="pos-loyalty-redeem">
        <div
            v-if="open"
            class="pos-loyalty-redeem-overlay"
            role="dialog"
            aria-modal="true"
            :aria-label="$t('pos.loyalty.redeem.title')"
            data-testid="pos-loyalty-redeem-overlay"
            @click.self="onBackdrop"
            @keydown.esc.stop="emitClose"
            tabindex="-1"
            ref="overlayRef"
        >
            <section class="pos-loyalty-redeem-dialog" :aria-busy="loading">
                <header class="pos-loyalty-redeem-dialog__head">
                    <h2 class="pos-loyalty-redeem-dialog__title" data-testid="pos-loyalty-redeem-title">
                        {{ $t('pos.loyalty.redeem.title') }}
                    </h2>
                    <button
                        type="button"
                        class="pos-loyalty-redeem-dialog__close"
                        :aria-label="$t('pos.loyalty.redeem.close')"
                        data-testid="pos-loyalty-redeem-close"
                        @click="emitClose"
                    >
                        ✕
                    </button>
                </header>

                <!-- Error band -->
                <div
                    v-if="errorMessage"
                    class="pos-loyalty-redeem-dialog__error"
                    role="alert"
                    data-testid="pos-loyalty-redeem-error"
                >
                    {{ errorMessage }}
                </div>

                <!-- Success band -->
                <div
                    v-if="successMessage"
                    class="pos-loyalty-redeem-dialog__success"
                    role="status"
                    data-testid="pos-loyalty-redeem-success"
                >
                    {{ successMessage }}
                </div>

                <div class="pos-loyalty-redeem-dialog__body">
                    <!-- Customer balance display (after lookup) -->
                    <dl
                        v-if="customerBalance !== null"
                        class="pos-loyalty-redeem-dialog__stats"
                        data-testid="pos-loyalty-redeem-balance-block"
                    >
                        <div class="pos-loyalty-redeem-dialog__stat">
                            <dt>{{ $t('pos.loyalty.redeem.balance') }}</dt>
                            <dd data-testid="pos-loyalty-redeem-balance">
                                {{ customerBalance }} pts
                            </dd>
                        </div>
                    </dl>

                    <!-- Loyalty code input -->
                    <label class="pos-loyalty-redeem-dialog__field-label" for="pos-loyalty-redeem-code">
                        {{ $t('pos.loyalty.redeem.input_code') }}
                    </label>
                    <input
                        id="pos-loyalty-redeem-code"
                        ref="codeInput"
                        v-model="loyaltyCode"
                        type="text"
                        class="pos-loyalty-redeem-dialog__input"
                        autocomplete="off"
                        data-testid="pos-loyalty-redeem-code-input"
                        :aria-label="$t('pos.loyalty.redeem.input_code')"
                        @input="onCodeInput"
                    />

                    <!-- Points to redeem input -->
                    <label class="pos-loyalty-redeem-dialog__field-label" for="pos-loyalty-redeem-points">
                        {{ $t('pos.loyalty.redeem.input_points') }}
                    </label>
                    <input
                        id="pos-loyalty-redeem-points"
                        v-model.number="pointsToRedeem"
                        type="number"
                        :step="rateHint || 100"
                        min="0"
                        class="pos-loyalty-redeem-dialog__input"
                        data-testid="pos-loyalty-redeem-points-input"
                        :aria-label="$t('pos.loyalty.redeem.input_points')"
                    />

                    <!-- Rate hint -->
                    <p
                        v-if="rateHint"
                        class="pos-loyalty-redeem-dialog__hint"
                        data-testid="pos-loyalty-redeem-rate-hint"
                    >
                        {{ $t('pos.loyalty.redeem.rate_hint', { rate: rateHint }) }}
                    </p>

                    <!-- Live preview of discount -->
                    <p
                        v-if="previewDiscountEur > 0"
                        class="pos-loyalty-redeem-dialog__preview"
                        data-testid="pos-loyalty-redeem-preview"
                    >
                        {{ $t('pos.loyalty.redeem.preview_discount') }} :
                        <strong>−{{ previewDiscountEur.toFixed(2) }} €</strong>
                    </p>
                </div>

                <footer class="pos-loyalty-redeem-dialog__actions">
                    <button
                        type="button"
                        class="pos-loyalty-redeem-dialog__btn pos-loyalty-redeem-dialog__btn--ghost"
                        data-testid="pos-loyalty-redeem-cancel"
                        @click="emitClose"
                    >
                        {{ $t('pos.loyalty.redeem.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="pos-loyalty-redeem-dialog__btn pos-loyalty-redeem-dialog__btn--primary"
                        data-testid="pos-loyalty-redeem-apply"
                        :disabled="loading || !canApply"
                        @click="submitApply"
                    >
                        {{ loading ? '…' : $t('pos.loyalty.redeem.apply') }}
                    </button>
                </footer>
            </section>
        </div>
    </transition>
</template>

<script>
/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 POS cashier loyalty redeem modal.
 *
 * Mounted from PosOrderShowComponent (NOT frozen) or any cashier-facing
 * Vue surface that has an active order id.
 *
 * Contract :
 *   Props :
 *     - open          (Boolean) — visibility flag
 *     - orderId       (Number)  — POS order id to redeem against
 *     - rate          (Number)  — points required per €1 (default 100)
 *   Emits :
 *     - close         — user dismissed modal (Esc / backdrop / cancel button)
 *     - applied       — payload { discount_eur, balance_after, order } on
 *                       successful redemption ; parent should refresh totals
 *
 * Backend endpoint :
 *   POST /api/admin/pos-order/{orderId}/redeem-loyalty
 *   Body : { points: int, loyalty_code: string }
 *   Auth : sanctum + permission:pos.redeem-loyalty
 *
 * Anti-fraud safeguards (LOCK §6) are enforced server-side ; this UI only
 * surfaces failures via stable error_code → localized message mapping.
 *
 * A11y :
 *   - role="dialog" + aria-modal="true"
 *   - aria-label from i18n
 *   - Esc key handler emits close
 *   - Backdrop click emits close (click.self only)
 *   - Initial focus moved to loyalty code input on mount
 *   - Error / success bands carry role="alert" + role="status"
 */
import axios from 'axios';
import { onEvents } from '../../../services/eventContract';

export default {
    name: 'PosLoyaltyRedeemModal',
    props: {
        open: {
            type: Boolean,
            default: false,
        },
        orderId: {
            type: [Number, String],
            default: null,
        },
        rate: {
            type: Number,
            default: 100,
        },
        // [GOAL LOYALTY-SYNC L2 2026-06-11] live balance subscription scope.
        branchId: {
            type: [Number, String],
            default: 0,
        },
    },
    emits: ['close', 'applied'],
    mounted() {
        // [L2] Subscribe to balance movements: another surface (kiosk earn,
        // refund clawback, other till) changing the customer's points updates
        // THIS open modal instead of leaving a stale balance.
        const branchId = Number(this.branchId) || 0;
        if (branchId <= 0) return;
        try {
            this._loyaltySub = onEvents(branchId, [
                {
                    broadcastAs: 'LoyaltyBalanceChanged',
                    handler: (event) => {
                        if (this._destroyed) return;
                        if (this.customerBalance === null) return; // no lookup yet
                        const after = Number(event?.balance_after);
                        if (Number.isFinite(after)) {
                            this.customerBalance = after;
                        }
                    },
                },
            ]);
        } catch (subscribeError) {
            // Degradation = stale-until-next-lookup (polling invariant §7).
        }
    },
    beforeUnmount() {
        this._destroyed = true;
        if (this._loyaltySub && typeof this._loyaltySub.unsubscribe === 'function') {
            this._loyaltySub.unsubscribe();
        }
    },
    data() {
        return {
            loyaltyCode: '',
            pointsToRedeem: 0,
            loading: false,
            errorMessage: '',
            successMessage: '',
            customerBalance: null,
            // Server response payload kept around for the parent emit.
            lastResponse: null,
        };
    },
    computed: {
        rateHint() {
            const r = Number(this.rate);
            return Number.isFinite(r) && r > 0 ? r : 100;
        },
        previewDiscountEur() {
            const pts = Number(this.pointsToRedeem) || 0;
            if (pts <= 0 || this.rateHint <= 0) return 0;
            return Math.round((pts / this.rateHint) * 100) / 100;
        },
        canApply() {
            const pts = Number(this.pointsToRedeem) || 0;
            const code = (this.loyaltyCode || '').trim();
            if (!this.orderId) return false;
            if (pts <= 0) return false;
            if (code.length < 4) return false;
            return true;
        },
    },
    watch: {
        open(newVal) {
            if (newVal) {
                // Reset state on each fresh open.
                this.errorMessage = '';
                this.successMessage = '';
                this.loading = false;
                this.lastResponse = null;
                this.$nextTick(() => {
                    if (this.$refs.codeInput) {
                        try {
                            this.$refs.codeInput.focus();
                        } catch (e) {
                            // ignore — jsdom / non-DOM env
                        }
                    }
                });
            }
        },
    },
    methods: {
        emitClose() {
            this.$emit('close');
        },
        onBackdrop() {
            this.emitClose();
        },
        onCodeInput() {
            // Reset any prior balance display when the user edits the code —
            // it would be stale otherwise.
            if (this.customerBalance !== null) {
                this.customerBalance = null;
            }
            this.errorMessage = '';
        },
        /**
         * Build a per-attempt idempotency key so the server-side middleware
         * can de-duplicate flaky-tablet retries. The key includes the order
         * id, the cashier-typed code, and a coarse timestamp so a legitimate
         * second click 5 minutes later (same code/points) does not reuse a
         * cached response.
         */
        buildIdempotencyKey() {
            const ts = Math.floor(Date.now() / 60000); // minute bucket
            const code = (this.loyaltyCode || '').trim().toUpperCase();
            const pts = Number(this.pointsToRedeem) || 0;
            return `pos-redeem-${this.orderId}-${code}-${pts}-${ts}`;
        },
        translateErrorCode(code) {
            // Stable map : backend error_code → i18n key.
            const map = {
                INSUFFICIENT_BALANCE: 'pos.loyalty.redeem.errors.insufficient_balance',
                CUSTOMER_NOT_FOUND: 'pos.loyalty.redeem.errors.customer_not_found',
                ALREADY_REDEEMED: 'pos.loyalty.redeem.errors.already_redeemed',
                ORDER_ALREADY_FINALIZED: 'pos.loyalty.redeem.errors.order_already_finalized',
                POINTS_NOT_MULTIPLE: 'pos.loyalty.redeem.errors.points_not_multiple',
                DISCOUNT_EXCEEDS_SUBTOTAL: 'pos.loyalty.redeem.errors.discount_exceeds_subtotal',
            };
            const key = map[code];
            if (!key) {
                return this.$t('pos.loyalty.redeem.errors.generic');
            }
            return this.$t(key, { rate: this.rateHint });
        },
        async submitApply() {
            if (!this.canApply || this.loading) return;

            this.errorMessage = '';
            this.successMessage = '';
            this.loading = true;

            const payload = {
                points: Number(this.pointsToRedeem),
                loyalty_code: (this.loyaltyCode || '').trim(),
            };

            try {
                const url = `/admin/pos-order/${this.orderId}/redeem-loyalty`;
                const headers = {
                    'X-Idempotency-Key': this.buildIdempotencyKey(),
                };
                const res = await axios.post(url, payload, { headers });
                if (res && res.data && res.data.status === true) {
                    const data = res.data.data || {};
                    this.customerBalance = Number.isFinite(data.balance_after)
                        ? data.balance_after
                        : null;
                    const eur = Number(data.discount_eur) || 0;
                    this.successMessage = this.$t('pos.loyalty.redeem.success', {
                        amount: `${eur.toFixed(2)} €`,
                    });
                    this.lastResponse = data;
                    this.$emit('applied', data);
                } else {
                    this.errorMessage = this.$t('pos.loyalty.redeem.errors.generic');
                }
            } catch (err) {
                const status = err && err.response ? err.response.status : null;
                const code = err && err.response && err.response.data
                    ? err.response.data.code
                    : null;
                if (status === 403) {
                    this.errorMessage = this.$t('pos.loyalty.redeem.errors.permission_denied');
                } else if (code) {
                    this.errorMessage = this.translateErrorCode(code);
                } else {
                    this.errorMessage = this.$t('pos.loyalty.redeem.errors.generic');
                }
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<style scoped>
.pos-loyalty-redeem-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1050;
    padding: 1rem;
}

.pos-loyalty-redeem-dialog {
    background: #fff;
    width: 100%;
    max-width: 420px;
    border-radius: 0.5rem;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.pos-loyalty-redeem-dialog__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px dashed #e5e7eb;
    padding-bottom: 0.5rem;
}

.pos-loyalty-redeem-dialog__title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
}

.pos-loyalty-redeem-dialog__close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: #6b7280;
    padding: 0.25rem 0.5rem;
}

.pos-loyalty-redeem-dialog__error {
    background: #fee2e2;
    color: #991b1b;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}

.pos-loyalty-redeem-dialog__success {
    background: #d1fae5;
    color: #065f46;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}

.pos-loyalty-redeem-dialog__body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.pos-loyalty-redeem-dialog__stats {
    margin: 0 0 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #f3f4f6;
    border-radius: 0.375rem;
}

.pos-loyalty-redeem-dialog__stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.875rem;
}

.pos-loyalty-redeem-dialog__stat dt {
    margin: 0;
    color: #4b5563;
}

.pos-loyalty-redeem-dialog__stat dd {
    margin: 0;
    font-weight: 600;
    color: #111827;
}

.pos-loyalty-redeem-dialog__field-label {
    font-size: 0.75rem;
    color: #374151;
    font-weight: 600;
    margin-top: 0.25rem;
}

.pos-loyalty-redeem-dialog__input {
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    width: 100%;
    box-sizing: border-box;
}

.pos-loyalty-redeem-dialog__hint {
    margin: 0;
    color: #6b7280;
    font-size: 0.75rem;
}

.pos-loyalty-redeem-dialog__preview {
    margin: 0;
    color: #111827;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    background: #ecfdf5;
    border-radius: 0.375rem;
}

.pos-loyalty-redeem-dialog__actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    border-top: 1px dashed #e5e7eb;
    padding-top: 0.75rem;
}

.pos-loyalty-redeem-dialog__btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    border: none;
    font-size: 0.875rem;
    cursor: pointer;
    font-weight: 600;
}

.pos-loyalty-redeem-dialog__btn--ghost {
    background: #fff;
    color: #374151;
    border: 1px solid #d1d5db;
}

.pos-loyalty-redeem-dialog__btn--primary {
    background: #2563eb;
    color: #fff;
}

.pos-loyalty-redeem-dialog__btn--primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
