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
        <span v-if="tone !== 'ok'" class="pos-health-pill-msg">{{ health.checks.sync.message }}</span>
        <span
            v-if="fiscalAlert"
            class="pos-health-pill-fiscal"
            :title="health.checks.fiscal.message"
        >🔒 {{ health.checks.fiscal.message }}</span>
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
        tone() {
            const o = this.health && this.health.overall;
            if (o === 'down') return 'down';
            if (o === 'degraded') return 'warn';
            return 'ok';
        },
        label() {
            if (!this.health) return '';
            const s = this.health.checks && this.health.checks.sync && this.health.checks.sync.status;
            if (this.tone === 'ok') return 'Système OK';
            if (s === 'down') return 'Temps réel coupé';
            return 'Temps réel dégradé';
        },
        fiscalAlert() {
            return !!(this.health && this.health.checks && this.health.checks.fiscal
                && this.health.checks.fiscal.status !== 'ok');
        },
        detailText() {
            if (!this.health || !this.health.checks) return '';
            const parts = [];
            if (this.health.checks.sync) parts.push(this.health.checks.sync.message);
            if (this.fiscalAlert) parts.push(this.health.checks.fiscal.message);
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
