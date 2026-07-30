# Abuse ROBUSTESSE + RENDU + A11Y — Site Le Cayenne (2026-07-30)

Cible : `http://127.0.0.1:8899` (React SPA hash-routée + pages légales statiques).
Scripts : `/tmp/abuse-{viewports,network,chaos,verify,modals,cdn}.js` · Captures : `/tmp/robust-shots/` (62).
**P0 = 0 · P1 = 2 · P2 = 4.** Discipline verify-before-report appliquée : 5 faux positifs réfutés.

## Ce qui TIENT (prouvé, positif)
- **Zéro overflow horizontal du document** à 280 / 320 / 1920 / paysage(812) sur home, menu, orders, loyalty + 5 pages légales. Cartes, titres, CTA wrappent proprement à 280px (`vp280-menu.png`, `vp280-home.png`).
- **Zéro label brut** (undefined / NaN / null / 0€ faux / kiosk.x / [object Object]) sur toutes les pages et modales.
- **XSS échappé** : 5 payloads (`<img onerror>`, `<svg onload>`, `<script>`, `<b>`) dans la recherche → aucun dialog, aucun nœud DOM vivant, layout intact (`chaos-xss-search.png`).
- **États vides soignés** : recherche 0 résultat → carte « Rien trouvé » + « RÉINITIALISER » (`chaos-empty-search.png`) ; panier vide OK.
- **Nav chaos survit** : back/forward en rafale (8+8), 10 modales d'affilée, deep-links `#checkout/#payment/#confirm/#track` panier vide → redirigés vers menu/home (garde ghost-ticket), aucun crash.
- **Modales détail/wizard/compte** : plein écran net à 320px, focus-trap Tab RÉEL + Escape + restauration focus (`WebModal` components.jsx:239-279 ; `modal-detail-320.png`, `modal-wizard-320.png`).
- **Console propre** sur toutes les routes (hors bruit vendor). Desktop 1920 = conteneur centré, pas d'étirement (`vp1920-home.png`).

## P1 — à corriger avant go-live
**P1-1 · CDN = point de défaillance unique (site mort).** React/ReactDOM/Babel/fonts chargés depuis `unpkg.com` + `gstatic`. En bloquant CES SEULS hôtes (localhost intact), l'app ne monte JAMAIS : `React` undefined, écran figé sur « CHARGEMENT… » à l'infini, sans message ni repli. Repro : `/tmp/abuse-cdn.js` → `net-cdn-blocked.png`. Impact réel : panne unpkg, proxy d'entreprise/école, bloqueur de pub, filtrage opérateur → **tout le site tombe**. Correctif : self-host React+ReactDOM+Babel (ou pré-transpiler + bundler), + Service Worker de repli.

**P1-2 · Premier rendu ~24–33 s sur 3G.** Même racine : téléchargement React + Babel-standalone (~3 Mo) puis transpilation JSX de 9 fichiers AU RUNTIME dans le navigateur. Mesuré : mount ≈ **33 428 ms** sur throttling 3G (`/tmp/abuse-network.log`, `net-3g-menu.png`). L'utilisateur fixe « Chargement… » ~30 s → abandon. Correctif : build de production (pré-transpilation, code-split), supprimer babel-standalone du chemin critique.

## P2 — a11y / robustesse
**P2-1 · Anneau de focus supprimé** sur l'input de recherche, les onglets catégorie (`.lc-cat-tab`) et le bouton « Se connecter ». Ces éléments matchent `:focus-visible` mais `outline-width = 0` (override local `outline:0` — styles-v2.css:244, styles-v5.css:104) bat la règle globale styles.css:114. Prouvé : `/tmp/abuse-verify.log` (4/8 stops sans anneau). WCAG 2.4.7. NB : la règle globale fonctionne ailleurs (croix de modale = anneau orange visible).

**P2-2 · Tiroir panier sans focus-trap.** `CartDrawer` (flows.jsx:66) est `role="dialog" aria-modal="true"` mais Tab s'échappe vers la page derrière (15/15 stops sortis, arrière-plan non-inert). Escape + focus-in + restauration fonctionnent ; seul le piège Tab manque (contrairement à `WebModal`). WCAG 2.4.3.

**P2-3 · Page « orders » sans `<h1>`** (4 viewports). Structure de titres incomplète. WCAG 1.3.1.

**P2-4 · Pas de 404 de marque / fallback SPA.** `vercel.json` sans rewrite → chemin inconnu = 404 générique (serveur dev : page Python brute ; prod : 404 Vercel). Impact faible (routing par hash, la vraie nav reste sur `/`).

## Faux positifs RÉFUTÉS (ne pas remonter)
1. « Fermer le panier » hors écran → tiroir FERMÉ mis `inert` (flows.jsx:26), hors ordre de tabulation. OK.
2. MODAL-OVERFLOW détail 280/320 → c'était le tiroir panier fermé (role=dialog toujours rendu, translaté hors-champ). OK.
3. Cart « ESC-NOOP » (chaos) → état sali par le spam 10 modales ; re-test propre : **Escape ferme + rend le focus** (`/tmp/abuse-verify.log`). OK.
4. Chip catégorie « Bols » hors écran → rail horizontal scrollable intentionnel (document sans overflow). OK.
5. « Offline 1er chargement blanc » → Playwright bloque aussi le fetch HTML ; vrai enjeu couvert par P1-1.
