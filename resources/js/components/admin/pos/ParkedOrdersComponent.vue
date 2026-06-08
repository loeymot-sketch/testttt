<template>
    <transition name="parked-orders-slide">
        <div v-if="open" class="parked-orders-overlay" @click.self="closeDrawer">
            <aside class="parked-orders-drawer">
                <div class="parked-orders-header">
                    <div>
                        <h3 class="parked-orders-title">{{ $t('pos.parked_orders') }}</h3>
                        <p class="parked-orders-subtitle">{{ parkedOrders.length }}</p>
                    </div>
                    <button type="button" class="parked-orders-close" @click="closeDrawer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!--
                  [POS-V4-CASHIER-OPS 2026-05-02] Inline search.
                  - Filters client-side over already-fetched parked orders so it stays
                    instant even on slow networks (parked list is small by definition).
                  - Search matches: id (numeric prefix), label (case-insensitive
                    substring), and customer name when present in the parked payload.
                -->
                <div v-if="parkedOrders.length > 0" class="parked-orders-search">
                    <i class="fa-solid fa-magnifying-glass parked-orders-search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        v-model="searchQuery"
                        :placeholder="$t('pos.parked_search_placeholder')"
                        :aria-label="$t('pos.parked_search_placeholder')"
                        data-testid="parked-orders-search"
                        class="parked-orders-search-input"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="parked-orders-search-clear"
                        :aria-label="$t('button.clear')"
                        @click="searchQuery = ''"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="parked-orders-body">
                    <div v-if="loading" class="parked-orders-empty">
                        {{ $t('label.loading') || 'Loading...' }}
                    </div>

                    <div v-else-if="parkedOrders.length === 0" class="parked-orders-empty">
                        {{ $t('pos.empty_parked_orders') }}
                    </div>

                    <div v-else-if="filteredParkedOrders.length === 0" class="parked-orders-empty">
                        {{ $t('pos.parked_search_no_match') }}
                    </div>

                    <article
                        v-for="order in filteredParkedOrders"
                        :key="order.id"
                        class="parked-orders-card"
                    >
                        <div class="parked-orders-card-head">
                            <div>
                                <h4 class="parked-orders-card-title">
                                    {{ order.label || $t('pos.parked_order_fallback_label') }}
                                </h4>
                                <p class="parked-orders-card-meta">
                                    {{ order.items_count }} {{ $t(Number(order.items_count) > 1 ? 'label.items' : 'label.item') }} · {{ formatTimeAgo(order.created_at) }}
                                </p>
                            </div>
                            <span class="parked-orders-total">
                                {{ formatMoney(order.preview_total) }}
                            </span>
                        </div>

                        <div class="parked-orders-actions">
                            <button
                                type="button"
                                class="parked-orders-action parked-orders-action-primary"
                                :disabled="busyId === order.id"
                                @click="restoreOrder(order.id)"
                            >
                                {{ $t('pos.restore') }}
                            </button>
                            <button
                                type="button"
                                class="parked-orders-action parked-orders-action-danger"
                                :disabled="busyId === order.id"
                                @click="discardOrder(order.id)"
                            >
                                {{ $t('pos.discard') }}
                            </button>
                        </div>
                    </article>
                </div>
            </aside>
        </div>
    </transition>
</template>

<script>
import alertService from "../../../services/alertService";

// [Phase-5 / T08] Liste des paniers serv-side (posParked), rappel / écart, tri
// côté store (récent d’abord) — rappel ne traverse pas `branch_id` (API 404) ;
// G-3 variation indispo : voir `posParked` recall + backlog.

export default {
    name: "ParkedOrdersComponent",
    props: {
        open: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['close', 'restored'],
    data() {
        return {
            loading: false,
            busyId: null,
            // [POS-V4-CASHIER-OPS 2026-05-02] Client-side search over the already
            // fetched parked list. Empty string = no filter (all visible).
            searchQuery: '',
        };
    },
    computed: {
        parkedOrders() {
            return this.$store.getters['posParked/list'] || [];
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] Filter by id prefix, label substring, or
        // customer name (when exposed in the parked payload). Case-insensitive,
        // accent-tolerant via toLowerCase normalization. Defensive against
        // missing/null fields — the parked schema is loose.
        filteredParkedOrders() {
            // Defensive: drop null / id-less entries first so the v-for key
            // never receives undefined (Vue would warn) and downstream code
            // can assume a usable object.
            const list = (this.parkedOrders || []).filter((o) => o && o.id != null);
            const raw = String(this.searchQuery || '').trim().toLowerCase();
            if (!raw) {
                return list;
            }
            return list.filter((order) => {
                if (!order) return false;
                const idStr = String(order.id || '').toLowerCase();
                const label = String(order.label || '').toLowerCase();
                const customer = String(
                    order.customer_name
                    || order.user_name
                    || (order.customer && order.customer.name)
                    || ''
                ).toLowerCase();
                return idStr.startsWith(raw)
                    || label.indexOf(raw) !== -1
                    || customer.indexOf(raw) !== -1;
            });
        },
        setting() {
            return this.$store.getters['frontendSetting/lists'] || {};
        },
    },
    watch: {
        open: {
            immediate: true,
            handler(isOpen) {
                if (isOpen) {
                    this.fetchList();
                }
            },
        },
    },
    methods: {
        closeDrawer() {
            this.$emit('close');
        },
        async fetchList() {
            this.loading = true;

            try {
                await this.$store.dispatch('posParked/fetchList');
            } catch (error) {
                alertService.error(this.$t('pos.park_fetch_error'));
            } finally {
                this.loading = false;
            }
        },
        async restoreOrder(id) {
            if ((this.$store.getters['posCart/lists'] || []).length > 0) {
                alertService.info(this.$t('pos.park_restore_requires_empty_cart'));
                return;
            }

            this.busyId = id;

            try {
                const payload = await this.$store.dispatch('posParked/recall', id);
                this.$emit('restored', payload);
                // [M7-02] Surface unavailable-item / unavailable-variation drops so the
                // cashier KNOWS the restored cart differs from what was parked. Before,
                // an unconditional success toast hid the loss until checkout.
                const purgedCount = Array.isArray(payload && payload._recall_purged_item_ids)
                    ? payload._recall_purged_item_ids.length : 0;
                const variationDrops = payload && payload._recall_warnings
                    && Array.isArray(payload._recall_warnings.unavailable_variations)
                    ? payload._recall_warnings.unavailable_variations.length : 0;
                if (purgedCount > 0 || variationDrops > 0) {
                    alertService.warning(
                        this.$t('pos.park_restore_partial')
                        || 'Commande restaurée, mais des articles/variations indisponibles ont été retirés — vérifiez le panier avant d\'encaisser.'
                    );
                } else {
                    alertService.success(this.$t('pos.park_restore_success'));
                }
            } catch (error) {
                alertService.error(this.$t('pos.park_restore_error'));
            } finally {
                this.busyId = null;
            }
        },
        async discardOrder(id) {
            this.busyId = id;

            try {
                await this.$store.dispatch('posParked/discard', id);
                alertService.success(this.$t('pos.park_discard_success'));
            } catch (error) {
                alertService.error(this.$t('pos.park_discard_error'));
            } finally {
                this.busyId = null;
            }
        },
        formatMoney(amount) {
            const numericAmount = Number(amount || 0);

            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: this.setting.site_default_currency_code || 'EUR',
            }).format(numericAmount);
        },
        formatTimeAgo(date) {
            if (!date) {
                return '';
            }

            const target = new Date(date).getTime();

            if (Number.isNaN(target)) {
                return '';
            }

            const deltaSeconds = Math.round((target - Date.now()) / 1000);
            const absoluteSeconds = Math.abs(deltaSeconds);
            // [POS-UIUX-2026-06-08 P1] V1 LOCAL is FR-only (ADR-007). The previous
            // `site_default_language || navigator.language` fallback leaked the browser
            // locale (e.g. en-US) and rendered "5 days ago" in English on the FR caisse.
            // Hardcode fr-FR, matching the sibling formatMoney() above.
            const formatter = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' });

            if (absoluteSeconds < 60) {
                return formatter.format(deltaSeconds, 'second');
            }

            if (absoluteSeconds < 3600) {
                return formatter.format(Math.round(deltaSeconds / 60), 'minute');
            }

            if (absoluteSeconds < 86400) {
                return formatter.format(Math.round(deltaSeconds / 3600), 'hour');
            }

            return formatter.format(Math.round(deltaSeconds / 86400), 'day');
        },
    },
};
</script>

<style scoped>
/* =============================================================================
   ParkedOrdersComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.5
   - Drawer warm V5 — radius xl gauche, shadow modal, bordure border
   - Header avec eyebrow rouge brand "EN ATTENTE" + title h5
   - Cards avec hover lift + total monospace tabular
   - Actions Reprendre (success vibrant) / Supprimer (danger ghost)
   ============================================================================= */
.parked-orders-overlay {
    position: fixed;
    inset: 0;
    z-index: var(--pos-v5-z-modal);
    background: rgba(26, 26, 26, 0.42);
    display: flex;
    justify-content: flex-end;
    backdrop-filter: blur(2px);
}

.parked-orders-drawer {
    width: min(420px, 100vw);
    height: 100vh;
    background: var(--pos-v5-bg-panel);
    box-shadow: -20px 0 48px rgba(26, 26, 26, 0.20);
    display: flex;
    flex-direction: column;
    border-top-left-radius: var(--pos-v5-radius-xl);
    border-bottom-left-radius: var(--pos-v5-radius-xl);
    font-family: var(--pos-v5-font-sans);
}

.parked-orders-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
    padding: var(--pos-v5-space-5);
    border-bottom: 1px solid var(--pos-v5-border);
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
}

.parked-orders-title {
    margin: 0;
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    line-height: var(--pos-v5-leading-tight);
}

.parked-orders-subtitle {
    margin: 4px 0 0;
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-brand-red);
}

/* Inline search */
.parked-orders-search {
    position: relative;
    margin: var(--pos-v5-space-3) var(--pos-v5-space-5) var(--pos-v5-space-1);
    display: flex;
    align-items: center;
}
.parked-orders-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--pos-v5-ink-soft);
    font-size: 12px;
    pointer-events: none;
}
.parked-orders-search-input {
    width: 100%;
    height: 40px;
    padding: 0 36px 0 32px;
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
    background: var(--pos-v5-bg-panel);
    transition: border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.parked-orders-search-input::placeholder {
    color: var(--pos-v5-ink-muted);
    font-weight: var(--pos-v5-weight-medium);
}
.parked-orders-search-input:focus {
    outline: none;
    border-color: var(--pos-v5-brand-red);
    box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft);
}
.parked-orders-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 26px;
    height: 26px;
    border-radius: var(--pos-v5-radius-pill);
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    border: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    cursor: pointer;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.parked-orders-search-clear:hover {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger);
}

.parked-orders-close {
    width: 36px;
    height: 36px;
    border-radius: var(--pos-v5-radius-pill);
    border: 0;
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.parked-orders-close:hover {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger);
}

.parked-orders-body {
    flex: 1;
    overflow-y: auto;
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-3);
    background: var(--pos-v5-bg-app);
}

.parked-orders-empty {
    min-height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: var(--pos-v5-ink-muted);
    font-size: var(--pos-v5-text-body);
}

.parked-orders-card {
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-left: 4px solid var(--pos-v5-warning);
    border-radius: var(--pos-v5-radius-md);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-3);
    box-shadow: var(--pos-v5-shadow-sm);
    transition: box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                transform var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
}
.parked-orders-card:hover {
    box-shadow: var(--pos-v5-shadow-md);
    transform: translateY(-1px);
}

.parked-orders-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
}

.parked-orders-card-title {
    margin: 0;
    font-size: var(--pos-v5-text-body-lg);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
    line-height: var(--pos-v5-leading-tight);
}

.parked-orders-card-meta {
    margin: 4px 0 0;
    font-size: var(--pos-v5-text-caption);
    color: var(--pos-v5-ink-muted);
}

.parked-orders-total {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body-lg);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-brand-red);
    white-space: nowrap;
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

.parked-orders-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--pos-v5-space-2);
}

.parked-orders-action {
    min-height: 40px;
    border: 0;
    border-radius: var(--pos-v5-radius-md);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-caption);
    font-weight: var(--pos-v5-weight-extrabold);
    cursor: pointer;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
    padding: 0 var(--pos-v5-space-3);
}

.parked-orders-action:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.parked-orders-action-primary {
    background: var(--pos-v5-success);
    color: var(--pos-v5-ink-on-dark);
    box-shadow: var(--pos-v5-shadow-success);
}
.parked-orders-action-primary:hover:not(:disabled) {
    background: var(--pos-v5-success-dark);
}

.parked-orders-action-danger {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger-dark);
    border: 1px solid transparent;
}
.parked-orders-action-danger:hover:not(:disabled) {
    background: var(--pos-v5-danger-ghost);
    border-color: var(--pos-v5-danger);
}

/* Slide-in transitions */
.parked-orders-slide-enter-active,
.parked-orders-slide-leave-active {
    transition: opacity var(--pos-v5-duration-base);
}

.parked-orders-slide-enter-active .parked-orders-drawer,
.parked-orders-slide-leave-active .parked-orders-drawer {
    transition: transform var(--pos-v5-duration-slow) var(--pos-v5-ease-standard);
}

.parked-orders-slide-enter-from,
.parked-orders-slide-leave-to {
    opacity: 0;
}

.parked-orders-slide-enter-from .parked-orders-drawer,
.parked-orders-slide-leave-to .parked-orders-drawer {
    transform: translateX(100%);
}

@media (prefers-reduced-motion: reduce) {
    .parked-orders-slide-enter-active,
    .parked-orders-slide-leave-active,
    .parked-orders-slide-enter-active .parked-orders-drawer,
    .parked-orders-slide-leave-active .parked-orders-drawer { transition: none; }
    .parked-orders-card { transition: none; }
}
</style>
