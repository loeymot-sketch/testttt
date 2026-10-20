<template>
    <ErrorBoundary>
        <LoadingComponent :props="loadingProps" />

        <div class="db-card db-tab-div active upsell-preview">
            <div class="db-card-header border-none">
                <div>
                    <h3 class="db-card-title">{{ $t('kiosk.admin.upsell_preview_title') }}</h3>
                    <p class="upsell-preview-subtitle">
                        {{ $t('kiosk.admin.upsell_preview_subtitle') }}
                    </p>
                </div>
            </div>

            <div class="upsell-preview-form">
                <div class="upsell-preview-row">
                    <label class="upsell-preview-label" for="up-branch">
                        {{ $t('label.branch') }}
                    </label>
                    <select
                        id="up-branch"
                        v-model="selectedBranchId"
                        class="db-field-control"
                    >
                        <option v-if="branches.length === 0" :value="null" disabled>
                            {{ $t('kiosk.admin.upsell_preview_no_branch') }}
                        </option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">
                            {{ b.name }}
                        </option>
                    </select>
                </div>

                <div class="upsell-preview-row">
                    <label class="upsell-preview-label" for="up-strategy">
                        {{ $t('kiosk.admin.upsell_preview_strategy') }}
                    </label>
                    <select
                        id="up-strategy"
                        v-model="selectedStrategy"
                        class="db-field-control"
                    >
                        <option value="rule_based">rule_based</option>
                        <option value="ml_placeholder">ml_placeholder</option>
                    </select>
                </div>

                <fieldset class="upsell-preview-cart">
                    <legend>{{ $t('kiosk.admin.upsell_preview_cart_items') }}</legend>
                    <div
                        v-for="(line, idx) in cartItems"
                        :key="idx"
                        class="upsell-preview-cart-line"
                    >
                        <input
                            type="number"
                            min="1"
                            :placeholder="$t('kiosk.admin.upsell_preview_item_id')"
                            class="db-field-control upsell-preview-cart-input"
                            v-model.number="line.item_id"
                        />
                        <input
                            type="number"
                            min="1"
                            :placeholder="$t('label.quantity')"
                            class="db-field-control upsell-preview-cart-input"
                            v-model.number="line.quantity"
                        />
                        <button
                            v-if="cartItems.length > 1"
                            type="button"
                            class="db-btn db-btn-outline upsell-preview-cart-remove"
                            @click="removeLine(idx)"
                            :aria-label="$t('label.delete')"
                        >
                            ×
                        </button>
                    </div>
                    <button
                        type="button"
                        class="db-btn db-btn-outline upsell-preview-cart-add"
                        @click="addLine"
                    >
                        + {{ $t('kiosk.admin.upsell_preview_add_item') }}
                    </button>
                </fieldset>

                <div class="upsell-preview-actions">
                    <button
                        type="button"
                        class="db-btn db-btn-primary"
                        :disabled="!canSubmit || loadingProps.isActive"
                        @click="runPreview"
                    >
                        {{ $t('kiosk.admin.upsell_preview_run') }}
                    </button>
                </div>
            </div>

            <div v-if="lastResult" class="upsell-preview-result">
                <div class="upsell-preview-meta">
                    <span class="upsell-preview-meta-pill">
                        {{ $t('kiosk.admin.upsell_preview_strategy') }}:
                        <strong>{{ lastResult.strategy_used }}</strong>
                    </span>
                    <span class="upsell-preview-meta-pill">
                        {{ $t('kiosk.admin.upsell_preview_latency') }}:
                        <strong>{{ lastResult.latency_ms }} ms</strong>
                    </span>
                    <span class="upsell-preview-meta-pill">
                        {{ $t('kiosk.admin.upsell_preview_cart_size') }}:
                        <strong>{{ lastResult.cart_size }}</strong>
                    </span>
                </div>

                <h4 class="upsell-preview-result-title">
                    {{ $t('kiosk.admin.upsell_preview_results') }}
                    <span class="upsell-preview-count">
                        ({{ lastResult.recommendations.length }})
                    </span>
                </h4>

                <ul
                    v-if="lastResult.recommendations.length > 0"
                    class="upsell-preview-list"
                >
                    <li
                        v-for="rec in lastResult.recommendations"
                        :key="rec.item_id"
                        class="upsell-preview-list-item"
                    >
                        <div class="upsell-preview-list-name">
                            <strong>#{{ rec.item_id }}</strong> — {{ rec.name }}
                        </div>
                        <div class="upsell-preview-list-meta">
                            <span>{{ rec.price }} €</span>
                            <span class="upsell-preview-reason">{{ rec.reason }}</span>
                            <span class="upsell-preview-score">
                                score: {{ rec.score.toFixed(2) }}
                            </span>
                        </div>
                    </li>
                </ul>
                <p v-else class="upsell-preview-empty">
                    {{ $t('kiosk.admin.upsell_preview_empty') }}
                </p>
            </div>
        </div>
    </ErrorBoundary>
</template>

<script>
import axios from "axios";
import LoadingComponent from "../components/LoadingComponent";
import ErrorBoundary from "../components/ErrorBoundary.vue";
import alertService from "../../../services/alertService";

/**
 * V2-3 Phase A — Admin Upsell Preview Page (greenfield).
 *
 * Outil d'aperçu admin (permission `settings`). Permet de tester les
 * stratégies RuleBased / MlPlaceholder hors prod kiosk sans toucher aux
 * frozen components (`KioskUpsellComponent` admin-curated reste intact).
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md.
 */
export default {
    name: "UpsellPreviewPage",
    components: {
        LoadingComponent,
        ErrorBoundary,
    },
    data() {
        return {
            loadingProps: { isActive: false },
            selectedBranchId: null,
            selectedStrategy: "rule_based",
            cartItems: [{ item_id: null, quantity: 1 }],
            lastResult: null,
        };
    },
    computed: {
        branches() {
            return this.$store.getters["branch/lists"] || [];
        },
        canSubmit() {
            if (!this.selectedBranchId) return false;
            const validLines = this.cartItems.filter(
                (l) =>
                    Number.isInteger(l.item_id) &&
                    l.item_id > 0 &&
                    Number.isInteger(l.quantity) &&
                    l.quantity > 0
            );
            return validLines.length > 0;
        },
    },
    mounted() {
        this.loadBranches();
    },
    methods: {
        loadBranches() {
            try {
                if (this.branches.length === 0) {
                    this.$store
                        .dispatch("branch/lists", { paginate: 0 })
                        .then(() => {
                            if (
                                this.selectedBranchId === null &&
                                this.branches.length > 0
                            ) {
                                this.selectedBranchId = this.branches[0].id;
                            }
                        })
                        .catch(() => {});
                } else if (
                    this.selectedBranchId === null &&
                    this.branches.length > 0
                ) {
                    this.selectedBranchId = this.branches[0].id;
                }
            } catch (e) {
                // non-fatal
            }
        },
        addLine() {
            this.cartItems.push({ item_id: null, quantity: 1 });
        },
        removeLine(idx) {
            if (this.cartItems.length > 1) {
                this.cartItems.splice(idx, 1);
            }
        },
        runPreview() {
            if (!this.canSubmit || this.loadingProps.isActive) return;

            this.loadingProps.isActive = true;
            const validLines = this.cartItems.filter(
                (l) =>
                    Number.isInteger(l.item_id) &&
                    l.item_id > 0 &&
                    Number.isInteger(l.quantity) &&
                    l.quantity > 0
            );

            axios
                .post("admin/upsell-preview", {
                    cart_items: validLines,
                    branch_id: this.selectedBranchId,
                    strategy: this.selectedStrategy,
                })
                .then((res) => {
                    this.lastResult = res.data || null;
                })
                .catch((err) => {
                    const msg =
                        err?.response?.data?.message ||
                        this.$t("kiosk.admin.upsell_preview_error") ||
                        "Preview failed";
                    try {
                        alertService.error(msg);
                    } catch (_) {
                        // alertService not always available — non-fatal
                    }
                })
                .finally(() => {
                    this.loadingProps.isActive = false;
                });
        },
    },
};
</script>

<style scoped>
.upsell-preview {
    padding: 24px;
}
.upsell-preview-subtitle {
    color: rgba(0, 0, 0, 0.6);
    font-size: 14px;
    margin: 4px 0 0 0;
}
.upsell-preview-form {
    display: grid;
    gap: 16px;
    margin-top: 16px;
    max-width: 720px;
}
.upsell-preview-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    align-items: center;
    gap: 12px;
}
.upsell-preview-label {
    font-weight: 600;
    font-size: 14px;
}
.upsell-preview-cart {
    border: 1px solid rgba(0, 0, 0, 0.1);
    padding: 16px;
    border-radius: 8px;
}
.upsell-preview-cart legend {
    font-weight: 600;
    padding: 0 8px;
}
.upsell-preview-cart-line {
    display: grid;
    grid-template-columns: 1fr 120px 40px;
    gap: 8px;
    margin-bottom: 8px;
}
.upsell-preview-cart-input {
    width: 100%;
}
.upsell-preview-cart-add {
    margin-top: 8px;
}
.upsell-preview-cart-remove {
    padding: 0;
}
.upsell-preview-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 8px;
}
.upsell-preview-result {
    margin-top: 24px;
    padding: 16px;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 8px;
}
.upsell-preview-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}
.upsell-preview-meta-pill {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
}
.upsell-preview-result-title {
    margin: 8px 0 12px 0;
    font-size: 16px;
}
.upsell-preview-count {
    color: rgba(0, 0, 0, 0.5);
    font-weight: 400;
}
.upsell-preview-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 8px;
}
.upsell-preview-list-item {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 6px;
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.upsell-preview-list-name {
    font-size: 14px;
}
.upsell-preview-list-meta {
    display: flex;
    gap: 12px;
    font-size: 13px;
    color: rgba(0, 0, 0, 0.65);
}
.upsell-preview-reason {
    background: #f0f3f7;
    border-radius: 4px;
    padding: 2px 8px;
    font-family: monospace;
    font-size: 12px;
}
.upsell-preview-empty {
    color: rgba(0, 0, 0, 0.5);
    font-style: italic;
    margin: 0;
}
</style>
