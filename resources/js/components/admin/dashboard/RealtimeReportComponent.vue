<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 capitalize text-primary">{{ $t('dashboard.realtime.title') }}</h4>
        <div class="row">
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-green-400 to-green-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">{{ $t('dashboard.realtime.daily_sales') }}</h3>
                    <h4 class="font-bold text-4xl">{{ report.daily_sales || '0.00' }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-blue-400 to-blue-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">{{ $t('dashboard.realtime.daily_orders') }}</h3>
                    <h4 class="font-bold text-4xl">{{ report.daily_orders || '0' }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-purple-400 to-purple-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">{{ $t('dashboard.realtime.average_ticket') }}</h3>
                    <h4 class="font-bold text-4xl">{{ report.average_ticket || '0.00' }}</h4>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
const BASE_DELAY = 30000;
const MAX_DELAY = 240000;
const FAILURE_THRESHOLD = 3;

export default {
    name: "RealtimeReportComponent",
    data() {
        return {
            report: {},
            pollTimeout: null,
            pollFailures: 0,
            pollDelay: BASE_DELAY,
        }
    },
    mounted() {
        this.pollLoop();
    },
    beforeUnmount() {
        clearTimeout(this.pollTimeout);
    },
    methods: {
        async fetchData() {
            const res = await this.$store.dispatch('dashboard/realtimeReport');
            this.report = res.data.data;
        },
        async pollLoop() {
            try {
                await this.fetchData();
                this.pollFailures = 0;
                this.pollDelay = BASE_DELAY;
            } catch (e) {
                this.pollFailures++;
                if (this.pollFailures >= FAILURE_THRESHOLD) {
                    console.warn('[RealtimeReport] consecutive polling failures, backing off');
                    this.pollDelay = Math.min(this.pollDelay * 2, MAX_DELAY);
                }
            }
            this.pollTimeout = setTimeout(() => this.pollLoop(), this.pollDelay);
        }
    }
}
</script>
