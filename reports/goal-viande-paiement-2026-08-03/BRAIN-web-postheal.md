# BRAIN — Audit READ-ONLY post-heal web (état FINAL) — 2026-08-03

Repo : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`
Commits audités :
- `93f5d9e` heal(RED) : sync isAuth après OTP inline + garde isAuthed + champs Mollie cachés + min:2 noms
- `e7d14a1` feat(commander) : page UNIQUE (fusion CheckoutPage→PaymentPage)

Working tree : 2 modifs NON commitées, hors périmètre des heals — `legal/cgv.html` + `screens-v3.jsx`
(seuil fidélité 50→100 pts, contenu texte uniquement, transforme Babel OK).

## Verdicts par heal

### Heal 1 — funnel.jsx verifyOtp garde isAuthed + onAuthedInline → **SAFE**
- Flux tracé : `verifyOtp` (funnel.jsx:703) → `await api.guestVerify(...)` (717) →
  api.js:228-234 : `if (r && r.token) { setToken(r.token); ... }` (synchrone, localStorage) →
  garde funnel.jsx:731 `if (!(api && api.isAuthed && api.isAuthed())) { setAuthErr(...); return; }` →
  `isAuthed()` = `!!getToken()` (api.js:237) → true → `setAuthStep('done')` (732) →
  badge « ✓ Coordonnées confirmées » rendu à funnel.jsx:775-778. Le badge s'affiche bien après un verify OK.
- Si le backend répond 200 SANS token (anormal) : badge bloqué + « Connexion non établie. Réessaie. » — comportement voulu du heal.
- `onAuthedInline` : PaymentPage n'a **qu'un seul call-site** — index.html:403, prop passée
  (`onAuthedInline={()=>setIsAuth(true)}`). Aucun autre `<PaymentPage` ni `<CheckoutPage` dans le repo.
  Et l'appel est gardé `typeof onAuthedInline === 'function'` (funnel.jsx:735) → un rendu sans la prop ne casse rien.
- Effet parent : `setIsAuth(true)` → le bloc « Compte (recommandé) » (funnel.jsx:948 `{!isAuth && ...}`)
  disparaît sans démonter PaymentPage (même view) → `authStep` reste 'done', badge conservé.
- Edge résiduel (P3, pré-existant) : localStorage indisponible (Safari private strict) → `setToken` avale
  l'exception → boucle « Connexion non établie » ; comportement identique avant heal (commande aurait
  échoué au submit) ; hors périmètre.

### Heal 2 — Champs Mollie cachés pas détruits → **SAFE**
- funnel.jsx:864-907 : `{onlineCard && (<div className="lcf-cardform" style={ctx.method === 'card' ? undefined : { display: 'none' }}>` — JSX équilibré (div fermée 906, bloc fermé 907), preuve :
  Babel transforme funnel.jsx sans erreur (voir §Transform).
- `onlineCard=false` (prod actuelle : `mollieProfileId` vide → funnel.jsx:515-516) ⇒ le bloc entier
  n'est PAS rendu (aucun DOM carte), et le useEffect (528-529) early-return sur `!onlineCard`. Rien ne change en prod.
- useEffect montage (funnel.jsx:528-561, deps `[onlineCard, ctx.method, mollieProfileId]`) :
  skip tant que `ctx.method !== 'card'` ; au toggle vers card → mount une seule fois (`mollieRef.current`
  idempotent) ; retour comptoir → conteneur seulement `display:none` → iframes survivent ; re-toggle carte →
  ref déjà posée, pas de re-mount → exactement le bug pré-existant (6858540) corrigé. Cleanup `cancelled`
  couvre le script Mollie en vol pendant un toggle.
- a11y : les `label htmlFor` (876/880/885/889) pointent toujours les mêmes divs hôtes
  `#lc-mollie-*` — INCHANGÉ par le heal (le diff ne touche que la ligne wrapper 864-865).
  Note pré-existante (P3) : `htmlFor` vers un div n'est pas un élément labelable — présent depuis
  le commit « paiement dans la page » 2026-08-01, pas introduit ici.

### Heal 3 — account-v2.jsx min:2 prénom/nom → **SAFE**
- Affichage : bloc prénom/nom rendu à account-v2.jsx:169 gaté `(mode === 'signup' || mode === 'login')` —
  exactement le même gating que la validation lignes 57-58 → les champs sont visibles dans les deux modes,
  aucune validation d'un champ invisible.
- Cohérence messages : « Prénom requis (2 caractères min) » / « Nom requis (2 caractères min) » = règle
  `trim().length < 2`. Parité backend prouvée : `app/Http/Requests/GuestSignupEmailOtpRequest.php:34-35`
  (`first_name`/`last_name` `min:2|max:100`). Parité funnel inline aussi (funnel.jsx:696-697, `< 2`).
- Cosmétique pré-existant (P3) : erreurs affichées dans l'ordre last puis first (lignes 175-176).

### Check 4 — Transform Babel → **SAFE**
- `@babel/standalone` (scratchpad node_modules), presets env+react :
  `OK funnel.jsx · OK account-v2.jsx · OK screens-v3.jsx · OK index.html inline #1` — exit 0.

### Check 5 — Références CheckoutPage + orphelins → **SAFE (1 P3)**
- `CheckoutPage` : seule occurrence exécutable = l'alias volontaire funnel.jsx:1270
  (`Object.assign(window, { ..., CheckoutPage: PaymentPage, ... })`). Aucun `<CheckoutPage` /
  `CheckoutPage(` dans le code. Les autres hits sont des commentaires (funnel.jsx:167/243, index.html:354-355 —
  commentaire périmé « CheckoutPage.applyPromo », inoffensif).
- Orphelin détecté (P3, introduit par la feature 2026-08-01, pas par la fusion) : état
  `cardFieldsReady` (funnel.jsx:475) — SET à 545, jamais LU. Dead state, 1 re-render superflu au mount
  Mollie. Candidat nettoyage, zéro risque.
- `ctx.paidOnline` / `ctx.cardFallback` (P1 de e7d14a1) : bien écrits (628/640) ET lus par la
  confirmation (1018-1028) — pas d'orphelin.

### Check 6 — Serveur 127.0.0.1:8899 → **SAFE**
- HTTP 200 ; `index.html` servi == disque (diff vide) ; `funnel.jsx` servi == disque.
- Cache-bust : 24/24 assets versionnés `?v=20260803a` (25 occurrences totales), **aucun** asset
  sur une version antérieure.
- Aucun marqueur de conflit `<<<<<<<`/`>>>>>>>` dans funnel.jsx / account-v2.jsx / index.html / screens-v3.jsx.

## Verdict global : **SAFE** (0 P0, 0 P1)
P3 informatifs : `cardFieldsReady` dead state · htmlFor→div Mollie (pré-existant 08-01) ·
ordre erreurs last/first account-v2 · edge localStorage-off · commentaire périmé index.html:354.
Working tree : 2 fichiers modifiés non commités (seuil 50→100 pts) à commiter/écarter par l'owner.
