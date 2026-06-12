<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.sales_report') }}</h3>
                <div class="db-card-filter">
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('sales-report-filter')" />
                    <div class="dropdown-group">
                        <ExportComponent />
                        <div
                            class="dropdown-list db-card-filter-dropdown-list transition-all duration-300 scale-y-0 origin-top">
                            <PrintComponent :props="printObj" />
                            <ExcelComponent :method="xls" />
                            <PdfComponent :method="pdf" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-filter-div" id="sales-report-filter">
                <form class="p-4 sm:p-5 mb-5" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="order_id" class="db-field-title after:hidden">{{ $t('label.order_id') }}</label>
                            <input id="order_id" v-model="props.search.order_serial_no" type="text"
                                class="db-field-control">
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{
                                $t('label.status')
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.status" :options="[
                                    { id: enums.orderStatusEnum.PENDING, name: $t('label.pending') },
                                    { id: enums.orderStatusEnum.ACCEPT, name: $t('label.accept') },
                                    { id: enums.orderStatusEnum.PREPARING, name: $t('label.preparing') },
                                    { id: enums.orderStatusEnum.PREPARED, name: $t('label.prepared') },
                                    { id: enums.orderStatusEnum.OUT_FOR_DELIVERY, name: $t('label.out_for_delivery') },
                                    { id: enums.orderStatusEnum.DELIVERED, name: $t('label.delivered') },
                                    { id: enums.orderStatusEnum.CANCELED, name: $t('label.canceled') },
                                    { id: enums.orderStatusEnum.REJECTED, name: $t('label.rejected') },
                                    { id: enums.orderStatusEnum.RETURNED, name: $t('label.returned') }
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStartDate" class="db-field-title after:hidden">{{
                                $t('label.date')
                            }}</label>
                            <Datepicker hideInputIcon autoApply :enableTimePicker="false" locale="fr" format="dd/MM/yyyy" utc="false"
                                @update:modelValue="handleDate" v-model="props.form.date" range
                                :preset-ranges="presetRanges">
                                <template #yearly="{ label, range, presetDateRange }">
                                    <span @click="presetDateRange(range)">{{ label }}</span>
                                </template>
                            </Datepicker>
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{
                                $t('label.paid_status')
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.payment_status" :options="[
                                    { id: enums.paymentStatusEnum.PAID, name: $t('label.paid') },
                                    { id: enums.paymentStatusEnum.UNPAID, name: $t('label.unpaid') }
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{
                                $t('label.payment_type')
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.payment_method" :options="paymentGateways" label-by="name"
                                value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="delivery_boy_id" class="db-field-title">{{
                                $t("label.delivery_boy")
                            }}</label>

                            <vue-select class="db-field-control f-b-custom-select" id="site_default_branch"
                                v-model="props.search.delivery_boy_id" :options="deliveryBoys" label-by="name"
                                value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="source" class="db-field-title after:hidden">{{
                                $t('label.source')
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="source"
                                v-model="props.search.source" :options="enums.sourceObject" label-by="name"
                                value-by="value" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
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


            <div class="row px-5 mt-5 mb-5">
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-total-orders text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">
                                {{ $t('label.total_orders') }}
                            </h3>
                            <h4 class="font-bold text-lg text-[#6E7191]"> {{ salesReportOverview.total_orders }} </h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-total-sale text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{
                                $t('label.total_earnings') }}
                            </h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ salesReportOverview.total_earnings }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-fill-ticket-discount text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">{{
                                $t('label.total_discounts')
                            }}
                            </h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ salesReportOverview.total_discounts }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
                    <div class="border flex items-center gap-4 p-4 rounded-lg">
                        <div class="bg-[#F7F7F7] w-12 h-12 rounded-full flex items-center justify-center">
                            <i class="lab-fill-moneys text-[#6E7191] text-2xl lab-font-size-24"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-sm capitalize tracking-wide mb-1">
                                {{ $t('label.total_delivery_charges') }}
                            </h3>
                            <h4 class="font-bold text-lg text-[#6E7191]">{{ salesReportOverview.total_delivery_charges
                            }}
                            </h4>
                        </div>
                    </div>
                </div>
            </div>



            <div class="db-table-responsive">
                <table class="db-table stripe" id="print" :dir="direction">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.order_id') }}</th>
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th">{{ $t('label.total') }}</th>
                            <th class="db-table-head-th">{{ $t('label.discount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.delivery_charge') }}</th>
                            <th class="db-table-head-th">{{ $t('label.payment_type') }}</th>
                            <th class="db-table-head-th">{{ $t('label.payment_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="salesReports.length > 0">
                        <tr class="db-table-body-tr" v-for="salesReport in salesReports" :key="salesReport.id">
                            <td class="db-table-body-td">{{ salesReport.order_serial_no }}</td>
                            <td class="db-table-body-td">{{ salesReport.order_datetime }}</td>
                            <td class="db-table-body-td">{{ salesReport.total_currency_price }}</td>
                            <td class="db-table-body-td">{{ salesReport.discount_currency_price }}</td>
                            <td class="db-table-body-td">{{ salesReport.delivery_charge_currency_price }}</td>
                            <td class="db-table-body-td">
                                <!-- [REP-SALES-PAYTYPE-02 FIX] / [REP-SALES-ENUM-05 FIX] -->
                                <span>{{ paymentTypeLabel(salesReport) }}</span>
                            </td>
                            <td class="db-table-body-td">
                                <span :class="statusClass(salesReport.payment_status)">
                                    {{ enums.paymentStatusEnumArray[salesReport.payment_status] }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="7">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full" :src="ENV.API_URL + '/images/default/not-found.png'"
                                            alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
                v-if="salesReports.length > 0">
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
import alertService from "../../../services/alertService";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import paymentStatusEnum from "../../../enums/modules/paymentStatusEnum";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import paymentTypeEnum from "../../../enums/modules/paymentTypeEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import ExportComponent from "../components/buttons/export/ExportComponent";
import print from 'vue3-print-nb';
import PrintComponent from "../components/buttons/export/PrintComponent";
import ExcelComponent from "../components/buttons/export/ExcelComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { ref } from 'vue';
import { endOfMonth, endOfYear, startOfMonth, startOfYear, subMonths } from 'date-fns';
import SmIconViewComponent from "../components/buttons/SmIconViewComponent";
import statusEnum from "../../../enums/modules/statusEnum";
import sourceEnum from "../../../enums/modules/sourceEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import PdfComponent from "../components/buttons/export/PdfComponent";
import ENV from "../../../config/env";

export default {
    name: "SalesReportListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        ExportComponent,
        FilterComponent,
        PrintComponent,
        ExcelComponent,
        Datepicker,
        SmIconViewComponent,
        PdfComponent,
    },
    setup() {
        const date = ref();

        const presetRanges = ref([
            { label: 'Today', range: [new Date(), new Date()] },
            { label: 'This month', range: [startOfMonth(new Date()), endOfMonth(new Date())] },
            {
                label: 'Last month',
                range: [startOfMonth(subMonths(new Date(), 1)), endOfMonth(subMonths(new Date(), 1))],
            },
            { label: 'This year', range: [startOfYear(new Date()), endOfYear(new Date())] },
            {
                label: 'This year (slot)',
                range: [startOfYear(new Date()), endOfYear(new Date())],
                slot: 'yearly',
            },
        ]);

        return {
            date,
            presetRanges,
        }
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                paymentStatusEnum: paymentStatusEnum,
                paymentTypeEnum: paymentTypeEnum,
                orderStatusEnum: orderStatusEnum,
                orderTypeEnum: orderTypeEnum,
                sourceEnum: sourceEnum,
                paymentStatusEnumArray: {
                    [paymentStatusEnum.PAID]: this.$t("label.paid"),
                    [paymentStatusEnum.UNPAID]: this.$t("label.unpaid"),
                    // [REP-SALES-STATUS-01 FIX] Was blank for PENDING_COUNTER + REFUNDED.
                    [paymentStatusEnum.PENDING_COUNTER]: this.$t("label.pending_counter"),
                    [paymentStatusEnum.REFUNDED]: this.$t("label.refunded")
                },
                paymentTypeEnumArray: {
                    [paymentTypeEnum.CASH_ON_DELIVERY]: this.$t("label.cash_on_delivery"),
                    [paymentTypeEnum.E_WALLET]: this.$t("label.e_wallet"),
                    [paymentTypeEnum.PAYPAL]: this.$t("label.paypal"),
                    // [REP-SALES-PAYTYPE-02 FIX 2026-06-07] kiosk card/TR orders (Plan-B,
                    // pos_payment_method not yet set) were blank — gateway CARD=4 / TR=5.
                    [paymentTypeEnum.CARD]: this.$t("label.card"),
                    [paymentTypeEnum.TICKET_RESTAURANT]: this.$t("label.ticket_restaurant")
                },
                posPaymentMethodEnumArray: {
                    [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
                    [posPaymentMethodEnum.CARD]: this.$t("label.card"),
                    [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
                    [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
                    // [REP-SALES-PAYTYPE-02 FIX] Was blank for TR + counter-deferred kiosk orders.
                    [posPaymentMethodEnum.TICKET_RESTAURANT]: this.$t("label.ticket_restaurant"),
                    [posPaymentMethodEnum.COUNTER_DEFERRED]: this.$t("label.counter_deferred"),
                },
                orderStatusEnumArray: {
                    [orderStatusEnum.PENDING]: this.$t("label.pending"),
                    [orderStatusEnum.ACCEPT]: this.$t("label.accept"),
                    [orderStatusEnum.PREPARING]: this.$t("label.preparing"),
                    [orderStatusEnum.OUT_FOR_DELIVERY]: this.$t("label.out_for_delivery"),
                    [orderStatusEnum.DELIVERED]: this.$t("label.delivered"),
                    [orderStatusEnum.CANCELED]: this.$t("label.canceled"),
                    [orderStatusEnum.REJECTED]: this.$t("label.rejected"),
                    [orderStatusEnum.RETURNED]: this.$t("label.returned")
                },
                sourceObject: [
                    {
                        name: this.$t("label.web"),
                        value: sourceEnum.WEB,
                    },
                    {
                        name: this.$t("label.app"),
                        value: sourceEnum.APP,
                    },
                    {
                        name: this.$t("label.pos"),
                        value: sourceEnum.POS,
                    },
                ],
            },
            paymentGateways: [],
            printLoading: true,
            printObj: {
                id: "print",
                popTitle: this.$t('menu.sales_report')
            },
            props: {
                form: {
                    date: null,
                },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    payment_status: null,
                    payment_method: null,
                    order_serial_no: "",
                    status: null,
                    from_date: "",
                    to_date: "",
                    delivery_boy_id: null,
                    source: null,
                }
            },
            ENV: ENV
        }
    },
    mounted() {
        this.list();
        this.$store.dispatch('deliveryBoy/lists', {
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });
        this.$store.dispatch("paymentGateway/lists", {
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        }).then((res) => {
            this.paymentGateways = res.data.data;
            this.paymentGateways = [
                ...this.paymentGateways,
                { id: - posPaymentMethodEnum.CARD, name: this.$t("label.card") },
                { id: - posPaymentMethodEnum.CASH, name: this.$t("label.cash") },
                { id: - posPaymentMethodEnum.MOBILE_BANKING, name: this.$t("label.mobile_banking") },
                { id: - posPaymentMethodEnum.OTHER, name: this.$t("label.other") }
            ];
        })
    },
    computed: {
        salesReports: function () {
            return this.$store.getters['salesReport/lists'];
        },
        deliveryBoys: function () {
            return this.$store.getters['deliveryBoy/lists'];
        },
        pagination: function () {
            return this.$store.getters['salesReport/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['salesReport/page'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        salesReportOverview: function () {
            return this.$store.getters['salesReport/salesReportOverview'];
        }
    },
    methods: {
        // [REP-EXP-01 FIX] Build an export payload from the active filters with
        // pagination STRIPPED so the backend returns the FULL filtered dataset
        // (Excel/PDF). The on-screen list keeps its own paginated props.search.
        exportSearch: function () {
            const params = { ...this.props.search };
            params.paginate = 0;
            delete params.per_page;
            delete params.page;
            return params;
        },
        // [REP-EXP-ERR-04 FIX] Export responses use responseType:'blob'; a failed
        // export therefore arrives as a Blob whose `.message` is undefined. Decode
        // the Blob body to text/JSON before surfacing the error.
        showExportError: async function (err) {
            let message = this.$t('message.no_data_available');
            try {
                const data = err?.response?.data;
                if (data instanceof Blob) {
                    const text = await data.text();
                    try {
                        message = JSON.parse(text).message || text || message;
                    } catch (e) {
                        message = text || message;
                    }
                } else if (data?.message) {
                    message = data.message;
                }
            } catch (e) { /* fall back to default message */ }
            alertService.error(message);
        },
        // [REP-SALES-PAYTYPE-02 FIX] / [REP-SALES-ENUM-05 FIX] Accurate payment-type
        // label. A real gateway transaction wins; otherwise prefer the POS/counter
        // method (kiosk pay-at-counter sets pos_payment_method=COUNTER_DEFERRED even
        // though source≠POS — the old `source === orderTypeEnum.POS` guard compared
        // the wrong enum AND mislabeled those rows as "Cash on delivery").
        paymentTypeLabel: function (order) {
            if (order.transaction) {
                // [FP-ARMY-P2A] The unified counter-encaissement writes order.transaction as a raw
                // code (counter_cash/counter_card/counter_ticket_restaurant/counter_mobile_banking/
                // counter_other) which leaked verbatim into the financial report. Map ALL 5 to FR
                // (existing label keys, case-robust); real gateway provider names pass through.
                const txMap = {
                    COUNTER_CASH: this.$t('label.cash'),
                    COUNTER_CARD: this.$t('label.card'),
                    COUNTER_TICKET_RESTAURANT: this.$t('label.ticket_restaurant'),
                    COUNTER_MOBILE_BANKING: this.$t('label.mobile_banking'),
                    COUNTER_OTHER: this.$t('label.other'),
                };
                return txMap[String(order.transaction).toUpperCase()] || order.transaction;
            }
            if (order.pos_payment_method && this.enums.posPaymentMethodEnumArray[order.pos_payment_method]) {
                return this.enums.posPaymentMethodEnumArray[order.pos_payment_method];
            }
            // Online/gateway orders (web/app) fall back to the payment-type label.
            return this.enums.paymentTypeEnumArray[order.payment_method];
        },
        floatNumber(e) {
            return appService.floatNumber(e);
        },
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        search: function () {
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
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.order_serial_no = "";
            this.props.search.payment_status = null;
            this.props.search.payment_method = null;
            this.props.search.status = null;
            this.props.search.from_date = "";
            this.props.search.to_date = "";
            this.props.search.delivery_boy_id = null;
            this.props.search.source = null;
            this.props.form.date = null;
            this.list();
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('salesReport/lists', this.props.search).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
                // [REP-EXP-ERR-04 FIX] surface the failure instead of swallowing it.
                alertService.error(err?.response?.data?.message || this.$t('message.no_data_available'));
            });

            this.$store.dispatch('salesReport/salesReportOverview', this.props.search).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },

        xls: function () {
            this.loading.isActive = true;
            // [REP-EXP-01 FIX] full filtered set (pagination stripped).
            this.$store.dispatch('salesReport/export', this.exportSearch()).then(res => {
                this.loading.isActive = false;
                const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = this.$t("menu.sales_report");
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                this.showExportError(err); // [REP-EXP-ERR-04 FIX]
            });
        },
        pdf: function () {
            this.loading.isActive = true;
            // [REP-EXP-01 FIX] full filtered set (pagination stripped).
            this.$store.dispatch("salesReport/pdf", this.exportSearch()).then((res) => {
                this.loading.isActive = false;
                const blob = new Blob([res.data]);
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = this.$t("menu.sales_report") + ".pdf";
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                this.showExportError(err); // [REP-EXP-ERR-04 FIX]
            });
        }
    }
}
</script>
<style scoped>
@media print {
    .hidden-print {
        display: none !important;
    }
}
</style>