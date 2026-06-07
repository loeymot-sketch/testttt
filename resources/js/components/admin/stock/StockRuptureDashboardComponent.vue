<template>
    <!--
        StockRuptureDashboardComponent — V2 Unified Catalogue Browser
        Mission 1 — Stock-Rupture UI Simplification (Owner spec 2026-05-21).

        Plan: plans/PLAN_MISSION_1_STOCK_RUPTURE_SIMPLIFICATION_2026-05-21.md

        ONE page. Left rail = all browsable buckets (item categories + extra
        groups + variation attributes). Right pane = product cards with a
        binary in-stock / out-of-stock toggle per product.

        Backend SSOT:
          - GET  /api/admin/stock/catalog-overview                  (read)
          - POST /api/admin/menu/availability/toggle                (items)
          - POST /api/admin/menu/availability/extra/toggle          (extras)
          - POST /api/admin/menu/availability/variation/toggle      (variations)

        Deduped axes:
          - Extras grouped by `group_label`, deduped by name. Toggling
            "Algérienne" cascades to all underlying extra_ids in one batch.
          - Variations grouped by item_attribute_id, deduped by name. Toggling
            "Tomate" cascades to all underlying variation_ids in one batch.

        Real-time sync via Echo on `private-branch.{branchId}` for the 3
        availability events; 60s polling fallback for admin (branch_id=0).
    -->
    <section
        class="stock-mgmt-v2 space-y-4"
        data-testid="stock-management-v2"
        :aria-busy="loading"
    >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    {{ $t('admin.stock_mgmt.title') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $t('admin.stock_mgmt.subtitle') }}
                </p>
            </div>
            <!-- [STOCK-05 FIX] Dead branch-filter removed. `branchOptions` was never
                 populated (catalogOverview returns branch_id but no branches list),
                 so this <select> + canFilterByBranch never rendered — a misleading
                 affordance. V1 is single-branch (Le Cayenne, branch_id=1); the
                 `branchId` data field is kept because it still scopes the API params. -->
        </header>

        <div
            v-if="errorMessage"
            class="rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"
            role="alert"
            aria-live="polite"
            data-testid="stock-mgmt-error"
        >
            {{ errorMessage }}
        </div>

        <div class="layout grid grid-cols-1 gap-4 md:grid-cols-[260px,1fr]">
            <!-- Left rail: bucket list -->
            <nav
                class="rail rounded border border-slate-200 bg-white p-2"
                data-testid="stock-mgmt-rail"
                :aria-label="$t('admin.stock_mgmt.title')"
            >
                <ul class="space-y-1">
                    <li
                        v-for="bucket in buckets"
                        :key="bucket.key"
                    >
                        <button
                            type="button"
                            class="w-full flex items-center justify-between gap-2 rounded px-3 py-2 text-left text-sm transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                            :class="bucket.key === activeBucketKey ? 'bg-slate-900 text-white hover:bg-slate-900' : 'text-slate-700'"
                            :data-testid="`stock-mgmt-bucket-${bucket.key}`"
                            :aria-current="bucket.key === activeBucketKey ? 'true' : 'false'"
                            @click="activeBucketKey = bucket.key"
                        >
                            <span class="flex items-center gap-2">
                                <span aria-hidden="true">{{ bucket.icon }}</span>
                                <span>{{ bucket.label }}</span>
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="bucket.key === activeBucketKey ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ bucket.count }}
                            </span>
                        </button>
                    </li>
                </ul>
                <p
                    v-if="buckets.length === 0 && !loading"
                    class="px-3 py-4 text-xs text-slate-500"
                >
                    {{ $t('admin.stock_mgmt.empty') }}
                </p>
            </nav>

            <!-- Right pane: product grid -->
            <main class="pane rounded border border-slate-200 bg-white p-3">
                <div class="mb-3 flex items-center gap-2">
                    <input
                        v-model="searchQuery"
                        type="search"
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                        :placeholder="$t('admin.stock_mgmt.search')"
                        data-testid="stock-mgmt-search"
                        :aria-label="$t('admin.stock_mgmt.search')"
                    />
                    <span
                        v-if="!canEditAvailability"
                        class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700"
                        data-testid="stock-mgmt-read-only"
                    >
                        {{ $t('admin.stock_mgmt.read_only') }}
                    </span>
                </div>

                <p
                    v-if="loading && filteredProducts.length === 0"
                    class="px-2 py-6 text-center text-sm text-slate-500"
                    aria-live="polite"
                >
                    {{ $t('admin.stock_mgmt.loading') }}
                </p>

                <p
                    v-else-if="filteredProducts.length === 0"
                    class="px-2 py-6 text-center text-sm text-slate-500"
                    data-testid="stock-mgmt-empty"
                >
                    <!-- [Wave Final S6 2026-05-23] Differentiate the empty
                         message: "no result" when the user typed a search
                         query that excluded everything, vs "no products in
                         category" when the bucket is genuinely empty.
                         Same FR-only V1 polish. -->
                    {{ searchQuery && searchQuery.trim()
                        ? $t('admin.stock_mgmt.empty_search')
                        : $t('admin.stock_mgmt.empty') }}
                </p>

                <div
                    v-else
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <article
                        v-for="product in filteredProducts"
                        :key="product.key"
                        class="product-card flex items-center justify-between gap-3 rounded border border-slate-200 bg-white p-3"
                        :data-testid="`stock-mgmt-product-${product.key}`"
                    >
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <img
                                v-if="product.thumb"
                                :src="product.thumb"
                                alt=""
                                class="h-12 w-12 rounded object-cover bg-slate-100 flex-shrink-0"
                                @error="onThumbError"
                            />
                            <span
                                v-else
                                class="h-12 w-12 rounded bg-slate-100 flex items-center justify-center text-xl flex-shrink-0"
                                aria-hidden="true"
                            >📦</span>
                            <span class="name text-sm font-medium text-slate-800 min-w-0 flex-1" :title="product.name">
                                {{ product.name }}
                            </span>
                        </div>

                        <button
                            v-if="canEditAvailability"
                            type="button"
                            role="switch"
                            :aria-checked="product.is_available ? 'true' : 'false'"
                            :class="[
                                'toggle inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                                product.is_available
                                    ? 'in-stock bg-emerald-100 text-emerald-800 hover:bg-emerald-200'
                                    : 'out-of-stock bg-rose-100 text-rose-800 hover:bg-rose-200',
                                busyKeys[product.key] ? 'opacity-60 cursor-wait' : '',
                            ]"
                            :disabled="busyKeys[product.key]"
                            :data-testid="`stock-mgmt-toggle-${product.key}`"
                            @click="toggleProduct(product)"
                        >
                            {{ product.is_available ? $t('admin.stock_mgmt.in_stock') : $t('admin.stock_mgmt.out_of_stock') }}
                        </button>
                        <span
                            v-else
                            :class="[
                                'inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold whitespace-nowrap',
                                product.is_available
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-rose-100 text-rose-800',
                            ]"
                            :data-testid="`stock-mgmt-toggle-${product.key}`"
                            role="img"
                            :aria-label="product.is_available ? $t('admin.stock_mgmt.in_stock') : $t('admin.stock_mgmt.out_of_stock')"
                        >
                            {{ product.is_available ? $t('admin.stock_mgmt.in_stock') : $t('admin.stock_mgmt.out_of_stock') }}
                        </span>
                    </article>
                </div>
            </main>
        </div>
    </section>
</template>

<script>
import appService from "../../../services/appService";
import { onEvents } from "../../../services/eventContract";

/**
 * StockRuptureDashboardComponent V2 — owner spec 2026-05-21.
 *
 * Mission 1 rewrite: drops the multi-axis V1 dashboard (cron status, low
 * alerts, bulk multi-select, reason modal) and replaces it with a single
 * unified browser. Binary toggle per product. Sync flows through the
 * existing AvailabilityService SSOT — zero new mutating endpoint.
 *
 * Permissions:
 *   - items_show : read (gated server-side on /stock/catalog-overview)
 *   - items_edit : toggle (gated client-side: read-only badge fallback)
 *
 * Echo channel: `branch.{branchId}` (`onEvents` prepends `private-`).
 *   - branch_id=0 admins: polling fallback only.
 *   - branch_id>0 staff:  Echo subscribe → debounced loadAll on event.
 */

const DEBOUNCE_MS = 500;

const DEFAULT_REASON = 'out_of_stock_manual';

const CATEGORY_ICONS = {
    burgers: '🍔',
    burger: '🍔',
    tacos: '🌮',
    bols: '🥗',
    bol: '🥗',
    frites: '🍟',
    boissons: '🥤',
    boisson: '🥤',
    drink: '🥤',
    drinks: '🥤',
    dessert: '🍰',
    desserts: '🍰',
    menu: '🍱',
    menus: '🍱',
    formule: '🍱',
    formules: '🍱',
    sauce: '🧂',
    sauces: '🧂',
    crudite: '🥬',
    crudites: '🥬',
    'crudités': '🥬',
    viande: '🥩',
    viandes: '🥩',
    taille: '📏',
    tailles: '📏',
    supplement: '➕',
    suppléments: '➕',
    supplements: '➕',
};

function pickIcon(label) {
    const slug = String(label || '').toLowerCase().trim();
    if (CATEGORY_ICONS[slug]) return CATEGORY_ICONS[slug];
    for (const key of Object.keys(CATEGORY_ICONS)) {
        if (slug.includes(key)) return CATEGORY_ICONS[key];
    }
    return '📦';
}

function debounce(fn, ms) {
    let t = null;
    return function (...args) {
        if (t) clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), ms);
    };
}

export default {
    name: 'StockRuptureDashboardComponent',
    props: {
        pollIntervalMs: { type: Number, default: 60_000 },
    },
    data() {
        return {
            loading: false,
            // [STOCK-05 FIX] branchId retained — it still scopes the read/toggle
            // API params (loadAll, toggle*). The dead branch-options array (never
            // populated) was removed along with the misleading branch select.
            branchId: 0,
            categories: [],
            extraGroups: [],
            variationGroups: [],
            activeBucketKey: '',
            searchQuery: '',
            busyKeys: {},
            errorMessage: '',
            // Echo lifecycle
            _timer: null,
            _visibilityHandler: null,
            _echoSubscription: null,
            _debouncedReload: null,
        };
    },
    computed: {
        canEditAvailability() {
            return appService.permissionChecker('items_edit');
        },
        // [STOCK-05 FIX] canFilterByBranch removed — its only consumer was the dead
        // branch <select>, and branchOptions (its data dependency) was never populated.
        buckets() {
            const buckets = [];

            // Item categories.
            (this.categories || []).forEach((cat) => {
                const items = (cat.items || []).map((it) => ({
                    key: `item-${it.id}`,
                    kind: 'item',
                    name: it.name,
                    thumb: it.thumb || null,
                    is_available: Boolean(it.is_available),
                    item_id: Number(it.id),
                }));
                buckets.push({
                    key: `cat-${cat.id}`,
                    kind: 'item',
                    label: cat.name,
                    icon: pickIcon(cat.name),
                    count: items.length,
                    items,
                });
            });

            // Extra groups (deduped by name within group_label).
            (this.extraGroups || []).forEach((group) => {
                const items = (group.items || []).map((it) => ({
                    key: `extra-${group.group_label}-${it.name}`,
                    kind: 'extra',
                    name: it.name,
                    thumb: it.thumb || null,
                    is_available: Boolean(it.is_available),
                    extra_ids: Array.isArray(it.extra_ids) ? it.extra_ids.slice() : [],
                }));
                buckets.push({
                    key: `extra-${group.group_label}`,
                    kind: 'extra',
                    label: group.display_name || group.group_label,
                    icon: pickIcon(group.display_name || group.group_label),
                    count: items.length,
                    items,
                });
            });

            // Variation attributes (deduped by name within attribute).
            (this.variationGroups || []).forEach((group) => {
                const items = (group.items || []).map((it) => ({
                    key: `var-${group.attribute_id}-${it.name}`,
                    kind: 'variation',
                    name: it.name,
                    thumb: it.thumb || null,
                    is_available: Boolean(it.is_available),
                    variation_ids: Array.isArray(it.variation_ids) ? it.variation_ids.slice() : [],
                }));
                buckets.push({
                    key: `var-${group.attribute_id}`,
                    kind: 'variation',
                    label: group.attribute_name,
                    icon: pickIcon(group.attribute_name),
                    count: items.length,
                    items,
                });
            });

            return buckets;
        },
        activeBucket() {
            return this.buckets.find((b) => b.key === this.activeBucketKey) || null;
        },
        filteredProducts() {
            const bucket = this.activeBucket;
            if (!bucket) return [];
            const q = (this.searchQuery || '').trim().toLowerCase();
            if (!q) return bucket.items;
            return bucket.items.filter((p) => String(p.name || '').toLowerCase().includes(q));
        },
    },
    watch: {
        buckets(newBuckets) {
            // Preserve active selection across reloads; fall back to first
            // non-"Autres" bucket so the admin lands on a meaningful default
            // even when the misc bucket sorts first alphabetically.
            if (newBuckets.length === 0) {
                this.activeBucketKey = '';
                return;
            }
            if (!newBuckets.find((b) => b.key === this.activeBucketKey)) {
                this.activeBucketKey = this.pickDefaultBucketKey(newBuckets);
            }
        },
    },
    mounted() {
        this._debouncedReload = debounce(() => { this.loadAll(); }, DEBOUNCE_MS);
        this.loadAll();
        this._timer = setInterval(this.loadAll, this.pollIntervalMs);
        this._visibilityHandler = () => {
            if (!document.hidden) this.loadAll();
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
        this.subscribeEcho();
    },
    beforeUnmount() {
        if (this._timer) clearInterval(this._timer);
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        }
        this.unsubscribeEcho();
    },
    methods: {
        // [Wave M1 round-3 A-009] Skip "Autres" / "Autres ingrédients" buckets
        // for the default-active selection — those are misc/unsorted buckets
        // and shouldn't be the first thing the admin sees. Falls back to the
        // first bucket if every bucket is "Autres" (defensive).
        pickDefaultBucketKey(list) {
            if (!Array.isArray(list) || list.length === 0) return '';
            const isAutres = (b) => {
                const lbl = String(b?.label || '').trim().toLowerCase();
                return lbl === 'autres' || lbl.startsWith('autres ');
            };
            const firstMeaningful = list.find((b) => !isAutres(b));
            return (firstMeaningful || list[0]).key;
        },

        authBranchId() {
            try {
                const fromGetter = this.$store?.getters?.['auth/authBranchId'];
                if (fromGetter !== undefined && fromGetter !== null && fromGetter !== '') {
                    return Number(fromGetter) || 0;
                }
                const fromState = this.$store?.state?.auth?.authBranchId;
                if (fromState !== undefined && fromState !== null && fromState !== '') {
                    return Number(fromState) || 0;
                }
            } catch (_) {}
            return 0;
        },

        onThumbError(event) {
            if (event && event.target) {
                event.target.style.display = 'none';
            }
        },

        // ---------- Echo lifecycle ----------
        subscribeEcho() {
            if (typeof window === 'undefined' || !window.Echo) return;
            const branchId = this.authBranchId();
            if (branchId <= 0) return;
            this.unsubscribeEcho();
            try {
                this._echoSubscription = onEvents(branchId, [
                    { broadcastAs: 'ItemAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                    { broadcastAs: 'ItemVariationAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                    { broadcastAs: 'ItemExtraAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                ]);
            } catch (e) {
                // Best-effort: Echo is optional in some environments (tests, offline mode).
                if (typeof console !== 'undefined') {
                    console.warn('[StockMgmtV2] Echo subscription failed:', e?.message);
                }
            }
        },
        unsubscribeEcho() {
            try {
                this._echoSubscription?.unsubscribe?.();
            } catch (_) {}
            this._echoSubscription = null;
        },

        // ---------- Data load ----------
        async loadAll() {
            if (typeof document !== 'undefined' && document.hidden) return;
            this.loading = true;
            this.errorMessage = '';
            try {
                const params = {};
                if (Number(this.branchId) > 0) {
                    params.branch_id = Number(this.branchId);
                }
                const response = await axios.get('admin/stock/catalog-overview', { params });
                const data = response.data || {};
                this.categories = Array.isArray(data.categories) ? data.categories : [];
                this.extraGroups = Array.isArray(data.extra_groups) ? data.extra_groups : [];
                this.variationGroups = Array.isArray(data.variation_groups) ? data.variation_groups : [];
                // Resolve branch options + canonical branchId on first load.
                const resolvedBranchId = Number(data.branch_id) || 0;
                if (resolvedBranchId > 0 && !Number(this.branchId)) {
                    this.branchId = resolvedBranchId;
                }
                // Default active bucket = first non-"Autres" bucket.
                if (!this.activeBucketKey && this.buckets.length > 0) {
                    this.activeBucketKey = this.pickDefaultBucketKey(this.buckets);
                }
            } catch (err) {
                this.errorMessage = this.$t('admin.stock_mgmt.loading_error');
            } finally {
                this.loading = false;
            }
        },

        // ---------- Toggle ----------
        async toggleProduct(product) {
            if (!product || !this.canEditAvailability) return;
            const key = product.key;
            if (this.busyKeys[key]) return;

            this.busyKeys = { ...this.busyKeys, [key]: true };
            this.errorMessage = '';
            const previous = product.is_available;
            const next = !previous;
            // Optimistic flip on the local state.
            product.is_available = next;

            try {
                if (product.kind === 'item') {
                    await this.sendItemToggle(product.item_id, next);
                } else if (product.kind === 'extra') {
                    await this.sendBulkToggle(
                        product.extra_ids,
                        next,
                        (id) => axios.post('admin/menu/availability/extra/toggle', {
                            extra_id: Number(id),
                            branch_id: Number(this.branchId),
                            is_available: next,
                            ...(next ? {} : { reason: DEFAULT_REASON }),
                        }),
                    );
                } else if (product.kind === 'variation') {
                    await this.sendBulkToggle(
                        product.variation_ids,
                        next,
                        (id) => axios.post('admin/menu/availability/variation/toggle', {
                            variation_id: Number(id),
                            branch_id: Number(this.branchId),
                            is_available: next,
                            ...(next ? {} : { reason: DEFAULT_REASON }),
                        }),
                    );
                }
            } catch (err) {
                // Rollback optimistic flip.
                product.is_available = previous;
                this.errorMessage = this.$t('admin.stock_mgmt.toggle_error');
            } finally {
                const nextBusy = { ...this.busyKeys };
                delete nextBusy[key];
                this.busyKeys = nextBusy;
            }
        },

        async sendItemToggle(itemId, isAvailable) {
            await axios.post('admin/menu/availability/toggle', {
                item_id: Number(itemId),
                branch_id: Number(this.branchId),
                is_available: !!isAvailable,
            });
        },

        /**
         * Fan-out N toggle POSTs in small batches with an inter-batch delay.
         *
         * [M1 round-2 A-005/A-006/A-013 fix 2026-05-21] Reduced from
         * concurrency=5 (continuous) to concurrency=2 batched + 100ms gap
         * between batches. Reason: bulk fan-out from the V2 unified browser
         * was tripping the `throttle:60,1` bucket on
         * /admin/menu/availability/{extra,variation}/toggle (routes/api.php
         * group "[BLUE 2026-05-08 / B3-S5 P1]"). 9 POSTs in 1.4s under
         * concurrency-5 triggered 429s; the rejected hits stayed counted in
         * the rate-limiter window and cascaded into POS catalog reload
         * returning 429 ("Aucune donnée disponible"). Batching at 2/100ms
         * caps the effective rate at ~20 RPS while still keeping a
         * 6-item fan-out under ~400ms end-to-end (acceptable UX).
         *
         * All-or-nothing semantics from the user's point of view: any
         * single failure rolls back the optimistic flip.
         */
        async sendBulkToggle(ids, _next, makeRequest) {
            const arr = (ids || []).map(Number).filter((n) => n > 0);
            if (arr.length === 0) return;
            const concurrency = 2;
            const interBatchDelayMs = 100;
            let firstError = null;
            for (let i = 0; i < arr.length; i += concurrency) {
                const batch = arr.slice(i, i + concurrency);
                const results = await Promise.allSettled(batch.map((id) => makeRequest(id)));
                for (const r of results) {
                    if (r.status === 'rejected' && !firstError) {
                        firstError = r.reason;
                    }
                }
                if (i + concurrency < arr.length) {
                    await new Promise((resolve) => setTimeout(resolve, interBatchDelayMs));
                }
            }
            if (firstError) throw firstError;
        },
    },
};
</script>

<style scoped>
.stock-mgmt-v2 .product-card {
    transition: box-shadow 0.15s ease;
}
.stock-mgmt-v2 .product-card:hover {
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
}
/* [Wave M1 round-3 A-012 truncation regression]
 * `truncate` (single-line ellipsis) clipped names to "Sa..." / "Big..." even
 * with enough horizontal room because the flex parent didn't have min-w-0.
 * Replace with a 2-line clamp + word-break so longer names wrap gracefully
 * inside the card width. The :title attribute remains for hover affordance. */
.stock-mgmt-v2 .product-card .name {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.25;
}
</style>
