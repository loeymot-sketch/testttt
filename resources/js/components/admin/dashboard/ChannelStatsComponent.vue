<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6" data-testid="channel-stats-widget">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full">
            <h4 class="font-semibold text-lg text-gray-800 mb-6">Répartition par Canal (Aujourd'hui)</h4>
            
            <div class="space-y-6">
                <div v-for="(stat, index) in stats" :key="index">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700">{{ stat.name }}</span>
                        <span class="text-sm font-semibold text-gray-500">{{ stat.value }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full" 
                             :style="{ width: stat.value + '%' }"
                             :class="getColor(stat.name)">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "ChannelStatsComponent",
    data() {
        return {
            stats: [],
            // [DASH-01 FIX] Auto-refresh timer handle (mirror RealtimeReportComponent).
            timer: null
        }
    },
    mounted() {
        this.fetchData();
        // [DASH-01 FIX] Poll every 30s so the channel breakdown does not go
        // stale until a full reload.
        this.timer = setInterval(this.fetchData, 30000);
    },
    beforeUnmount() {
        // [DASH-01 FIX] Clear the interval to avoid a leaked timer.
        clearInterval(this.timer);
    },
    methods: {
        fetchData() {
            this.$store.dispatch('dashboard/channelStatistics').then(res => {
                this.stats = res.data.data;
            }).catch(() => {
                // Best-effort: keep last-known stats on a transient failure
                // rather than wiping the chart.
            });
        },
        getColor(name) {
            if(name === 'Web') return 'bg-blue-500';
            if(name === 'Kiosk/App') return 'bg-orange-500';
            if(name === 'POS') return 'bg-purple-500';
            return 'bg-gray-500';
        }
    }
}
</script>
