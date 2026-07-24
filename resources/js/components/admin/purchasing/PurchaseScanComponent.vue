<template>
    <div class="purchase-scan">
        <header class="ps-head">
            <h1 class="ps-title">Scan de facture</h1>
            <p class="ps-sub">
                Photographie une facture fournisseur — l'IA propose les entrées en stock,
                tu valides d'un tap.
            </p>
        </header>

        <!-- Bandeau mode démo (aucune clé OpenAI) -->
        <div v-if="!openaiEnabled" class="ps-banner ps-banner--demo" data-testid="demo-banner">
            <i class="fa-solid fa-flask"></i>
            <span>
                Mode démo (lecture simulée) — pose ta clé OpenAI pour lire les vraies factures.
            </span>
        </div>

        <!-- Zone d'upload / drag -->
        <div class="ps-upload">
            <label
                class="ps-drop"
                :class="{ 'is-dragging': dragging }"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input
                    ref="file"
                    type="file"
                    accept="image/*"
                    class="ps-file"
                    data-testid="file-input"
                    @change="onFileChange"
                />
                <i class="fa-solid fa-camera ps-drop-icon"></i>
                <span class="ps-drop-label">{{ fileName || 'Choisir ou déposer une photo de facture' }}</span>
            </label>
            <button
                type="button"
                class="ps-btn ps-btn--primary"
                data-testid="scan-btn"
                :disabled="!file || scanning"
                @click="scan"
            >
                <span v-if="scanning">Lecture en cours…</span>
                <span v-else>Scanner la facture</span>
            </button>
        </div>

        <div v-if="error" class="ps-banner ps-banner--error" data-testid="error-banner">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>{{ error }}</span>
        </div>
        <div v-if="successMessage" class="ps-banner ps-banner--success" data-testid="success-banner">
            <i class="fa-solid fa-circle-check"></i>
            <span>{{ successMessage }}</span>
        </div>

        <!-- Propositions -->
        <section v-if="proposals.length" class="ps-results" data-testid="proposals">
            <h2 class="ps-results-title">
                {{ proposals.length }} ligne(s) lue(s)
                <span class="ps-doc-status" :class="'is-' + (document && document.status)">
                    {{ document && document.status === 'validated' ? 'appliqué' : 'brouillon' }}
                </span>
            </h2>

            <table class="ps-table">
                <thead>
                    <tr>
                        <th>Libellé lu</th>
                        <th class="ps-num">Qté</th>
                        <th>Unité</th>
                        <th class="ps-num">Prix (€)</th>
                        <th>Cible</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in proposals"
                        :key="line.id"
                        data-testid="proposal-row"
                        :class="{ 'is-unmatched': line.matched === false }"
                    >
                        <td data-label="Libellé">
                            <span class="ps-label">{{ line.raw_label }}</span>
                            <span class="ps-tags">
                                <span
                                    v-if="line.status === 'proposed'"
                                    class="ps-ai"
                                    data-testid="ai-badge"
                                >proposé par IA</span>
                                <span
                                    v-if="hasScore(line)"
                                    class="ps-score"
                                    data-testid="score-badge"
                                    :class="scoreClass(line.score)"
                                >{{ scorePct(line.score) }}%</span>
                                <span v-if="line.matched === false" class="ps-warn">à confirmer</span>
                            </span>
                        </td>
                        <td data-label="Qté" class="ps-num">
                            <input
                                type="number" min="0" step="0.001"
                                class="ps-input ps-input--num"
                                :disabled="isApplied"
                                v-model.number="line.qty"
                            />
                        </td>
                        <td data-label="Unité">{{ line.unit }}</td>
                        <td data-label="Prix" class="ps-num">
                            <input
                                type="number" min="0" step="0.0001"
                                class="ps-input ps-input--num"
                                :disabled="isApplied"
                                v-model.number="line.unit_price"
                            />
                        </td>
                        <td data-label="Cible">
                            <select
                                class="ps-input"
                                data-testid="target-type"
                                :disabled="isApplied"
                                v-model="line.target_type"
                                @change="onTargetTypeChange(line)"
                            >
                                <option value="raw_material">Matière première</option>
                                <option value="stock_item">Produit revendu (boisson)</option>
                                <option value="charge">Charge sans stock</option>
                            </select>

                            <select
                                v-if="line.target_type === 'raw_material'"
                                class="ps-input ps-input--target"
                                data-testid="target-raw"
                                :disabled="isApplied"
                                v-model.number="line.target_id"
                            >
                                <option :value="null">— choisir la matière —</option>
                                <option v-for="m in rawMaterials" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <select
                                v-else-if="line.target_type === 'stock_item'"
                                class="ps-input ps-input--target"
                                data-testid="target-item"
                                :disabled="isApplied"
                                v-model.number="line.target_id"
                            >
                                <option :value="null">— choisir le produit —</option>
                                <option v-for="d in drinkItems" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                            <span v-else class="ps-muted">Aucun stock</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="ps-actions">
                <button
                    v-if="!isApplied"
                    type="button"
                    class="ps-btn ps-btn--primary ps-btn--lg"
                    data-testid="validate-btn"
                    :disabled="validating || !canValidate"
                    @click="validate"
                >
                    <span v-if="validating">Application…</span>
                    <span v-else>Valider l'entrée en stock</span>
                </button>
                <p v-if="!isApplied && !canValidate" class="ps-hint" data-testid="validate-hint">
                    Choisis une matière/produit pour chaque ligne concernée avant de valider.
                </p>
            </div>
        </section>
    </div>
</template>

<script>
import axios from 'axios';

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Écran ADMIN de scan de facture.
 *
 * Rend le pipeline P3a+P3b utilisable : upload photo → POST /purchasing/scan
 * (propositions IA) → tableau éditable (cible pré-remplie par l'IA + score) →
 * « Valider l'entrée en stock » → POST /purchasing/{id}/validate (applique au
 * stock via PurchaseService : matière/boisson/charge, avg_cost pondéré, idempotent).
 *
 * Aucun prix/aucune donnée fiscale ici — domaine ADDITIF, HORS NF525. Le flag
 * OpenAI (window.foodkingConfig.purchasing.openaiEnabled) pilote le bandeau démo.
 */
export default {
    name: 'PurchaseScanComponent',
    data() {
        return {
            openaiEnabled: false,
            file: null,
            fileName: '',
            dragging: false,
            scanning: false,
            validating: false,
            error: '',
            successMessage: '',
            document: null,
            proposals: [],
            rawMaterials: [],
            drinkItems: [],
        };
    },
    computed: {
        isApplied() {
            return !!(this.document && this.document.status === 'validated');
        },
        /** Toute ligne matière/produit doit avoir une cible choisie avant validation. */
        canValidate() {
            return this.proposals.every((line) => {
                if (line.target_type === 'charge') {
                    return true;
                }
                return !!line.target_id;
            });
        },
    },
    mounted() {
        this.openaiEnabled = this.readOpenaiFlag();
        this.fetchTargets();
    },
    methods: {
        readOpenaiFlag() {
            const cfg = typeof window !== 'undefined' ? window.foodkingConfig : null;
            return !!(cfg && cfg.purchasing && cfg.purchasing.openaiEnabled);
        },
        async fetchTargets() {
            try {
                const { data } = await axios.get('admin/purchasing/targets');
                this.rawMaterials = (data && data.raw_materials) || [];
                this.drinkItems = (data && data.drink_items) || [];
            } catch (e) {
                // Non bloquant : l'écran reste utilisable, dropdowns vides le temps du retry.
                this.rawMaterials = [];
                this.drinkItems = [];
            }
        },
        onFileChange(event) {
            const files = event && event.target && event.target.files;
            this.setFile(files && files[0]);
        },
        onDrop(event) {
            this.dragging = false;
            const files = event && event.dataTransfer && event.dataTransfer.files;
            this.setFile(files && files[0]);
        },
        setFile(file) {
            this.file = file || null;
            this.fileName = file ? file.name : '';
            this.error = '';
            this.successMessage = '';
        },
        async scan() {
            if (!this.file || this.scanning) {
                return;
            }
            this.scanning = true;
            this.error = '';
            this.successMessage = '';
            try {
                const form = new FormData();
                form.append('photo', this.file);
                const { data } = await axios.post('admin/purchasing/scan', form, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                this.applyScanResponse(data);
            } catch (e) {
                this.error = this.extractError(e, "La lecture de la facture a échoué.");
            } finally {
                this.scanning = false;
            }
        },
        applyScanResponse(data) {
            this.document = (data && data.document) || null;
            this.proposals = ((data && data.proposals) || []).map((p) => ({ ...p }));
            if (data && data.idempotent) {
                this.successMessage = 'Facture déjà scannée — propositions existantes rechargées.';
            }
        },
        onTargetTypeChange(line) {
            // Changer de type invalide la cible précédente (sauf « charge » = sans cible).
            line.target_id = null;
        },
        async validate() {
            if (this.validating || !this.document || !this.canValidate) {
                return;
            }
            this.validating = true;
            this.error = '';
            this.successMessage = '';
            try {
                const payload = {
                    lines: this.proposals.map((line) => ({
                        id: line.id,
                        target_type: line.target_type,
                        target_id: line.target_type === 'charge' ? null : line.target_id,
                        qty: line.qty,
                        unit_price: line.unit_price,
                    })),
                };
                const { data } = await axios.post(
                    'admin/purchasing/' + this.document.id + '/validate',
                    payload,
                );
                this.applyValidateResponse(data);
            } catch (e) {
                this.error = this.extractError(e, "La validation en stock a échoué.");
            } finally {
                this.validating = false;
            }
        },
        applyValidateResponse(data) {
            this.document = (data && data.document) || this.document;
            this.proposals = ((data && data.proposals) || this.proposals).map((p) => ({ ...p }));
            const applied = (data && data.applied && data.applied.applied) || {};
            const raw = applied.raw_material || 0;
            const item = applied.stock_item || 0;
            const charge = applied.charge || 0;
            this.successMessage =
                'Entrée en stock validée — ' +
                raw + ' matière(s), ' + item + ' produit(s), ' + charge + ' charge(s).';
        },
        hasScore(line) {
            return line && line.score !== null && line.score !== undefined;
        },
        scorePct(score) {
            return Math.round((Number(score) || 0) * 100);
        },
        scoreClass(score) {
            const pct = this.scorePct(score);
            if (pct >= 75) return 'ps-score--high';
            if (pct >= 50) return 'ps-score--mid';
            return 'ps-score--low';
        },
        extractError(e, fallback) {
            const res = e && e.response;
            if (res && res.data && res.data.message) {
                return res.data.message;
            }
            return fallback;
        },
    },
};
</script>

<style scoped>
.purchase-scan {
    --ps-primary: #F4501E;
    --ps-accent: #FFB800;
    --ps-dark: #1A1A1A;
    max-width: 1080px;
    margin: 0 auto;
    padding: 20px 16px 64px;
    color: var(--ps-dark);
}

.ps-head {
    margin-bottom: 18px;
}
.ps-title {
    font-size: 26px;
    font-weight: 800;
    margin: 0 0 4px;
    color: var(--ps-dark);
}
.ps-sub {
    margin: 0;
    color: #5b5b5b;
    font-size: 15px;
}

.ps-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 12px;
    margin: 14px 0;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}
.ps-banner--demo {
    background: #FFF6E0;
    color: #8a6100;
    border: 1px solid #FFE0A3;
}
.ps-banner--error {
    background: #FDECEA;
    color: #A32217;
    border: 1px solid #F5C2BD;
}
.ps-banner--success {
    background: #E9F8EE;
    color: #1B7A3D;
    border: 1px solid #B7E7C6;
}

.ps-upload {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
    gap: 12px;
    margin: 8px 0 6px;
}
.ps-drop {
    flex: 1 1 260px;
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 64px;
    padding: 12px 18px;
    border: 2px dashed #d5d5d5;
    border-radius: 14px;
    background: #fafafa;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.ps-drop.is-dragging,
.ps-drop:hover {
    border-color: var(--ps-primary);
    background: #FFF3EF;
}
.ps-drop-icon {
    font-size: 22px;
    color: var(--ps-primary);
}
.ps-drop-label {
    font-weight: 600;
    color: #444;
    word-break: break-word;
}
.ps-file {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    overflow: hidden;
}

.ps-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 0 22px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: transform .05s, filter .15s, background .15s;
}
.ps-btn:active {
    transform: translateY(1px);
}
.ps-btn--primary {
    background: var(--ps-primary);
    color: #fff;
}
.ps-btn--primary:hover:not(:disabled) {
    filter: brightness(1.05);
}
.ps-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}
.ps-btn--lg {
    min-height: 54px;
    padding: 0 32px;
    font-size: 16px;
}

.ps-results {
    margin-top: 22px;
}
.ps-results-title {
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 12px;
}
.ps-doc-status {
    font-size: 12px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    background: #EEE;
    color: #555;
}
.ps-doc-status.is-validated {
    background: #E9F8EE;
    color: #1B7A3D;
}

.ps-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 0 #ececec, 0 8px 24px rgba(0, 0, 0, .04);
}
.ps-table th,
.ps-table td {
    text-align: left;
    padding: 12px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}
.ps-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #8a8a8a;
    background: #fafafa;
}
.ps-table .ps-num {
    text-align: right;
    white-space: nowrap;
}
.ps-table tr.is-unmatched td {
    background: #FFFBF0;
}

.ps-label {
    font-weight: 600;
    display: block;
}
.ps-tags {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}
.ps-ai {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    background: #EDE7FF;
    color: #5B3FBF;
}
.ps-score {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    color: #fff;
}
.ps-score--high { background: #1B7A3D; }
.ps-score--mid { background: var(--ps-accent); color: #5a4300; }
.ps-score--low { background: #B0403A; }
.ps-warn {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    background: #FDECEA;
    color: #A32217;
}

.ps-input {
    width: 100%;
    min-height: 44px;
    padding: 8px 10px;
    border: 1px solid #d9d9d9;
    border-radius: 10px;
    font-size: 14px;
    background: #fff;
    color: var(--ps-dark);
}
.ps-input:focus {
    outline: none;
    border-color: var(--ps-primary);
    box-shadow: 0 0 0 3px rgba(244, 80, 30, .15);
}
.ps-input--num {
    max-width: 120px;
    text-align: right;
}
.ps-input--target {
    margin-top: 8px;
}
.ps-muted {
    color: #999;
    font-size: 13px;
    font-style: italic;
}

.ps-actions {
    margin-top: 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ps-hint {
    margin: 0;
    font-size: 13px;
    color: #8a6100;
}

/* Mobile : la table passe en cartes empilées (l'owner scanne au tél). */
@media (max-width: 720px) {
    .ps-table,
    .ps-table thead,
    .ps-table tbody,
    .ps-table th,
    .ps-table td,
    .ps-table tr {
        display: block;
    }
    .ps-table thead {
        display: none;
    }
    .ps-table tr {
        border-bottom: 8px solid #f3f3f3;
        padding: 6px 0;
    }
    .ps-table td {
        border: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 8px 14px;
    }
    .ps-table td::before {
        content: attr(data-label);
        font-size: 12px;
        font-weight: 700;
        color: #8a8a8a;
        text-transform: uppercase;
    }
    .ps-table .ps-num {
        text-align: right;
    }
    .ps-input--num {
        max-width: 140px;
    }
    .ps-input--target {
        margin-top: 0;
    }
}
</style>
