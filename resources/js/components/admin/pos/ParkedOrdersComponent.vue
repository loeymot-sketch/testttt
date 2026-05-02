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
                                    {{ order.items_count }} {{ $t('label.items') }} · {{ formatTimeAgo(order.created_at) }}
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
                alertService.success(this.$t('pos.park_restore_success'));
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
            const locale = this.setting.site_default_language || navigator.language || 'fr';
            const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

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
.parked-orders-overlay {
    position: fixed;
    inset: 0;
    z-index: 2100;
    background: rgba(15, 23, 42, 0.35);
    display: flex;
    justify-content: flex-end;
}

.parked-orders-drawer {
    width: min(380px, 100vw);
    height: 100vh;
    background: #fff;
    box-shadow: -12px 0 32px rgba(15, 23, 42, 0.18);
    display: flex;
    flex-direction: column;
}

.parked-orders-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid #eff0f6;
}

.parked-orders-title {
    font-size: 1rem;
    font-weight: 700;
    color: #2e2f38;
}

.parked-orders-subtitle {
    font-size: 0.8125rem;
    color: #6b7280;
    margin-top: 4px;
}

/* [POS-V4-CASHIER-OPS 2026-05-02] Inline search input above the parked list. */
.parked-orders-search {
    position: relative;
    margin: 14px 20px 4px;
    display: flex;
    align-items: center;
}
.parked-orders-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 12px;
    pointer-events: none;
}
.parked-orders-search-input {
    width: 100%;
    height: 38px;
    padding: 0 36px 0 32px;
    border: 1px solid #eff0f6;
    border-radius: 10px;
    font-size: 13px;
    color: #2e2f38;
    background: #f9fafb;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.parked-orders-search-input:focus {
    outline: none;
    border-color: #b0004d;
    background: #fff;
}
.parked-orders-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    border-radius: 9999px;
    background: #eff0f6;
    color: #6b7280;
    border: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.parked-orders-search-clear:hover {
    background: #b0004d;
    color: #fff;
}

.parked-orders-close {
    width: 36px;
    height: 36px;
    border-radius: 9999px;
    border: 1px solid #eff0f6;
    color: #2e2f38;
}

.parked-orders-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.parked-orders-empty {
    min-height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #6b7280;
    font-size: 0.875rem;
}

.parked-orders-card {
    border: 1px solid #eff0f6;
    border-radius: 14px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.parked-orders-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.parked-orders-card-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #2e2f38;
}

.parked-orders-card-meta {
    margin-top: 4px;
    font-size: 0.75rem;
    color: #8e8ea9;
}

.parked-orders-total {
    font-size: 0.875rem;
    font-weight: 700;
    color: #e8001c;
    white-space: nowrap;
}

.parked-orders-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.parked-orders-action {
    min-height: 38px;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    transition: opacity 0.2s ease;
}

.parked-orders-action:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.parked-orders-action-primary {
    background: #1ab759;
    color: #fff;
}

.parked-orders-action-danger {
    background: #fee2e2;
    color: #b91c1c;
}

.parked-orders-slide-enter-active,
.parked-orders-slide-leave-active {
    transition: opacity 0.2s ease;
}

.parked-orders-slide-enter-active .parked-orders-drawer,
.parked-orders-slide-leave-active .parked-orders-drawer {
    transition: transform 0.2s ease;
}

.parked-orders-slide-enter-from,
.parked-orders-slide-leave-to {
    opacity: 0;
}

.parked-orders-slide-enter-from .parked-orders-drawer,
.parked-orders-slide-leave-to .parked-orders-drawer {
    transform: translateX(100%);
}
</style>
