<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 text-orange-700">Suivi en direct</h4>
        <div class="row">
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-green-400 to-green-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Chiffre d'Affaires du Jour</h3>
                    <h4 class="font-bold text-4xl">{{ report.daily_sales || '0.00' }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-blue-400 to-blue-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Commandes du Jour</h3>
                    <h4 class="font-bold text-4xl">{{ report.daily_orders || '0' }}</h4>
                </div>
            </div>
            <div class="col-12 sm:col-4">
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-gradient-to-br from-purple-400 to-purple-600 text-white transform transition-transform hover:scale-105">
                    <h3 class="font-medium text-lg mb-2 opacity-90">Ticket Moyen</h3>
                    <h4 class="font-bold text-4xl">{{ report.average_ticket || '0.00' }}</h4>
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
            });
        }
    }
}
</script>
