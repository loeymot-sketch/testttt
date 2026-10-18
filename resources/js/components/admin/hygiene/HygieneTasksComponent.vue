<template>
    <div class="col-12">
        <HygieneHubBar />
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Nettoyage — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="tsk-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <div class="hy-head">
                        <div class="hy-summary" data-testid="tsk-summary">
                            <span class="hy-chip is-dark" data-testid="tsk-progress">{{ doneCount }}/{{ summary.total }} faites</span>
                            <span class="hy-chip" :class="{ 'is-todo': summary.todo }" data-testid="tsk-sum-todo">{{ summary.todo }} à faire</span>
                            <span v-if="summary.overdue" class="hy-chip is-alert" data-testid="tsk-sum-overdue">{{ summary.overdue }} en retard</span>
                            <span v-if="summary.partial" class="hy-chip is-todo" data-testid="tsk-sum-partial">{{ summary.partial }} incomplète{{ summary.partial > 1 ? "s" : "" }}</span>
                        </div>
                        <HygieneOperatorPicker v-model="operatorId" v-model:token="operatorToken" />
                    </div>

                    <div class="hy-tabs" role="tablist">
                        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="hy-tab" :class="{ 'is-on': tab === t.key }"
                                :aria-selected="tab === t.key ? 'true' : 'false'" :data-testid="`tsk-tab-${t.key}`" @click="setTab(t.key)">
                            {{ t.label }}<small v-if="t.count !== null">{{ t.count }}</small>
                        </button>
                    </div>

                    <!-- ── À faire / Faites ─────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'todo' || tab === 'done'">
                        <p v-if="!items.length" class="hy-muted" data-testid="tsk-empty">
                            Aucune fiche à faire aujourd'hui. Ajoutez-en dans l'onglet « Plan de nettoyage ».
                        </p>
                        <p v-else-if="!shown.length && tab === 'todo' && summary.partial > 0" class="hy-note is-todo" data-testid="tsk-partial-left">
                            Plus de fiche à faire, mais {{ summary.partial }} fiche{{ summary.partial > 1 ? "s" : "" }} incomplète{{ summary.partial > 1 ? "s" : "" }} à reprendre : ouvrez « Faites aujourd'hui ».
                        </p>
                        <p v-else-if="!shown.length" class="hy-note" data-testid="tsk-all-done">
                            {{ tab === "todo" ? "Tout est fait pour aujourd'hui. Bravo." : "Rien n'a encore été validé aujourd'hui." }}
                        </p>

                        <div class="hy-grid">
                            <div v-for="t in shown" :key="t.id" class="hy-card" :class="cardClass(t)" :data-testid="`tsk-card-${t.id}`">
                                <div class="hy-card-head">
                                    <div>
                                        <strong class="hy-name">{{ t.name }}</strong>
                                        <span class="hy-sub">{{ t.zone || "Sans zone" }} · {{ t.checklist.length }} point{{ t.checklist.length > 1 ? "s" : "" }}</span>
                                    </div>
                                    <span v-if="t.due_time" class="hy-tag" :class="{ 'is-late': t.overdue }" :data-testid="`tsk-due-${t.id}`">
                                        {{ t.overdue ? "En retard · " : "Avant " }}{{ t.due_time }}
                                    </span>
                                </div>

                                <!-- déjà validée -->
                                <template v-if="t.completion && !forms[t.id]">
                                    <p class="hy-sub" :data-testid="`tsk-done-${t.id}`">
                                        <strong>{{ t.completion.complete ? "✓ Faite" : `⚠ ${t.completion.items_done}/${t.completion.items_total} points` }}</strong>
                                        à {{ hm(t.completion.completed_at) }}<template v-if="t.completion.operator_name"> par {{ t.completion.operator_name }}</template>
                                        <template v-if="t.completion.late"> · après l'heure</template>
                                    </p>
                                    <ul v-if="!t.completion.complete" class="hy-list">
                                        <li v-for="i in t.completion.items.filter((x) => !x.done)" :key="i.key" class="hy-row is-alert">Non fait : {{ i.label }}</li>
                                    </ul>
                                    <p v-if="t.completion.note" class="hy-sub">« {{ t.completion.note }} »</p>
                                    <div v-if="voidTarget === t.completion.id" class="hy-field">
                                        <label class="hy-label" :for="`tsk-void-reason-${t.id}`">Pourquoi annuler ? (obligatoire)</label>
                                        <input :id="`tsk-void-reason-${t.id}`" v-model="voidReason" class="hy-input" maxlength="120" :data-testid="`tsk-void-reason-${t.id}`" />
                                        <div>
                                            <button type="button" class="hy-btn hy-btn--small hy-btn--danger" :disabled="saving || voidReason.trim().length < 3" :data-testid="`tsk-void-confirm-${t.id}`" @click="confirmVoid">Annuler la validation</button>
                                            <button type="button" class="hy-btn hy-btn--small" @click="voidTarget = null">Garder</button>
                                        </div>
                                    </div>
                                    <div v-else>
                                        <button type="button" class="hy-btn hy-btn--small" :data-testid="`tsk-void-${t.id}`" @click="askVoid(t.completion)">Annuler la validation</button>
                                    </div>
                                </template>

                                <!-- fiche à remplir -->
                                <template v-else>
                                    <button v-if="!forms[t.id]" type="button" class="hy-btn hy-btn--primary" :data-testid="`tsk-open-${t.id}`" @click="openCard(t)">Voir et valider</button>
                                    <div v-else class="hy-field" style="gap:10px">
                                        <details v-if="t.how_to" class="hy-sub" :data-testid="`tsk-how-${t.id}`"><summary class="hy-summary-link">ℹ️ Comment faire</summary>{{ t.how_to }}</details>
                                        <ul class="hy-toggles">
                                            <li v-for="i in t.checklist" :key="i.key">
                                                <button type="button" class="hy-toggle" :aria-pressed="forms[t.id].checked[i.key] ? 'true' : 'false'" :data-testid="`tsk-item-${t.id}-${i.key}`" @click="toggle(t, i.key)">
                                                    <span class="hy-box" aria-hidden="true">{{ forms[t.id].checked[i.key] ? "✓" : "" }}</span>{{ i.label }}
                                                </button>
                                            </li>
                                        </ul>
                                        <button type="button" class="hy-btn hy-btn--small" :data-testid="`tsk-all-${t.id}`" @click="checkAll(t)">Tout cocher</button>
                                        <div v-if="missing(t) > 0" class="hy-field">
                                            <label class="hy-label" :for="`tsk-note-${t.id}`">{{ missing(t) }} point{{ missing(t) > 1 ? "s" : "" }} non fait{{ missing(t) > 1 ? "s" : "" }} : pourquoi ? (obligatoire)</label>
                                            <textarea :id="`tsk-note-${t.id}`" v-model="forms[t.id].note" class="hy-textarea" maxlength="200" :data-testid="`tsk-note-${t.id}`"></textarea>
                                        </div>
                                        <HygienePhotoField v-model="forms[t.id].photo" kind="task" label="📷 Photo (facultatif)" />
                                        <!-- L'engagement : une phrase, une case. Valider n'est plus un simple appui : c'est dire « c'est vrai, et on pourra me le demander ». -->
                                        <button type="button" class="hy-toggle tsk-attest" :aria-pressed="forms[t.id].attest ? 'true' : 'false'" :data-testid="`tsk-attest-${t.id}`" @click="forms[t.id].attest = !forms[t.id].attest">
                                            <span class="hy-box" aria-hidden="true">{{ forms[t.id].attest ? "✓" : "" }}</span>
                                            <span>Je confirme l'exactitude des informations et je comprends qu'un responsable pourra les contrôler.</span>
                                        </button>
                                        <p v-if="forms[t.id].error" class="hy-alert" role="alert" :data-testid="`tsk-form-error-${t.id}`">{{ forms[t.id].error }}</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <button type="button" class="hy-btn hy-btn--primary" :disabled="saving || !canSubmit(t)" :data-testid="`tsk-submit-${t.id}`" @click="submit(t)">
                                                {{ saving ? "Enregistrement…" : (missing(t) ? "Valider avec réserve" : "Valider") }}
                                            </button>
                                            <button type="button" class="hy-btn" :data-testid="`tsk-cancel-${t.id}`" @click="closeCard(t)">Fermer</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- ── Journal ────────────────────────────────────────────────────────────── -->
                    <template v-if="tab === 'journal'">
                        <label class="hy-chip hy-chip--btn" style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px">
                            <input v-model="incompleteOnly" type="checkbox" data-testid="tsk-incomplete-only" @change="loadHistory" /> Incomplètes seulement
                        </label>
                        <p v-if="!history.length" class="hy-muted" data-testid="tsk-history-empty">Aucune validation sur les 30 derniers jours.</p>
                        <ul class="hy-list" data-testid="tsk-history">
                            <li v-for="h in history" :key="h.id" class="hy-row" :class="{ 'is-alert': !h.complete && !h.voided, 'is-void': h.voided }">
                                <span class="hy-row-when">{{ dayHm(h.completed_at) }}</span>
                                <span class="hy-row-name">{{ h.task_name }}</span>
                                <span class="hy-row-meta">
                                    {{ h.items_done }}/{{ h.items_total }} points<template v-if="h.operator_name"> · {{ h.operator_name }}</template>
                                    <template v-if="h.late"> · après l'heure</template><template v-if="h.note"> · « {{ h.note }} »</template>
                                    <template v-if="h.voided"> · ANNULÉE : {{ h.void_reason }}</template>
                                </span>
                            </li>
                        </ul>
                    </template>

                    <!-- ── Plan de nettoyage ──────────────────────────────────────────────────── -->
                    <template v-if="tab === 'plan'">
                        <p v-if="recommended.missing > 0" class="hy-note" data-testid="tsk-reco">
                            {{ recommended.missing }} fiche{{ recommended.missing > 1 ? "s" : "" }} du plan de départ manque{{ recommended.missing > 1 ? "nt" : "" }}.
                            <button type="button" class="hy-btn hy-btn--small" :disabled="saving" data-testid="tsk-install" @click="installRecommended(false)">Ajouter les fiches recommandées</button>
                        </p>
                        <ul class="hy-list">
                            <li v-for="p in plan" :key="p.id" class="hy-row" :class="{ 'is-void': !p.is_active }" :data-testid="`tsk-plan-${p.id}`">
                                <span class="hy-row-name hy-row-name--wide">{{ p.name }}</span>
                                <span class="hy-row-meta">{{ p.zone || "—" }} · {{ frequencyText(p) }}<template v-if="p.due_time"> · avant {{ p.due_time }}</template> · {{ p.checklist.length }} point{{ p.checklist.length > 1 ? "s" : "" }}</span>
                                <button type="button" class="hy-btn hy-btn--small" :data-testid="`tsk-plan-edit-${p.id}`" @click="startEdit(p)">Modifier</button>
                            </li>
                        </ul>
                        <button v-if="!edit" type="button" class="hy-btn hy-btn--primary" style="margin-top:12px" data-testid="tsk-plan-new" @click="startEdit(null)">＋ Nouvelle fiche</button>

                        <div v-if="edit" class="hy-card is-open" style="margin-top:12px" data-testid="tsk-editor">
                            <div class="hy-field"><label class="hy-label" for="tsk-e-name">Nom</label><input id="tsk-e-name" v-model="edit.name" class="hy-input" maxlength="80" data-testid="tsk-e-name" /></div>
                            <div class="hy-field"><label class="hy-label" for="tsk-e-zone">Zone</label><input id="tsk-e-zone" v-model="edit.zone" class="hy-input" maxlength="40" data-testid="tsk-e-zone" /></div>
                            <div class="hy-field">
                                <label class="hy-label" for="tsk-e-freq">Fréquence</label>
                                <select id="tsk-e-freq" v-model="edit.frequency" class="hy-select" data-testid="tsk-e-freq">
                                    <option value="daily">Tous les jours</option><option value="weekly">Une fois par semaine</option><option value="monthly">Une fois par mois</option>
                                </select>
                            </div>
                            <div v-if="edit.frequency === 'weekly'" class="hy-field">
                                <label class="hy-label" for="tsk-e-weekday">Quel jour ?</label>
                                <select id="tsk-e-weekday" v-model.number="edit.weekday" class="hy-select" data-testid="tsk-e-weekday">
                                    <option v-for="(d, i) in WEEKDAYS" :key="d" :value="i + 1">{{ d }}</option>
                                </select>
                            </div>
                            <div v-if="edit.frequency === 'monthly'" class="hy-field">
                                <label class="hy-label" for="tsk-e-mday">Quel jour du mois ? (1 à 28)</label>
                                <input id="tsk-e-mday" v-model.number="edit.month_day" type="number" min="1" max="28" class="hy-input" data-testid="tsk-e-mday" />
                            </div>
                            <div class="hy-field"><label class="hy-label" for="tsk-e-time">À faire avant (facultatif)</label><input id="tsk-e-time" v-model="edit.due_time" type="time" class="hy-input" data-testid="tsk-e-time" /></div>
                            <div class="hy-field"><label class="hy-label" for="tsk-e-how">Comment faire (produit, méthode)</label><textarea id="tsk-e-how" v-model="edit.how_to" class="hy-textarea" maxlength="600" data-testid="tsk-e-how"></textarea></div>
                            <div class="hy-field">
                                <span class="hy-label">Points à vérifier</span>
                                <div v-for="(c, i) in edit.checklist" :key="i" style="display:flex;gap:6px;margin-bottom:6px">
                                    <input v-model="c.label" class="hy-input" maxlength="80" :aria-label="`Point ${i + 1}`" :data-testid="`tsk-e-item-${i}`" />
                                    <button type="button" class="hy-btn hy-btn--small" :aria-label="`Retirer le point ${i + 1}`" :disabled="edit.checklist.length < 2" @click="edit.checklist.splice(i, 1)">✕</button>
                                </div>
                                <button type="button" class="hy-btn hy-btn--small" data-testid="tsk-e-add-item" @click="edit.checklist.push({ key: '', label: '' })">＋ Ajouter un point</button>
                            </div>
                            <label class="hy-chip hy-chip--btn" style="display:inline-flex;gap:8px;align-items:center"><input v-model="edit.is_active" type="checkbox" data-testid="tsk-e-active" /> Fiche active</label>
                            <p v-if="edit.error" class="hy-alert" role="alert" data-testid="tsk-e-error">{{ edit.error }}</p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="button" class="hy-btn hy-btn--primary" :disabled="saving" data-testid="tsk-e-save" @click="saveEdit">Enregistrer</button>
                                <button type="button" class="hy-btn" @click="edit = null">Annuler</button>
                                <button v-if="edit.id" type="button" class="hy-btn hy-btn--danger" :disabled="saving" data-testid="tsk-e-delete" @click="deleteEdit">Supprimer la fiche</button>
                            </div>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Plan de nettoyage (équivalent des « Tâches » de Komia, en mieux : « Tout cocher », horaire,
 * photo, point non fait motivé). Une fiche = une checklist ; on coche, on dit qui, on valide. La validation est un événement
 * du journal (jamais modifiée) ; une erreur s'annule avec un motif.
 */
import HygieneHubBar from "./HygieneHubBar";
import axios from "axios";
import alertService from "../../../services/alertService";
import HygieneOperatorPicker from "./HygieneOperatorPicker";
import HygienePhotoField from "./HygienePhotoField";
import { apiMessage, formatHm, formatDayHm, formatDay, newClientRef, refFor, latest } from "../../../helpers/hygieneCommon";
import hygieneRefresh from "../../../helpers/hygieneRefresh";

const historySeq = latest();

const WEEKDAYS = ["Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

export default {
    name: "HygieneTasksComponent",
    mixins: [hygieneRefresh],
    components: { HygieneHubBar, HygieneOperatorPicker, HygienePhotoField },
    data() {
        return {
            WEEKDAYS,
            items: [],
            meta: { summary: { total: 0, done: 0, partial: 0, todo: 0, overdue: 0 }, recommended: { total: 0, missing: 0, never_configured: false }, server_now: null, timezone: "Europe/Paris" },
            loaded: false,
            loadError: "",
            tab: "todo",
            forms: {},
            operatorId: null, operatorToken: null,
            saving: false,
            voidTarget: null,
            voidReason: "",
            history: [],
            incompleteOnly: false,
            plan: [],
            edit: null,
            autoTried: false,
        };
    },
    computed: {
        summary() { return this.meta.summary; },
        recommended() { return this.meta.recommended; },
        /** « faites » = validées COMPLÈTES, comme sur le hub : une fiche validée avec des points non faits reste à reprendre (jamais comptée faite). */
        doneCount() { return this.summary.done; },
        todo() { return this.items.filter((t) => t.state === "todo"); },
        finished() { return this.items.filter((t) => t.state !== "todo"); },
        shown() { return this.tab === "todo" ? this.todo : this.finished; },
        tabs() {
            return [
                { key: "todo", label: "À faire", count: this.todo.length },
                { key: "done", label: "Faites aujourd'hui", count: this.finished.length },
                { key: "journal", label: "Journal", count: null },
                { key: "plan", label: "Plan de nettoyage", count: null },
            ];
        },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
    },
    created() { this.load(); },
    methods: {
        hyDraft() { return Object.keys(this.forms).length > 0 || this.edit !== null || this.voidTarget !== null; },
        hyReload() { return this.load(); },
        hm(iso) { return formatHm(iso, this.meta.timezone); },
        dayHm(iso) { return formatDayHm(iso, this.meta.timezone); },
        frequencyText(p) {
            if (p.frequency === "weekly") return `Chaque ${WEEKDAYS[(p.weekday || 1) - 1].toLowerCase()}`;
            if (p.frequency === "monthly") return `Le ${p.month_day} de chaque mois`;
            return "Tous les jours";
        },
        cardClass(t) {
            if (this.forms[t.id]) return "is-open";
            if (t.state === "done") return "is-done";
            if (t.state === "partial") return "is-partial";
            return t.overdue ? "is-alert" : "";
        },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/tasks/today");
                this.items = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
                // premier lancement : le plan de départ s'installe seul (jamais après une suppression volontaire)
                if (this.meta.recommended.never_configured && !this.autoTried) {
                    this.autoTried = true;
                    await this.installRecommended(true);
                }
            } catch (e) {
                this.loadError = "Fiches indisponibles. Vérifiez la connexion puis rechargez la page.";
            }
        },
        async installRecommended(auto) {
            this.saving = true;
            try {
                const { data } = await axios.post("admin/hygiene/tasks/templates/install-recommended", auto ? { only_if_empty: true } : {});
                const added = data.data && data.data.added;
                await this.load();
                if (this.tab === "plan") await this.loadPlan();
                if (!auto) alertService.success(added ? `${added} fiche${added > 1 ? "s" : ""} ajoutée${added > 1 ? "s" : ""}.` : "Le plan est déjà complet.");
            } catch (e) {
                if (!auto) alertService.error(apiMessage(e, "Installation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },
        setTab(key) {
            this.tab = key;
            if (key === "journal") this.loadHistory();
            if (key === "plan") this.loadPlan();
        },

        // ── remplir une fiche ───────────────────────────────────────────────
        openCard(t) { this.forms = { ...this.forms, [t.id]: { checked: {}, note: "", photo: null, error: "", attest: false, ref: newClientRef("k") } }; },
        closeCard(t) { const { [t.id]: _x, ...rest } = this.forms; this.forms = rest; },
        toggle(t, key) { const f = this.forms[t.id]; f.checked = { ...f.checked, [key]: !f.checked[key] }; },
        checkAll(t) { this.forms[t.id].checked = Object.fromEntries(t.checklist.map((i) => [i.key, true])); },
        missing(t) { const f = this.forms[t.id]; return f ? t.checklist.filter((i) => !f.checked[i.key]).length : 0; },
        canSubmit(t) {
            const f = this.forms[t.id];
            // l'engagement est coché PAR la personne : sans lui, rien ne se valide
            return Boolean(f) && f.attest && (this.missing(t) === 0 || f.note.trim().length >= 3);
        },
        async submit(t) {
            const f = this.forms[t.id];
            if (!f || this.saving || !this.canSubmit(t)) return;
            this.saving = true;
            f.error = "";
            const all = this.missing(t) === 0;
            try {
                await axios.post("admin/hygiene/tasks/completions", {
                    template_id: t.id,
                    ...(all ? { all: true } : { done: t.checklist.filter((i) => f.checked[i.key]).map((i) => i.key), note: f.note.trim() }),
                    attachment_id: f.photo || undefined,
                    attested: true,
                    operator_user_id: this.operatorId || undefined,
                    operator_token: this.operatorToken || undefined,
                    client_ref: refFor(f.ref, { all, d: t.checklist.filter((i) => f.checked[i.key]).map((i) => i.key), n: f.note.trim(), p: f.photo, o: this.operatorId }),
                });
                this.closeCard(t);
                await this.load();
                // récompense honnête : ce qu'il reste (chiffre du serveur) ou la fin de la journée ; une fiche « avec réserve » n'est jamais fêtée
                const left = Number(this.summary.todo) || 0;
                const rest = left > 0 ? ` Plus que ${left} fiche${left > 1 ? "s" : ""} à faire.` : (this.summary.partial ? "" : " Toutes les fiches du jour sont faites, bravo !");
                alertService.success(all ? `« ${t.name} » validée ✓${rest}` : `« ${t.name} » validée avec réserve (points non faits tracés).${rest}`, "top-right", { timeout: 6000 });
            } catch (e) {
                f.error = apiMessage(e, "Validation impossible : vérifiez la connexion puis revalidez (rien n'est doublé).");
            } finally {
                this.saving = false;
            }
        },
        askVoid(c) { this.voidReason = ""; this.voidTarget = c.id; },
        async confirmVoid() {
            if (!this.voidTarget || this.saving) return;
            this.saving = true;
            try {
                await axios.post(`admin/hygiene/tasks/completions/${this.voidTarget}/void`, { reason: this.voidReason.trim(), operator_user_id: this.operatorId || undefined, operator_token: this.operatorToken || undefined });
                this.voidTarget = null;
                await this.load();
                alertService.success("Validation annulée (elle reste au journal).");
            } catch (e) {
                alertService.error(apiMessage(e, "Annulation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },

        // ── journal ─────────────────────────────────────────────────────────
        async loadHistory() {
            const id = historySeq.next();
            try {
                const { data } = await axios.get("admin/hygiene/tasks/history", { params: { incomplete_only: this.incompleteOnly ? 1 : 0, limit: 200 } });
                if (historySeq.isCurrent(id)) this.history = data.data || [];
            } catch (e) {
                alertService.error(apiMessage(e, "Journal indisponible."));
            }
        },

        // ── plan de nettoyage ───────────────────────────────────────────────
        async loadPlan() {
            try {
                const { data } = await axios.get("admin/hygiene/tasks/templates");
                this.plan = data.data || [];
                this.meta = { ...this.meta, recommended: (data.meta && data.meta.recommended) || this.meta.recommended };
            } catch (e) {
                alertService.error(apiMessage(e, "Plan indisponible."));
            }
        },
        startEdit(p) {
            this.edit = p
                ? { id: p.id, name: p.name, zone: p.zone || "", how_to: p.how_to || "", frequency: p.frequency, weekday: p.weekday || 1, month_day: p.month_day || 1,
                    due_time: p.due_time || "", is_active: p.is_active, checklist: p.checklist.map((c) => ({ ...c })), error: "" }
                : { id: null, name: "", zone: "", how_to: "", frequency: "daily", weekday: 1, month_day: 1, due_time: "", is_active: true, checklist: [{ key: "", label: "" }], error: "" };
        },
        async saveEdit() {
            const e = this.edit;
            if (!e || this.saving) return;
            const checklist = e.checklist.map((c) => ({ key: c.key || undefined, label: c.label.trim() })).filter((c) => c.label !== "");
            const body = {
                name: e.name.trim(), zone: e.zone.trim() || null, how_to: e.how_to.trim() || null, frequency: e.frequency,
                weekday: e.frequency === "weekly" ? e.weekday : null, month_day: e.frequency === "monthly" ? e.month_day : null,
                due_time: e.due_time || null, is_active: e.is_active, checklist,
            };
            this.saving = true;
            e.error = "";
            try {
                if (e.id) await axios.put(`admin/hygiene/tasks/templates/${e.id}`, body);
                else await axios.post("admin/hygiene/tasks/templates", body);
                this.edit = null;
                await Promise.all([this.loadPlan(), this.load()]);
                alertService.success("Fiche enregistrée.");
            } catch (err) {
                e.error = apiMessage(err, "Enregistrement impossible : réessayez.");
            } finally {
                this.saving = false;
            }
        },
        async deleteEdit() {
            const e = this.edit;
            if (!e || !e.id || this.saving) return;
            this.saving = true;
            try {
                await axios.delete(`admin/hygiene/tasks/templates/${e.id}`);
                this.edit = null;
                await Promise.all([this.loadPlan(), this.load()]);
                alertService.success("Fiche supprimée (son historique reste au journal).");
            } catch (err) {
                e.error = apiMessage(err, "Suppression impossible : réessayez.");
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
