<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Cayenne Hygiène — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <HygieneQueueBanner :timezone="meta.timezone || 'Europe/Paris'" />
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="home-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <p v-if="stale" class="hy-banner is-todo" role="status" data-testid="home-stale">
                        Réseau indisponible : ces chiffres datent de {{ staleSince }}. Ils ne sont peut-être plus à jour.
                    </p>
                    <p v-else class="hy-banner" :class="`is-${bannerKind}`" role="status" data-testid="home-banner">{{ bannerText }}</p>

                    <!-- Mise en place : où j'en suis, ce qu'il reste, LA prochaine étape (rien à cocher : tout se déduit des journaux) -->
                    <section v-if="showSetup" class="hy-setup" :class="{ 'is-complete': setup.complete }" aria-labelledby="home-setup-title" data-testid="home-setup">
                        <div class="hy-setup-head">
                            <h4 id="home-setup-title" class="hy-setup-title" data-testid="home-setup-title">{{ setupTitle }}</h4>
                            <span class="hy-setup-count" data-testid="home-setup-count">{{ setup.required_done }}/{{ setup.required_total }}</span>
                        </div>
                        <div class="hy-setup-bar" role="progressbar" aria-valuemin="0" :aria-valuemax="setup.required_total" :aria-valuenow="setup.required_done" aria-label="Mise en place" data-testid="home-setup-bar">
                            <span class="hy-setup-fill" :style="{ width: setupPct + '%' }"></span>
                        </div>
                        <p class="hy-setup-sub" data-testid="home-setup-sub">{{ setupSub }}</p>

                        <router-link v-if="setup.next && !setup.complete" :to="setup.next.route" class="hy-setup-next" data-testid="home-setup-next">
                            <span class="hy-setup-next-label">Prochaine étape</span>
                            <strong>{{ setup.next.label }}</strong>
                            <span class="hy-setup-next-detail">{{ setup.next.detail }}</span>
                            <span class="hy-setup-next-cta">{{ setup.next.cta }} →</span>
                        </router-link>

                        <ul class="hy-setup-steps" data-testid="home-setup-steps">
                            <li v-for="st in requiredSteps" :key="st.key" :class="{ 'is-done': st.done, 'is-next': setup.next && setup.next.key === st.key }" :data-testid="`home-setup-step-${st.key}`">
                                <span class="hy-setup-mark" aria-hidden="true">{{ st.done ? "✓" : "○" }}</span>
                                <span class="hy-setup-label">{{ st.label }}<span class="sr-only">{{ st.done ? " : fait" : " : à faire" }}</span></span>
                            </li>
                        </ul>

                        <details v-if="optionalSteps.length" class="hy-setup-more" data-testid="home-setup-more">
                            <summary>Pour aller plus loin ({{ optionalSteps.filter((o) => o.done).length }}/{{ optionalSteps.length }})</summary>
                            <ul class="hy-setup-steps">
                                <li v-for="st in optionalSteps" :key="st.key" :class="{ 'is-done': st.done }" :data-testid="`home-setup-step-${st.key}`">
                                    <span class="hy-setup-mark" aria-hidden="true">{{ st.done ? "✓" : "○" }}</span>
                                    <router-link v-if="!st.done" :to="st.route" class="hy-setup-label">{{ st.label }} <span class="hy-setup-opt">— {{ st.cta }}</span></router-link>
                                    <span v-else class="hy-setup-label">{{ st.label }}</span>
                                </li>
                            </ul>
                        </details>

                        <button v-if="setup.complete" type="button" class="hy-btn hy-btn--small hy-setup-dismiss" data-testid="home-setup-dismiss" @click="dismissSetup">Compris, masquer ce guide</button>
                    </section>

                    <!-- Ce qui est en retard ou à traiter MAINTENANT : une phrase, un geste (touchez pour y aller) -->
                    <section v-if="reminders.length" class="hy-reminders" :class="{ 'is-soft': !hasAlertReminder }" aria-labelledby="home-reminders-title" data-testid="home-reminders">
                        <h4 id="home-reminders-title" class="hy-reminders-title">À traiter maintenant</h4>
                        <ul class="hy-reminders-list">
                            <li v-for="r in reminders" :key="r.key" :class="`is-${r.level}`" data-testid="home-reminder">
                                <router-link :to="r.route" class="hy-reminder-link">
                                    <span class="hy-reminder-icon" aria-hidden="true">{{ r.level === "alert" ? "⚠" : "●" }}</span>
                                    <span class="hy-reminder-text">{{ r.text }}</span>
                                    <span class="hy-reminder-go" aria-hidden="true">→</span>
                                </router-link>
                            </li>
                        </ul>
                    </section>

                    <div class="hy-tiles">
                        <router-link v-for="t in tiles" :key="t.key" :to="t.route" class="hy-tile" :class="`is-${t.status}`" :data-testid="`home-tile-${t.key}`">
                            <span class="hy-tile-icon" aria-hidden="true"><i :class="t.icon"></i></span>
                            <span class="hy-tile-label">{{ t.label }}</span>
                            <strong class="hy-tile-headline" :data-testid="`home-headline-${t.key}`">{{ t.headline }}</strong>
                            <span v-if="t.detail" class="hy-tile-detail" :data-testid="`home-detail-${t.key}`">{{ t.detail }}</span>
                            <!-- le geste, pas « Ouvrir » : une action = un grand bouton visible -->
                            <span v-if="t.cta" class="hy-tile-cta" :data-testid="`home-cta-${t.key}`">{{ t.cta }} →</span>
                        </router-link>
                        <router-link to="/admin/hygiene/report" class="hy-tile is-report" data-testid="home-tile-report">
                            <span class="hy-tile-icon" aria-hidden="true"><i class="fa-solid fa-file-pdf"></i></span>
                            <span class="hy-tile-label">Dossier de contrôle</span>
                            <strong class="hy-tile-headline">PDF et exports</strong>
                            <span class="hy-tile-detail">Pour l'inspection sanitaire</span>
                        </router-link>
                    </div>
                    <p class="hy-home-links">
                        <router-link to="/admin/hygiene/alerts" class="hy-btn hy-btn--small" data-testid="home-alerts-link">🔔 Alertes par e-mail aux responsables</router-link>
                        <router-link to="/admin/hygiene/team" class="hy-btn hy-btn--small" data-testid="home-team-link">🔒 Codes de l'équipe</router-link>
                    </p>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Accueil du module : une tuile par domaine avec ce qu'il reste à faire AUJOURD'HUI et les alertes
 * (la tuile rouge « 0/5 effectués » de Komia, pour tous les domaines). Les compteurs viennent du serveur ; l'écran se rafraîchit seul.
 */
import axios from "axios";
import { formatDay } from "../../../helpers/hygieneCommon";
import HygieneQueueBanner from "./HygieneQueueBanner";

export default {
    name: "HygieneHomeComponent",
    components: { HygieneQueueBanner },
    data() {
        return { tiles: [], meta: { alerts: 0, todo: 0, reminders: [], setup: null, server_now: null, timezone: "Europe/Paris" }, setupDismissed: this.readDismissed(), loaded: false, loadError: "", timer: null, failures: 0, lastOkAt: null };
    },
    computed: {
        /** Après deux rafraîchissements ratés, « Tout est en ordre ✓ » ne doit plus s'afficher : ce serait une affirmation sur des données périmées. */
        stale() { return this.loaded && this.failures >= 2; },
        staleSince() {
            try { return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(this.lastOkAt)); } catch (_) { return "tout à l'heure"; }
        },
        setup() { return this.meta.setup && Array.isArray(this.meta.setup.steps) ? this.meta.setup : null; },
        requiredSteps() { return this.setup ? this.setup.steps.filter((x) => !x.optional) : []; },
        optionalSteps() { return this.setup ? this.setup.steps.filter((x) => x.optional) : []; },
        /** Le guide reste tant que la mise en place n'est pas finie ; une fois finie, il se félicite puis se masque quand on l'a lu. */
        showSetup() { return !!this.setup && !(this.setup.complete && this.setupDismissed); },
        setupPct() { return this.setup && this.setup.required_total ? Math.round((this.setup.required_done / this.setup.required_total) * 100) : 0; },
        setupTitle() {
            if (!this.setup) return "";
            if (this.setup.complete) return "Mise en place terminée 🎉";
            return this.setup.required_done === 0 ? "Mettons votre registre en route" : "Votre registre prend forme";
        },
        setupSub() {
            if (!this.setup) return "";
            const left = this.setup.required_total - this.setup.required_done;
            if (this.setup.complete) return "Votre registre est prêt : chaque relevé, chaque nettoyage, chaque étiquette s'ajoute désormais au dossier de contrôle.";
            if (this.setup.required_done === 0) return `${this.setup.required_total} étapes, quelques minutes en tout. Les réglages sont déjà préparés pour vous.`;
            if (left === 1) return "Plus qu'une étape : vous y êtes presque !";
            return `Bien joué : encore ${left} étapes.`;
        },
        hasAlertReminder() { return this.reminders.some((r) => r.level === "alert"); },
        /** Les rappels du serveur : alertes d'abord, puis le reste à faire. */
        reminders() {
            const rank = { alert: 0, todo: 1 };
            return [...(this.meta.reminders || [])].sort((a, b) => (rank[a.level] ?? 2) - (rank[b.level] ?? 2));
        },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
        /** Domaines pas encore configurés (aucun frigo, aucun plan…) : « tout est en ordre » serait faux tant qu'il en reste. */
        unconfigured() { return this.tiles.filter((t) => t.status === "empty"); },
        /** Un domaine FACULTATIF (la friture : beaucoup de restaurants n'en ont pas) ne retire jamais « journée en règle » : le guide le propose, sans reproche. */
        blocking() { return this.unconfigured.filter((t) => t.key !== "oil"); },
        bannerKind() {
            if (this.meta.alerts > 0) return "alert";
            // un rappel « à traiter » (huile à changer, traçabilité muette…) ne promeut pas sa tuile en alerte : la bannière ne dit jamais « en règle » à côté de lui
            return this.meta.todo > 0 || this.blocking.length > 0 || this.reminders.length > 0 ? "todo" : "ok";
        },
        bannerText() {
            const a = this.meta.alerts;
            const t = this.meta.todo;
            // a et t comptent des DOMAINES (tuiles), pas des tâches : le dire, sinon « 1 chose à faire » contredit « 5 à faire » sur la tuile
            if (a > 0) return `${a} domaine${a > 1 ? "s" : ""} en alerte à traiter${t > 0 ? ` · ${t} autre${t > 1 ? "s" : ""} à compléter` : ""}`;
            if (t > 0) return `${t} domaine${t > 1 ? "s" : ""} à compléter aujourd'hui`;
            if (this.tiles.length && this.blocking.length && this.unconfigured.length === this.tiles.length) return "Commencez par configurer un domaine : touchez une tuile.";
            if (this.blocking.length) return `Rien d'urgent, mais à configurer : ${this.blocking.map((u) => u.label).join(", ")}.`;
            if (this.reminders.length) return `Rien d'urgent, mais ${this.reminders.length} point${this.reminders.length > 1 ? "s" : ""} à traiter ci-dessous.`;
            return this.doneSummary ? `Journée en règle ✓ · ${this.doneSummary}` : "Tout est en ordre ✓";
        },
        /** Ce qui est VRAIMENT fait aujourd'hui, en chiffres : un « tout est en ordre » vague ne récompense personne. */
        doneSummary() {
            const parts = [];
            const t = this.tiles.find((x) => x.key === "temperature");
            if (t && t.counts && t.counts.total > 0) parts.push(`${t.counts.done}/${t.counts.total} relevés`);
            const k = this.tiles.find((x) => x.key === "tasks");
            if (k && k.counts && k.counts.total > 0) parts.push(`${k.counts.done}/${k.counts.total} fiches de nettoyage`);
            return parts.join(" · ");
        },
    },
    created() {
        this.load();
        this.timer = setInterval(() => this.load(), 60000);
    },
    beforeUnmount() { if (this.timer) clearInterval(this.timer); },
    methods: {
        readDismissed() {
            try { return window.localStorage.getItem("hygiene.setup.dismissed") === "1"; } catch (_) { return false; }
        },
        dismissSetup() {
            this.setupDismissed = true;
            try { window.localStorage.setItem("hygiene.setup.dismissed", "1"); } catch (_) { /* navigation privée : le guide reviendra, sans conséquence */ }
        },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/overview");
                this.tiles = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
                this.failures = 0;
                this.lastOkAt = Date.now();
            } catch (e) {
                this.failures += 1;
                // un rafraîchissement raté ne vide pas l'écran : on garde les dernières valeurs
                if (!this.loaded) this.loadError = "Accueil indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
