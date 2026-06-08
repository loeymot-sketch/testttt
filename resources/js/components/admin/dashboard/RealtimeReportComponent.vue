<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 text-orange-700">Suivi en direct</h4>
        <div class="row">
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-green-400 to-green-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Chiffre d'Affaires du Jour</h3>
                    <h4 class="font-bold text-4xl">{{ !loaded ? '…' : (hasError ? '—' : (report.daily_sales || '0,00 €')) }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-blue-400 to-blue-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Commandes du Jour</h3>
                    <h4 class="font-bold text-4xl">{{ !loaded ? '…' : (hasError ? '—' : (report.daily_orders ?? '0')) }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-purple-400 to-purple-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Ticket Moyen</h3>
                    <h4 class="font-bold text-4xl">{{ !loaded ? '…' : (hasError ? '—' : (report.average_ticket || '0,00 €')) }}</h4>
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
            timer: null,
            // [FP-09] true when a poll fails — render '—' instead of a false '0,00 €' no-revenue day.
            hasError: false,
            // [W3.4 COCKPIT 2026-06-08] First-paint guard. Before the first poll
            // resolves the KPIs would flash real-looking '0,00 €' / '0' (a false
            // "no-revenue day" — same class as DASH-04). Show a neutral '…'
            // placeholder until the first fetch settles (success OR error).
            loaded: false
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
                this.hasError = false;
            }).catch(() => {
                // [FP-09] A poll failure must NOT paint a false 0,00 € zero-revenue day
                // (the DASH-04 class). Flag the error → template shows '—' instead.
                this.hasError = true;
            }).finally(() => {
                // [W3.4 COCKPIT 2026-06-08] First poll settled — lift the '…'
                // placeholder. On persistent failure hasError keeps the cards on
                // '—' (not stuck on the placeholder).
                this.loaded = true;
            });
        }
    }
}
</script>
