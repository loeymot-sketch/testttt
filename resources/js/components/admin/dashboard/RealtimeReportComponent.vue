<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 text-orange-700">Suivi en direct</h4>
        <!-- [micro-ux 2026-06-18] aria-live polite so the 30s auto-refresh of CA / orders / ticket
             is announced to screen readers; aria-atomic re-reads the whole block on change. -->
        <div class="row" role="status" aria-live="polite" aria-atomic="true">
            <div class="col-12 sm:col-4">
                <!-- [micro-ux 2026-06-18] solid dark green (green-700 #15803D) clears 4.5:1 with white;
                     was a light gradient (white-on-light = ~1.74:1 FAIL). -->
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-[#15803D] text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2">Chiffre d'Affaires du Jour</h3>
                    <!-- [micro-ux 2026-06-18] 3 distinct states: loading (—), genuine value, error note.
                         tabular-nums keeps digits from shifting on refresh. -->
                    <h4 v-if="loaded" class="font-bold text-4xl tabular-nums">{{ report.daily_sales || '0,00 €' }}</h4>
                    <h4 v-else-if="failed" class="font-medium text-sm tabular-nums">{{ $t('label.no_data_available') }}</h4>
                    <h4 v-else class="font-bold text-4xl tabular-nums">—</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <!-- [micro-ux 2026-06-18] solid blue-700 #1D4ED8 (≥4.5:1 with white). -->
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-[#1D4ED8] text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2">Commandes du Jour</h3>
                    <h4 v-if="loaded" class="font-bold text-4xl tabular-nums">{{ report.daily_orders || '0' }}</h4>
                    <h4 v-else-if="failed" class="font-medium text-sm tabular-nums">{{ $t('label.no_data_available') }}</h4>
                    <h4 v-else class="font-bold text-4xl tabular-nums">—</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <!-- [micro-ux 2026-06-18] solid purple-800 #6B21A8 (≥4.5:1 with white). -->
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-[#6B21A8] text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2">Ticket Moyen</h3>
                    <h4 v-if="loaded" class="font-bold text-4xl tabular-nums">{{ report.average_ticket || '0,00 €' }}</h4>
                    <h4 v-else-if="failed" class="font-medium text-sm tabular-nums">{{ $t('label.no_data_available') }}</h4>
                    <h4 v-else class="font-bold text-4xl tabular-nums">—</h4>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "RealtimeReportComponent",
    data() {
        return {
            report: {},
            // [micro-ux 2026-06-18] loading / genuine-zero / error are now 3 distinct
            // states so a failed first fetch no longer renders "0,00 €" as if sales were zero.
            loaded: false,
            failed: false,
            timer: null
        }
    },
    mounted() {
        this.fetchData();
        // Auto-refresh every 30 seconds
        this.timer = setInterval(this.fetchData, 30000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        fetchData() {
            this.$store.dispatch('dashboard/realtimeReport').then(res => {
                this.report = res.data.data;
                // [micro-ux 2026-06-18] mark loaded on success; clear any prior error.
                this.loaded = true;
                this.failed = false;
            }).catch(() => {
                // [prod-finale 2026-06-17] keep last-good values on a failed poll instead of resetting to {}.
                // [micro-ux 2026-06-18] but if we never loaded, flag the error so the cards show a
                // neutral "no data" note instead of a misleading "0,00 €".
                if (!this.loaded) {
                    this.failed = true;
                }
            });
        }
    }
}
</script>
