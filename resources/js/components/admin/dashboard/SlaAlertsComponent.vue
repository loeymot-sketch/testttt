<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6" data-testid="sla-alerts-widget">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full">
            <div class="flex items-center justify-between mb-4">
                <h4 class="font-semibold text-lg text-gray-800 flex items-center gap-2">
                    Alertes SLA (Cuisine > 15min)
                    <span v-if="!hasError && alerts.length > 0" class="flex h-3 w-3 relative ml-2">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                    </span>
                </h4>
                <!-- [DASH-04 FIX] On API failure the count badge must NOT show
                     "0 Alerte(s)" — a false reassurance. Suppress it entirely
                     when hasError; render a neutral 'indisponible' chip instead. -->
                <span v-if="hasError" data-testid="sla-alerts-status-unavailable" class="text-sm font-medium bg-gray-100 text-gray-600 px-3 py-1 rounded-full">Indisponible</span>
                <span v-else data-testid="sla-alerts-count" class="text-sm font-medium bg-red-100 text-red-900 px-3 py-1 rounded-full">{{ alerts.length }} Alerte(s)</span>
            </div>

            <!-- [DASH-04 FIX] Distinct neutral error state on API failure —
                 NEVER the green "flux optimal" empty-state (false-negative). -->
            <div v-if="hasError" data-testid="sla-alerts-error" class="flex flex-col items-center justify-center p-8 text-gray-500">
                <i class="lab lab-warning text-4xl mb-2 text-gray-400"></i>
                <p>Données indisponibles</p>
                <p class="text-xs mt-1 text-gray-400">Impossible de charger les alertes SLA. Nouvelle tentative automatique.</p>
            </div>

            <div v-else-if="alerts.length === 0" data-testid="sla-alerts-empty" class="flex flex-col items-center justify-center p-8 text-gray-600">
                <i class="lab lab-check-circle text-4xl mb-2 text-green-400"></i>
                <p>Aucune alerte SLA. Flux de cuisine optimal.</p>
            </div>

            <div v-else class="space-y-3 max-h-[300px] overflow-y-auto pr-2">
                <div v-for="alert in alerts" :key="alert.order_serial_no" class="flex items-center justify-between p-4 bg-red-50 border border-red-100 rounded-lg">
                    <div>
                        <h5 class="font-semibold text-red-700">Ticket #{{ alert.queue_number }} ({{ alert.order_serial_no }})</h5>
                        <p class="text-sm text-red-600 font-medium mt-1">
                            <i class="lab lab-time w-4 h-4 mr-1"></i>
                            En attente depuis {{ alert.time_preparing }} minutes
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "SlaAlertsComponent",
    data() {
        return {
            alerts: [],
            // [DASH-04 FIX] Track API-failure so the template renders a neutral
            // 'Données indisponibles' state instead of the reassuring green
            // empty-state (false-negative).
            hasError: false,
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
        fetchData() {
            this.$store.dispatch('dashboard/slaAlerts').then(res => {
                this.alerts = res.data.data;
                // [DASH-04 FIX] Self-heal: a later successful poll clears the
                // error flag so a transient failure does not latch the error UI.
                this.hasError = false;
            }).catch(() => {
                // [DASH-04 FIX] On failure DON'T leave alerts=[] masquerading as
                // 'all good' — flag the error so the neutral state renders.
                this.hasError = true;
            });
        }
    }
}
</script>
