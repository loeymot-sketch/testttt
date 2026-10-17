<template>
    <div class="im-overlay" data-testid="dlc-import" @keydown.esc="close" @keydown.tab="trapTab">
        <div class="im-panel" role="dialog" aria-modal="true" aria-label="Importer des produits depuis un fichier">
            <header class="im-head">
                <h4>Importer des produits depuis un fichier</h4>
                <button type="button" class="im-close" aria-label="Fermer" data-testid="dlc-import-close" :disabled="busy" @click="close">✕</button>
            </header>

            <div class="im-body">
                <ol class="im-steps">
                    <li>
                        Téléchargez le fichier-modèle (il s'ouvre dans Excel ou Numbers) :
                        <button type="button" class="im-btn im-btn--small" :disabled="downloading" data-testid="dlc-import-template" @click="downloadTemplate">
                            {{ downloading ? "Téléchargement…" : "⬇ Fichier-modèle (CSV)" }}
                        </button>
                    </li>
                    <li>Une ligne par produit : nom, conservation, puis la durée de chaque événement (« 24 h », « 3 j »). Les trois exemples du modèle se remplacent.</li>
                    <li>Enregistrez en <b>CSV</b> (Excel : « CSV UTF-8 »), puis choisissez le fichier ci-dessous : vous voyez le résultat <b>avant</b> que rien ne soit ajouté.</li>
                </ol>

                <label class="im-file">
                    <span class="im-label">Fichier CSV</span>
                    <input ref="file" type="file" accept=".csv,.txt,text/csv,text/plain" :disabled="busy" data-testid="dlc-import-file" @change="onFile" />
                </label>

                <p v-if="busy" class="im-muted" role="status">Lecture du fichier…</p>
                <p v-if="error" class="im-error" role="alert" data-testid="dlc-import-error">{{ error }}</p>

                <template v-if="result">
                    <p class="im-summary" data-testid="dlc-import-summary">
                        <span class="im-pill im-pill--new">{{ result.summary.new }} à ajouter</span>
                        <span class="im-pill im-pill--exists">{{ result.summary.exists }} déjà présent{{ result.summary.exists > 1 ? "s" : "" }} (non modifié{{ result.summary.exists > 1 ? "s" : "" }})</span>
                        <span class="im-pill im-pill--bad">{{ result.summary.invalid }} à corriger</span>
                    </p>
                    <ul class="im-rows" data-testid="dlc-import-rows">
                        <li v-for="r in result.rows" :key="r.line" class="im-row" :class="`is-${r.status}`" :data-testid="`dlc-import-row-${r.line}`">
                            <span class="im-line">ligne {{ r.line }}</span>
                            <span class="im-name">{{ r.name || "—" }}</span>
                            <span v-if="r.status === 'new'" class="im-info">{{ r.summary }}</span>
                            <span v-else-if="r.status === 'exists'" class="im-info">Déjà dans votre liste : laissé tel quel.</span>
                            <span v-else class="im-info im-info--bad">{{ r.errors.join(" ") }}</span>
                        </li>
                    </ul>
                    <p v-if="result.summary.new" class="im-help">
                        Les durées importées ne sont <b>pas validées</b> : celles sans source indiquée dans le fichier attendent votre confirmation dans « Contrôle du catalogue ».
                        <template v-if="result.summary.invalid"> Les {{ result.summary.invalid }} ligne{{ result.summary.invalid > 1 ? "s" : "" }} « à corriger » ne seront <b>pas</b> importées.</template>
                    </p>
                </template>
            </div>

            <footer class="im-foot">
                <span class="im-spacer"></span>
                <button type="button" class="im-btn" :disabled="busy" @click="close">Fermer</button>
                <button type="button" class="im-btn im-btn--primary" :disabled="!canImport || busy" data-testid="dlc-import-go" @click="doImport">
                    {{ busy && result ? "Import…" : result && result.summary.new ? `Importer ${result.summary.new} produit${result.summary.new > 1 ? "s" : ""}` : "Importer" }}
                </button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P1.4] Importer des produits depuis un fichier CSV, avec un APERÇU avant toute écriture :
 * chaque ligne est dite « à ajouter », « déjà présente » (jamais écrasée) ou « à corriger » avec la raison. Les règles sont celles de
 * la fiche produit (App\Support\Labels\DlcCsvImporter) ; cet écran n'en invente aucune.
 */
import axios from "axios";
import alertService from "../../../services/alertService";

export default {
    name: "DlcImportDialog",
    emits: ["close", "imported"],
    data() {
        return { file: null, result: null, busy: false, error: "", downloading: false, returnFocus: null, seq: 0 };
    },
    computed: {
        canImport() { return Boolean(this.file && this.result && this.result.summary.new > 0); },
    },
    mounted() {
        this.returnFocus = document.activeElement;
        this.$nextTick(() => { const b = this.$el.querySelector("button"); if (b) b.focus(); });
    },
    beforeUnmount() {
        if (this.returnFocus && this.returnFocus.focus && document.contains(this.returnFocus)) this.returnFocus.focus();
    },
    methods: {
        message(e, fallback) {
            const d = e && e.response && e.response.data;
            if (d && d.errors) {
                const first = Object.values(d.errors)[0];
                if (Array.isArray(first) && first[0]) return first[0];
            }
            return (d && d.message) || fallback;
        },
        async downloadTemplate() {
            if (this.downloading) return;
            this.downloading = true;
            try {
                const { data } = await axios.get("admin/labels/products/import-template", { responseType: "blob" });
                const url = URL.createObjectURL(data instanceof Blob ? data : new Blob([data], { type: "text/csv" }));
                const a = document.createElement("a");
                a.href = url;
                a.download = "modele-produits-dlc.csv";
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (e) {
                this.error = "Téléchargement impossible : vérifiez la connexion puis réessayez.";
            } finally {
                this.downloading = false;
            }
        },
        /** Fermer pendant une requête laisserait la liste périmée (le parent ne serait jamais prévenu) : on attend la réponse. */
        close() { if (!this.busy) this.$emit("close"); },
        async send(commit, file = this.file) {
            const fd = new FormData();
            fd.append("file", file);
            fd.append("commit", commit ? "1" : "0");
            const { data } = await axios.post("admin/labels/products/import", fd);
            return data.data;
        },
        async onFile(e) {
            const file = (e.target.files && e.target.files[0]) || null;
            this.file = file;
            this.result = null;
            this.error = "";
            if (!file) return;
            // jeton : l'aperçu affiché est TOUJOURS celui du fichier retenu (une réponse lente d'un fichier précédent est ignorée)
            const mine = ++this.seq;
            this.busy = true;
            try {
                const preview = await this.send(false, file);
                if (mine === this.seq) this.result = preview;
            } catch (err) {
                if (mine === this.seq) this.error = this.message(err, "Lecture impossible : vérifiez le fichier (CSV) puis réessayez.");
            } finally {
                if (mine === this.seq) this.busy = false;
            }
            // même nom choisi à nouveau (fichier corrigé dans Excel) : sans cela le navigateur n'émet pas « change »
            e.target.value = "";
        },
        async doImport() {
            if (!this.canImport || this.busy) return;
            this.busy = true;
            this.error = "";
            try {
                const done = await this.send(true);
                alertService.success(`${done.created} produit${done.created > 1 ? "s" : ""} importé${done.created > 1 ? "s" : ""}.`);
                this.$emit("imported", done.created);
                this.$emit("close");
            } catch (err) {
                this.error = this.message(err, "Import impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = false;
            }
        },
        trapTab(e) {
            const items = [...this.$el.querySelectorAll("button:not([disabled]), input:not([disabled])")];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
    },
};
</script>

<style scoped>
.im-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: center; justify-content: center; padding: 12px; }
.im-panel { background: #fff; border-radius: 16px; width: min(860px, 100%); max-height: calc(100vh - 24px); box-shadow: 0 20px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; color: #1f2937; }
.im-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto; }
.im-head h4 { margin: 0; font-size: 20px; font-weight: 800; color: #111827; }
.im-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
.im-body { padding: 14px 20px; overflow-y: auto; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; gap: 12px; }
.im-body > * { flex-shrink: 0; }
.im-steps { list-style: decimal; margin: 0; padding-left: 22px; display: flex; flex-direction: column; gap: 8px; font-size: 15px; line-height: 1.45; }
.im-label { display: block; font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.im-file input { width: 100%; min-height: 52px; padding: 10px; border: 1px dashed #94a3b8; border-radius: 10px; background: #f8fafc; font-size: 15px; }
.im-muted { color: #475569; margin: 0; }
.im-error { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 0; font-weight: 600; }
.im-summary { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
.im-pill { padding: 6px 12px; border-radius: 999px; font-size: 14px; font-weight: 800; border: 1px solid; }
.im-pill--new { background: #ecfdf5; border-color: #86efac; color: #065f46; }
.im-pill--exists { background: #f1f5f9; border-color: #cbd5e1; color: #334155; }
.im-pill--bad { background: #fef2f2; border-color: #fca5a5; color: #7f1d1d; }
.im-rows { list-style: none; margin: 0; padding: 0; max-height: 320px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.im-row { display: grid; grid-template-columns: 78px minmax(120px, 220px) 1fr; gap: 10px; align-items: start; padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
.im-row.is-invalid { background: #fef2f2; }
.im-row.is-exists { background: #f8fafc; color: #475569; }
.im-line { color: #64748b; font-size: 13px; }
.im-name { font-weight: 700; color: #111827; }
.im-info--bad { color: #7f1d1d; font-weight: 600; }
.im-help { margin: 0; font-size: 14px; color: #92400e; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 8px 12px; }
.im-foot { display: flex; gap: 10px; align-items: center; padding: 12px 20px; border-top: 1px solid #e2e8f0; flex: 0 0 auto; flex-wrap: wrap; }
.im-spacer { flex: 1 1 auto; }
.im-btn { min-height: 52px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; color: #1f2937; font-size: 16px; font-weight: 700; cursor: pointer; }
.im-btn--small { min-height: 48px; padding: 0 12px; font-size: 14px; }
.im-btn.im-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.im-btn.im-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
@media (max-width: 599px) { .im-row { grid-template-columns: 1fr; gap: 2px; } }
</style>
