<template>
    <div id="receiptModal" class="modal">
        <div class="modal-dialog max-w-[340px] rounded-none" id="print" :dir="direction">
            <div class="modal-body">
                <!-- [HEAL-H1 2026-06-07] NF525 fiscal header — SIRET / TVA intra /
                     N° caisse / Opérateur. Mirrors ReceiptComponent.vue:67-73 so the
                     order-history reprint ("Imprimer La Facture") renders a
                     fiscally-conformant ticket. Fields fed by OrderDetailsResource
                     (pos_siret/pos_vat_intra/pos_register_id/operator_name). -->
                <div v-if="order.pos_siret || order.pos_vat_intra || order.pos_register_id || order.operator_name"
                    class="text-center text-[10px] leading-snug text-heading pb-2 border-b border-dashed border-gray-400">
                    <p v-if="order.pos_siret">{{ $t('label.siret') }}: {{ order.pos_siret }}</p>
                    <p v-if="order.pos_vat_intra">{{ $t('label.vat_intra') }}: {{ order.pos_vat_intra }}</p>
                    <p v-if="order.pos_register_id">{{ $t('label.register_id') }}: {{ order.pos_register_id }}</p>
                    <p v-if="order.operator_name">{{ $t('label.operator') }}: {{ order.operator_name }}</p>
                </div>
                <receipt-duplicata-marker :order="order" />
                <receipt-remboursement-marker :order="order" />
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
                            <!-- [HEAL-H1 2026-06-07] Per-rate VAT ventilation (CGI art.
                                 242 nonies A). Mirrors ReceiptComponent.vue:180-191 ;
                                 fed by OrderDetailsResource.tax_lines (tax_name, tax_rate,
                                 base_ht_currency, tax_currency). -->
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
                <!-- [HEAL-H1 2026-06-07] NF525 footer — N° ticket NF525 (fiscal
                     sequence), audit fingerprint, legal mentions. Mirrors
                     ReceiptComponent.vue:253-259 ; lines built by buildNf525Footer()
                     (posReceiptBuilder) from fiscal_sequence_no /
                     audit_chain_fingerprint / pos_legal_footer. -->
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
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import OrderTypeEnum from "../../../enums/modules/orderTypeEnum";
// [HEAL-H1 2026-06-07] Reuse the SSOT NF525 footer builder + duplicata marker
// already used by the POS ReceiptComponent so the order-history reprint ticket
// renders the same fiscal fields (no divergent receipt logic).
import { receiptBranchHeader, buildNf525Footer } from "../../../helpers/posReceiptBuilder";
import ReceiptRemboursementMarker from "../pos/ReceiptRemboursementMarker.vue";
import ReceiptDuplicataMarker from "../pos/ReceiptDuplicataMarker.vue";

export default {
    name: "PosOrderReceiptComponent",
    components: { ReceiptRemboursementMarker, ReceiptDuplicataMarker },
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
                // [HEAL-H1 2026-06-07] codes 5 (Ticket Restaurant) & 6 (counter
                // deferred) — previously unmapped → blank "Type de paiement" on
                // the history receipt for those tenders. Labels already in fr.json.
                [posPaymentMethodEnum.TICKET_RESTAURANT]: this.$t("label.ticket_restaurant"),
                [posPaymentMethodEnum.COUNTER_DEFERRED]: this.$t("label.counter_deferred"),
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
        // [HEAL-H1 2026-06-07] NF525 footer lines (fiscal ticket no, audit
        // fingerprint, legal mentions) — same SSOT builder as ReceiptComponent.
        nf525FooterLines: function () {
            return buildNf525Footer(this.order);
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