<template>
    <!-- [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Ajustement inventaire matière
         première — la seule porte d'écriture manuelle du domaine (RawMaterialStockService::
         adjust() existait, testée, sans appelant). Liste (GET admin/stock/unified-overview,
         axe raw_materials) + formulaire d'ajustement par ligne (POST admin/raw-materials/
         {id}/adjust, raison obligatoire) + historique des derniers ajustements (GET
         admin/raw-materials/{id}/movements). Palette Cayenne, mobile-friendly, lecture
         seule si l'utilisateur n'a pas items_create. -->
    <section class="rma" data-testid="raw-material-adjust">
        <header class="rma-header">
            <div class="rma-header-text">
                <h1 class="rma-title">{{ $t('admin.raw_material_adjust.title') }}</h1>
                <p class="rma-subtitle">{{ $t('admin.raw_material_adjust.subtitle') }}</p>
            </div>
            <div class="rma-header-actions">
                <span v-if="!canAdjust" class="rma-readonly" data-testid="rma-read-only">
                    {{ $t('admin.raw_material_adjust.read_only') }}
                </span>
                <button
                    type="button"
                    class="rma-btn rma-btn--refresh"
                    :disabled="loading"
                    data-testid="rma-refresh"
                    @click="load"
                >
                    {{ $t('admin.raw_material_adjust.refresh') }}
                </button>
            </div>
        </header>

        <div v-if="loading && materials.length === 0" class="rma-state rma-state--loading" data-testid="rma-loading">
            {{ $t('admin.raw_material_adjust.loading') }}
        </div>

        <div v-else-if="loadError" class="rma-state rma-state--error" data-testid="rma-load-error">
            <span>{{ $t('admin.raw_material_adjust.load_error') }}</span>
            <button type="button" class="rma-btn" data-testid="rma-retry" @click="load">
                {{ $t('admin.raw_material_adjust.retry') }}
            </button>
        </div>

        <template v-else>
            <div v-if="toast" class="rma-toast" :class="'rma-toast--' + toast.kind" role="status" aria-live="polite" data-testid="rma-toast">
                {{ toast.message }}
            </div>

            <input
                v-model="searchQuery"
                type="search"
                class="rma-search"
                :placeholder="$t('admin.raw_material_adjust.search')"
                data-testid="rma-search"
            />

            <p v-if="materials.length === 0" class="rma-state rma-state--empty" data-testid="rma-empty">
                {{ $t('admin.raw_material_adjust.empty') }}
            </p>

            <p v-else-if="filteredMaterials.length === 0" class="rma-empty-inline" data-testid="rma-no-match">
                {{ $t('admin.raw_material_adjust.no_match') }}
            </p>

            <ul v-else class="rma-list">
                <li
                    v-for="material in filteredMaterials"
                    :key="material.id"
                    class="rma-card"
                    :data-testid="'rma-material-' + material.id"
                >
                    <div class="rma-card-row">
                        <div class="rma-card-main">
                            <span class="rma-card-name">{{ material.name }}</span>
                            <span class="rma-pill" :class="'rma-pill--' + material.status">
                                {{ statusLabel(material.status) }}
                            </span>
                        </div>
                        <div class="rma-card-stock" :data-testid="'rma-onhand-' + material.id">
                            {{ formatQty(material.on_hand) }} {{ material.unit }}
                            <span v-if="material.threshold_low != null" class="rma-threshold">
                                · {{ $t('admin.raw_material_adjust.threshold') }} {{ formatQty(material.threshold_low) }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="rma-btn rma-btn--toggle"
                            :aria-expanded="openId === material.id ? 'true' : 'false'"
                            :data-testid="'rma-open-' + material.id"
                            @click="toggleRow(material)"
                        >
                            {{ openId === material.id ? $t('admin.raw_material_adjust.close') : $t('admin.raw_material_adjust.adjust_action') }}
                        </button>
                    </div>

                    <div v-if="openId === material.id" class="rma-panel" :data-testid="'rma-panel-' + material.id">
                        <form v-if="canAdjust" class="rma-form" @submit.prevent="submitAdjust(material)">
                            <label class="rma-field">
                                <span class="rma-field-label">{{ $t('admin.raw_material_adjust.field_target') }}</span>
                                <input
                                    v-model.number="form.target_on_hand"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    required
                                    class="rma-input"
                                    :data-testid="'rma-target-' + material.id"
                                />
                            </label>
                            <label class="rma-field">
                                <span class="rma-field-label">{{ $t('admin.raw_material_adjust.field_reason') }}</span>
                                <input
                                    v-model="form.reason"
                                    type="text"
                                    minlength="3"
                                    maxlength="64"
                                    required
                                    class="rma-input"
                                    :placeholder="$t('admin.raw_material_adjust.field_reason_placeholder')"
                                    :data-testid="'rma-reason-' + material.id"
                                />
                            </label>
                            <label class="rma-field">
                                <span class="rma-field-label">{{ $t('admin.raw_material_adjust.field_note') }}</span>
                                <textarea
                                    v-model="form.note"
                                    maxlength="255"
                                    rows="2"
                                    class="rma-input rma-textarea"
                                    :placeholder="$t('admin.raw_material_adjust.field_note_placeholder')"
                                    :data-testid="'rma-note-' + material.id"
                                ></textarea>
                            </label>

                            <p v-if="formError" class="rma-form-error" role="alert" data-testid="rma-form-error">
                                {{ formError }}
                            </p>

                            <div class="rma-form-actions">
                                <button
                                    type="submit"
                                    class="rma-btn rma-btn--submit"
                                    :disabled="submitting"
                                    :data-testid="'rma-submit-' + material.id"
                                >
                                    {{ submitting ? $t('admin.raw_material_adjust.saving') : $t('admin.raw_material_adjust.save') }}
                                </button>
                            </div>
                        </form>
                        <p v-else class="rma-empty-inline" data-testid="rma-readonly-panel">
                            {{ $t('admin.raw_material_adjust.read_only') }}
                        </p>

                        <div class="rma-history">
                            <h2 class="rma-history-title">{{ $t('admin.raw_material_adjust.history_title') }}</h2>
                            <p v-if="historyLoading" class="rma-empty-inline" :data-testid="'rma-history-loading-' + material.id">
                                {{ $t('admin.raw_material_adjust.loading') }}
                            </p>
                            <p v-else-if="history.length === 0" class="rma-empty-inline" :data-testid="'rma-history-empty-' + material.id">
                                {{ $t('admin.raw_material_adjust.history_empty') }}
                            </p>
                            <ul v-else class="rma-history-list" :data-testid="'rma-history-list-' + material.id">
                                <li v-for="row in history" :key="row.id" class="rma-history-row">
                                    <span class="rma-history-delta" :class="row.delta >= 0 ? 'rma-history-delta--pos' : 'rma-history-delta--neg'">
                                        {{ row.delta >= 0 ? '+' : '' }}{{ formatQty(row.delta) }}
                                    </span>
                                    <span class="rma-history-reason">{{ row.reason }}</span>
                                    <span v-if="row.note" class="rma-history-note">« {{ row.note }} »</span>
                                    <span class="rma-history-meta">
                                        {{ row.adjusted_by_name || $t('admin.raw_material_adjust.unknown_author') }}
                                        · {{ row.created_at_human }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>

<script>
import appService from '../../../services/appService';
import { buildIdempotencyHeaders } from '../../../helpers/idempotencyHeaders';

/**
 * [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] axios est global (bootstrap
 * Laravel window.axios, baseURL /api). La liste des matières réutilise l'endpoint
 * LECTURE existant `admin/stock/unified-overview` (axe raw_materials — id/name/
 * unit/on_hand/threshold_low/status) plutôt que de dupliquer une requête : cet
 * écran AJOUTE la seule pièce manquante (écriture + historique), il ne redéfinit
 * pas la vue stock.
 */
export default {
    name: 'RawMaterialAdjustComponent',
    data() {
        return {
            loading: false,
            loadError: false,
            materials: [],
            searchQuery: '',
            openId: null,
            form: { target_on_hand: 0, reason: '', note: '' },
            formError: '',
            submitting: false,
            history: [],
            historyLoading: false,
            toast: null,
            _toastTimer: null,
        };
    },
    computed: {
        canAdjust() {
            return appService.permissionChecker('items_create');
        },
        filteredMaterials() {
            const q = (this.searchQuery || '').trim().toLowerCase();
            if (!q) {
                return this.materials;
            }
            return this.materials.filter((m) => String(m.name || '').toLowerCase().includes(q));
        },
    },
    mounted() {
        this.load();
    },
    beforeUnmount() {
        if (this._toastTimer) {
            clearTimeout(this._toastTimer);
        }
    },
    methods: {
        async load() {
            this.loading = true;
            this.loadError = false;
            try {
                const response = await axios.get('admin/stock/unified-overview');
                this.materials = (response?.data?.raw_materials || []).slice();
            } catch (e) {
                this.loadError = true;
            } finally {
                this.loading = false;
            }
        },
        toggleRow(material) {
            if (this.openId === material.id) {
                this.openId = null;
                this.history = [];
                return;
            }
            this.openId = material.id;
            this.formError = '';
            this.form = { target_on_hand: this.roundQty(material.on_hand), reason: '', note: '' };
            this.loadHistory(material.id);
        },
        async loadHistory(rawMaterialId) {
            this.historyLoading = true;
            this.history = [];
            try {
                const response = await axios.get(`admin/raw-materials/${rawMaterialId}/movements`);
                this.history = response?.data?.movements || [];
            } catch (e) {
                // Historique optionnel — un échec de lecture ne bloque pas le formulaire d'ajustement.
                this.history = [];
            } finally {
                this.historyLoading = false;
            }
        },
        async submitAdjust(material) {
            this.formError = '';
            const payload = {
                target_on_hand: Number(this.form.target_on_hand),
                reason: String(this.form.reason || '').trim(),
                note: this.form.note ? String(this.form.note).trim() : undefined,
            };

            if (!payload.reason || payload.reason.length < 3) {
                this.formError = this.$t('admin.raw_material_adjust.error_reason_required');
                return;
            }
            if (Number.isNaN(payload.target_on_hand) || payload.target_on_hand < 0) {
                this.formError = this.$t('admin.raw_material_adjust.error_target_invalid');
                return;
            }

            this.submitting = true;
            try {
                const response = await axios.post(
                    `admin/raw-materials/${material.id}/adjust`,
                    payload,
                    { headers: buildIdempotencyHeaders(payload) }
                );
                const data = response?.data || {};
                const newOnHand = Number(data.on_hand ?? payload.target_on_hand);
                this.showToast('success', this.$t('admin.raw_material_adjust.success', {
                    name: material.name,
                    on_hand: this.formatQty(newOnHand),
                    unit: material.unit,
                }));
                this.form = { target_on_hand: this.roundQty(newOnHand), reason: '', note: '' };
                // [visual-test heal 2026-08-14] Ne PAS patcher juste `on_hand` en local : le
                // statut (OK/Bas/Rupture) est calculé côté backend (UnifiedStockViewService::
                // status()) et resterait périmé (pastille "RUPTURE" affichée alors que le stock
                // vient de repasser positif — vu à l'écran lors du test visuel Playwright/Chrome
                // MCP). Le backend reste la SSOT du statut (CLAUDE.md §3 règle 7) : on recharge
                // toute la liste plutôt que de dupliquer le seuillage low/out côté client.
                await this.load();
                this.loadHistory(material.id);
            } catch (e) {
                const status = e?.response?.status;
                if (status === 422) {
                    const errors = e?.response?.data?.errors || {};
                    const firstError = Object.values(errors)[0];
                    this.formError = Array.isArray(firstError) ? firstError[0] : this.$t('admin.raw_material_adjust.error_generic');
                } else if (status === 403) {
                    this.formError = this.$t('admin.raw_material_adjust.error_forbidden');
                } else {
                    this.formError = this.$t('admin.raw_material_adjust.error_generic');
                }
            } finally {
                this.submitting = false;
            }
        },
        showToast(kind, message) {
            this.toast = { kind, message };
            if (this._toastTimer) {
                clearTimeout(this._toastTimer);
            }
            this._toastTimer = setTimeout(() => {
                this.toast = null;
            }, 4000);
        },
        statusLabel(status) {
            return this.$t('admin.raw_material_adjust.status_' + status);
        },
        roundQty(value) {
            const n = Number(value);
            return Number.isFinite(n) ? Math.round(n * 1000) / 1000 : 0;
        },
        formatQty(value) {
            const n = Number(value);
            if (!Number.isFinite(n)) {
                return '0';
            }
            return String(Math.round(n * 1000) / 1000);
        },
    },
};
</script>

<style scoped>
/* Palette Cayenne : primary #F4501E, accent #FFB800, dark #1A1A1A. Light-mode. */
.rma {
    padding: 1rem;
    max-width: 900px;
    margin: 0 auto;
    color: #1a1a1a;
    font-size: 14px;
}

.rma-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.rma-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1a1a1a;
    margin: 0;
}

.rma-subtitle {
    color: #6b7280;
    margin: 0.25rem 0 0;
}

.rma-header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.rma-readonly {
    font-size: 0.78rem;
    background: #fff7e6;
    border: 1px solid #ffb800;
    color: #92600a;
    border-radius: 999px;
    padding: 0.3rem 0.75rem;
    font-weight: 600;
}

.rma-btn {
    min-height: 44px;
    padding: 0 1rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #1a1a1a;
    font-weight: 600;
    cursor: pointer;
}

.rma-btn--refresh {
    background: #f4501e;
    border-color: #f4501e;
    color: #fff;
}

.rma-btn--submit {
    background: #f4501e;
    border-color: #f4501e;
    color: #fff;
}

.rma-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.rma-state {
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

.rma-state--error {
    background: #fef2f2;
    color: #b91c1c;
}

.rma-toast {
    padding: 0.75rem 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    font-weight: 600;
}

.rma-toast--success {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}

.rma-search {
    width: 100%;
    min-height: 44px;
    padding: 0 0.9rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    font-size: 14px;
    margin-bottom: 1rem;
    box-sizing: border-box;
}

.rma-empty-inline {
    padding: 1rem 0.9rem;
    color: #9ca3af;
    text-align: center;
}

.rma-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.rma-card {
    border: 1px solid #eef0f3;
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
}

.rma-card-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    padding: 0.75rem 1rem;
}

.rma-card-main {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 200px;
    min-width: 0;
}

.rma-card-name {
    font-weight: 700;
    color: #1a1a1a;
}

.rma-card-stock {
    color: #374151;
    font-size: 0.85rem;
    white-space: nowrap;
}

.rma-threshold {
    color: #9ca3af;
}

.rma-pill {
    display: inline-block;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.rma-pill--ok {
    background: #dcfce7;
    color: #15803d;
}

.rma-pill--low {
    background: #fff3d1;
    color: #92600a;
}

.rma-pill--out {
    background: #fee2e2;
    color: #b91c1c;
}

.rma-panel {
    border-top: 1px solid #f1f2f4;
    background: #fafafa;
    padding: 1rem;
}

.rma-form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.rma-field {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.rma-field-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #374151;
}

.rma-input {
    min-height: 44px;
    padding: 0.5rem 0.75rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    font-size: 14px;
    box-sizing: border-box;
    font-family: inherit;
}

.rma-textarea {
    min-height: 60px;
    resize: vertical;
}

.rma-form-error {
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    margin: 0;
}

.rma-form-actions {
    display: flex;
    justify-content: flex-end;
}

.rma-history {
    border-top: 1px dashed #e5e7eb;
    padding-top: 0.75rem;
}

.rma-history-title {
    font-size: 0.9rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
    color: #1a1a1a;
}

.rma-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.rma-history-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    background: #fff;
    border: 1px solid #f1f2f4;
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    font-size: 0.8rem;
}

.rma-history-delta {
    font-weight: 800;
    min-width: 3.5rem;
}

.rma-history-delta--pos {
    color: #15803d;
}

.rma-history-delta--neg {
    color: #b91c1c;
}

.rma-history-reason {
    font-weight: 600;
    text-transform: capitalize;
}

.rma-history-note {
    color: #6b7280;
    font-style: italic;
}

.rma-history-meta {
    margin-left: auto;
    color: #9ca3af;
    white-space: nowrap;
}

@media (max-width: 640px) {
    .rma-card-row {
        flex-direction: column;
        align-items: flex-start;
    }

    .rma-btn--toggle {
        width: 100%;
    }

    .rma-history-meta {
        margin-left: 0;
    }
}
</style>
