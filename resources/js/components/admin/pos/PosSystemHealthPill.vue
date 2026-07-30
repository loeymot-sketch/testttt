<template>
    <!--
      [CAISSE-HEALTH 2026-07-30] Pastille santé système pour le poste de commande.
      Vert discret quand tout va bien ; ambre/rouge + message rassurant dès qu'une dégradation
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
            _timer: null,
        };
    },
    computed: {
        syncStatus() {
            return (this.health && this.health.checks && this.health.checks.sync && this.health.checks.sync.status) || 'ok';
        },
        tone() {
            const o = this.health && this.health.overall;
            if (o === 'down') return 'down';
            if (o === 'degraded') return 'warn';
            return 'ok';
        },
        label() {
            if (!this.health) return '';
            if (this.syncStatus === 'down') return 'Temps réel coupé';
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
                && this.health.checks.fiscal.status !== 'ok');
        },
        stockRuptures() {
            return (this.health && this.health.checks && this.health.checks.stock && this.health.checks.stock.count) || 0;
        },
        agingOrders() {
            return (this.health && this.health.checks && this.health.checks.aging && this.health.checks.aging.count) || 0;
        },
        detailText() {
            if (!this.health || !this.health.checks) return '';
            const parts = [];
            if (this.health.checks.sync) parts.push(this.health.checks.sync.message);
            if (this.fiscalAlert) parts.push(this.health.checks.fiscal.message);
            if (this.stockRuptures > 0) parts.push(this.health.checks.stock.message);
            if (this.agingOrders > 0) parts.push(this.health.checks.aging.message);
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
            try {
                const res = await axios.get('admin/pos/system-health');
                if (res && res.data && res.data.checks) {
                    this.health = res.data;
                    this.loaded = true;
                }
            } catch (e) {
                // Best-effort : on NE casse PAS l'écran caisse. On garde le dernier état connu
                // (si le endpoint santé lui-même est injoignable, la caisse fonctionne toujours).
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
</style>
