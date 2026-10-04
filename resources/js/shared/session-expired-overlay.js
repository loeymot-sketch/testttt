/**
 * [SESSION-EXPIRED-OVERLAY 2026-09-23] Audit "Plan de correction complet"
 * findings #10/#11 : un jeton expiré/révoqué en cours de session produit un
 * BURST de 401 sur toutes les requêtes déjà en vol (confirmé en prod, nginx
 * access.log 23/09 15:12:22-24 — /admin/pos-v4, 12+ appels 401 en ~2s, y
 * compris /api/auth/logout). Le handler 401 existant (app.js/pos-app.js)
 * déclenche déjà la redirection dès le PREMIER 401 détecté — mais entre cet
 * instant et le rechargement/la navigation réelle, les autres requêtes déjà
 * en vol continuent d'échouer et leurs composants respectifs retombent sur
 * leur état "aucune donnée"/"chargement infini" local, TOUS EN MÊME TEMPS —
 * exactement le symptôme observé (Stock/Vue caisse bloqués, Historique/
 * Transactions "Aucune donnée disponible").
 *
 * Ceci n'est PAS un bug d'authentification (la redirection fonctionne — les
 * logs post-incident ne montrent plus aucun 401) : c'est l'ABSENCE d'un état
 * visuel explicite pendant cette fenêtre transitoire. Cette fonction injecte
 * un overlay DOM PUR (aucune dépendance Vue/Vuex, qui peuvent être dans un
 * état incohérent au moment même où l'auth casse) dès le premier 401, avant
 * même la redirection — pour que l'écran dise honnêtement "session expirée,
 * reconnexion" au lieu de laisser chaque widget afficher son propre zéro.
 */
let _shown = false;

export function showSessionExpiredOverlay(message = 'Session expirée — reconnexion…') {
    if (_shown || typeof document === 'undefined') return;
    _shown = true;

    const overlay = document.createElement('div');
    overlay.setAttribute('role', 'alert');
    overlay.setAttribute('data-testid', 'session-expired-overlay');
    overlay.style.cssText = [
        'position:fixed', 'inset:0', 'z-index:2147483647',
        'background:rgba(17,17,17,0.92)', 'color:#fff',
        'display:flex', 'flex-direction:column', 'align-items:center', 'justify-content:center',
        'font-family:system-ui,-apple-system,sans-serif', 'font-size:18px', 'font-weight:600',
        'text-align:center', 'padding:24px', 'gap:12px',
    ].join(';');
    overlay.innerHTML = `
        <div style="font-size:32px;">🔒</div>
        <div>${message}</div>
    `;
    document.body.appendChild(overlay);
}
