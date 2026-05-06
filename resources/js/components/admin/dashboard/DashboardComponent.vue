<template>
    <LoadingComponent :props="loading"/>
    <div v-if="demo === 'true' || demo === 'TRUE' || demo === 'True' || demo === '1' || demo === 1" class="mb-4 bg-red-100 p-2 pl-4  rounded">
        <h2 class="mb-1">{{ $t('label.reminder') }}</h2>
        <p>{{ $t('label.data_reset') }}</p>
    </div>

    <div class="mb-8">
        <h3 class="font-semibold text-[26px] leading-10 capitalize text-primary">{{ visitorMessage() }}</h3>
        <h4 class="font-medium text-[22px] leading-[34px] capitalize">{{ authInfo.name }}</h4>
    </div>

    <nav v-if="quickAccessLinks.length" class="mb-8" :aria-label="$t('label.quick_access')">
        <h3 class="font-semibold text-[18px] leading-7 mb-3 text-heading">{{ $t('label.quick_access') }}</h3>
        <div class="flex flex-wrap gap-2">
            <template v-for="link in quickAccessLinks" :key="link.to">
                <a v-if="link.external" :href="link.to"
                    class="inline-flex items-center gap-2 rounded-xl border border-[#EFF0F6] bg-white px-4 py-2.5 text-sm font-medium text-heading shadow-xs transition hover:border-primary/40 hover:bg-primary/5">
                    <i :class="link.icon" class="text-primary" aria-hidden="true"></i>
                    <span>{{ link.label }}</span>
                </a>
                <router-link v-else :to="link.to"
                    class="inline-flex items-center gap-2 rounded-xl border border-[#EFF0F6] bg-white px-4 py-2.5 text-sm font-medium text-heading shadow-xs transition hover:border-primary/40 hover:bg-primary/5">
                    <i :class="link.icon" class="text-primary" aria-hidden="true"></i>
                    <span>{{ link.label }}</span>
                </router-link>
            </template>
        </div>
    </nav>

    <ErrorBoundary><OverviewComponent/></ErrorBoundary>

    <ErrorBoundary><RealtimeReportComponent/></ErrorBoundary>
    <div class="row">
        <ErrorBoundary><SlaAlertsComponent/></ErrorBoundary>
        <ErrorBoundary><ChannelStatsComponent/></ErrorBoundary>
    </div>
    <ErrorBoundary><AuditTrailComponent/></ErrorBoundary>

    <ErrorBoundary><OrderStatisticsComponent/></ErrorBoundary>
    <div class="row">
        <ErrorBoundary><SalesSummaryComponent/></ErrorBoundary>
        <ErrorBoundary><OrderSummaryComponent/></ErrorBoundary>
        <ErrorBoundary><StockLowAlertsWidget/></ErrorBoundary>
        <ErrorBoundary><LastZReportWidget/></ErrorBoundary>
        <ErrorBoundary><FeaturedItemsComponent/></ErrorBoundary>
        <ErrorBoundary><MostPopularItemsComponent/></ErrorBoundary>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import OverviewComponent from "./OverviewComponent";
import OrderStatisticsComponent from "./OrderStatisticsComponent";
import StockLowAlertsWidget from "./StockLowAlertsWidget";
import LastZReportWidget from "./LastZReportWidget";
import FeaturedItemsComponent from "./FeaturedItemsComponent";
import MostPopularItemsComponent from "./MostPopularItemsComponent";
import SalesSummaryComponent from "./SalesSummaryComponent";
import OrderSummaryComponent from "./OrderSummaryComponent";
import RealtimeReportComponent from "./RealtimeReportComponent";
import SlaAlertsComponent from "./SlaAlertsComponent";
import ChannelStatsComponent from "./ChannelStatsComponent";
import AuditTrailComponent from "./AuditTrailComponent";
import ErrorBoundary from "../components/ErrorBoundary";
import ENV from "../../../config/env";

export default {
    name: "DashboardComponent",
    components: {
        LoadingComponent,
        OverviewComponent,
        OrderStatisticsComponent,
        StockLowAlertsWidget,
        LastZReportWidget,
        FeaturedItemsComponent,
        MostPopularItemsComponent,
        SalesSummaryComponent,
        OrderSummaryComponent,
        RealtimeReportComponent,
        SlaAlertsComponent,
        ChannelStatsComponent,
        AuditTrailComponent,
        ErrorBoundary
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            demo : ENV.DEMO
        };
    },
    computed: {
        authInfo: function () {
            return this.$store.getters.authInfo;
        },
        quickAccessLinks() {
            const perms = this.normalizedPermissions();
            const has = (permissionUrl) => {
                if (!permissionUrl) {
                    return true;
                }
                if (!perms.length) {
                    return true;
                }
                const entry = perms.find((p) => p && p.url === permissionUrl);
                if (!entry) {
                    return true;
                }
                return entry.access === true;
            };
            const links = [];
            const push = (to, label, icon, permUrl, external = false) => {
                if (!has(permUrl)) {
                    return;
                }
                links.push({ to, label, icon, external });
            };
            push('/admin/pos', this.$t('menu.pos'), 'lab lab-pos-bold', 'pos', false);
            push('/admin/pos-v4', this.$t('label.pos_dedicated_shell'), 'lab lab-pos-bold', 'pos', true);
            push('/admin/pos-orders', this.$t('menu.pos_orders'), 'lab lab-pos-orders', 'pos-orders', false);
            push('/admin/pos-orders-tracker', this.$t('menu.pos_orders_tracker'), 'lab lab-pos-orders', 'pos-orders', false);
            push('/admin/kitchen-display-system', this.$t('menu.k_d_s'), 'lab lab-kds', 'kitchen-display-system', false);
            push('/admin/order-status-screen', this.$t('menu.o_s_s'), 'lab lab-cds', 'order-status-screen', false);
            push('/admin/items/studio', this.$t('menu.catalog'), 'lab lab-list', 'items', false);
            push('/admin/ingredients', this.$t('menu.ingredients'), 'lab lab-item-attributes', 'ingredients_manage', false);
            push('/admin/stock/rupture', this.$t('menu.stock_rupture'), 'lab lab-stock', 'items', false);
            return links;
        },
    },
    methods: {
        normalizedPermissions() {
            const p = this.$store.getters.authPermission;
            if (Array.isArray(p)) {
                return p;
            }
            if (p && Array.isArray(p.data)) {
                return p.data;
            }
            return [];
        },
        visitorMessage: function () {
            let greet;
            let myDate = new Date();
            let hrs = myDate.getHours();
            if (hrs < 12) {
                greet = this.$t('message.good_morning');
            } else if (hrs >= 12 && hrs <= 17) {
                greet = this.$t('message.good_afternoon');
            } else if (hrs >= 17 && hrs <= 24) {
                greet = this.$t('message.good_evening');
            }
            return greet;
        }
    }
}
</script>
