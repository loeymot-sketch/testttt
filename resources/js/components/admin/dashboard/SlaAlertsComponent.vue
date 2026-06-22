<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6">
        <!-- [micro-ux 2026-06-18] db-card convention (rounded border-none shadow-db-card)
             replaces the ad-hoc rounded-2xl/shadow-sm/border-gray-100 token drift. -->
        <div class="db-card h-full">
            <div class="db-card-body">
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

            <!-- [micro-ux 2026-06-18] error first: a failed fetch must NOT masquerade as
                 "flux optimal". Neutral "Donnée indisponible" instead of the green all-clear. -->
            <div v-if="failed && !loaded" class="flex flex-col items-center justify-center p-8 text-gray-600">
                <i class="lab lab-info-circle text-4xl mb-2 text-gray-400"></i>
                <p>{{ $t('label.no_data_available') }}</p>
            </div>

            <!-- [micro-ux 2026-06-18] only show the optimal/empty state once a fetch has
                 actually succeeded (loaded) — no green flash before the first response. -->
            <div v-else-if="loaded && alerts.length === 0" class="flex flex-col items-center justify-center p-8 text-gray-600">
                <i class="lab lab-check-circle text-4xl mb-2 text-green-400"></i>
                <p>Aucune alerte SLA. Flux de cuisine optimal.</p>
            </div>

            <div v-else-if="alerts.length > 0" class="space-y-3 max-h-[300px] overflow-y-auto pr-2">
                <div v-for="alert in alerts" :key="alert.order_serial_no" class="flex items-center justify-between p-4 bg-red-50 border border-red-100 rounded-lg">
                    <div>
                        <h5 class="font-semibold text-red-700">Ticket #{{ alert.queue_number }} ({{ alert.order_serial_no }})</h5>
                        <!-- [micro-ux 2026-06-18] text-red-600 -> text-red-700 for AA contrast on the light red card. -->
                        <p class="text-sm text-red-700 font-medium mt-1">
                            <i class="lab lab-time-slots w-4 h-4 mr-1"></i>
                            En attente depuis {{ humanizeMinutes(alert.time_preparing) }}
                        </p>
                    </div>
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
            // [micro-ux 2026-06-18] distinguish loading / empty(optimal) / error so a failed
            // poll never renders the green "optimal" all-clear.
            loaded: false,
            failed: false,
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
                this.failed = false;
            }).catch(() => {
                // [micro-ux 2026-06-18] keep last-good alerts on a failed poll (no unhandled
                // rejection on the 15s timer); flag the error only if we never loaded.
                if (!this.loaded) {
                    this.failed = true;
                }
            }).finally(() => {
                this.loaded = true;
            });
        }
    }
}
</script>
