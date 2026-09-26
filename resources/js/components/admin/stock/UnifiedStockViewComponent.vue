<template>
    <!-- [PHASE 3d-UI — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Écran LECTURE SEULE.
         Consomme GET admin/stock/unified-overview (UnifiedStockViewService::overview).
         « À acheter » EN HAUT, puis 2 rayons (matières / boissons revendues), totaux,
         recherche + filtre, bandeau coût manquant. Palette Cayenne, mobile-friendly. -->
    <section class="usv" data-testid="unified-stock-view">
        <header class="usv-header">
            <div class="usv-header-text">
                <h1 class="usv-title">{{ $t('admin.unified_stock.title') }}</h1>
                <p class="usv-subtitle">{{ $t('admin.unified_stock.subtitle') }}</p>
            </div>
            <div class="usv-header-actions">
                <span v-if="windowDays" class="usv-window" data-testid="usv-window">
                    {{ $t('admin.unified_stock.window_note', { days: windowDays }) }}
                </span>
                <button
                    type="button"
                    class="usv-btn usv-btn--refresh"
                    :disabled="loading"
                    data-testid="usv-refresh"
                    @click="load"
                >
                    {{ $t('admin.unified_stock.refresh') }}
                </button>
            </div>
        </header>

        <!-- [ONB-08 2026-08-28] Le refus d'un seuil doit se lire. Élément AUTONOME,
             hors de la chaîne v-if/v-else-if ci-dessous : l'y glisser orphelinait le
             bloc de chargement et faisait cohabiter deux états à l'écran. -->
        <p v-if="seuilErreur" class="usv-state usv-state--error" data-testid="usv-seuil-erreur">{{ seuilErreur }}</p>

        <!-- Loading (premier chargement) -->
        <div v-if="loading && !overview" class="usv-state usv-state--loading" data-testid="usv-loading">
            {{ $t('admin.unified_stock.loading') }}
        </div>

        <!-- Erreur -->
        <div v-else-if="error" class="usv-state usv-state--error" data-testid="usv-error">
            <span>{{ $t('admin.unified_stock.load_error') }}</span>
            <button type="button" class="usv-btn" data-testid="usv-retry" @click="load">
                {{ $t('admin.unified_stock.retry') }}
            </button>
        </div>

        <template v-else-if="overview">
            <!-- État vide global (DB sans matière ni boisson suivie) -->
            <div v-if="isEmpty" class="usv-state usv-state--empty" data-testid="usv-empty">
                {{ $t('admin.unified_stock.empty') }}
            </div>

            <template v-else>
                <!-- Bandeau coût manquant -->
                <div
                    v-if="hasMissingCost"
                    class="usv-banner usv-banner--warn"
                    data-testid="usv-missing-cost"
                >
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>{{ $t('admin.unified_stock.missing_cost_banner', { count: totals.missing_cost_count }) }}</span>
                </div>

                <!-- Totaux -->
                <div class="usv-totals" data-testid="usv-totals">
                    <div class="usv-total usv-total--value">
                        <span class="usv-total-label">{{ $t('admin.unified_stock.total_stock_value') }}</span>
                        <span class="usv-total-num" data-testid="usv-total-value">{{ formatMoney(totals.raw_material_stock_value) }}</span>
                    </div>
                    <div class="usv-total usv-total--out">
                        <span class="usv-total-label">{{ $t('admin.unified_stock.total_ruptures') }}</span>
                        <span class="usv-total-num" data-testid="usv-total-out">{{ totals.out_count || 0 }}</span>
                    </div>
                    <div class="usv-total usv-total--low">
                        <span class="usv-total-label">{{ $t('admin.unified_stock.total_low') }}</span>
                        <span class="usv-total-num" data-testid="usv-total-low">{{ totals.low_count || 0 }}</span>
                    </div>
                    <div class="usv-total usv-total--buy">
                        <span class="usv-total-label">{{ $t('admin.unified_stock.total_to_buy') }}</span>
                        <span class="usv-total-num" data-testid="usv-total-tobuy">{{ totals.to_buy_count || 0 }}</span>
                    </div>
                </div>

                <!-- Recherche + filtre -->
                <div class="usv-controls">
                    <input
                        v-model="searchQuery"
                        type="search"
                        class="usv-search"
                        :placeholder="$t('admin.unified_stock.search')"
                        data-testid="usv-search"
                    />
                    <div class="usv-filters" role="group" :aria-label="$t('admin.unified_stock.col_status')">
                        <button
                            v-for="f in filterOptions"
                            :key="f.key"
                            type="button"
                            class="usv-chip"
                            :class="{ 'usv-chip--active': statusFilter === f.key }"
                            :data-testid="'usv-filter-' + f.key"
                            @click="statusFilter = f.key"
                        >
                            {{ $t('admin.unified_stock.' + f.label) }}
                        </button>
                    </div>
                </div>

                <!-- 🛒 À ACHETER (EN HAUT) -->
                <section class="usv-section usv-section--buy" data-testid="usv-tobuy">
                    <h2 class="usv-section-title">{{ $t('admin.unified_stock.to_buy_title') }}</h2>
                    <p v-if="filteredToBuy.length === 0" class="usv-empty-inline" data-testid="usv-tobuy-empty">
                        {{ $t('admin.unified_stock.to_buy_empty') }}
                    </p>
                    <ul v-else class="usv-buy-list">
                        <li
                            v-for="row in filteredToBuy"
                            :key="row.kind + '-' + row.id"
                            class="usv-buy-item"
                            :data-testid="'usv-buy-' + row.kind + '-' + row.id"
                        >
                            <span class="usv-pill" :class="'usv-pill--' + row.status">{{ statusLabel(row.status) }}</span>
                            <span class="usv-buy-name">{{ row.name }}</span>
                            <span class="usv-buy-meta">
                                {{ formatQty(row.on_hand) }} {{ row.unit }}
                                <template v-if="row.threshold_low != null">
                                    · {{ $t('admin.unified_stock.col_threshold') }} {{ formatQty(row.threshold_low) }}
                                </template>
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- RAYON 1 — MATIÈRES PREMIÈRES -->
                <section class="usv-section" data-testid="usv-raw">
                    <h2 class="usv-section-title">
                        {{ $t('admin.unified_stock.raw_title') }}
                        <span class="usv-count">({{ rawMaterials.length }})</span>
                        <!--
                            [ONB-08 2026-08-28] La porte vers la DECLARATION des matieres.
                            Cet ecran-ci est en lecture seule (son docblock le dit), mais
                            il est celui ou le commercant regarde ses matieres : c'est
                            donc d'ici qu'il doit pouvoir en ajouter une.
                            Sans lien, l'ecran de declaration ne serait atteignable qu'en
                            tapant son URL — le defaut qu'on vient de corriger trois fois.
                        -->
                        <router-link :to="{ name: 'admin.stock.raw-materials' }"
                            class="usv-link" data-testid="usv-raw-declare">
                            {{ $t('label.raw_materials_manage') }}
                        </router-link>
                    </h2>
                    <div class="usv-table usv-table--raw" role="table">
                        <div class="usv-thead" role="row">
                            <span role="columnheader">{{ $t('admin.unified_stock.col_name') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_theo_stock') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_recent') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_threshold') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_avg_cost') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_value') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_status') }}</span>
                        </div>
                        <p v-if="rawMaterials.length === 0" class="usv-empty-inline" data-testid="usv-raw-empty">
                            {{ unFiltreEstActif ? $t('admin.unified_stock.no_match') : $t('admin.unified_stock.raw_vierge') }}
                        </p>
                        <div
                            v-for="row in rawMaterials"
                            :key="row.id"
                            class="usv-row"
                            role="row"
                            :data-testid="'usv-raw-' + row.id"
                        >
                            <span class="usv-cell usv-cell--name" role="cell" :data-label="$t('admin.unified_stock.col_name')">{{ row.name }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_theo_stock')">{{ formatQty(row.on_hand) }} {{ row.unit }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_recent')">{{ formatQty(row.recent_consumption) }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_threshold')">{{ row.threshold_low != null ? formatQty(row.threshold_low) : '—' }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_avg_cost')">
                                <template v-if="row.has_cost">{{ formatMoney(row.avg_cost) }}</template>
                                <span v-else class="usv-muted" :data-testid="'usv-raw-nocost-' + row.id">{{ $t('admin.unified_stock.no_cost') }}</span>
                            </span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_value')">{{ row.stock_value != null ? formatMoney(row.stock_value) : '—' }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_status')">
                                <span class="usv-pill" :class="'usv-pill--' + row.status">{{ statusLabel(row.status) }}</span>
                            </span>
                        </div>
                    </div>
                </section>

                <!-- RAYON 2 — PRODUITS REVENDUS / BOISSONS -->
                <section class="usv-section" data-testid="usv-resold">
                    <h2 class="usv-section-title">
                        {{ $t('admin.unified_stock.resold_title') }}
                        <span class="usv-count">({{ resoldProducts.length }})</span>
                    </h2>
                    <div class="usv-table usv-table--resold" role="table">
                        <div class="usv-thead" role="row">
                            <span role="columnheader">{{ $t('admin.unified_stock.col_name') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_unit_stock') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_recent') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_threshold') }}</span>
                            <span role="columnheader">{{ $t('admin.unified_stock.col_status') }}</span>
                        </div>
                        <p v-if="resoldProducts.length === 0" class="usv-empty-inline" data-testid="usv-resold-empty">
                            {{ unFiltreEstActif ? $t('admin.unified_stock.no_match') : $t('admin.unified_stock.resold_vierge') }}
                        </p>
                        <div
                            v-for="row in resoldProducts"
                            :key="row.id"
                            class="usv-row usv-row--resold"
                            role="row"
                            :data-testid="'usv-resold-' + row.id"
                        >
                            <span class="usv-cell usv-cell--name" role="cell" :data-label="$t('admin.unified_stock.col_name')">{{ row.name }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_unit_stock')">{{ row.on_hand }}</span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_recent')">{{ row.recent_consumption }}</span>
                            <!-- [ONB-08 2026-08-28] LE CHAMP QUI MANQUAIT.
                                 Cette colonne affichait « — » sur toutes les lignes, et
                                 pour cause : `stock_levels.threshold_low` etait LU par le
                                 tableau de bord des ruptures et par l'alerte de stock bas,
                                 et ECRIT par personne. 55 lignes en base, 0 seuil. La
                                 section « alertes stock bas » ne pouvait donc
                                 structurellement rien afficher. -->
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_threshold')">
                                <input
                                    type="number"
                                    min="0"
                                    class="usv-seuil"
                                    :value="row.threshold_low"
                                    :disabled="seuilEnCours === row.stock_level_id"
                                    :data-testid="'usv-seuil-' + row.id"
                                    :aria-label="$t('admin.unified_stock.col_threshold') + ' — ' + row.name"
                                    placeholder="—"
                                    @change="enregistrerLeSeuil(row, $event.target.value)"
                                />
                            </span>
                            <span class="usv-cell" role="cell" :data-label="$t('admin.unified_stock.col_status')">
                                <span class="usv-pill" :class="'usv-pill--' + row.status">{{ statusLabel(row.status) }}</span>
                            </span>
                        </div>
                    </div>
                </section>
            </template>
        </template>
    </section>
</template>

<script>
/**
 * [PHASE 3d-UI 2026-07-24] Vue conso & stock unifiée (lecture seule).
 * axios est global (bootstrap Laravel window.axios, baseURL /api). Aucune
 * dépendance $store : la branche est résolue côté serveur (admin branch_id=0
 * → branche 1). Aucune écriture, hors NF525.
 */
export default {
    name: 'UnifiedStockViewComponent',
    data() {
        return {
            // [ONB-08 2026-08-28] Ligne en cours d'enregistrement : on grise le champ
            // pour qu'un double envoi ne parte pas sur une valeur intermediaire.
            seuilEnCours: null,
            seuilErreur: '',
            loading: false,
            error: false,
            overview: null,
            searchQuery: '',
            statusFilter: 'all',
            filterOptions: [
                { key: 'all', label: 'filter_all' },
                { key: 'to_buy', label: 'filter_to_buy' },
                { key: 'out', label: 'filter_out' },
                { key: 'low', label: 'filter_low' },
            ],
        };
    },
    computed: {
        totals() {
            return this.overview?.totals || {};
        },
        windowDays() {
            return this.overview?.window_days || null;
        },
        hasMissingCost() {
            return (this.totals.missing_cost_count || 0) > 0;
        },
        /**
         * [ONB-11 2026-08-28] Un filtre est-il REELLEMENT pose ?
         *
         * L'etat par defaut est « recherche vide, tous les statuts ». Un rayon vide
         * dans cet etat ne veut pas dire « votre filtre est trop etroit », il veut
         * dire « vous n'avez rien declare » — ce qui est la situation NORMALE d'une
         * installation qui demarre.
         *
         * Sans cette distinction, `isEmpty()` ci-dessous exige que les DEUX rayons
         * soient vides : une seule boisson revendue suffisait a faire afficher
         * « Aucun element ne correspond au filtre » au rayon des matieres, alors
         * qu'aucun filtre n'etait pose.
         */
        unFiltreEstActif() {
            return (this.searchQuery || '').trim() !== '' || this.statusFilter !== 'all';
        },
        isEmpty() {
            if (!this.overview) {
                return false;
            }
            const raw = this.overview.raw_materials || [];
            const resold = this.overview.resold_products || [];
            return raw.length === 0 && resold.length === 0;
        },
        filteredToBuy() {
            return this.applyFilters(this.overview?.to_buy || []);
        },
        rawMaterials() {
            return this.applyFilters(this.overview?.raw_materials || []);
        },
        resoldProducts() {
            return this.applyFilters(this.overview?.resold_products || []);
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        /**
         * [ONB-08 2026-08-28] Enregistre le seuil d'alerte d'une ligne de stock.
         *
         * Champ vide = pas de surveillance. Un seuil qu'on ne peut plus retirer ne
         * serait pas un reglage, et `null` est la valeur qu'un formulaire perd le
         * plus facilement en route.
         */
        async enregistrerLeSeuil(ligne, valeur) {
            const id = ligne?.stock_level_id;
            if (!id) return;
    
            const brut = String(valeur ?? '').trim();
            const seuil = brut === '' ? null : Number(brut);
    
            this.seuilEnCours = id;
            this.seuilErreur = '';
            try {
                const res = await axios.put(`admin/stock/levels/${id}/seuil`, { threshold_low: seuil });
                const donnees = res?.data?.data ?? {};
                ligne.threshold_low = donnees.threshold_low ?? null;
                ligne.status = donnees.en_alerte ? 'low' : (ligne.on_hand <= 0 ? 'out' : 'ok');
            } catch (e) {
                const messages = e?.response?.data?.errors?.threshold_low;
                // Le refus doit etre VISIBLE : un enregistrement qui echoue en
                // silence laisse le patron croire son seuil pose. C'est l'invariant
                // que ce chantier applique partout ailleurs.
                this.seuilErreur = messages
                    ? messages[0]
                    : this.$t('admin.unified_stock.seuil_refuse');
            } finally {
                this.seuilEnCours = null;
            }
        },
        async load() {
            this.loading = true;
            this.error = false;
            try {
                const response = await axios.get('admin/stock/unified-overview');
                this.overview = response?.data || null;
            } catch (e) {
                this.error = true;
            } finally {
                this.loading = false;
            }
        },
        applyFilters(rows) {
            const q = (this.searchQuery || '').trim().toLowerCase();
            const filter = this.statusFilter;
            return (rows || []).filter((row) => {
                if (q && !String(row.name || '').toLowerCase().includes(q)) {
                    return false;
                }
                if (filter === 'to_buy') {
                    return row.status !== 'ok';
                }
                if (filter === 'out') {
                    return row.status === 'out';
                }
                if (filter === 'low') {
                    return row.status === 'low';
                }
                return true;
            });
        },
        statusLabel(status) {
            return this.$t('admin.unified_stock.status_' + status);
        },
        formatMoney(value) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) {
                return '—';
            }
            try {
                return new Intl.NumberFormat('fr-FR', {
                    style: 'currency',
                    currency: 'EUR',
                }).format(Number(value));
            } catch (e) {
                return Number(value).toFixed(2) + ' €';
            }
        },
        formatQty(value) {
            const n = Number(value);
            if (!Number.isFinite(n)) {
                return '0';
            }
            // Retire les décimales inutiles (3.000 → 3 ; 1.500 → 1.5).
            return String(Math.round(n * 1000) / 1000);
        },
    },
};
</script>

<style scoped>
/* Palette Cayenne : primary #F4501E, accent #FFB800, dark #1A1A1A. Light-mode. */
.usv {
    padding: 1rem;
    max-width: 1200px;
    margin: 0 auto;
    color: #1a1a1a;
    font-size: 14px;
}

.usv-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.usv-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1a1a1a;
    margin: 0;
}

.usv-subtitle {
    color: #6b7280;
    margin: 0.25rem 0 0;
}

.usv-header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.usv-window {
    font-size: 0.8rem;
    color: #6b7280;
    white-space: nowrap;
}

.usv-btn {
    min-height: 44px;
    padding: 0 1rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #1a1a1a;
    font-weight: 600;
    cursor: pointer;
}

.usv-btn--refresh {
    background: #f4501e;
    border-color: #f4501e;
    color: #fff;
}

.usv-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.usv-state {
    padding: 2rem 1rem;
    text-align: center;
    border-radius: 12px;
    background: #f9fafb;
    color: #6b7280;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
}

.usv-state--error {
    background: #fef2f2;
    color: #b91c1c;
}

.usv-banner {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    font-weight: 600;
}

.usv-banner--warn {
    background: #fff7e6;
    border: 1px solid #ffb800;
    color: #92600a;
}

.usv-totals {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.usv-total {
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.usv-total-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6b7280;
}

.usv-total-num {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1a1a1a;
}

.usv-total--value .usv-total-num {
    color: #f4501e;
}

.usv-total--out .usv-total-num {
    color: #dc2626;
}

.usv-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 1rem;
}

.usv-search {
    flex: 1 1 220px;
    min-height: 44px;
    padding: 0 0.9rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    font-size: 14px;
}

.usv-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.usv-chip {
    min-height: 44px;
    padding: 0 0.9rem;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151;
    font-weight: 600;
    cursor: pointer;
}

.usv-chip--active {
    background: #1a1a1a;
    border-color: #1a1a1a;
    color: #fff;
}

.usv-section {
    margin-bottom: 1.5rem;
}

.usv-section-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.6rem;
    color: #1a1a1a;
}

.usv-count {
    color: #9ca3af;
    font-weight: 500;
    font-size: 0.9rem;
}

.usv-section--buy {
    background: #fff8f5;
    border: 1px solid #f4501e33;
    border-radius: 14px;
    padding: 0.9rem 1rem;
}

.usv-buy-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 0.5rem;
}

.usv-buy-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: #fff;
    border: 1px solid #f1e4de;
    border-radius: 10px;
    padding: 0.6rem 0.75rem;
    min-height: 44px;
}

.usv-buy-name {
    font-weight: 700;
    flex: 1 1 auto;
}

.usv-buy-meta {
    color: #6b7280;
    font-size: 0.8rem;
    white-space: nowrap;
}

/* Tables en grille (desktop) → cartes empilées (mobile) */
.usv-table {
    border: 1px solid #eef0f3;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.usv-thead,
.usv-row {
    display: grid;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.9rem;
}

.usv-table--raw .usv-thead,
.usv-table--raw .usv-row {
    grid-template-columns: 2fr 1.1fr 1.1fr 1fr 1.1fr 1.1fr 0.9fr;
}

.usv-table--resold .usv-thead,
.usv-table--resold .usv-row {
    grid-template-columns: 2fr 1.1fr 1.1fr 1fr 0.9fr;
}

.usv-thead {
    background: #f9fafb;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6b7280;
    font-weight: 700;
}

.usv-row {
    border-top: 1px solid #f1f2f4;
}

.usv-cell--name {
    font-weight: 700;
    color: #1a1a1a;
}

.usv-muted {
    color: #b91c1c;
    font-style: italic;
}

.usv-empty-inline {
    padding: 1rem 0.9rem;
    color: #9ca3af;
    text-align: center;
}

.usv-pill {
    display: inline-block;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.usv-pill--ok {
    background: #dcfce7;
    color: #15803d;
}

.usv-pill--low {
    background: #fff3d1;
    color: #92600a;
}

.usv-pill--out {
    background: #fee2e2;
    color: #b91c1c;
}

@media (max-width: 767px) {
    .usv-totals {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .usv-thead {
        display: none;
    }

    .usv-table--raw .usv-row,
    .usv-table--resold .usv-row {
        display: block;
        grid-template-columns: none;
        padding: 0.75rem 0.9rem;
    }

    .usv-cell {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        min-height: 44px;
        border-bottom: 1px dashed #f1f2f4;
    }

    .usv-cell:last-child {
        border-bottom: 0;
    }

    .usv-cell::before {
        content: attr(data-label);
        font-weight: 700;
        color: #6b7280;
        font-size: 0.75rem;
        text-transform: uppercase;
    }

    .usv-cell--name {
        font-size: 1.05rem;
        border-bottom: 2px solid #f4501e33;
    }

    .usv-cell--name::before {
        display: none;
    }
}
</style>
