/**
 * kioskThemeManager.js — [Wave Gamma G3 / V2-5] Skinning saisonnier kiosk.
 * -----------------------------------------------------------------------------
 *
 * Service en charge d'appliquer le thème visuel actif au kiosk au boot, sans
 * recompile et sans toucher aux composants Vue (notamment les 8 wizard frozen,
 * KioskApp ou KioskPayment). Le mécanisme repose sur un seul attribut HTML :
 *
 *   document.documentElement.setAttribute('data-kiosk-theme', 'halloween');
 *
 * Les fichiers `resources/css/kiosk/themes/<slug>.css` (chargés via
 * `bootstrap-kiosk.js` Phase 2+) déclarent leurs overrides sous le sélecteur
 * `:root[data-kiosk-theme="<slug>"]`. Aucun thème n'a d'effet tant que
 * l'attribut n'est pas posé : la marque rouge FoodKing reste le défaut.
 *
 * Priorité de résolution au boot (cf. plan V2-5 §architecture) :
 *
 *   1. localStorage (`kiosk_active_theme`)  — sticky côté borne, survit aux
 *      restart Electron, indépendant du réseau.
 *   2. Backend GET /api/admin/kiosk-theme/{branchId} — source de vérité
 *      poussée par l'admin via PATCH du même endpoint.
 *   3. `'standard'` — fallback marque FoodKing.
 *
 * ⚠ Limite connue (V2 — non bloquant V1) : un kiosk déjà boot avec un thème
 *   stale dans localStorage ne picke pas immédiatement le changement admin.
 *   Le refresh se fait au prochain restart kiosk (typiquement la nuit) ou
 *   via un appel explicite à `forceRefreshFromBackend()`. La V2 brancherait
 *   un push WebSocket (déjà disponible via Pusher / Echo) pour invalider la
 *   clé localStorage et rappliquer en live.
 *
 * Stub-mode permissif : tout `try/catch` autour de localStorage et axios
 *   garantit qu'un environnement sans `window.localStorage` (SSR, vitest) ou
 *   sans `window.axios` (boot pre-login) ne fait pas crasher le kiosk —
 *   on tombe sur le fallback `'standard'`.
 *
 * @see resources/css/kiosk/themes/_base.css        (contract)
 * @see resources/css/kiosk/themes/halloween.css    (example)
 * @see app/Http/Controllers/Admin/KioskThemeController.php
 * @see plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md
 * @see docs/KIOSK_THEMES.md
 */

const STORAGE_KEY = 'kiosk_active_theme';

/**
 * Liste blanche des slugs de thème reconnus côté JS.
 *
 * La même liste DOIT être maintenue côté backend (validation `in:` du
 * `KioskThemeController::update()`) — sinon un thème poussé par l'admin
 * ne sera jamais appliqué côté borne. Pour ajouter un thème, suivre la
 * procédure documentée dans `docs/KIOSK_THEMES.md` (3 étapes).
 *
 * @type {ReadonlyArray<string>}
 */
export const SUPPORTED_THEMES = Object.freeze([
    'standard',
    'halloween',
    'christmas',
]);

const DEFAULT_THEME = 'standard';

export class KioskThemeManager {
    constructor() {
        this.activeTheme = DEFAULT_THEME;
    }

    /**
     * Vrai si `theme` figure dans la liste blanche `SUPPORTED_THEMES`.
     */
    isSupportedTheme(theme) {
        return typeof theme === 'string' && SUPPORTED_THEMES.includes(theme);
    }

    /**
     * Pose l'attribut `data-kiosk-theme` sur `<html>` ; un thème inconnu
     * retombe silencieusement sur `'standard'` après un warning console.
     * Aucun effet de bord serveur ici (pas de fetch, pas de persist).
     */
    apply(theme) {
        let resolved = theme;
        if (!this.isSupportedTheme(resolved)) {
            // eslint-disable-next-line no-console
            console.warn(
                `[ThemeManager] Unknown theme "${resolved}", falling back to "${DEFAULT_THEME}"`
            );
            resolved = DEFAULT_THEME;
        }
        if (typeof document !== 'undefined' && document.documentElement) {
            document.documentElement.setAttribute('data-kiosk-theme', resolved);
        }
        this.activeTheme = resolved;
        return resolved;
    }

    /**
     * Persiste le thème dans localStorage (best-effort, jamais bloquant).
     * Échoue silencieusement en environnement Safari Privé / SSR / vitest
     * sans `localStorage` initialisé.
     */
    persist(theme) {
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEY, theme);
            }
        } catch (_) {
            // intentionally silent — persistence is non-critical.
        }
    }

    /**
     * Lit le thème depuis localStorage, retourne `null` si indisponible
     * ou inconnu (la valeur stale d'un thème supprimé doit retomber sur
     * la chaîne backend → fallback, jamais être appliquée telle quelle).
     */
    load() {
        try {
            if (typeof localStorage === 'undefined') return null;
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw && this.isSupportedTheme(raw)) return raw;
            return null;
        } catch (_) {
            return null;
        }
    }

    /**
     * Récupère le thème actif côté backend pour la branch courante.
     *
     * Endpoint public-read par design (kiosks récupèrent leur skin AVANT
     * d'avoir un token sanctum si nécessaire ; cf. §3 du plan V2-5).
     * Retourne `null` en cas d'erreur réseau — le caller fallback
     * généralement sur localStorage déjà tenté ou sur `'standard'`.
     */
    async fetchActiveBranchTheme(branchId) {
        if (!branchId) return null;
        try {
            if (typeof window === 'undefined' || !window.axios) return null;
            const response = await window.axios.get(
                `/api/admin/kiosk-theme/${branchId}`
            );
            const theme = response?.data?.active_theme;
            return this.isSupportedTheme(theme) ? theme : null;
        } catch (_) {
            return null;
        }
    }

    /**
     * Pipeline de boot : résout le thème selon la priorité documentée
     * (localStorage > backend > default), l'applique et le retourne.
     *
     * Note : on ne persist PAS le résultat du backend dans localStorage
     * automatiquement. Le seul moment où on écrit dans localStorage est
     * une action explicite via `setTheme()`. Sinon un kiosk reseté
     * (clear cache) ne pourrait jamais re-récupérer un nouveau thème
     * poussé par l'admin (la stale localStorage gagnerait à vie).
     */
    async initialize(branchId = null) {
        let theme = this.load();
        if (!theme) {
            theme = await this.fetchActiveBranchTheme(branchId);
        }
        if (!this.isSupportedTheme(theme)) {
            theme = DEFAULT_THEME;
        }
        return this.apply(theme);
    }

    /**
     * Setter explicite (admin debug / preview / mode prévisualisation) :
     * applique + persiste. À utiliser quand l'utilisateur opère un choix
     * intentionnel, pas pour la propagation automatique d'un push admin.
     */
    setTheme(theme) {
        const applied = this.apply(theme);
        this.persist(applied);
        return applied;
    }

    /**
     * Force-refresh : ignore le cache localStorage et re-récupère depuis
     * le backend. Utile pour V2 quand on branchera une notification
     * Pusher du type "theme_changed" → ce hook fait le boulot côté JS.
     */
    async forceRefreshFromBackend(branchId) {
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (_) {
            /* silent */
        }
        return this.initialize(branchId);
    }
}

const themeManager = new KioskThemeManager();
export default themeManager;
