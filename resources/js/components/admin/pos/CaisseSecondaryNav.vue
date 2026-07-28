<template>
    <!-- [GOAL UX 2026-07-25] Navigation cohérente entre les pages secondaires de la caisse
         (Encaissement · Suivi · Historique · Écran client) + Retour caisse. Le caissier
         circule d'une page à l'autre sans passer par la sidebar admin. Miroir du toolbar
         déjà présent sur le tracker de suivi. -->
    <nav class="caisse-secondary-nav" :aria-label="$t('menu.encaissement') + ' — navigation'" data-testid="caisse-secondary-nav">
        <div class="csn-group">
            <router-link
                :to="{ name: 'admin.encaissement' }"
                class="csn-link"
                :class="{ active: current === 'encaissement' }"
                :aria-current="current === 'encaissement' ? 'page' : null"
                data-testid="csn-encaissement"
            >
                <span class="csn-ico" aria-hidden="true">💶</span>
                <span>{{ $t('menu.encaissement') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.pos-orders.tracker' }"
                class="csn-link"
                :class="{ active: current === 'suivi' }"
                :aria-current="current === 'suivi' ? 'page' : null"
                data-testid="csn-suivi"
            >
                <span class="csn-ico" aria-hidden="true">📋</span>
                <span>{{ $t('pos.tracker.title') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.historique.list' }"
                class="csn-link"
                :class="{ active: current === 'historique' }"
                :aria-current="current === 'historique' ? 'page' : null"
                data-testid="csn-historique"
            >
                <span class="csn-ico" aria-hidden="true">🗂️</span>
                <span>{{ $t('menu.historique') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.order-status-screen' }"
                target="_blank"
                rel="noopener"
                class="csn-link"
                data-testid="csn-oss"
            >
                <span class="csn-ico" aria-hidden="true">🖥️</span>
                <span>{{ $t('pos.tracker.customer_screen') }}</span>
            </router-link>
        </div>
        <router-link
            :to="{ name: 'admin.pos' }"
            class="csn-link csn-back"
            data-testid="csn-back-caisse"
        >
            <span class="csn-ico" aria-hidden="true">←</span>
            <span>{{ $t('pos.tracker.back_to_pos') }}</span>
        </router-link>
    </nav>
</template>

<script>
export default {
    name: 'CaisseSecondaryNav',
    props: {
        // 'encaissement' | 'suivi' | 'historique' — met en surbrillance la page courante.
        current: { type: String, default: '' },
    },
};
</script>

<style scoped>
.caisse-secondary-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.csn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.csn-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 15px;
    border-radius: 999px;
    border: 1.5px solid #E5E5EF;
    background: #FFFFFF;
    color: #1B1B3A;
    font-weight: 700;
    font-size: 13px;
    line-height: 1;
    text-decoration: none;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, transform 0.08s ease;
    white-space: nowrap;
}
.csn-link:hover { border-color: #F4501E; color: #F4501E; }
.csn-link:active { transform: scale(0.97); }
.csn-link.active {
    background: #F4501E;
    border-color: #F4501E;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(244, 80, 30, 0.22);
}
.csn-link.csn-back {
    margin-left: auto;
    color: #6B6B85;
    border-style: dashed;
}
.csn-link.csn-back:hover { color: #F4501E; }
.csn-ico { font-size: 15px; }
@media (max-width: 680px) {
    .csn-link.csn-back { margin-left: 0; }
    .csn-link { flex: 1 1 auto; justify-content: center; }
}
</style>
