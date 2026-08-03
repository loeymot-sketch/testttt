<template>
    <!--
      [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Refonte chrome modal Receipt.
      Le bloc papier print (.print-receipt-client / .print-receipt-kitchen)
      reste INTACT — zone fiscale NF525 : monospace + dashed borders légaux.
    -->
    <div id="receiptModal" class="modal pos-v5-receipt-modal" role="document" :aria-label="$t('a11y.receipt_preview')">
        <div class="modal-dialog pos-v5-receipt-dialog rounded-none w-full max-w-md">
            <!-- [BYPASS-P3] Marqueur visible UI quand PRINTING_BYPASS_MODE=true.
                 hidden-print = ne s'imprime pas, juste pour validation E2E à l'écran. -->
            <div v-if="bypassPrintingActive"
                 class="hidden-print bg-amber-100 border border-amber-400 text-amber-900 px-3 py-2 text-xs font-semibold text-center"
                 data-testid="bypass-printing-marker"
                 role="status"
                 aria-live="polite">
                {{ bypassPrintingMarker }}
            </div>
            <div class="modal-header pos-v5-receipt-toolbar hidden-print flex flex-wrap items-center justify-end gap-2">
                <button type="button" @click="reset"
                    class="modal-close pos-v5-receipt-btn pos-v5-receipt-btn--ghost"
                    :aria-label="$t('button.close')">
                    <span aria-hidden="true">←</span>
                    <span class="text-xs leading-5 capitalize">{{ $t('button.close') }}</span>
                </button>
                <button
                    type="button"
                    @click="handlePrintKitchenClick"
                    :disabled="printingKitchen"
                    :aria-busy="printingKitchen"
                    data-testid="receipt-print-kitchen"
                    class="pos-v5-receipt-btn pos-v5-receipt-btn--kitchen">
                    <span aria-hidden="true">👨‍🍳</span>
                    <span class="leading-5 capitalize">{{ $t('pos.print_ticket_kitchen') }}</span>
                </button>
                <button
                    type="button"
                    @click="handlePrintClientClick"
                    :disabled="printingClient"
                    :aria-busy="printingClient"
                    data-testid="receipt-print-client"
                    class="pos-v5-receipt-btn pos-v5-receipt-btn--client">
                    <span aria-hidden="true">🧾</span>
                    <span class="text-xs leading-5 capitalize">{{ $t('pos.print_ticket_client') }}</span>
                </button>
                <!-- [PRINT-INSTANT 2026-07-06] Fallback navigateur = BOUTONS MANUELS explicites.
                     window.print() n'est plus JAMAIS déclenché automatiquement (fini l'écran gris
                     surprise) : ces boutons n'apparaissent que si le pont d'impression est
                     injoignable, et c'est le caissier qui choisit d'imprimer via le navigateur. -->
                <div v-if="showBrowserPrintFallback" class="hidden-print w-full flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        @click="manualBrowserPrint('kitchen')"
                        data-testid="receipt-browser-print-kitchen"
                        class="pos-v5-receipt-btn pos-v5-receipt-btn--ghost">
                        <span aria-hidden="true">🖨️</span>
                        <span class="text-xs leading-5">{{ $t('pos.print_browser_manual') }} — {{ $t('pos.print_ticket_kitchen') }}</span>
                    </button>
                    <button
                        type="button"
                        @click="manualBrowserPrint('client')"
                        data-testid="receipt-browser-print-client"
                        class="pos-v5-receipt-btn pos-v5-receipt-btn--ghost">
                        <span aria-hidden="true">🖨️</span>
                        <span class="text-xs leading-5">{{ $t('pos.print_browser_manual') }} — {{ $t('pos.print_ticket_client') }}</span>
                    </button>
                </div>
                <button
                    ref="hiddenPrintClientButton"
                    type="button"
                    v-print="printObjClient"
                    class="hidden"
                    aria-hidden="true"
                    tabindex="-1"
                    data-testid="receipt-hidden-print-button-client">_</button>
                <button
                    ref="hiddenPrintKitchenButton"
                    type="button"
                    v-print="printObjKitchen"
                    class="hidden"
                    aria-hidden="true"
                    tabindex="-1"
                    data-testid="receipt-hidden-print-button-kitchen">_</button>
            </div>
            <div class="modal-body max-h-[75vh] overflow-y-auto space-y-6 px-2 pb-4">
                <!-- Aperçu ticket CLIENT (composition structurée uniquement — fiscal / caisse) -->
                <div>
                    <p class="hidden-print text-xs font-semibold text-heading mb-1">{{ $t('pos.print_ticket_client') }}</p>
                    <div id="print-receipt-client" :class="receiptPaperRootClass" :dir="direction">
                        <div v-if="order.pos_siret || order.pos_vat_intra || order.pos_register_id || order.operator_name"
                            class="text-center text-[10px] leading-snug text-heading pb-2 border-b border-dashed border-gray-400">
                            <p v-if="order.pos_siret">{{ $t('label.siret') }}: {{ order.pos_siret }}</p>
                            <p v-if="order.pos_vat_intra">{{ $t('label.vat_intra') }}: {{ order.pos_vat_intra }}</p>
                            <p v-if="order.pos_register_id">{{ $t('label.register_id') }}: {{ order.pos_register_id }}</p>
                            <p v-if="order.operator_name">{{ $t('label.operator') }}: {{ order.operator_name }}</p>
                        </div>
                        <receipt-duplicata-marker :order="effectiveOrder" />
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
                                <tr v-if="orderItems.length > 0" v-for="(item, idx) in orderItems" :key="'c-' + (item.id || `item-${idx}`)">
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
                                                <template v-if="extra.quantity > 1">{{ extra.quantity }}× </template>{{ extra.name }}<template v-if="extra.line_total > 0"> (+{{ formatReceiptAddonPrice(extra.line_total) }})</template>
                                                <span v-if="index + 1 < receiptExtrasFor(item).length">, </span>
                                            </span>
                                        </p>
                                        <!-- [G2-HEAL-03 / G.5 G5-F-002 P1] Composer addons (menu_formule bundled drinks/sides).
                                             NF525: line_total is the ratio-adjusted price; catalog_price is NOT rendered. -->
                                        <p v-for="(addon, index) in receiptAddonsFor(item)"
                                            :key="'addon-' + idx + '-' + index"
                                            class="text-xs leading-5 font-normal text-heading max-w-[200px] flex items-center justify-between gap-1"
                                            data-testid="receipt-addon-line">
                                            <span>
                                                + <template v-if="addon.quantity > 1">{{ addon.quantity }}× </template>{{ addon.name }}
                                            </span>
                                            <span v-if="addon.line_total > 0">+{{ formatReceiptAddonPrice(addon.line_total) }}</span>
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
                        <h4 v-if="order.queue_number || order.token"
                            class="py-2 capitalize text-xl font-bold text-center border-b border-dashed border-gray-400">
                            <template v-if="order.queue_number">N°{{ order.queue_number }}</template>
                            <template v-else>{{ $t('label.token') }} #{{ order.token }}</template>
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

                <!-- Aperçu ticket CUISINE (instructions complètes — sans usage fiscal) -->
                <div>
                    <p class="hidden-print text-xs font-semibold text-heading mb-1">{{ $t('pos.print_ticket_kitchen') }}</p>
                    <div id="print-receipt-kitchen" :class="receiptPaperRootClass" :dir="direction">
                        <div class="text-center pb-2 border-b-2 border-dashed border-gray-600">
                            <h2 class="text-xl font-black tracking-wide">{{ $t('pos.receipt_kitchen_title') }}</h2>
                            <p class="text-sm font-semibold">{{ company.company_name }}</p>
                            <p class="text-[11px]">{{ receiptBranch.address }}</p>
                            <p class="text-xs mt-1">{{ $t('button.order') }} #{{ order.order_serial_no }}</p>
                            <p class="text-xs">{{ order.order_date }} · {{ order.order_time }}</p>
                            <p v-if="order.operator_name" class="text-[10px] mt-0.5">{{ $t('label.operator') }}: {{ order.operator_name }}</p>
                            <p v-if="order.queue_number" class="text-lg font-bold mt-1">N°{{ order.queue_number }}</p>
                            <p class="text-[10px] uppercase mt-1">{{ enums.orderTypeEnumArray[order.order_type] }}</p>
                        </div>

                        <table class="w-full mt-2">
                            <thead class="border-b border-dashed border-gray-400">
                                <tr>
                                    <th class="py-1 text-left text-[10px] font-semibold w-7">{{ $t('label.qty') }}</th>
                                    <th class="py-1 text-left text-[10px] font-semibold">{{ $t('label.item_description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- [KITCHEN-SYMBOLS 2026-06-28] Format symbolique pour la cuisine :
                                     L1 [Support]|[Produit]|[Taille]|[Viande]|[Crudités]|[Sauce],
                                     L2 suppléments « + … », L3 MENU/F. Plus de « Sauce 1ère gratuite ». -->
                                <tr v-for="(item, idx) in orderItems" :key="'k-' + (item.id || `ki-${idx}`)" class="border-b border-dashed border-gray-300 align-top">
                                    <td class="py-1.5 align-top text-base font-black">{{ item.quantity }}×</td>
                                    <td class="py-1.5">
                                        <template v-for="(line, li) in kitchenSymbolicLines(item)" :key="'ks-' + idx + '-' + li">
                                            <p v-if="line.type === 'symbolic-main'" class="text-base font-black tracking-wide leading-snug">{{ line.label }}</p>
                                            <p v-else-if="line.type === 'supplement'" class="text-sm font-bold leading-snug mt-0.5">{{ line.label }}</p>
                                            <p v-else-if="line.type === 'symbolic-menu'" class="text-sm font-black leading-snug mt-0.5">{{ line.label }}</p>
                                        </template>
                                        <p v-if="kitchenInstructionText(item)"
                                            class="text-xs leading-snug mt-1 whitespace-pre-wrap border-l-2 border-gray-500 pl-2 font-semibold">
                                            ** {{ kitchenInstructionText(item) }}
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <p class="text-[9px] text-center text-gray-500 mt-3 px-1">{{ $t('pos.receipt_kitchen_subtitle') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import print from "vue3-print-nb";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import ReceiptDuplicataMarker from "./ReceiptDuplicataMarker.vue";
import { sanitizeKdsInstruction } from "../../../helpers/kdsCustomization";
import { renderItemSymbolic, extraDisplayName } from "../../../helpers/kdsSymbolic";
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from "../../../helpers/posLocalPrinter";
import ReceiptRemboursementMarker from "./ReceiptRemboursementMarker.vue";
import {
    formatPaymentsBreakdown as buildPaymentLines,
    buildNf525Footer,
    receiptWidthClass as receiptPaperClass,
    normalizeReceiptVariations,
    normalizeReceiptExtras,
    normalizeReceiptAddons,
    receiptBranchHeader,
} from "../../../helpers/posReceiptBuilder";

export default {
    name: "ReceiptComponent",
    components: { ReceiptDuplicataMarker, ReceiptRemboursementMarker },
    props: {
        order: Object,
        // [iter15-mega-fix B-009 round-7 2026-05-10 — addendum]
        // Gate the cart reset behavior to the FRESH-PAYMENT receipt only.
        // PaymentComponent passes `true` because the receipt is acting as a
        // freeze-frame of the order that was just paid. The re-print path in
        // PosOrdersTrackerComponent leaves this `false` (default) so closing
        // a re-print preview does NOT silently destroy the active cashier
        // cart that may already be in progress on /admin/pos.
        clearCartOnClose: { type: Boolean, default: false },
    },
    data() {
        return {
            localPrintCount: null,
            // [PRINT-INSTANT 2026-07-06] Verrous PAR ticket (client / cuisine) : les deux
            // pipelines peuvent tourner EN PARALLÈLE (plus de série 10-25 s) et un
            // double-clic sur le même bouton reste dédupliqué.
            printingClient: false,
            printingKitchen: false,
            // Fallback navigateur = bouton MANUEL (jamais window.print auto).
            showBrowserPrintFallback: false,
            printObjClient: {
                id: "print-receipt-client",
                popTitle: "Ticket client",
            },
            printObjKitchen: {
                id: "print-receipt-kitchen",
                popTitle: "Ticket cuisine",
            },
            posPaymentMethodEnumArray: {
                [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
                [posPaymentMethodEnum.CARD]: this.$t("label.card"),
                [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
                [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
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
        // [PRINT-INSTANT 2026-07-06] L'ancien computed `silentPrintOnly` a disparu :
        // window.print() n'est plus JAMAIS automatique (quel que soit le flag), le
        // fallback navigateur est un bouton MANUEL explicite (manualBrowserPrint).
        // [BYPASS-P3] Lit window.foodkingConfig.bypassMode injecté par master.blade.php.
        bypassPrintingActive: function () {
            return !!(typeof window !== 'undefined' && window.foodkingConfig?.bypassMode?.printing);
        },
        bypassPrintingMarker: function () {
            return (typeof window !== 'undefined' && window.foodkingConfig?.bypassMode?.printingScreenMarker)
                || '🔧 MODE TEST — IMPRESSION BYPASSÉE';
        },
        // [RECEIPT-NO-AUTO 2026-07-24] Flag OPT-IN d'auto-impression du reçu CLIENT
        // (défaut FALSE). Spec owner : le reçu client ne s'imprime JAMAIS auto — il
        // reste imprimable à la demande (bouton « Ticket client » + réimpression
        // tracker). Clé absente (ex. config POS trimmée) → false → pas d'auto.
        autoPrintClientReceipt: function () {
            return !!(typeof window !== 'undefined' && window.foodkingConfig?.printing?.autoPrintClientReceipt);
        },
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
        paperWidthMm: function () {
            return 58;
        },
        receiptPaperRootClass: function () {
            return ['rounded-none', receiptPaperClass(this.paperWidthMm)];
        },
        paymentLines: function () {
            return buildPaymentLines(this.order);
        },
        nf525FooterLines: function () {
            return buildNf525Footer(this.order);
        },
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
        this.refreshBranchShowFromOrder();
        this.printObjClient.popTitle = this.$t("pos.print_ticket_client");
        this.printObjKitchen.popTitle = this.$t("pos.print_ticket_kitchen");
        // [AUTO-PRINT 2026-06-25] Garde anti-double + cas reçu déjà posé au montage.
        this._autoPrintedOrderId = null;
        this.maybeAutoPrintClient(this.order?.id);
    },
    watch: {
        'order.id': function (newId) {
            this.localPrintCount = Number(this.order?.receipt_print_count ?? 0);
            this.refreshBranchShowFromOrder();
            this.maybeAutoPrintClient(newId);
        },
    },
    methods: {
        reset: function () {
            appService.modalHide();
            // [iter15-mega-fix B-009 round-7 2026-05-10 — addendum]
            // Cart reset moved here from PaymentComponent.confirmOrderWithAuthRetry,
            // and now GATED by `clearCartOnClose`. Only the post-payment receipt
            // (PaymentComponent) opts into the reset. Re-print flows from
            // PosOrdersTrackerComponent leave this prop at its default `false`,
            // so closing a re-print preview can no longer destroy an active
            // cashier cart that's still being built on /admin/pos.
            if (this.clearCartOnClose) {
                try {
                    this.$store?.dispatch('posCart/resetCart')?.catch?.(() => {});
                } catch (_) { /* never block modal close on cart reset */ }
            }
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
        // [KITCHEN-SYMBOLS 2026-06-28] Symbolic kitchen lines (same engine as the KDS
        // screen + the ESC/POS kitchen ticket) — main line + supplements + MENU/F.
        kitchenSymbolicLines: function (item) {
            try {
                return renderItemSymbolic(item || {}).lines.filter(
                    (l) => l.type === 'symbolic-main' || l.type === 'supplement' || l.type === 'symbolic-menu'
                );
            } catch (e) {
                return [];
            }
        },
        receiptVariationsFor: function (item) {
            return normalizeReceiptVariations(item ? item.item_variations : []);
        },
        receiptExtrasFor: function (item) {
            const extras = normalizeReceiptExtras(item ? item.item_extras : []);
            if (!item) {
                return extras;
            }
            // [MULTISAUCE 2026-07-18] Name the generic "Sauce supplémentaire" line on the
            // customer receipt with the recovered sauce name(s) — parity with the payment
            // screen + the ESC/POS ticket. Price-neutral (line_total untouched).
            return extras.map((ex) => ({
                ...ex,
                name: extraDisplayName(ex.name, item.instruction),
            }));
        },
        // [G2-HEAL-03 / G.5 G5-F-002 P1 2026-05-23] Composer addons (kiosk
        // menu_formule bundled drinks/sides) rendered alongside variations
        // + extras on the customer ticket. NF525: line_total is the
        // ratio-adjusted price (NEVER catalog_price).
        receiptAddonsFor: function (item) {
            return normalizeReceiptAddons(item ? item.item_addons : []);
        },
        formatReceiptAddonPrice: function (amount) {
            const n = Number(amount) || 0;
            if (n <= 0) {
                return '';
            }
            const decimal = Number(this.$store?.getters?.['setting/lists']?.site_digit_after_decimal_point ?? 2);
            const symbol = String(this.$store?.getters?.['setting/lists']?.site_default_currency_symbol ?? '€');
            const position = Number(this.$store?.getters?.['setting/lists']?.site_currency_position ?? 0);
            // [HEAL-money-fr 2026-06-26] FR (ADR-007): comma decimal + NBSP
            // (U+00A0) between amount and symbol. Was "€7.90"/"7.90€" (US point,
            // glued symbol). Aligns the addon/extra upcharge with the rest of the
            // ticket (backend *_currency_price fields already render FR).
            const formatted = n.toFixed(Number.isFinite(decimal) ? decimal : 2).replace('.', ',');
            const NBSP = ' ';
            // 0 = LEFT (symbol first), else RIGHT (symbol after) — ticket-local convention
            return position === 0 ? `${symbol}${NBSP}${formatted}` : `${formatted}${NBSP}${symbol}`;
        },
        kitchenInstructionText: function (item) {
            // [PRINT-SAGA 2026-06-25] Le ticket CUISINE imprimé rend déjà la compo
            // structurée (variations/extras) au-dessus ; renvoyer l'instruction BRUTE
            // ré-imprimait nom+viande+sauce+supplément une 2e fois (doublage prouvé
            // par l'audit e2e). On assainit avec le MÊME helper SSOT que le KDS :
            // strip le préambule nom-produit + le blob compo, garde les extras uniques
            // (« ↳ Sauce frites », « + Menu », notes libres).
            return item ? sanitizeKdsInstruction(item.instruction, item.item_name) : '';
        },
        refreshBranchShowFromOrder: function () {
            const bid = this.order?.branch_id ?? this.order?.branch?.id;
            if (bid) {
                this.$store.dispatch('backendGlobalState/branchShow', bid).catch(() => {});
            }
        },
        /**
         * [AUTO-PRINT 2026-06-25] Impression AUTO du ticket client en fin de
         * commande (choix owner : 1 clic). Uniquement le reçu d'un encaissement
         * FRAIS (clearCartOnClose=true, posé par PaymentComponent) — les re-prints
         * depuis le tracker restent à clearCartOnClose=false → pas d'auto-print.
         * Garde anti-double : une seule fois par order id ; en plus
         * handlePrintClientClick a son verrou printingClient et le POST /print-receipt
         * est idempotency-keyed → aucun double-incrément NF525 receipt_print_count.
         */
        maybeAutoPrintClient: function (orderId) {
            // [RECEIPT-NO-AUTO 2026-07-24] L'auto-impression du reçu CLIENT est
            // désormais OPT-IN (flag `printing.auto_print_client_receipt`, défaut
            // FALSE) : par défaut le reçu ne s'imprime PLUS automatiquement (spec
            // owner). `clearCartOnClose` continue de distinguer l'encaissement FRAIS
            // du re-print tracker → quand le flag est réactivé (rétro-compat), seul
            // l'encaissement frais auto-imprime, jamais un re-print.
            if (!this.autoPrintClientReceipt || !this.clearCartOnClose || !orderId) {
                return;
            }
            if (this._autoPrintedOrderId === orderId) {
                return;
            }
            this._autoPrintedOrderId = orderId;
            this.$nextTick(() => {
                this.handlePrintClientClick();
            });
        },
        /**
         * Ticket client : incrément NF525 + audit via POST print-receipt.
         *
         * [PRINT-INSTANT 2026-07-06] FIRE-AND-FORGET : le clic toast immédiatement
         * « Ticket envoyé » et rend la main (0 await dans le handler) ; le pipeline
         * réel (POST fiscal → pont ESC/POS) tourne en async et toast son résultat.
         * L'UI n'est JAMAIS bloquée par l'impression (fini les ~20 s owner).
         */
        handlePrintClientClick() {
            if (this.printingClient) {
                return;
            }
            this.printingClient = true;
            alertService.success(this.$t('pos.ticket_sent'));
            // Volontairement non-await : résultat toasté en async par le pipeline.
            const p = this._printClientPipeline()
                .catch((e) => { console.warn('[ReceiptComponent] client print pipeline failed', e); })
                .finally(() => { this.printingClient = false; });
            this._lastClientPrintPromise = p; // test hook / observabilité
            return undefined;
        },
        /** Pipeline async du ticket CLIENT (POST fiscal + pont). Jamais window.print auto. */
        async _printClientPipeline() {
            this._printedThermally = false;
            {
                if (this.order?.id) {
                    try {
                        // [K-001 abuse-e2e] print-receipt is in idempotency.required_routes
                        // (config/idempotency.php); the middleware (enabled in prod per the §8
                        // AppServiceProvider boot guard) returns 422 MISSING_IDEMPOTENCY_KEY
                        // without this header → reprint/print was BROKEN in production (the UI
                        // route predated the 2026-05-18 required_routes addition). Fresh key per
                        // click so each intentional (re)print increments the counter; a same-click
                        // network-retry dedupes on the identical key.
                        const idempotencyKey = `pos-print-receipt-${this.order.id}-${Date.now()}`;
                        const { data } = await axios.post(
                            `admin/pos/orders/${this.order.id}/print-receipt`,
                            {},
                            { headers: { 'X-Idempotency-Key': idempotencyKey } }
                        );
                        this.localPrintCount = Number(
                            data?.receipt_print_count
                            ?? (Number(this.localPrintCount ?? 0) + 1)
                        );
                        // [PRINT-SAGA] server printed straight to the thermal printer?
                        this._printedThermally = data?.printed_escpos === true;
                        // [PS-4 audit heal 2026-05-18] NF525 audit-chain write
                        // is best-effort server-side (PosReceiptPrintController
                        // returns audit_emitted=false on chain failure). The
                        // print itself succeeds so the cashier can deliver the
                        // paper, but the manager MUST be alerted so SIEM /
                        // fiscal ops can investigate the gap. Pre-heal the UI
                        // silently swallowed this signal — see RED-PS4-005.
                        if (data?.audit_emitted === false) {
                            alertService.warning(this.$t('pos.receipt_audit_chain_warning'));
                        }
                    } catch (apiError) {
                        const status = apiError?.response?.status;
                        if (status === 403) {
                            alertService.warning(this.$t('pos.receipt_print_forbidden'));
                        } else if (status === 404) {
                            alertService.warning(this.$t('pos.receipt_print_not_found'));
                        } else if (status === 409) {
                            alertService.warning(this.$t('pos.receipt_print_conflict'));
                        } else {
                            alertService.warning(this.$t('pos.receipt_print_server_error'));
                        }
                        this.localPrintCount = Number(this.localPrintCount ?? 0) + 1;
                        console.warn('[ReceiptComponent] increment API failed, printing anyway', apiError);
                    }
                }

                // [PRINT-SAGA] If the server already printed the ticket directly on
                // the thermal printer (e.g. USB SAGA), skip the local bridge to avoid a
                // double ticket.
                if (this._printedThermally) {
                    alertService.success(`${this.$t('pos.print_ticket_client')} ✓`);
                } else {
                    // [CAISSE-BRIDGE 2026-06-28] Pont local caisse (SILENCIEUX, sans
                    // fenêtre) : le serveur cloud ne joint pas l'USB SAGA, donc on
                    // récupère les octets ESC/POS rendus serveur et on les POSTe au pont.
                    // [PRINT-INSTANT 2026-07-06] window.print n'est PLUS un fallback auto :
                    // pont absent/échec → erreur claire + bouton navigateur MANUEL.
                    const bridgeRes = await this.tryCaisseBridge('client');
                    if (bridgeRes === 'printed') {
                        alertService.success(`${this.$t('pos.print_ticket_client')} ✓`);
                    } else if (bridgeRes === 'no-bridge') {
                        alertService.error(this.$t('pos.print_bridge_offline'));
                        this.showBrowserPrintFallback = true;
                    } else {
                        // Pont présent mais échec → erreur claire, JAMAIS la page grise auto.
                        alertService.error(this.$t('pos.reprint_error'));
                        this.showBrowserPrintFallback = true;
                    }
                }
            }
        },
        /**
         * [CAISSE-BRIDGE 2026-06-28] Tente l'impression SILENCIEUSE via le pont local
         * caisse : récupère les octets ESC/POS rendus serveur (SSOT NF525) puis les POSTe
         * au pont 127.0.0.1:9100/raw. Renvoie true si imprimé (→ pas de window.print).
         * Jamais throw ; si pont absent/échec → false → fallback window.print.
         */
        async tryCaisseBridge(ticket) {
            // [HARDWARE-HARDENING 2026-07-03] Renvoie un STATUT (plus un simple booléen) pour
            // distinguer 2 cas au retour :
            //   'printed'   → imprimé sur le SAGA (rien d'autre à faire).
            //   'failed'    → le pont est LÀ (health OK) mais l'impression a échoué (mauvais nom
            //                 d'imprimante, USB débranché, octets absents) → on affiche une ERREUR
            //                 claire et on NE retombe PAS sur window.print (fini la page grise + URL).
            //   'no-bridge' → aucun pont (health KO) = machine dev/navigateur → window.print autorisé.
            try {
                if (!this.order?.id) return 'no-bridge';
                if (!(await isCaisseBridgeAvailable())) return 'no-bridge';
                const { data } = await axios.get(
                    `admin/pos/orders/${this.order.id}/escpos`,
                    { params: { ticket } }
                );
                if (!data?.escpos_b64) return 'failed';
                const r = await printEscPosViaCaisseBridge(data.escpos_b64, { orderRef: this.order.id });
                return (r && r.ok) ? 'printed' : 'failed';
            } catch (_) {
                // Health a réussi mais une étape a jeté (serveur/pont) → pont présent = 'failed',
                // pas 'no-bridge' → on ne dégrade PAS vers la page grise du navigateur.
                return 'failed';
            }
        },
        /**
         * [PRINT-INSTANT 2026-07-06] Fallback navigateur = action MANUELLE explicite
         * (bouton visible quand le pont est injoignable). window.print() n'est plus
         * JAMAIS déclenché automatiquement — plus d'écran gris surprise. Le caissier
         * clique lui-même s'il veut l'aperçu navigateur.
         */
        async manualBrowserPrint(ticket) {
            await this.$nextTick();
            const refName = ticket === 'kitchen' ? 'hiddenPrintKitchenButton' : 'hiddenPrintClientButton';
            const trigger = this.$refs[refName];
            if (trigger && typeof trigger.click === 'function') {
                trigger.click();
            } else if (typeof window !== 'undefined' && typeof window.print === 'function') {
                window.print();
            }
        },
        /**
         * Ticket cuisine : pas d’appel fiscal — bon de préparation uniquement.
         * [PRINT-INSTANT 2026-07-06] Fire-and-forget, en PARALLÈLE du ticket client
         * (verrou séparé) — plus de série 10-25 s.
         */
        handlePrintKitchenClick() {
            if (this.printingKitchen) {
                return;
            }
            this.printingKitchen = true;
            alertService.success(this.$t('pos.ticket_sent'));
            const p = this._printKitchenPipeline()
                .catch((e) => { console.warn('[ReceiptComponent] kitchen print pipeline failed', e); })
                .finally(() => { this.printingKitchen = false; });
            this._lastKitchenPrintPromise = p; // test hook / observabilité
            return undefined;
        },
        /** Pipeline async du ticket CUISINE. Jamais window.print auto. */
        async _printKitchenPipeline() {
            let printedThermally = false;
            if (this.order?.id) {
                try {
                    const idempotencyKey = `pos-print-kitchen-${this.order.id}-${Date.now()}`;
                    const { data } = await axios.post(
                        `admin/pos/orders/${this.order.id}/print-kitchen`,
                        {},
                        { headers: { 'X-Idempotency-Key': idempotencyKey } }
                    );
                    printedThermally = data?.printed_escpos === true;
                } catch (e) {
                    console.warn('[ReceiptComponent] kitchen thermal print failed, printing anyway', e);
                }
            }
            if (printedThermally) {
                alertService.success(`${this.$t('pos.print_ticket_kitchen')} ✓`);
            } else {
                // [CAISSE-BRIDGE 2026-06-28] pont local silencieux — jamais window.print auto.
                const bridgeRes = await this.tryCaisseBridge('kitchen');
                if (bridgeRes === 'printed') {
                    alertService.success(`${this.$t('pos.print_ticket_kitchen')} ✓`);
                } else if (bridgeRes === 'no-bridge') {
                    alertService.error(this.$t('pos.print_bridge_offline'));
                    this.showBrowserPrintFallback = true;
                } else {
                    // [HARDWARE-HARDENING 2026-07-03] Pont là mais échec → erreur, pas de page grise.
                    alertService.error(this.$t('pos.reprint_error'));
                    this.showBrowserPrintFallback = true;
                }
            }
        },
    },
    directives: {
        print
    },
}
</script>
<style scoped>
/* =============================================================================
   ReceiptComponent — POS V5 chrome refonte (paper print intact pour fiscal NF525)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.4
   ============================================================================= */

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
    .hidden-print { display: none !important; }
    .receipt-58mm { width: 58mm !important; }
    .receipt-80mm { width: 80mm !important; }
    /* [TICKET-BIG 2026-06-30] Owner : écriture grande & lisible (~+30%) au window.print()
       de secours. On agrandit toute la police des 2 tickets ; la largeur 80mm est gardée. */
    #print-receipt-client,
    #print-receipt-kitchen {
        font-size: 130% !important;
        line-height: 1.35 !important;
    }
}

/* =============================================================================
   POS V5 Receipt modal chrome
   ============================================================================= */
.pos-v5-receipt-modal :deep(.modal-dialog) {
    border-radius: var(--pos-v5-radius-xl) !important;
    box-shadow: var(--pos-v5-shadow-modal) !important;
    background: var(--pos-v5-bg-panel) !important;
    border: 1px solid var(--pos-v5-border) !important;
    overflow: hidden;
}

.pos-v5-receipt-toolbar {
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4) !important;
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%) !important;
    border-bottom: 1px solid var(--pos-v5-border) !important;
}

.pos-v5-receipt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: var(--pos-v5-radius-md);
    border: 1px solid transparent;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-caption);
    font-weight: var(--pos-v5-weight-bold);
    cursor: pointer;
    appearance: none;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
    min-height: 36px;
}
.pos-v5-receipt-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.pos-v5-receipt-btn:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

/* Bouton "Fermer" — ghost discret */
.pos-v5-receipt-btn--ghost {
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    border-color: var(--pos-v5-border);
}
.pos-v5-receipt-btn--ghost:hover:not(:disabled) {
    background: var(--pos-v5-bg-panel);
    border-color: var(--pos-v5-border-strong);
    color: var(--pos-v5-ink);
}

/* Bouton "Ticket cuisine" — inversé sombre warm (signal différenciation) */
.pos-v5-receipt-btn--kitchen {
    background: var(--pos-v5-bg-strong);
    color: var(--pos-v5-ink-on-dark);
}
.pos-v5-receipt-btn--kitchen:hover:not(:disabled) {
    background: #2d2d2d;
}

/* Bouton "Ticket client" — success vibrant (action principale) */
.pos-v5-receipt-btn--client {
    background: var(--pos-v5-success);
    color: var(--pos-v5-ink-on-dark);
    box-shadow: var(--pos-v5-shadow-success);
}
.pos-v5-receipt-btn--client:hover:not(:disabled) {
    background: var(--pos-v5-success-dark);
}
</style>
