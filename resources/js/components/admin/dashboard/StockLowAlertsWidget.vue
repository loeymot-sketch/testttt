<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header flex items-center justify-between flex-wrap gap-2">
                <h3 class="db-card-title mb-0">{{ $t('label.stock_low_alerts') }}</h3>
                <router-link to="/admin/stock/rupture" class="text-sm font-medium text-pink-700" data-testid="stock-low-alerts-view-all">
                    {{ $t('label.view_all_alerts') }}
                </router-link>
            </div>
            <div class="db-card-body">
                <p v-if="!loading.isActive && alerts.length === 0" class="text-sm text-gray-500">
                    {{ $t('label.no_low_alerts') }}
                </p>
                <div v-else-if="loading.isActive" class="py-6" />
                <table v-else class="db-table db-table-mini w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="py-2 text-left font-medium text-gray-600">{{ $t('label.branch') }}</th>
                            <th class="py-2 text-left font-medium text-gray-600">{{ $t('label.item') }}</th>
                            <th class="py-2 text-right font-medium text-gray-600 whitespace-nowrap">{{ $t('label.quantity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="alert in alerts.slice(0, 5)"
                            :key="`${alert.branch_id}-${alert.stockable_type}-${alert.stockable_id}`"
                            class="border-b border-gray-50"
                            :data-testid="`stock-low-alert-${alert.stockable_id}`">
                            <td class="py-2 pr-2 text-gray-700">{{ alert.branch_name || alert.branch_id }}</td>
                            <td class="py-2 pr-2 text-gray-800">{{ alert.label || alert.stockable_name || ('#' + alert.stockable_id) }}</td>
                            <td class="py-2 text-right text-orange-600 font-semibold whitespace-nowrap">
                                {{ alert.on_hand }} / {{ alert.threshold_low }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent";

export default {
    name: 'StockLowAlertsWidget',
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            alerts: [],
        };
    },
    mounted() {
        this.fetchAlerts();
    },
    methods: {
        async fetchAlerts() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('/api/admin/stock/low-alerts');
                this.alerts = res.data?.alerts ?? [];
            } catch (_e) {
                this.alerts = [];
            } finally {
                this.loading.isActive = false;
            }
        },
    },
};
</script>
