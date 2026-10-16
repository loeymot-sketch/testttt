<template>
    <div class="sh-overlay" data-testid="dlc-sheet" @click.self="$emit('close')" @keydown.esc="$emit('close')">
        <div class="sh-panel" role="dialog" aria-modal="true" :aria-label="`Imprimer : ${product.name}`">
            <header class="sh-head">
                <div>
                    <h4>{{ product.name }}</h4>
                    <span v-if="product.category" class="sh-cat">{{ product.category }}</span>
                </div>
                <button type="button" class="sh-close" aria-label="Fermer" data-testid="dlc-sheet-close" @click="$emit('close')">✕</button>
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

                    <div v-if="product.ask_original_dlc" class="sh-original">
                        <label for="dlc-sheet-original" class="sh-label">DLC du fabricant (sur l'emballage d'origine)</label>
                        <input id="dlc-sheet-original" v-model="original" type="date" class="db-field-control" :min="todayIso"
                               data-testid="dlc-sheet-original" />
                        <p v-if="capNote" class="sh-cap" :class="{ 'is-bad': originalExpired }" data-testid="dlc-sheet-cap-note">{{ capNote }}</p>
                    </div>

                    <span class="sh-label">Nombre d'étiquettes</span>
                    <div class="sh-stepper">
                        <button type="button" class="sh-step" aria-label="Une de moins" data-testid="dlc-sheet-minus" @click="copies = Math.max(1, copies - 1)">−</button>
                        <span class="sh-step-n" data-testid="dlc-sheet-copies">{{ copies }}</span>
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
 * qui en résulte, nombre d'étiquettes, DLC du fabricant quand le produit l'exige (la DLC imprimée ne la dépasse jamais),
 * aperçu fidèle. S'ouvre seulement quand il y a un choix à faire : sinon un appui sur la tuile imprime directement.
 */
import { DLC_ACTIONS, dlcLabelBlocks, previewExpiry, shelfLifeText, formatDlcDate } from "../../../helpers/dlcLabelBuilder";
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
    },
    emits: ["print", "close"],
    data() {
        const actions = this.product.actions || [];
        const start = actions.find((a) => a.key === this.initialAction) || actions[0] || { key: "opened" };
        return { action: start.key, copies: Number(this.product.default_copies) || 1, original: "" };
    },
    computed: {
        now() { return new Date(Date.now() + this.serverOffsetMs); },
        todayIso() { return isoDay(this.now); },
        current() { return (this.product.actions || []).find((a) => a.key === this.action) || (this.product.actions || [])[0]; },
        originalEnd() {
            if (!this.original) return null;
            const [y, m, d] = this.original.split("-").map(Number);
            return new Date(y, m - 1, d, 23, 59, 0);
        },
        originalExpired() { return Boolean(this.originalEnd && this.originalEnd < this.now); },
        expires() {
            if (!this.current) return this.now;
            const e = previewExpiry(this.current, this.now);
            return this.originalEnd && e > this.originalEnd ? this.originalEnd : e;
        },
        capped() {
            return Boolean(this.current && this.originalEnd && previewExpiry(this.current, this.now) > this.originalEnd);
        },
        capNote() {
            if (!this.originalEnd) return "";
            if (this.originalExpired) return "DLC du fabricant dépassée : ce produit ne doit pas être utilisé.";
            return this.capped
                ? `La DLC imprimée sera limitée à celle du fabricant (${this.original.split("-").reverse().join("/")}).`
                : "La DLC du fabricant est plus lointaine : la durée habituelle s'applique.";
        },
        canPrint() {
            if (!this.current) return false;
            if (this.product.ask_original_dlc && (!this.original || this.originalExpired)) return false;
            return true;
        },
        preview() {
            if (!this.current) return "";
            try {
                const blocks = dlcLabelBlocks({
                    name: this.product.name, category: this.product.category, note: this.product.note, action: this.current.key,
                    openedAt: this.now.toISOString(), expiresAt: this.expires.toISOString(), storage: this.product.storage,
                    allergens: this.product.allergens, capped: this.capped, originalDlc: this.original,
                    by: this.product.print_operator ? "Prénom" : "", lot: "L000000-0000",
                });
                return previewUrls([blocks], this.settings).urls[0] || "";
            } catch (_) {
                return "";
            }
        },
    },
    methods: {
        labelOf(key) { return (DLC_ACTIONS[key] || DLC_ACTIONS.opened).label; },
        life(a) { return shelfLifeText(a.value, a.unit); },
        dueText(a) { return formatDlcDate(previewExpiry(a, this.now)); },
        print() {
            if (!this.canPrint) return;
            this.$emit("print", { action: this.current.key, copies: this.copies, original_dlc: this.original || null });
        },
    },
};
</script>

<style scoped>
.sh-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: flex-end; justify-content: center; }
@media (min-width: 900px) { .sh-overlay { align-items: center; } }
.sh-panel { background: #fff; width: min(900px, 100%); border-radius: 16px 16px 0 0; box-shadow: 0 -10px 40px rgba(0, 0, 0, .25); max-height: 92vh; overflow-y: auto; }
@media (min-width: 900px) { .sh-panel { border-radius: 16px; } }
.sh-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; }
.sh-head h4 { margin: 0; font-size: 22px; font-weight: 800; color: #111827; }
.sh-cat { color: #475569; font-size: 14px; }
.sh-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
.sh-body { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 18px; padding: 16px 20px; }
@media (max-width: 899px) { .sh-body { grid-template-columns: 1fr; } }
.sh-left { display: flex; flex-direction: column; gap: 10px; }
.sh-label { font-size: 14px; font-weight: 800; color: #334155; }
.sh-actions { display: flex; flex-direction: column; gap: 8px; }
.sh-action { text-align: left; min-height: 60px; padding: 10px 14px; border-radius: 12px; border: 2px solid #e2e8f0; background: #fff; cursor: pointer; display: flex; flex-direction: column; gap: 2px; font-size: 15px; color: #334155; }
.sh-action strong { font-size: 18px; color: #111827; }
.sh-action.is-on { border-color: #D63D0B; background: #fff7f3; }
.sh-original .db-field-control { min-height: 52px; font-size: 18px; max-width: 260px; }
.sh-cap { margin: 6px 0 0; font-size: 14px; color: #92400e; font-weight: 600; }
.sh-cap.is-bad { color: #b91c1c; }
.sh-stepper { display: flex; align-items: center; gap: 10px; }
.sh-step { width: 56px; height: 56px; border-radius: 14px; border: 1px solid #cbd5e1; background: #fff; font-size: 26px; font-weight: 800; cursor: pointer; }
.sh-step-n { min-width: 44px; text-align: center; font-size: 26px; font-weight: 800; }
.sh-preview img { width: 100%; border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; display: block; }
.sh-help { color: #64748b; font-size: 13px; margin: 6px 0 0; }
.sh-foot { display: flex; gap: 10px; justify-content: flex-end; padding: 12px 20px 16px; border-top: 1px solid #e2e8f0; }
.sh-btn { min-height: 56px; padding: 0 22px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 17px; font-weight: 700; cursor: pointer; }
.sh-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; min-width: 240px; }
.sh-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
</style>
