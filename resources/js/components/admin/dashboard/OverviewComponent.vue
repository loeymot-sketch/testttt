<template>
    <LoadingComponent :props="loading" />
    <div class="mb-9">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3">{{ $t("menu.overview") }}</h4>
        <div class="row">
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#F4501E]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-sale lab-font-size-24 lab-color-pink"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-white">{{ $t('label.total_sales_today') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-white">{{ total_sales ?? '…' }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#1A1A1A]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-orders lab-font-size-24 lab-color-portage"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-white">{{ $t('label.total_orders_today') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-white">{{ total_orders ?? '…' }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-12 sm:col-6 xl:col-4">
                <div class="p-4 rounded-lg flex items-center gap-4 bg-[#FFB800]">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white">
                        <i class="lab lab-total-menu-items lab-font-size-24 lab-color-heliotrope"></i>
                    </div>
                    <div>
                        <h3 class="font-medium text-[#1A1A1A]">{{ $t('label.total_menu_items') }}</h3>
                        <h4 class="font-semibold text-[22px] leading-[34px] text-[#1A1A1A]">{{ total_menu_items ?? '…' }}</h4>
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
            pendingLoads: 0,
            loadError: false,
        };
    },
    mounted() {
        this.totalSales();
        this.totalOrders();
        this.totalMenuItems();
    },
    methods: {
        beginLoad() {
            this.pendingLoads += 1;
            this.loading.isActive = true;
        },
        endLoad() {
            this.pendingLoads = Math.max(0, this.pendingLoads - 1);
            this.loading.isActive = this.pendingLoads > 0;
        },
        // [T-5.2 CUMUL-NON-DATE 2026-08-15 · GOAL_CONFORT_MAX] Ces 2 tuiles étaient
        // des cumuls DEPUIS TOUJOURS (aucun filtre de date côté serveur) — un
        // propriétaire au jour 500 d'exploitation lisait un "Total ventes" qui ne
        // disait rien de la journée en cours. Scopées sur le jour fiscal
        // (business_date, pas minuit UTC) + relabellisées honnêtement.
        totalSales: function () {
            this.beginLoad();
            this.$store.dispatch("dashboard/totalSales", 'today').then((res) => {
                this.total_sales = res.data.data.total_sales;
                this.endLoad();
            }).catch(() => {
                this.loadError = true;
                this.endLoad();
            });
        },

        totalOrders: function () {
            this.beginLoad();
            this.$store.dispatch("dashboard/totalOrders", 'today').then((res) => {
                this.total_orders = res.data.data.total_orders;
                this.endLoad();
            }).catch(() => {
                this.loadError = true;
                this.endLoad();
            });
        },
        totalMenuItems: function () {
            this.beginLoad();
            this.$store.dispatch("dashboard/totalMenuItems").then((res) => {
                this.total_menu_items = res.data.data.total_menu_items;
                this.endLoad();
            }).catch(() => {
                this.loadError = true;
                this.endLoad();
            });
        },
    },
}
</script>
