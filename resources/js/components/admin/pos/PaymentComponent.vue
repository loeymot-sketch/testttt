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
                    <nav class="pos-v4-payment-methods pos-v5-payment-methods pos-v5-payment-methods--3col" role="tablist">
                        <button
                            data-tab="#cash"
                            type="button"
                            role="tab"
                            :aria-selected="paymentMode === 'cash'"
                            class="other-tabBtn pos-v4-payment-method pos-v5-payment-method"
                            :class="{ 'active is-active': paymentMode === 'cash' }"
                            data-testid="pos-payment-mode-cash"
                            @click="setPaymentMode('cash')"
                        >
                            <span class="pos-v5-payment-method-icon" aria-hidden="true">💵</span>
                            <span class="pos-v5-payment-method-label">{{ $t("label.cash") }}</span>
                        </button>
                        <button
                            data-tab="#card"
                            type="button"
                            role="tab"
                            :aria-selected="paymentMode === 'card'"
                            class="other-tabBtn pos-v4-payment-method pos-v5-payment-method"
                            :class="{ 'active is-active': paymentMode === 'card' }"
                            data-testid="pos-payment-mode-card"
                            @click="setPaymentMode('card')"
                        >
                            <span class="pos-v5-payment-method-icon" aria-hidden="true">💳</span>
                            <span class="pos-v5-payment-method-label">{{ $t("label.card") }} (TPE)</span>
                        </button>
                        <button
                            data-tab="#multi"
                            type="button"
                            role="tab"
                            :aria-selected="paymentMode === 'multi'"
                            class="other-tabBtn pos-v4-payment-method pos-v5-payment-method"
                            :class="{ 'active is-active': paymentMode === 'multi' }"
                            data-testid="pos-payment-mode-multi"
                            @click="setPaymentMode('multi')"
                        >
                            <span class="pos-v5-payment-method-icon" aria-hidden="true">🔀</span>
                            <span class="pos-v5-payment-method-label">{{ $t('label.split_payment') || 'Multi-paiement' }}</span>
                        </button>
                    </nav>
                </div>

                <!-- Cash input + change due -->
                <div id="cash" class="data-tab hidden"
                    :class="paymentMode === 'cash' ? 'active' : ''">
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
                    :class="paymentMode === 'card' ? 'active' : ''">
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
                    v-if="paymentMode === 'cash' || paymentMode === 'card'"
                >
                    <PosV5Numpad
                        aria-label="Pavé numérique"
                        @input="numpadInput"
                        @back="numpadBack"
                        @clear="numpadClear"
                    />
                </div>

                <!--
                  Multi-paiement (split) — CV1-POS-SPLIT-PAYMENT-001
                  Tranches locales (data.tranches) ; le payload est construit
                  uniquement au submit (submitMulti) — n'altère PAS props.form
                  pour les modes cash/card historiques.
                -->
                <div
                    v-if="paymentMode === 'multi'"
                    id="multi"
                    class="pos-v5-split-block"
                    data-testid="pos-payment-split-block"
                >
                    <div class="pos-v5-split-summary" role="group" :aria-label="$t('label.split_summary') || 'Résumé multi-paiement'">
                        <div class="pos-v5-split-summary__row">
                            <span class="pos-v5-split-summary__label">{{ $t('label.total_covered') || 'Couvert' }}</span>
                            <span class="pos-v5-split-summary__value pos-v5-tabular" data-testid="pos-payment-total-covered">
                                {{ currencyFormat(totalCoveredEur,
                                    setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                    setting.site_currency_position) }}
                            </span>
                        </div>
                        <div
                            class="pos-v5-split-summary__row pos-v5-split-summary__row--remaining"
                            :class="{ 'pos-v5-split-summary__row--ok': remainingDueEur <= 0.01 }"
                            role="status"
                            aria-live="polite"
                            data-testid="pos-payment-remaining-due-row"
                        >
                            <span class="pos-v5-split-summary__label">{{ $t('label.remaining_due') || 'Reste dû' }}</span>
                            <span
                                class="pos-v5-split-summary__value pos-v5-tabular"
                                data-testid="pos-payment-remaining-due"
                            >
                                {{ currencyFormat(remainingDueEur,
                                    setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                    setting.site_currency_position) }}
                            </span>
                        </div>
                        <div
                            v-if="totalChangeEur > 0"
                            class="pos-v5-split-summary__row pos-v5-split-summary__row--change"
                            role="status"
                            aria-live="polite"
                        >
                            <span class="pos-v5-split-summary__label">
                                <span aria-hidden="true">✨</span>
                                {{ $t('label.change_due') }}
                            </span>
                            <span class="pos-v5-split-summary__value pos-v5-tabular" data-testid="pos-payment-total-change">
                                {{ currencyFormat(totalChangeEur,
                                    setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                    setting.site_currency_position) }}
                            </span>
                        </div>
                    </div>

                    <!-- Diviser entre N personnes -->
                    <div class="pos-v5-split-divider">
                        <label for="splitCountInput" class="pos-v5-split-divider__label">
                            {{ $t('label.split_among_n') || 'Diviser entre N personnes' }}
                        </label>
                        <div class="pos-v5-split-divider__row">
                            <input
                                id="splitCountInput"
                                type="number"
                                min="2"
                                max="20"
                                step="1"
                                v-model.number="splitCount"
                                class="pos-v5-split-divider__input pos-v5-tabular"
                                :aria-label="$t('label.split_among_n') || 'Diviser entre N personnes'"
                                data-testid="pos-payment-split-count"
                            />
                            <button
                                type="button"
                                class="pos-v5-split-divider__btn"
                                :disabled="!canSplitEqually"
                                @click="splitEquallyHandler"
                                data-testid="pos-payment-split-equal"
                            >
                                {{ $t('button.split_equally') || 'Diviser à parts égales' }}
                            </button>
                        </div>
                        <!-- [iter12 2026-05-09] Bidirectional split helpers -->
                        <div class="pos-v5-split-divider__row" v-if="tranches.length >= 2">
                            <button
                                type="button"
                                class="pos-v5-split-divider__btn"
                                @click="autoBalanceFromIndex(0)"
                                data-testid="pos-payment-auto-balance"
                                :title="$t('label.auto_balance_help') || 'Le reste s’équilibre automatiquement sur la 2ème tranche'"
                            >
                                {{ $t('button.auto_balance') || 'Équilibrer le reste' }}
                            </button>
                            <button
                                type="button"
                                class="pos-v5-split-divider__btn"
                                @click="suggestCashTendered"
                                data-testid="pos-payment-suggest-tendered"
                                :title="$t('label.suggest_tendered_help') || 'Arrondit les rendus monnaie au 5€ supérieur'"
                            >
                                {{ $t('button.suggest_tendered') || 'Suggérer les rendus monnaie' }}
                            </button>
                        </div>
                    </div>

                    <!-- Liste des tranches -->
                    <div
                        class="pos-v5-split-tranches"
                        role="list"
                        :aria-label="$t('label.split_tranches') || 'Tranches de paiement'"
                    >
                        <PosV5TrancheRow
                            v-for="(tr, idx) in tranches"
                            :key="tr.id"
                            :tranche="tr"
                            :index="idx"
                            role="listitem"
                            @update="(patch) => updateTranche(idx, patch)"
                            @remove="removeTranche(idx)"
                        />
                        <p v-if="tranches.length === 0" class="pos-v5-split-empty">
                            {{ $t('pos.split_empty_hint') || 'Ajoutez une tranche pour commencer.' }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="pos-v5-split-add"
                        @click="addTranche()"
                        data-testid="pos-payment-tranche-add"
                    >
                        <span aria-hidden="true">+</span>
                        {{ $t('button.add_tranche') || 'Ajouter une tranche' }}
                    </button>
                </div>

                <!-- [AUDIT-P2] :disabled prevents a second click while the order is being submitted -->
                <button
                    @click="confirmOrder"
                    type="button"
                    :disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti)"
                    :aria-busy="loading.isActive"
                    :aria-disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti)"
                    class="pos-v4-confirm-button pos-v5-payment-confirm w-full"
                    data-testid="pos-payment-confirm"
                >
                    <span aria-hidden="true">✓</span>
                    {{ $t('button.confirm_and_print') }}
                </button>
            </div>
        </div>
    </div>

    <!-- [iter15-mega-fix B-009 round-7 2026-05-10 — addendum] :clear-cart-on-close="true"
         opts THIS receipt instance into the deferred posCart/resetCart dispatch fired
         when the cashier dismisses the modal. Re-print receipts in
         PosOrdersTrackerComponent omit the prop (default false) and never destroy
         a parallel cart in progress. -->
    <ReceiptComponent ref="receiptRoot" :order="order" :clear-cart-on-close="true" />
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
// [CV1-POS-SPLIT-PAYMENT-001] Multi-tender (split) UI atom + helpers
import PosV5TrancheRow from "./v5/PosV5TrancheRow.vue";
import {
    toCents as splitToCents,
    fromCents as splitFromCents,
    sumCoveredCents,
    remainingCents as splitRemainingCents,
    canConfirm as splitCanConfirm,
    splitEqually as splitEquallyHelper,
    serializeTranches,
    totalChangeCents,
    makeTrancheId,
    autoBalanceTranches,
    suggestTenderedForCashTranches,
} from "../../../helpers/posSplitPayment";

export default {
    name: "PaymentComponent",
    components: { LoadingComponent, ReceiptComponent, PosV5Numpad, PosV5TrancheRow },
    /**
     * [RED-R1 Q-X-1] Authoritative list of events PaymentComponent is allowed to emit.
     * MODIFIER UNIQUEMENT VIA REVIEW HUMAINE — toute addition/suppression doit :
     *   1. apparaître dans la description du PR
     *   2. être justifiée par un FK-ID + plan documenté
     *   3. mettre à jour la sentinel `paymentComponentEmitsJsdocList.spec.js`
     * Events autorisés (exhaustif) :
     *   - "payment-form:patch"   → patch du form parent (delta, jamais mutation directe)
     *   - "payment-form:reset"   → demande au parent de réinitialiser le form
     *   - "order:confirmed"      → notifie le parent qu'une commande a été confirmée
     */
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
            // [CV1-POS-SPLIT-PAYMENT-001] Local mode for the segmented control.
            // 'cash' / 'card' map 1:1 to existing pos_payment_method paths (untouched).
            // 'multi' is fully local: tranches[] are not bubbled into props.form.
            paymentMode: 'cash',
            tranches: [],
            splitCount: 2,
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
        // [CV1-POS-SPLIT-PAYMENT-001] Split-payment computed surface.
        totalCents: function () {
            return splitToCents(this.props?.form?.total || 0);
        },
        coveredCents: function () {
            return sumCoveredCents(this.tranches);
        },
        totalCoveredEur: function () {
            return splitFromCents(this.coveredCents);
        },
        remainingCentsValue: function () {
            return splitRemainingCents(this.totalCents, this.tranches);
        },
        remainingDueEur: function () {
            const cents = this.remainingCentsValue;
            return cents > 0 ? splitFromCents(cents) : 0;
        },
        totalChangeEur: function () {
            return splitFromCents(totalChangeCents(this.tranches));
        },
        canConfirmMulti: function () {
            return splitCanConfirm(this.totalCents, this.tranches);
        },
        canSplitEqually: function () {
            return Number(this.splitCount) >= 2 && this.totalCents > 0;
        },
    },
    mounted() {
        // [CV1-POS-SPLIT-PAYMENT-001] Sync local paymentMode with parent's
        // pos_payment_method on mount so a re-opened modal with CARD-default
        // doesn't show CASH-tab as aria-selected. Multi mode is local-only,
        // never inherited (single-tender re-open ⇒ never multi).
        this.syncPaymentModeFromForm();

        // [iter12 2026-05-09] Auto-refresh quote every 60s while modal is
        // open. Quote TTL bumped to 300s server-side (config quote.ttl_seconds)
        // but cashier multi-paiement entry can still exceed it. Refresh keeps
        // signature alive so confirm never sees "Order quote expired".
        // 60000ms × 4 refreshes = 240s coverage; well under 300s TTL.
        this._quoteRefreshTimer = setInterval(() => {
            try {
                if (this.paymentMode !== 'multi') return;
                if (this.loading?.isActive) return;
                if (typeof this.refreshQuote === 'function') {
                    this.refreshQuote(this.props?.form ?? {}).catch(() => {});
                }
            } catch (_e) {}
        }, 60000);
    },
    beforeUnmount() {
        // [iter12 2026-05-09] Clear quote-refresh timer to avoid leak +
        // ghost POSTs after modal closes.
        if (this._quoteRefreshTimer) {
            clearInterval(this._quoteRefreshTimer);
            this._quoteRefreshTimer = null;
        }
    },
    watch: {
        // [CV1-POS-SPLIT-PAYMENT-001] String-path watcher (Vue 3 supports it for nested
        // reactive props). On external mutation of pos_payment_method (parent reset /
        // restore-from-localStorage), re-sync the segmented control — unless the
        // cashier is in 'multi' mode (multi is purely local; parent doesn't track it).
        'props.form.pos_payment_method': {
            handler() {
                if (this.paymentMode !== 'multi') {
                    this.syncPaymentModeFromForm();
                }
            },
        },
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
            // [CV1-POS-SPLIT-PAYMENT-001] Reset multi-tender local state on modal close.
            this.tranches = [];
            this.splitCount = 2;
            this.paymentMode = 'cash';
            this.emitPaymentFormPatch({ pos_payment_note: "" });
            // [iter15-BUG-SESSION-EXPIRED 2026-05-10] Mirror beforeUnmount cleanup:
            // clear zombie quote-refresh timer when cashier closes payment popup
            // (e.g. switch CARD→CASH). Without this, refreshQuote() keeps firing
            // every 60s; once the quote TTL elapses the POST hits 401 and the
            // global axios handler boots the cashier to /login ("session expirée").
            if (this._quoteRefreshTimer) {
                clearInterval(this._quoteRefreshTimer);
                this._quoteRefreshTimer = null;
            }
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
        // [CV1-POS-SPLIT-PAYMENT-001] Reflect parent's pos_payment_method into the
        // local segmented control. CARD → 'card', anything else → 'cash'. Never
        // auto-promotes to 'multi' (multi is a deliberate user choice).
        syncPaymentModeFromForm: function () {
            const m = this.props?.form?.pos_payment_method;
            if (m === this.posPaymentMethodEnum.CARD) {
                this.paymentMode = 'card';
                this.inputIdName = 'cardInput';
            } else {
                this.paymentMode = 'cash';
                this.inputIdName = 'cashInput';
            }
        },
        // [CV1-POS-SPLIT-PAYMENT-001] Local-only mode toggle.
        // For 'cash'/'card' we still call paymentMethod() to keep props.form in sync
        // (zero behavior change for the existing single-tender flow).
        // For 'multi' we leave props.form untouched and submit() builds payload directly.
        setPaymentMode: function (mode) {
            if (mode !== 'cash' && mode !== 'card' && mode !== 'multi') return;
            this.paymentMode = mode;
            if (mode === 'cash') {
                this.paymentMethod(this.posPaymentMethodEnum.CASH, 'cashInput');
            } else if (mode === 'card') {
                this.paymentMethod(this.posPaymentMethodEnum.CARD, 'cardInput');
            }
            // Multi: do not mutate props.form; tranches[] is the source of truth.
        },
        addTranche: function (mode = null, amount = null, tendered = null) {
            const total = this.totalCents;
            const remaining = Math.max(0, splitRemainingCents(total, this.tranches));
            // If remaining is 0 (or near 0), pre-fill 1 cent so the cashier doesn't
            // see an instant "amount required" error — they'll edit immediately.
            const defaultAmountCents = remaining > 0 ? remaining : 1;
            const defaultAmount = amount !== null ? Number(amount) : splitFromCents(defaultAmountCents);
            const defaultMode = mode !== null ? Number(mode) : this.posPaymentMethodEnum.CASH;
            this.tranches.push({
                id: makeTrancheId(this.tranches.length),
                mode: defaultMode,
                amount: defaultAmount,
                tendered: tendered !== null ? Number(tendered) : null,
                note: null,
            });
        },
        removeTranche: function (index) {
            if (index < 0 || index >= this.tranches.length) return;
            this.tranches.splice(index, 1);
        },
        updateTranche: function (index, patch) {
            if (index < 0 || index >= this.tranches.length) return;
            const current = this.tranches[index];
            this.tranches.splice(index, 1, { ...current, ...patch });
        },
        splitEquallyHandler: function () {
            const n = Math.max(2, Math.min(20, Number(this.splitCount) || 2));
            const totalEur = splitFromCents(this.totalCents);
            this.tranches = splitEquallyHelper(totalEur, n);
        },
        /**
         * [iter12 2026-05-09] Bidirectional split — auto-balance the
         * non-edited tranche so the sum matches order total. Called by the
         * "Équilibrer le reste" button. Owner reported manual balancing
         * was slow and caused quote-expiry hangs.
         */
        autoBalanceFromIndex: function (editedIndex) {
            if (!Array.isArray(this.tranches) || this.tranches.length < 2) return;
            const idx = (typeof editedIndex === 'number' && editedIndex >= 0)
                ? editedIndex
                : 0;
            const balanced = autoBalanceTranches(this.tranches, this.totalCents, idx);
            this.tranches = balanced;
        },
        /**
         * [iter12 2026-05-09] Suggest tendered (cash received) values for
         * every CASH tranche by rounding amount UP to next €5. User can
         * still override per-tranche. Triggered by "Suggérer les rendus"
         * button after a split-equal between people.
         */
        suggestCashTendered: function () {
            this.tranches = suggestTenderedForCashTranches(this.tranches, 5);
        },
        buildSplitPayload: function () {
            return serializeTranches(this.tranches);
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
        /**
         * After SSOT quote, `form.total` can exceed what the cashier typed when the modal
         * still showed the client-only preview (PosOrderRequest: received vs total).
         * If they already covered the amount shown before quote, bump received so confirm can succeed.
         */
        alignCashReceivedWithQuotedTotal: function (preQuoteForm, quotedForm) {
            if (quotedForm.pos_payment_method !== this.posPaymentMethodEnum.CASH) {
                return quotedForm;
            }
            const shownTotal = parseFloat(preQuoteForm.total);
            const serverTotal = parseFloat(quotedForm.total);
            const received = parseFloat(quotedForm.pos_received_amount);
            if (!Number.isFinite(shownTotal) || !Number.isFinite(serverTotal) || !Number.isFinite(received)) {
                return quotedForm;
            }
            if (serverTotal <= received + 0.001) {
                return quotedForm;
            }
            if (received + 0.001 < shownTotal) {
                return quotedForm;
            }
            const bumped = Math.ceil((serverTotal + 5) * 100) / 100;
            const cashInput = document.getElementById('cashInput');
            if (cashInput) {
                cashInput.value = String(bumped);
                cashInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            this.cashReceivedRaw = bumped;
            const patch = { pos_received_amount: bumped };
            this.emitPaymentFormPatch(patch);
            alertService.info(
                this.$t('pos.cash_received_auto_bumped')
                || 'The server total changed at checkout; the received amount was increased to cover it.',
            );
            return { ...quotedForm, ...patch };
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
            // [CV1-POS-SPLIT-PAYMENT-001] When paymentMode === 'multi', attach
            // payment_breakdown[] to the payload. The frozen-zone backend currently
            // ignores this field; once PLAN_P12 ships, it will create order_payments
            // rows. The dominant tender (largest tranche) is set as pos_payment_method
            // for backward compat with reports/receipt rendering.
            const isMulti = this.paymentMode === 'multi';
            let multiPatch = {};
            if (isMulti) {
                const breakdown = this.buildSplitPayload();
                const dominant = breakdown.reduce((max, t) => (t.amount > (max?.amount || 0) ? t : max), null);
                const dominantMode = dominant?.mode ?? this.posPaymentMethodEnum.CASH;
                const cashTranche = breakdown.find((t) => t.mode === this.posPaymentMethodEnum.CASH);
                multiPatch = {
                    pos_payment_method: dominantMode,
                    payment_breakdown: breakdown,
                    pos_received_amount: cashTranche ? cashTranche.tendered : null,
                    pos_payment_note: 'multi-tender',
                };
            }
            const preparedForm = this.currentFormSnapshot({
                ...inputPatch,
                ...multiPatch,
                branch_id: branchId,
                items: this.normalizeItemsPayload(this.paymentForm.items),
            });

            this.emitPaymentFormPatch({
                ...inputPatch,
                ...multiPatch,
                branch_id: preparedForm.branch_id,
                items: preparedForm.items,
            });

            const quotedForm = await this.refreshQuote(preparedForm);
            const saveForm = this.alignCashReceivedWithQuotedTotal(preparedForm, quotedForm);
            const orderResponse = await this.$store.dispatch('posOrder/save', saveForm);
            await this.handleOrderSuccess(orderResponse, saveForm);
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

            appService.modalHide('#orderpayment');

            const raw = orderResponse?.data;
            const created = raw && typeof raw === 'object' && raw.data !== undefined ? raw.data : raw;
            if (!created || created.id == null) {
                alertService.error(this.$t('message.something_wrong') || 'Réponse commande POS invalide.');
                throw new Error('POS save: missing order payload');
            }
            this.order = created;
            if (Array.isArray(created.order_items)) {
                this.$store.commit('posOrder/orderItems', created.order_items);
            }

            await this.$nextTick();

            // Intentionally no `posOrder/show` refresh: `posOrder/save` already returns `OrderDetailsResource`.
            // A follow-up GET can 401/403 for cashier-only roles and the global axios interceptor logs the user out.

            this.$emit("payment-form:reset");
            this.resetPaymentInputs();
            // [iter15-mega-fix B-009 round-7 2026-05-10] Defer cart reset until
            // the receipt modal is dismissed. Previously the cart was nuked here,
            // immediately before showReceiptModalFromDom() — meaning the cart
            // sub-total flashed to 0,00 € the moment the receipt overlay opened.
            // Owner expects the receipt to act as a freeze-frame of the order
            // that was just paid. The reset is now triggered from
            // ReceiptComponent.reset() (the close button) so the chip stays
            // visible while the cashier reads the ticket.
            // await this.$store.dispatch('posCart/resetCart').catch(() => {});
        },
        showReceiptModalFromDom: function () {
            appService.modalShow('#receiptModal');
        },
        handlePaymentError: function (err) {
            if (err?._paymentTimeout) {
                alertService.error(err.message);
                return;
            }
            if (err?._idempotencyConflict) {
                // [RED-R1 P2] 409 Idempotency-Key-Conflict — surfaced explicitly so cashier
                // does not retry blindly (commande probablement déjà dans le ticker).
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
            // [CV1-POS-SPLIT-PAYMENT-001] Guard: in multi mode, refuse if not balanced.
            if (this.paymentMode === 'multi' && !this.canConfirmMulti) {
                alertService.error(
                    this.$t('pos.split_not_balanced')
                    || 'Le paiement multi n’est pas équilibré (reste dû).'
                );
                return;
            }
            this.loading.isActive = true;
            let paymentSucceeded = false;
            try {
                await this.confirmOrderWithAuthRetry();
                paymentSucceeded = true;
                // [POS-V5 WAVE 3 2026-05-02] Notify parent for success-flash animation
                // (overlay vert 700ms après confirm). Logique métier inchangée.
                this.$emit("order:confirmed", this.order);
            } catch (err) {
                this.handlePaymentError(err);
            } finally {
                this.loading.isActive = false;
            }
            // Open receipt only after the fullscreen LoadingComponent tears down — otherwise Playwright
            // visibility and some overlays can keep the receipt in a "hidden" state.
            if (paymentSucceeded) {
                await this.$nextTick();
                await this.$nextTick();
                await new Promise((resolve) => {
                    requestAnimationFrame(() => requestAnimationFrame(resolve));
                });
                await new Promise((resolve) => setTimeout(resolve, 50));
                this.showReceiptModalFromDom();
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

/* Méthodes paiement — segmented control 2 ou 3 onglets */
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
/* [CV1-POS-SPLIT-PAYMENT-001] 3 onglets : cash | card | multi */
.pos-v5-payment-methods--3col {
    grid-template-columns: 1fr 1fr 1fr;
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
    box-shadow: 0 12px 28px rgba(244, 80, 30, 0.32);
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

/* =============================================================================
   [CV1-POS-SPLIT-PAYMENT-001] Multi-tender block
   -----------------------------------------------------------------------------
   - Sticky summary "Couvert / Reste dû / Monnaie totale"
   - Liste de tranches éditables (PosV5TrancheRow)
   - Diviser à parts égales (input N + bouton)
   ============================================================================= */
.pos-v5-split-block {
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-3);
    margin-bottom: var(--pos-v5-space-4);
}

.pos-v5-split-summary {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    background: var(--pos-v5-bg-subtle);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
}

.pos-v5-split-summary__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
}

.pos-v5-split-summary__label {
    color: var(--pos-v5-ink-soft);
    text-transform: uppercase;
    font-size: var(--pos-v5-text-eyebrow);
    letter-spacing: var(--pos-v5-tracking-caps);
}

.pos-v5-split-summary__value {
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

.pos-v5-split-summary__row--remaining .pos-v5-split-summary__value {
    color: var(--pos-v5-danger);
    font-size: var(--pos-v5-text-h6);
}

.pos-v5-split-summary__row--ok .pos-v5-split-summary__value {
    color: var(--pos-v5-success-dark);
}

.pos-v5-split-summary__row--change .pos-v5-split-summary__value {
    color: var(--pos-v5-success-dark);
}

.pos-v5-split-divider {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    border: 1px dashed var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
}

.pos-v5-split-divider__label {
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    text-transform: uppercase;
    letter-spacing: var(--pos-v5-tracking-caps);
    color: var(--pos-v5-ink-soft);
}

.pos-v5-split-divider__row {
    display: flex;
    gap: var(--pos-v5-space-2);
    align-items: stretch;
}

.pos-v5-split-divider__input {
    width: 90px;
    height: 44px;
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-sm);
    background: var(--pos-v5-bg-panel);
    text-align: center;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
}
.pos-v5-split-divider__input:focus-visible {
    outline: 0;
    border-color: var(--pos-v5-brand-red);
    box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft);
}

.pos-v5-split-divider__btn {
    flex: 1 1 auto;
    height: 44px;
    border: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
    border-radius: var(--pos-v5-radius-sm);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
    cursor: pointer;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-split-divider__btn:hover:not(:disabled) {
    background: var(--pos-v5-brand-red-soft);
    border-color: var(--pos-v5-brand-red);
    color: var(--pos-v5-brand-red);
}
.pos-v5-split-divider__btn:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}
.pos-v5-split-divider__btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-v5-split-tranches {
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-2);
}

.pos-v5-split-empty {
    margin: 0;
    padding: var(--pos-v5-space-3);
    text-align: center;
    color: var(--pos-v5-ink-soft);
    font-size: var(--pos-v5-text-body);
    border: 1px dashed var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    background: var(--pos-v5-bg-subtle);
}

.pos-v5-split-add {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 48px;
    padding: 0 var(--pos-v5-space-4);
    border: 1px dashed var(--pos-v5-brand-red);
    background: var(--pos-v5-brand-red-faint);
    color: var(--pos-v5-brand-red);
    border-radius: var(--pos-v5-radius-md);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
    cursor: pointer;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-split-add:hover {
    background: var(--pos-v5-brand-red-soft);
    color: var(--pos-v5-brand-red-dark);
}
.pos-v5-split-add:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}
</style>
