<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Hygiène &amp; étiquettes — {{ todayLabel }}</h3>
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
                            <span class="hy-tile-cta" data-testid="home-cta-report">Voir le dossier →</span>
                        </router-link>
                    </div>
                    <!-- Tout le reste du module, en un coup d'œil : ce qui n'a pas de compteur du jour (étiquettes de commande, imprimante,
                         alertes, équipe). Rien n'est caché dans la barre latérale : elle n'a plus qu'UNE entrée. -->
                    <section class="hy-tools" aria-labelledby="home-tools-title" data-testid="home-tools">
                        <h4 id="home-tools-title" class="hy-tools-title">Autres outils</h4>
                        <div v-for="g in toolGroups" :key="g.key" class="hy-tools-group" :data-testid="`home-tools-${g.key}`">
                            <h5 class="hy-tools-group-title">{{ g.title }}</h5>
                            <ul class="hy-tools-list">
                                <li v-for="t in g.items" :key="t.key">
                                    <router-link :to="t.route" class="hy-tool" :data-testid="t.testid">
                                        <span class="hy-tool-icon" aria-hidden="true"><i :class="t.icon"></i></span>
                                        <span class="hy-tool-body">
                                            <strong>{{ t.label }}</strong>
                                            <span>{{ t.detail }}</span>
                                        </span>
                                        <span class="hy-tool-go" aria-hidden="true">→</span>
                                    </router-link>
                                </li>
                            </ul>
                        </div>
                    </section>
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
import { formatDay, scrollShellToTop, loadedBody } from "../../../helpers/hygieneCommon";
import { hasPermissionAccess } from "../../../shared/permission-match";
import HygieneQueueBanner from "./HygieneQueueBanner";

/** Rafraîchissement du hub : toutes les 60 s écran visible ; au réveil, seulement si les chiffres ont plus de 15 s. */
const REFRESH_MS = 60000;
const WAKE_MIN_AGE_MS = 15000;

export default {
    name: "HygieneHomeComponent",
    components: { HygieneQueueBanner },
    data() {
        return { tiles: [], meta: { alerts: 0, todo: 0, reminders: [], setup: null, server_now: null, timezone: "Europe/Paris" }, setupDismissed: this.readDismissed(), loaded: false, loadError: "", timer: null, failures: 0, lastOkAt: null, inFlight: false };
    },
    computed: {
        /** Dès le PREMIER rafraîchissement raté, « Journée en règle ✓ » ne doit plus s'afficher : ce serait une affirmation sur des données qu'on n'a pas pu vérifier (le tour suivant est dans 60 s). */
        stale() { return this.loaded && this.failures >= 1; },
        staleSince() {
            try { return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(this.lastOkAt)); } catch (_) { return "tout à l'heure"; }
        },
        /** Les outils sans compteur du jour, par famille (les tuiles ci-dessus portent les six domaines et le dossier de contrôle). */
        toolGroups() {
            // « needs » : le droit que le SERVEUR exige. Une carte vers un écran qui répondrait 403 est une impasse (59 caissiers + 22 responsables de site).
            // Liste de droits pas encore chargée : on propose tout (même règle que la barre latérale).
            const perms = "$store" in this && this.$store.getters ? this.$store.getters.authPermission : null; // `in` : pas d'avertissement quand la coquille n'a pas de store (tests)
            const groups = [
                { key: "labels", title: "Étiquettes et imprimante", items: [
                    { key: "printer", label: "Imprimante d'étiquettes", detail: "Brother QL-820NWB : connexion et réglages", route: "/admin/labels/printer", icon: "fa-solid fa-print", testid: "home-tool-labels-printer" },
                ] },
                { key: "settings", title: "Réglages du module", items: [
                    { key: "alerts", label: "Alertes par e-mail", detail: "Qui est prévenu, et quand", route: "/admin/hygiene/alerts", icon: "fa-solid fa-bell", testid: "home-alerts-link", needs: "settings" },
                    { key: "team", label: "Codes de l'équipe", detail: "Le code personnel pour signer un relevé", route: "/admin/hygiene/team", icon: "fa-solid fa-lock", testid: "home-team-link", needs: "settings" },
                ] },
            ];
            return groups
                .map((g) => ({ ...g, items: g.items.filter((t) => !t.needs || hasPermissionAccess(perms, t.needs)) }))
                .filter((g) => g.items.length > 0);
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
        this.timer = setInterval(() => { if (!this.hidden()) this.load(); }, REFRESH_MS);
    },
    mounted() {
        scrollShellToTop();
        // Tablette de cuisine allumée toute la journée : on n'interroge pas le serveur écran masqué, et au réveil / au retour du réseau
        // on rafraîchit tout de suite (sinon l'écran réveillé affichait « Journée en règle » d'il y a une heure jusqu'au prochain tour).
        document.addEventListener("visibilitychange", this.onWake);
        window.addEventListener("online", this.onWake);
    },
    beforeUnmount() {
        if (this.timer) clearInterval(this.timer);
        document.removeEventListener("visibilitychange", this.onWake);
        window.removeEventListener("online", this.onWake);
    },
    methods: {
        readDismissed() {
            try { return window.localStorage.getItem("hygiene.setup.dismissed") === "1"; } catch (_) { return false; }
        },
        dismissSetup() {
            this.setupDismissed = true;
            try { window.localStorage.setItem("hygiene.setup.dismissed", "1"); } catch (_) { /* navigation privée : le guide reviendra, sans conséquence */ }
        },
        hidden() { return typeof document !== "undefined" && document.visibilityState === "hidden"; },
        /** Écran qui se réveille ou réseau qui revient : rafraîchir si les chiffres ont plus de WAKE_MIN_AGE_MS (pas à chaque va-et-vient d'onglet). */
        onWake() {
            if (this.hidden()) return;
            if (!this.lastOkAt || Date.now() - this.lastOkAt >= WAKE_MIN_AGE_MS) this.load();
        },
        async load() {
            // jamais deux appels en même temps : une réponse lente + le tour suivant doublaient la charge, et la plus ancienne pouvait gagner
            if (this.inFlight) return;
            this.inFlight = true;
            try {
                // Une réponse 200 qui n'a pas la forme attendue (portail captif, proxy, page de connexion, {}) n'est pas « tout est en ordre » :
                // sans tuile ni rappel, la bannière aurait dit « Tout est en ordre ✓ » sur AUCUNE donnée. Un vrai serveur envoie toujours l'horloge.
                // [VG-2026-10-21 M8-103] la garde vit dans hygieneCommon (loadedBody) : la même pour TOUS les écrans du module
                const data = loadedBody(await axios.get("admin/hygiene/overview"));
                if (!Array.isArray(data.data)) throw new Error("réponse inattendue");
                this.tiles = data.data;
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
                this.failures = 0;
                this.lastOkAt = Date.now();
            } catch (e) {
                this.failures += 1;
                // un rafraîchissement raté ne vide pas l'écran : on garde les dernières valeurs
                if (!this.loaded) this.loadError = "Accueil indisponible. Vérifiez la connexion puis rechargez la page.";
            } finally {
                this.inFlight = false;
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
