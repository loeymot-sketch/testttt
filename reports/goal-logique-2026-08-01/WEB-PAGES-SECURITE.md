# Audit Sécurité + Parcours — Site Client Le Cayenne

**Date** : 2026-08-01/02 · **Auditeur** : session /goal logique
**Cibles** : site statique local `http://127.0.0.1:8899` (source `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`), prod `https://www.lecayenne.fr` (Vercel), API `https://vps-418872ac.vps.ovh.net` (OVH/nginx/Laravel).
**Méthode** : inventaire par grep du routage réel + 2 sous-agents lecture-seule (api.js / XSS-legal-secrets) + probes live curl + parcours Playwright + test XSS live. Chaque finding = `file:line` OU requête reproductible + réponse observée. Aucun code applicatif modifié.

---

## 1. Inventaire des pages atteignables par le client

Le site est un **SPA mono-fichier** (`index.html`) à routes-hash + **5 pages légales statiques**. Routage réel : `index.html:136-251` (RESTORE_ROUTES + popstate).

| # | Page / route | Type | Accès | Garde cold-load (accès direct URL) | Rendu |
|---|---|---|---|---|---|
| 1 | `#home` (défaut) | Vue SPA | public | — | OK |
| 2 | `#menu` | Vue SPA | public | restaurée (RESTORE_ROUTES) | OK |
| 3 | `#orders` | Vue SPA | public (empty-state si non connecté) | restaurée → empty-state « Connecte-toi » | OK, aucune fuite |
| 4 | `#loyalty` | Vue SPA | public (empty-state) | restaurée → empty-state « Connecte-toi » | OK, aucune fuite |
| 5 | `#checkout` | Vue SPA | requiert panier | **redirige → #menu si panier vide** (`index.html:239`) | garde OK |
| 6 | `#payment` | Vue SPA | requiert panier | **redirige → #menu si panier vide** | garde OK |
| 7 | `#confirm` | Vue SPA | requiert commande en ctx | **redirige → #home si pas de commande** (`index.html:357-363`) | garde OK, pas de ticket fantôme |
| 8 | `#track` | Vue SPA | requiert commande en ctx | **redirige → #home si pas de commande** | garde OK |
| 9 | `#account` (modale) | Modale SPA | public (login/signup OTP) | non routable en cold-load (modale) → #home | OK |
| 10 | `legal/allergens.html` | Page statique | public | autonome | OK |
| 11 | `legal/cgv.html` | Page statique | public | autonome | OK |
| 12 | `legal/cookies.html` | Page statique | public | autonome | OK |
| 13 | `legal/mentions.html` | Page statique | public | autonome | OK |
| 14 | `legal/privacy.html` | Page statique | public | autonome | OK |

**Total : 14 pages/vues client** (9 vues SPA + 5 légales).

### Fichiers servis publiquement mais NON-pages (surface exposée)
`vercel.json:5` `outputDirectory:"."` + `.vercelignore` n'exclut QUE `tests-e2e` → sont accessibles par URL : `tools/generate-menu-from-api.mjs`, `tools/README-generate-menu.md`, `VERCEL_DEPLOY.md`, `data/menu.js`, `data/loyalty.js`, `vendor/qrcode.js`, tous les `*.jsx` sources et `styles*.css`. (Voir P2-6.)

---

## 2. Résultats PARCOURS (Playwright, cold-load direct URL de chaque route)

Chaque route testée en accès direct sans session (`reports/goal-logique-2026-08-01/shots/parcours-*.png`) :
- `#menu`, `#home` : rendu OK, 0 erreur console.
- `#orders`, `#loyalty` : empty-state propre « Connecte-toi pour… » + CTA (aucune donnée d'autrui). Capture `parcours-orders.png`.
- `#checkout`, `#payment` : **redirigent vers #menu** (panier vide) — garde effective.
- `#confirm`, `#track`, `#account` : **redirigent vers #home** — pas de ticket/QR fantôme, pas de données commande sans contexte.
- Retour arrière (popstate) : ferme d'abord la modale puis navigue dans le site ; gardes panier/commande ré-appliquées au rendu (`index.html:229-251`, `:357-363`).

**Verdict parcours** : toutes les vues s'affichent correctement, empty-states propres, **toutes les gardes cold-load fonctionnent** — aucun écran protégé n'est atteignable sans son contexte.

---

## 3. Résultats SÉCURITÉ (les 7 axes du mandat)

### Axe 1 — Accès direct écrans protégés → **AUCUNE FUITE**
`#confirm`/`#track` redirigent à #home sans commande en ctx ; `#orders`/`#loyalty` affichent un empty-state ; `#account` est une modale non deep-linkable. Preuve : parcours §2.

### Axe 2 — IDOR → **AIRTIGHT (bloqué serveur, prouvé)**
Le client appelle `/api/frontend/order/show/{id}` avec un **id = clé primaire DB séquentielle** (`api.js:671-674`, `orders.jsx:17-22`) et sans contrôle de propriété côté client. **MAIS le backend refuse** :
- `order/show/{frontendOrder}` → `FrontendOrderService.php:754-756` : `if ((int)$frontendOrder->user_id !== (int)Auth::id()) abort(403)`.
- `order/{id}/mollie-checkout` → `MolliePaymentController.php:50-51` : 403 si non-propriétaire.
- `order/change-status/{id}` → `OrderController.php:165-175` : filtre `where('user_id', $authenticatedUserId)`.
- `order/show/{id}/escpos` (ticket) → `OrderController.php:119-121` : 403 si non-propriétaire.

Preuve live : `GET /api/frontend/order/show/1` → **401** sans token, **401** avec Bearer forgé. (Test propriétaire-vs-propriétaire impossible sans 2 vrais tokens OTP, mais la garde source est explicite et unitairement couverte — heal `FRONT-SHOW-403-422`.)

### Axe 3 — Données sensibles côté client → **1 placeholder faible, 0 secret live**
- `<meta name="api-key">` servie **en prod** = `change-me-long-random-string-local-dev` (`index.html:18`, confirmé sur `www.lecayenne.fr`). Clé front PUBLIQUE par nature ; ne déverrouille QUE les endpoints publics (menu/loyalty-config/wait-estimate). **Sans elle → 400 ; fausse → 400 ; profil/historique/commande exigent le Bearer (401 sans).** → **P2-2**.
- Fallback codée en dur `b6d68…3453q120` dans `api.js:21` et `tools/generate-menu-from-api.mjs:47`. → **P3-7 / P2-6**.
- **0 secret réel** dans tout l'arbre servi : `sk_live|sk_test|AKIA|aws_secret|PRIVATE KEY|SMTP|@gmail…` → 0 hit. `data/menu.js`, `data/loyalty.js`, `vendor/qrcode.js` = clean.

### Axe 4 — XSS → **AUCUN exploitable (prouvé statique + live)**
- 0 `innerHTML|outerHTML|document.write|insertAdjacentHTML|eval|new Function` dans tout le source servi.
- 2 seuls `dangerouslySetInnerHTML` (`components.jsx:83`, `funnel.jsx:57`) = SVG QR généré **localement**, géométrie seule (`vendor/qrcode.js:525-536`, texte échappé + non transmis) → non-exploitable même avec réponse serveur hostile.
- Toute saisie utilisateur ré-entre via `value={}` (propriété DOM, pas de parsing HTML) ou interpolation texte React (auto-échappée).
- **Test LIVE** : note cuisine remplie avec `<img src=x onerror="window.__XSS_FIRED=1"><b id="xsspwn">PWNMARK</b>` → `xssFired:false`, aucun élément `<b id=xsspwn>` créé, aucune img : la charge reste **texte inerte** dans le champ. La note part au SERVEUR (ticket cuisine ESC/POS), jamais rendue en HTML navigateur.

### Axe 5 — Stockage local → **token + téléphone effacés au logout ; résidus faible-PII**
Clés écrites : `lecayenne.authToken` (token Sanctum, plaintext, `api.js:148-151`), `lecayenne.authPhone` (`api.js:149-153`), `lc.cart.v1` (panier + note cuisine, TTL 24 h `index.html:125`), `lc.mollie.pending` (sessionStorage : id/serial/total commande), `lc.funnel.idem`, `lecayenne.notifPrefs`.
- `logout()` (`api.js:224`) **efface bien token + téléphone**. Vérifié.
- **Pas de révocation serveur** au logout (pas d'appel `/logout` depuis ce chemin) → un token déjà copié/synchronisé reste valide **jusqu'au TTL = 8 h** (`config/sanctum.php:51` = 480 min ; le commentaire api.js « 30 j » est PÉRIMÉ). → **P2-3**.
- `isAuthed()` = simple présence de string (`api.js:223`) : forgeable, mais **client-view-only, fail-closed** (tout appel authed → 401 → purge `api.js:193-196`) — aucune donnée serveur exposée.
- Survivent au logout : panier (dont note texte libre), `lc.mollie.pending` (id/total commande) → faible PII. → **P3-5**.

### Axe 6 — Headers de sécurité
- **Site Vercel** (`www.lecayenne.fr`) : `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security: max-age=63072000` présents (`vercel.json` + HSTS Vercel). **Manque : Content-Security-Policy** (aucune). → **P2-5**.
- **API OVH** : cert TLS **valide** (200 sans `-k`). CSP en **`Content-Security-Policy-Report-Only`** (n'applique rien) ; **pas de HSTS** sur les réponses API. `X-Frame-Options`/`X-Content-Type` présents. → **P2-5**.

### Axe 7 — Auth / OTP → **AIRTIGHT**
- Envoi OTP throttlé **5/min** (`routes/api.php:209-216` `throttle:otp-send`) — live : 6ᵉ requête → **429**.
- Verify OTP throttlé **3/5 min** (`routes/api.php:219`) — live : 3ᵉ tentative → **429**. Code **4 chiffres, usage unique, expire 5 min** → brute-force local infaisable.
- **0 fuite `dev_code`** en prod : `POST /otp` → `{"status":true,"message":"…"}` (le hint `?dev` est inerte car le serveur n'émet pas `dev_code`).
- Coupon-checking throttlé **10/min/IP** (`routes/api.php:1518`), loyalty/register **5/min** (`routes/api.php:1558`) → pas d'oracle non-throttlé.
- Réserve : throttle verify **par IP** (guest non-authed) + code 4 chiffres → brute-force **distribué** (rotation IP) théoriquement possible dans la fenêtre de 5 min. Backlog connu « OTP cap ». → **P2-4**.

---

## 4. Findings numérotés

### P0 — 0
Aucune fuite de données client, aucune prise de contrôle de compte, aucun trou money-path.

### P1 — 0
Aucun défaut actuellement exploitable en prod (IDOR bloqué serveur, XSS nul, OTP throttlé, secrets absents). Le point « paiement en ligne » (ci-dessous) est **latent** — gated derrière une meta non posée — donc classé P2, pas P1.

### P2 — Durcissement / exposition latente

**P2-1 — Contradiction légale « aucun paiement en ligne » vs feature-online-card=1 (LATENTE, une meta d'activation).**
`cgv.html:135-136` (« paiement exclusivement au comptoir… aucun paiement en ligne par carte ») + `privacy.html:100,188,223` (« Sans objet — paiement au comptoir », Mollie ABSENT de la liste des sous-traitants art. 28) — texte **confirmé déployé** sur `www.lecayenne.fr/legal/*`.
MAIS `index.html:21` `<meta name="feature-online-card" content="1">` est **actif en prod**.
Atténuation vérifiée : l'option carte ne s'affiche QUE si `mollie-profile-id` est présente (`funnel.jsx:524` `onlineCard = onlineCardEnabled && !!mollieProfileId`) — or cette meta est **absente en prod** → option carte **non rendue aujourd'hui**, donc le comptoir-only est vrai en pratique. Risque : poser `mollie-profile-id` (pour activer Mollie déjà flaggé) rend le site contradictoire avec ses CGV + omet Mollie du registre RGPD. **Action** : soit remettre `feature-online-card=0`, soit mettre à jour CGV art. 6 + privacy §sous-traitants AVANT d'activer la carte.

**P2-2 — Clé API prod = placeholder jamais rotée.** `index.html:18` = `change-me-long-random-string-local-dev` servi live. Publique par nature (ne protège que du public), mais une clé devinable = 0 plafond anti-abus au bord. Backlog go-live « registre secrets ».

**P2-3 — Pas de révocation serveur du token au logout.** `logout()` (`api.js:224`) efface le localStorage mais n'appelle aucun endpoint de révocation → token valide jusqu'au TTL 8 h (`config/sanctum.php:51`). Un token volé/synchronisé ne peut être tué par « déconnexion ».

**P2-4 — OTP 4 chiffres + throttle par IP → brute-force distribué théorique.** `throttle:3,5` (`routes/api.php:219`) est keyé par IP pour le guest. Botnet/rotation IP dans la fenêtre 5 min = attaque envisageable. Backlog « OTP cap » (mémoire). Durcir : 6 chiffres OU throttle par téléphone OU cap global.

**P2-5 — En-têtes manquants.** Site Vercel : pas de **CSP**. API : CSP en **Report-Only** (n'applique rien) + **pas de HSTS**. Ajouter une CSP au site (délicat vu React/Babel via unpkg — envisager self-host + CSP stricte) ; passer la CSP API en enforce + poser HSTS.

**P2-6 — Fichiers internes servis publiquement.** `.vercelignore` n'exclut que `tests-e2e`. `tools/generate-menu-from-api.mjs` (contient la clé dev `:47` + `127.0.0.1`) et `VERCEL_DEPLOY.md` (`:26,:41` = chemin filesystem local du owner) sont téléchargeables. Aucun endpoint privé ni secret live divulgué, mais hygiène : ajouter `tools`, `*.md`, `VERCEL_DEPLOY.md` à `.vercelignore`.

### P3 — Cosmétique / dette

**P3-1 — `data/loyalty.js` commentaires périmés = piège à régression.** En-tête (`:4,6,14,30-31`) dit `min_redeem_points 100` / `1 €=1 pt` alors que le code (`:32-36`) = `earn 10 / redeem 100 / min 50` (correct, aligné CGV art. 12). Un futur « fix vers le commentaire » réintroduirait le bug 1€=1pt déjà healé. Corriger les commentaires.

**P3-2 — `cookies.html` sans identité éditeur ni contact first-party** (les 4 autres légales l'ont). Renvoie seulement à la CNIL.

**P3-3 — `privacy.html:90,221` sur-déclare un traitement de mot de passe** inexistant sur le front web (auth = téléphone + email-OTP ; seul résidu = clé d'état morte `account-v2.jsx:22`). Sur-déclaration = inoffensif mais inexact.

**P3-4 — Validations client incohérentes.** maxlength promo 20 (`funnel.jsx:397`) vs aucune (`flows.jsx:119`) ; filtre chiffres OTP présent (`account-v2.jsx:96`) vs absent (`funnel.jsx:788`) ; regex email stricte (`funnel.jsx:612`) vs `includes('@')` (`account-v2.jsx:53`). Backend = rempart dur ; harmoniser pour l'UX.

**P3-5 — Panier (note texte libre) + `lc.mollie.pending` (id/total commande) survivent au logout** (`index.html:162`, `funnel.jsx:571`). Faible PII (téléphone/token bien effacés).

**P3-6 — 3 liens `target="_blank" rel="noopener"` sans `noreferrer`** (`screens-v3.jsx:166`, `wizard-v2.jsx:792,832`) — tous vers `legal/allergens.html` first-party. `noopener` bloque déjà le tabnabbing ; fuite Referer same-origin seulement.

**P3-7 — Clé dev en dur** dans `api.js:21` (fallback) — écrasée par la meta, mais ne devrait pas être committée.

---

## 5. Verdict — zones AIRTIGHT (vérifiées bonnes)

- **IDOR** commande/paiement/statut/ticket : ownership 403 serveur sur les 4 endpoints (source + heal FRONT-SHOW-403-422).
- **Auth** : endpoints privés exigent Bearer valide ; token forgé/absent → 401 ; clé API seule ne lit aucune donnée privée.
- **OTP** : envoi 5/min, verify 3/5min, code 4 chiffres usage unique 5 min, 0 fuite dev_code prod.
- **Gardes cold-load** : checkout/payment→menu, confirm/track/account→home, orders/loyalty→empty-state — 0 fuite.
- **XSS** : 0 sink recevant de la saisie utilisateur ; test live note = charge inerte.
- **Secrets** : 0 credential live dans l'arbre servi ; cert API valide ; site HSTS 2 ans.
- **money-path** : prix/total scellés backend (le client n'envoie que `expected_total` indicatif, garde 422).
