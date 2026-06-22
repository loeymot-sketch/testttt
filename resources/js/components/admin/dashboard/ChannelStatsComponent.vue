<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full">
            <h4 class="font-semibold text-lg text-gray-800 mb-6">Répartition par Canal (Aujourd'hui)</h4>
            
            <div class="space-y-6">
                <div v-for="(stat, index) in stats" :key="index">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700">{{ stat.name }}</span>
                        <span class="text-sm font-semibold text-gray-500">{{ formatPercent(stat.value) }}</span>
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
            stats: []
        }
    },
    mounted() {
        this.fetchData();
    },
    methods: {
        fetchData() {
            this.$store.dispatch('dashboard/channelStatistics').then(res => {
                this.stats = res.data.data;
            }).catch(() => {
                // [prod-finale 2026-06-17] keep last-good bars instead of silently empty on a failed fetch.
            });
        },
        formatPercent(value) {
            // [micro-ux 2026-06-19] FR display: en-US dot ("4.35%") → comma + NBSP before %
            // ("4,35 %"). Backend already rounds to 2 decimals (DashboardService::channelStatistics);
            // we only swap the separator and keep the exact precision the value carries (trailing
            // zeros stay dropped, so "8.7" → "8,7 %"). Presentation-only, no computation change.
            const NBSP = String.fromCharCode(0x00A0);
            const n = Number(value);
            const safe = Number.isFinite(n) ? n : 0;
            return String(safe).replace('.', ',') + NBSP + '%';
        },
        getColor(name) {
            if(name === 'Web') return 'bg-blue-500';
            if(name === 'Kiosk/App') return 'bg-orange-500';
            if(name === 'POS') return 'bg-purple-500';
            if(name === 'Livraison') return 'bg-emerald-500';
            return 'bg-gray-500';
        }
    }
}
</script>
