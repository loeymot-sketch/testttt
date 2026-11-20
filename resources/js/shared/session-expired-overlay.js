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
let _minuteur = null;
let _veille = null;

const surPageDeConnexion = () => typeof window !== 'undefined' && /^\/(login|admin\/login)(\/|$)/.test(window.location.pathname);

/**
 * [GARDE DE CONNEXION 2026-10-19 · owner « je rafraîchis plusieurs fois pour que ça fonctionne »] Cet écran était
 * POSÉ et jamais ENLEVÉ : la navigation vers la connexion (router.push) échouait en silence sur l'écran cuisine
 * et l'écran restait figé sur « Session expirée — reconnexion… » (reproduit au navigateur). Désormais :
 *  - un bouton « Se reconnecter » toujours là ;
 *  - un repli AUTOMATIQUE : si au bout de 4 s on n'est pas sur la page de connexion, redirection franche (rechargement) ;
 *  - l'écran s'efface seul dès qu'on est sur la page de connexion (navigation SPA réussie).
 */
export function showSessionExpiredOverlay(message = 'Session expirée — reconnexion…', options = {}) {
    if (_shown || typeof document === 'undefined') return;
    _shown = true;

    const aller = options.aller || (() => { window.location.assign('/login'); });
    const delai = Number.isFinite(options.delaiMs) ? options.delaiMs : 4000;

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
    const bouton = document.createElement('button');
    bouton.type = 'button';
    bouton.setAttribute('data-testid', 'session-expired-reconnect');
    bouton.textContent = 'Se reconnecter';
    bouton.style.cssText = 'margin-top:8px;background:#F4501E;color:#fff;border:none;border-radius:8px;padding:12px 22px;font-size:18px;font-weight:700;cursor:pointer';
    bouton.addEventListener('click', () => aller());
    overlay.appendChild(bouton);
    document.body.appendChild(overlay);

    const retirer = () => {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        if (_minuteur) clearTimeout(_minuteur);
        if (_veille) clearInterval(_veille);
        _minuteur = null;
        _veille = null;
        _shown = false;
    };

    // Navigation SPA réussie vers la connexion : l'écran sombre n'a plus de raison d'être.
    _veille = setInterval(() => { if (surPageDeConnexion()) retirer(); }, 300);
    // Navigation restée sans effet : on force.
    _minuteur = setTimeout(() => { if (!surPageDeConnexion()) aller(); }, delai);
}
