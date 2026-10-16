<template>
    <div class="ed-overlay" data-testid="dlc-editor" @keydown.esc="$emit('cancel')" @keydown.tab="trapTab">
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
                        <p class="ed-help">Cochez ce que vous faites avec ce produit, avec la durée de votre plan de maîtrise sanitaire.
                            « Par défaut » = l'événement imprimé d'un seul appui.</p>
                        <div v-for="a in actionRows" :key="a.key" class="ed-action" :class="{ 'is-on': a.enabled }">
                            <label class="ed-action-toggle">
                                <input v-model="a.enabled" type="checkbox" :data-testid="`dlc-ed-action-${a.key}`" @change="onToggle(a)" />
                                <span>{{ a.label }}</span>
                            </label>
                            <template v-if="a.enabled">
                                <input v-model.number="a.value" type="number" min="1" inputmode="numeric" class="db-field-control ed-num"
                                       :aria-label="`Durée ${a.label}`" :data-testid="`dlc-ed-value-${a.key}`" />
                                <select v-model="a.unit" class="db-field-control ed-unit" :aria-label="`Unité ${a.label}`" :data-testid="`dlc-ed-unit-${a.key}`">
                                    <option value="hours">heures</option>
                                    <option value="days">jours</option>
                                </select>
                                <label class="ed-default" :class="{ 'is-on': defaultKey === a.key }">
                                    <input v-model="defaultKey" type="radio" name="dlc-ed-default" :value="a.key" :data-testid="`dlc-ed-default-${a.key}`" />
                                    <span>Par défaut</span>
                                </label>
                                <span class="ed-action-hint">{{ hint(a) }}</span>
                                <span v-if="warningOf(a)" class="ed-action-warn" :data-testid="`dlc-ed-warn-${a.key}`">⚠ {{ warningOf(a) }}</span>
                            </template>
                        </div>
                        <label v-if="warnings.length" class="ed-switch ed-switch--warn">
                            <input v-model="durationsChecked" type="checkbox" data-testid="dlc-ed-durations-ok" />
                            <span><b>Durées vérifiées</b> : ces durées et températures sont validées par notre plan de maîtrise sanitaire ou
                                indiquées par le fabricant (votre nom et l'heure sont gardés sur la fiche).</span>
                        </label>
                        <p v-if="checkedText" class="ed-help" data-testid="dlc-ed-durations-checked-at">{{ checkedText }}</p>
                    </fieldset>

                    <fieldset class="ed-fieldset">
                        <legend>Conservation <span class="ed-required">(obligatoire, imprimée sur l'étiquette)</span></legend>
                        <div class="ed-chips">
                            <button v-for="(st, i) in storageChoices" :key="st" type="button" class="ed-chip"
                                    :class="{ 'is-on': form.storage === st }" :aria-pressed="form.storage === st ? 'true' : 'false'"
                                    :data-testid="`dlc-ed-storage-${i}`" @click="form.storage = form.storage === st ? '' : st">{{ st }}</button>
                            <input v-model="form.storage" type="text" maxlength="60" class="db-field-control ed-storage" placeholder="Autre…"
                                   aria-label="Conservation (autre)" data-testid="dlc-ed-storage" />
                        </div>
                        <!-- Après décongélation : jamais une valeur unique imposée (viande hachée +2 °C, poisson 0/+2 °C, pain ambiante). -->
                        <div v-if="thawingEnabled" class="ed-thawed" data-testid="dlc-ed-thawed">
                            <span class="ed-label">{{ thawedRequired ? "Après décongélation — à choisir (obligatoire)" : "Après décongélation" }}</span>
                            <div class="ed-chips">
                                <button v-for="(st, i) in thawedChoices" :key="st" type="button" class="ed-chip"
                                        :class="{ 'is-on': form.thawed_storage === st }" :aria-pressed="form.thawed_storage === st ? 'true' : 'false'"
                                        :data-testid="`dlc-ed-thawed-${i}`" @click="form.thawed_storage = form.thawed_storage === st ? '' : st">{{ st }}</button>
                                <input v-model="form.thawed_storage" type="text" maxlength="60" class="db-field-control ed-storage" placeholder="Autre…"
                                       aria-label="Conservation après décongélation (autre)" data-testid="dlc-ed-thawed-storage" />
                            </div>
                            <p class="ed-note" data-testid="dlc-ed-thawed-note">L'étiquette de décongélation indiquera « {{ thawedPrinted }} » et « NE PAS RECONGELER ».
                                Viande hachée : 0 à +2 °C ; poisson : 0 à +2 °C ; pain : ambiante.</p>
                        </div>
                    </fieldset>

                    <fieldset class="ed-fieldset">
                        <legend>Allergènes imprimés sur l'étiquette</legend>
                        <label class="ed-switch ed-switch--inline">
                            <input v-model="form.no_allergens" type="checkbox" data-testid="dlc-ed-no-allergens" @change="onNoAllergens" />
                            <span><b>Aucun</b> des 14 allergènes réglementaires (imprimé « ALLERGÈNES : aucun des 14 majeurs »)</span>
                        </label>
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
                        <input v-model="form.ask_original_dlc" type="checkbox" data-testid="dlc-ed-ask-original" @change="askTouched = true" />
                        <span><b>Demander la DLC du fabricant</b> à chaque impression — la DLC imprimée ne la dépassera jamais
                            (règle du plan de maîtrise sanitaire). Recommandé pour tout produit entamé dans son emballage d'origine.</span>
                    </label>
                    <label class="ed-switch">
                        <input v-model="form.needs_parent" type="checkbox" data-testid="dlc-ed-needs-parent" />
                        <span><b>Fait à partir d'un produit déjà étiqueté</b> (marinade, sauce, préparation…) : chaque impression demande le
                            produit d'origine — la DLC ne repart pas à zéro (elle suit la sienne, sauf cuisson) — ou « produit neuf ».</span>
                    </label>
                    <label class="ed-switch">
                        <input v-model="form.print_operator" type="checkbox" data-testid="dlc-ed-operator" />
                        <span>Imprimer le prénom de la personne qui étiquette</span>
                    </label>

                    <p v-if="shownError" class="ed-error" role="alert" data-testid="dlc-ed-error">{{ shownError }}</p>
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
                <span v-if="shownError" class="ed-foot-error" aria-hidden="true" data-testid="dlc-ed-foot-error">⚠ {{ shownError }}</span>
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
 * famille, plusieurs événements (ouverture, décongélation, préparation, cuisson, sous vide) chacun avec sa durée et l'un
 * d'eux « par défaut », conservation (obligatoire), 14 allergènes, mention, DLC du fabricant demandée, prénom imprimé,
 * nombre d'étiquettes — avec l'aperçu EXACT de l'étiquette pendant la saisie.
 *
 * Garde-fous du guide PMS : une durée inhabituelle (décongelé au-delà de 3 jours, froid positif au-delà de 7 jours, ou
 * « 96 heures » qui ressemble à une erreur d'unité) est signalée et doit être confirmée avant d'enregistrer.
 */
import {
    DLC_ACTIONS, DLC_ACTION_KEYS, DLC_ALLERGENS, DLC_CATEGORIES, DLC_STORAGES, DLC_THAWED_STORAGES,
    dlcLabelBlocks, previewExpiry, shelfLifeText, storageForAction, storagePhrase, isFrozenOrAmbient, needsThawedStorage,
    isValidThawedStorage, formatDlcDate,
} from "../../../helpers/dlcLabelBuilder";
import { previewUrls } from "../../../helpers/labelPrintJob";

const DEFAULT_DURATION = { opened: [2, "days"], thawed: [3, "days"], prepared: [24, "hours"], cooked: [3, "days"], vacuum: [6, "days"] };
/** Événements où le produit est encore dans son emballage d'origine : la DLC du fabricant est demandée par défaut. */
const PACKAGED = ["opened", "vacuum"];
/** Viande ou poisson : décongelés, l'usage courant est 24 h (au-delà, le PMS doit le prévoir). */
const MEAT_OR_FISH = /viande|volaille|poulet|b(?:oe|œ)uf|steak|hach|kebab|poisson|saumon|thon|crevette|fruits de mer/iu;
/** Pains et viennoiseries : les seuls produits qui se décongèlent à température ambiante. */
const BREAD = /pain|bun|brioch|viennois|baguette|croissant|wrap|tortilla|galette|pita|naan|bagel|muffin|cookie|g[aâ]teau|p[aâ]tisserie|dessert/iu;
/** Viande hachée et produits de la pêche : +2 °C au plus (arrêté du 21/12/2009, annexe I). */
const MINCED_OR_FISH = /hach|steak|poisson|saumon|thon|cabillaud|colin|crevette|fruits de mer/iu;

/** Température la plus haute écrite (« 0 à +4 °C » → 4) ; null si aucune. */
function maxTemp(text) {
    const nums = String(text || "").match(/[+]?\d+/g);
    return nums ? Math.max(...nums.map((n) => Number(n.replace("+", "")))) : null;
}

function hoursOf(a) { return a.unit === "days" ? Number(a.value) * 24 : Number(a.value); }

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
        // Fiche neuve sans avis explicite : DLC du fabricant demandée si le produit est entamé dans son emballage.
        const askDefault = p.ask_original_dlc === undefined ? PACKAGED.includes(actions[0].key) : Boolean(p.ask_original_dlc);
        return {
            form: {
                id: p.id || null,
                name: p.name || "",
                category: p.category || "",
                storage: p.storage || "",
                thawed_storage: p.thawed_storage || "",
                note: p.note || "",
                allergens: Array.isArray(p.allergens) ? [...p.allergens] : [],
                no_allergens: Boolean(p.no_allergens),
                needs_parent: Boolean(p.needs_parent),
                ask_original_dlc: askDefault,
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
            defaultKey: actions[0].key,
            askTouched: p.ask_original_dlc !== undefined,
            durationsChecked: false,
            localError: "",
            confirmDelete: false,
            returnFocus: null,
        };
    },
    computed: {
        categoryChoices() {
            return [...new Set([...DLC_CATEGORIES, ...this.categories.filter(Boolean)])];
        },
        storageChoices() { return DLC_STORAGES; },
        thawedChoices() { return DLC_THAWED_STORAGES; },
        thawingEnabled() { return this.actionRows.some((a) => a.key === "thawed" && a.enabled); },
        thawedRequired() { return needsThawedStorage(this.form.storage, this.enabledActions) && !String(this.form.thawed_storage || "").trim(); },
        thawedPrinted() {
            return storagePhrase(storageForAction(this.form.storage, "thawed", this.form.thawed_storage)) || "… à choisir ci-dessus";
        },
        checkedText() {
            const at = this.product && this.product.durations_checked_at;
            return at ? `Durées inhabituelles vérifiées le ${formatDlcDate(at)}.` : "";
        },
        allergenChoices() { return DLC_ALLERGENS; },
        /** Les événements cochés, l'événement « par défaut » en premier (c'est lui qu'imprime un appui). */
        enabledActions() {
            const list = this.actionRows.filter((a) => a.enabled && Number(a.value) >= 1).map((a) => ({ key: a.key, value: Number(a.value), unit: a.unit }));
            const i = list.findIndex((a) => a.key === this.defaultKey);
            if (i > 0) list.unshift(list.splice(i, 1)[0]);
            return list;
        },
        warnings() {
            return this.actionRows.filter((a) => a.enabled && this.warningOf(a)).map((a) => a.key);
        },
        shownError() { return this.localError || this.error; },

        preview() {
            const first = this.enabledActions[0];
            if (!String(this.form.name).trim() || !first) return "";
            const now = new Date();
            try {
                const blocks = dlcLabelBlocks({
                    name: this.form.name, category: this.form.category, note: this.form.note, action: first.key,
                    openedAt: now.toISOString(), expiresAt: previewExpiry(first, now).toISOString(),
                    storage: storageForAction(this.form.storage, first.key, this.form.thawed_storage), allergens: this.form.allergens,
                    noAllergens: this.form.no_allergens,
                    by: this.form.print_operator ? "Prénom" : "", lot: "L000000-0000",
                });
                return previewUrls([blocks], this.settings).urls[0] || "";
            } catch (_) {
                return "";
            }
        },
    },
    watch: {
        // Tant que la personne n'a pas tranché, « DLC du fabricant » suit l'événement par défaut d'une fiche neuve.
        defaultKey(key) {
            if (!this.askTouched && !this.form.id) this.form.ask_original_dlc = PACKAGED.includes(key);
        },
        warnings(now, before) {
            if (now.join() !== before.join()) this.durationsChecked = false;
        },
    },
    mounted() {
        this.returnFocus = document.activeElement;
        this.$nextTick(() => { if (this.$refs.name) this.$refs.name.focus(); });
    },
    beforeUnmount() {
        if (this.returnFocus && this.returnFocus.focus && document.contains(this.returnFocus)) this.returnFocus.focus();
    },
    methods: {
        hint(a) {
            const v = Number(a.value);
            if (!(v >= 1)) return "durée à saisir";
            return `→ DLC ${shelfLifeText(v, a.unit)} après`;
        },
        /** Durée inhabituelle (règles de prudence du guide PMS, et erreurs d'unité courantes). */
        warningOf(a) {
            const v = Number(a.value);
            if (!(v >= 1)) return "";
            const h = hoursOf(a);
            if (a.unit === "hours" && v > 72) return `${v} heures = ${Math.round(v / 24)} jours : vérifiez l'unité.`;
            const what = `${this.form.name} ${this.form.category}`;
            if (a.key === "thawed" && MINCED_OR_FISH.test(what) && maxTemp(this.form.thawed_storage) > 2) {
                return "Viande hachée ou poisson décongelés : +2 °C au plus (arrêté du 21/12/2009, annexe I).";
            }
            // Décongelé à température ambiante : seul un pain ou une viennoiserie s'y prête — tout le reste au froid (arrêté du
            // 21/12/2009, annexe I : la température du produit frais équivalent). Liste blanche des pains, pas liste noire des viandes.
            if (a.key === "thawed" && /^\s*ambiante\s*$/iu.test(String(this.form.thawed_storage || "")) && !BREAD.test(what)) {
                return "Décongelé à température ambiante : réservé au pain et aux viennoiseries — viande, poisson, plats : au froid (arrêté du 21/12/2009, annexe I).";
            }
            if (a.key === "thawed" && h > 24 && MEAT_OR_FISH.test(what)) {
                return "Viande ou poisson décongelé : 24 h est l'usage courant ; au-delà, votre plan de maîtrise sanitaire doit le prévoir.";
            }
            if (a.key === "thawed" && h > 72) return "Un produit décongelé se garde en général 3 jours au plus.";
            const cold = !this.form.storage || !isFrozenOrAmbient(this.form.storage) || a.key === "thawed";
            if (cold && h > 7 * 24) return "Plus de 7 jours au froid positif : durée inhabituelle.";
            return "";
        },
        onToggle(a) {
            // L'événement par défaut est toujours un événement coché.
            if (a.enabled && !this.actionRows.some((r) => r.enabled && r.key === this.defaultKey)) this.defaultKey = a.key;
            if (!a.enabled && a.key === this.defaultKey) {
                const next = this.actionRows.find((r) => r.enabled);
                if (next) this.defaultKey = next.key;
            }
        },
        onNoAllergens() {
            if (this.form.no_allergens) this.form.allergens = [];
        },
        toggleAllergen(code) {
            const i = this.form.allergens.indexOf(code);
            if (i >= 0) this.form.allergens.splice(i, 1);
            else { this.form.allergens.push(code); this.form.no_allergens = false; }
        },
        askDelete() {
            if (!this.confirmDelete) { this.confirmDelete = true; setTimeout(() => { this.confirmDelete = false; }, 4000); return; }
            this.$emit("delete", this.form.id);
        },
        trapTab(e) {
            const items = [...this.$el.querySelectorAll("button:not([disabled]), input:not([disabled]), select, textarea")];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
        submit() {
            this.localError = "";
            if (!String(this.form.storage || "").trim()) {
                this.localError = "Indiquez la température de conservation (elle est imprimée sur l’étiquette).";
                return;
            }
            if (this.thawedRequired) {
                this.localError = "Indiquez la conservation après décongélation (ex. 0 à +2 °C pour une viande hachée).";
                return;
            }
            if (this.thawingEnabled && !isValidThawedStorage(this.form.thawed_storage)) {
                this.localError = "Après décongélation : du froid positif (ex. 0 à +2 °C, 0 à +4 °C) ou « Ambiante » — jamais le congélateur.";
                return;
            }
            if (this.warnings.length && !this.durationsChecked) {
                this.localError = "Durée inhabituelle : corrigez-la, ou cochez « Durées vérifiées ».";
                return;
            }
            this.$emit("save", {
                id: this.form.id,
                name: this.form.name,
                category: this.form.category || null,
                storage: this.form.storage.trim(),
                thawed_storage: this.thawingEnabled ? (String(this.form.thawed_storage || "").trim() || null) : null,
                note: this.form.note || null,
                allergens: this.form.allergens,
                no_allergens: this.form.allergens.length ? false : this.form.no_allergens,
                needs_parent: this.form.needs_parent,
                durations_checked: this.warnings.length && this.durationsChecked ? true : undefined,
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
.ed-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: center; justify-content: center; padding: 12px; }
.ed-panel { background: #fff; border-radius: 16px; width: min(1040px, 100%); max-height: calc(100vh - 24px); box-shadow: 0 20px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; }
.ed-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto; }
.ed-head h4 { margin: 0; font-size: 20px; font-weight: 800; color: #111827; }
.ed-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
/* Le corps défile ; l'en-tête et les boutons « Annuler / Enregistrer » restent toujours visibles. */
.ed-body { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; padding: 16px 20px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
@media (max-width: 899px) {
    .ed-body { grid-template-columns: 1fr; }
    /* Portrait : l'aperçu passe en tête, compact — on voit l'étiquette changer pendant la saisie. */
    .ed-preview { order: -1; position: static; max-width: 340px; width: 100%; margin: 0 auto; }
}
.ed-form { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.ed-row { display: flex; gap: 12px; flex-wrap: wrap; }
.ed-field { flex: 1 1 200px; min-width: 0; }
.ed-field--grow { flex: 2 1 260px; }
.ed-field label, .ed-label { display: block; font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.ed-form .db-field-control { min-height: 48px; }
.ed-fieldset { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px 12px; margin: 0; }
.ed-fieldset legend { font-size: 15px; font-weight: 800; color: #111827; padding: 0 6px; width: auto; margin: 0; }
.ed-required { font-weight: 600; color: #64748b; font-size: 13px; }
.ed-help { color: #475569; font-size: 14px; margin: 2px 0 8px; }
.ed-action { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 6px 8px; border-radius: 10px; min-height: 56px; }
.ed-action.is-on { background: #fff7f3; }
.ed-action-toggle { display: flex; align-items: center; gap: 10px; min-width: 190px; min-height: 48px; cursor: pointer; font-weight: 700; font-size: 16px; margin: 0; }
.ed-action-toggle input, .ed-switch input { width: 26px; height: 26px; flex: 0 0 auto; }
.ed-num { width: 96px; }
.ed-unit { width: 120px; }
.ed-default { display: inline-flex; align-items: center; gap: 6px; min-height: 48px; padding: 0 10px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; font-size: 14px; font-weight: 600; color: #475569; cursor: pointer; margin: 0; }
.ed-default input { width: 20px; height: 20px; }
.ed-default.is-on { border-color: #D63D0B; color: #9a2c08; background: #fff; }
.ed-action-hint { color: #64748b; font-size: 14px; }
.ed-action-warn { flex-basis: 100%; color: #92400e; font-size: 14px; font-weight: 700; padding-left: 36px; }
.ed-switch--warn { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 8px 10px; margin-top: 6px; }
.ed-switch--inline { margin-bottom: 8px; }
.ed-thawed { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0; }
.ed-note { margin: 8px 0 0; font-size: 14px; color: #1e3a8a; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 8px 12px; }
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
.ed-foot { display: flex; gap: 10px; align-items: center; padding: 12px 20px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; flex: 0 0 auto; }
.ed-spacer { flex: 1 1 auto; }
.ed-foot-error { color: #b91c1c; font-weight: 700; font-size: 14px; flex: 1 1 260px; min-width: 0; }
.ed-btn { min-height: 52px; padding: 0 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.ed-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.ed-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; }
.ed-btn--danger { color: #b91c1c; border-color: #fca5a5; }
</style>
