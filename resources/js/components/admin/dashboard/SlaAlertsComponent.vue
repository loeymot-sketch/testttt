<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full">
            <div class="flex items-center justify-between mb-4">
                <h4 class="font-semibold text-lg text-gray-800 flex items-center gap-2">
                    Alertes SLA (Cuisine > 15min)
                    <span v-if="alerts.length > 0" class="flex h-3 w-3 relative ml-2">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                    </span>
                </h4>
                <span class="text-sm font-medium bg-red-100 text-red-900 px-3 py-1 rounded-full">{{ alerts.length }} Alerte(s)</span>
            </div>

            <div v-if="alerts.length === 0" class="flex flex-col items-center justify-center p-8 text-gray-600">
                <i class="lab lab-check-circle text-4xl mb-2 text-green-400"></i>
                <p>Aucune alerte SLA. Flux de cuisine optimal.</p>
            </div>

            <div v-else class="space-y-3 max-h-[300px] overflow-y-auto pr-2">
                <div v-for="alert in alerts" :key="alert.order_serial_no" class="flex items-center justify-between p-4 bg-red-50 border border-red-100 rounded-lg">
                    <div>
                        <h5 class="font-semibold text-red-700">Ticket #{{ alert.queue_number }} ({{ alert.order_serial_no }})</h5>
                        <p class="text-sm text-red-600 font-medium mt-1">
                            <i class="lab lab-time w-4 h-4 mr-1"></i>
                            En attente depuis {{ humanizeMinutes(alert.time_preparing) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import appService from "../../../services/appService";

export default {
    name: "SlaAlertsComponent",
    data() {
        return {
            alerts: [],
            timer: null
        }
    },
    mounted() {
        this.fetchData();
        // Auto-refresh every 15 seconds for SLA
        this.timer = setInterval(this.fetchData, 15000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        humanizeMinutes(minutes) {
            return appService.humanizeMinutes(minutes);
        },
        fetchData() {
            this.$store.dispatch('dashboard/slaAlerts').then(res => {
                this.alerts = res.data.data;
            });
        }
    }
}
</script>
