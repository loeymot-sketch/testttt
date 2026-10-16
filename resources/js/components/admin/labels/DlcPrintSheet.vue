<template>
    <div class="sh-overlay" data-testid="dlc-sheet" @click.self="$emit('close')" @keydown.esc="$emit('close')" @keydown.tab="trapTab">
        <div ref="panel" class="sh-panel" role="dialog" aria-modal="true" :aria-label="`Imprimer : ${product.name}`" tabindex="-1">
            <header class="sh-head">
                <div>
                    <h4>{{ product.name }}</h4>
                    <span v-if="product.category" class="sh-cat">{{ product.category }}</span>
                </div>
                <div class="sh-head-actions">
                    <button type="button" class="sh-btn sh-btn--ghost" data-testid="dlc-sheet-edit" @click="$emit('edit', product)">✎ Modifier la fiche</button>
                    <button type="button" class="sh-close" aria-label="Fermer" data-testid="dlc-sheet-close" @click="$emit('close')">✕</button>
                </div>
            </header>

            <div class="sh-body">
                <div class="sh-left">
                    <span class="sh-label">Événement</span>
                    <div class="sh-actions" role="radiogroup" aria-label="Événement">
                        <button v-for="a in product.actions" :key="a.key" type="button" class="sh-action" role="radio"
                                :aria-checked="action === a.key ? 'true' : 'false'" :class="{ 'is-on': action === a.key }"
                                :data-testid="`dlc-sheet-action-${a.key}`" @click="action = a.key">
                            <strong>{{ labelOf(a.key) }}</strong>
                            <span>DLC +{{ life(a) }} · {{ dueText(a) }}</span>
                        </button>
                    </div>
                    <p v-if="thawedMissing" class="sh-cap is-bad" data-testid="dlc-sheet-thawed-missing">
                        Conservation après décongélation non renseignée : « Modifier la fiche » pour la choisir (elle est imprimée).
                    </p>
                    <p v-else-if="action === 'thawed'" class="sh-note" data-testid="dlc-sheet-thawed-note">
                        Étiquette à poser en <b>sortant le produit du congélateur</b>. Elle portera « NE PAS RECONGELER » et
                        « {{ storageText }} ».
                    </p>

                    <div v-if="product.ask_original_dlc" class="sh-original">
                        <label for="dlc-sheet-original" class="sh-label">DLC du fabricant (sur l'emballage d'origine)</label>
                        <input id="dlc-sheet-original" v-model="original" type="date" class="db-field-control" :min="todayIso"
                               data-testid="dlc-sheet-original" />
                        <!-- La DLC d'un NOUVEL emballage se recopie : la dernière connue n'est proposée que sur un choix explicite. -->
                        <button v-if="lastOriginal && lastOriginal.original_dlc && original !== lastOriginal.original_dlc" type="button"
                                class="sh-btn sh-btn--ghost sh-same" data-testid="dlc-sheet-same-pack" @click="useLastPack">
                            Même emballage que la dernière fois ({{ lastPackText }})
                        </button>
                        <p v-if="capNote" class="sh-cap" :class="{ 'is-bad': originalExpired }" data-testid="dlc-sheet-cap-note">{{ capNote }}</p>
                        <label for="dlc-sheet-supplier-lot" class="sh-label sh-label--sub">Lot du fabricant (facultatif)</label>
                        <input id="dlc-sheet-supplier-lot" v-model="supplierLot" type="text" maxlength="40" class="db-field-control sh-lot"
                               autocomplete="off" placeholder="ex. 2345A" data-testid="dlc-sheet-supplier-lot" />
                    </div>

                    <!-- Produit fait à partir d'un produit déjà étiqueté : la DLC ne repart pas à zéro (sauf cuisson). Pour une fiche
                         « fait à partir de… », le choix est OBLIGATOIRE : l'origine, ou « produit neuf » dit explicitement. -->
                    <div v-if="parentChoices.length || product.needs_parent" class="sh-parent" :class="{ 'is-required': mustChooseParent }">
                        <button type="button" class="sh-toggle" :aria-expanded="showParents ? 'true' : 'false'" data-testid="dlc-sheet-parent-toggle"
                                @click="showParents = !showParents">
                            {{ showParents ? "▾" : "▸" }} {{ product.needs_parent ? "Produit d'origine (obligatoire)" : "Fait à partir d'un produit déjà étiqueté ?" }}
                            <span v-if="parent" class="sh-parent-picked">— {{ parent.name }}</span>
                            <span v-else-if="parentChoice === 'none'" class="sh-parent-picked">— produit neuf</span>
                        </button>
                        <div v-if="showParents" class="sh-parent-list" role="radiogroup" aria-label="Produit d'origine">
                            <input v-if="parentChoices.length > 6" v-model="parentQuery" type="search" class="db-field-control sh-parent-search"
                                   placeholder="🔍 Chercher un lot (nom, N°)…" aria-label="Chercher un lot" data-testid="dlc-sheet-parent-search" />
                            <button type="button" class="sh-parent-item" role="radio" :aria-checked="parentChoice === 'none' ? 'true' : 'false'"
                                    :class="{ 'is-on': parentChoice === 'none' }" data-testid="dlc-sheet-parent-none" @click="parentChoice = 'none'">
                                Aucun : produit neuf (pas fait à partir d'un produit étiqueté)
                            </button>
                            <button v-for="l in filteredParents" :key="l.id" type="button" class="sh-parent-item" role="radio"
                                    :aria-checked="parentChoice === l.id ? 'true' : 'false'" :class="{ 'is-on': parentChoice === l.id }"
                                    :data-testid="`dlc-sheet-parent-${l.id}`" @click="parentChoice = l.id">
                                <strong>{{ l.name }}</strong>
                                <span>{{ l.actionShort }} · jusqu'au {{ l.until }}<template v-if="l.lot"> · N° {{ l.lot }}</template><template v-if="l.used"> · bac utilisé à l'instant</template></span>
                                <span v-if="l.anomaly" class="sh-anomaly" data-testid="dlc-sheet-parent-anomaly">⚠ À vérifier (imprimée hors ligne) : sa DLC peut être fausse</span>
                            </button>
                            <p v-if="lotsPartial" class="sh-cap" data-testid="dlc-sheet-lots-partial">Liste des lots en cours indisponible (réseau) : seuls les lots récents sont proposés.</p>
                            <p v-if="!filteredParents.length" class="sh-help">{{ parentChoices.length ? "Aucun lot ne correspond." : "Aucun lot en cours dans le journal." }}</p>
                        </div>
                        <p v-if="mustChooseParent" class="sh-cap is-bad" data-testid="dlc-sheet-parent-required">Choisissez le produit d'origine, ou « produit neuf ».</p>
                        <p v-else-if="parentNote" class="sh-cap" data-testid="dlc-sheet-parent-note">{{ parentNote }}</p>
                    </div>

                    <span class="sh-label">Nombre d'étiquettes</span>
                    <div class="sh-stepper">
                        <button type="button" class="sh-step" aria-label="Une de moins" data-testid="dlc-sheet-minus" @click="copies = Math.max(1, copies - 1)">−</button>
                        <span class="sh-step-n" data-testid="dlc-sheet-copies" aria-live="polite">{{ copies }}</span>
                        <button type="button" class="sh-step" aria-label="Une de plus" data-testid="dlc-sheet-plus" @click="copies = Math.min(20, copies + 1)">+</button>
                    </div>
                </div>

                <aside class="sh-preview" aria-label="Aperçu">
                    <img v-if="preview" :src="preview" alt="Aperçu de l'étiquette" data-testid="dlc-sheet-preview" />
                    <p class="sh-help">Aperçu : les dates exactes sont fixées par le serveur au moment de l'impression.</p>
                </aside>
            </div>

            <footer class="sh-foot">
                <button type="button" class="sh-btn" @click="$emit('close')">Annuler</button>
                <button type="button" class="sh-btn sh-btn--primary" :disabled="!canPrint || busy" data-testid="dlc-sheet-print" @click="print">
                    🖨 {{ busy ? "Impression…" : `Imprimer ${copies} étiquette${copies > 1 ? "s" : ""}` }}
                </button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16] Fiche d'impression d'UN produit : choix de l'événement (ouverture, décongélation…) avec la DLC
 * qui en résulte, nombre d'étiquettes, DLC et lot du fabricant quand le produit l'exige (la DLC imprimée ne la dépasse
 * jamais), produit d'origine déjà étiqueté (la DLC suit la sienne, sauf cuisson — GBPH § 2.7), aperçu fidèle.
 * S'ouvre seulement quand il y a un choix à faire : sinon un appui sur la tuile imprime directement.
 */
import { DLC_ACTIONS, dlcLabelBlocks, previewExpiry, shelfLifeText, formatDlcDate, storageForAction, storagePhrase, isValidThawedStorage } from "../../../helpers/dlcLabelBuilder";
import { previewUrls } from "../../../helpers/labelPrintJob";

function isoDay(d) {
    const p = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export default {
    name: "DlcPrintSheet",
    props: {
        product: { type: Object, required: true },
        settings: { type: Object, required: true },
        serverOffsetMs: { type: Number, default: 0 },
        busy: { type: Boolean, default: false },
        initialAction: { type: String, default: "" },
        /** Lots en cours (journal entier, `GET labels/lots`) : origines possibles d'une préparation. */
        lots: { type: Array, default: () => [] },
        /** La liste complète des lots n'a pas pu être relue (réseau, serveur) : celle affichée est partielle. */
        lotsPartial: { type: Boolean, default: false },
        /** Dernière DLC / lot du fabricant saisis pour ce produit, encore valables : { original_dlc, supplier_lot }. */
        lastOriginal: { type: Object, default: null },
        timezone: { type: String, default: "Europe/Paris" },
    },
    emits: ["print", "close", "edit"],
    data() {
        const actions = this.product.actions || [];
        const start = actions.find((a) => a.key === this.initialAction) || actions[0] || { key: "opened" };
        return {
            action: start.key, copies: Number(this.product.default_copies) || 1,
            // jamais pré-remplie : la DLC d'un nouvel emballage se lit sur l'emballage (« Même emballage » la reprend)
            original: "",
            supplierLot: "",
            // null = pas encore choisi ; "none" = produit neuf ; sinon l'identifiant du lot d'origine
            parentChoice: null, parentQuery: "", showParents: Boolean(this.product.needs_parent), returnFocus: null,
        };
    },
    computed: {
        now() { return new Date(Date.now() + this.serverOffsetMs); },
        todayIso() { return isoDay(this.now); },
        current() { return (this.product.actions || []).find((a) => a.key === this.action) || (this.product.actions || [])[0]; },
        storageText() { return storagePhrase(storageForAction(this.product.storage, this.action, this.product.thawed_storage)); },
        originalEnd() {
            if (!this.original) return null;
            const [y, m, d] = this.original.split("-").map(Number);
            return new Date(y, m - 1, d, 23, 59, 0);
        },
        originalExpired() { return Boolean(this.originalEnd && this.originalEnd < this.now); },
        /** Lots encore bons, d'un AUTRE produit que celui-ci, un par lot (même produit + même échéance). */
        parentChoices() {
            const nowMs = this.now.getTime();
            const seen = new Set();
            return (this.lots || [])
                .filter((l) => ["printed", "unconfirmed"].includes(l.status) && l.expires_at
                    && (!l.discarded_at || l.discard_reason === "used")
                    && Date.parse(l.expires_at) > nowMs && l.dlc_product_id !== this.product.id)
                .sort((a, b) => Date.parse(a.expires_at) - Date.parse(b.expires_at))
                .filter((l) => { const k = `${l.product_name}|${l.expires_at}`; if (seen.has(k)) return false; seen.add(k); return true; })
                .map((l) => ({
                    id: l.id, name: l.product_name, lot: l.lot, expiresAt: l.expires_at, used: Boolean(l.discarded_at),
                    action: l.action || "", anomaly: Boolean(l.anomaly), chainCooked: Boolean(l.chain_cooked) || l.action === "cooked",
                    actionShort: (DLC_ACTIONS[l.action] || DLC_ACTIONS.opened).short,
                    until: formatDlcDate(l.expires_at, this.timezone),
                }));
        },
        filteredParents() {
            const q = String(this.parentQuery || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
            const norm = (v) => String(v || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
            return this.parentChoices.filter((l) => !q || norm(l.name).includes(q) || norm(l.lot).includes(q)).slice(0, 40);
        },
        parentId() { return typeof this.parentChoice === "number" ? this.parentChoice : null; },
        parent() { return this.parentChoices.find((l) => l.id === this.parentId) || null; },
        parentEnd() { return this.parent ? new Date(this.parent.expiresAt) : null; },
        mustChooseParent() { return Boolean(this.product.needs_parent) && this.parentChoice === null; },
        rawExpiry() { return this.current ? previewExpiry(this.current, this.now) : this.now; },
        limit() { return this.limitFor(this.current); },
        capNote() {
            if (!this.originalEnd) return "";
            if (this.originalExpired) return "DLC du fabricant dépassée : ce produit ne doit pas être utilisé.";
            return this.limit.source === "original"
                ? `La DLC imprimée sera limitée à celle du fabricant (${this.original.split("-").reverse().join("/")}).`
                : "La DLC du fabricant est plus lointaine : la durée habituelle s'applique.";
        },
        parentNote() {
            if (!this.parent) return "";
            if (this.action === "cooked" && this.parent.chainCooked) return "Déjà cuit : une seule remise en température — la DLC suit celle du produit d'origine.";
            if (this.action === "cooked") return "Cuisson : nouvelle durée de conservation (le produit d'origine est encore bon).";
            return this.limit.source === "parent"
                ? `La DLC suivra celle du produit d'origine (${this.parent.until}).`
                : "Le produit d'origine se garde plus longtemps : la durée habituelle s'applique.";
        },
        /** Décongélation sur une fiche sans conservation après décongélation : on n'imprime rien (aucune valeur de repli). */
        thawedMissing() { return this.action === "thawed" && !isValidThawedStorage(this.product.thawed_storage); },
        lastPackText() {
            const o = this.lastOriginal || {};
            const d = o.original_dlc ? o.original_dlc.split("-").reverse().join("/") : "";
            return [d, o.supplier_lot ? `lot ${o.supplier_lot}` : ""].filter(Boolean).join(" · ");
        },
        canPrint() {
            if (!this.current) return false;
            if (this.thawedMissing) return false;
            if (this.product.ask_original_dlc && (!this.original || this.originalExpired)) return false;
            if (this.mustChooseParent) return false;
            return true;
        },
        preview() {
            if (!this.current) return "";
            try {
                const blocks = dlcLabelBlocks({
                    name: this.product.name, category: this.product.category, note: this.product.note, action: this.current.key,
                    openedAt: this.now.toISOString(), expiresAt: this.limit.expires.toISOString(),
                    storage: storageForAction(this.product.storage, this.current.key, this.product.thawed_storage),
                    allergens: this.product.allergens, noAllergens: this.product.no_allergens,
                    capped: Boolean(this.limit.source), capSource: this.limit.source,
                    originalDlc: this.original, supplierLot: this.supplierLot.trim(),
                    parent: this.parent ? { lot: this.parent.lot, name: this.parent.name } : null,
                    by: this.product.print_operator ? "Prénom" : "", lot: "L000000-0000",
                });
                return previewUrls([blocks], this.settings).urls[0] || "";
            } catch (_) {
                return "";
            }
        },
    },
    mounted() {
        // Le focus entre dans la fenêtre (clavier, lecteur d'écran) et revient d'où il venait à la fermeture.
        this.returnFocus = document.activeElement;
        this.$nextTick(() => {
            const first = this.$el.querySelector(".sh-action.is-on") || this.$refs.panel;
            if (first && first.focus) first.focus();
        });
    },
    beforeUnmount() {
        if (this.returnFocus && this.returnFocus.focus && document.contains(this.returnFocus)) this.returnFocus.focus();
    },
    methods: {
        labelOf(key) { return (DLC_ACTIONS[key] || DLC_ACTIONS.opened).label; },
        useLastPack() {
            this.original = this.lastOriginal.original_dlc || "";
            this.supplierLot = this.lastOriginal.supplier_lot || "";
        },
        life(a) { return shelfLifeText(a.value, a.unit); },
        /** La DLC qu'imprimerait cet événement, LIMITÉE comme sur l'aperçu (fabricant, produit d'origine sauf cuisson). */
        limitFor(a) {
            let e = a ? previewExpiry(a, this.now) : this.now;
            let source = null;
            if (this.originalEnd && e > this.originalEnd) { e = this.originalEnd; source = "original"; }
            // cuire redonne une durée propre, SAUF un produit déjà cuit (une seule remise en température)
            const restart = a && a.key === "cooked" && !(this.parent && this.parent.chainCooked);
            if (this.parentEnd && !restart && e > this.parentEnd) { e = this.parentEnd; source = "parent"; }
            return { expires: e, source };
        },
        dueText(a) {
            const l = this.limitFor(a);
            return `${formatDlcDate(l.expires, this.timezone)}${l.source ? " (limitée)" : ""}`;
        },
        trapTab(e) {
            const items = [...this.$el.querySelectorAll("button:not([disabled]), input:not([disabled]), select, [tabindex='0']")];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
        print() {
            if (!this.canPrint) return;
            this.$emit("print", {
                action: this.current.key, copies: this.copies, original_dlc: this.original || null,
                supplier_lot: this.supplierLot.trim() || null, parent_log_id: this.parentId,
                parent_none: this.parentChoice === "none" || undefined,
            });
        },
    },
};
</script>

<style scoped>
.sh-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: flex-end; justify-content: center; }
@media (min-width: 900px) { .sh-overlay { align-items: center; } }
.sh-panel { background: #fff; width: min(900px, 100%); border-radius: 16px 16px 0 0; box-shadow: 0 -10px 40px rgba(0, 0, 0, .25); max-height: 92vh; display: flex; flex-direction: column; outline: none; }
@media (min-width: 900px) { .sh-panel { border-radius: 16px; } }
.sh-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; }
.sh-head h4 { margin: 0; font-size: 22px; font-weight: 800; color: #111827; }
.sh-head-actions { display: flex; gap: 8px; align-items: center; flex: 0 0 auto; }
.sh-cat { color: #475569; font-size: 14px; }
.sh-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
.sh-body { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 18px; padding: 16px 20px; overflow-y: auto; }
@media (max-width: 899px) { .sh-body { grid-template-columns: 1fr; } }
.sh-left { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.sh-label { font-size: 14px; font-weight: 800; color: #334155; }
.sh-label--sub { margin-top: 6px; display: block; }
.sh-actions { display: flex; flex-direction: column; gap: 8px; }
.sh-action { text-align: left; min-height: 60px; padding: 10px 14px; border-radius: 12px; border: 2px solid #e2e8f0; background: #fff; cursor: pointer; display: flex; flex-direction: column; gap: 2px; font-size: 15px; color: #334155; }
.sh-action strong { font-size: 18px; color: #111827; }
.sh-action.is-on { border-color: #D63D0B; background: #fff7f3; }
.sh-note { margin: 0; font-size: 14px; color: #1e3a8a; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 8px 12px; }
.sh-original .db-field-control { min-height: 52px; font-size: 18px; max-width: 260px; }
.sh-original .sh-lot { font-size: 16px; }
.sh-cap { margin: 6px 0 0; font-size: 14px; color: #92400e; font-weight: 600; }
.sh-cap.is-bad { color: #b91c1c; }
.sh-parent { border: 1px solid #e2e8f0; border-radius: 12px; padding: 4px 10px 8px; }
.sh-parent.is-required { border-color: #f59e0b; background: #fffbeb; }
.sh-parent-search { min-height: 48px; margin-bottom: 4px; }
.sh-anomaly { color: #9a3412; font-weight: 700; font-size: 13px; }
.sh-same { margin-top: 8px; display: block; text-align: left; height: auto; padding: 10px 14px; line-height: 1.3; }
.sh-toggle { background: none; border: 0; font-size: 15px; font-weight: 700; color: #334155; min-height: 48px; cursor: pointer; padding: 0; text-align: left; }
.sh-parent-picked { color: #D63D0B; }
.sh-parent-list { display: flex; flex-direction: column; gap: 6px; }
.sh-parent-item { text-align: left; min-height: 52px; padding: 8px 12px; border-radius: 10px; border: 2px solid #e2e8f0; background: #fff; cursor: pointer; display: flex; flex-direction: column; font-size: 14px; color: #475569; }
.sh-parent-item strong { font-size: 16px; color: #111827; }
.sh-parent-item.is-on { border-color: #D63D0B; background: #fff7f3; }
.sh-stepper { display: flex; align-items: center; gap: 10px; }
.sh-step { width: 56px; height: 56px; border-radius: 14px; border: 1px solid #cbd5e1; background: #fff; font-size: 26px; font-weight: 800; cursor: pointer; }
.sh-step-n { min-width: 44px; text-align: center; font-size: 26px; font-weight: 800; }
.sh-preview img { width: 100%; border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; display: block; }
.sh-help { color: #64748b; font-size: 13px; margin: 6px 0 0; }
.sh-foot { display: flex; gap: 10px; justify-content: flex-end; padding: 12px 20px 16px; border-top: 1px solid #e2e8f0; }
.sh-btn { min-height: 56px; padding: 0 22px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 17px; font-weight: 700; cursor: pointer; }
.sh-btn--ghost { min-height: 48px; padding: 0 14px; font-size: 15px; color: #334155; }
.sh-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; min-width: 240px; }
.sh-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
@media (max-width: 520px) { .sh-btn--ghost { padding: 0 10px; } .sh-btn--primary { min-width: 0; flex: 1 1 auto; } }
</style>
