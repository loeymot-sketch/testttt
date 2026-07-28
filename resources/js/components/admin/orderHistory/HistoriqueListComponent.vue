<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <CaisseSecondaryNav current="historique" />
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.historique') }}</h3>
                <div class="db-card-filter">
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('historique-filter')" />
                </div>
            </div>

            <div class="table-filter-div" id="historique-filter">
                <form class="p-4 sm:p-5 mb-5" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="order_id" class="db-field-title after:hidden">{{ $t('label.order_id') }}</label>
                            <input id="order_id" v-model="props.search.order_serial_no" type="text"
                                class="db-field-control">
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchOrigin" class="db-field-title after:hidden">{{ $t('label.origin') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchOrigin"
                                v-model="props.origin" :options="originOptions" label-by="name" value-by="id"
                                :closeOnSelect="true" :searchable="false" :clearOnClose="true" placeholder="--"
                                search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{ $t('label.status') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.status" :options="[
                                    { id: enums.orderStatusEnum.ACCEPT, name: $t('label.accept') },
                                    { id: enums.orderStatusEnum.PREPARING, name: $t('label.preparing') },
                                    { id: enums.orderStatusEnum.PREPARED, name: $t('label.prepared') },
                                    { id: enums.orderStatusEnum.DELIVERED, name: $t('label.delivered') },
                                    { id: enums.orderStatusEnum.CANCELED, name: $t('label.canceled') },
                                    { id: enums.orderStatusEnum.REJECTED, name: $t('label.rejected') },
                                    { id: enums.orderStatusEnum.RETURNED, name: $t('label.returned') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchPayment" class="db-field-title after:hidden">{{ $t('label.payment_status') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchPayment"
                                v-model="props.search.payment_status" :options="[
                                    { id: enums.paymentStatusEnum.PAID, name: $t('label.paid') },
                                    { id: enums.paymentStatusEnum.UNPAID, name: $t('label.unpaid') },
                                    { id: enums.paymentStatusEnum.PENDING_COUNTER, name: $t('label.pending_counter') },
                                    { id: enums.paymentStatusEnum.REFUNDED, name: $t('label.refunded') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="dp-input-searchStartDate" class="db-field-title after:hidden">{{ $t('label.date') }}</label>
                            <Datepicker uid="searchStartDate" name="searchStartDate" hideInputIcon autoApply
                                :enableTimePicker="false" utc="false" @update:modelValue="handleDate"
                                v-model="props.form.date" range :preset-ranges="presetRanges"
                                :aria-labels="{ input: $t('label.date') }">
                                <template #yearly="{ label, range, presetDateRange }">
                                    <span @click="presetDateRange(range)">{{ label }}</span>
                                </template>
                            </Datepicker>
                        </div>

                        <div class="col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-search-line lab-font-size-16"></i>
                                    <span>{{ $t('button.search') }}</span>
                                </button>
                                <button class="db-btn py-2 text-white bg-gray-600" @click="clear">
                                    <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                                    <span>{{ $t('button.clear') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="db-table-responsive">
                <table class="db-table stripe" :dir="direction">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.order_id') }}</th>
                            <th class="db-table-head-th">{{ $t('label.origin') }}</th>
                            <th class="db-table-head-th">{{ $t('label.queue_number') }}</th>
                            <th class="db-table-head-th">{{ $t('label.customer') }}</th>
                            <th class="db-table-head-th">{{ $t('label.amount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.payment') }}</th>
                            <th class="db-table-head-th">{{ $t('label.fiscal_number') }}</th>
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th">{{ $t('label.status') }}</th>
                            <th class="db-table-head-th" v-if="permissionChecker('pos-orders')">{{ $t('label.action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="orders.length > 0">
                        <tr class="db-table-body-tr" v-for="order in orders" :key="order.id">
                            <td class="db-table-body-td">{{ order.order_serial_no }}</td>
                            <td class="db-table-body-td">
                                <span class="hist-origin-badge" :class="originBadge(order).cls">
                                    {{ originBadge(order).label }}
                                </span>
                            </td>
                            <td class="db-table-body-td">
                                <span v-if="order.queue_number" class="pos-order-queue-chip">N°{{ order.queue_number }}</span>
                                <span v-else class="text-[#6E7191]">—</span>
                            </td>
                            <td class="db-table-body-td">{{ order.customer_name || '—' }}</td>
                            <td class="db-table-body-td">{{ formatPrice(order.total) }}</td>
                            <td class="db-table-body-td">
                                <span class="hist-pay-badge" :class="paymentBadgeClass(order.payment_status)">
                                    {{ paymentLabel(order.payment_status) }}
                                </span>
                                <span v-if="order.parent_order_id" class="hist-refund-tag" :title="$t('label.refunded')">
                                    ↩ #{{ order.parent_order_id }}
                                </span>
                            </td>
                            <td class="db-table-body-td">
                                <span v-if="order.fiscal_sequence_no" class="hist-fiscal-chip">{{ order.fiscal_sequence_no }}</span>
                                <span v-else class="text-[#6E7191]">—</span>
                            </td>
                            <td class="db-table-body-td">{{ order.order_datetime }}</td>
                            <td class="db-table-body-td">
                                <span :class="orderStatusClass(order.status)">
                                    {{ enums.orderStatusEnumArray[order.status] || order.status_name }}
                                </span>
                            </td>
                            <td class="db-table-body-td" v-if="permissionChecker('pos-orders')">
                                <div class="flex justify-start items-center gap-1.5">
                                    <SmIconViewComponent :link="'admin.pos-orders.show'" :id="order.id" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="10">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full" :src="ENV.API_URL + '/images/default/not-found.png'" alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="orders.length > 0">
                <PaginationSMBox :pagination="pagination" :method="list" />
                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <PaginationTextComponent :props="{ page: paginationPage }" />
                    <PaginationBox :pagination="pagination" :method="list" />
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import LoadingComponent from "../components/LoadingComponent";
import CaisseSecondaryNav from "../pos/CaisseSecondaryNav.vue";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import paymentStatusEnum from "../../../enums/modules/paymentStatusEnum";
import SourceEnum from "../../../enums/modules/sourceEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import SmIconViewComponent from "../components/buttons/SmIconViewComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { ref } from 'vue';
import { endOfMonth, endOfYear, startOfMonth, startOfYear, subMonths } from 'date-fns';
import statusEnum from "../../../enums/modules/statusEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import ENV from "../../../config/env";
import { adminPriceMixin } from "../../../helpers/formatPrice";

export default {
    name: "HistoriqueListComponent",
    mixins: [adminPriceMixin],
    components: {
        CaisseSecondaryNav,
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmIconViewComponent,
        FilterComponent,
        Datepicker
    },
    setup() {
        const date = ref();
        const presetRanges = ref([
            { label: 'Aujourd’hui', range: [new Date(), new Date()] },
            { label: 'Ce mois', range: [startOfMonth(new Date()), endOfMonth(new Date())] },
            { label: 'Mois dernier', range: [startOfMonth(subMonths(new Date(), 1)), endOfMonth(subMonths(new Date(), 1))] },
            { label: 'Cette année', range: [startOfYear(new Date()), endOfYear(new Date())] },
            { label: 'Cette année', range: [startOfYear(new Date()), endOfYear(new Date())], slot: 'yearly' },
        ]);
        return { date, presetRanges };
    },
    data() {
        return {
            loading: { isActive: false },
            enums: {
                orderStatusEnum: orderStatusEnum,
                orderTypeEnum: orderTypeEnum,
                paymentStatusEnum: paymentStatusEnum,
                orderStatusEnumArray: {
                    [orderStatusEnum.ACCEPT]: this.$t("label.accept"),
                    [orderStatusEnum.PREPARING]: this.$t("label.preparing"),
                    [orderStatusEnum.PREPARED]: this.$t("label.prepared"),
                    [orderStatusEnum.DELIVERED]: this.$t("label.delivered"),
                    [orderStatusEnum.RETURNED]: this.$t("label.returned")
                },
            },
            props: {
                // Origin filter is a UI concept mapped onto backend filters
                // (source_surface for Borne/Caisse/En ligne, order_type for
                // Livraison) on search().
                origin: null,
                form: { date: null },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_by: "desc",
                    order_serial_no: "",
                    user_id: null,
                    status: null,
                    payment_status: null,
                    source_surface: null,
                    order_type: null,
                    from_date: "",
                    to_date: "",
                }
            },
            ENV: ENV
        }
    },
    mounted() {
        this.list();
    },
    computed: {
        orders: function () {
            return this.$store.getters['orderHistory/lists'];
        },
        pagination: function () {
            return this.$store.getters['orderHistory/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['orderHistory/page'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        originOptions: function () {
            return [
                { id: 'kiosk', name: this.$t('label.kiosk') },
                { id: 'pos', name: this.$t('label.caisse') },
                { id: 'online', name: this.$t('label.online') },
                { id: 'delivery', name: this.$t('label.delivery') },
            ];
        },
    },
    methods: {
        permissionChecker(e) {
            return appService.permissionChecker(e);
        },
        orderStatusClass: function (status) {
            return appService.orderStatusClass(status);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        // Origin resolver — source_surface is the reliable signal; delivery
        // type overrides (a delivery can originate from any surface).
        originBadge: function (order) {
            const surface = String(order.source_surface || '').toLowerCase();
            const type = parseInt(order.order_type);
            if (type === orderTypeEnum.DELIVERY) {
                return { label: this.$t('label.delivery'), cls: 'origin-delivery' };
            }
            if (surface === 'kiosk' || type === orderTypeEnum.KIOSK) {
                return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
            }
            if (surface === 'pos' || type === orderTypeEnum.POS) {
                return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            }
            if (surface === 'web' || surface === 'app' || surface === 'mobile') {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            // [abuse-e2e P3 heal 2026-05-30] Fallback on the legacy `source` column
            // when source_surface is empty/NULL (older orders predate the surface
            // tag) so they are not all blanket-badged "En ligne": Source POS=Caisse,
            // WEB/APP=En ligne. Unknown/dirty source values keep the En ligne default.
            const src = parseInt(order.source);
            if (src === SourceEnum.POS) {
                return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            }
            if (src === SourceEnum.WEB || src === SourceEnum.APP) {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            return { label: this.$t('label.online'), cls: 'origin-online' };
        },
        paymentLabel: function (ps) {
            switch (parseInt(ps)) {
                case paymentStatusEnum.PAID: return this.$t('label.paid');
                case paymentStatusEnum.UNPAID: return this.$t('label.unpaid');
                case paymentStatusEnum.PENDING_COUNTER: return this.$t('label.pending_counter');
                case paymentStatusEnum.REFUNDED: return this.$t('label.refunded');
                default: return '—';
            }
        },
        paymentBadgeClass: function (ps) {
            switch (parseInt(ps)) {
                case paymentStatusEnum.PAID: return 'pay-paid';
                case paymentStatusEnum.PENDING_COUNTER: return 'pay-pending';
                case paymentStatusEnum.REFUNDED: return 'pay-refunded';
                default: return 'pay-unpaid';
            }
        },
        // Translate the origin filter selection into backend filters.
        applyOriginFilter: function () {
            this.props.search.source_surface = null;
            this.props.search.order_type = null;
            switch (this.props.origin) {
                case 'kiosk': this.props.search.source_surface = 'kiosk'; break;
                case 'pos': this.props.search.source_surface = 'pos'; break;
                case 'online': this.props.search.source_surface = 'web'; break;
                case 'delivery': this.props.search.order_type = orderTypeEnum.DELIVERY; break;
            }
        },
        search: function () {
            this.applyOriginFilter();
            this.list();
        },
        handleDate: function (e) {
            if (e) {
                this.props.search.from_date = e[0];
                this.props.search.to_date = e[1];
            } else {
                this.props.form.date = null;
                this.props.search.from_date = null;
                this.props.search.to_date = null;
            }
        },
        clear: function () {
            this.props.origin = null;
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.order_by = "desc";
            this.props.search.order_serial_no = "";
            this.props.search.status = null;
            this.props.search.payment_status = null;
            this.props.search.source_surface = null;
            this.props.search.order_type = null;
            this.props.search.from_date = "";
            this.props.search.to_date = "";
            this.props.form.date = null;
            this.list();
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('orderHistory/lists', this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
    }
}
</script>

<style scoped>
:deep(.db-card) {
    border-radius: var(--pos-v5-radius-lg);
    box-shadow: var(--pos-v5-shadow-md);
    border: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
    overflow: hidden;
}
:deep(.db-card-header) {
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
    border-bottom: 1px solid var(--pos-v5-border);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
}
:deep(.db-card-title) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    letter-spacing: var(--pos-v5-tracking-tight);
}
:deep(.db-table-head) { background: var(--pos-v5-bg-subtle); }
:deep(.db-table-head-th) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
}
:deep(.db-table-body-tr) { transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard); }
:deep(.db-table-body-tr:hover) { background: var(--pos-v5-brand-red-faint); }
:deep(.db-table-body-td) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}
.pos-order-queue-chip {
    display: inline-flex;
    min-height: 2rem;
    align-items: center;
    border: 1px solid #fed7aa;
    border-radius: 9999px;
    background: #fff7ed;
    color: #9a3412;
    font-weight: var(--pos-v5-weight-extrabold);
    padding: 0.2rem 0.65rem;
    white-space: nowrap;
}

/* Origin badge — V1 Le Cayenne origin colors */
.hist-origin-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.15rem 0.6rem;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
}
.origin-borne { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.origin-caisse { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.origin-online { background: #f5f3ff; color: #5b21b6; border-color: #ddd6fe; }
.origin-delivery { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }

/* Payment badge */
.hist-pay-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.12rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}
.pay-paid { background: #ecfdf5; color: #065f46; }
.pay-pending { background: #fffbeb; color: #92400e; }
.pay-unpaid { background: #fef2f2; color: #991b1b; }
.pay-refunded { background: #f1f5f9; color: #475569; }
.hist-refund-tag { margin-left: 0.35rem; font-size: 0.72rem; color: #475569; font-weight: 600; }
.hist-fiscal-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 0.4rem;
    padding: 0.1rem 0.45rem;
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

:deep(.db-btn.bg-primary) {
    background: var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-bold);
}
:deep(.db-btn.bg-primary:hover) { background: var(--pos-v5-brand-red-dark) !important; }
:deep(.db-btn.bg-gray-600) {
    background: var(--pos-v5-bg-subtle) !important;
    color: var(--pos-v5-ink) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-semibold);
}
:deep(.db-btn.bg-gray-600:hover) { background: var(--pos-v5-border-strong) !important; }
</style>
