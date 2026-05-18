<template>
    <div class="db-card" data-testid="delivery-cash-form">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ formTitle }}</h3>
        </div>

        <div class="db-card-body">
            <div v-if="errorMessage" class="alert alert-danger" data-testid="delivery-cash-form-error">
                {{ errorMessage }}
            </div>

            <!-- MODE 1 — OPEN form -->
            <form
                v-if="mode === 'open'"
                @submit.prevent="submitOpen"
                data-testid="delivery-cash-form-open"
            >
                <div class="mb-3">
                    <label for="deliveryBoyId" class="db-field-title">
                        {{ $t('label.delivery_boy') }} <span aria-hidden="true">*</span>
                    </label>
                    <input
                        id="deliveryBoyId"
                        v-model.number="form.delivery_boy_id"
                        type="number"
                        min="1"
                        class="db-field-control"
                        data-testid="delivery-cash-form-livreur-input"
                        required
                    />
                </div>
                <div class="mb-3">
                    <label for="openingAmount" class="db-field-title">
                        {{ $t('label.cash_session_opening_amount') }} <span aria-hidden="true">*</span>
                    </label>
                    <input
                        id="openingAmount"
                        v-model.number="form.opening_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="db-field-control"
                        data-testid="delivery-cash-form-opening-input"
                        required
                    />
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" class="db-btn-outline" @click="$emit('cancel')">
                        {{ $t('button.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="db-btn bg-primary text-white"
                        :disabled="submitting"
                        data-testid="delivery-cash-form-open-submit"
                    >
                        {{ submitting ? '...' : $t('label.cash_session_open') }}
                    </button>
                </div>
            </form>

            <!-- MODE 2 — CLOSE form -->
            <form
                v-else-if="mode === 'close'"
                @submit.prevent="submitClose"
                data-testid="delivery-cash-form-close"
            >
                <p class="text-sm text-gray-600 mb-3">
                    {{ $t('label.delivery_cash_close_intro') }}
                </p>
                <div class="mb-3">
                    <label for="closingAmount" class="db-field-title">
                        {{ $t('label.cash_session_closing_amount') }} <span aria-hidden="true">*</span>
                    </label>
                    <input
                        id="closingAmount"
                        v-model.number="form.closing_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="db-field-control"
                        data-testid="delivery-cash-form-closing-input"
                        required
                    />
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" class="db-btn-outline" @click="$emit('cancel')">
                        {{ $t('button.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="db-btn bg-warning text-white"
                        :disabled="submitting"
                        data-testid="delivery-cash-form-close-submit"
                    >
                        {{ submitting ? '...' : $t('label.cash_session_close') }}
                    </button>
                </div>
            </form>

            <!-- MODE 3 — RECONCILE form -->
            <form
                v-else-if="mode === 'reconcile'"
                @submit.prevent="submitReconcile"
                data-testid="delivery-cash-form-reconcile"
            >
                <p class="text-sm text-gray-600 mb-3">
                    {{ $t('label.delivery_cash_reconcile_intro') }}
                </p>
                <div class="mb-3">
                    <label for="varianceReason" class="db-field-title">
                        {{ $t('label.cash_session_variance_reason') }}
                    </label>
                    <textarea
                        id="varianceReason"
                        v-model="form.variance_reason"
                        rows="3"
                        class="db-field-control"
                        data-testid="delivery-cash-form-reason-input"
                    />
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" class="db-btn-outline" @click="$emit('cancel')">
                        {{ $t('button.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="db-btn bg-success text-white"
                        :disabled="submitting"
                        data-testid="delivery-cash-form-reconcile-submit"
                    >
                        {{ submitting ? '...' : $t('label.cash_session_reconcile') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] Delivery boy cash session — open / close / reconcile form.
 *
 * Single component, 3 modes (open, close, reconcile). Parent passes `mode` +
 * for non-open modes the `sessionId`. On success emits `submitted` with the
 * updated session. On error emits `error` with the message (also shown
 * inline via errorMessage).
 *
 * No store / Vuex coupling — direct axios so this stays a self-contained
 * micro-feature usable from the DeliveryBoyShow tabs.
 */
import axios from 'axios';

export default {
    name: 'DeliveryBoyCashSessionFormComponent',
    props: {
        mode: {
            type: String,
            required: true,
            validator: (v) => ['open', 'close', 'reconcile'].includes(v),
        },
        sessionId: { type: [Number, String], default: null },
        defaultDeliveryBoyId: { type: [Number, String], default: null },
    },
    emits: ['submitted', 'cancel', 'error'],
    data() {
        return {
            submitting: false,
            errorMessage: '',
            form: {
                delivery_boy_id: this.defaultDeliveryBoyId
                    ? Number(this.defaultDeliveryBoyId)
                    : null,
                opening_amount: 0,
                closing_amount: 0,
                variance_reason: '',
            },
        };
    },
    computed: {
        formTitle() {
            switch (this.mode) {
                case 'open':
                    return this.$t('label.delivery_cash_open_form_title');
                case 'close':
                    return this.$t('label.delivery_cash_close_form_title');
                case 'reconcile':
                    return this.$t('label.delivery_cash_reconcile_form_title');
                default:
                    return '';
            }
        },
    },
    methods: {
        async submitOpen() {
            this.submitting = true;
            this.errorMessage = '';
            try {
                const res = await axios.post(
                    '/api/admin/delivery-boy/cash-sessions/open',
                    {
                        delivery_boy_id: Number(this.form.delivery_boy_id),
                        opening_amount: Number(this.form.opening_amount),
                    },
                );
                this.$emit('submitted', res.data.data);
            } catch (err) {
                this.errorMessage = this._extractError(err);
                this.$emit('error', this.errorMessage);
            } finally {
                this.submitting = false;
            }
        },
        async submitClose() {
            if (!this.sessionId) {
                this.errorMessage = this.$t('label.delivery_cash_missing_session');
                return;
            }
            this.submitting = true;
            this.errorMessage = '';
            try {
                const res = await axios.post(
                    `/api/admin/delivery-boy/cash-sessions/${this.sessionId}/close`,
                    {
                        closing_amount: Number(this.form.closing_amount),
                    },
                );
                this.$emit('submitted', res.data.data);
            } catch (err) {
                this.errorMessage = this._extractError(err);
                this.$emit('error', this.errorMessage);
            } finally {
                this.submitting = false;
            }
        },
        async submitReconcile() {
            if (!this.sessionId) {
                this.errorMessage = this.$t('label.delivery_cash_missing_session');
                return;
            }
            this.submitting = true;
            this.errorMessage = '';
            try {
                const res = await axios.post(
                    `/api/admin/delivery-boy/cash-sessions/${this.sessionId}/reconcile`,
                    {
                        variance_reason: this.form.variance_reason
                            ? this.form.variance_reason.trim()
                            : null,
                    },
                );
                this.$emit('submitted', res.data.data);
            } catch (err) {
                this.errorMessage = this._extractError(err);
                this.$emit('error', this.errorMessage);
            } finally {
                this.submitting = false;
            }
        },
        _extractError(err) {
            const fromResponse = err && err.response && err.response.data && err.response.data.message;
            if (fromResponse) return fromResponse;
            if (err && err.message) return err.message;
            return this.$t('label.delivery_cash_form_generic_error');
        },
    },
};
</script>
