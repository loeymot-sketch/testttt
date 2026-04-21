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

                <div class="parked-orders-body">
                    <div v-if="loading" class="parked-orders-empty">
                        {{ $t('label.loading') || 'Loading...' }}
                    </div>

                    <div v-else-if="parkedOrders.length === 0" class="parked-orders-empty">
                        {{ $t('pos.empty_parked_orders') }}
                    </div>

                    <article
                        v-for="order in parkedOrders"
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
        };
    },
    computed: {
        parkedOrders() {
            return this.$store.getters['posParked/list'] || [];
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
