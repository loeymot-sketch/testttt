# SITE_FINDINGS — audit adversarial, partie SITE de la livraison (round 1, 2026-10-21)

Dépôt audité : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (remote vérifié : `github.com/loeymot-sketch/Site-lecayenne`, HEAD `afe30f7`). Aucun fichier modifié, aucun git add/commit/push, pas de SSH.
Backend local : `http://127.0.0.1:8767`. État de la livraison restauré à l'identique (`enabled:true`) après les bascules.
Scripts et captures : `/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/4f1315cd-0d71-49ae-8cdb-17e3445190a3/scratchpad/` (`lib.js`, `scen.js`, `suggest.js`, `fails.js`, `r429.js`, `mid.js`, `pay.js`, dossier `shots/`).

## Instrument (prouvé, pas supposé)
- Site servi statiquement (`python3 -m http.server 5199 --directory <site>`), navigateur Chromium Playwright, horloge navigateur figée à 19:10 Paris (sinon « SERVICE FERMÉ »). Les appels vers l'API de prod du `<meta api-base-url>` sont redirigés par `context.route` vers le backend local ; chaque appel est journalisé (statut réel). Preuve que ça mesure : `GET /loyalty/config 200`, `/item?branch_id=1 200`, puis les `delivery/quote` 200/422/429 observés dans le journal, et le prix 5,90 € de Oignies lu à l'écran = prix du backend.
- Article du panier : Perrier 33cl (id 125, réel). Un faux article « Coca » (id 1001) s'affichait « ÉPUISÉ » et bloquait « Passer commande » : artefact d'instrument, écarté.
- Pas de commande créée (OTP requis). Tout ce qui dépend de la création d'une commande est marqué « non rejoué ».

## Réponses aux 6 questions

### 1. Le site appelle-t-il status / suggest / waves / quote ? Compilé et cache-buster ?
- `status` : `api.js:151-157` (`deliveryStatus`), appelé par `useDeliveryEnabled` `funnel.jsx:22-30`. `suggest` : `api.js:159-166`, appelé à chaque frappe `funnel.jsx:555`. `quote` : `api.js:170-188`, appelé par `computeDelivery` `funnel.jsx:483-491`. Confirmé en direct dans le journal (`/api/frontend/delivery/status 200`, `suggest 200`, `quote 200`).
- `GET /delivery/waves` n'est JAMAIS appelé par le site (aucune occurrence dans `api.js`, `funnel.jsx`, `compiled/funnel.js`). Les créneaux viennent du champ `waves` de la réponse `quote` (`api.js:185`). Ce n'est pas un défaut : la route `waves` du backend est simplement inutilisée par le site (P3, route morte côté client).
- Compilé : `compiled/funnel.js` contient le code livraison (`grep` : lignes 51, 55, 816, 948-965). `compiled/funnel.js.source-sha256` = sha256 de `funnel.jsx` (identique : `e00e1534…`). `index.html:422` charge `compiled/funnel.js?v=20261020livraison3`, `index.html:391` charge `api.js?v=20261020livraison1`.
- Cache-buster : dans le commit HEAD `afe30f7` les jetons ont bien été bumpés (`api.js?v=20260929audit1` → `20261020livraison1`, `funnel.js?v=20260929audit1` → `20261020livraison3`) ET l'empreinte du contenu committé de `api.js` (`9f326254…`) = celle de `tools/asset-versions.json` au HEAD. OK pour ce qui est committé.
  - ⚠ `node tools/check-asset-versions.mjs --check` ÉCHOUE sur ces deux fichiers (« contenu modifié APRÈS le jeton : dernier commit 2026-10-21 > jeton 2026-10-20 »). Pour `compiled/funnel.js` c'est un faux positif de l'heuristique de date (commit à 01:29 le 21, jeton daté du 20, contenu identique à celui du jeton). Pour `api.js` c'est un VRAI problème, voir F-09.

### 2. Invisible si « suspendue », visible si « active » ?
Oui, prouvé (Playwright + API) :
- Suspendue (`POST /api/admin/pos-delivery/toggle {"enabled":false}` → `GET /delivery/status` → `enabled:false`) : le tunnel n'affiche plus la tuile « Livraison » native ; à la place un lien `<a>` « Livraison — Sur Uber Eats → » (`https://www.ubereats.com/fr/store/le-cayenne/C1s3XHn2V2m_D8d6IOVJQg`), aucun champ adresse (`input[aria-label="Adresse de livraison"]` : 0). Capture `shots/susp-w390.png`.
- Active : tuile « Livraison — Dès 3,90 € · selon ta commune », champ adresse, devis, créneaux (captures `w390-*`, `w320-*`, `w1440-*`).
- Bascule en cours de session : page restée ouverte, le client re-clique « Calculer » après suspension → message « La livraison est momentanément suspendue. » (`api.js:179`) ; mais la tuile « Livraison » reste affichée jusqu'au rechargement (le statut est mis en cache de module `wfvDeliveryCache`, `funnel.jsx:21`). Mineur (P3) car le serveur refuse aussi à la commande : `app/Http/Requests/OrderRequest.php:296` (non rejoué : pas de commande créée).
- Fail-closed : si `status` répond 429/erreur, `deliveryStatus()` renvoie `enabled:false` (`api.js:154-156`) → la livraison disparaît (comportement sûr).
- Barème lu à l'écran = backend : Hénin-Beaumont 437 rue Élie Gruyelle 0 km → 3,90 € ; Oignies 6,71 km → 5,90 € ; Lens 8,7 km → 6,90 € ; Lille → refus + lien Uber Eats ; « azertyuiop » → « Adresse invalide. Veuillez en saisir une autre. ». Recap : sous-total 1,90 + livraison 5,90 = 7,80 € (`shots/w390-4-recap-top.png`), le CTA « Confirmer ma commande 7,80 € » inclut bien la livraison.

### 3. Cohérence du barème / de la copie (voir F-01, F-02)
Le tunnel est cohérent avec `config/delivery.php` (3,90 / 4,90 ≤5 km / 5,90 ≤8 km / 6,90 ≤10 km / refus >10 km ; textes `funnel.jsx:531`, `:565`, `:574`, `api.js:139-144`). Aucun ancien barème visible côté client dans le HTML/JS servi (seulement 2 commentaires périmés, `funnel.jsx:509`, `:1178`). En revanche, TOUTE la copie vitrine dit « pas de livraison en propre / 100 % à emporter » : voir F-01/F-02 (sans effet tant que le serveur dit « suspendue », contradictoire à l'activation).

### 4. Champ d'adresse
- Autocomplétion = `<datalist id="lcf-delivery-suggest">` natif (`funnel.jsx:553-560`), alimenté par `suggest` à partir de 4 caractères.
- Refus hors zone : message « « {adresse} » est trop loin pour notre livraison (plus de 10 km). … Commander sur Uber Eats → » (`funnel.jsx:563-570`), lien vers `api.config.uberEatsUrl`. Vu à 320/390/1440 (`shots/w*-3-lille.png`).
- 429 sur `quote` (rejoué réellement) : « Trop de tentatives. Patiente une minute avant de réessayer. » (lisible). 429 sur `suggest` : ÉCHEC MUET (F-04). Panne BAN : « Adresse invalide » trompeur (F-05).

### 5. Pilotage navigateur — défauts visuels (captures lues avec Read)
- 390 / 1440 : mise en page intacte, pas de chevauchement, pas de libellé brut, textes de barème en gris 4,68:1 (≥ 4,5, mesuré), créneaux en noir sur blanc 21:1.
- 320 : pas de débordement horizontal ; le champ d'adresse ne montre que la fin du texte saisi (« 62590 OI▼ », `w320-3-oignies.png`) : P3 (le bandeau « Adresse comprise » répète l'adresse en entier).
- Créneaux non sélectionnés : fond blanc, bordure transparente (`bg rgb(255,255,255) border rgba(0,0,0,0)`, mesuré) → ils se lisent comme du texte, pas comme des boutons (P3).
- Voir F-03 (paiement) pour le défaut visible le plus net.

### 6. app/www (Capacitor)
`node tools/build-app-www.mjs --check` (lecture seule) : « app/www est PÉRIMÉ (3) : api.js, compiled/funnel.js, index.html ». Hash : `compiled/funnel.js` source `775549158214…` vs www/ios/android `a263ecac49f6…` ; `api.js` `0418535b…` vs `95a3b3ea…`. Les copies `app/ios/App/App/public` et `app/android/.../assets/public` sont identiques à `app/www` (donc périmées pareil). `app/www/compiled/funnel.js` daté du 17 oct : ancien tunnel (géocodage Nominatim `app/www/api.js:173-177`, barème client). Effet : l'application des stores ne verra AUCUNE livraison propre tant que `build:www` + `cap sync` ne sont pas refaits ; son défaut `feature-delivery` est `'0'` (`app/www/api.js:77`) → elle reste « à emporter + lien Uber Eats », donc pas de promesse fausse (périmée mais sûre). Le `--check` compare l'arbre de travail (qui contient du travail non committé d'une autre session) : « à jour » n'a de sens qu'après commit de ces fichiers.

## Constats

### P1 — défaut visible utilisateur ou promesse contredite (à l'activation)

**F-01 — La copie du site dit « pas de livraison en propre » partout (contredit l'offre dès que la caisse « remet »).**
Preuve (grep, sources servies) : `llms.txt:6,19,64` (« Pas de livraison en propre », « Uniquement Uber Eats ») ; `index.html:352` (FAQ prérendue « Non, pas en propre : tout se commande à emporter ») ; `commander.html:7,17,24` (meta « Livraison via Uber Eats »), `:496-499` (JSON-LD FAQPage), `:583-584`, `:607` ; `livraison-henin-beaumont.html:7,17,24` (title/og/twitter : « Le Cayenne ne livre pas en propre »), `:498-499` (JSON-LD), `:576`, `:585` (« Autant être clair : Le Cayenne ne livre pas en propre »), `:604` (« Pourquoi on ne livre pas nous-mêmes »), `:624` ; `a-propos.html:525` (JSON-LD), `:607`, `:626` ; `carte.html:1136` ; `manger-tard-henin-beaumont.html:622` ; `legal/cgv.html:140-143` (« uniquement le retrait… Aucun frais de livraison n'est facturé par Le Cayenne ») ; `screens-v3.jsx` (`FAQ_HOME`, aussi dans `compiled/screens-v3.js`). `index.html:21-24` (commentaire d'en-tête) interdit de revendiquer une livraison propre. `docs/LIVRAISON.md §4.5` le sait déjà (« relire la copie… »).
Reproduction : `grep -rniE "pas de livraison|ne livre pas|uniquement.*uber|à emporter uniquement" . --include='*.html' --include='*.txt'` dans le dépôt du site. Pas de défaut tant que `enabled:false` ; dès `enabled:true`, la page « Livraison à Hénin-Beaumont » (indexée, au sitemap) dit l'inverse du tunnel.
Complément juridique : `legal/privacy.html` (tableau des données, l. ~105-150) ne liste ni l'adresse de livraison ni l'envoi de l'adresse à la Base Adresse Nationale (via le serveur) ; les CGV ne mentionnent ni frais ni zone ni délai de livraison.

**F-02 (P2, hors périmètre livraison mais contradictoire) — Politique de confidentialité vs paiement en ligne.**
`legal/privacy.html` dit « le paiement s'effectue au comptoir » alors que `feature-online-card=1` + Mollie live (`index.html:69,83-84`) ; hors périmètre livraison mais contradictoire.

**F-03 — En mode Livraison, le paiement est présenté comme « au comptoir ».**
`funnel.jsx:1829` : `{ id:'counter', name:'Payer sur place', meta:'Espèces ou carte au comptoir — ton plat t'attend' }` ; bandeau « PAIEMENT AU COMPTOIR » (visible `shots/w390-6-paiement-livraison.png`). Aucune branche `fulfillment==='delivery'` ne le change. Un client livré lit « ton plat t'attend » au comptoir alors qu'il paiera à la porte (`docs/LIVRAISON.md §5` : carte à la porte non câblée). Reproduit en navigateur (mode Livraison + devis calculé, `pay.js`).
Même cause, écran de confirmation : `funnel.jsx:2504` `<h4>Ticket retrait</h4>`, QR « retrait », `:2513` « Prêt dans » = `ctx.slotTime` / attente de cuisine ; aucune occurrence de `delivery`/`livraison` entre `funnel.jsx:2397` et `:2530` (grep) → un client livré voit « Ticket retrait / Prêt dans » et jamais l'adresse ni l'heure de départ choisie (`deliverySlotAt`). NON REJOUÉ en navigateur (aucune commande créée) : constat par lecture de code + grep, à confirmer en round 2 avec une vraie commande. Idem `orders.jsx:181` « Points fidélité en attente du retrait ».

### P2 — qualité UX

**F-04 — `suggest` : pas de debounce, 429 muet.** `funnel.jsx:555` lance `deliverySuggest` à CHAQUE frappe dès 4 caractères ; `api.js:162-166` avale toute erreur (`return []`). Limite serveur : 40/min/IP (`RouteServiceProvider.php:207-214`). Mesuré (`suggest.js`) : « 12 rue de la gare henin » (23 car.) = 20 appels ; une 2ᵉ saisie dans la minute → des 429 (`200,…,429,200,429,429`), la 3ᵉ saisie : 100 % 429, liste de suggestions vide, AUCUN message (texte de la section : « ADRESSE DE LIVRAISON | CALCULER »). Le bouton « Calculer » (limite quote 20/min, séparée) reste utilisable. Aggravé derrière une IP partagée (4G/CGNAT). Correctif : debounce ~300 ms + message discret sur 429.

**F-05 — Panne BAN = « Adresse invalide ».** `app/Services/Delivery/AddressGeocoder.php:95-103` : exception réseau, HTTP non 2xx, et « adresse introuvable » lèvent la MÊME `GeocodeUnavailableException` → 422 « Adresse invalide. Veuillez en saisir une autre. » (`GeocodeUnavailableException.php:10`). Le client retape des adresses valides pendant une panne. Rejoué côté site avec 422 simulé : message identique.

**F-06 — Messages d'erreur du devis mal traduits.** Simulations (`fails.js`, réponses interceptées, donc instrument = site) : 500 → « Une erreur technique est survenue (500). Réessaie, ou règle ta commande en caisse. » (« en caisse » n'a aucun sens pour une livraison) ; 503 `{"message":"Service Unavailable"}` (mode maintenance Laravel pendant un déploiement) → « Service Unavailable » affiché brut en anglais (`api.js` heuristique anglaise ne le reconnaît pas) ; réseau coupé → « Réseau indisponible. Vérifie ta connexion. » (OK).

**F-07 — Autocomplétion `<datalist>` probablement invisible.** Les 5 suggestions renvoyées pour « 12 rue de la gare henin » ne contiennent AUCUNE la saisie comme sous-chaîne (0/5, mesuré). Le filtrage natif de Chrome étant par sous-chaîne, la liste n'afficherait rien (à CONFIRMER sur appareil réel : le popup natif n'est pas capturable en headless ; Safari iOS se comporte autrement). Piste : liste maison, ou ne pas dépendre du filtre natif.

**F-08 — Adresse comprise ≠ adresse tapée, sans confirmation bloquante.** « 12 rue de la gare 62590 Oignies » → le serveur « comprend » « 12 Rue de la Victoire 62590 Oignies » (6,71 km, 5,90 €). Le site l'affiche en gras + « si ce n'est pas la bonne, précise… » (`funnel.jsx:572`), mais le champ garde le texte tapé et rien n'oblige à cliquer « c'est bien là » avant « Confirmer ma commande » ; l'adresse enregistrée est `dq.label` (`funnel.jsx:1181`). Risque : livraison dans une autre rue (prix correct pour le point compris). Capture `shots/w1440-3-oignies.png`.

**F-09 — `api.js` modifié sans bump du jeton (travail non committé d'une autre session).** `git diff HEAD -- api.js` : +86 lignes (synchro catalogue `applyCatalogFacts`, règle SMTP) ; `index.html` (working tree) garde `api.js?v=20261020livraison1`. Si cela est committé tel quel : le correctif est invisible pour tout navigateur déjà venu (défaut documenté dans `tools/check-asset-versions.mjs`). 117 entrées `git status` dans le dépôt du site (dont `compiled/components.js`, `screens.js`, `racine.js`, `index.html`, `tools/asset-versions.json`) : il faut distinguer ce lot du lot livraison au moment de pousser.

**F-10 — Aucun champ étage / interphone / digicode.** `funnel.jsx:1181` : `saveAddress({ label, address })` sans `apartment` ; aucun champ de saisie (grep `apartment|interphone|étage` dans `funnel.jsx` : 0). Le serveur et la caisse savent le gérer (`apartment`, e2e backend `'2B'`). Seule la « Note pour la cuisine » existe (pas pour le livreur).

### P3 — cosmétique / dette
- F-11 : commentaires périmés `funnel.jsx:509` et `:1178` (« barème 4 € ≤3 km puis +2 €/km »), `api.js:73-76` (« 0 livraison… default OFF » alors que le défaut est `'1'`).
- F-12 : champ d'adresse étroit à 320/390 (fin du texte seulement visible) ; créneaux non sélectionnés sans affordance de bouton.
- F-13 : tuile « Livraison » reste visible après suspension tant que la page n'est pas rechargée (cache `funnel.jsx:21`) ; le refus final est côté serveur.
- F-14 : « Tu gagnes +78 pts » compte les frais de livraison (points sur le total, `AwardLoyaltyPointsOnDelivery.php:135-142`) : à valider métier.
- F-15 : latent backend, `FrontendOrderService.php:668-677` : `free_delivery_above` par défaut 30 € si le réglage est absent ; local = 0 (migration `2026_07_27_093000`). Je n'ai pas pu lire la valeur de prod (pas de SSH) ; si absente, toute livraison ≥ 30 € serait gratuite côté serveur ET refusée par `expected_total` (422) — à vérifier en prod avant l'ouverture.
- F-16 : route `GET /delivery/waves` jamais utilisée par le site.

## Rejeté / non prouvé (honnêteté)
- « Free delivery ≥ 30 € actif » : RÉFUTÉ en local (`Settings delivery.free_delivery_above` = 0, `tinker` lecture seule) ; reste F-15 pour la prod.
- Création de commande, page de confirmation et suivi pour une livraison : NON rejoués (OTP/compte requis, pas d'écriture en base) ; F-03 (partie confirmation) repose sur la lecture du code.
- Rendu du popup natif `<datalist>` : non mesurable en headless (F-07 « à confirmer »).
- iOS / Android réels : non testés ; `app/www` mesuré par hash uniquement (aucune compilation d'app).
