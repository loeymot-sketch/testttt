# CONTRACTS — coordination implémenteurs WF-2 (2026-07-08)
> Contrat UNIQUE de coordination. Tout implémenteur le lit AVANT d'écrire. Les noms ci-dessous sont NORMATIFS.

## 0. Chemins
- BACKEND = `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
- WEB = `/Users/1millnonstop/Downloads/web` · MOBILE = `<BACKEND>/mobile`
- FIXTURE canonique = `<BACKEND>/reports/goal-web-app-sync/catalog-canonical.json` (data.categories[9], data.items[42])
- Findings par scope = `<BACKEND>/reports/goal-web-app-sync/w1/by-spec/<scope>.json`
- Serveurs : API+borne `http://127.0.0.1:8766` (UP) · web statique `:8096` (UP) · mobile `php -S 127.0.0.1:8087 -t mobile/`

## 1. Auth clients (VÉRIFIÉ curl 2026-07-08)
- `POST /api/auth/guest-signup/otp` {phone, code:'+33'} (X-API-Key requis) → 200.
- `POST /api/auth/guest-signup/verify` {phone, code:'+33', token:'<saisie>'} → **N'IMPORTE QUEL code passe** (site_phone_verification ≠ ENABLE en V1 local) → {token:'<id>|<plain>', user:{id,...}} ability `kiosk:order`, TTL 30 j.
- Headers systématiques : `X-API-Key: <meta api-key / LC.config.apiKey>` ; auth = `Authorization: Bearer <token>`.

## 2. Fidélité — endpoints (contrats vérifiés file:line en w1/by-spec/backend_loyalty-endpoints.json)
- `GET /api/frontend/loyalty/config` PUBLIC → data{points_per_euro=1, points_for_1_euro_discount=100, min_redeem_points=100, tiers, label}. **Clients NE hardcodent PLUS ces taux.**
- `GET /api/profile` auth → loyalty_points + loyalty_code (source du solde ; PAS /balance).
- `GET /api/frontend/loyalty/history?page=&per_page=` auth → lignes {type, points, balance_after, source_surface, description, date} + meta.
- `POST /api/frontend/loyalty/qr` auth (throttle 30/min) → data{token:'lqr.…', expires_at, ttl_seconds:300, loyalty_code} (mint loyalty_code si absent).
- `POST /api/frontend/loyalty/redeem` auth + header `X-Idempotency-Key` → body {code:<loyalty_code>, points:<multiple de 100>} ; gérer 400 (multiple) et 422 (kill-switch V1) avec message FR propre.
- `POST /api/frontend/loyalty/register` PUBLIC {phone,name?} → création OU code:'PHONE_EXISTS'.
- EARN = automatique backend (1 pt/€ **floor** du total TTC, au statut PREPARED/DELIVERED) : le client n'ajoute JAMAIS de points ; il envoie `loyalty_code` dans placeOrder. Affichage estimation earn : `Math.floor(total × points_per_euro)`.
- **Modèle redeem = CONTINU points→€ (100 pts = 1 €), PAS de catalogue de récompenses** (décision D6=A + aucune route /loyalty/rewards). Les UI "rewards" mock sont remplacées par ce modèle.

## 3. QR — affichage (web + mobile identique)
- Lib vendorisée LOCALE : `WEB/vendor/qrcode.js` et `MOBILE/vendor/qrcode.js` (déjà en place, API globale `qrcode`).
- Rendu : `var q=qrcode(0,'M'); q.addData(token); q.make(); container.innerHTML=q.createSvgTag({cellSize:3, margin:0, scalable:true});`
- UX : mint-on-display via POST /qr ; compte à rebours ttl_seconds ; auto re-mint à expiration + bouton « Actualiser » ; afficher `loyalty_code` en clair sous le QR + mention « Présentez ce QR ou dictez votre numéro en caisse ».
- INTERDIT : QR persistant offline, format legacy `FK:<code>` (rejeté backend), CDN externe.

## 4. Flag Stripe « paiement en ligne » (OFF par défaut, double verrou)
- WEB : `<meta name="feature-online-card" content="0">` dans index.html ; api.js CFG lit `metaContent('feature-online-card','0')==='1'` → exposé `LC.api.config.onlineCardEnabled`.
- MOBILE : `window.LC.config = { apiBase:'http://127.0.0.1:8766', apiKey:'<même clé que web api.js:21>', branchId:1, onlineCardEnabled:false }` défini dans index.html AVANT tout script ; consommé par api/client.js et les écrans.
- Flag OFF ⇒ AUCUNE option carte-en-ligne dans le DOM (web: methods=[counter seulement + mention CB au comptoir] ; mobile: ModalPayChoice sans « Payer maintenant », ScreenStripe inaccessible). Copy mensongère (« Stripe 3D-Secure » sans débit) SUPPRIMÉE.
- Flag ON (test uniquement) ⇒ l'option apparaît ; le flux reste bloqué serveur par config/payment.php (web_payment_v1 + pilot_restrict + activation_guard) — c'est ATTENDU, ne pas contourner.
- payment_method envoyé : counter→1 toujours quand OFF. Ne plus envoyer 4 pour des paiements non réels.

## 5. Data mirrors menu.js — flags normatifs (mêmes noms web & mobile)
- `PAINS = [{id:'pain-classique',name:'Pain'},{id:'pain-galette',name:'Galette'}]` + `has_pain_choice:true` sur les 4 sandwichs.
- `EXTRA_MEAT_PRICE = 2.50` + `has_extra_meat:true` sur : 4 sandwichs, 2 galettes, 6 burgers, 2 tacos, 2 bols. priceFor compte `opts.extraMeatIds.length * EXTRA_MEAT_PRICE`.
- `CRUDITES` = 4 entrées : + `{id:'c-oignons-cuits', name:'Oignons cuits', price:0}`.
- `SUPPLEMENTS` : + `{id:'sup-boule-gratinee', name:'Boule gratinée', price:1.00, galette_only:true}` ; `sup-boursin` reçoit `galette_excluded:true` (le canonique n'offre PAS Boursin sur les galettes). Les wizards filtrent par ces 2 flags selon la catégorie.
- Tacos M/L : `has_crudites:true` (REVERT backend 05e5cacd0 2026-07-07 : 4 crudités restaurées sur la borne — fixture faisant foi, extras group_label='crudite' ×4 sur items 26/97).
- Sauces : templates sandwich/tacos/burger = **1 sauce max** (canonique min1/max1) → supprimer la tarification multi-sauces +0,50 € de ces templates (la garder UNIQUEMENT là où le canonique la prévoit : nulle part actuellement ; cascade frites-sauce du menu reste instruction gratuite). Cayenne : `has_sauce:true` + `sauce_default:'Sauce fromagère maison'` (pré-sélectionnée), retirer sauce_locked ; `is_spicy:false`.
- `BOL_SAUCES = [{id:'bs-fromagere',name:'Sauce fromagère maison'},{id:'bs-spicy',name:'Sauce spicy'}]` (mobile : à créer ; web : existe) — bols = 1 sauce parmi ces 2.
- `SUPPLEMENTS_BOLS` = 9 × 0,90 € (Jambon, Champignons, Oignons frits, Cheddar, Raclette, Emmental, Boursin, Œuf, Légumes sautés) + `{id:'sb-gratine', name:'Option Gratiné', price:2.00, riz_only:true}` (sélectionnable UNIQUEMENT Bol Riz).
- `DRINKS` : +7 items ids 1009-1015 : coca-cherry 'Coca Cherry 33cl', tropico 'Tropico 33cl', ice-tea-peche 'Ice Tea Pêche 33cl', fanta-citron 'Fanta Citron 33cl', fuze-tea 'Fuze Tea 33cl', hawai 'Hawaï 33cl', perrier 'Perrier 33cl' — tous 1.90, descriptions du canonique. `FORMULE_DRINKS` étendu aux 15 saveurs (d-*) ; `priceForDrinkAddon` : `'d-capri':1.50` (FIX P0) + nouvelles saveurs 1.90.
- `Menu Enfant Burger` → renommé `Menu Enfant Chicken Burger` (nom canonique EXACT — la résolution API se fait PAR NOM).
- `is_halal` : défaut mkItem passe à `false` (canonique is_halal:false partout — allégation réglementaire).
- Catégorie 6 mobile : `wizard_template:'bol'` (aligné web) — le wizard mobile doit supporter ce template.
- Images viandes mobile : réutiliser le mapping du web (IMG-SYNC-CAISSE) **UNIQUEMENT si le fichier existe** (vérifier ls) ; sinon garder l'existant. Ne JAMAIS référencer un asset inexistant.
- Frites : conserver le wizard `frites_style` (équivalence prix validée au centime : 2,50/4,00 + 1,00/2,00 = les 6 SKU canoniques) — divergence STRUCTURELLE ACCEPTÉE, documentée. Le gate parity vérifie l'atteignabilité des 6 prix, pas l'égalité SKU.

## 6. LC.api web — ajouts (api.js, pattern req() existant)
`loyaltyRegister(phone,name)`, `loyaltyHistory(page,perPage)`, `loyaltyRedeem(code,points)` (X-Idempotency-Key auto 'web-lr'+uuid), `loyaltyQr()`, + `config.onlineCardEnabled`. `DRINK_ID_TO_SLUG` +7 nouvelles saveurs. Erreurs = contrat {kind,message,status} existant.

## 7. window.LC.mobileApi — nouveau `mobile/api/client.js` (M-D)
Fonctions : `guestOtp(phone)`, `guestVerify(phone,otp)` (→ storage.setAuth({token,phone,user_id})), `isAuthed()`, `logout()`, `profile()`, `loyaltyConfig()`, `loyaltyHistory(page)`, `loyaltyQr()`, `loyaltyRedeem(code,points)`, `buildItemIndex()`, `resolveOrderItems(lines)`, `placeOrder(o)`, `getOrder(id)`, `orderHistory(limit)`. PORT du pattern `WEB/api.js` (le LIRE comme référence) adapté au shape `buildLineItem` mobile (meatIds/sauceIds/cruditeRemoved/supplementIds/bolSupplementIds/bolDrinkId/menuChoice/…). Résolution PAR NOM canonique (norm() identique au web). Offline/API down ⇒ throw {kind:'network'} et les écrans retombent sur le mode local V0 (dégradation douce, bandeau « hors ligne »).

## 8. Vérifications OBLIGATOIRES avant de retourner (implémenteurs)
- Data mirrors : `node <BACKEND>/tools/parity/check-parity.mjs --surface=web|mobile` (gate B3 ; si pas encore dispo : one-liner node qui évalue le mirror IIFE avec un stub window et diffe noms/prix vs fixture).
- JSX modifiés : servir la surface + charger la page en Playwright (`npx playwright ...` recettes w1/by-spec/infra_e2e-harness.json) OU au minimum `node --check` pour les .js purs ; JSX : vérifier via babel du repo testttt `node -e "require('@babel/core').transformFileSync('<f>',{presets:[['@babel/preset-react']]})"` si dispo, sinon smoke navigateur obligatoire.
- AUCUNE écriture hors de tes fichiers OWNED. AUCUN produit inventé (fixture = loi). UI 100% FR. Pas de CDN. Pas de secrets committés. Tu ne commites PAS (l'orchestrateur commite).

## 9. Interdits absolus (rappel)
Frozen zones CLAUDE.md §7 (pos-wizard, kiosk Vue, PricingService, Fiscal, BranchScope, IdempotencyKeyMiddleware, OrderStateMachine, PaymentComponent, PosV5TrancheRow, admin-pos-v4.blade) ; tout fichier caisse/borne ; `git add .` ; `composer dump-autoload`.
