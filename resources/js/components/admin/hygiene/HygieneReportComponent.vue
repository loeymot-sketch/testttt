<template>
    <div class="col-12">
        <HygieneHubBar />
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Dossier de contrôle sanitaire</h3>
            </div>

            <div class="db-card-body">
                <div class="hy-segment" role="group" aria-label="Période" data-testid="rep-presets">
                    <button v-for="p in presets" :key="p.key" type="button" class="hy-chip hy-chip--btn" :class="{ 'is-on': preset === p.key }" :aria-pressed="preset === p.key ? 'true' : 'false'"
                            :data-testid="`rep-preset-${p.key}`" @click="choose(p.key)">{{ p.label }}</button>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">
                    <div class="hy-field" style="min-width:180px"><label class="hy-label" for="rep-from">Du</label><input id="rep-from" v-model="from" type="date" class="hy-input" data-testid="rep-from" @change="preset = 'custom'; load()" /></div>
                    <div class="hy-field" style="min-width:180px"><label class="hy-label" for="rep-to">Au</label><input id="rep-to" v-model="to" type="date" class="hy-input" data-testid="rep-to" @change="preset = 'custom'; load()" /></div>
                </div>

                <p v-if="error" class="hy-alert" role="alert" data-testid="rep-error">{{ error }}</p>
                <p v-if="loading" class="hy-muted" role="status">Calcul…</p>

                <template v-if="data && !loading">
                    <div class="hy-grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));margin-bottom:14px" data-testid="rep-kpis">
                        <div class="hy-card" :class="rateClass(data.temperature.coverage_pct)" data-testid="rep-kpi-temperature">
                            <span class="hy-sub">Relevés de température faits</span>
                            <strong class="hy-kpi">{{ pct(data.temperature.coverage_pct) }}</strong>
                            <span class="hy-sub">{{ data.temperature.covered }} sur {{ data.temperature.expected }} attendus · {{ plural(data.temperature.alerts, "écart") }}</span>
                            <span v-if="data.temperature.today" class="hy-sub" data-testid="rep-today-temperature">{{ todayLine(data.temperature.today, data.temperature.expected, data.period.past_days) }}</span>
                        </div>
                        <div class="hy-card" :class="rateClass(data.tasks.completion_pct)" data-testid="rep-kpi-tasks">
                            <span class="hy-sub">Fiches de nettoyage faites</span>
                            <strong class="hy-kpi">{{ pct(data.tasks.completion_pct) }}</strong>
                            <span class="hy-sub">{{ data.tasks.done }} sur {{ data.tasks.expected }} · {{ data.tasks.partial }} incomplète(s) · {{ data.tasks.missed }} non faite(s)</span>
                            <span v-if="data.tasks.today" class="hy-sub" data-testid="rep-today-tasks">{{ todayLine(data.tasks.today, data.tasks.expected, data.period.past_days) }}</span>
                        </div>
                        <div class="hy-card" :class="rateClass(data.oil.coverage_pct)" data-testid="rep-kpi-oil">
                            <span class="hy-sub">Tests d'huile faits</span>
                            <strong class="hy-kpi">{{ pct(data.oil.coverage_pct) }}</strong>
                            <span class="hy-sub">{{ data.oil.tests }} test(s) · {{ data.oil.max_polar === null || data.oil.max_polar === undefined ? "aucune mesure de polaires" : `max ${pct(data.oil.max_polar)} de polaires` }} · {{ plural(data.oil.alerts, "écart") }}</span>
                            <span v-if="data.oil.today && data.oil.fryers > 0" class="hy-sub" data-testid="rep-today-oil">{{ todayLine(data.oil.today, data.oil.expected, data.period.past_days) }}</span>
                        </div>
                        <div class="hy-card" :class="data.receptions.alerts ? 'is-partial' : ''" data-testid="rep-kpi-receptions">
                            <span class="hy-sub">Réceptions</span>
                            <strong class="hy-kpi">{{ data.receptions.count }}</strong>
                            <span class="hy-sub">{{ plural(data.receptions.alerts, "écart") }} · {{ data.receptions.refused }} refusée(s)</span>
                        </div>
                        <div class="hy-card" data-testid="rep-kpi-trace">
                            <span class="hy-sub">Produits tracés</span>
                            <strong class="hy-kpi">{{ data.trace.count }}</strong>
                            <span class="hy-sub">{{ data.trace.without_lot }} sans lot (expliqué)</span>
                        </div>
                        <div class="hy-card" :class="data.dlc.expired_not_out ? 'is-alert' : ''" data-testid="rep-kpi-dlc">
                            <span class="hy-sub">Étiquettes DLC</span>
                            <strong class="hy-kpi">{{ data.dlc.count }}</strong>
                            <span class="hy-sub">{{ data.dlc.discarded }} jetée(s) · {{ data.dlc.used }} utilisée(s) · {{ data.dlc.expired_not_out }} échue(s) non sortie(s)</span>
                        </div>
                    </div>

                    <p v-if="data.tasks.missed" class="hy-alert" data-testid="rep-missed">
                        {{ data.tasks.missed }} fiche(s) de nettoyage non faite(s) sur la période<template v-if="data.tasks.missed_list.length"> — dernières : {{ data.tasks.missed_list.slice(-3).map((m) => `${day(m.date)} ${m.task}`).join(" ; ") }}</template>.
                    </p>

                    <h4 class="hy-h">Incidents et actions correctives ({{ data.incidents.length }})</h4>
                    <p v-if="!data.incidents.length" class="hy-note" data-testid="rep-no-incident">Aucun incident sur la période.</p>
                    <ul class="hy-list" data-testid="rep-incidents">
                        <li v-for="(i, k) in data.incidents" :key="k" class="hy-row is-alert">
                            <span class="hy-row-when">{{ dayHm(i.at) }}</span>
                            <span class="hy-row-name">{{ moduleName(i.module) }}</span>
                            <span class="hy-row-meta">{{ i.text }}<template v-if="i.action"> — <strong>{{ i.action }}</strong></template><template v-if="i.operator"> · {{ i.operator }}</template></span>
                        </li>
                    </ul>

                    <h4 class="hy-h">Télécharger</h4>
                    <div class="hy-segment">
                        <button type="button" class="hy-btn hy-btn--primary" :disabled="busy !== ''" data-testid="rep-pdf" @click="pdf">{{ busy === "pdf" ? "Préparation…" : "📄 Dossier complet (PDF)" }}</button>
                        <button v-for="m in csvModules" :key="m.key" type="button" class="hy-btn" :disabled="busy !== ''" :data-testid="`rep-csv-${m.key}`" @click="csv(m)">{{ busy === m.key ? "…" : `${m.label} (CSV)` }}</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Dossier de contrôle sanitaire : choisir une période, voir les TAUX RÉELS (relevés et nettoyages
 * faits sur ceux qui étaient attendus) et les incidents avec l'action corrective, puis télécharger le PDF à remettre à l'inspection
 * ou un CSV par domaine. Tout vient du serveur (une seule source pour l'écran, le PDF et les CSV).
 */
import HygieneHubBar from "./HygieneHubBar";
import axios from "axios";
import alertService from "../../../services/alertService";
import { apiMessage, formatDayHm, todayIso, addDaysIso, downloadFile, latest } from "../../../helpers/hygieneCommon";

const loadSeq = latest();

const MODULE_NAMES = { temperature: "Températures", tasks: "Nettoyage", receptions: "Réception", trace: "Traçabilité", oil: "Huile" };

export default {
    name: "HygieneReportComponent",
    components: { HygieneHubBar },
    data() {
        // période et « aujourd'hui » viennent du SERVEUR (1er chargement sans période) : une tablette mal réglée ne décale rien
        return { preset: "month", from: "", to: "", data: null, loading: false, error: "", busy: "", today: "" };
    },
    computed: {
        presets() {
            return [{ key: "today", label: "Aujourd'hui" }, { key: "week", label: "7 derniers jours" }, { key: "month30", label: "30 derniers jours" },
                { key: "month", label: "Mois en cours" }, { key: "lastmonth", label: "Mois dernier" }, { key: "custom", label: "Personnalisé" }];
        },
        csvModules() {
            return [{ key: "temperature", label: "Températures" }, { key: "tasks", label: "Nettoyage" }, { key: "receptions", label: "Réceptions" },
                { key: "trace", label: "Traçabilité" }, { key: "oil", label: "Huile" }, { key: "dlc", label: "Étiquettes DLC" }];
        },
    },
    created() { this.load(true); },
    methods: {
        pct(v) { return v === null || v === undefined ? "—" : `${String(v).replace(".", ",")} %`; },
        plural(n, w) { return `${n} ${w}${n > 1 ? "s" : ""}`; },
        day(iso) { return String(iso).split("-").reverse().join("/"); },
        dayHm(iso) { return formatDayHm(iso); },
        moduleName(m) { return MODULE_NAMES[m] || m; },
        /**
         * La journée EN COURS n'entre jamais dans le taux (elle n'est pas finie) : dite à part, avec le même chiffre que l'accueil (1 sur 3).
         * « Aucun jour écoulé » vient du SERVEUR (`period.past_days`), jamais de `expected === 0` : un frigo créé hier ou aucune fiche configurée
         * n'a aucun jour attendu alors que des jours se sont écoulés (tour 5 : la phrase était fausse).
         */
        todayLine(t, expected, pastDays) {
            let head = "";
            if (pastDays === 0) head = "Aucun jour écoulé dans la période, donc pas de taux. ";
            else if (expected === 0) head = "Aucun jour attendu dans la période (éléments créés récemment ou rien de configuré), donc pas de taux. ";
            return `${head}Aujourd'hui (journée en cours, hors taux) : ${t.done} sur ${t.expected}.`;
        },
        rateClass(v) { return v === null || v === undefined ? "" : (v >= 100 ? "is-done" : (v >= 90 ? "is-partial" : "is-alert")); },
        choose(key) {
            const t = this.today;
            if (!t) return;
            if (key === "custom") { this.preset = key; return; }
            if (key === "today") { this.from = t; this.to = t; }
            else if (key === "week") { this.from = addDaysIso(t, -6); this.to = t; }
            else if (key === "month30") { this.from = addDaysIso(t, -29); this.to = t; }
            else if (key === "month") { this.from = `${t.slice(0, 8)}01`; this.to = t; }
            else if (key === "lastmonth") {
                const first = `${t.slice(0, 8)}01`;
                const last = addDaysIso(first, -1);
                this.from = `${last.slice(0, 8)}01`;
                this.to = last;
            }
            this.preset = key;
            this.load();
        },
        async load(initial = false) {
            if (!initial && (!this.from || !this.to)) return;
            if (!initial && this.to < this.from) { this.error = "La date de fin précède la date de début."; this.data = null; return; }
            const id = loadSeq.next();
            this.loading = true;
            this.error = "";
            try {
                const { data } = await axios.get("admin/hygiene/report/summary", { params: initial ? {} : { from: this.from, to: this.to } });
                if (!loadSeq.isCurrent(id)) return; // une réponse plus lente d'une ancienne période n'écrase jamais la dernière demandée
                if (initial) {
                    this.from = data.data.period.from;
                    this.to = data.data.period.to;
                    this.today = todayIso(data.meta.timezone || "Europe/Paris", new Date(data.meta.server_now || Date.now()));
                }
                this.data = data.data;
            } catch (e) {
                if (!loadSeq.isCurrent(id)) return;
                this.data = null;
                this.error = apiMessage(e, "Résumé indisponible. Vérifiez la connexion puis réessayez.");
            } finally {
                if (loadSeq.isCurrent(id)) this.loading = false;
            }
        },
        async pdf() {
            if (this.busy) return;
            this.busy = "pdf";
            try {
                await downloadFile("admin/hygiene/report/pdf", { from: this.from, to: this.to }, `dossier-hygiene-${this.from}-${this.to}.pdf`);
            } catch (e) {
                alertService.error("PDF non généré : réessayez.");
            } finally {
                this.busy = "";
            }
        },
        async csv(m) {
            if (this.busy) return;
            this.busy = m.key;
            try {
                await downloadFile("admin/hygiene/report/csv", { module: m.key, from: this.from, to: this.to }, `hygiene-${m.key}-${this.from}-${this.to}.csv`);
            } catch (e) {
                alertService.error("Export non généré : réessayez.");
            } finally {
                this.busy = "";
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
