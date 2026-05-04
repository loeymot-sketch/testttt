<template>
    <div id="receiptModal" class="modal">
        <div class="modal-dialog max-w-[340px] rounded-none" id="print" :dir="direction">
            <div class="modal-body">
                <div class="text-center pb-3.5 border-b border-dashed border-gray-400">
                    <h3 class="text-2xl font-bold mb-1">{{ company.company_name }}</h3>
                    <h4 class="text-sm font-normal">{{ receiptBranch.address }}</h4>
                    <h5 class="text-sm font-normal">Tel: {{ receiptBranch.phone }}</h5>
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
                        <tr v-if="orderItems.length > 0" v-for="item in orderItems" :key="item.id || item.item_name">
                            <td class="text-left font-normal align-top py-1.5">
                                <p class="text-xs leading-5 font-semibold text-heading">{{ item.quantity }}</p>
                            </td>
                            <td class="text-left font-normal align-top py-1.5 pl-1">
                                <!-- Nom produit + prix total -->
                                <div class="flex items-start justify-between gap-1">
                                    <h4 class="text-sm font-semibold capitalize leading-tight">{{ item.item_name }}</h4>
                                    <p class="text-xs font-semibold text-heading whitespace-nowrap">{{ item.total_without_tax_currency_price }}</p>
                                </div>

                                <!-- Instruction structurée : chaque ligne = une info (Viandes, Sauce, Supplément, Formule…) -->
                                <template v-if="item.instruction">
                                    <div v-for="(line, li) in item.instruction.split('\n').filter(l => l.trim())" :key="li"
                                        class="flex items-start justify-between gap-1 mt-0.5">
                                        <!-- Ligne ↳ (option formule) -->
                                        <p v-if="line.startsWith('\u21b3')"
                                            class="text-[10px] leading-4 text-gray-500 pl-2">{{ line }}</p>
                                        <!-- Ligne normale -->
                                        <p v-else class="text-[10px] leading-4 text-heading">{{ line }}</p>
                                    </div>
                                </template>

                                <!-- Taxe si applicable -->
                                <div class="flex items-center justify-between mt-0.5" v-if="item.tax_rate > 0">
                                    <p class="text-[10px] leading-4 text-gray-400">{{ item.tax_name }} ({{ item.tax_currency_rate }} {{ item.tax_type }})</p>
                                    <p class="text-[10px] leading-4 text-gray-400">{{ item.tax_currency_amount }}</p>
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
                                <td class="text-xs text-right py-0.5 text-heading">
                                    {{ order.subtotal_without_tax_currency_price }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-xs text-left py-0.5 uppercase text-heading">
                                    {{ $t('label.total_tax') }}:
                                </td>
                                <td class="text-xs text-right py-0.5 text-heading">
                                    {{ order.total_tax_currency_price }}
                                </td>
                            </tr>
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
                            <tr>
                                <td class="pt-1 pb-1 pr-1 align-top text-start">{{ $t('label.payment_type') }}: {{ posPaymentMethodEnumArray[order.pos_payment_method] }}</td>
                                <td class="pt-1 pb-1 text-end" v-if="order.cash_back_amount > 0">
                                    <div>{{ $t('label.cash') }}: {{ order.pos_received_currency_amount }}</div>
                                    <span>{{ $t('label.change') }} : {{ order.cash_back_currency_amount }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h4 v-if="order.queue_number || order.token"
                    class="py-2 capitalize text-xl font-bold text-center border-b border-dashed border-gray-400">
                    <template v-if="order.queue_number">N°{{ order.queue_number }}</template>
                    <template v-else>{{ $t('label.token') }} #{{ order.token }}</template>
                </h4>
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
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import OrderTypeEnum from "../../../enums/modules/orderTypeEnum";
import { receiptBranchHeader } from "../../../helpers/posReceiptBuilder";

export default {
    name: "PosOrderReceiptComponent",
    props: {
        order: Object
    },
    data() {
        return {
            posPaymentMethodEnumArray: {
                [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
                [posPaymentMethodEnum.CARD]: this.$t("label.card"),
                [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
                [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
            },
            orderTypeEnum: OrderTypeEnum,
            enums: {
                orderTypeEnumArray: {
                    [OrderTypeEnum.DELIVERY]: this.$t("label.delivery"),
                    [OrderTypeEnum.TAKEAWAY]: this.$t("label.takeaway"),
                    [OrderTypeEnum.DINING_TABLE]: this.$t("label.dining_table")
                }
            }
        }
    },
    computed: {
        company: function () {
            return this.$store.getters['company/lists'];
        },
        receiptBranch: function () {
            return receiptBranchHeader(
                this.order,
                this.$store.getters['backendGlobalState/branchShow']
            );
        },
        orderItems: function () {
            return this.$store.getters['posOrder/orderItems'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
    },
    mounted() {
        this.$store.dispatch("company/lists").then().catch();
        const bid = this.order?.branch_id ?? this.order?.branch?.id;
        if (bid) {
            this.$store.dispatch('backendGlobalState/branchShow', bid).catch(() => {});
        }
    }
}
</script>

<style scoped>
/* =============================================================================
   PosOrderReceiptComponent — POS V5 chrome touche (refonte 2026-05-02 R2)
   -----------------------------------------------------------------------------
   Cycle    : CV1-POS-DESIGN-CONVERGENCE-001 R2
   Note     : modal de réimpression depuis l'historique. Le bloc papier
   `#receiptModal .modal-dialog #print` reste INTACT (zone fiscale NF525 :
   monospace + dashed borders légaux). Seul le chrome modal est retouché V5.
   ============================================================================= */
:deep(#receiptModal .modal-dialog) {
    border-radius: var(--pos-v5-radius-xl) !important;
    box-shadow: var(--pos-v5-shadow-modal) !important;
    background: var(--pos-v5-bg-panel) !important;
    border: 1px solid var(--pos-v5-border) !important;
    overflow: hidden;
}

/* Aperçu papier ticket reçoit une matière warm */
:deep(#print) {
    background: var(--pos-v5-bg-receipt);
    padding: var(--pos-v5-space-4);
}
</style>