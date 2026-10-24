// [CAYENNE HYGIÈNE 2026-10-17] Une tablette reste ouverte toute la journée : sans rafraîchissement, le moment de la journée (matin → soir à 15 h),
// les relevés faits par un autre poste et les alertes restent figés. Mixin : recharge toutes les 5 minutes et au retour sur l'onglet (après
// 1 minute d'absence) — SAUF si la personne est en train de saisir (`hyDraft()`), pour ne jamais lui voler sa saisie.
// Le composant définit `hyReload()` (recharger) et, s'il a une saisie en cours, `hyDraft()`.
export default {
    created() {
        this.hyLast = Date.now();
        this.hyOnVisible = () => {
            if (typeof document !== "undefined" && document.visibilityState === "visible" && Date.now() - this.hyLast > 60000) this.hyAutoRefresh();
        };
        if (typeof document !== "undefined") document.addEventListener("visibilitychange", this.hyOnVisible);
        this.hyTimer = setInterval(() => { if (Date.now() - this.hyLast >= 300000) this.hyAutoRefresh(); }, 60000);
    },
    beforeUnmount() {
        if (this.hyTimer) clearInterval(this.hyTimer);
        if (typeof document !== "undefined" && this.hyOnVisible) document.removeEventListener("visibilitychange", this.hyOnVisible);
    },
    methods: {
        hyAutoRefresh() {
            this.hyLast = Date.now();
            if (typeof this.hyDraft === "function" && this.hyDraft()) return;
            if (typeof this.hyReload === "function") this.hyReload();
        },
    },
};
