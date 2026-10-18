<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Températures — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <!-- ── Hors ligne : ce qui est gardé sur la tablette, et ce que le serveur a refusé ───────────── -->
                <div v-if="queuedPending.length" class="tmp-offline" role="status" data-testid="tmp-offline">
                    <strong>⏳ {{ queuedReadingsCount }} relevé{{ queuedReadingsCount > 1 ? "s" : "" }} gardé{{ queuedReadingsCount > 1 ? "s" : "" }} sur cette tablette, en attente de réseau.</strong>
                    Ils partent tout seuls dès que la connexion revient (même si vous rechargez la page) et gardent l'heure de la mesure.
                    <button type="button" class="tmp-btn tmp-btn--small" :disabled="flushing" data-testid="tmp-offline-retry" @click="flushQueue">{{ flushing ? "Envoi…" : "Réessayer maintenant" }}</button>
                </div>
                <div v-for="r in queuedRejected" :key="r.qid" class="tmp-alert tmp-refused" role="alert" data-testid="tmp-refused">
                    <strong>Relevés refusés par le serveur ({{ r.label }}) :</strong> {{ r.reason }} <em>Ressaisissez-les : rien n'a été enregistré.</em>
                    <button type="button" class="tmp-btn tmp-btn--small" data-testid="tmp-refused-ok" @click="dismissRefused(r.qid)">J'ai compris</button>
                </div>

                <p v-if="loadError" class="tmp-alert" role="alert" data-testid="tmp-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="tmp-muted" role="status">Chargement…</p>


                <!-- ── Démarrage : « j'ai 4 frigos et 2 congélateurs » ───────────────────────────── -->
                <div v-else-if="loaded && !items.length && !manageHasRows" class="tmp-setup" data-testid="tmp-setup">
                    <p>
                        <strong>Aucun équipement pour l'instant.</strong> Dites combien vous en avez : les équipements sont créés avec leurs seuils
                        (frigo 0 à +4 °C, congélateur −18 °C ou moins, bain-marie 63 °C ou plus). Vous pourrez les renommer ensuite.
                    </p>
                    <div class="tmp-steppers">
                        <div v-for="k in setupKinds" :key="k.key" class="tmp-stepper">
                            <span class="tmp-stepper-label">{{ k.plural }}</span>
                            <button type="button" class="tmp-step" :aria-label="`Un ${k.label.toLowerCase()} de moins`" :data-testid="`tmp-setup-${k.key}-minus`"
                                    @click="setup[k.key] = Math.max(0, setup[k.key] - 1)">−</button>
                            <strong class="tmp-stepper-n" :data-testid="`tmp-setup-${k.key}`">{{ setup[k.key] }}</strong>
                            <button type="button" class="tmp-step" :aria-label="`Un ${k.label.toLowerCase()} de plus`" :data-testid="`tmp-setup-${k.key}-plus`"
                                    @click="setup[k.key] = Math.min(20, setup[k.key] + 1)">+</button>
                        </div>
                    </div>
                    <button type="button" class="tmp-btn tmp-btn--primary" :disabled="saving || !setupTotal" data-testid="tmp-setup-go" @click="quickSetup">
                        {{ saving ? "Création…" : `Créer mes ${setupTotal} équipement${setupTotal > 1 ? "s" : ""}` }}
                    </button>
                </div>

                <template v-else-if="loaded">
                    <p v-if="!items.length" class="tmp-muted" data-testid="tmp-all-hidden">Tous les équipements sont masqués : réactivez-en un dans « Équipements » ci-dessous.</p>
                    <!-- ── Bandeau du jour ─────────────────────────────────────────────────────────── -->
                    <div class="tmp-summary" data-testid="tmp-summary">
                        <span class="tmp-period" data-testid="tmp-period">{{ periodLabel }}</span>
                        <span class="tmp-chip" :class="{ 'is-todo': summary.todo }" data-testid="tmp-sum-todo">{{ summary.todo }} à relever</span>
                        <span class="tmp-chip is-ok" data-testid="tmp-sum-ok">{{ summary.ok }} conforme{{ summary.ok > 1 ? "s" : "" }}</span>
                        <span class="tmp-chip" :class="{ 'is-alert': summary.alert }" data-testid="tmp-sum-alert">{{ summary.alert }} écart{{ summary.alert > 1 ? "s" : "" }}</span>
                    </div>
                    <div class="tmp-who">
                        <HygieneOperatorPicker v-model="operatorId" v-model:token="operatorToken" />
                    </div>

                    <!-- ── Un équipement = une carte ───────────────────────────────────────────────── -->
                    <div class="tmp-grid">
                        <div v-for="e in items" :key="e.id" class="tmp-card" :class="cardClass(e)" :data-testid="`tmp-card-${e.id}`">
                            <div class="tmp-card-head">
                                <div>
                                    <strong class="tmp-name">{{ e.name }}</strong>
                                    <span class="tmp-kind">{{ e.kind_label }}<template v-if="e.location"> · {{ e.location }}</template></span>
                                </div>
                                <span class="tmp-limit" :class="{ 'is-neg': e.negative }">{{ limitText(e) }}</span>
                            </div>

                            <!-- relevé déjà fait pour ce moment de la journée -->
                            <div v-if="e.last_today && !entries[e.id]" class="tmp-done" :class="{ 'is-alert': e.last_today.status === 'alert' }" :data-testid="`tmp-done-${e.id}`">
                                <span class="tmp-done-value">{{ e.last_today.status === "alert" ? "⚠" : "✓" }} {{ fmt(e.last_today.value_c) }} °C</span>
                                <span class="tmp-done-meta">
                                    {{ hm(e.last_today.recorded_at) }} · {{ periodName(e.last_today.period) }}<template v-if="e.last_today.operator_name"> · {{ e.last_today.operator_name }}</template>
                                </span>
                                <span v-if="e.last_today.status === 'alert'" class="tmp-done-alert">
                                    {{ alertText(e.last_today) }}<template v-if="e.last_today.corrective_label"> — {{ e.last_today.corrective_label }}</template>
                                </span>
                                <div class="tmp-done-actions">
                                    <button type="button" class="tmp-btn tmp-btn--small" :data-testid="`tmp-again-${e.id}`" @click="startEntry(e)">Nouveau relevé</button>
                                    <button type="button" class="tmp-btn tmp-btn--small" :data-testid="`tmp-void-${e.id}`" @click="askVoid(e.last_today)">Annuler ce relevé</button>
                                </div>
                            </div>

                            <!-- saisie -->
                            <template v-else>
                                <div v-if="queuedFor(e) && !entries[e.id]" class="tmp-queued" :data-testid="`tmp-queued-${e.id}`">
                                    <span class="tmp-done-value">⏳ {{ fmt(queuedFor(e).value) }} °C</span>
                                    <span class="tmp-done-meta">{{ hm(queuedFor(e).at) }} · gardé sur la tablette, en attente d'envoi</span>
                                </div>
                                <div v-else-if="entries[e.id]" class="tmp-entry">
                                    <div class="tmp-input-row">
                                        <button type="button" class="tmp-sign" :aria-pressed="entries[e.id].neg ? 'true' : 'false'"
                                                :aria-label="entries[e.id].neg ? 'Température négative' : 'Température positive'" :data-testid="`tmp-sign-${e.id}`"
                                                @click="entries[e.id].neg = !entries[e.id].neg">{{ entries[e.id].neg ? "−" : "+" }}</button>
                                        <input v-model="entries[e.id].raw" type="text" inputmode="decimal" autocomplete="off" class="tmp-input" placeholder="0,0"
                                               :aria-label="`Température de ${e.name}`" :data-testid="`tmp-input-${e.id}`" @focus="$event.target.select()" />
                                        <span class="tmp-unit">°C</span>
                                    </div>
                                    <div class="tmp-quick">
                                        <button v-if="e.previous" type="button" class="tmp-chip tmp-chip--btn" :data-testid="`tmp-same-${e.id}`" @click="sameAsPrevious(e)">
                                            = {{ fmt(e.previous.value_c) }} °C ({{ previousWhen(e.previous.recorded_at) }})
                                        </button>
                                        <button type="button" class="tmp-chip tmp-chip--btn" :data-testid="`tmp-cancel-${e.id}`" @click="stopEntry(e)">Annuler</button>
                                    </div>
                                    <p v-if="rawError(e)" class="tmp-hint is-alert" role="status" :data-testid="`tmp-raw-error-${e.id}`">{{ rawError(e) }}</p>
                                    <template v-else-if="valueOf(e) !== null">
                                        <p class="tmp-verdict" :class="verdictOf(e).status === 'alert' ? 'is-alert' : 'is-ok'" :data-testid="`tmp-verdict-${e.id}`">
                                            {{ verdictOf(e).status === "alert" ? `⚠ Hors seuil : ${fmt(valueOf(e))} °C (${reasonText(verdictOf(e).reason, e.hot)})` : `✓ Conforme : ${fmt(valueOf(e))} °C` }}
                                        </p>
                                        <!-- écart : ce qui a été fait est OBLIGATOIRE -->
                                        <div v-if="verdictOf(e).status === 'alert'" class="tmp-corrective" :data-testid="`tmp-corrective-${e.id}`">
                                            <span class="tmp-label">Qu'avez-vous fait ? (obligatoire)</span>
                                            <div class="tmp-actions">
                                                <button v-for="a in actionsFor(e)" :key="a.key" type="button" class="tmp-chip tmp-chip--btn"
                                                        :class="{ 'is-on': entries[e.id].action === a.key }" :aria-pressed="entries[e.id].action === a.key ? 'true' : 'false'"
                                                        :data-testid="`tmp-action-${e.id}-${a.key}`" @click="entries[e.id].action = a.key">{{ a.label }}</button>
                                            </div>
                                            <input v-if="needsNote(e)" v-model="entries[e.id].note" type="text" maxlength="200" class="tmp-note" placeholder="Précisez…"
                                                   :aria-label="`Précision pour ${e.name}`" :data-testid="`tmp-note-${e.id}`" />
                                        </div>
                                    </template>
                                </div>
                                <button v-else type="button" class="tmp-take" :data-testid="`tmp-take-${e.id}`" @click="startEntry(e)">
                                    <span>Saisir la température</span>
                                    <small v-if="e.previous">{{ previousWhen(e.previous.recorded_at) }} {{ fmt(e.previous.value_c) }} °C</small>
                                </button>
                            </template>

                            <!-- courbe des 14 derniers jours -->
                            <button type="button" class="tmp-link" :aria-expanded="charts[e.id] && charts[e.id].open ? 'true' : 'false'" :data-testid="`tmp-chart-toggle-${e.id}`" @click="toggleChart(e)">
                                {{ charts[e.id] && charts[e.id].open ? "▾" : "▸" }} Courbe 14 jours
                            </button>
                            <p v-if="charts[e.id] && charts[e.id].open && charts[e.id].loading" class="tmp-muted" role="status">Chargement de la courbe…</p>
                            <HygieneTempChart v-else-if="charts[e.id] && charts[e.id].open" :readings="charts[e.id].readings" :min="e.min_c" :max="e.max_c" :days="14" />

                            <!-- annulation d'un relevé : motif obligatoire, la ligne reste au journal -->
                            <div v-if="voidTarget && voidTarget.equipment_id === e.id" class="tmp-void" :data-testid="`tmp-void-box-${e.id}`">
                                <label class="tmp-label" :for="`tmp-void-reason-${e.id}`">Motif de l'annulation (le relevé reste au journal)</label>
                                <input :id="`tmp-void-reason-${e.id}`" v-model="voidReason" type="text" maxlength="120" class="tmp-note" placeholder="Ex. faute de frappe"
                                       :data-testid="`tmp-void-reason-${e.id}`" />
                                <div class="tmp-done-actions">
                                    <button type="button" class="tmp-btn tmp-btn--small tmp-btn--danger" :disabled="saving || voidReason.trim().length < 3"
                                            :data-testid="`tmp-void-confirm-${e.id}`" @click="confirmVoid">Annuler le relevé</button>
                                    <button type="button" class="tmp-btn tmp-btn--small" @click="voidTarget = null">Garder</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Barre de validation ───────────────────────────────────────────────────── -->
                    <div v-if="items.length" class="tmp-bar" data-testid="tmp-bar">
                        <p v-if="formError" class="tmp-alert" role="alert" style="flex:1 1 100%;margin:0" data-testid="tmp-form-error">{{ formError }}</p>
                        <span class="tmp-bar-text">
                            <strong data-testid="tmp-pending">{{ pendingList.length }}</strong> relevé{{ pendingList.length > 1 ? "s" : "" }} saisi{{ pendingList.length > 1 ? "s" : "" }}
                            <template v-if="missingActions"> — <span class="tmp-bar-warn">{{ missingActions }} écart{{ missingActions > 1 ? "s" : "" }} sans action</span></template>
                        </span>
                        <button type="button" class="tmp-btn" :disabled="saving || !todoWithoutEntry" data-testid="tmp-fill-all" @click="startAll">
                            Saisir tout ce qui reste ({{ todoWithoutEntry }})
                        </button>
                        <button type="button" class="tmp-btn tmp-btn--primary" :disabled="saving || !canSubmit" data-testid="tmp-submit" @click="submit">
                            {{ saving ? "Enregistrement…" : "Valider les relevés" }}
                        </button>
                    </div>
                </template>

                <!-- ── Journal ───────────────────────────────────────────────────────────────────── -->
                <div v-if="loaded && (items.length || manageHasRows)" class="tmp-section">
                    <button type="button" class="tmp-toggle" :aria-expanded="showHistory ? 'true' : 'false'" data-testid="tmp-history-toggle" @click="toggleHistory">
                        {{ showHistory ? "▾" : "▸" }} Journal des relevés
                    </button>
                    <div v-if="showHistory" data-testid="tmp-history">
                        <label class="tmp-check"><input v-model="alertsOnly" type="checkbox" data-testid="tmp-alerts-only" @change="loadHistory" /> Écarts seulement</label>
                        <p v-if="!history.length" class="tmp-muted">Aucun relevé sur la période.</p>
                        <ul v-else class="tmp-list">
                            <li v-for="r in history" :key="r.id" class="tmp-row" :class="{ 'is-void': r.voided, 'is-alert': r.status === 'alert' && !r.voided }" :data-testid="`tmp-row-${r.id}`">
                                <span class="tmp-row-when">{{ dayHm(r.recorded_at) }}</span>
                                <span class="tmp-row-name">{{ r.equipment_name }}</span>
                                <strong class="tmp-row-value">{{ fmt(r.value_c) }} °C</strong>
                                <span class="tmp-row-meta">
                                    {{ r.voided ? `Annulé : ${r.void_reason}` : (r.status === "alert" ? `${alertText(r)}${r.corrective_label ? " — " + r.corrective_label : ""}${r.corrective_note ? " (" + r.corrective_note + ")" : ""}` : "Conforme") }}
                                    · {{ periodName(r.period) }}<template v-if="r.operator_name"> · {{ r.operator_name }}</template><template v-if="r.offline"> · saisi hors ligne, envoyé à {{ hm(r.synced_at) }}</template>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ── Équipements ───────────────────────────────────────────────────────────────── -->
                <div v-if="loaded && (items.length || manageHasRows)" class="tmp-section">
                    <button type="button" class="tmp-toggle" :aria-expanded="manage ? 'true' : 'false'" data-testid="tmp-manage-toggle" @click="toggleManage">
                        {{ manage ? "▾" : "▸" }} Équipements ({{ items.length }})
                    </button>
                    <div v-if="manage" data-testid="tmp-manage">
                        <ul class="tmp-list">
                            <li v-for="q in equipment" :key="q.id" class="tmp-row tmp-row--edit" :class="{ 'is-void': !q.is_active }" :data-testid="`tmp-eq-${q.id}`">
                                <input v-model="q.name" type="text" maxlength="80" class="tmp-note tmp-eq-name" :aria-label="`Nom de ${q.name}`" :data-testid="`tmp-eq-name-${q.id}`" />
                                <label class="tmp-eq-th">de <input v-model="q.min_c" type="text" inputmode="decimal" class="tmp-eq-num" placeholder="—" :aria-label="`Seuil bas de ${q.name}`" :data-testid="`tmp-eq-min-${q.id}`" /> °C</label>
                                <label class="tmp-eq-th">à <input v-model="q.max_c" type="text" inputmode="decimal" class="tmp-eq-num" placeholder="—" :aria-label="`Seuil haut de ${q.name}`" :data-testid="`tmp-eq-max-${q.id}`" /> °C</label>
                                <button type="button" class="tmp-btn tmp-btn--small" :data-testid="`tmp-eq-save-${q.id}`" @click="saveEquipment(q)">Enregistrer</button>
                                <button type="button" class="tmp-btn tmp-btn--small" @click="toggleEquipment(q)">{{ q.is_active ? "Masquer" : "Réactiver" }}</button>
                            </li>
                        </ul>
                        <div class="tmp-add" data-testid="tmp-add">
                            <input v-model="newEq.name" type="text" maxlength="80" class="tmp-note" placeholder="Nom (ex. Frigo boissons)" aria-label="Nom du nouvel équipement" data-testid="tmp-new-name" />
                            <select v-model="newEq.kind" class="tmp-select" aria-label="Type" data-testid="tmp-new-kind">
                                <option v-for="k in kinds" :key="k.key" :value="k.key">{{ k.label }}</option>
                            </select>
                            <button type="button" class="tmp-btn tmp-btn--small tmp-btn--primary" :disabled="saving || !newEq.name.trim()" data-testid="tmp-new-add" @click="addEquipment">＋ Ajouter</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Relevés de température : « j'ai 4 frigos positifs et 2 congélateurs négatifs. Chaque jour
 * je dis 3° pour un frigo, −18° pour un congélateur, je valide, c'est enregistré. »
 *
 * Le verdict affiché pendant la saisie n'est qu'un APERÇU (mêmes seuils que le serveur) : c'est le serveur qui décide, enregistre
 * et refuse un écart sans action corrective. Un relevé n'est jamais modifié : une faute de frappe s'annule avec un motif.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import { parseTemperature, previewVerdict, formatTemperature, limitText } from "../../../helpers/hygieneTemperature";
import { refFor, latest } from "../../../helpers/hygieneCommon";
import hygieneRefresh from "../../../helpers/hygieneRefresh";
import HygieneTempChart from "./HygieneTempChart";
import HygieneOperatorPicker from "./HygieneOperatorPicker";
import { hygieneQueue, isRetriable } from "../../../helpers/hygieneOfflineQueue";

const historySeq = latest();

function clientRef() {
    return `t${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export default {
    name: "HygieneTemperatureComponent",
    components: { HygieneTempChart, HygieneOperatorPicker },
    mixins: [hygieneRefresh],
    data() {
        return {
            operatorId: null, operatorToken: null,
            queued: [],
            flushing: false,
            onlineHandler: null,
            queueTimer: null,
            items: [],
            equipment: [],
            meta: { period: "morning", periods: {}, summary: { total: 0, todo: 0, ok: 0, alert: 0 }, kinds: [], corrective_actions: [], server_now: null },
            entries: {},
            loaded: false,
            loadError: "",
            formError: "",
            saving: false,
            setup: { fridge: 4, freezer: 2, cold_room: 0, display: 0, hot_holding: 0 },
            charts: {},
            history: [],
            showHistory: false,
            alertsOnly: false,
            manage: false,
            newEq: { name: "", kind: "fridge" },
            voidTarget: null,
            voidReason: "",
            manageHasRows: false,
            serverOffsetMs: 0,
            nowMs: Date.now(),
            tick: null,
        };
    },
    computed: {
        summary() { return this.meta.summary || { todo: 0, ok: 0, alert: 0, total: 0 }; },
        kinds() { return this.meta.kinds || []; },
        actions() { return this.meta.corrective_actions || []; },
        setupKinds() {
            return [
                { key: "fridge", label: "Réfrigérateur", plural: "Réfrigérateurs" }, { key: "freezer", label: "Congélateur", plural: "Congélateurs" },
                { key: "cold_room", label: "Chambre froide", plural: "Chambres froides" }, { key: "display", label: "Vitrine", plural: "Vitrines" },
                { key: "hot_holding", label: "Bain-marie", plural: "Bains-marie (chaud)" },
            ];
        },
        setupTotal() { return Object.values(this.setup).reduce((a, b) => a + b, 0); },
        periodLabel() { return (this.meta.periods && this.meta.periods[this.meta.period]) || ""; },
        todayLabel() {
            try {
                return new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(this.nowMs + this.serverOffsetMs));
            } catch (_) { return ""; }
        },
        /** Les saisies prêtes à partir : une valeur lisible. */
        pendingList() {
            return this.items.filter((e) => this.entries[e.id] && this.valueOf(e) !== null);
        },
        missingActions() {
            return this.pendingList.filter((e) => !this.actionOk(e)).length;
        },
        canSubmit() {
            return this.pendingList.length > 0 && this.missingActions === 0;
        },
        queuedPending() { return this.queued.filter((i) => i.state === "pending"); },
        queuedRejected() { return this.queued.filter((i) => i.state === "rejected"); },
        queuedReadingsCount() { return this.queuedPending.reduce((n, i) => n + ((i.body && i.body.readings) || []).length, 0); },
        /** equipment_id → le dernier relevé gardé sur la tablette (valeur, heure de la mesure) : la carte le dit, pour qu'on ne le ressaisisse pas. */
        queuedByEquipment() {
            const map = {};
            this.queuedPending.forEach((i) => ((i.body && i.body.readings) || []).forEach((r) => { map[r.equipment_id] = { value: r.value, at: r.measured_at }; }));
            return map;
        },
        todoWithoutEntry() {
            return this.items.filter((e) => !this.entries[e.id] && !e.done_this_period).length;
        },
    },
    created() {
        this.loadToday();
        this.tick = setInterval(() => { this.nowMs = Date.now(); }, 60000);
        // hors ligne : ce qui est gardé sur la tablette repart dès que le réseau revient (événement « online », puis toutes les 30 s)
        this.refreshQueue();
        this.flushQueue();
        this.onlineHandler = () => this.flushQueue();
        if (typeof window !== "undefined") window.addEventListener("online", this.onlineHandler);
        this.queueTimer = setInterval(() => { if (this.queuedPending.length) this.flushQueue(); }, 30000);
    },
    beforeUnmount() {
        if (this.tick) clearInterval(this.tick);
        if (this.queueTimer) clearInterval(this.queueTimer);
        if (typeof window !== "undefined" && this.onlineHandler) window.removeEventListener("online", this.onlineHandler);
    },
    methods: {
        queue() { return hygieneQueue(axios); },
        refreshQueue() { this.queued = this.queue().items().filter((i) => i.kind === "temperature"); },
        queuedFor(e) { return this.queuedByEquipment[e.id] || null; },
        dismissRefused(qid) { this.queue().dismiss(qid); this.refreshQueue(); },
        async flushQueue() {
            if (this.flushing) return;
            this.refreshQueue();
            if (!this.queuedPending.length) return;
            this.flushing = true;
            try {
                const r = await this.queue().flush();
                this.refreshQueue();
                if (r.sent > 0) {
                    await this.loadToday();
                    await this.reloadCharts();
                    if (this.showHistory) await this.loadHistory();
                    alertService.success(`${r.sent} envoi${r.sent > 1 ? "s" : ""} gardé${r.sent > 1 ? "s" : ""} hors ligne ${r.sent > 1 ? "ont" : "a"} été enregistré${r.sent > 1 ? "s" : ""}.`);
                }
            } finally {
                this.flushing = false;
            }
        },
        hyDraft() { return Object.keys(this.entries).length > 0 || this.voidTarget !== null; },
        hyReload() { return this.loadToday(); },
        fmt(v) { return formatTemperature(v); },
        limitText(e) { return limitText(e.min_c, e.max_c); },
        hm(iso) {
            try { return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(iso)); } catch (_) { return ""; }
        },
        dayHm(iso) {
            try { return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(iso)); } catch (_) { return ""; }
        },
        previousWhen(iso) {
            const d = new Date(iso);
            const now = new Date(this.nowMs + this.serverOffsetMs);
            const yesterday = new Date(now.getTime() - 86400000);
            const same = (a, b) => a.toDateString() === b.toDateString();
            if (same(d, yesterday)) return "hier";
            return this.dayHm(iso).split(" ")[0];
        },
        periodName(p) { return (this.meta.periods && this.meta.periods[p]) || ""; },
        alertText(r) {
            const hot = this.kinds.some((k) => k.key === r.equipment_kind && k.hot);
            const text = this.reasonText(r.alert_reason, hot);

            return text.charAt(0).toUpperCase() + text.slice(1);
        },
        /** Un équipement chaud sort du seuil en refroidissant (« pas assez chaud ») ; un froid, en se réchauffant (« trop chaud »). */
        reasonText(reason, hot) { return reason === "too_cold" ? (hot ? "pas assez chaud" : "trop froid") : "trop chaud"; },
        /** Les actions correctives qui ont un sens pour CET équipement (pas de « dégivrage » pour un bain-marie). */
        actionsFor(e) { return this.actions.filter((a) => !a.for || a.for === (e.hot ? "hot" : "cold")); },
        todayYmd(minusDays = 0) {
            const d = new Date(this.nowMs + this.serverOffsetMs - minusDays * 86400000);
            try { return new Intl.DateTimeFormat("fr-CA", { timeZone: this.meta.timezone || "Europe/Paris" }).format(d); } catch (_) { return d.toISOString().slice(0, 10); }
        },
        async loadChart(e) {
            try {
                const { data } = await axios.get("admin/hygiene/temperature/history", { params: { equipment_id: e.id, from: this.todayYmd(13), limit: 500 } });
                // l'état d'ouverture est celui d'AUJOURD'HUI (fermée pendant le chargement : elle reste fermée), pas celui du départ
                const cur = this.charts[e.id];
                this.charts = { ...this.charts, [e.id]: { open: cur ? cur.open : true, loading: false, readings: data.data || [] } };
            } catch (err) {
                this.charts = { ...this.charts, [e.id]: { open: false, loading: false, readings: [] } };
                alertService.error(this.apiMessage(err, "Courbe indisponible : réessayez."));
            }
        },
        async toggleChart(e) {
            const cur = this.charts[e.id];
            if (cur && cur.open) {
                this.charts = { ...this.charts, [e.id]: { ...cur, open: false } };
                return;
            }
            this.charts = { ...this.charts, [e.id]: { open: true, loading: true, readings: cur ? cur.readings : [] } };
            await this.loadChart(e);
        },
        reloadCharts() {
            return Promise.all(this.items.filter((e) => this.charts[e.id] && this.charts[e.id].open).map((e) => this.loadChart(e)));
        },
        apiMessage(e, fallback) {
            const data = e && e.response && e.response.data;
            if (data && data.errors) {
                const first = Object.values(data.errors)[0];
                if (Array.isArray(first) && first[0]) return first[0];
            }
            return (data && data.message) || fallback;
        },

        cardClass(e) {
            const entry = this.entries[e.id];
            if (entry && this.valueOf(e) !== null) return this.verdictOf(e).status === "alert" ? "is-alert" : "is-ready";
            if (e.last_today && !entry) return e.last_today.status === "alert" ? "is-alert" : "is-done";
            return "";
        },

        // ── saisie ────────────────────────────────────────────────────────────
        startEntry(e) {
            this.voidTarget = null;
            this.entries = { ...this.entries, [e.id]: { raw: "", neg: Boolean(e.negative), action: "", note: "", ref: clientRef() } };
            this.$nextTick(() => {
                const el = this.$el && this.$el.querySelector(`[data-testid="tmp-input-${e.id}"]`);
                if (el && el.focus) el.focus();
            });
        },
        startAll() {
            const next = { ...this.entries };
            this.items.forEach((e) => {
                if (!next[e.id] && !e.done_this_period) next[e.id] = { raw: "", neg: Boolean(e.negative), action: "", note: "", ref: clientRef() };
            });
            this.entries = next;
        },
        stopEntry(e) {
            const { [e.id]: _gone, ...rest } = this.entries;
            this.entries = rest;
        },
        /** « = hier » : un appui, jamais pré-rempli (valider sans mesurer n'est pas un relevé). */
        sameAsPrevious(e) {
            const p = e.previous && Number(e.previous.value_c);
            if (!Number.isFinite(p)) return;
            const entry = this.entries[e.id];
            entry.neg = p < 0;
            entry.raw = this.fmt(Math.abs(p)).replace(/,0$/, "");
        },
        rawError(e) {
            const entry = this.entries[e.id];
            if (!entry || String(entry.raw).trim() === "") return "";
            return parseTemperature(entry.raw) === null ? "Valeur illisible : tapez les chiffres (ex. 3 ou 3,5), le signe se règle avec le bouton ±." : "";
        },
        /** Température signée prête à envoyer, ou null tant que la saisie est vide/illisible. */
        valueOf(e) {
            const entry = this.entries[e.id];
            if (!entry) return null;
            const n = parseTemperature(entry.raw);
            if (n === null) return null;
            return entry.neg ? -n : n;
        },
        verdictOf(e) {
            const v = this.valueOf(e);
            return v === null ? { status: "ok", reason: null } : previewVerdict(v, e.min_c, e.max_c);
        },
        needsNote(e) {
            const entry = this.entries[e.id];
            const a = entry && this.actions.find((x) => x.key === entry.action);
            return Boolean(a && a.note);
        },
        actionOk(e) {
            if (this.verdictOf(e).status !== "alert") return true;
            const entry = this.entries[e.id];
            if (!entry.action) return false;
            return !this.needsNote(e) || String(entry.note || "").trim() !== "";
        },

        // ── chargement ────────────────────────────────────────────────────────
        async loadToday() {
            try {
                const { data } = await axios.get("admin/hygiene/temperature/today");
                this.items = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                const server = Date.parse(this.meta.server_now);
                if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                // une saisie en cours pour un équipement disparu n'a plus de carte
                const ids = new Set(this.items.map((i) => i.id));
                this.entries = Object.fromEntries(Object.entries(this.entries).filter(([id]) => ids.has(Number(id))));
                if (!this.items.length) {
                    // des équipements existent peut-être, tous masqués : pas de « démarrage » dans ce cas
                    const all = await axios.get("admin/hygiene/temperature/equipment");
                    this.manageHasRows = (all.data.data || []).length > 0;
                }
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                this.loadError = "Relevés indisponibles. Vérifiez la connexion puis rechargez la page.";
            }
        },
        async loadHistory() {
            const id = historySeq.next();
            try {
                const { data } = await axios.get("admin/hygiene/temperature/history", { params: { alerts_only: this.alertsOnly ? 1 : 0, limit: 200 } });
                if (historySeq.isCurrent(id)) this.history = data.data || [];
            } catch (e) {
                alertService.error(this.apiMessage(e, "Journal indisponible."));
            }
        },
        toggleHistory() {
            this.showHistory = !this.showHistory;
            if (this.showHistory) this.loadHistory();
        },
        async loadEquipment() {
            try {
                const { data } = await axios.get("admin/hygiene/temperature/equipment");
                this.equipment = (data.data || []).map((q) => ({ ...q, min_c: q.min_c === null ? "" : String(q.min_c).replace(".", ","), max_c: q.max_c === null ? "" : String(q.max_c).replace(".", ",") }));
            } catch (e) {
                alertService.error(this.apiMessage(e, "Équipements indisponibles."));
            }
        },
        toggleManage() {
            this.manage = !this.manage;
            if (this.manage) this.loadEquipment();
        },

        // ── enregistrement ────────────────────────────────────────────────────
        async submit() {
            if (this.saving || !this.canSubmit) return;
            this.formError = "";
            this.saving = true;
            const sent = this.pendingList;
            // L'heure de la mesure n'est envoyée QUE hors ligne (l'horloge d'une tablette peut être fausse : en ligne, c'est le serveur qui date).
            const bodyFor = (stamp) => ({
                operator_user_id: this.operatorId || undefined,
                operator_token: this.operatorToken || undefined,
                readings: sent.map((e) => {
                    const entry = this.entries[e.id];
                    const verdict = this.verdictOf(e);
                    return {
                        equipment_id: e.id,
                        value: this.valueOf(e),
                        client_ref: refFor(entry.ref, { v: this.valueOf(e), a: entry.action, n: entry.note }),
                        ...(verdict.status === "alert" ? { corrective_action: entry.action, corrective_note: entry.note || undefined } : {}),
                        ...(stamp ? { measured_at: stamp } : {}),
                    };
                }),
            });
            try {
                const { data } = await axios.post("admin/hygiene/temperature/readings", bodyFor(null));
                const rest = { ...this.entries };
                sent.forEach((e) => { delete rest[e.id]; });
                this.entries = rest;
                await this.loadToday();
                await this.reloadCharts();
                if (this.showHistory) await this.loadHistory();
                const n = (data.data || []).length;
                const alerts = Number(data.meta && data.meta.alerts) || 0;
                if (alerts > 0) alertService.error(`${n} relevé${n > 1 ? "s" : ""} enregistré${n > 1 ? "s" : ""} — ${alerts} écart${alerts > 1 ? "s" : ""} tracé${alerts > 1 ? "s" : ""}.`);
                else alertService.success(`${n} relevé${n > 1 ? "s" : ""} enregistré${n > 1 ? "s" : ""}.`);
            } catch (e) {
                if (isRetriable(e)) {
                    // pas de réseau (ou serveur indisponible) : le relevé est GARDÉ sur la tablette avec l'heure de la mesure, jamais perdu
                    const n = sent.length;
                    const kept = this.queue().enqueue({ url: "admin/hygiene/temperature/readings", body: bodyFor(new Date().toISOString()), label: `${n} relevé${n > 1 ? "s" : ""}`, kind: "temperature" });
                    if (kept) {
                        const rest = { ...this.entries };
                        sent.forEach((x) => { delete rest[x.id]; });
                        this.entries = rest;
                        this.refreshQueue();
                        alertService.info(`Pas de réseau : ${n} relevé${n > 1 ? "s" : ""} gardé${n > 1 ? "s" : ""} sur la tablette. Ils partiront tout seuls dès que la connexion revient.`);
                        return;
                    }
                    this.formError = "Pas de réseau, et la tablette ne peut pas garder ces relevés (mémoire pleine ou navigation privée). Ne fermez pas cet écran : revalidez dès que la connexion revient.";
                    alertService.error(this.formError);
                    return;
                }
                // refus du serveur (écart sans action, valeur absurde…) : la saisie reste, la personne corrige et revalide
                this.formError = this.apiMessage(e, "Enregistrement impossible : vérifiez la connexion puis revalidez (rien n'est doublé).");
                alertService.error(this.formError);
            } finally {
                this.saving = false;
            }
        },
        askVoid(reading) {
            this.voidReason = "";
            this.voidTarget = reading;
        },
        async confirmVoid() {
            if (!this.voidTarget || this.saving) return;
            this.saving = true;
            try {
                await axios.post(`admin/hygiene/temperature/readings/${this.voidTarget.id}/void`, { reason: this.voidReason.trim() });
                this.voidTarget = null;
                await this.loadToday();
                await this.reloadCharts();
                if (this.showHistory) await this.loadHistory();
                alertService.success("Relevé annulé (il reste au journal).");
            } catch (e) {
                alertService.error(this.apiMessage(e, "Annulation impossible : réessayez."));
            } finally {
                this.saving = false;
            }
        },
        async quickSetup() {
            if (this.saving || !this.setupTotal) return;
            this.saving = true;
            try {
                await axios.post("admin/hygiene/temperature/equipment/quick-setup", { ...this.setup });
                await this.loadToday();
            } catch (e) {
                this.loadError = this.apiMessage(e, "Création impossible. Vérifiez la connexion puis réessayez.");
            } finally {
                this.saving = false;
            }
        },

        // ── équipements ───────────────────────────────────────────────────────
        /** Seuil saisi → nombre, null (aucun seuil, champ vide) ou NaN (illisible : refusé, jamais envoyé comme « aucun seuil »). */
        thresholdPayload(v) {
            const s = String(v ?? "").replace(/[−–—]/g, "-").replace(",", ".").trim();
            return s === "" ? null : (/^-?\d{1,2}(\.\d)?$/.test(s) ? Number(s) : NaN);
        },
        async saveEquipment(q) {
            const min = this.thresholdPayload(q.min_c);
            const max = this.thresholdPayload(q.max_c);
            if (Number.isNaN(min) || Number.isNaN(max)) {
                // jamais envoyé comme « aucun seuil » : NaN serait sérialisé en null et désarmerait silencieusement le contrôle
                alertService.error(`Seuil illisible pour « ${q.name.trim()} » : tapez un nombre (ex. 4 ou −18), ou laissez vide pour aucun seuil.`);
                return;
            }
            try {
                await axios.put(`admin/hygiene/temperature/equipment/${q.id}`, {
                    name: q.name, min_c: min, max_c: max,
                });
                await Promise.all([this.loadEquipment(), this.loadToday()]);
                alertService.success(`« ${q.name.trim()} » enregistré.`);
            } catch (e) {
                alertService.error(this.apiMessage(e, "Enregistrement impossible."));
            }
        },
        async toggleEquipment(q) {
            try {
                await axios.put(`admin/hygiene/temperature/equipment/${q.id}`, { is_active: !q.is_active });
                await Promise.all([this.loadEquipment(), this.loadToday()]);
            } catch (e) {
                alertService.error(this.apiMessage(e, "Modification impossible."));
            }
        },
        async addEquipment() {
            if (this.saving) return;
            this.saving = true;
            try {
                await axios.post("admin/hygiene/temperature/equipment", { name: this.newEq.name.trim(), kind: this.newEq.kind });
                this.newEq = { name: "", kind: this.newEq.kind };
                await Promise.all([this.loadEquipment(), this.loadToday()]);
            } catch (e) {
                alertService.error(this.apiMessage(e, "Ajout impossible."));
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>

<style scoped>
/* Le thème met les titres de carte en « Capitalize » (anglais) : « Samedi 17 Octobre ». Cet écran n'importe pas hygiene.css. */
.db-card-header .db-card-title.hy-title { text-transform: none; }
.tmp-muted { color: #475569; padding: 12px 4px; }
.tmp-alert { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 10px 12px; margin: 0 0 12px; font-weight: 600; }
.tmp-setup { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 18px; }
.tmp-steppers { display: flex; flex-wrap: wrap; gap: 14px; margin: 14px 0; }
.tmp-stepper { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px 12px; }
.tmp-stepper-label { min-width: 130px; font-weight: 700; }
.tmp-stepper-n { min-width: 28px; text-align: center; font-size: 22px; }
.tmp-step { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 26px; font-weight: 800; cursor: pointer; }

.tmp-summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.tmp-who { margin-bottom: 12px; }
.tmp-offline { border: 2px solid #f59e0b; background: #fffbeb; color: #78350f; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; line-height: 1.5; display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; }
.tmp-refused { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; margin-bottom: 12px; }
.tmp-queued { display: flex; flex-direction: column; gap: 2px; border: 2px dashed #f59e0b; background: #fffbeb; border-radius: 12px; padding: 10px 12px; color: #78350f; }
.tmp-period { background: #111827; color: #fff; border-radius: 999px; padding: 6px 14px; font-weight: 800; }
.tmp-chip { border-radius: 999px; padding: 6px 12px; background: #f1f5f9; color: #334155; font-weight: 700; border: 1px solid transparent; font-size: 15px; }
.tmp-chip.is-todo { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.tmp-chip.is-ok { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.tmp-chip.is-alert { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.tmp-link { background: none; border: 0; padding: 6px 0; min-height: 48px; font-size: 14px; font-weight: 700; color: #1e40af; cursor: pointer; text-align: left; }
.tmp-chip--btn { cursor: pointer; min-height: 44px; background: #fff; border-color: #cbd5e1; }
.tmp-chip--btn.is-on { background: #111827; color: #fff; border-color: #111827; }

.tmp-grid { padding-bottom: 4px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; align-items: start; }
.tmp-card { border: 2px solid #e2e8f0; border-radius: 14px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 10px; }
.tmp-card.is-done { border-color: #86efac; background: #f0fdf4; }
.tmp-card.is-ready { border-color: #2563eb; }
.tmp-card.is-alert { border-color: #f87171; background: #fef2f2; }
.tmp-card-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.tmp-name { display: block; font-size: 19px; color: #111827; }
.tmp-kind { color: #64748b; font-size: 14px; }
.tmp-limit { background: #e0f2fe; color: #075985; border-radius: 8px; padding: 4px 8px; font-weight: 800; font-size: 14px; white-space: nowrap; }
.tmp-limit.is-neg { background: #ede9fe; color: #5b21b6; }

.tmp-take { color: #111827; min-height: 64px; border-radius: 12px; border: 2px dashed #94a3b8; background: #f8fafc; cursor: pointer; font-size: 17px; font-weight: 800; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
.tmp-take small { font-weight: 600; color: #64748b; }
.tmp-entry { display: flex; flex-direction: column; gap: 8px; }
.tmp-input-row { display: flex; align-items: center; gap: 8px; }
.tmp-sign { width: 56px; height: 56px; border-radius: 12px; border: 2px solid #111827; background: #fff; font-size: 30px; font-weight: 900; cursor: pointer; }
.tmp-sign[aria-pressed="true"] { background: #111827; color: #fff; }
.tmp-input { flex: 1; min-width: 0; height: 56px; font-size: 30px; font-weight: 800; text-align: center; border: 2px solid #94a3b8; border-radius: 12px; }
.tmp-input:focus { border-color: #2563eb; outline: none; }
.tmp-unit { font-size: 22px; font-weight: 800; color: #475569; }
.tmp-quick { display: flex; flex-wrap: wrap; gap: 8px; }
.tmp-hint.is-alert { color: #b91c1c; margin: 0; font-size: 14px; }
.tmp-verdict { margin: 0; padding: 8px 10px; border-radius: 10px; font-weight: 800; }
.tmp-verdict.is-ok { background: #ecfdf5; color: #065f46; }
.tmp-verdict.is-alert { background: #fee2e2; color: #991b1b; }
.tmp-corrective { display: flex; flex-direction: column; gap: 8px; }
.tmp-label { font-weight: 700; font-size: 14px; color: #334155; }
.tmp-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.tmp-note { width: 100%; min-height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 6px 10px; font-size: 16px; }

.tmp-done { display: flex; flex-direction: column; gap: 4px; }
.tmp-done-value { font-size: 28px; font-weight: 900; color: #166534; }
.tmp-done.is-alert .tmp-done-value { color: #b91c1c; }
.tmp-done-meta { color: #475569; font-size: 14px; }
.tmp-done-alert { color: #991b1b; font-weight: 700; font-size: 14px; }
.tmp-done-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.tmp-void { border-top: 1px solid #fecaca; padding-top: 10px; display: flex; flex-direction: column; gap: 8px; }

.tmp-bar { color: #111827; position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-top: 14px; padding: 12px; background: #fff; border: 2px solid #111827; border-radius: 14px; box-shadow: 0 -6px 18px rgba(15, 23, 42, .12); }
.tmp-bar-text { font-size: 17px; }
.tmp-bar-warn { color: #b91c1c; font-weight: 800; }

.tmp-btn { min-height: 52px; padding: 0 18px; border-radius: 12px; border: 2px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 800; cursor: pointer; }
.tmp-btn:disabled { opacity: .45; cursor: not-allowed; }
.tmp-btn--primary { background: #111827; color: #fff; border-color: #111827; }
.tmp-btn--small { min-height: 44px; padding: 0 12px; font-size: 15px; }
.tmp-btn--danger { background: #b91c1c; color: #fff; border-color: #b91c1c; }

.tmp-section { margin-top: 18px; }
.tmp-toggle { background: none; border: 0; font-size: 17px; font-weight: 800; cursor: pointer; padding: 8px 0; min-height: 48px; color: #111827; }
.tmp-check { display: inline-flex; gap: 8px; align-items: center; min-height: 44px; font-weight: 600; }
.tmp-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.tmp-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
.tmp-row.is-alert { border-color: #fecaca; background: #fef2f2; }
.tmp-row.is-void { opacity: .55; text-decoration: line-through; }
.tmp-row--edit { text-decoration: none; }
.tmp-row-when { color: #64748b; min-width: 100px; }
.tmp-row-name { font-weight: 700; min-width: 120px; }
.tmp-row-meta { color: #475569; font-size: 14px; flex: 1; min-width: 200px; }
.tmp-eq-name { width: 200px; flex: 0 0 auto; }
.tmp-eq-th { display: inline-flex; gap: 6px; align-items: center; font-weight: 600; }
.tmp-eq-num { width: 64px; min-height: 44px; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center; font-size: 16px; }
.tmp-add { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
.tmp-add .tmp-note { max-width: 280px; }
.tmp-select { min-height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 10px; font-size: 16px; }
</style>
