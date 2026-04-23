<template>
    <div id="receiptModal" class="modal" role="document" :aria-label="$t('a11y.receipt_preview')">
        <div :class="receiptDialogClasses" id="print" :dir="direction">
            <div class="modal-header hidden-print">
                <button type="button" @click="reset"
                    class="modal-close flex items-center justify-center gap-1.5 py-2 px-4 rounded bg-[#FB4E4E]">
                    <i class="lab lab-back-bold lab-font-size-16 text-white"></i>
                    <span class="text-xs leading-5 capitalize text-white">{{ $t('button.close') }}</span>
                </button>
                <button
                    type="button"
                    @click="handlePrintClick"
                    :disabled="isPrinting"
                    :aria-busy="isPrinting"
                    data-testid="receipt-print-trigger"
                    class="flex items-center justify-center gap-1.5 py-2 px-4 rounded bg-[#1AB759] disabled:opacity-60">
                    <i class="lab lab-print-bold lab-font-size-16 text-white"></i>
                    <span class="text-xs leading-5 capitalize text-white">{{ $t('button.print_invoice') }}</span>
                </button>
                <button
                    ref="hiddenPrintButton"
                    type="button"
                    v-print="printObj"
                    class="hidden"
                    aria-hidden="true"
                    tabindex="-1"
                    data-testid="receipt-hidden-print-button">_</button>
            </div>
            <div class="modal-body">
                <div v-if="order.pos_siret || order.pos_vat_intra || order.pos_register_id || order.operator_name"
                    class="text-center text-[10px] leading-snug text-heading pb-2 border-b border-dashed border-gray-400">
                    <p v-if="order.pos_siret">{{ $t('label.siret') }}: {{ order.pos_siret }}</p>
                    <p v-if="order.pos_vat_intra">{{ $t('label.vat_intra') }}: {{ order.pos_vat_intra }}</p>
                    <p v-if="order.pos_register_id">{{ $t('label.register_id') }}: {{ order.pos_register_id }}</p>
                    <p v-if="order.operator_name">{{ $t('label.operator') }}: {{ order.operator_name }}</p>
                </div>
                <receipt-duplicata-marker :order="effectiveOrder" />
                <div class="text-center pb-3.5 border-b border-dashed border-gray-400">
                    <h3 class="text-2xl font-bold mb-1">{{ company.company_name }}</h3>
                    <h4 class="text-sm font-normal">{{ branch.address }}</h4>
                    <h5 class="text-sm font-normal">Tel: {{ branch.phone }}</h5>
                </div>

                <table class="w-full my-1.5">
                    <tbody>
                        <tr>
                            <td class="text-xs text-left py-0.5 text-heading">{{ $t('button.order') }}
                                #{{ order.order_serial_no }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs text-left py-0.5 text-heading">{{ order.order_date }}</td>
                            <td class="text-xs text-right py-0.5 text-heading">{{ order.order_time }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="w-full">
                    <thead class="border-t border-b border-dashed border-gray-400">
                        <tr>
                            <th scope="col" class="py-1 font-normal text-xs capitalize text-left text-heading w-8">
                                {{ $t('label.qty') }}
                            </th>
                            <th scope="col"
                                class="py-1 font-normal text-xs capitalize flex items-center justify-between text-heading">
                                <span>{{ $t('label.item_description') }}</span>
                                <span>{{ $t('label.price') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="border-b border-dashed border-gray-400">
                        <tr v-if="orderItems.length > 0" v-for="(item, idx) in orderItems" :key="item.id || `item-${idx}`">
                            <td class="text-left font-normal align-top py-1">
                                <p class="text-xs leading-5 text-heading">{{ item.quantity }}</p>
                            </td>
                            <td class="text-left font-normal align-top py-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-normal capitalize">{{ item.item_name }}</h4>
                                    <p class="text-xs leading-5 text-heading">{{ item.total_without_tax_currency_price
                                        }}
                                    </p>
                                </div>
                                <p v-if="receiptVariationsFor(item).length !== 0"
                                    class="text-xs leading-5 font-normal text-heading max-w-[200px]">
                                    <span v-for="(variation, index) in receiptVariationsFor(item)" :key="'var-' + idx + '-' + index">
                                        <template v-if="variation.label">{{ variation.label }}: </template>
                                        <template v-if="variation.quantity > 1">{{ variation.quantity }}× </template>{{ variation.name }}
                                        <span v-if="index + 1 < receiptVariationsFor(item).length">, </span>
                                    </span>
                                </p>
                                <p v-if="receiptExtrasFor(item).length > 0"
                                    class="text-xs leading-5 font-normal text-heading max-w-[200px]">
                                    {{ $t('label.extras') }}:
                                    <span v-for="(extra, index) in receiptExtrasFor(item)" :key="'extra-' + idx + '-' + index">
                                        <template v-if="extra.quantity > 1">{{ extra.quantity }}× </template>{{ extra.name }}
                                        <span v-if="index + 1 < receiptExtrasFor(item).length">, </span>
                                    </span>
                                </p>
                                <p v-if="item.instruction"
                                    class="text-xs leading-5 font-normal text-heading max-w-[200px]">
                                    {{ $t('label.instruction') }}: {{ item.instruction }}
                                </p>

                                <div class="flex items-center justify-between" v-if="item.tax_rate > 0">
                                    <p class="text-xs leading-5 font-normal text-heading">
                                        {{ item.tax_name }} ({{ item.tax_currency_rate }} {{ item.tax_type }})</p>
                                    <p class="text-xs leading-5 font-normal text-heading">
                                        {{ item.tax_currency_amount }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="py-2 pl-7">
                    <table class="w-full">
                        <tbody>
                            <tr>
                                <td class="text-xs text-left py-0.5 uppercase text-heading">{{ $t('label.subtotal') }}:
                                </td>
                                <td class="text-xs text-right py-0.5 text-heading">{{
                                    order.subtotal_without_tax_currency_price
                                }}</td>
                            </tr>
                            <tr>
                                <td class="text-xs text-left py-0.5 uppercase text-heading">
                                    {{ $t('label.total_tax') }}:
                                </td>
                                <td class="text-xs text-right py-0.5 text-heading">
                                    {{ order.total_tax_currency_price }}
                                </td>
                            </tr>
                            <!--
                              [POS-9.1.13] Per-rate VAT breakdown (CGI art. 242 nonies A).
                              Renders nothing when there is a single rate AND no rate at all
                              (back-compat with old orders without `tax_lines`).
                            -->
                            <template v-if="Array.isArray(order.tax_lines) && order.tax_lines.length > 0">
                                <tr v-for="line in order.tax_lines" :key="(line.tax_name || '') + '@' + line.tax_rate">
                                    <td class="text-[10px] text-left py-0.5 pl-2 text-heading">
                                        {{ line.tax_name || $t('label.total_tax') }}
                                        <span v-if="line.tax_rate"> ({{ line.tax_rate }}%)</span>
                                        <span class="text-[10px]"> · {{ $t('label.base_ht') || 'HT' }} {{ line.base_ht_currency }}</span>
                                    </td>
                                    <td class="text-[10px] text-right py-0.5 text-heading">
                                        {{ line.tax_currency }}
                                    </td>
                                </tr>
                            </template>
                            <tr>
                                <td class="text-xs text-left py-0.5 uppercase text-heading">{{ $t('label.discount') }}:
                                </td>
                                <td class="text-xs text-right py-0.5 text-heading">{{ order.discount_currency_price }}
                                </td>
                            </tr>
                            <tr v-if="order.order_type === orderTypeEnum.DELIVERY">
                                <td class="text-xs text-left py-0.5 uppercase text-heading">{{
                                    $t('label.delivery_charge') }}:</td>
                                <td class="text-xs text-right py-0.5 text-heading">{{
                                    order.delivery_charge_currency_price }}</td>
                            </tr>

                            <tr>
                                <td class="text-xs text-left py-0.5 font-bold uppercase text-heading">
                                    {{ $t('label.total') }}:
                                </td>
                                <td class="text-xs text-right py-0.5 font-bold text-heading">
                                    {{ order.total_currency_price }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-xs py-2 border-t border-b border-dashed border-gray-400 text-heading">
                    <table class="w-full">
                        <tbody>
                            <tr>
                                <td class="pt-1 pb-1 pr-1"> {{ $t('label.order_type') }}: {{ enums.orderTypeEnumArray[order.order_type] }}</td>
                            </tr>
                            <template v-if="paymentLines.length === 1">
                                <tr>
                                    <td class="pt-1 pb-1 pr-1 align-top text-start">{{ $t('label.payment_type') }}:
                                        {{ paymentMethodLabel(paymentLines[0].method) }}</td>
                                    <td class="pt-1 pb-1 text-end" v-if="paymentLines[0].change_amount > 0">
                                        <div>{{ $t('label.cash') }}: {{ order.pos_received_currency_amount }}</div>
                                        <span>{{ $t('label.change') }} : {{ order.cash_back_currency_amount }}</span>
                                    </td>
                                </tr>
                            </template>
                            <template v-else-if="paymentLines.length > 1">
                                <tr>
                                    <td colspan="2" class="pt-1 pb-0.5 text-start font-semibold">{{ $t('label.tendered_breakdown') }}</td>
                                </tr>
                                <tr v-for="(line, idx) in paymentLines" :key="'pay-' + idx">
                                    <td class="pb-1 pr-1 align-top text-start">{{ paymentMethodLabel(line.method) }}</td>
                                    <td class="pb-1 text-end">
                                        <div>{{ line.currency_amount != null ? line.currency_amount : line.amount }}</div>
                                        <div v-if="line.change_amount > 0">
                                            <span>{{ $t('label.change') }} : </span>{{ line.change_amount }}</div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <h4 v-if="order.token"
                    class="py-2 capitalize text-xl font-bold text-center border-b border-dashed border-gray-400">
                    {{ $t('label.token') }} #{{ order.token }}
                </h4>
                <div v-if="nf525FooterLines.length"
                    class="text-[10px] leading-snug text-heading text-center px-1 py-2 border-b border-dashed border-gray-400">
                    <p v-for="line in nf525FooterLines" :key="line.key" class="mb-0.5">
                        <span class="font-semibold">{{ $t('label.' + line.key) }}:</span>
                        {{ line.value }}
                    </p>
                </div>
                <div class="text-center pt-2 pb-4">
                    <p class="text-[11px] leading-[14px] capitalize text-heading">
                        {{ $t('message.thank_you') }}
                    </p>
                    <p class="text-[11px] leading-[14px] capitalize text-heading">
                        {{ $t('message.please_come_again') }}
                    </p>
                </div>
                <div class="flex flex-col items-end">
                    <h5 class="text-[8px] font-normal text-left w-[46px] leading-[10px]">
                        {{ $t('label.powered_by') }}
                    </h5>
                    <h6 class="text-xs font-normal leading-4">{{ company.company_name }}</h6>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import print from "vue3-print-nb";
import appService from "../../../services/appService";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import ReceiptDuplicataMarker from "./ReceiptDuplicataMarker.vue";
import {
    formatPaymentsBreakdown as buildPaymentLines,
    buildNf525Footer,
    receiptWidthClass as receiptPaperClass,
    normalizeReceiptVariations,
    normalizeReceiptExtras,
} from "../../../helpers/posReceiptBuilder";

// [Phase-4 / T15–T21] Aperçu reçu + impression (NF525, composition_snapshot / legacy),
// POST /print-receipt, duplicata, continuité si API down (W9.D) — ne pas refondre
// le gabarit légal sans relecture (voir plan 10 phases, GATE reçu).

export default {
    name: "ReceiptComponent",
    components: { ReceiptDuplicataMarker },
    props: {
        order: Object
    },
    data() {
        return {
            // [W9.D] Local override of `order.receipt_print_count`. We
            // bump this BEFORE triggering the print so that the
            // ReceiptDuplicataMarker (computed off `effectiveOrder`)
            // shows up on the printed paper from the 2nd impression
            // onwards. Initialised lazily from the prop in mounted().
            localPrintCount: null,
            isPrinting: false,
            printObj: {
                id: "print",
                popTitle: this.$t("menu.order_receipt"),
            },
            posPaymentMethodEnumArray: {
                [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
                [posPaymentMethodEnum.CARD]: this.$t("label.card"),
                [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
                [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
                // [P11_RECEIPT_TR_LABEL] TICKET_RESTAURANT (back enum value = 5).
                // Refacto futur : remplacer `5` par `posPaymentMethodEnum.TICKET_RESTAURANT`
                // une fois que P11_FRONT_TR_UI aura complété l'enum JS.
                5: this.$t("label.ticket_restaurant"),
            },
            orderTypeEnum: orderTypeEnum,
            enums: {
                orderTypeEnumArray: {
                    [orderTypeEnum.DELIVERY]: this.$t("label.delivery"),
                    [orderTypeEnum.TAKEAWAY]: this.$t("label.takeaway"),
                    [orderTypeEnum.DINING_TABLE]: this.$t("label.dining_table")
                }
            }
        }
    },
    computed: {
        company: function () {
            return this.$store.getters['company/lists'];
        },
        branch: function () {
            return this.$store.getters['backendGlobalState/branchShow'];
        },
        orderItems: function () {
            return this.$store.getters['posOrder/orderItems'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        paperWidthMm: function () {
            return 58;
        },
        receiptDialogClasses: function () {
            return ['modal-dialog', 'rounded-none', receiptPaperClass(this.paperWidthMm)];
        },
        paymentLines: function () {
            return buildPaymentLines(this.order);
        },
        nf525FooterLines: function () {
            return buildNf525Footer(this.order);
        },
        /*
         * [W9.D + W9-AUDIT TEST-3] Reactive view of the order with the
         * locally-bumped print count. We never mutate the upstream prop.
         *
         * Take the MAX of (local optimistic, parent prop). Reasoning:
         * - The local count is bumped right after the print POST resolves,
         *   so it's the most up-to-date value during the print sequence.
         * - The parent prop catches up later via store refetch; at that
         *   point baseCount === localCount (no-op).
         * - If the parent ever pushes a HIGHER count (e.g. another tab
         *   printed concurrently), MAX ensures we don't regress the badge.
         * - The counter is monotonically increasing by design (NF525
         *   evidence), so MAX is semantically correct.
         */
        effectiveOrder: function () {
            const baseCount = Number(this.order?.receipt_print_count ?? 0);
            const localCount = this.localPrintCount;
            const effectiveCount = localCount === null
                ? baseCount
                : Math.max(baseCount, localCount);
            return {
                ...this.order,
                receipt_print_count: effectiveCount,
            };
        },
    },
    mounted() {
        this.$store.dispatch("company/lists").then().catch();
        this.localPrintCount = Number(this.order?.receipt_print_count ?? 0);
    },
    watch: {
        // Keep local count in sync if the parent swaps to a different
        // order in the same modal mount (e.g. operator switching tabs).
        'order.id': function () {
            this.localPrintCount = Number(this.order?.receipt_print_count ?? 0);
        },
    },
    methods: {
        reset: function () {
            appService.modalHide();
        },
        paymentMethodLabel: function (method) {
            const n = Number(method);
            if (!Number.isNaN(n) && this.posPaymentMethodEnumArray[n] !== undefined) {
                return this.posPaymentMethodEnumArray[n];
            }
            if (typeof method === 'string' && method !== '') {
                return method;
            }
            return method ?? '';
        },
        // [V14 GLOBAL FINDING G-1 P0 + G-2 P1] Receipt must consume the
        // immutable composition_snapshot lines (post-T07) AND the legacy
        // item_variations JSON (pre-T07) without breaking either path.
        // The snapshot uses `variation_name` as the value and `attribute_name`
        // as the label ; legacy uses `name` as the value and `variation_name`
        // as the label. Both shapes are normalized in posReceiptBuilder
        // helpers so that the printed receipt always shows the historical
        // attribute / value (NF525 fiscal immutability) AND the per-line
        // quantity (multi-qty parity with the cart UI).
        receiptVariationsFor: function (item) {
            return normalizeReceiptVariations(item ? item.item_variations : []);
        },
        receiptExtrasFor: function (item) {
            return normalizeReceiptExtras(item ? item.item_extras : []);
        },
        // [W9.D / G3] Receipt print + reprint policy.
        //
        // Flow:
        //   1. POST /admin/pos/orders/{id}/print-receipt to atomically
        //      bump the server-side counter and emit the NF525 audit
        //      row (pos.receipt.print or pos.receipt.reprint).
        //   2. Reflect the new count locally so the DUPLICATA badge
        //      shows BEFORE we capture the DOM for printing.
        //   3. Wait one tick so the badge actually renders.
        //   4. Programmatically click the hidden v-print button to
        //      trigger vue3-print-nb's iframe pipeline.
        //
        // Failure handling: if the API call fails (network blip, lock
        // contention, server error) we still proceed with the print —
        // the operator MUST be able to hand a paper ticket to the
        // customer for operational continuity. We optimistically bump
        // the local count so the UI badge reflects intent; the next
        // successful call will re-sync with the server-authoritative
        // value via `watch order.id`.
        async handlePrintClick() {
            if (this.isPrinting) {
                return;
            }
            this.isPrinting = true;
            try {
                if (this.order?.id) {
                    try {
                        const { data } = await axios.post(
                            `admin/pos/orders/${this.order.id}/print-receipt`
                        );
                        this.localPrintCount = Number(
                            data?.receipt_print_count
                            ?? (Number(this.localPrintCount ?? 0) + 1)
                        );
                    } catch (apiError) {
                        // Optimistic local bump so DUPLICATA appears
                        // even if the server temporarily refuses.
                        this.localPrintCount = Number(this.localPrintCount ?? 0) + 1;
                        console.warn('[ReceiptComponent] increment API failed, printing anyway', apiError);
                    }
                }

                await this.$nextTick();
                const trigger = this.$refs.hiddenPrintButton;
                if (trigger && typeof trigger.click === 'function') {
                    trigger.click();
                } else if (typeof window !== 'undefined' && typeof window.print === 'function') {
                    window.print();
                }
            } finally {
                this.isPrinting = false;
            }
        },
    },
    directives: {
        print
    },
}
</script>
<style scoped>
.receipt-58mm {
    width: 58mm;
    max-width: 100%;
    box-sizing: border-box;
}
.receipt-80mm {
    width: 80mm;
    max-width: 100%;
    box-sizing: border-box;
}
@media print {
    .hidden-print {
        display: none !important;
    }
    .receipt-58mm {
        width: 58mm !important;
    }
    .receipt-80mm {
        width: 80mm !important;
    }
}
</style>