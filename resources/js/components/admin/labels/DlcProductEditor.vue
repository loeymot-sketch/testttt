<template>
    <div class="ed-overlay" data-testid="dlc-editor" @keydown.esc="$emit('cancel')">
        <div class="ed-panel" role="dialog" aria-modal="true" :aria-label="form.id ? 'Modifier le produit' : 'Nouveau produit'">
            <header class="ed-head">
                <h4>{{ form.id ? "Modifier le produit" : "Nouveau produit" }}</h4>
                <button type="button" class="ed-close" aria-label="Fermer" data-testid="dlc-ed-close" @click="$emit('cancel')">✕</button>
            </header>

            <div class="ed-body">
                <form class="ed-form" @submit.prevent="submit">
                    <div class="ed-row">
                        <div class="ed-field ed-field--grow">
                            <label for="dlc-ed-name">Produit</label>
                            <input id="dlc-ed-name" ref="name" v-model="form.name" type="text" maxlength="120" class="db-field-control"
                                   placeholder="Oignons émincés" autocomplete="off" data-testid="dlc-ed-name" />
                        </div>
                        <div class="ed-field">
                            <label for="dlc-ed-category">Famille</label>
                            <input id="dlc-ed-category" v-model="form.category" type="text" maxlength="60" class="db-field-control"
                                   list="dlc-ed-categories" placeholder="Légumes & crudités" data-testid="dlc-ed-category" />
                            <datalist id="dlc-ed-categories">
                                <option v-for="c in categoryChoices" :key="c" :value="c" />
                            </datalist>
                        </div>
                    </div>

                    <!-- Événements : chacun avec SA durée de conservation -->
                    <fieldset class="ed-fieldset">
                        <legend>Événements et durée de conservation</legend>
                        <p class="ed-help">Cochez ce que vous faites avec ce produit. Le premier coché est proposé par défaut.</p>
                        <div v-for="a in actionRows" :key="a.key" class="ed-action" :class="{ 'is-on': a.enabled }">
                            <label class="ed-action-toggle">
                                <input v-model="a.enabled" type="checkbox" :data-testid="`dlc-ed-action-${a.key}`" />
                                <span>{{ a.label }}</span>
                            </label>
                            <template v-if="a.enabled">
                                <input v-model.number="a.value" type="number" min="1" inputmode="numeric" class="db-field-control ed-num"
                                       :aria-label="`Durée ${a.label}`" :data-testid="`dlc-ed-value-${a.key}`" />
                                <select v-model="a.unit" class="db-field-control ed-unit" :aria-label="`Unité ${a.label}`" :data-testid="`dlc-ed-unit-${a.key}`">
                                    <option value="hours">heures</option>
                                    <option value="days">jours</option>
                                </select>
                                <span class="ed-action-hint">{{ hint(a) }}</span>
                            </template>
                        </div>
                    </fieldset>

                    <fieldset class="ed-fieldset">
                        <legend>Conservation</legend>
                        <div class="ed-chips">
                            <button v-for="st in storageChoices" :key="st" type="button" class="ed-chip"
                                    :class="{ 'is-on': form.storage === st }" :aria-pressed="form.storage === st ? 'true' : 'false'"
                                    @click="form.storage = form.storage === st ? '' : st">{{ st }}</button>
                            <input v-model="form.storage" type="text" maxlength="60" class="db-field-control ed-storage" placeholder="Autre…"
                                   aria-label="Conservation (autre)" data-testid="dlc-ed-storage" />
                        </div>
                    </fieldset>

                    <fieldset class="ed-fieldset">
                        <legend>Allergènes imprimés sur l'étiquette</legend>
                        <div class="ed-chips">
                            <button v-for="[code, label] in allergenChoices" :key="code" type="button" class="ed-chip ed-chip--allergen"
                                    :class="{ 'is-on': form.allergens.includes(code) }" :aria-pressed="form.allergens.includes(code) ? 'true' : 'false'"
                                    :data-testid="`dlc-ed-allergen-${code}`" @click="toggleAllergen(code)">{{ label }}</button>
                        </div>
                    </fieldset>

                    <div class="ed-row">
                        <div class="ed-field ed-field--grow">
                            <label for="dlc-ed-note">Mention (facultatif)</label>
                            <input id="dlc-ed-note" v-model="form.note" type="text" maxlength="80" class="db-field-control"
                                   placeholder="Fait maison, bac n°2…" data-testid="dlc-ed-note" />
                        </div>
                        <div class="ed-field">
                            <span class="ed-label">Étiquettes par appui</span>
                            <div class="ed-stepper">
                                <button type="button" class="ed-step" aria-label="Une de moins" data-testid="dlc-ed-copies-minus" @click="form.default_copies = Math.max(1, form.default_copies - 1)">−</button>
                                <span class="ed-step-n" data-testid="dlc-ed-copies">{{ form.default_copies }}</span>
                                <button type="button" class="ed-step" aria-label="Une de plus" data-testid="dlc-ed-copies-plus" @click="form.default_copies = Math.min(20, form.default_copies + 1)">+</button>
                            </div>
                        </div>
                    </div>

                    <label class="ed-switch">
                        <input v-model="form.ask_original_dlc" type="checkbox" data-testid="dlc-ed-ask-original" />
                        <span><b>Demander la DLC du fabricant</b> à chaque impression — la DLC imprimée ne la dépassera jamais
                            (règle du plan de maîtrise sanitaire).</span>
                    </label>
                    <label class="ed-switch">
                        <input v-model="form.print_operator" type="checkbox" data-testid="dlc-ed-operator" />
                        <span>Imprimer le prénom de la personne qui étiquette</span>
                    </label>

                    <p v-if="error" class="ed-error" role="alert" data-testid="dlc-ed-error">{{ error }}</p>
                </form>

                <aside class="ed-preview" aria-label="Aperçu de l'étiquette">
                    <span class="ed-label">Aperçu</span>
                    <img v-if="preview" :src="preview" alt="Aperçu de l'étiquette" data-testid="dlc-ed-preview" />
                    <p v-else class="ed-help">Saisissez le nom et une durée pour voir l'étiquette.</p>
                </aside>
            </div>

            <footer class="ed-foot">
                <button v-if="form.id" type="button" class="ed-btn ed-btn--danger" data-testid="dlc-ed-delete" @click="askDelete">
                    {{ confirmDelete ? "Confirmer la suppression" : "Supprimer" }}
                </button>
                <span class="ed-spacer"></span>
                <button type="button" class="ed-btn" @click="$emit('cancel')">Annuler</button>
                <button type="button" class="ed-btn ed-btn--primary" :disabled="saving" data-testid="dlc-ed-save" @click="submit">
                    {{ saving ? "Enregistrement…" : form.id ? "Enregistrer" : "Ajouter le produit" }}
                </button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner « très configuré selon chaque produit, comme les concurrents »] Fiche produit DLC :
 * famille, plusieurs événements (ouverture, décongélation, préparation, cuisson, sous vide) chacun avec sa durée,
 * conservation, 14 allergènes, mention, DLC du fabricant demandée, prénom imprimé, nombre d'étiquettes — avec l'aperçu
 * EXACT de l'étiquette pendant la saisie.
 */
import {
    DLC_ACTIONS, DLC_ACTION_KEYS, DLC_ALLERGENS, DLC_CATEGORIES, DLC_STORAGES,
    dlcLabelBlocks, previewExpiry, shelfLifeText,
} from "../../../helpers/dlcLabelBuilder";
import { previewUrls } from "../../../helpers/labelPrintJob";

const DEFAULT_DURATION = { opened: [2, "days"], thawed: [3, "days"], prepared: [24, "hours"], cooked: [3, "days"], vacuum: [6, "days"] };

export default {
    name: "DlcProductEditor",
    props: {
        product: { type: Object, default: null },
        categories: { type: Array, default: () => [] },
        settings: { type: Object, required: true },
        saving: { type: Boolean, default: false },
        error: { type: String, default: "" },
    },
    emits: ["save", "cancel", "delete"],
    data() {
        const p = this.product || {};
        const actions = Array.isArray(p.actions) && p.actions.length
            ? p.actions
            : (p.shelf_life_value ? [{ key: "opened", value: p.shelf_life_value, unit: p.shelf_life_unit }] : [{ key: "opened", value: 2, unit: "days" }]);
        const order = [...actions.map((a) => a.key), ...DLC_ACTION_KEYS.filter((k) => !actions.some((a) => a.key === k))];
        return {
            form: {
                id: p.id || null,
                name: p.name || "",
                category: p.category || "",
                storage: p.storage || "",
                note: p.note || "",
                allergens: Array.isArray(p.allergens) ? [...p.allergens] : [],
                ask_original_dlc: Boolean(p.ask_original_dlc),
                print_operator: p.print_operator === undefined ? true : Boolean(p.print_operator),
                default_copies: Number(p.default_copies) || 1,
            },
            actionRows: order.map((key) => {
                const existing = actions.find((a) => a.key === key);
                const [value, unit] = DEFAULT_DURATION[key];
                return {
                    key, label: DLC_ACTIONS[key].label, enabled: Boolean(existing),
                    value: existing ? existing.value : value, unit: existing ? existing.unit : unit,
                };
            }),
            confirmDelete: false,
        };
    },
    computed: {
        categoryChoices() {
            return [...new Set([...DLC_CATEGORIES, ...this.categories.filter(Boolean)])];
        },
        storageChoices() { return DLC_STORAGES; },
        allergenChoices() { return DLC_ALLERGENS; },
        enabledActions() {
            return this.actionRows.filter((a) => a.enabled && Number(a.value) >= 1).map((a) => ({ key: a.key, value: Number(a.value), unit: a.unit }));
        },
        preview() {
            const first = this.enabledActions[0];
            if (!String(this.form.name).trim() || !first) return "";
            const now = new Date();
            try {
                const blocks = dlcLabelBlocks({
                    name: this.form.name, category: this.form.category, note: this.form.note, action: first.key,
                    openedAt: now.toISOString(), expiresAt: previewExpiry(first, now).toISOString(),
                    storage: this.form.storage, allergens: this.form.allergens,
                    by: this.form.print_operator ? "Prénom" : "", lot: "L000000-0000",
                });
                return previewUrls([blocks], this.settings).urls[0] || "";
            } catch (_) {
                return "";
            }
        },
    },
    mounted() {
        this.$nextTick(() => { if (this.$refs.name) this.$refs.name.focus(); });
    },
    methods: {
        hint(a) {
            const v = Number(a.value);
            if (!(v >= 1)) return "durée à saisir";
            return `→ DLC ${shelfLifeText(v, a.unit)} après`;
        },
        toggleAllergen(code) {
            const i = this.form.allergens.indexOf(code);
            if (i >= 0) this.form.allergens.splice(i, 1);
            else this.form.allergens.push(code);
        },
        askDelete() {
            if (!this.confirmDelete) { this.confirmDelete = true; setTimeout(() => { this.confirmDelete = false; }, 4000); return; }
            this.$emit("delete", this.form.id);
        },
        submit() {
            this.$emit("save", {
                id: this.form.id,
                name: this.form.name,
                category: this.form.category || null,
                storage: this.form.storage || null,
                note: this.form.note || null,
                allergens: this.form.allergens,
                actions: this.enabledActions,
                ask_original_dlc: this.form.ask_original_dlc,
                print_operator: this.form.print_operator,
                default_copies: this.form.default_copies,
            });
        },
    },
};
</script>

<style scoped>
.ed-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 24px 12px; }
.ed-panel { background: #fff; border-radius: 16px; width: min(1040px, 100%); box-shadow: 0 20px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; }
.ed-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; }
.ed-head h4 { margin: 0; font-size: 20px; font-weight: 800; color: #111827; }
.ed-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
.ed-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; padding: 16px 20px; }
@media (max-width: 899px) { .ed-body { grid-template-columns: 1fr; } }
.ed-form { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.ed-row { display: flex; gap: 12px; flex-wrap: wrap; }
.ed-field { flex: 1 1 200px; min-width: 0; }
.ed-field--grow { flex: 2 1 260px; }
.ed-field label, .ed-label { display: block; font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.ed-form .db-field-control { min-height: 48px; }
.ed-fieldset { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px 12px; margin: 0; }
.ed-fieldset legend { font-size: 15px; font-weight: 800; color: #111827; padding: 0 6px; width: auto; margin: 0; }
.ed-help { color: #475569; font-size: 14px; margin: 2px 0 8px; }
.ed-action { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 6px 8px; border-radius: 10px; min-height: 56px; }
.ed-action.is-on { background: #fff7f3; }
.ed-action-toggle { display: flex; align-items: center; gap: 10px; min-width: 190px; min-height: 48px; cursor: pointer; font-weight: 700; font-size: 16px; margin: 0; }
.ed-action-toggle input, .ed-switch input { width: 26px; height: 26px; flex: 0 0 auto; }
.ed-num { width: 96px; }
.ed-unit { width: 120px; }
.ed-action-hint { color: #64748b; font-size: 14px; }
.ed-chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ed-chip { min-height: 48px; padding: 0 14px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
.ed-chip.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.ed-chip--allergen.is-on { background: #b91c1c; border-color: #b91c1c; }
.ed-storage { width: 160px; }
.ed-stepper { display: flex; align-items: center; gap: 8px; }
.ed-step { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 22px; font-weight: 800; cursor: pointer; }
.ed-step-n { min-width: 36px; text-align: center; font-size: 20px; font-weight: 800; }
.ed-switch { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; line-height: 1.4; cursor: pointer; min-height: 48px; margin: 0; }
.ed-error { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 0; font-weight: 600; }
.ed-preview { position: sticky; top: 0; align-self: start; }
.ed-preview img { width: 100%; border: 1px solid #94a3b8; background: #fff; image-rendering: pixelated; display: block; }
.ed-foot { display: flex; gap: 10px; align-items: center; padding: 12px 20px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
.ed-spacer { flex: 1 1 auto; }
.ed-btn { min-height: 52px; padding: 0 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.ed-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.ed-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; }
.ed-btn--danger { color: #b91c1c; border-color: #fca5a5; }
</style>
