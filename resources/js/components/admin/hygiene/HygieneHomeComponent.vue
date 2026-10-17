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

                    <div class="hy-tiles">
                        <router-link v-for="t in tiles" :key="t.key" :to="t.route" class="hy-tile" :class="`is-${t.status}`" :data-testid="`home-tile-${t.key}`">
                            <span class="hy-tile-icon" aria-hidden="true"><i :class="t.icon"></i></span>
                            <span class="hy-tile-label">{{ t.label }}</span>
                            <strong class="hy-tile-headline" :data-testid="`home-headline-${t.key}`">{{ t.headline }}</strong>
                            <span v-if="t.detail" class="hy-tile-detail" :data-testid="`home-detail-${t.key}`">{{ t.detail }}</span>
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
        return { tiles: [], meta: { alerts: 0, todo: 0, server_now: null, timezone: "Europe/Paris" }, loaded: false, loadError: "", timer: null };
    },
    computed: {
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
            if (a > 0) return `${a} alerte${a > 1 ? "s" : ""} à traiter${t > 0 ? ` · ${t} chose${t > 1 ? "s" : ""} à faire` : ""}`;
            if (t > 0) return `${t} chose${t > 1 ? "s" : ""} à faire aujourd'hui`;
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
