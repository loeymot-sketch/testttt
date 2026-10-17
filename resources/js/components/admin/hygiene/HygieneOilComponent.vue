<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Huile de friture — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="oil-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <!-- ── Démarrage : « j'ai 2 friteuses » ───────────────────────────────────────── -->
                <div v-else-if="loaded && !items.length && !manageHasRows" class="hy-card" style="background:#f8fafc;border-style:dashed" data-testid="oil-setup">
                    <p><strong>Aucune friteuse pour l'instant.</strong> Dites combien vous en avez : elles sont créées d'un coup, vous pourrez les renommer ensuite.</p>
                    <div class="hy-measure" style="max-width:320px">
                        <button type="button" class="hy-sign" aria-label="Une friteuse de moins" data-testid="oil-setup-minus" @click="setupCount = Math.max(1, setupCount - 1)">−</button>
                        <strong class="hy-measure-input" style="display:flex;align-items:center;justify-content:center" data-testid="oil-setup-count">{{ setupCount }}</strong>
                        <button type="button" class="hy-sign" aria-label="Une friteuse de plus" data-testid="oil-setup-plus" @click="setupCount = Math.min(12, setupCount + 1)">+</button>
                    </div>
                    <button type="button" class="hy-btn hy-btn--primary" :disabled="saving" data-testid="oil-setup-go" @click="quickSetup">
                        {{ saving ? "Création…" : `Créer ${setupCount} friteuse${setupCount > 1 ? "s" : ""}` }}
                    </button>
                </div>

                <template v-else-if="loaded">
                    <div class="hy-head">
                        <div class="hy-summary" data-testid="oil-summary">
                            <span class="hy-chip is-dark" data-testid="oil-progress">{{ summary.total - summary.todo }}/{{ summary.total }} testées</span>
                            <span class="hy-chip" :class="{ 'is-todo': summary.todo }" data-testid="oil-sum-todo">{{ summary.todo }} à tester</span>
                            <span v-if="summary.warn" class="hy-chip is-todo" data-testid="oil-sum-warn">{{ summary.warn }} à surveiller</span>
                            <span v-if="summary.alert" class="hy-chip is-alert" data-testid="oil-sum-alert">{{ summary.alert }} écart{{ summary.alert > 1 ? "s" : "" }}</span>
                        </div>
                        <HygieneOperatorPicker v-model="operatorId" />
                    </div>

                    <div class="hy-tabs" role="tablist">
                        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="hy-tab" :class="{ 'is-on': tab === t.key }"
                                :aria-selected="tab === t.key ? 'true' : 'false'" :data-testid="`oil-tab-${t.key}`" @click="setTab(t.key)">{{ t.label }}</button>
                    </div>

                    <!-- ── Aujourd'hui ─────────────────────────────────────────────────────────── -->
                    <div v-if="tab === 'today'" class="hy-grid">
                        <div v-for="f in items" :key="f.id" class="hy-card" :class="cardClass(f)" :data-testid="`oil-card-${f.id}`">
                            <div class="hy-card-head">
                                <div>
                                    <strong class="hy-name">{{ f.name }}</strong>
                                    <span class="hy-sub" :data-testid="`oil-change-${f.id}`">{{ changeText(f) }}</span>
                                </div>
                                <span class="hy-tag" :class="{ 'is-late': f.state === 'alert' }" :data-testid="`oil-state-${f.id}`">{{ stateText(f) }}</span>
                            </div>
                            <p v-if="f.last_test" class="hy-sub">
                                Dernier test : <strong>{{ pct(f.last_test.polar_pct) }} %</strong><template v-if="f.last_test.bath_temp_c !== null"> · bain {{ num(f.last_test.bath_temp_c) }} °C</template>
                                · {{ dayHm(f.last_test.performed_at) }}
                            </p>

                            <!-- formulaire ouvert -->
                            <div v-if="forms[f.id]" class="hy-field" style="gap:10px" :data-testid="`oil-form-${f.id}`">
                                <template v-if="forms[f.id].type === 'test'">
                                    <div class="hy-field">
                                        <span class="hy-label">Composés polaires * <span class="hy-sub">(limite légale {{ num(limits.polar_limit) }} %)</span></span>
                                        <HygieneMeasureInput v-model="forms[f.id].polar" label="Composés polaires en pourcentage" :testid="`oil-polar-${f.id}`" unit="%" :signed="false" />
                                    </div>
                                    <div class="hy-field">
                                        <span class="hy-label">Température du bain (facultatif)</span>
                                        <HygieneMeasureInput v-model="forms[f.id].bath" label="Température du bain" :testid="`oil-bath-${f.id}`" :signed="false" />
                                    </div>
                                    <p v-if="readError(f)" class="hy-sub" style="color:#991b1b" role="alert">Valeur illisible : tapez les chiffres (ex. 14 ou 14,5).</p>
                                    <p v-else-if="verdict(f)" class="hy-verdict" :class="`is-${verdict(f).status === 'warn' ? 'ok' : verdict(f).status}`" :data-testid="`oil-verdict-${f.id}`">{{ verdict(f).text }}</p>
                                    <div v-if="verdict(f) && verdict(f).status === 'alert'" class="hy-field" :data-testid="`oil-corrective-${f.id}`">
                                        <span class="hy-label">Qu'avez-vous fait ? (obligatoire)</span>
                                        <div class="hy-segment" role="group" aria-label="Action corrective">
                                            <button v-for="a in actions" :key="a.key" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': forms[f.id].action === a.key }"
                                                    :aria-pressed="forms[f.id].action === a.key ? 'true' : 'false'" :data-testid="`oil-action-${f.id}-${a.key}`" @click="forms[f.id].action = a.key">{{ a.label }}</button>
                                        </div>
                                        <input v-if="needsNote(f)" v-model="forms[f.id].note" class="hy-input" maxlength="200" placeholder="Précisez" aria-label="Précision" :data-testid="`oil-action-note-${f.id}`" />
                                    </div>
                                    <HygienePhotoField v-model="forms[f.id].photo" kind="strip" label="📷 Photo de la mesure (facultatif)" />
                                </template>
                                <template v-else-if="forms[f.id].type === 'change'">
                                    <div class="hy-field">
                                        <span class="hy-label">Volume d'huile remis (facultatif)</span>
                                        <HygieneMeasureInput v-model="forms[f.id].litres" label="Volume en litres" :testid="`oil-litres-${f.id}`" unit="L" :signed="false" />
                                    </div>
                                    <div class="hy-field">
                                        <label class="hy-label" :for="`oil-brand-${f.id}`">Huile utilisée (facultatif)</label>
                                        <input :id="`oil-brand-${f.id}`" v-model="forms[f.id].brand" class="hy-input" maxlength="60" :data-testid="`oil-brand-${f.id}`" />
                                    </div>
                                </template>
                                <template v-else><p class="hy-sub">Enregistrer le filtrage de {{ f.name }} ?</p></template>

                                <p v-if="forms[f.id].error" class="hy-alert" role="alert" :data-testid="`oil-form-error-${f.id}`">{{ forms[f.id].error }}</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <button type="button" class="hy-btn hy-btn--primary" :disabled="saving || !canSubmit(f)" :data-testid="`oil-submit-${f.id}`" @click="submit(f)">{{ saving ? "Enregistrement…" : "Enregistrer" }}</button>
                                    <button type="button" class="hy-btn" :data-testid="`oil-cancel-${f.id}`" @click="closeForm(f)">Annuler</button>
                                </div>
                                <p v-if="blockReason(f)" class="hy-sub" :data-testid="`oil-block-${f.id}`">{{ blockReason(f) }}</p>
                            </div>

                            <div v-else class="hy-segment">
                                <button type="button" class="hy-btn hy-btn--primary" :data-testid="`oil-open-test-${f.id}`" @click="openForm(f, 'test')">Tester l'huile</button>
                                <button type="button" class="hy-btn" :data-testid="`oil-open-filter-${f.id}`" @click="openForm(f, 'filter')">Filtrage</button>
                                <button type="button" class="hy-btn" :data-testid="`oil-open-change-${f.id}`" @click="openForm(f, 'change')">Vidange</button>
                            </div>

                            <ul v-if="f.today.length" class="hy-list">
                                <li v-for="t in f.today" :key="t.id" class="hy-row" :class="{ 'is-alert': t.status === 'alert' && !t.voided, 'is-void': t.voided }">
                                    <span class="hy-row-when">{{ hm(t.performed_at) }}</span>
                                    <span class="hy-row-meta">{{ describe(t) }}<template v-if="t.operator_name"> · {{ t.operator_name }}</template><template v-if="t.voided"> · ANNULÉ : {{ t.void_reason }}</template></span>
                                    <button v-if="!t.voided && voidTarget !== t.id" type="button" class="hy-btn hy-btn--small" :data-testid="`oil-void-${t.id}`" @click="voidTarget = t.id; voidReason = ''">Annuler</button>
                                    <span v-if="voidTarget === t.id" class="hy-field" style="flex:1 1 100%">
                                        <input v-model="voidReason" class="hy-input" maxlength="120" placeholder="Pourquoi annuler ? (obligatoire)" aria-label="Motif d'annulation" :data-testid="`oil-void-reason-${t.id}`" />
                                        <span>
                                            <button type="button" class="hy-btn hy-btn--small hy-btn--danger" :disabled="saving || voidReason.trim().length < 3" :data-testid="`oil-void-confirm-${t.id}`" @click="confirmVoid">Annuler la ligne</button>
                                            <button type="button" class="hy-btn hy-btn--small" @click="voidTarget = null">Garder</button>
                                        </span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- ── Journal ────────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'journal'">
                        <label class="hy-chip hy-chip--btn" style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px">
                            <input v-model="alertsOnly" type="checkbox" data-testid="oil-alerts-only" @change="loadHistory" /> Écarts seulement
                        </label>
                        <p v-if="!history.length" class="hy-muted" data-testid="oil-history-empty">Aucune ligne sur les 30 derniers jours.</p>
                        <ul class="hy-list" data-testid="oil-history">
                            <li v-for="h in history" :key="h.id" class="hy-row" :class="{ 'is-alert': h.status === 'alert' && !h.voided, 'is-void': h.voided }">
                                <span class="hy-row-when">{{ dayHm(h.performed_at) }}</span>
                                <span class="hy-row-name">{{ h.equipment_name }}</span>
                                <span class="hy-row-meta">{{ describe(h) }}<template v-if="h.corrective_label"> — {{ h.corrective_label }}</template><template v-if="h.operator_name"> · {{ h.operator_name }}</template><template v-if="h.voided"> · ANNULÉ : {{ h.void_reason }}</template></span>
                            </li>
                        </ul>
                    </template>

                    <!-- ── Friteuses ──────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'fryers'">
                        <ul class="hy-list">
                            <li v-for="q in fryers" :key="q.id" class="hy-row" :class="{ 'is-void': !q.is_active }" :data-testid="`oil-fryer-${q.id}`">
                                <input v-model="q.name" class="hy-input" style="max-width:260px" maxlength="80" :aria-label="`Nom de ${q.name}`" :data-testid="`oil-fryer-name-${q.id}`" />
                                <button type="button" class="hy-btn hy-btn--small" :data-testid="`oil-fryer-save-${q.id}`" @click="saveFryer(q)">Enregistrer</button>
                                <button type="button" class="hy-btn hy-btn--small" @click="toggleFryer(q)">{{ q.is_active ? "Masquer" : "Réactiver" }}</button>
                            </li>
                        </ul>
                        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                            <input v-model="newFryer" class="hy-input" style="max-width:260px" maxlength="80" placeholder="Nom de la nouvelle friteuse" aria-label="Nouvelle friteuse" data-testid="oil-new-name" />
                            <button type="button" class="hy-btn hy-btn--small hy-btn--primary" :disabled="saving || !newFryer.trim()" data-testid="oil-new-add" @click="addFryer">＋ Ajouter</button>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Huile de friture : tester (composés polaires, bain), filtrer, vidanger, par friteuse. Le verdict
 * affiché n'est qu'un APERÇU des mêmes limites que le serveur (25 % de composés polaires = limite légale) : le serveur décide et
 * exige l'action corrective en cas d'écart. Journal append-only, annulation motivée.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import HygieneOperatorPicker from "./HygieneOperatorPicker";
import HygieneMeasureInput from "./HygieneMeasureInput";
import HygienePhotoField from "./HygienePhotoField";
import { apiMessage, formatHm, formatDayHm, formatDay, newClientRef, refFor, latest } from "../../../helpers/hygieneCommon";
import hygieneRefresh from "../../../helpers/hygieneRefresh";
import { parseTemperature, formatTemperature } from "../../../helpers/hygieneTemperature";

const historySeq = latest();

export default {
    name: "HygieneOilComponent",
    mixins: [hygieneRefresh],
    components: { HygieneOperatorPicker, HygieneMeasureInput, HygienePhotoField },
    data() {
        return {
            items: [], fryers: [], history: [],
            meta: { summary: { total: 0, todo: 0, ok: 0, warn: 0, alert: 0 }, types: [], corrective_actions: [], limits: { polar_limit: 25, polar_warn: 20, bath_max_c: 180 }, server_now: null, timezone: "Europe/Paris" },
            loaded: false, loadError: "", tab: "today", operatorId: null, forms: {}, saving: false, setupCount: 2, newFryer: "",
            alertsOnly: false, voidTarget: null, voidReason: "", manageHasRows: false,
        };
    },
    computed: {
        summary() { return this.meta.summary; },
        limits() { return this.meta.limits; },
        actions() { return this.meta.corrective_actions || []; },
        tabs() { return [{ key: "today", label: "Aujourd'hui" }, { key: "journal", label: "Journal" }, { key: "fryers", label: "Friteuses" }]; },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
    },
    created() { this.load(); },
    methods: {
        hyDraft() { return Object.keys(this.forms).length > 0 || this.voidTarget !== null; },
        hyReload() { return this.load(); },
        hm(iso) { return formatHm(iso, this.meta.timezone); },
        dayHm(iso) { return formatDayHm(iso, this.meta.timezone); },
        num(v) { return formatTemperature(v).replace(/,0$/, ""); },
        pct(v) { return v === null || v === undefined ? "—" : this.num(v); },
        cardClass(f) { return this.forms[f.id] ? "is-open" : ({ ok: "is-done", warn: "is-partial", alert: "is-alert" })[f.state] || ""; },
        stateText(f) { return ({ todo: "À tester", ok: "✓ Conforme", warn: "À surveiller", alert: "⚠ Écart" })[f.state]; },
        changeText(f) {
            if (f.days_since_change === null) return "Aucune vidange enregistrée";
            if (f.days_since_change === 0) return "Huile changée aujourd'hui";
            return `Huile changée il y a ${f.days_since_change} jour${f.days_since_change > 1 ? "s" : ""}`;
        },
        describe(t) {
            if (t.type === "test") return `Test : ${this.pct(t.polar_pct)} % de composés polaires${t.bath_temp_c !== null ? ` · bain ${this.num(t.bath_temp_c)} °C` : ""}`;
            if (t.type === "change") return `Vidange${t.oil_litres !== null ? ` · ${this.num(t.oil_litres)} L` : ""}${t.oil_brand ? ` · ${t.oil_brand}` : ""}`;
            return "Filtrage";
        },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/oil/today");
                this.items = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                if (!this.items.length) {
                    // des friteuses existent peut-être, toutes masquées : pas de « démarrage » dans ce cas
                    const all = await axios.get("admin/hygiene/oil/fryers");
                    this.manageHasRows = (all.data.data || []).length > 0;
                }
                const ids = new Set(this.items.map((i) => i.id));
                this.forms = Object.fromEntries(Object.entries(this.forms).filter(([id]) => ids.has(Number(id))));
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                this.loadError = "Huile indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
        setTab(k) { this.tab = k; if (k === "journal") this.loadHistory(); if (k === "fryers") this.loadFryers(); },
        async quickSetup() {
            if (this.saving) return;
            this.saving = true;
            try {
                await axios.post("admin/hygiene/oil/fryers/quick-setup", { count: this.setupCount });
                await this.load();
            } catch (e) {
                this.loadError = apiMessage(e, "Création impossible. Vérifiez la connexion puis réessayez.");
            } finally {
                this.saving = false;
            }
        },

        // ── formulaires ─────────────────────────────────────────────────────
        openForm(f, type) {
            this.voidTarget = null;
            this.forms = { ...this.forms, [f.id]: { type, polar: { raw: "", neg: false }, bath: { raw: "", neg: false }, litres: { raw: "", neg: false }, brand: "", action: "", note: "", photo: null, error: "", ref: newClientRef("o") } };
        },
        closeForm(f) { const { [f.id]: _x, ...rest } = this.forms; this.forms = rest; },
        polarOf(f) { return parseTemperature(this.forms[f.id].polar.raw, 2); },
        bathOf(f) { return parseTemperature(this.forms[f.id].bath.raw, 3); },
        litresOf(f) { return parseTemperature(this.forms[f.id].litres.raw, 3); },
        readError(f) {
            const x = this.forms[f.id];
            if (x.type === "test") return (String(x.polar.raw).trim() !== "" && this.polarOf(f) === null) || (String(x.bath.raw).trim() !== "" && this.bathOf(f) === null);
            if (x.type === "change") return String(x.litres.raw).trim() !== "" && this.litresOf(f) === null;
            return false;
        },
        verdict(f) {
            const x = this.forms[f.id];
            if (x.type !== "test") return null;
            const p = this.polarOf(f);
            const b = this.bathOf(f);
            const hot = b !== null && Math.round(b * 10) / 10 > Number(this.limits.bath_max_c);
            const over = p !== null && Math.round(p * 10) / 10 > Number(this.limits.polar_limit);
            if (over || hot) return { status: "alert", text: over ? `⚠ Au-dessus de ${this.num(this.limits.polar_limit)} % : l'huile doit être changée` : `⚠ Bain au-dessus de ${this.num(this.limits.bath_max_c)} °C` };
            if (p === null) return null;
            if (p >= Number(this.limits.polar_warn)) return { status: "warn", text: "À surveiller : l'huile approche de la limite" };
            return { status: "ok", text: "✓ Conforme" };
        },
        needsNote(f) {
            const a = this.actions.find((x) => x.key === this.forms[f.id].action);
            return Boolean(a && a.note);
        },
        blockReason(f) {
            const x = this.forms[f.id];
            if (this.readError(f)) return "Une valeur est illisible.";
            if (x.type === "test") {
                if (this.polarOf(f) === null) return "Saisissez le taux de composés polaires mesuré.";
                const v = this.verdict(f);
                if (v && v.status === "alert") {
                    if (!x.action) return "Écart : indiquez ce qui a été fait.";
                    if (this.needsNote(f) && x.note.trim().length < 3) return "Précisez l'action corrective.";
                }
            }
            return "";
        },
        canSubmit(f) { return this.forms[f.id] && this.blockReason(f) === ""; },
        async submit(f) {
            const x = this.forms[f.id];
            if (!x || this.saving || !this.canSubmit(f)) return;
            this.saving = true;
            x.error = "";
            const body = { equipment_id: f.id, type: x.type, operator_user_id: this.operatorId || undefined };
            if (x.type === "test") {
                body.polar_pct = this.polarOf(f);
                if (this.bathOf(f) !== null) body.bath_temp_c = this.bathOf(f);
                if (x.photo) body.attachment_id = x.photo;
                const v = this.verdict(f);
                if (v && v.status === "alert") { body.corrective_action = x.action; if (x.note.trim()) body.corrective_note = x.note.trim(); }
            } else if (x.type === "change") {
                if (this.litresOf(f) !== null) body.oil_litres = this.litresOf(f);
                if (x.brand.trim()) body.oil_brand = x.brand.trim();
            }
            body.client_ref = refFor(x.ref, body); // même saisie = même référence (rejeu sûr) ; saisie corrigée = nouvelle référence
            try {
                await axios.post("admin/hygiene/oil/controls", body);
                this.closeForm(f);
                await this.load();
                alertService.success(x.type === "test" ? "Test enregistré." : (x.type === "change" ? "Vidange enregistrée." : "Filtrage enregistré."));
            } catch (e) {
                x.error = apiMessage(e, "Enregistrement impossible : vérifiez la connexion puis réessayez (rien n'est doublé).");
            } finally {
                this.saving = false;
            }
        },
        async confirmVoid() {
            if (!this.voidTarget || this.saving) return;
            this.saving = true;
            try {
                await axios.post(`admin/hygiene/oil/controls/${this.voidTarget}/void`, { reason: this.voidReason.trim() });
                this.voidTarget = null;
                await this.load();
                alertService.success("Ligne annulée (elle reste au journal).");
            } catch (e) {
                alertService.error(apiMessage(e, "Annulation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },

        // ── journal et friteuses ───────────────────────────────────────────
        async loadHistory() {
            const id = historySeq.next();
            try {
                const { data } = await axios.get("admin/hygiene/oil/history", { params: { alerts_only: this.alertsOnly ? 1 : 0, limit: 200 } });
                if (historySeq.isCurrent(id)) this.history = data.data || [];
            } catch (e) {
                alertService.error(apiMessage(e, "Journal indisponible."));
            }
        },
        async loadFryers() {
            try {
                const { data } = await axios.get("admin/hygiene/oil/fryers");
                this.fryers = data.data || [];
            } catch (e) {
                alertService.error(apiMessage(e, "Friteuses indisponibles."));
            }
        },
        async saveFryer(q) {
            try {
                await axios.put(`admin/hygiene/oil/fryers/${q.id}`, { name: q.name });
                await Promise.all([this.loadFryers(), this.load()]);
                alertService.success(`« ${q.name.trim()} » enregistrée.`);
            } catch (e) {
                alertService.error(apiMessage(e, "Enregistrement impossible."));
            }
        },
        async toggleFryer(q) {
            try {
                await axios.put(`admin/hygiene/oil/fryers/${q.id}`, { is_active: !q.is_active });
                await Promise.all([this.loadFryers(), this.load()]);
            } catch (e) {
                alertService.error(apiMessage(e, "Modification impossible."));
            }
        },
        async addFryer() {
            if (this.saving) return;
            this.saving = true;
            try {
                await axios.post("admin/hygiene/oil/fryers", { name: this.newFryer.trim() });
                this.newFryer = "";
                await Promise.all([this.loadFryers(), this.load()]);
            } catch (e) {
                alertService.error(apiMessage(e, "Ajout impossible."));
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
