<template>
    <LoadingComponent :props="loading" />

    <div id="orderpayment" class="modal pos-v4-payment-modal">
        <div class="modal-dialog pos-v4-payment-dialog max-w-[428px] w-full">
            <div class="modal-header pos-v4-payment-header pb-3 border-b border-[#D9DBE9]">
                <h3 class="capitalize font-medium">{{ $t('label.order_payment') }}</h3>
                <button class="modal-close fa-regular fa-circle-xmark" @click="reset"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <div
                        class="pos-v4-payment-total-card flex justify-between items-center h-12 w-full rounded-lg py-1.5 px-2 placeholder:text-[10px] placeholder:text-[#6E7191] bg-[#F7F7FC]">
                        <span class="text-sm font-normal text-[#2E2F38]">{{ $t('label.total_amount')
                            }}</span>
                        <span class="text-primary text-base font-medium">{{
                            currencyFormat(props.form.total,
                                setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                setting.site_currency_position)
                        }}</span>
                    </div>
                </div>
                <div class="mb-4">
                    <h3 class="capitalize font-medium mb-2">{{ $t('label.select_payment_method')
                        }}</h3>
                    <nav class="pos-v4-payment-methods flex flex-wrap gap-4 active-group">
                        <button data-tab="#cash" type="button"
                            class="other-tabBtn pos-v4-payment-method w-fit flex flex-col items-center gap-2 rounded-lg py-3 px-7 border bg-[#F7F7FC] border-[#F7F7FC] flex-1"
                            :class="props.form.pos_payment_method === posPaymentMethodEnum.CASH ? 'active' : ''"
                            @click="paymentMethod(posPaymentMethodEnum.CASH, 'cashInput')">
                            <i class="lab lab-cash lab-font-size-24"></i>
                            <span class="text-xs font-normal leading-none text-heading">{{ $t("label.cash") }}</span>
                        </button>
                        <button data-tab="#card" type="button"
                            class="other-tabBtn pos-v4-payment-method w-fit flex flex-col items-center gap-2 rounded-lg py-3 px-7 border bg-[#F7F7FC] border-[#F7F7FC] flex-1"
                            :class="props.form.pos_payment_method === posPaymentMethodEnum.CARD ? 'active' : ''"
                            @click="paymentMethod(posPaymentMethodEnum.CARD, 'cardInput')">
                            <i class="lab lab-card-2 lab-font-size-24"></i>
                            <span class="text-xs font-normal leading-none text-heading">{{ $t("label.card") }} (TPE)</span>
                        </button>
                    </nav>
                </div>
                <div id="cash" class="data-tab hidden"
                    :class="props.form.pos_payment_method === posPaymentMethodEnum.CASH ? 'active' : ''">
                    <div class="mb-4">
                        <h3 class="capitalize font-medium mb-2">{{ $t("label.received_amount") }}</h3>
                        <input id="cashInput" ref="cashInput" type="text" v-on:keypress="floatNumber($event)"
                            @input="onCashInput"
                            class="h-12 w-full rounded-lg border py-1.5 px-4 border-[#D9DBE9] text-black">
                    </div>
                    <div v-if="cashChange > 0"
                        class="mb-4 flex justify-between items-center h-12 w-full rounded-lg py-1.5 px-3 bg-green-50 border border-green-300">
                        <span class="text-sm font-semibold text-green-700">{{ $t("label.change_due") || 'Monnaie à rendre' }}</span>
                        <span class="text-green-700 text-lg font-bold">{{
                            currencyFormat(cashChange,
                                setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                setting.site_currency_position)
                        }}</span>
                    </div>
                </div>
                <div id="card" class="data-tab hidden"
                    :class="props.form.pos_payment_method === posPaymentMethodEnum.CARD ? 'active' : ''">
                    <div class="mb-4">
                        <h3 class="capitalize font-medium mb-2">{{ $t('label.enter_card_last_4_digits') }}</h3>
                        <input id="cardInput" type="number" ref="cardInput"
                            class="h-12 w-full rounded-lg border py-1.5 px-4 border-[#D9DBE9] text-black" required>
                    </div>
                </div>


                <div class="pos-v4-numpad grid grid-cols-4 gap-x-4 gap-y-3.5 mb-6"
                    v-if="props.form.pos_payment_method === posPaymentMethodEnum.CASH || props.form.pos_payment_method === posPaymentMethodEnum.CARD">
                    <button @click="numpadInput('1')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">1</button>
                    <button @click="numpadInput('2')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">2</button>
                    <button @click="numpadInput('3')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">3</button>
                    <button @click="numpadBack()" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39] row-span-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M16.9997 3.75H10.2797C8.86969 3.75 7.52969 4.34 6.57969 5.39L3.04969 9.27C1.63969 10.82 1.63969 13.18 3.04969 14.73L6.57969 18.61C7.52969 19.65 8.86969 20.25 10.2797 20.25H16.9997C19.7597 20.25 21.9997 18.01 21.9997 15.25V8.75C21.9997 5.99 19.7597 3.75 16.9997 3.75ZM16.5297 13.94C16.8197 14.23 16.8197 14.71 16.5297 15C16.3797 15.15 16.1897 15.22 15.9997 15.22C15.8097 15.22 15.6197 15.15 15.4697 15L13.5297 13.06L11.5897 15C11.4397 15.15 11.2497 15.22 11.0597 15.22C10.8697 15.22 10.6797 15.15 10.5297 15C10.2397 14.71 10.2397 14.23 10.5297 13.94L12.4697 12L10.5297 10.06C10.2397 9.77 10.2397 9.29 10.5297 9C10.8197 8.71 11.2997 8.71 11.5897 9L13.5297 10.94L15.4697 9C15.7597 8.71 16.2397 8.71 16.5297 9C16.8197 9.29 16.8197 9.77 16.5297 10.06L14.5897 12L16.5297 13.94Z"
                                fill="#1F1F39" />
                        </svg>
                    </button>
                    <button @click="numpadInput('4')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">4</button>
                    <button @click="numpadInput('5')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">5</button>
                    <button @click="numpadInput('6')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">6</button>
                    <button @click="numpadInput('7')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">7</button>
                    <button @click="numpadInput('8')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">8</button>
                    <button @click="numpadInput('9')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">9</button>
                    <button @click="numpadClear()" type="reset" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39] row-span-2">
                        {{ $t('button.clear') }}
                    </button>
                    <button @click="numpadInput('00')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">00</button>
                    <button @click="numpadInput('0')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">0</button>
                    <button @click="numpadInput('.')" class="num pos-v4-numpad-key bg-[#F7F7FC] rounded-lg p-2.5 flex items-center justify-center text-base font-medium text-[#1F1F39]">.</button>
                </div>
                <!-- [AUDIT-P2] :disabled prevents a second click while the order is being submitted -->
                <button @click="confirmOrder" type="button" :disabled="loading.isActive"
                    class="pos-v4-confirm-button rounded-3xl text-base py-2 px-3 font-medium w-full text-white bg-primary disabled:opacity-50 disabled:cursor-not-allowed">{{
                        $t('button.confirm_and_print') }}</button>
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

export default {
    name: "PaymentComponent",
    components: { LoadingComponent, ReceiptComponent },
    emits: ["payment-form:patch", "payment-form:reset"],
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
.pos-v4-payment-dialog {
  border: 0;
  border-radius: 22px;
  overflow: hidden;
  box-shadow: 0 30px 80px rgba(20, 24, 33, 0.28);
}

.pos-v4-payment-header {
  min-height: 70px;
  padding: 18px 20px !important;
  background: linear-gradient(135deg, #111827, #2b1118 62%, #e8001c 130%);
  color: #fff;
  border-bottom: 0 !important;
}

.pos-v4-payment-header h3 {
  font-size: 19px;
  font-weight: 900;
  letter-spacing: 0;
}

.pos-v4-payment-header .modal-close {
  color: rgba(255, 255, 255, 0.84);
}

.pos-v4-payment-total-card {
  min-height: 72px !important;
  padding: 12px 14px !important;
  border: 1px solid rgba(232, 0, 28, 0.14);
  border-radius: 18px !important;
  background: linear-gradient(135deg, #fff5f6, #fff) !important;
}

.pos-v4-payment-total-card span:last-child {
  color: #e8001c !important;
  font-size: 26px !important;
  font-weight: 900 !important;
}

.pos-v4-payment-method {
  min-height: 88px;
  border-radius: 18px !important;
  border: 1px solid rgba(20, 24, 33, 0.08) !important;
  background: #f6f8fb !important;
  transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.pos-v4-payment-method:hover {
  transform: translateY(-1px);
  box-shadow: 0 14px 30px rgba(20, 24, 33, 0.1);
}

.pos-v4-payment-method.active {
  border-color: rgba(232, 0, 28, 0.36) !important;
  background: #fff5f6 !important;
  color: #e8001c;
  box-shadow: 0 16px 34px rgba(232, 0, 28, 0.13);
}

.pos-v4-numpad {
  gap: 10px !important;
}

.pos-v4-numpad-key {
  min-height: 48px;
  border-radius: 16px !important;
  border: 1px solid rgba(20, 24, 33, 0.08);
  background: #f6f8fb !important;
  font-weight: 900 !important;
  box-shadow: inset 0 -1px 0 rgba(20, 24, 33, 0.04);
}

.pos-v4-numpad-key:active {
  transform: translateY(1px);
}

.pos-v4-confirm-button {
  min-height: 52px;
  border-radius: 16px !important;
  background: #12965d !important;
  font-weight: 900 !important;
  box-shadow: 0 16px 34px rgba(18, 150, 93, 0.24);
}
</style>
