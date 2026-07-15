<template>
    <!--
      [GOAL RUPTURE-CARNET 2026-07-15 / W2] Panel rupture partagé CAISSE + CUISINE.
      Le staff (POS Operator / Chef, permission `availability_toggle`) marque un
      produit en rupture (86) ou le réactive. Propagation backend existante :
      AvailabilityService::toggle → ItemAvailabilityChanged → outbox + invalidation
      cache kiosk-menu → borne / caisse / web se mettent à jour en temps réel.
      Fetch LOCAL (vuex:false) : ne contamine jamais le store `item/lists` des tuiles.
    -->
    <div
        v-if="visible"
        class="atp-overlay"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('availability.panel_title')"
        @click.self="$emit('close')"
    >
        <section class="atp-panel">
            <header class="atp-head">
                <h2 class="atp-title">{{ $t('availability.panel_title') }}</h2>
                <button type="button" class="atp-close" :aria-label="$t('button.close')" @click="$emit('close')">&times;</button>
            </header>

            <div class="atp-toolbar">
                <input
                    v-model.trim="search"
                    type="search"
                    class="atp-search"
                    :placeholder="$t('availability.search_placeholder')"
                    data-testid="availability-panel-search"
                />
                <button type="button" class="atp-refresh" :disabled="loading" @click="fetchItems">↻</button>
            </div>

            <p v-if="error" class="atp-error" role="alert">{{ error }}</p>
            <p v-else-if="loading" class="atp-loading">{{ $t('availability.loading') }}</p>

            <div v-else class="atp-body">
                <template v-if="ruptureItems.length > 0">
                    <h3 class="atp-section atp-section--rupture">
                        {{ $t('availability.section_rupture') }} ({{ ruptureItems.length }})
                    </h3>
                    <ul class="atp-list">
                        <li v-for="it in ruptureItems" :key="'r' + it.id" class="atp-row atp-row--rupture">
                            <span class="atp-name">{{ it.name }}</span>
                            <button
                                type="button"
                                class="atp-btn atp-btn--enable"
                                :disabled="!!busy[it.id]"
                                :data-testid="'availability-enable-' + it.id"
                                @click="toggle(it, true)"
                            >{{ busy[it.id] ? '…' : $t('availability.mark_available') }}</button>
                        </li>
                    </ul>
                </template>

                <h3 class="atp-section">{{ $t('availability.section_available') }} ({{ availableItems.length }})</h3>
                <ul class="atp-list">
                    <li v-for="it in availableItems" :key="'a' + it.id" class="atp-row">
                        <span class="atp-name">{{ it.name }}</span>
                        <button
                            type="button"
                            class="atp-btn atp-btn--disable"
                            :disabled="!!busy[it.id]"
                            :data-testid="'availability-disable-' + it.id"
                            @click="toggle(it, false)"
                        >{{ busy[it.id] ? '…' : $t('availability.mark_rupture') }}</button>
                    </li>
                </ul>
                <p v-if="filteredItems.length === 0" class="atp-empty">{{ $t('availability.empty') }}</p>
            </div>
        </section>
    </div>
</template>

<script>
export default {
    name: 'AvailabilityTogglePanel',
    props: {
        visible: { type: Boolean, default: false },
    },
    emits: ['close', 'changed'],
    data() {
        return {
            items: [],
            loading: false,
            error: null,
            search: '',
            busy: {},
        };
    },
    computed: {
        branchId() {
            const auth = this.$store.state.auth || {};
            const raw = auth.authBranchId
                ?? auth.authInfo?.branch_id
                ?? auth.authUser?.branch_id
                ?? null;
            const n = Number(raw);
            return Number.isFinite(n) && n > 0 ? n : null;
        },
        filteredItems() {
            const q = this.search.toLowerCase();
            if (!q) return this.items;
            return this.items.filter((it) => String(it.name || '').toLowerCase().includes(q));
        },
        ruptureItems() {
            return this.filteredItems.filter((it) => it.is_available === false);
        },
        availableItems() {
            return this.filteredItems.filter((it) => it.is_available !== false);
        },
    },
    watch: {
        visible(v) {
            if (v) this.fetchItems();
        },
    },
    methods: {
        fetchItems() {
            this.loading = true;
            this.error = null;
            const payload = { vuex: false, per_page: 500, order_column: 'name', order_type: 'asc' };
            if (this.branchId) payload.branch_id = this.branchId;
            this.$store.dispatch('item/lists', payload).then((res) => {
                this.items = (res?.data?.data || []).map((it) => ({
                    id: it.id,
                    name: it.name,
                    is_available: it.is_available !== false,
                    availability_reason: it.availability_reason || null,
                }));
            }).catch(() => {
                this.error = this.$t('availability.load_error');
            }).finally(() => {
                this.loading = false;
            });
        },
        toggle(item, isAvailable) {
            this.busy = { ...this.busy, [item.id]: true };
            this.$store.dispatch('itemAvailability/toggle', {
                itemId: item.id,
                branchId: this.branchId,
                isAvailable,
                unavailableReason: isAvailable ? null : 'stock_rupture',
            }).then(() => {
                const row = this.items.find((it) => it.id === item.id);
                if (row) {
                    row.is_available = isAvailable;
                    row.availability_reason = isAvailable ? null : 'stock_rupture';
                }
                this.$emit('changed', { itemId: item.id, isAvailable });
            }).catch(() => {
                this.error = this.$t('availability.toggle_error');
            }).finally(() => {
                const next = { ...this.busy };
                delete next[item.id];
                this.busy = next;
            });
        },
    },
};
</script>

<style scoped>
.atp-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 1200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.atp-panel {
    background: #fff;
    border-radius: 14px;
    width: min(560px, 100%);
    max-height: min(82dvh, 720px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
}
.atp-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: #1a1a1a;
    color: #fff;
}
/* color explicite : les styles admin globaux (h2 { color: … }) battent
   l'héritage du .atp-head — sans ça le titre est illisible sur fond noir. */
.atp-title { font-size: 17px; font-weight: 700; margin: 0; color: #fff; }
.atp-close {
    background: none;
    border: none;
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    padding: 2px 8px;
}
.atp-toolbar { display: flex; gap: 8px; padding: 12px 18px 4px; }
.atp-search {
    flex: 1;
    border: 1px solid #d5d5d5;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 15px;
}
.atp-refresh {
    border: 1px solid #d5d5d5;
    background: #fff;
    border-radius: 8px;
    padding: 0 14px;
    font-size: 17px;
    cursor: pointer;
}
.atp-body { overflow-y: auto; padding: 4px 18px 18px; }
.atp-section {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7280;
    margin: 14px 0 6px;
}
.atp-section--rupture { color: #dc2626; }
.atp-list { list-style: none; margin: 0; padding: 0; }
.atp-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid #f1f1f1;
}
.atp-row--rupture .atp-name { color: #dc2626; text-decoration: line-through; }
.atp-name { font-size: 15px; }
.atp-btn {
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    min-width: 118px;
}
.atp-btn:disabled { opacity: 0.55; cursor: wait; }
.atp-btn--disable { background: #fee2e2; color: #b91c1c; }
.atp-btn--enable { background: #dcfce7; color: #15803d; }
.atp-error { color: #b91c1c; padding: 10px 18px; }
.atp-loading, .atp-empty { color: #6b7280; padding: 10px 18px; }
</style>
