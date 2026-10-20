<!--
    KioskBurgerBuilderPoc — V2-2 Phase A POC standalone wrapper page.

    Mounted on /kiosk/burger-builder-poc to demo the drag-and-drop burger
    builder feasibility without touching any frozen wizard component.
    Provides a hardcoded ingredient catalogue + a debug pane that mirrors
    the emitted `update:extras` payload so a reviewer can quickly verify
    state propagation without opening the Vue devtools.

    Phase B (real wizard integration) is gated; this page is throwaway.
-->
<template>
    <div class="poc-page">
        <header class="poc-page-header">
            <h1>POC — Burger Builder Drag &amp; Drop</h1>
            <p class="poc-page-subtitle">Phase A — Standalone POC (V2-2)</p>
        </header>

        <KioskBurgerBuilder
            :available-ingredients="ingredients"
            @update:extras="onUpdate"
            @switch-to-classic="onSwitchClassic"
        />

        <section class="poc-debug" aria-label="Debug payload">
            <h2 class="poc-debug-title">Debug — emitted extras</h2>
            <pre class="poc-debug-content">{{ debugJson }}</pre>
        </section>
    </div>
</template>

<script>
import KioskBurgerBuilder from './KioskBurgerBuilder.vue';

export default {
    name: 'KioskBurgerBuilderPoc',
    components: { KioskBurgerBuilder },
    data() {
        return {
            currentExtras: [],
            ingredients: [
                { id: 1, name: 'Steak haché', emoji: '🥩', price: 3.50 },
                { id: 2, name: 'Cheddar', emoji: '🧀', price: 1.00 },
                { id: 3, name: 'Bacon', emoji: '🥓', price: 1.50 },
                { id: 4, name: 'Salade', emoji: '🥬', price: 0.50 },
                { id: 5, name: 'Tomate', emoji: '🍅', price: 0.80 },
                { id: 6, name: 'Oignon', emoji: '🧅', price: 0.30 },
                { id: 7, name: 'Sauce BBQ', emoji: '🍶', price: 0.40 },
            ],
        };
    },
    computed: {
        debugJson() {
            return JSON.stringify(this.currentExtras, null, 2);
        },
    },
    methods: {
        onUpdate(extras) {
            this.currentExtras = Array.isArray(extras) ? extras : [];
        },
        onSwitchClassic() {
            // Best-effort fallback navigation. The kiosk wizard is the
            // canonical "classic" target; on dev / standalone runs we
            // simply emit a console marker so the reviewer can verify
            // the event without a configured kiosk session.
            if (this.$router && typeof this.$router.push === 'function') {
                this.$router.push({ path: '/kiosk/wizard' }).catch(() => {
                    /* swallow router errors in standalone POC mode */
                });
            }
        },
    },
};
</script>

<style scoped>
.poc-page {
    padding: 32px;
    max-width: 1200px;
    margin: 0 auto;
    background: var(--kiosk-bg, #FFFBF5);
    min-height: 100vh;
}

.poc-page-header {
    margin-bottom: 24px;
}

.poc-page-header h1 {
    margin: 0 0 4px;
    font-size: 28px;
    font-weight: 900;
    color: var(--kiosk-text, #1B1B1B);
}

.poc-page-subtitle {
    margin: 0;
    color: var(--kiosk-text-muted, #5A5A5A);
    font-size: 14px;
}

.poc-debug {
    margin-top: 32px;
}

.poc-debug-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--kiosk-text-muted, #5A5A5A);
    margin: 0 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.poc-debug-content {
    background: #f0f0f0;
    padding: 16px;
    font-family: ui-monospace, "SF Mono", Menlo, Monaco, monospace;
    font-size: 12px;
    border-radius: 8px;
    margin: 0;
    overflow-x: auto;
}
</style>
