<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6">
        <section
            class="sla-cockpit bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full"
            aria-labelledby="sla-cockpit-title"
            data-testid="sla-cockpit"
        >
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-orange-700 mb-1">Supervision cuisine</p>
                    <h4 id="sla-cockpit-title" class="font-semibold text-lg text-gray-900">
                        Délais de préparation
                    </h4>
                    <p class="text-xs text-gray-600 mt-1" data-testid="sla-freshness">
                        {{ freshnessLabel }}
                    </p>
                </div>
                <button
                    type="button"
                    class="sla-refresh min-h-11 min-w-11 inline-flex items-center justify-center rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 disabled:opacity-50"
                    :disabled="refreshing"
                    :aria-label="refreshing ? 'Actualisation des alertes SLA en cours' : 'Actualiser les alertes SLA'"
                    @click="fetchData"
                >
                    <i
                        class="fa-solid fa-arrows-rotate"
                        :class="{ 'sla-refreshing': refreshing }"
                        aria-hidden="true"
                    ></i>
                </button>
            </div>

            <div
                v-if="loading"
                class="sla-state flex items-center gap-3 rounded-xl bg-gray-50 p-4 text-sm text-gray-700"
                role="status"
                data-testid="sla-loading"
            >
                <span class="sla-loading-dot" aria-hidden="true"></span>
                Contrôle des délais en cours…
            </div>

            <div
                v-else-if="error && !hasSnapshot"
                class="sla-state rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950"
                role="alert"
                data-testid="sla-error"
            >
                <p class="font-semibold">Contrôle SLA indisponible</p>
                <p class="text-sm mt-1">{{ error }}</p>
                <button type="button" class="sla-inline-action mt-3" @click="fetchData">Réessayer</button>
            </div>

            <template v-else>
                <div
                    v-if="error || isStale"
                    class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 mb-4"
                    role="status"
                    data-testid="sla-stale-warning"
                >
                    {{ error ? 'Actualisation impossible. Dernier relevé conservé.' : 'Relevé ancien : actualisez avant de décider.' }}
                </div>

                <div class="grid grid-cols-3 gap-2 mb-4" aria-label="Synthèse des délais cuisine">
                    <div class="sla-metric">
                        <span class="sla-metric-value" data-testid="sla-alert-count">{{ alertCount }}</span>
                        <span class="sla-metric-label">hors délai<span class="sla-metric-scope"> · {{ fenetreHeures }} h</span></span>
                    </div>
                    <div class="sla-metric">
                        <span class="sla-metric-value" data-testid="sla-urgent-count">{{ urgentCount }}</span>
                        <span class="sla-metric-label">urgentes</span>
                    </div>
                    <div class="sla-metric">
                        <span class="sla-metric-value" data-testid="sla-oldest-wait">{{ oldestWait }}</span>
                        <span class="sla-metric-label">plus ancienne</span>
                    </div>
                </div>

                <div
                    v-if="alertCount === 0 && !error && !isStale"
                    class="flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-emerald-950"
                    data-testid="sla-empty"
                >
                    <i class="lab lab-check-circle text-2xl text-emerald-600" aria-hidden="true"></i>
                    <div>
                        <p class="font-semibold">Aucune préparation hors délai</p>
                        <p class="text-sm text-emerald-800" data-testid="sla-empty-scope">
                            Sur les {{ fenetreHeures }} dernières heures. Les commandes plus
                            anciennes ne sont pas contrôlées ici.
                        </p>
                    </div>
                </div>

                <div v-else-if="visibleAlerts.length" class="space-y-2" data-testid="sla-alert-list">
                    <article
                        v-for="(alert, index) in visibleAlerts"
                        :key="alert.order_serial_no || `${alert.queue_number}-${index}`"
                        class="sla-alert flex items-center justify-between gap-4 rounded-xl border p-3"
                        :class="alert.waitMinutes >= urgentThreshold ? 'sla-alert-critical' : 'sla-alert-warning'"
                        data-testid="sla-alert-row"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">
                                Ticket #{{ alert.queue_number || '—' }}
                                <span class="font-normal text-gray-600">· {{ alert.order_serial_no || 'sans n°' }}</span>
                            </p>
                            <p class="text-sm text-gray-700 mt-1 truncate">
                                {{ alert.customer || 'Client non renseigné' }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-red-800">{{ humanizeWait(alert.waitMinutes) }}</p>
                            <p class="text-xs text-gray-600">en préparation</p>
                        </div>
                    </article>
                    <p v-if="hiddenCount > 0" class="text-xs text-gray-600 pt-1" data-testid="sla-hidden-count">
                        + {{ hiddenCount }} autre(s) alerte(s), classées par ancienneté.
                    </p>
                </div>
            </template>

            <span class="sr-only" aria-live="polite">{{ liveSummary }}</span>
        </section>
    </div>
</template>

<script>
export default {
    name: "SlaAlertsComponent",
    data() {
        return {
            alerts: [],
            // [2026-09-03] Fenêtre de contrôle, publiée par le serveur. Déclarée ICI pour
            // être réactive : une propriété assignée sans être déclarée ne fait rien réagir.
            fenetreHeures: 24,
            timer: null,
            loading: true,
            refreshing: false,
            error: '',
            lastSuccessfulAt: null,
            clock: Date.now(),
            requestSequence: 0,
            urgentThreshold: 30,
            maxVisibleAlerts: 6,
        }
    },
    computed: {
        normalizedAlerts() {
            return this.alerts
                .map((alert) => ({
                    ...alert,
                    waitMinutes: Math.max(0, Math.round(Number(alert?.time_preparing) || 0)),
                }))
                .sort((a, b) => b.waitMinutes - a.waitMinutes);
        },
        visibleAlerts() {
            return this.normalizedAlerts.slice(0, this.maxVisibleAlerts);
        },
        alertCount() {
            return this.normalizedAlerts.length;
        },
        urgentCount() {
            return this.normalizedAlerts.filter((alert) => alert.waitMinutes >= this.urgentThreshold).length;
        },
        hiddenCount() {
            return Math.max(0, this.alertCount - this.visibleAlerts.length);
        },
        oldestWait() {
            return this.alertCount ? this.humanizeWait(this.normalizedAlerts[0].waitMinutes) : '—';
        },
        hasSnapshot() {
            return this.lastSuccessfulAt !== null;
        },
        isStale() {
            return this.hasSnapshot && (this.clock - this.lastSuccessfulAt) > 45_000;
        },
        freshnessLabel() {
            if (this.loading) return 'Premier contrôle en cours';
            if (!this.hasSnapshot) return 'Aucun relevé disponible';
            if (this.isStale) return `Dernier relevé ${this.relativeFreshness}`;
            return `Actualisé ${this.relativeFreshness}`;
        },
        relativeFreshness() {
            const seconds = Math.max(0, Math.floor((this.clock - this.lastSuccessfulAt) / 1000));
            if (seconds < 5) return 'à l’instant';
            if (seconds < 60) return `il y a ${seconds} s`;
            return `il y a ${Math.floor(seconds / 60)} min`;
        },
        liveSummary() {
            if (this.loading) return 'Contrôle des alertes SLA en cours.';
            if (this.error && !this.hasSnapshot) return 'Contrôle des alertes SLA indisponible.';
            return `${this.alertCount} préparations hors délai, dont ${this.urgentCount} urgentes.`;
        },
    },
    mounted() {
        this.fetchData();
        this.timer = setInterval(() => {
            this.clock = Date.now();
            this.fetchData();
        }, 15000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        async fetchData() {
            // Le poll et le clic manuel partagent le même verrou : une sonde lente
            // ne doit jamais empiler des requêtes ni rendre un relevé plus ancien.
            if (this.refreshing) return;
            const sequence = ++this.requestSequence;
            this.refreshing = true;
            this.clock = Date.now();
            try {
                const res = await this.$store.dispatch('dashboard/slaAlerts');
                if (sequence !== this.requestSequence) return;
                this.alerts = Array.isArray(res?.data?.data) ? res.data.data : [];
                // Repli sur 24 si un serveur plus ancien ne publie pas la clé.
                this.fenetreHeures = Number(res?.data?.fenetre_heures) || 24;
                this.lastSuccessfulAt = Date.now();
                this.clock = this.lastSuccessfulAt;
                this.error = '';
            } catch (error) {
                if (sequence !== this.requestSequence) return;
                this.error = error?.response?.data?.message
                    || 'Impossible de joindre la supervision cuisine.';
            } finally {
                if (sequence === this.requestSequence) {
                    this.loading = false;
                    this.refreshing = false;
                }
            }
        },
        humanizeWait(minutes) {
            const m = Math.max(0, Math.round(Number(minutes) || 0));
            if (m < 60) return `${m} min`;
            if (m < 1440) {
                const h = Math.floor(m / 60), rm = m % 60;
                return rm ? `${h} h ${rm} min` : `${h} h`;
            }
            const d = Math.floor(m / 1440), rh = Math.floor((m % 1440) / 60);
            return rh ? `${d} j ${rh} h` : `${d} j`;
        }
    }
}
</script>

<style scoped>
.sla-metric {
    min-width: 0;
    border: 1px solid #fed7aa;
    border-radius: 0.75rem;
    background: #fff7ed;
    padding: 0.65rem 0.7rem;
}

.sla-metric-value,
.sla-metric-label {
    display: block;
}

.sla-metric-value {
    color: #9a3412;
    font-size: 1.05rem;
    font-weight: 800;
    line-height: 1.2;
}

.sla-metric-label {
    margin-top: 0.2rem;
    overflow: hidden;
    color: #57534e;
    font-size: 0.7rem;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sla-alert-critical {
    border-color: #fecaca;
    background: #fff1f2;
}

.sla-alert-warning {
    border-color: #fed7aa;
    background: #fff7ed;
}

.sla-inline-action {
    min-height: 2.75rem;
    border: 1px solid #d97706;
    border-radius: 0.65rem;
    padding: 0.45rem 0.85rem;
    color: #92400e;
    font-weight: 700;
}

.sla-loading-dot {
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 9999px;
    background: #f97316;
    animation: sla-breathe 1.2s ease-in-out infinite;
}

.sla-refreshing {
    animation: sla-spin 0.8s linear infinite;
}

@keyframes sla-breathe {
    50% { opacity: 0.35; }
}

@keyframes sla-spin {
    to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
    .sla-loading-dot,
    .sla-refreshing {
        animation: none !important;
    }
}
</style>
