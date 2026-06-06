<template>
    <LoadingComponent :props="loading" />
    <div class="mb-9" data-testid="overview-widget">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 capitalize">{{ $t("menu.overview") }}</h4>
        <div class="row">
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#C81E63]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-sale lab-font-size-24 lab-color-pink"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-white">{{ $t('label.total_sales') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-white">{{ total_sales }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#4F35C8]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-orders lab-font-size-24 lab-color-portage"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-white">{{ $t('label.total_orders') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-white">{{ total_orders }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#6B21A8]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-menu-items lab-font-size-24 lab-color-heliotrope"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-white">{{ $t('label.total_menu_items') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-white">{{ total_menu_items }}</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
export default {
    name: "OverviewComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },

            total_sales: null,
            total_orders: null,
            total_menu_items: null,
            // [DASH-01 FIX] Auto-refresh timer handle (mirror RealtimeReportComponent).
            timer: null,
        };
    },
    mounted() {
        // Initial load shows the loading overlay…
        this.totalSales();
        this.totalOrders();
        this.totalMenuItems();
        // [DASH-01 FIX] …then poll every 30s so the headline KPIs do not go
        // stale until a full reload (siblings RealtimeReport/SlaAlerts already
        // poll). Timer-driven refreshes are SILENT (no spinner flash).
        this.timer = setInterval(this.refreshSilently, 30000);
    },
    beforeUnmount() {
        // [DASH-01 FIX] Always clear the interval to avoid a leaked timer /
        // post-unmount dispatch.
        clearInterval(this.timer);
    },
    methods: {
        // [DASH-01 FIX] Silent refresh used by the interval — never toggles the
        // loading overlay so the dashboard does not flash a spinner every 30s.
        refreshSilently: function () {
            this.totalSales(true);
            this.totalOrders(true);
            this.totalMenuItems(true);
        },
        totalSales: function (silent = false) {
            if (!silent) this.loading.isActive = true;
            this.$store.dispatch("dashboard/totalSales").then((res) => {
                this.total_sales = res.data.data.total_sales;
                if (!silent) this.loading.isActive = false;
            }).catch((err) => {
                if (!silent) this.loading.isActive = false;
            });
        },

        totalOrders: function (silent = false) {
            if (!silent) this.loading.isActive = true;
            this.$store.dispatch("dashboard/totalOrders").then((res) => {
                this.total_orders = res.data.data.total_orders;
                if (!silent) this.loading.isActive = false;
            }).catch((err) => {
                if (!silent) this.loading.isActive = false;
            });
        },
        totalMenuItems: function (silent = false) {
            if (!silent) this.loading.isActive = true;
            this.$store.dispatch("dashboard/totalMenuItems").then((res) => {
                this.total_menu_items = res.data.data.total_menu_items;
                if (!silent) this.loading.isActive = false;
            }).catch((err) => {
                if (!silent) this.loading.isActive = false;
            });
        },
    },
}
</script>
