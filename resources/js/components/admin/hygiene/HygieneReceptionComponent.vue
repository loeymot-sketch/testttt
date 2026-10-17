<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Réception de marchandises — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="rcp-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <div class="hy-head">
                        <div class="hy-summary" data-testid="rcp-summary">
                            <span class="hy-chip is-dark" data-testid="rcp-count">{{ summary.count }} livraison{{ summary.count > 1 ? "s" : "" }}</span>
                            <span class="hy-chip" :class="{ 'is-alert': summary.alerts }" data-testid="rcp-alerts">{{ summary.alerts }} écart{{ summary.alerts > 1 ? "s" : "" }}</span>
                            <span v-if="summary.refused" class="hy-chip is-alert" data-testid="rcp-refused">{{ summary.refused }} refusée{{ summary.refused > 1 ? "s" : "" }}</span>
                        </div>
                        <HygieneOperatorPicker v-model="operatorId" />
                    </div>

                    <div class="hy-tabs" role="tablist">
                        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="hy-tab" :class="{ 'is-on': tab === t.key }"
                                :aria-selected="tab === t.key ? 'true' : 'false'" :data-testid="`rcp-tab-${t.key}`" @click="setTab(t.key)">{{ t.label }}</button>
                    </div>

                    <!-- ── Aujourd'hui ─────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'today'">
                        <button v-if="!form" type="button" class="hy-btn hy-btn--primary" data-testid="rcp-new" @click="openForm">＋ Nouvelle réception</button>

                        <!-- formulaire -->
                        <div v-if="form" class="hy-card is-open" style="gap:16px" data-testid="rcp-form">
                            <div class="hy-field">
                                <span class="hy-label">Fournisseur *</span>
                                <div class="hy-segment" role="group" aria-label="Fournisseur">
                                    <button v-for="s in suppliers" :key="s.id" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': form.supplierId === s.id }"
                                            :aria-pressed="form.supplierId === s.id ? 'true' : 'false'" :data-testid="`rcp-supplier-${s.id}`" @click="pickSupplier(s)">{{ s.name }}</button>
                                    <button type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': form.newSupplier }" :aria-pressed="form.newSupplier ? 'true' : 'false'"
                                            data-testid="rcp-supplier-new" @click="form.newSupplier = !form.newSupplier; if (form.newSupplier) form.supplierId = null">＋ Autre fournisseur</button>
                                </div>
                                <input v-if="form.newSupplier" v-model="form.supplierName" class="hy-input" maxlength="80" placeholder="Nom du fournisseur" aria-label="Nom du nouveau fournisseur" data-testid="rcp-supplier-name" />
                            </div>

                            <div style="display:flex;gap:12px;flex-wrap:wrap">
                                <div class="hy-field" style="flex:1;min-width:200px">
                                    <label class="hy-label" for="rcp-bl">Numéro du bon de livraison</label>
                                    <input id="rcp-bl" v-model="form.bl" class="hy-input" maxlength="40" data-testid="rcp-bl" />
                                </div>
                                <div class="hy-field" style="flex:1;min-width:200px">
                                    <label class="hy-label" for="rcp-driver">Livreur (facultatif)</label>
                                    <input id="rcp-driver" v-model="form.driver" class="hy-input" maxlength="60" data-testid="rcp-driver" />
                                </div>
                            </div>

                            <div class="hy-field">
                                <span class="hy-label">Températures à la livraison (au moins une) *</span>
                                <div class="hy-grid" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
                                    <div v-for="c in categories" :key="c.key" class="hy-card" :class="tempCardClass(c)" :data-testid="`rcp-temp-${c.key}`">
                                        <div class="hy-card-head">
                                            <strong>{{ c.label }}</strong>
                                            <span class="hy-tag">{{ c.max === null ? "sans limite" : limitText(null, c.max) }}</span>
                                        </div>
                                        <HygieneMeasureInput v-model="form.temps[c.key]" :label="`Température ${c.label.toLowerCase()}`" :testid="`rcp-t-${c.key}`" />
                                        <p v-if="rawError(c)" class="hy-sub" style="color:#991b1b" role="alert">Valeur illisible : tapez les chiffres (ex. 3 ou 3,5), le signe se règle avec ±.</p>
                                        <p v-else-if="tempValue(c) !== null" class="hy-verdict" :class="tempStatus(c) === 'alert' ? 'is-alert' : 'is-ok'" :data-testid="`rcp-verdict-${c.key}`">
                                            {{ tempStatus(c) === "alert" ? "⚠ Au-dessus de la limite" : (c.max === null ? "Relevé noté" : "✓ Conforme") }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="hy-field">
                                <span class="hy-label">Contrôles visuels * <button type="button" class="hy-btn hy-btn--small" style="margin-left:8px" data-testid="rcp-all-ok" @click="allOk">Tout est conforme</button></span>
                                <ul class="hy-list">
                                    <li v-for="c in checks" :key="c.key" class="hy-row" :class="{ 'is-alert': form.checks[c.key] === false }" :data-testid="`rcp-check-${c.key}`">
                                        <span class="hy-row-name" style="flex:1;min-width:220px">{{ c.label }}</span>
                                        <span class="hy-segment" role="group" :aria-label="c.label">
                                            <button type="button" class="hy-btn hy-btn--small" :class="{ 'is-ok': form.checks[c.key] === true }" :aria-pressed="form.checks[c.key] === true ? 'true' : 'false'"
                                                    :data-testid="`rcp-check-${c.key}-ok`" @click="form.checks[c.key] = true">✓ Conforme</button>
                                            <button type="button" class="hy-btn hy-btn--small" :class="{ 'is-ko': form.checks[c.key] === false }" :aria-pressed="form.checks[c.key] === false ? 'true' : 'false'"
                                                    :data-testid="`rcp-check-${c.key}-ko`" @click="form.checks[c.key] = false">✗ Non conforme</button>
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div class="hy-field">
                                <span class="hy-label">Photo du bon de livraison</span>
                                <HygienePhotoField v-model="form.photo" kind="bl" label="📷 Photographier le BL" />
                            </div>

                            <div v-if="hasDeviation" class="hy-card is-alert" data-testid="rcp-deviation">
                                <strong>Livraison non conforme — décision obligatoire</strong>
                                <div class="hy-segment" role="group" aria-label="Décision">
                                    <button v-for="d in deviationDecisions" :key="d.key" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': form.decision === d.key }"
                                            :aria-pressed="form.decision === d.key ? 'true' : 'false'" :data-testid="`rcp-decision-${d.key}`" @click="form.decision = d.key">{{ d.label }}</button>
                                </div>
                                <div class="hy-field">
                                    <label class="hy-label" for="rcp-note">Ce qui ne va pas et ce qui a été décidé (obligatoire)</label>
                                    <textarea id="rcp-note" v-model="form.note" class="hy-textarea" maxlength="300" data-testid="rcp-note"></textarea>
                                </div>
                            </div>
                            <div v-else class="hy-field">
                                <label class="hy-label" for="rcp-note-ok">Commentaire (facultatif)</label>
                                <input id="rcp-note-ok" v-model="form.note" class="hy-input" maxlength="300" data-testid="rcp-note-ok" />
                            </div>

                            <p v-if="form.error" class="hy-alert" role="alert" data-testid="rcp-form-error">{{ form.error }}</p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="button" class="hy-btn hy-btn--primary" :disabled="saving || !canSubmit" data-testid="rcp-submit" @click="submit">
                                    {{ saving ? "Enregistrement…" : "Enregistrer la réception" }}
                                </button>
                                <button type="button" class="hy-btn" data-testid="rcp-cancel" @click="form = null">Abandonner la saisie</button>
                            </div>
                            <p v-if="!canSubmit && blockReason" class="hy-sub" data-testid="rcp-block">{{ blockReason }}</p>
                        </div>

                        <p v-if="!rows.length && !form" class="hy-muted" data-testid="rcp-empty">Aucune livraison enregistrée aujourd'hui.</p>
                        <ul class="hy-list" style="margin-top:12px" data-testid="rcp-today">
                            <li v-for="r in rows" :key="r.id" class="hy-card" :class="rowClass(r)" :data-testid="`rcp-row-${r.id}`">
                                <div class="hy-card-head">
                                    <div>
                                        <strong class="hy-name">{{ r.supplier_name }}<template v-if="r.delivery_note_no"> · {{ blText(r.delivery_note_no) }}</template></strong>
                                        <span class="hy-sub">{{ hm(r.received_at) }}<template v-if="r.operator_name"> · {{ r.operator_name }}</template><template v-if="r.delivered_by"> · livreur {{ r.delivered_by }}</template></span>
                                    </div>
                                    <span class="hy-tag" :class="{ 'is-late': r.status === 'alert' }">{{ r.status === "alert" ? "⚠ " : "✓ " }}{{ r.decision_label }}</span>
                                </div>
                                <div class="hy-summary">
                                    <span v-for="t in r.temperatures" :key="t.category" class="hy-chip" :class="t.status === 'alert' ? 'is-alert' : 'is-ok'">{{ t.label }} {{ fmt(t.value_c) }} °C</span>
                                    <span v-for="c in r.checks.filter((x) => !x.ok)" :key="c.key" class="hy-chip is-alert">✗ {{ c.label }}</span>
                                </div>
                                <p v-if="r.note" class="hy-sub">« {{ r.note }} »</p>
                                <p v-if="r.voided" class="hy-sub" style="color:#991b1b">ANNULÉE : {{ r.void_reason }}</p>
                                <div v-if="!r.voided" style="display:flex;gap:8px;flex-wrap:wrap">
                                    <HygienePhotoView v-if="r.attachment_id" :id="r.attachment_id" label="📷 Voir le BL" />
                                    <button v-if="voidTarget !== r.id" type="button" class="hy-btn hy-btn--small" :data-testid="`rcp-void-${r.id}`" @click="askVoid(r)">Annuler cette réception</button>
                                </div>
                                <div v-if="voidTarget === r.id" class="hy-field">
                                    <label class="hy-label" :for="`rcp-void-reason-${r.id}`">Pourquoi annuler ? (obligatoire)</label>
                                    <input :id="`rcp-void-reason-${r.id}`" v-model="voidReason" class="hy-input" maxlength="120" :data-testid="`rcp-void-reason-${r.id}`" />
                                    <div>
                                        <button type="button" class="hy-btn hy-btn--small hy-btn--danger" :disabled="saving || voidReason.trim().length < 3" :data-testid="`rcp-void-confirm-${r.id}`" @click="confirmVoid">Annuler la réception</button>
                                        <button type="button" class="hy-btn hy-btn--small" @click="voidTarget = null">Garder</button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </template>

                    <!-- ── Journal ────────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'journal'">
                        <label class="hy-chip hy-chip--btn" style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px">
                            <input v-model="alertsOnly" type="checkbox" data-testid="rcp-alerts-only" @change="loadHistory" /> Écarts seulement
                        </label>
                        <p v-if="!history.length" class="hy-muted" data-testid="rcp-history-empty">Aucune réception sur les 30 derniers jours.</p>
                        <ul class="hy-list" data-testid="rcp-history">
                            <li v-for="h in history" :key="h.id" class="hy-row" :class="{ 'is-alert': h.status === 'alert' && !h.voided, 'is-void': h.voided }">
                                <span class="hy-row-when">{{ dayHm(h.received_at) }}</span>
                                <span class="hy-row-name">{{ h.supplier_name }}</span>
                                <span class="hy-row-meta">
                                    <template v-if="h.delivery_note_no">{{ blText(h.delivery_note_no) }} · </template>
                                    <template v-for="t in h.temperatures" :key="t.category">{{ t.label }} {{ fmt(t.value_c) }} °C · </template>
                                    {{ h.decision_label }}<template v-if="h.operator_name"> · {{ h.operator_name }}</template><template v-if="h.note"> · « {{ h.note }} »</template>
                                    <template v-if="h.voided"> · ANNULÉE : {{ h.void_reason }}</template>
                                </span>
                            </li>
                        </ul>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Contrôle à la réception : fournisseur, BL, trois températures (frais / surgelé / ambiant)
 * avec leur limite, contrôles visuels, photo du BL ; un écart impose une décision et un commentaire. Le verdict affiché n'est qu'un
 * APERÇU : le serveur décide, enregistre et refuse une livraison non conforme « acceptée » sans réserve.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import HygieneOperatorPicker from "./HygieneOperatorPicker";
import HygieneMeasureInput from "./HygieneMeasureInput";
import HygienePhotoField from "./HygienePhotoField";
import HygienePhotoView from "./HygienePhotoView";
import { apiMessage, formatHm, formatDayHm, formatDay, newClientRef, refFor, latest } from "../../../helpers/hygieneCommon";
import hygieneRefresh from "../../../helpers/hygieneRefresh";
import { parseTemperature, formatTemperature, limitText } from "../../../helpers/hygieneTemperature";

const historySeq = latest();

export default {
    name: "HygieneReceptionComponent",
    mixins: [hygieneRefresh],
    components: { HygieneOperatorPicker, HygieneMeasureInput, HygienePhotoField, HygienePhotoView },
    data() {
        return {
            rows: [],
            meta: { summary: { count: 0, alerts: 0, refused: 0 }, categories: [], checks: [], decisions: [], suppliers: [], server_now: null, timezone: "Europe/Paris" },
            loaded: false,
            loadError: "",
            tab: "today",
            operatorId: null,
            form: null,
            saving: false,
            voidTarget: null,
            voidReason: "",
            history: [],
            alertsOnly: false,
        };
    },
    computed: {
        summary() { return this.meta.summary; },
        categories() { return this.meta.categories || []; },
        checks() { return this.meta.checks || []; },
        suppliers() { return this.meta.suppliers || []; },
        deviationDecisions() { return (this.meta.decisions || []).filter((d) => d.key !== "accepted"); },
        tabs() { return [{ key: "today", label: "Aujourd'hui" }, { key: "journal", label: "Journal" }]; },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
        hasDeviation() {
            if (!this.form) return false;
            return this.categories.some((c) => this.tempValue(c) !== null && this.tempStatus(c) === "alert") || this.checks.some((c) => this.form.checks[c.key] === false);
        },
        supplierOk() {
            const f = this.form;
            return Boolean(f) && (f.newSupplier ? f.supplierName.trim().length >= 2 : f.supplierId !== null);
        },
        anyTemp() { return this.categories.some((c) => this.tempValue(c) !== null); },
        illegible() { return this.categories.some((c) => this.rawError(c)); },
        allAnswered() { return this.checks.every((c) => typeof this.form.checks[c.key] === "boolean"); },
        blockReason() {
            const f = this.form;
            if (!f) return "";
            if (!this.supplierOk) return "Choisissez le fournisseur.";
            if (this.illegible) return "Une température est illisible.";
            if (!this.anyTemp) return "Relevez au moins une température.";
            if (!this.allAnswered) return "Répondez à tous les contrôles visuels (ou « Tout est conforme »).";
            if (this.hasDeviation && !f.decision) return "Livraison non conforme : choisissez « Accepté avec réserve » ou « Refusé ».";
            if (this.hasDeviation && f.note.trim().length < 3) return "Livraison non conforme : dites ce qui ne va pas.";
            return "";
        },
        canSubmit() { return Boolean(this.form) && this.blockReason === ""; },
    },
    created() { this.load(); },
    methods: {
        hyDraft() { return this.form !== null || this.voidTarget !== null; },
        hyReload() { return this.load(); },
        fmt(v) { return formatTemperature(v); },
        /** « 2210 » → « BL 2210 » ; « BL-2210 » reste tel quel (jamais « BL BL-2210 »). */
        blText(n) { return /^bl/i.test(String(n).trim()) ? String(n).trim() : `BL ${String(n).trim()}`; },
        limitText(min, max) { return limitText(min, max); },
        hm(iso) { return formatHm(iso, this.meta.timezone); },
        dayHm(iso) { return formatDayHm(iso, this.meta.timezone); },
        rowClass(r) { return r.voided ? "" : (r.status === "alert" ? (r.decision === "refused" ? "is-alert" : "is-partial") : "is-done"); },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/receptions/today");
                this.rows = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                this.loadError = "Réceptions indisponibles. Vérifiez la connexion puis rechargez la page.";
            }
        },
        setTab(k) { this.tab = k; if (k === "journal") this.loadHistory(); },
        async loadHistory() {
            const id = historySeq.next();
            try {
                const { data } = await axios.get("admin/hygiene/receptions/history", { params: { alerts_only: this.alertsOnly ? 1 : 0, limit: 200 } });
                if (historySeq.isCurrent(id)) this.history = data.data || [];
            } catch (e) {
                alertService.error(apiMessage(e, "Journal indisponible."));
            }
        },

        // ── formulaire ──────────────────────────────────────────────────────
        openForm() {
            const temps = {};
            this.categories.forEach((c) => { temps[c.key] = { raw: "", neg: Boolean(c.negative) }; });
            const checks = {};
            this.checks.forEach((c) => { checks[c.key] = null; });
            this.form = { supplierId: null, newSupplier: false, supplierName: "", bl: "", driver: "", temps, checks, photo: null, decision: "", note: "", error: "", ref: newClientRef("r") };
        },
        pickSupplier(s) { this.form.supplierId = s.id; this.form.newSupplier = false; },
        allOk() { this.checks.forEach((c) => { this.form.checks[c.key] = true; }); },
        rawError(c) {
            const t = this.form && this.form.temps[c.key];
            return Boolean(t) && String(t.raw).trim() !== "" && parseTemperature(t.raw) === null;
        },
        tempValue(c) {
            const t = this.form && this.form.temps[c.key];
            if (!t) return null;
            const n = parseTemperature(t.raw);
            return n === null ? null : (t.neg ? -n : n);
        },
        tempStatus(c) {
            const v = this.tempValue(c);
            if (v === null || c.max === null || c.max === undefined) return "ok";
            return Math.round(v * 10) / 10 > Number(c.max) ? "alert" : "ok";
        },
        tempCardClass(c) {
            if (this.tempValue(c) === null) return "";
            return this.tempStatus(c) === "alert" ? "is-alert" : "is-done";
        },
        async submit() {
            const f = this.form;
            if (!f || this.saving || !this.canSubmit) return;
            this.saving = true;
            f.error = "";
            const temperatures = {};
            this.categories.forEach((c) => { const v = this.tempValue(c); if (v !== null) temperatures[c.key] = v; });
            try {
                await axios.post("admin/hygiene/receptions", {
                    ...(f.newSupplier ? { supplier_name: f.supplierName.trim() } : { supplier_id: f.supplierId }),
                    delivery_note_no: f.bl.trim() || undefined, delivered_by: f.driver.trim() || undefined,
                    temperatures, checks: { ...f.checks },
                    ...(this.hasDeviation ? { decision: f.decision } : {}),
                    note: f.note.trim() || undefined, attachment_id: f.photo || undefined,
                    operator_user_id: this.operatorId || undefined,
                    client_ref: refFor(f.ref, { s: f.newSupplier ? f.supplierName.trim() : f.supplierId, bl: f.bl, dr: f.driver, t: temperatures, c: f.checks, d: this.hasDeviation ? f.decision : "", n: f.note, p: f.photo, o: this.operatorId }),
                });
                this.form = null;
                await this.load();
                alertService.success("Réception enregistrée.");
            } catch (e) {
                f.error = apiMessage(e, "Enregistrement impossible : vérifiez la connexion puis réessayez (rien n'est doublé).");
            } finally {
                this.saving = false;
            }
        },
        askVoid(r) { this.voidReason = ""; this.voidTarget = r.id; },
        async confirmVoid() {
            if (!this.voidTarget || this.saving) return;
            this.saving = true;
            try {
                await axios.post(`admin/hygiene/receptions/${this.voidTarget}/void`, { reason: this.voidReason.trim() });
                this.voidTarget = null;
                await this.load();
                alertService.success("Réception annulée (elle reste au journal).");
            } catch (e) {
                alertService.error(apiMessage(e, "Annulation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
