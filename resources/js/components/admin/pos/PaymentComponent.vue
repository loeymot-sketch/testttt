<template>
    <!--
      [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Refonte chrome modal paiement.
      - Hero "À encaisser" en display 48px monospace tabular (le moment de vérité)
      - Méthodes en segmented control V5 (cash | card)
      - Numpad partagé via PosV5Numpad (réutilisable futurs override prix admin)
      - CTA confirm V5 "primary-pay" avec ombre rouge soft
      - Logique métier (paiement, quote refresh, auth retry) inchangée.
    -->
    <LoadingComponent :props="loading" />

    <div id="orderpayment" class="modal pos-v4-payment-modal pos-v5-payment-modal">
        <div class="modal-dialog pos-v4-payment-dialog pos-v5-payment-dialog max-w-[480px] w-full">
            <div class="modal-header pos-v4-payment-header pos-v5-payment-header pb-3 border-b">
                <h3 class="capitalize font-extrabold text-[var(--pos-v5-text-h5)] text-[var(--pos-v5-ink)] m-0">
                    💳 {{ $t('label.order_payment') }}
                </h3>
                <button class="modal-close pos-v5-payment-close" @click="reset" :aria-label="$t('button.close')">
                    <span aria-hidden="true">✕</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Hero "À encaisser" — moment de vérité -->
                <div class="mb-4">
                    <div class="pos-v4-payment-total-card pos-v5-payment-total-card">
                        <p class="pos-v5-payment-total-label">{{ $t('label.total_amount') }}</p>
                        <p class="pos-v5-payment-total-value pos-v5-tabular">{{
                            currencyFormat(props.form.total,
                                setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                setting.site_currency_position)
                        }}</p>
                    </div>
                </div>

                <!-- Méthode de paiement -->
                <div class="mb-4">
                    <p class="pos-v5-payment-section-title">{{ $t('label.select_payment_method') }}</p>
                    <nav class="pos-v4-payment-methods pos-v5-payment-methods" role="tablist">
                        <button
                            data-tab="#cash"
                            type="button"
                            role="tab"
                            :aria-selected="props.form.pos_payment_method === posPaymentMethodEnum.CASH"
                            class="other-tabBtn pos-v4-payment-method pos-v5-payment-method"
                            :class="{ 'active is-active': props.form.pos_payment_method === posPaymentMethodEnum.CASH }"
                            @click="paymentMethod(posPaymentMethodEnum.CASH, 'cashInput')"
                        >
                            <span class="pos-v5-payment-method-icon" aria-hidden="true">💵</span>
                            <span class="pos-v5-payment-method-label">{{ $t("label.cash") }}</span>
                        </button>
                        <button
                            data-tab="#card"
                            type="button"
                            role="tab"
                            :aria-selected="props.form.pos_payment_method === posPaymentMethodEnum.CARD"
                            class="other-tabBtn pos-v4-payment-method pos-v5-payment-method"
                            :class="{ 'active is-active': props.form.pos_payment_method === posPaymentMethodEnum.CARD }"
                            @click="paymentMethod(posPaymentMethodEnum.CARD, 'cardInput')"
                        >
                            <span class="pos-v5-payment-method-icon" aria-hidden="true">💳</span>
                            <span class="pos-v5-payment-method-label">{{ $t("label.card") }} (TPE)</span>
                        </button>
                    </nav>
                </div>

                <!-- Cash input + change due -->
                <div id="cash" class="data-tab hidden"
                    :class="props.form.pos_payment_method === posPaymentMethodEnum.CASH ? 'active' : ''">
                    <div class="mb-3">
                        <label for="cashInput" class="pos-v5-payment-input-label">{{ $t("label.received_amount") }}</label>
                        <input
                            id="cashInput"
                            ref="cashInput"
                            type="text"
                            v-on:keypress="floatNumber($event)"
                            @input="onCashInput"
                            class="pos-v5-payment-input pos-v5-tabular"
                        />
                    </div>
                    <div v-if="cashChange > 0" class="pos-v5-payment-change" role="status" aria-live="polite">
                        <span class="pos-v5-payment-change-label">
                            <span aria-hidden="true">✨</span>
                            {{ $t("label.change_due") || 'Monnaie à rendre' }}
                        </span>
                        <span class="pos-v5-payment-change-value pos-v5-tabular">{{
                            currencyFormat(cashChange,
                                setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                setting.site_currency_position)
                        }}</span>
                    </div>
                </div>

                <!-- Card input -->
                <div id="card" class="data-tab hidden"
                    :class="props.form.pos_payment_method === posPaymentMethodEnum.CARD ? 'active' : ''">
                    <div class="mb-3">
                        <label for="cardInput" class="pos-v5-payment-input-label">{{ $t('label.enter_card_last_4_digits') }}</label>
                        <input
                            id="cardInput"
                            ref="cardInput"
                            type="number"
                            class="pos-v5-payment-input pos-v5-tabular"
                            required
                        />
                    </div>
                </div>

                <!--
                  Numpad V5 — composant partagé (PosV5Numpad).
                  Émissions @input(value) / @back / @clear sont raccordées aux
                  méthodes existantes numpadInput / numpadBack / numpadClear.
                -->
                <div
                    class="pos-v4-numpad pos-v5-payment-numpad-wrap mb-4"
                    v-if="props.form.pos_payment_method === posPaymentMethodEnum.CASH || props.form.pos_payment_method === posPaymentMethodEnum.CARD"
                >
                    <PosV5Numpad
                        aria-label="Pavé numérique"
                        @input="numpadInput"
                        @back="numpadBack"
                        @clear="numpadClear"
                    />
                </div>

                <!-- [AUDIT-P2] :disabled prevents a second click while the order is being submitted -->
                <button
                    @click="confirmOrder"
                    type="button"
                    :disabled="loading.isActive"
                    :aria-busy="loading.isActive"
                    class="pos-v4-confirm-button pos-v5-payment-confirm w-full"
                    data-testid="pos-payment-confirm"
                >
                    <span aria-hidden="true">✓</span>
                    {{ $t('button.confirm_and_print') }}
                </button>
            </div>
        </div>
    </div>

    <ReceiptComponent :order="order" />
</template>
<script>
import _ from "lodash";
import axios from "axios";
import LoadingComponent from "../components/LoadingComponent.vue";
import appService from "../../../services/appService";
import alertService from "../../../services/alertService";
import ReceiptComponent from "./ReceiptComponent.vue";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import sourceEnum from "../../../enums/modules/sourceEnum";
import isAdvanceOrderEnum from "../../../enums/modules/isAdvanceOrderEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
// [POS-9.1.12] Hardware bridge for the cash drawer (POS-GA-F-19).
import { openDrawer } from "../../../services/kioskHardware";
import { normalizeId } from "../../../helpers/posNormalizeIds";
import { normalizeCartForApi } from "../../../store/modules/posCart";
// [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Numpad partagé V5
import PosV5Numpad from "./v5/PosV5Numpad.vue";

export default {
    name: "PaymentComponent",
    components: { LoadingComponent, ReceiptComponent, PosV5Numpad },
    emits: ["payment-form:patch", "payment-form:reset", "order:confirmed"],
    props: {
        props: Object,
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            order: {},
            posPaymentMethodEnum: posPaymentMethodEnum,
            inputIdName: "cashInput",
            cashReceivedRaw: 0,
        };
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        cashChange: function () {
            const received = parseFloat(this.cashReceivedRaw) || 0;
            const total = parseFloat(this.props?.form?.total) || 0;
            return received > total ? Math.round((received - total) * 100) / 100 : 0;
        },
        paymentForm: function () {
            return this.props?.form || {};
        },
    },
    mounted() {
    },
    methods: {
        currencyFormat: function (amount, decimal, currency, position) {
            return appService.currencyFormat(amount, decimal, currency, position);
        },
        floatNumber(e) {
            return appService.floatNumber(e);
        },
        onCashInput(e) {
            this.cashReceivedRaw = e.target.value;
        },
        numpadInput(val) {
            const el = document.getElementById(this.inputIdName);
            if (el) { el.value += val; el.dispatchEvent(new Event('input')); }
        },
        numpadBack() {
            const el = document.getElementById(this.inputIdName);
            if (el) { el.value = el.value.slice(0, -1); el.dispatchEvent(new Event('input')); }
        },
        numpadClear() {
            const el = document.getElementById(this.inputIdName);
            if (el) { el.value = ''; el.dispatchEvent(new Event('input')); }
        },
        resetPaymentInputs: function () {
            Object.keys(this.$refs).forEach(refName => {
                if (this.$refs[refName].value !== undefined) {
                    this.$refs[refName].value = "";
                }
            });
            this.cashReceivedRaw = 0;
        },
        emitPaymentFormPatch: function (patch) {
            this.$emit("payment-form:patch", patch);
        },
        currentFormSnapshot: function (patch = {}) {
            return {
                ...this.paymentForm,
                ...patch,
            };
        },
        reset: function () {
            this.resetPaymentInputs();
            this.emitPaymentFormPatch({ pos_payment_note: "" });
            appService.modalHide('#orderpayment');
        },
        paymentMethod: function (method, Idname = "") {
            if (Idname) {
                this.inputIdName = Idname;
            }

            this.resetPaymentInputs();
            this.emitPaymentFormPatch({
                pos_payment_method: method,
                pos_payment_note: "",
                pos_received_amount: null,
            });
        },
        collectPaymentInputPatch: function (form) {
            const patch = {};
            if (form.pos_payment_method === this.posPaymentMethodEnum.CASH) {
                const cashInput = document.getElementById('cashInput');
                patch.pos_received_amount = cashInput && cashInput.value ? parseFloat(cashInput.value) : null;
            }

            patch.pos_payment_note =
                form.pos_payment_method === this.posPaymentMethodEnum.CARD && this.$refs.cardInput?.value
                    ? this.$refs.cardInput.value
                    : "";

            return patch;
        },
        normalizeItemsPayload: function (rawItems) {
            let itemsArray;
            if (typeof rawItems === "string") {
                try { itemsArray = JSON.parse(rawItems) || []; }
                catch (_e) { itemsArray = []; }
            } else if (Array.isArray(rawItems)) {
                itemsArray = rawItems;
            } else {
                itemsArray = [];
            }

            return JSON.stringify(normalizeCartForApi(itemsArray));
        },
        refreshQuote: function (form) {
            return axios.post('admin/pos/quote', form).then((res) => {
                const quote = res?.data?.data;
                if (!quote || quote.total_ttc === undefined || !quote.quote_token || !quote.signature) {
                    throw new Error('Réponse quote invalide.');
                }

                const quotePatch = {
                    quote_token: quote.quote_token,
                    quote_signature: quote.signature,
                    subtotal: quote.subtotal,
                    discount: quote.discount,
                    delivery_charge: quote.delivery_charge,
                    total: quote.total_ttc,
                };
                this.emitPaymentFormPatch(quotePatch);

                return this.currentFormSnapshot({
                    ...form,
                    ...quotePatch,
                });
            });
        },
        isUnauthorized: function (err) {
            return err?.response?.status === 401;
        },
        sessionExpiredError: function () {
            return new Error('Session expirée. Reconnectez-vous puis relancez le paiement.');
        },
        refreshPaymentAuth: function () {
            return this.$store.dispatch("authcheck").then((res) => {
                if (res?.data?.status === false) {
                    throw this.sessionExpiredError();
                }
                return res;
            }).catch(() => {
                throw this.sessionExpiredError();
            });
        },
        confirmOrderWithAuthRetry: async function () {
            try {
                return await this.runConfirmOrderAttempt();
            } catch (err) {
                if (!this.isUnauthorized(err)) {
                    throw err;
                }
            }

            await this.refreshPaymentAuth();

            try {
                return await this.runConfirmOrderAttempt();
            } catch (err) {
                if (this.isUnauthorized(err)) {
                    throw this.sessionExpiredError();
                }
                throw err;
            }
        },
        runConfirmOrderAttempt: async function () {
            const inputPatch = this.collectPaymentInputPatch(this.paymentForm);
            const accessResponse = await this.$store.dispatch("defaultAccess/show");
            const branchId = normalizeId(accessResponse.data.data.branch_id) || accessResponse.data.data.branch_id;
            const preparedForm = this.currentFormSnapshot({
                ...inputPatch,
                branch_id: branchId,
                items: this.normalizeItemsPayload(this.paymentForm.items),
            });

            this.emitPaymentFormPatch({
                ...inputPatch,
                branch_id: preparedForm.branch_id,
                items: preparedForm.items,
            });

            const quotedForm = await this.refreshQuote(preparedForm);
            const orderResponse = await this.$store.dispatch('posOrder/save', quotedForm);
            await this.handleOrderSuccess(orderResponse, quotedForm);
        },
        handleOrderSuccess: async function (orderResponse, submittedForm) {
            // [POS-9.1.12] Open the physical cash drawer the moment a CASH
            // payment is accepted. The hardware bridge is a no-op when no
            // bridge is exposed (web-only POS), so this is safe in dev.
            // Audit POS-GA-F-19.
            if (submittedForm.pos_payment_method === this.posPaymentMethodEnum.CASH) {
                try {
                    Promise.resolve(openDrawer()).catch(() => {});
                } catch (e) { /* defensive: never block the receipt path */ }
            }

            this.$emit("payment-form:reset");
            this.resetPaymentInputs();
            appService.modalHide('#orderpayment');

            await this.$store.dispatch('posCart/resetCart').catch(() => {});
            try {
                const res = await this.$store.dispatch('posOrder/show', orderResponse.data.data.id);
                this.order = res.data.data;
            } catch (error) {
                alertService.error(error?.response?.data?.message || error?.message || 'Erreur réseau. Veuillez réessayer.');
            }

            appService.modalShow('#receiptModal');
        },
        handlePaymentError: function (err) {
            if (err?._paymentTimeout) {
                alertService.error(err.message);
                return;
            }

            const errors = err?.response?.data?.errors;
            if (errors && typeof errors === 'object') {
                _.forEach(errors, (error) => {
                    alertService.error(error[0]);
                });
                return;
            }

            alertService.error(
                err?.response?.data?.message ||
                err?.message ||
                'Erreur réseau. Veuillez réessayer.'
            );
        },
        confirmOrder: async function () {
            // [Phase-3 / T17] Paiement POS : single-flight, erreurs API (catch → alertService),
            // normalisation items string→array (V14 B-6), libellé d’échec réseau côté catch.
            // [AUDIT-P2] Strict single-flight guard: if already submitting, bail out immediately.
            // The :disabled on the button is the first line of defense; this is the second.
            if (this.loading.isActive) return;
            this.loading.isActive = true;
            try {
                await this.confirmOrderWithAuthRetry();
                // [POS-V5 WAVE 3 2026-05-02] Notify parent for success-flash animation
                // (overlay vert 700ms après confirm). Logique métier inchangée.
                this.$emit("order:confirmed", this.order);
            } catch (err) {
                this.handlePaymentError(err);
            } finally {
                this.loading.isActive = false;
            }
        },
    },
};
</script>

<style scoped>
/* =============================================================================
   PaymentComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.3
   - Hero "À encaisser" 48px monospace tabular = moment de vérité du flow
   - Méthodes en segmented control V5
   - Numpad partagé via PosV5Numpad
   - CTA "Confirmer & Imprimer" full-width primary-pay
   ============================================================================= */

.pos-v5-payment-dialog,
.pos-v4-payment-dialog {
    border: 1px solid var(--pos-v5-border) !important;
    border-radius: var(--pos-v5-radius-xl) !important;
    overflow: hidden;
    box-shadow: var(--pos-v5-shadow-modal) !important;
    background: var(--pos-v5-bg-panel) !important;
    font-family: var(--pos-v5-font-sans);
}

/* Header — warm soft (pas de gradient sombre) */
.pos-v5-payment-header,
.pos-v4-payment-header {
    min-height: 64px;
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5) !important;
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%) !important;
    border-bottom: 1px solid var(--pos-v5-border) !important;
    color: var(--pos-v5-ink) !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
}

.pos-v5-payment-header h3 {
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    letter-spacing: var(--pos-v5-tracking-tight);
    color: var(--pos-v5-ink) !important;
    margin: 0;
}

.pos-v5-payment-close {
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: var(--pos-v5-radius-pill);
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    font-size: 14px;
    font-weight: var(--pos-v5-weight-bold);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-payment-close:hover {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger);
}

/* Hero "À encaisser" — moment de vérité, display 48px */
.pos-v5-payment-total-card,
.pos-v4-payment-total-card {
    display: block !important;
    width: 100%;
    height: auto !important;
    min-height: 110px !important;
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5) !important;
    border: 1px dashed var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-lg) !important;
    background: linear-gradient(135deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-receipt)) !important;
    text-align: center;
    box-shadow: var(--pos-v5-shadow-sm);
}
.pos-v5-payment-total-label {
    margin: 0 0 4px;
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
    line-height: 1;
}
.pos-v5-payment-total-value {
    margin: 0;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-display-lg);
    font-weight: var(--pos-v5-weight-black);
    color: var(--pos-v5-brand-red);
    line-height: 1.1;
    letter-spacing: var(--pos-v5-tracking-tight);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

/* Section title (Mode de paiement / Reçu / Carte) */
.pos-v5-payment-section-title {
    margin: 0 0 var(--pos-v5-space-2);
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
}

/* Méthodes paiement — segmented control 2 onglets */
.pos-v5-payment-methods,
.pos-v4-payment-methods {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: var(--pos-v5-space-2) !important;
    padding: 4px;
    background: var(--pos-v5-bg-subtle);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
}

.pos-v5-payment-method,
.pos-v4-payment-method {
    min-height: 64px !important;
    border-radius: var(--pos-v5-radius-sm) !important;
    border: 1px solid transparent !important;
    background: transparent !important;
    color: var(--pos-v5-ink-soft);
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
    display: flex !important;
    align-items: center;
    justify-content: center;
    gap: var(--pos-v5-space-2);
    flex-direction: row !important;
    padding: var(--pos-v5-space-2) var(--pos-v5-space-3) !important;
    cursor: pointer;
    box-shadow: none;
}

.pos-v5-payment-method:hover {
    background: var(--pos-v5-bg-panel) !important;
    color: var(--pos-v5-ink) !important;
    box-shadow: var(--pos-v5-shadow-sm);
}

.pos-v5-payment-method.is-active,
.pos-v5-payment-method.active,
.pos-v4-payment-method.active {
    background: var(--pos-v5-bg-panel) !important;
    border-color: var(--pos-v5-brand-red) !important;
    color: var(--pos-v5-brand-red) !important;
    box-shadow: var(--pos-v5-shadow-md), 0 0 0 3px var(--pos-v5-brand-red-soft);
    font-weight: var(--pos-v5-weight-extrabold);
}

.pos-v5-payment-method-icon {
    font-size: 22px;
    line-height: 1;
}

.pos-v5-payment-method-label {
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
}

/* Cash / card input */
.pos-v5-payment-input-label {
    display: block;
    margin-bottom: var(--pos-v5-space-2);
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
}
.pos-v5-payment-input {
    width: 100%;
    height: 56px;
    padding: 0 var(--pos-v5-space-4);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    background: var(--pos-v5-bg-panel);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h4);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    text-align: center;
    transition: border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-payment-input:focus {
    outline: 0;
    border-color: var(--pos-v5-brand-red);
    box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft);
}

/* Change due — success vibrant */
.pos-v5-payment-change {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    margin-top: var(--pos-v5-space-3);
    background: var(--pos-v5-success-soft);
    border: 1px solid var(--pos-v5-success);
    border-radius: var(--pos-v5-radius-md);
    box-shadow: var(--pos-v5-shadow-success);
}
.pos-v5-payment-change-label {
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-success-dark);
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-2);
}
.pos-v5-payment-change-value {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h4);
    font-weight: var(--pos-v5-weight-black);
    color: var(--pos-v5-success-dark);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

/* Numpad container */
.pos-v5-payment-numpad-wrap,
.pos-v4-numpad {
    background: transparent;
    padding: 0;
    border: 0;
    margin-bottom: var(--pos-v5-space-4);
}

/* Confirm CTA — primary-pay full width */
.pos-v5-payment-confirm,
.pos-v4-confirm-button {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    gap: var(--pos-v5-space-2);
    min-height: 56px;
    width: 100%;
    padding: 0 var(--pos-v5-space-5);
    background: linear-gradient(135deg, var(--pos-v5-brand-red), var(--pos-v5-brand-red-dark)) !important;
    color: var(--pos-v5-ink-on-red) !important;
    border: 0 !important;
    border-radius: var(--pos-v5-radius-lg) !important;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-extrabold);
    letter-spacing: var(--pos-v5-tracking-tight);
    cursor: pointer;
    box-shadow: var(--pos-v5-shadow-cta);
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
}
.pos-v5-payment-confirm:hover:not(:disabled),
.pos-v4-confirm-button:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 12px 28px rgba(232, 0, 28, 0.32);
}
.pos-v5-payment-confirm:disabled,
.pos-v4-confirm-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}
.pos-v5-payment-confirm:focus-visible,
.pos-v4-confirm-button:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

@media (prefers-reduced-motion: reduce) {
    .pos-v5-payment-confirm,
    .pos-v4-confirm-button { transition: none !important; }
    .pos-v5-payment-confirm:hover:not(:disabled),
    .pos-v4-confirm-button:hover:not(:disabled) { transform: none; }
}
</style>
