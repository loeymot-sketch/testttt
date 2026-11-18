<template>
    <div class="col-12">
        <HygieneHubBar />
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Traçabilité — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="trc-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <div class="hy-head">
                        <div class="hy-summary" data-testid="trc-summary">
                            <span class="hy-chip is-dark" data-testid="trc-count">{{ summary.count }} produit{{ summary.count > 1 ? "s" : "" }} tracé{{ summary.count > 1 ? "s" : "" }}</span>
                            <span v-if="summary.alerts" class="hy-chip is-alert" data-testid="trc-alerts">{{ summary.alerts }} écart{{ summary.alerts > 1 ? "s" : "" }}</span>
                        </div>
                        <HygieneOperatorPicker v-model="operatorId" v-model:token="operatorToken" />
                    </div>

                    <div class="hy-tabs" role="tablist">
                        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="hy-tab" :class="{ 'is-on': tab === t.key }"
                                :aria-selected="tab === t.key ? 'true' : 'false'" :data-testid="`trc-tab-${t.key}`" @click="setTab(t.key)">{{ t.label }}</button>
                    </div>

                    <!-- ── Aujourd'hui ─────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'today'">
                        <button v-if="!form" type="button" class="hy-btn hy-btn--primary" data-testid="trc-new" @click="openForm">＋ Tracer un produit</button>

                        <div v-if="form" class="hy-card is-open" style="gap:16px" data-testid="trc-form">
                            <div class="hy-field">
                                <label class="hy-label" for="trc-product">Produit *</label>
                                <input id="trc-product" v-model="form.product" class="hy-input" maxlength="80" autocomplete="off" placeholder="Rechercher ou saisir le produit" data-testid="trc-product" @input="form.productId = null" />
                                <div v-if="suggestions.length" class="hy-segment" data-testid="trc-suggestions">
                                    <button v-for="p in suggestions" :key="p.id" type="button" class="hy-chip hy-chip--btn" :data-testid="`trc-suggest-${p.id}`" @click="pickProduct(p)">{{ p.name }}</button>
                                </div>
                                <span v-if="form.productId" class="hy-sub" data-testid="trc-product-linked">✓ Produit du catalogue DLC</span>
                            </div>

                            <div class="hy-field">
                                <span class="hy-label">Fournisseur</span>
                                <div class="hy-segment" role="group" aria-label="Fournisseur">
                                    <button v-for="s in suppliers" :key="s.id" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': form.supplierId === s.id }"
                                            :aria-pressed="form.supplierId === s.id ? 'true' : 'false'" :data-testid="`trc-supplier-${s.id}`" @click="pickSupplier(s)">{{ s.name }}</button>
                                </div>
                                <input v-model="form.supplierName" class="hy-input" maxlength="80" placeholder="… ou saisir un autre fournisseur" aria-label="Autre fournisseur" data-testid="trc-supplier-name" @input="form.supplierId = null" />
                            </div>

                            <div v-if="receptions.length" class="hy-field">
                                <span class="hy-label">Livraison concernée (facultatif)</span>
                                <div class="hy-segment" role="group" aria-label="Livraison">
                                    <button v-for="r in receptions" :key="r.id" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': form.receptionId === r.id }"
                                            :aria-pressed="form.receptionId === r.id ? 'true' : 'false'" :data-testid="`trc-reception-${r.id}`" @click="form.receptionId = form.receptionId === r.id ? null : r.id">
                                        {{ r.supplier_name }}<template v-if="r.delivery_note_no"> · {{ r.delivery_note_no }}</template> · {{ hm(r.received_at) }}
                                    </button>
                                </div>
                            </div>

                            <div style="display:flex;gap:12px;flex-wrap:wrap">
                                <div class="hy-field" style="flex:1;min-width:220px">
                                    <label class="hy-label" for="trc-lot">Numéro de lot *</label>
                                    <input id="trc-lot" v-model="form.lot" class="hy-input" maxlength="60" autocomplete="off" data-testid="trc-lot" />
                                </div>
                                <div class="hy-field" style="flex:1;min-width:220px">
                                    <label class="hy-label" for="trc-dlc">{{ form.kind === "dluo" ? "DLUO" : "DLC" }} du fournisseur</label>
                                    <div style="display:flex;gap:6px">
                                        <input id="trc-dlc" v-model="form.dlc" type="date" class="hy-input" data-testid="trc-dlc" />
                                        <button type="button" class="hy-btn hy-btn--small" :aria-pressed="form.kind === 'dluo' ? 'true' : 'false'" data-testid="trc-kind" @click="form.kind = form.kind === 'dluo' ? 'dlc' : 'dluo'">{{ form.kind === "dluo" ? "DLUO" : "DLC" }}</button>
                                    </div>
                                </div>
                            </div>

                            <div class="hy-field">
                                <span class="hy-label">Photo de l'étiquette du fournisseur</span>
                                <HygienePhotoField v-model="form.photo" kind="label" label="📷 Photographier l'étiquette" />
                            </div>

                            <div class="hy-field">
                                <label class="hy-label" for="trc-note">{{ noteRequired ? "Commentaire (obligatoire)" : "Commentaire (facultatif)" }}</label>
                                <input id="trc-note" v-model="form.note" class="hy-input" maxlength="200" data-testid="trc-note" />
                                <span v-if="expired" class="hy-sub" style="color:#991b1b" role="alert" data-testid="trc-expired">⚠ DLC déjà dépassée : dites ce qui a été fait du produit.</span>
                                <span v-else-if="!form.lot.trim()" class="hy-sub" data-testid="trc-nolot">Pas de lot ? Expliquez-le ici (ex. « aucun lot sur le sachet »).</span>
                            </div>

                            <p v-if="form.error" class="hy-alert" role="alert" data-testid="trc-form-error">{{ form.error }}</p>
                            <div v-if="form.duplicate" class="hy-card is-partial" role="alert" data-testid="trc-dup">
                                <strong>Ce lot est déjà tracé {{ form.duplicate.when }} pour ce produit.</strong>
                                <span class="hy-sub">Enregistrer une seconde ligne quand même (autre colis du même lot, par exemple) ?</span>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <button type="button" class="hy-btn hy-btn--primary" :disabled="saving" data-testid="trc-dup-confirm" @click="confirmDuplicate">Enregistrer quand même</button>
                                    <button type="button" class="hy-btn" data-testid="trc-dup-cancel" @click="form.duplicate = null">Ne pas enregistrer</button>
                                </div>
                            </div>
                            <div v-else style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="button" class="hy-btn hy-btn--primary" :disabled="saving || !canSubmit" data-testid="trc-submit" @click="submit">{{ saving ? "Enregistrement…" : "Enregistrer" }}</button>
                                <button type="button" class="hy-btn" data-testid="trc-cancel" @click="form = null">Abandonner la saisie</button>
                            </div>
                        </div>

                        <p v-if="!rows.length && !form" class="hy-muted" data-testid="trc-empty">Aucun produit tracé aujourd'hui.</p>
                        <ul class="hy-list" style="margin-top:12px" data-testid="trc-today">
                            <li v-for="r in rows" :key="r.id" class="hy-card" :class="r.voided ? '' : (r.status === 'alert' ? 'is-alert' : 'is-done')" :data-testid="`trc-row-${r.id}`">
                                <div class="hy-card-head">
                                    <div>
                                        <strong class="hy-name">{{ r.product_name }}</strong>
                                        <span class="hy-sub">{{ hm(r.traced_at) }}<template v-if="r.operator_name"> · {{ r.operator_name }}</template><template v-if="r.supplier_name"> · {{ r.supplier_name }}</template><template v-if="r.delivery_note_no"> · {{ r.delivery_note_no }}</template></span>
                                    </div>
                                    <span v-if="r.lot" class="hy-tag">Lot {{ r.lot }}</span>
                                </div>
                                <p class="hy-sub">
                                    <template v-if="r.dlc_date">{{ r.dlc_kind === "dluo" ? "DLUO" : "DLC" }} {{ day(r.dlc_date) }}</template>
                                    <template v-if="r.note"> « {{ r.note }} »</template>
                                    <template v-if="r.voided"> · ANNULÉ : {{ r.void_reason }}</template>
                                </p>
                                <div v-if="!r.voided" style="display:flex;gap:8px;flex-wrap:wrap">
                                    <HygienePhotoView v-if="r.attachment_id" :id="r.attachment_id" label="📷 Voir l'étiquette" />
                                    <button v-if="voidTarget !== r.id" type="button" class="hy-btn hy-btn--small" :data-testid="`trc-void-${r.id}`" @click="voidTarget = r.id; voidReason = ''">Annuler</button>
                                </div>
                                <div v-if="voidTarget === r.id" class="hy-field">
                                    <label class="hy-label" :for="`trc-void-reason-${r.id}`">Pourquoi annuler ? (obligatoire)</label>
                                    <input :id="`trc-void-reason-${r.id}`" v-model="voidReason" class="hy-input" maxlength="120" :data-testid="`trc-void-reason-${r.id}`" />
                                    <div>
                                        <button type="button" class="hy-btn hy-btn--small hy-btn--danger" :disabled="saving || voidReason.trim().length < 3" :data-testid="`trc-void-confirm-${r.id}`" @click="confirmVoid">Annuler la ligne</button>
                                        <button type="button" class="hy-btn hy-btn--small" @click="voidTarget = null">Garder</button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </template>

                    <!-- ── Journal ────────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'journal'">
                        <div class="hy-field" style="max-width:420px;margin-bottom:10px">
                            <label class="hy-label" for="trc-q">Rechercher (produit, fournisseur, lot, BL)</label>
                            <input id="trc-q" v-model="q" class="hy-input" maxlength="60" data-testid="trc-q" @input="debouncedHistory" />
                        </div>
                        <p v-if="!history.length" class="hy-muted" data-testid="trc-history-empty">Aucune ligne sur les 30 derniers jours.</p>
                        <ul class="hy-list" data-testid="trc-history">
                            <li v-for="h in history" :key="h.id" class="hy-row" :class="{ 'is-alert': h.status === 'alert' && !h.voided, 'is-void': h.voided }">
                                <span class="hy-row-when">{{ dayHm(h.traced_at) }}</span>
                                <span class="hy-row-name">{{ h.product_name }}</span>
                                <span class="hy-row-meta" :data-testid="`trc-history-meta-${h.id}`">
                                    <template v-if="h.lot">Lot {{ h.lot }} · </template><template v-if="h.dlc_date">{{ kindText(h.dlc_kind) }} {{ day(h.dlc_date) }} · </template>
                                    <template v-if="h.supplier_name">{{ h.supplier_name }} · </template>{{ h.operator_name }}
                                    <template v-if="h.note"> · « {{ h.note }} »</template><template v-if="h.voided"> · ANNULÉ : {{ h.void_reason }}</template>
                                </span>
                                <HygienePhotoView v-if="h.attachment_id" :id="h.attachment_id" label="📷 Voir l'étiquette" />
                            </li>
                        </ul>
                    </template>

                    <!-- ── Rappel de lot ──────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'recall'">
                        <p class="hy-sub">Rappel produit : saisissez le numéro de lot du fournisseur. La fiche liste où il est entré et toutes les préparations qui en sont issues.</p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">
                            <input v-model="recallLot" class="hy-input" style="max-width:320px" maxlength="60" placeholder="N° de lot" aria-label="Numéro de lot" data-testid="trc-recall-lot" @keyup.enter="runRecall" />
                            <button type="button" class="hy-btn hy-btn--primary" :disabled="recallLot.trim().length < 2 || saving" data-testid="trc-recall-go" @click="runRecall">Chercher</button>
                            <button v-if="recall" type="button" class="hy-btn" data-testid="trc-recall-print" @click="printSheet">🖨 Imprimer la fiche</button>
                        </div>
                        <p v-if="recallError" class="hy-alert" role="alert" data-testid="trc-recall-error">{{ recallError }}</p>
                        <div v-if="recall" class="trc-print-area" data-testid="trc-recall">
                            <!-- [2026-11-18 audit P2-g] en-tête imprimé seulement : restaurant, date et heure d'édition -->
                            <p class="trc-print-only trc-print-head" data-testid="trc-print-head"><template v-if="restaurantName"><strong>{{ restaurantName }}</strong> · </template>Fiche éditée le {{ printedAtText }}</p>
                            <h4 class="hy-h hy-h--big">Fiche de rappel — lot {{ recall.lot }}</h4>
                            <p class="hy-note" v-if="!recall.entries.length && !recall.receptions.length && !recall.labels.length" data-testid="trc-recall-none">Aucune trace de ce lot. Vérifiez l'écriture du numéro, ou cherchez une partie du lot dans l'onglet Journal.</p>
                            <template v-else>
                                <p class="hy-sub" data-testid="trc-recall-sum">{{ recall.entries.length }} entrée{{ recall.entries.length > 1 ? "s" : "" }} · {{ recall.receptions.length }} livraison{{ recall.receptions.length > 1 ? "s" : "" }}<template v-if="voidedReceptions"> (dont {{ voidedReceptions }} annulée{{ voidedReceptions > 1 ? "s" : "" }})</template> · {{ recall.labels.length }} étiquette{{ recall.labels.length > 1 ? "s" : "" }} de préparation</p>
                                <h5 v-if="recall.entries.length" class="hy-h">Produit tracé</h5>
                                <ul class="hy-list">
                                    <li v-for="e in recall.entries" :key="`e${e.id}`" class="hy-row" :data-testid="`trc-recall-entry-${e.id}`">
                                        <span>{{ dayHm(e.traced_at) }} · <strong>{{ e.product_name }}</strong><template v-if="e.supplier_name"> · {{ e.supplier_name }}</template><template v-else> · fournisseur non renseigné</template><template v-if="e.dlc_date"> · {{ kindText(e.dlc_kind) }} {{ day(e.dlc_date) }}</template></span>
                                        <HygienePhotoView v-if="e.attachment_id" class="trc-no-print" :id="e.attachment_id" label="📷 Voir l'étiquette" />
                                    </li>
                                </ul>
                                <h5 v-if="recall.receptions.length" class="hy-h">Livraisons</h5>
                                <ul class="hy-list">
                                    <li v-for="r in recall.receptions" :key="`r${r.id}`" class="hy-row" :class="{ 'is-alert': r.status === 'alert' && !r.voided, 'trc-voided': r.voided }" :data-testid="`trc-recall-reception-${r.id}`">
                                        <span :class="{ 'trc-struck': r.voided }">{{ dayHm(r.received_at) }} · <strong>{{ r.supplier_name || "fournisseur non renseigné" }}</strong><template v-if="r.delivery_note_no"> · {{ r.delivery_note_no }}</template> · {{ r.decision_label }}</span>
                                        <strong v-if="r.voided" class="trc-void-tag" :data-testid="`trc-recall-reception-void-${r.id}`">ANNULÉE : {{ r.void_reason }}<template v-if="r.voided_at"> (le {{ dayHm(r.voided_at) }}<template v-if="r.voided_by_name">, par {{ r.voided_by_name }}</template>)</template></strong>
                                        <HygienePhotoView v-for="(pid, i) in photoIds(r)" :key="`rp${pid}`" class="trc-no-print" :id="pid" :label="i === 0 ? '📷 Voir le BL' : `📷 Photo ${i + 1}`" />
                                    </li>
                                </ul>
                                <h5 v-if="recall.labels.length" class="hy-h">Préparations issues du lot</h5>
                                <ul class="hy-list" data-testid="trc-recall-labels">
                                    <li v-for="l in recall.labels" :key="`l${l.id}`" class="hy-row" :class="{ 'is-void': l.discard_reason === 'cancelled' }" :style="{ marginLeft: `${l.depth * 24}px` }">
                                        <span class="hy-row-when">{{ dayHm(l.opened_at) }}</span>
                                        <strong>{{ l.product_name }}</strong>
                                        <span class="hy-row-meta">N° {{ l.lot }} · jusqu'au {{ dayHm(l.expires_at) }}<template v-if="labelPrintNote(l)"> · {{ labelPrintNote(l) }}</template><template v-if="l.discarded_at"> · {{ discardText(l.discard_reason) }} le {{ dayHm(l.discarded_at) }}</template><template v-else> · encore en cours</template></span>
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Traçabilité amont : produit · fournisseur · lot · DLC · BL · photo de l'étiquette, et la fiche de
 * rappel d'un lot. Le serveur impose le lot (ou une explication) et un commentaire si la DLC est déjà dépassée ; l'écran le dit avant.
 */
import HygieneHubBar from "./HygieneHubBar";
import axios from "axios";
import alertService from "../../../services/alertService";
import HygieneOperatorPicker from "./HygieneOperatorPicker";
import HygienePhotoField from "./HygienePhotoField";
import HygienePhotoView from "./HygienePhotoView";
import { apiMessage, formatHm, formatDayHm, formatDay, newClientRef, refFor, latest, loadedBody, savedBody, loadFailedText } from "../../../helpers/hygieneCommon";
import hygieneRefresh from "../../../helpers/hygieneRefresh";
import { searchProducts } from "../../../helpers/dlcSearch";

const historySeq = latest();
const norm = (s) => String(s || "").trim().toLowerCase();
/** Classe posée sur <body> le temps d'imprimer la fiche de rappel (les règles @media print ne valent qu'avec elle). */
const PRINT_CLASS = "trc-printing";

export default {
    name: "HygieneTraceabilityComponent",
    mixins: [hygieneRefresh],
    components: { HygieneHubBar, HygieneOperatorPicker, HygienePhotoField, HygienePhotoView },
    data() {
        return {
            rows: [], meta: { summary: { count: 0, alerts: 0 }, products: [], suppliers: [], receptions: [], server_now: null, timezone: "Europe/Paris" },
            loaded: false, loadError: "",
            lastOkAt: null, // [VG-2026-10-21 M8-103] dernier chargement réussi : un échec ensuite dit « chiffres périmés », jamais une liste vide
            tab: "today", operatorId: null, operatorToken: null, form: null, saving: false,
            voidTarget: null, voidReason: "", history: [], q: "", timer: null, recallLot: "", recall: null, recallError: "",
            printedAt: null,
        };
    },
    computed: {
        summary() { return this.meta.summary; },
        suppliers() { return this.meta.suppliers || []; },
        receptions() { return this.meta.receptions || []; },
        tabs() { return [{ key: "today", label: "Aujourd'hui" }, { key: "journal", label: "Journal" }, { key: "recall", label: "Rappel de lot" }]; },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
        todayIso() { return (this.meta.server_now || new Date().toISOString()).slice(0, 10); },
        suggestions() {
            const f = this.form;
            const t = f ? f.product.trim().toLowerCase() : "";
            if (!f || f.productId || t.length < 2) return [];
            // [2026-11-18 audit P2-c] même recherche tolérante que l'écran DLC (accents, pluriels, fautes, « l'ail »)
            return searchProducts(this.meta.products || [], f.product).items.slice(0, 8);
        },
        /** Ce que l'opérateur a saisi (hors message d'erreur et avertissement) : quand ça change, le message du serveur n'est plus vrai. */
        formSignature() {
            const f = this.form;
            return f ? JSON.stringify([f.product, f.productId, f.supplierId, f.supplierName, f.receptionId, f.lot, f.dlc, f.kind, f.photo, f.note]) : "";
        },
        voidedReceptions() { return this.recall ? this.recall.receptions.filter((r) => r.voided).length : 0; },
        restaurantName() {
            const company = this.$store && this.$store.getters ? this.$store.getters["company/lists"] : null;
            const name = company && typeof company === "object" && !Array.isArray(company) ? company.company_name : "";
            if (name) return String(name).trim();
            // la page porte le nom du restaurant dans son titre (master.blade) ; le suivi des commandes peut y préfixer « (3) »
            return typeof document !== "undefined" ? String(document.title || "").replace(/^\(\d+\)\s*/, "").trim() : "";
        },
        printedAtText() {
            const at = this.printedAt || new Date().toISOString();
            try {
                return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" })
                    .format(new Date(at)).replace(" ", " à ");
            } catch (_) { return formatDayHm(at, this.meta.timezone); }
        },
        expired() { return Boolean(this.form && this.form.dlc && this.form.dlc < this.todayIso); },
        noteRequired() { return this.expired || (this.form && !this.form.lot.trim()); },
        canSubmit() {
            const f = this.form;
            if (!f) return false;
            if (f.product.trim().length < 2) return false;
            if (this.noteRequired && f.note.trim().length < 3) return false;
            return true;
        },
    },
    watch: {
        // [2026-11-18 audit P1] une erreur du serveur s'efface dès que la saisie change ; l'avertissement « lot déjà tracé » aussi
        formSignature(now, before) {
            if (!this.form || !before) return;
            this.form.error = "";
            this.form.duplicate = null;
            this.form.duplicateOk = false;
        },
    },
    created() { this.load(); },
    mounted() {
        if (typeof window === "undefined") return;
        this._onBeforePrint = () => this.preparePrint();
        this._onAfterPrint = () => this.endPrint();
        window.addEventListener("beforeprint", this._onBeforePrint);
        window.addEventListener("afterprint", this._onAfterPrint);
    },
    beforeUnmount() {
        if (this.timer) clearTimeout(this.timer);
        if (typeof window !== "undefined" && this._onBeforePrint) {
            window.removeEventListener("beforeprint", this._onBeforePrint);
            window.removeEventListener("afterprint", this._onAfterPrint);
        }
        this.endPrint();
    },
    methods: {
        kindText(k) { return k === "dluo" ? "DLUO" : "DLC"; },
        /** Toutes les photos d'une livraison (BL puis les autres), sans doublon. */
        photoIds(r) { return [...new Set([r.attachment_id, ...(Array.isArray(r.attachment_ids) ? r.attachment_ids : [])].filter((x) => Number.isInteger(x) && x > 0))]; },
        /** Une étiquette dont l'imprimante n'a pas confirmé la sortie reste sur la fiche, mais le dit. */
        labelPrintNote(l) {
            if (l.status === "unconfirmed" || l.status === "claimed") return "impression non confirmée";
            if (l.status === "failed") return "impression incomplète";
            return "";
        },
        hyDraft() { return this.form !== null || this.voidTarget !== null; },
        hyReload() { return this.load(); },
        hm(iso) { return formatHm(iso, this.meta.timezone); },
        dayHm(iso) { return iso ? formatDayHm(iso, this.meta.timezone) : ""; },
        day(d) { return String(d).split("-").reverse().join("/"); },
        discardText(r) { return ({ discarded: "jetée", used: "utilisée", cancelled: "annulée" })[r] || "sortie"; },
        async load() {
            try {
                const data = loadedBody(await axios.get("admin/hygiene/trace/today")); // [VG-2026-10-21 M8-103]
                this.rows = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
                this.lastOkAt = Date.now();
            } catch (e) {
                // [VG-2026-10-21 M8-103] premier chargement : erreur ; ensuite : les données affichées restent, dites périmées
                this.loadError = loadFailedText("Traçabilité indisponible. Vérifiez la connexion puis rechargez la page.", this.lastOkAt, this.meta.timezone || "Europe/Paris");
            }
        },
        setTab(k) { this.tab = k; if (k === "journal") this.loadHistory(); },
        async loadHistory() {
            const id = historySeq.next();
            try {
                const data = loadedBody(await axios.get("admin/hygiene/trace/history", { params: { q: this.q.trim() || undefined, limit: 200 } })); // [VG-2026-10-21 M8-103]
                if (historySeq.isCurrent(id)) this.history = data.data || [];
            } catch (e) {
                alertService.error(apiMessage(e, "Journal indisponible."));
            }
        },
        debouncedHistory() { if (this.timer) clearTimeout(this.timer); this.timer = setTimeout(() => this.loadHistory(), 300); },

        openForm() { this.form = { product: "", productId: null, supplierId: null, supplierName: "", receptionId: null, lot: "", dlc: "", kind: "dlc", photo: null, note: "", error: "", duplicate: null, duplicateOk: false, ref: newClientRef("t") }; },
        pickProduct(p) { this.form.product = p.name; this.form.productId = p.id; },
        pickSupplier(s) { this.form.supplierId = s.id; this.form.supplierName = ""; },
        /**
         * [2026-11-18 audit P2-d] Même produit + même lot (à la casse près) déjà tracé : aujourd'hui (liste chargée) ou sur les 30 jours du
         * journal. Rien n'est bloqué : l'écran demande seulement confirmation. Si le journal ne répond pas, on n'empêche pas d'enregistrer.
         */
        async findDuplicate(f) {
            const lot = norm(f.lot);
            if (!lot) return null;
            const product = norm(f.product);
            const same = (r) => !r.voided && norm(r.lot) === lot && ((f.productId && r.dlc_product_id === f.productId) || norm(r.product_name) === product);
            let hit = this.rows.find(same) || null;
            if (!hit) {
                try {
                    const data = loadedBody(await axios.get("admin/hygiene/trace/history", { params: { q: f.lot.trim(), limit: 50 } }));
                    hit = (data.data || []).find(same) || null;
                } catch (_) { hit = null; }
            }
            if (!hit) return null;
            const today = formatDay(hit.traced_at, this.meta.timezone) === this.todayLabel;
            return { id: hit.id, when: today ? `aujourd'hui à ${this.hm(hit.traced_at)}` : `le ${this.dayHm(hit.traced_at)}` };
        },
        confirmDuplicate() {
            if (!this.form) return;
            this.form.duplicateOk = true;
            this.form.duplicate = null;
            this.submit();
        },
        async submit() {
            const f = this.form;
            if (!f || this.saving || !this.canSubmit) return;
            this.saving = true;
            f.error = "";
            if (!f.duplicateOk) {
                const sig = this.formSignature;
                const dup = await this.findDuplicate(f);
                // saisie abandonnée ou modifiée pendant la vérification : on n'envoie rien, l'opérateur relance
                if (this.form !== f || this.formSignature !== sig) { this.saving = false; return; }
                if (dup) {
                    f.duplicate = dup;
                    this.saving = false;
                    return;
                }
            }
            try {
                savedBody(await axios.post("admin/hygiene/trace", { // [VG-2026-10-21 M8-101]
                    ...(f.productId ? { dlc_product_id: f.productId } : { product_name: f.product.trim() }),
                    ...(f.supplierId ? { supplier_id: f.supplierId } : { supplier_name: f.supplierName.trim() || undefined }),
                    reception_id: f.receptionId || undefined, lot: f.lot.trim() || undefined, dlc_date: f.dlc || undefined, dlc_kind: f.kind,
                    note: f.note.trim() || undefined, attachment_id: f.photo || undefined, operator_user_id: this.operatorId || undefined, operator_token: this.operatorToken || undefined,
                    client_ref: refFor(f.ref, { p: f.productId || f.product.trim(), s: f.supplierId || f.supplierName.trim(), r: f.receptionId, l: f.lot.trim(), d: f.dlc, k: f.kind, n: f.note.trim(), ph: f.photo, o: this.operatorId }),
                }));
                this.form = null;
                await this.load();
                alertService.success("Produit tracé.");
            } catch (e) {
                f.error = apiMessage(e, "Enregistrement impossible : vérifiez la connexion puis réessayez (rien n'est doublé).");
            } finally {
                this.saving = false;
            }
        },
        async confirmVoid() {
            if (!this.voidTarget || this.saving) return;
            this.saving = true;
            try {
                savedBody(await axios.post(`admin/hygiene/trace/${this.voidTarget}/void`, { reason: this.voidReason.trim(), operator_user_id: this.operatorId || undefined, operator_token: this.operatorToken || undefined })); // [VG-2026-10-21 M8-101]
                this.voidTarget = null;
                await this.load();
                alertService.success("Ligne annulée (elle reste au journal).");
            } catch (e) {
                alertService.error(apiMessage(e, "Annulation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },
        async runRecall() {
            if (this.saving || this.recallLot.trim().length < 2) return;
            this.saving = true;
            this.recallError = "";
            try {
                const data = loadedBody(await axios.get("admin/hygiene/trace/recall", { params: { lot: this.recallLot.trim() } })); // [VG-2026-10-21 M8-103]
                this.recall = data.data;
            } catch (e) {
                this.recall = null;
                this.recallError = apiMessage(e, "Recherche impossible : réessayez.");
            } finally {
                this.saving = false;
            }
        },
        /** [2026-11-18 audit P2-g] Impression : seule la fiche sort, avec l'heure d'édition (voir les règles @media print plus bas). */
        preparePrint() {
            if (this.tab !== "recall" || !this.recall) return false;
            this.printedAt = new Date().toISOString();
            if (typeof document !== "undefined" && document.body) document.body.classList.add(PRINT_CLASS);
            return true;
        },
        endPrint() { if (typeof document !== "undefined" && document.body) document.body.classList.remove(PRINT_CLASS); },
        async printSheet() {
            if (typeof window === "undefined" || !window.print || !this.preparePrint()) return;
            await this.$nextTick(); // l'heure d'édition est à l'écran avant que le navigateur ne mette en page
            window.print();
        },
    },
};
</script>

<style src="./hygiene.css"></style>
<style>
/* [2026-11-18 audit] Fiche de rappel : réception annulée barrée, mention lisible ; impression de la fiche seule. */
.trc-struck { text-decoration: line-through; color: #64748b; }
.trc-void-tag { color: #991b1b; }
.hy-row.trc-voided { border-color: #fecaca; background: #fff7f7; }
.trc-print-only { display: none; }
@media print {
    /* N'agit que pendant l'impression demandée depuis la fiche (classe posée sur <body>) : aucune autre page n'est touchée. */
    body.trc-printing *:not(:has(.trc-print-area)):not(.trc-print-area):not(.trc-print-area *) { display: none !important; }
    body.trc-printing *:has(.trc-print-area) {
        margin: 0 !important; padding: 0 !important; border: 0 !important; box-shadow: none !important; background: none !important;
        position: static !important; transform: none !important; width: auto !important; max-width: none !important; min-height: 0 !important; overflow: visible !important;
    }
    body.trc-printing .trc-print-area { display: block !important; color: #000; }
    body.trc-printing .trc-print-only { display: block !important; margin: 0 0 8px; font-size: 12px; }
    body.trc-printing .trc-no-print { display: none !important; }
    body.trc-printing .trc-print-area .hy-row { break-inside: avoid; background: #fff !important; }
}
</style>
