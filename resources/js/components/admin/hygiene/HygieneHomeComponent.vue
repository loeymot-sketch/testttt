<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Cayenne Hygiène — {{ todayLabel }}</h3>
            </div>

            <div class="db-card-body">
                <p v-if="loadError" class="hy-alert" role="alert" data-testid="home-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <p class="hy-banner" :class="`is-${bannerKind}`" role="status" data-testid="home-banner">{{ bannerText }}</p>

                    <!-- Ce qui est en retard ou à traiter MAINTENANT : une phrase, un geste (touchez pour y aller) -->
                    <section v-if="reminders.length" class="hy-reminders" aria-labelledby="home-reminders-title" data-testid="home-reminders">
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

export default {
    name: "HygieneHomeComponent",
    data() {
        return { tiles: [], meta: { alerts: 0, todo: 0, reminders: [], server_now: null, timezone: "Europe/Paris" }, loaded: false, loadError: "", timer: null };
    },
    computed: {
        /** Les rappels du serveur : alertes d'abord, puis le reste à faire. */
        reminders() {
            const rank = { alert: 0, todo: 1 };
            return [...(this.meta.reminders || [])].sort((a, b) => (rank[a.level] ?? 2) - (rank[b.level] ?? 2));
        },
        todayLabel() { return formatDay(this.meta.server_now || new Date().toISOString(), this.meta.timezone); },
        /** Domaines pas encore configurés (aucun frigo, aucun plan…) : « tout est en ordre » serait faux tant qu'il en reste. */
        unconfigured() { return this.tiles.filter((t) => t.status === "empty"); },
        bannerKind() {
            if (this.meta.alerts > 0) return "alert";
            return this.meta.todo > 0 || this.unconfigured.length > 0 ? "todo" : "ok";
        },
        bannerText() {
            const a = this.meta.alerts;
            const t = this.meta.todo;
            // a et t comptent des DOMAINES (tuiles), pas des tâches : le dire, sinon « 1 chose à faire » contredit « 5 à faire » sur la tuile
            if (a > 0) return `${a} domaine${a > 1 ? "s" : ""} en alerte à traiter${t > 0 ? ` · ${t} autre${t > 1 ? "s" : ""} à compléter` : ""}`;
            if (t > 0) return `${t} domaine${t > 1 ? "s" : ""} à compléter aujourd'hui`;
            if (this.tiles.length && this.unconfigured.length === this.tiles.length) return "Commencez par configurer un domaine : touchez une tuile.";
            if (this.unconfigured.length) return `Rien d'urgent, mais à configurer : ${this.unconfigured.map((u) => u.label).join(", ")}.`;
            return "Tout est en ordre ✓";
        },
    },
    created() {
        this.load();
        this.timer = setInterval(() => this.load(), 60000);
    },
    beforeUnmount() { if (this.timer) clearInterval(this.timer); },
    methods: {
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/overview");
                this.tiles = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                // un rafraîchissement raté ne vide pas l'écran : on garde les dernières valeurs
                if (!this.loaded) this.loadError = "Accueil indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
