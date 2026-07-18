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
                    <li v-for="it in availableItems" :key="'a' + it.id" class="atp-item">
                        <div class="atp-row">
                            <span class="atp-name">{{ it.name }}</span>
                            <div class="atp-actions">
                                <button
                                    type="button"
                                    class="atp-btn atp-btn--options"
                                    :aria-expanded="expanded[it.id] ? 'true' : 'false'"
                                    :data-testid="'availability-options-' + it.id"
                                    @click="toggleExpand(it)"
                                >{{ (expanded[it.id] ? '▾ ' : '▸ ') + $t('availability.options') }}</button>
                                <button
                                    type="button"
                                    class="atp-btn atp-btn--disable"
                                    :disabled="!!busy[it.id]"
                                    :data-testid="'availability-disable-' + it.id"
                                    @click="toggle(it, false)"
                                >{{ busy[it.id] ? '…' : $t('availability.mark_rupture') }}</button>
                            </div>
                        </div>

                        <!-- [D1] Rupture ciblée extra / variation (sauce, supplément, taille…). -->
                        <div v-if="expanded[it.id]" class="atp-choices">
                            <p v-if="choicesLoading[it.id]" class="atp-choices-info">{{ $t('availability.options_loading') }}</p>
                            <p v-else-if="choicesError[it.id]" class="atp-choices-info atp-choices-info--error" role="alert">{{ choicesError[it.id] }}</p>
                            <template v-else-if="choices[it.id]">
                                <template v-if="choices[it.id].variations.length || choices[it.id].extras.length">
                                    <template v-if="choices[it.id].variations.length">
                                        <h4 class="atp-subsection">{{ $t('availability.section_variations') }}</h4>
                                        <ul class="atp-list atp-list--choices">
                                            <li
                                                v-for="v in choices[it.id].variations"
                                                :key="'v' + v.id"
                                                class="atp-row atp-row--choice"
                                                :class="{ 'atp-row--rupture': v.is_available === false }"
                                            >
                                                <span class="atp-name atp-name--choice">
                                                    <span v-if="v.attribute" class="atp-choice-group">{{ v.attribute }} · </span>{{ v.name }}
                                                </span>
                                                <button
                                                    type="button"
                                                    class="atp-btn"
                                                    :class="v.is_available === false ? 'atp-btn--enable' : 'atp-btn--disable'"
                                                    :disabled="!!choiceBusy['variation-' + v.id]"
                                                    :data-testid="'availability-variation-toggle-' + v.id"
                                                    @click="toggleChoice(it, v, 'variation')"
                                                >{{ choiceBusy['variation-' + v.id] ? '…' : (v.is_available === false ? $t('availability.mark_available') : $t('availability.mark_rupture')) }}</button>
                                            </li>
                                        </ul>
                                    </template>
                                    <template v-if="choices[it.id].extras.length">
                                        <h4 class="atp-subsection">{{ $t('availability.section_extras') }}</h4>
                                        <ul class="atp-list atp-list--choices">
                                            <li
                                                v-for="e in choices[it.id].extras"
                                                :key="'e' + e.id"
                                                class="atp-row atp-row--choice"
                                                :class="{ 'atp-row--rupture': e.is_available === false }"
                                            >
                                                <span class="atp-name atp-name--choice">
                                                    <span v-if="e.group_label" class="atp-choice-group">{{ e.group_label }} · </span>{{ e.name }}
                                                </span>
                                                <button
                                                    type="button"
                                                    class="atp-btn"
                                                    :class="e.is_available === false ? 'atp-btn--enable' : 'atp-btn--disable'"
                                                    :disabled="!!choiceBusy['extra-' + e.id]"
                                                    :data-testid="'availability-extra-toggle-' + e.id"
                                                    @click="toggleChoice(it, e, 'extra')"
                                                >{{ choiceBusy['extra-' + e.id] ? '…' : (e.is_available === false ? $t('availability.mark_available') : $t('availability.mark_rupture')) }}</button>
                                            </li>
                                        </ul>
                                    </template>
                                </template>
                                <p v-else class="atp-choices-info">{{ $t('availability.options_none') }}</p>
                            </template>
                        </div>
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
            // [D1] État par item pour la rupture ciblée extra/variation.
            expanded: {},        // itemId -> bool (bloc options déplié)
            choices: {},         // itemId -> { extras: [...], variations: [...] }
            choicesLoading: {},  // itemId -> bool
            choicesError: {},    // itemId -> message
            choiceBusy: {},      // 'extra-<id>' | 'variation-<id>' -> bool
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
            // Reset l'état options : un refresh recharge les dispos à la demande.
            this.expanded = {};
            this.choices = {};
            this.choicesLoading = {};
            this.choicesError = {};
            this.choiceBusy = {};
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

        // [D1] Déplie/replie le bloc options d'un item ; charge à la demande.
        toggleExpand(item) {
            const willExpand = !this.expanded[item.id];
            this.expanded = { ...this.expanded, [item.id]: willExpand };
            if (willExpand && !this.choices[item.id] && !this.choicesLoading[item.id]) {
                this.fetchChoices(item);
            }
        },

        // [D1] Charge extras + variations (branch-aware) via l'endpoint EXISTANT
        // item/details (NormalItemResource) — accessible au caissier/chef via
        // l'exemption `availability_toggle` du ItemController (contrairement à
        // item/show qui exige items_show). Fetch local : ne touche pas le store item.
        fetchChoices(item) {
            this.choicesLoading = { ...this.choicesLoading, [item.id]: true };
            const clearedErr = { ...this.choicesError };
            delete clearedErr[item.id];
            this.choicesError = clearedErr;

            const payload = { id: item.id };
            if (this.branchId) payload.branch_id = this.branchId;

            this.$store.dispatch('item/details', payload).then((res) => {
                const data = res?.data?.data || {};
                const extras = (Array.isArray(data.extras) ? data.extras : []).map((e) => ({
                    id: e.id,
                    name: e.name,
                    group_label: e.group_label || null,
                    is_available: e.is_available !== false,
                }));
                // NormalItemResource renvoie variations groupées par item_attribute_id
                // (objet). On aplatit en liste plate pour l'affichage.
                const variationGroups = data.variations || {};
                const variations = [];
                Object.keys(variationGroups).forEach((gid) => {
                    const grp = variationGroups[gid];
                    if (!Array.isArray(grp)) return;
                    grp.forEach((v) => {
                        variations.push({
                            id: v.id,
                            name: v.name,
                            attribute: v.item_attribute && v.item_attribute.name ? v.item_attribute.name : null,
                            is_available: v.is_available !== false,
                        });
                    });
                });
                this.choices = { ...this.choices, [item.id]: { extras, variations } };
            }).catch(() => {
                this.choicesError = { ...this.choicesError, [item.id]: this.$t('availability.options_error') };
            }).finally(() => {
                const next = { ...this.choicesLoading };
                delete next[item.id];
                this.choicesLoading = next;
            });
        },

        // [D1] 86 / réactivation d'un extra ou d'une variation précis.
        // kind ∈ {'extra','variation'}. Cible = inverse de l'état courant.
        toggleChoice(item, choice, kind) {
            const target = choice.is_available === false; // en rupture → réactive ; dispo → 86
            const busyKey = kind + '-' + choice.id;
            this.choiceBusy = { ...this.choiceBusy, [busyKey]: true };

            const action = kind === 'extra' ? 'itemAvailability/toggleExtra' : 'itemAvailability/toggleVariation';
            const payload = kind === 'extra'
                ? { extraId: choice.id, branchId: this.branchId, isAvailable: target, reason: target ? null : 'out_of_stock_manual' }
                : { variationId: choice.id, branchId: this.branchId, isAvailable: target, reason: target ? null : 'out_of_stock_manual' };

            this.$store.dispatch(action, payload).then(() => {
                choice.is_available = target;
                this.$emit('changed', { kind, id: choice.id, itemId: item.id, isAvailable: target });
            }).catch(() => {
                this.error = this.$t('availability.toggle_error');
            }).finally(() => {
                const next = { ...this.choiceBusy };
                delete next[busyKey];
                this.choiceBusy = next;
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

/* [D1] Rupture ciblée extra / variation */
.atp-item { border-bottom: 1px solid #f1f1f1; }
.atp-item > .atp-row { border-bottom: none; }
.atp-actions { display: flex; align-items: center; gap: 8px; }
.atp-btn--options {
    background: #f3f4f6;
    color: #374151;
    min-width: 0;
    padding: 8px 12px;
}
.atp-choices {
    padding: 2px 0 12px 14px;
    margin: 0 0 4px;
    border-left: 3px solid #f0b8ad;
}
.atp-choices-info { color: #6b7280; font-size: 13px; padding: 6px 0; margin: 0; }
.atp-choices-info--error { color: #b91c1c; }
.atp-subsection {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9ca3af;
    margin: 8px 0 2px;
}
.atp-list--choices .atp-row--choice { padding: 7px 0; border-bottom: 1px solid #f7f7f7; }
.atp-name--choice { font-size: 14px; }
.atp-choice-group { color: #9ca3af; font-weight: 600; }
</style>
