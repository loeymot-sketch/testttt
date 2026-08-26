<template>
    <!--
      [CAISSE-HEALTH 2026-07-30] Pastille santé système pour le poste de commande.
      Vert discret quand tout va bien ; ambre/rouge + message honnête et actionnable dès qu'une dégradation
      apparaît (surtout le cas « temps réel connecté mais périmé » = worker DOWN, invisible sinon).
      Best-effort : ne casse JAMAIS l'écran caisse (garde le dernier état connu si le poll échoue).
    -->
    <div
        v-if="loaded"
        :class="['pos-health-pill', 'pos-health-pill--' + tone]"
        role="status"
        aria-live="polite"
        :title="detailText"
        :data-testid="'pos-health-pill'"
        :data-tone="tone"
    >
        <span class="pos-health-pill-dot" aria-hidden="true"></span>
        <span class="pos-health-pill-label">{{ label }}</span>
        <span v-if="syncMessage" class="pos-health-pill-msg">{{ syncMessage }}</span>
        <span
            v-if="nonSyncUnknownMessage"
            class="pos-health-pill-msg pos-health-pill-unknown"
            data-testid="pos-health-unknown-message"
        >{{ nonSyncUnknownMessage }}</span>
        <span v-if="freshnessLabel" class="pos-health-pill-freshness">{{ freshnessLabel }}</span>
        <span
            v-if="fiscalAlert"
            class="pos-health-pill-fiscal"
            :title="health.checks.fiscal.message"
        >🔒 {{ health.checks.fiscal.message }}</span>
        <!-- [CAISSE-HEALTH 2026-07-31] Ruptures de stock — compteur INFO (n'alarme pas le ton système,
             quelques épuisés en service = normal). Toujours ambre pour rester visible sur fond vert. -->
        <span
            v-if="stockRuptures > 0"
            class="pos-health-pill-stock"
            :title="'Produits actuellement en rupture (voir Gestion Produits & Stock)'"
            data-testid="pos-health-stock"
        >🍽️ {{ stockRuptures }} en rupture</span>
        <!-- [CAISSE-HEALTH 2026-07-31] Commandes qui vieillissent trop (pas encore prêtes > 15 min) —
             compteur INFO. Le tracker les colore déjà par carte ; ici c'est le coup d'œil agrégé. -->
        <span
            v-if="agingOrders > 0"
            class="pos-health-pill-aging"
            :title="health.checks.aging.message"
            data-testid="pos-health-aging"
        >⏱️ {{ agingOrders }} en retard</span>
        <button
            v-if="monitorUnavailable || isStale || hasUnknownCheck"
            type="button"
            class="pos-health-pill-retry"
            :disabled="pollInFlight"
            aria-label="Relancer le contrôle de santé de la caisse"
            @click="poll"
        >{{ pollInFlight ? 'Contrôle…' : 'Réessayer' }}</button>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'PosSystemHealthPill',
    data() {
        return {
            health: null,
            loaded: false,
            pollInFlight: false,
            lastSuccessfulPollAt: null,
            pollFailedAt: null,
            now: Date.now(),
            _timer: null,
        };
    },
    computed: {
        syncStatus() {
            return (this.health && this.health.checks && this.health.checks.sync && this.health.checks.sync.status) || 'unknown';
        },
        monitorUnavailable() {
            return this.pollFailedAt !== null;
        },
        hasUnknownCheck() {
            if (!this.health || !this.health.checks) return true;
            return Object.values(this.health.checks).some((check) => check && check.status === 'unknown');
        },
        nonSyncUnknownMessages() {
            if (!this.health || !this.health.checks) return [];
            return Object.entries(this.health.checks)
                .filter(([name, check]) => name !== 'sync' && check && check.status === 'unknown' && check.message)
                .map(([, check]) => check.message);
        },
        nonSyncUnknownMessage() {
            return this.nonSyncUnknownMessages.join(' · ');
        },
        isStale() {
            if (!this.lastSuccessfulPollAt) return false;
            return this.now - this.lastSuccessfulPollAt > 100000;
        },
        tone() {
            // [REPLAN_8 2026-08-24] Le ROUGE passe AVANT l'ambre. L'ordre précédent rétrogradait un
            // `overall: 'down'` en ambre dès qu'une sonde annexe était `unknown` — or c'est
            // exactement la combinaison que produit une vraie panne : socket coupé (rang 2) PLUS
            // une sonde stock qui tombe (rang 1). On masquait la panne opérationnelle avec
            // l'incertitude d'un voisin. La sévérité ne redescend jamais.
            const o = this.health && this.health.overall;
            if (o === 'down') return 'down';
            if (this.monitorUnavailable || this.isStale || this.hasUnknownCheck) return 'warn';
            if (o === 'degraded') return 'warn';
            // Un `overall` inattendu (contrat serveur élargi) ne doit pas retomber en vert :
            // on ne connaît pas cet état, on le signale comme dégradé.
            return o === 'ok' ? 'ok' : 'warn';
        },
        label() {
            if (this.monitorUnavailable) return 'Contrôle indisponible';
            if (this.isStale) return 'Contrôle périmé';
            if (!this.health) return 'Contrôle indisponible';
            // [REPLAN_8 2026-08-24] « Temps réel coupé » passe AVANT « Contrôle dégradé » : une
            // sonde annexe inconnue ne doit pas effacer le libellé de la panne opérationnelle que
            // l'opérateur doit voir. Les messages `unknown` restent affichés à côté.
            if (this.syncStatus === 'down') return 'Temps réel coupé';
            if (this.hasUnknownCheck) return 'Contrôle dégradé';
            if (this.syncStatus === 'warn') return 'Temps réel dégradé';
            // Temps réel OK : soit tout va bien, soit seule la chaîne fiscale alerte.
            return this.fiscalAlert ? 'Alerte fiscale' : 'Système OK';
        },
        syncMessage() {
            // On ne montre le message temps réel QUE s'il est réellement en cause (sinon on afficherait
            // « les commandes arrivent en direct » à côté d'une alerte fiscale = signal contradictoire).
            return this.syncStatus !== 'ok' && this.health && this.health.checks && this.health.checks.sync
                ? this.health.checks.sync.message
                : '';
        },
        fiscalAlert() {
            return !!(this.health && this.health.checks && this.health.checks.fiscal
                && this.health.checks.fiscal.status === 'alert');
        },
        stockRuptures() {
            return (this.health && this.health.checks && this.health.checks.stock && this.health.checks.stock.count) || 0;
        },
        agingOrders() {
            return (this.health && this.health.checks && this.health.checks.aging && this.health.checks.aging.count) || 0;
        },
        freshnessLabel() {
            if (!this.lastSuccessfulPollAt) return 'Aucun contrôle récent';
            const seconds = Math.max(0, Math.round((this.now - this.lastSuccessfulPollAt) / 1000));
            if (seconds < 5) return 'Vérifié à l’instant';
            if (seconds < 60) return `Vérifié il y a ${seconds} s`;
            return `Vérifié il y a ${Math.floor(seconds / 60)} min`;
        },
        detailText() {
            if (this.monitorUnavailable) {
                return `Le contrôle de santé ne répond plus. ${this.freshnessLabel}. Vérifie les écrans et réessaie.`;
            }
            if (!this.health || !this.health.checks) return 'Le contrôle de santé ne répond pas.';
            const parts = [];
            if (this.health.checks.sync) parts.push(this.health.checks.sync.message);
            if (this.fiscalAlert) parts.push(this.health.checks.fiscal.message);
            if (this.stockRuptures > 0) parts.push(this.health.checks.stock.message);
            if (this.agingOrders > 0) parts.push(this.health.checks.aging.message);
            for (const message of this.nonSyncUnknownMessages) {
                if (!parts.includes(message)) parts.push(message);
            }
            if (this.isStale) parts.unshift('Le dernier contrôle est périmé');
            else if (this.hasUnknownCheck) parts.unshift('Un ou plusieurs contrôles sont indisponibles');
            if (this.freshnessLabel) parts.push(this.freshnessLabel);
            return parts.join(' · ');
        },
    },
    mounted() {
        this.poll();
        // Toutes les 45 s : assez court pour voir une dégradation vite, assez espacé pour rester léger.
        this._timer = setInterval(this.poll, 45000);
    },
    beforeUnmount() {
        if (this._timer) { clearInterval(this._timer); this._timer = null; }
    },
    methods: {
        async poll() {
            if (this.pollInFlight) return;
            this.pollInFlight = true;
            this.now = Date.now();
            try {
                const res = await axios.get('admin/pos/system-health');
                if (res && res.data && res.data.checks) {
                    this.health = res.data;
                    this.loaded = true;
                    const serverTime = Date.parse(res.data.timestamp || '');
                    this.lastSuccessfulPollAt = Number.isFinite(serverTime) ? serverTime : Date.now();
                    this.pollFailedAt = null;
                }
            } catch (e) {
                // Le POS reste fonctionnel, mais la supervision ne doit jamais conserver un faux vert.
                this.loaded = true;
                this.pollFailedAt = Date.now();
                this.now = this.pollFailedAt;
            } finally {
                this.pollInFlight = false;
            }
        },
    },
};
</script>

<style scoped>
.pos-health-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
    max-width: 100%;
    border: 1.5px solid transparent;
}
.pos-health-pill-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.pos-health-pill-label { white-space: nowrap; }
.pos-health-pill-msg {
    font-weight: 600;
    opacity: 0.92;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pos-health-pill-freshness {
    font-weight: 600;
    opacity: 0.78;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.pos-health-pill-retry {
    min-height: 30px;
    padding: 4px 10px;
    border: 1px solid currentColor;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.62);
    color: inherit;
    font: inherit;
    cursor: pointer;
    touch-action: manipulation;
}
.pos-health-pill-retry:hover { background: rgba(255, 255, 255, 0.9); }
.pos-health-pill-retry:focus-visible { outline: 3px solid currentColor; outline-offset: 2px; }
.pos-health-pill-retry:disabled { cursor: wait; opacity: 0.65; }
.pos-health-pill-fiscal {
    font-weight: 700;
    white-space: nowrap;
    margin-left: 4px;
    padding-left: 8px;
    border-left: 1px solid currentColor;
}
/* Ruptures de stock — toujours ambre (visible sur fond vert/ambre/rouge), séparateur neutre. */
.pos-health-pill-stock {
    font-weight: 700;
    white-space: nowrap;
    margin-left: 4px;
    padding-left: 8px;
    border-left: 1px solid rgba(0, 0, 0, 0.15);
    color: #B8560F;
}
/* Commandes en retard — rouge-orangé (en retard = un peu plus urgent qu'une rupture), séparateur neutre. */
.pos-health-pill-aging {
    font-weight: 700;
    white-space: nowrap;
    margin-left: 4px;
    padding-left: 8px;
    border-left: 1px solid rgba(0, 0, 0, 0.15);
    color: #C2410C;
}

/* Vert discret — tout va bien, ne doit pas distraire l'opérateur. */
.pos-health-pill--ok {
    background: rgba(31, 166, 83, 0.10);
    color: #157a3d;
    border-color: rgba(31, 166, 83, 0.28);
}
.pos-health-pill--ok .pos-health-pill-dot { background: #1FA653; }

/* Ambre — dégradation (temps réel en retard) : visible mais pas alarmant. */
.pos-health-pill--warn {
    background: rgba(244, 128, 30, 0.12);
    color: #B8560F;
    border-color: rgba(244, 128, 30, 0.40);
}
.pos-health-pill--warn .pos-health-pill-dot { background: #F4801E; animation: pos-health-pulse 1.6s ease-in-out infinite; }

/* Rouge — temps réel coupé : signal fort. */
.pos-health-pill--down {
    background: rgba(215, 38, 56, 0.12);
    color: #C2410C;
    border-color: rgba(215, 38, 56, 0.42);
}
.pos-health-pill--down .pos-health-pill-dot { background: #D72638; animation: pos-health-pulse 1.2s ease-in-out infinite; }

@keyframes pos-health-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.35; }
}

@media (prefers-reduced-motion: reduce) {
    .pos-health-pill-dot { animation: none !important; }
}
</style>
