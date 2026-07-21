<template>
    <!--
        CatalogHubComponent — Owner-approved unification (2026-07-21).

        A thin, accessible TAB WRAPPER that hosts the two catalogue-side admin
        screens on a single URL (`/admin/catalog-hub`) without merging their
        internals:
          - Tab "Catalogue"  → mounts <CatalogStudioComponent> AS-IS.
          - Tab "Stock"      → mounts <StockRuptureDashboardComponent> AS-IS.

        Design notes:
          - WAI-ARIA tabs pattern: role="tablist"/"tab"/"tabpanel",
            aria-selected, aria-controls, roving tabindex + arrow/Home/End keys.
          - Active tab is deep-linkable via `?tab=stock` (default = catalogue).
          - Each panel's child is lazily mounted with v-if so only the active
            screen's timers / polling / Echo subscriptions run at a time. The
            panel wrappers stay in the DOM so aria-controls always resolves.
          - Both original routes (admin.items.studio, admin.stock.rupture) stay
            alive for deep-links; this wrapper is additive.
    -->
    <div class="catalog-hub" data-testid="catalog-hub">
        <div
            class="catalog-hub__tablist"
            role="tablist"
            :aria-label="$t('menu.catalog')"
            @keydown="onTabKeydown"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                ref="tabButtons"
                type="button"
                role="tab"
                class="catalog-hub__tab"
                :class="{ 'catalog-hub__tab--active': activeTab === tab.key }"
                :id="`catalog-hub-tab-${tab.key}`"
                :aria-selected="activeTab === tab.key ? 'true' : 'false'"
                :aria-controls="`catalog-hub-panel-${tab.key}`"
                :tabindex="activeTab === tab.key ? 0 : -1"
                :data-testid="`catalog-hub-tab-${tab.key}`"
                @click="selectTab(tab.key)"
            >
                <i v-if="tab.icon" :class="tab.icon" aria-hidden="true"></i>
                <span>{{ tab.label }}</span>
            </button>
        </div>

        <div
            id="catalog-hub-panel-catalogue"
            role="tabpanel"
            aria-labelledby="catalog-hub-tab-catalogue"
            class="catalog-hub__panel"
            data-testid="catalog-hub-panel-catalogue"
            :hidden="activeTab !== 'catalogue'"
            tabindex="0"
        >
            <CatalogStudioComponent v-if="activeTab === 'catalogue'" />
        </div>

        <div
            id="catalog-hub-panel-stock"
            role="tabpanel"
            aria-labelledby="catalog-hub-tab-stock"
            class="catalog-hub__panel"
            data-testid="catalog-hub-panel-stock"
            :hidden="activeTab !== 'stock'"
            tabindex="0"
        >
            <StockRuptureDashboardComponent v-if="activeTab === 'stock'" />
        </div>
    </div>
</template>

<script>
import CatalogStudioComponent from "./CatalogStudioComponent.vue";
import StockRuptureDashboardComponent from "../stock/StockRuptureDashboardComponent.vue";

const VALID_TABS = ['catalogue', 'stock'];

export default {
    name: 'CatalogHubComponent',
    components: {
        CatalogStudioComponent,
        StockRuptureDashboardComponent,
    },
    data() {
        return {
            activeTab: this.resolveTabFromQuery(),
        };
    },
    computed: {
        tabs() {
            // Reuse existing, all-locale-verified menu.* keys (no new i18n
            // surface). "Catalogue" vs "Produits & Stock" reads clearly.
            return [
                { key: 'catalogue', label: this.$t('menu.catalog'), icon: 'lab lab-list' },
                { key: 'stock', label: this.$t('menu.stock_rupture'), icon: 'lab lab-stock' },
            ];
        },
    },
    watch: {
        // Keep the active tab in sync with the URL for back/forward + deep-links.
        '$route.query.tab'(next) {
            const resolved = VALID_TABS.includes(next) ? next : 'catalogue';
            if (resolved !== this.activeTab) {
                this.activeTab = resolved;
            }
        },
    },
    methods: {
        resolveTabFromQuery() {
            const q = this.$route && this.$route.query ? this.$route.query.tab : null;
            return VALID_TABS.includes(q) ? q : 'catalogue';
        },
        selectTab(key) {
            if (!VALID_TABS.includes(key)) return;
            this.activeTab = key;
            this.syncQuery(key);
            this.focusTab(key);
        },
        syncQuery(key) {
            if (!this.$router) return;
            const current = this.$route && this.$route.query ? this.$route.query.tab : undefined;
            if (current === key) return;
            const query = { ...(this.$route ? this.$route.query : {}), tab: key };
            const result = this.$router.replace({ query });
            // Swallow NavigationDuplicated / redundant-navigation rejections.
            if (result && typeof result.catch === 'function') {
                result.catch(() => {});
            }
        },
        focusTab(key) {
            this.$nextTick(() => {
                const index = this.tabs.findIndex((t) => t.key === key);
                const buttons = this.$refs.tabButtons;
                if (Array.isArray(buttons) && buttons[index] && typeof buttons[index].focus === 'function') {
                    buttons[index].focus();
                }
            });
        },
        onTabKeydown(event) {
            const index = this.tabs.findIndex((t) => t.key === this.activeTab);
            if (index === -1) return;
            let nextIndex = null;
            switch (event.key) {
                case 'ArrowRight':
                case 'ArrowDown':
                    nextIndex = (index + 1) % this.tabs.length;
                    break;
                case 'ArrowLeft':
                case 'ArrowUp':
                    nextIndex = (index - 1 + this.tabs.length) % this.tabs.length;
                    break;
                case 'Home':
                    nextIndex = 0;
                    break;
                case 'End':
                    nextIndex = this.tabs.length - 1;
                    break;
                default:
                    return;
            }
            event.preventDefault();
            this.selectTab(this.tabs[nextIndex].key);
        },
    },
};
</script>

<style scoped>
.catalog-hub__tablist {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 1rem;
}
.catalog-hub__tab {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #64748b;
    border: none;
    border-bottom: 2px solid transparent;
    background: transparent;
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
}
.catalog-hub__tab:hover {
    color: #1e293b;
}
.catalog-hub__tab--active {
    color: #F4501E;
    border-bottom-color: #F4501E;
}
.catalog-hub__tab:focus-visible {
    outline: 2px solid #F4501E;
    outline-offset: 2px;
    border-radius: 4px;
}
.catalog-hub__panel[hidden] {
    display: none;
}
</style>
