# QA Loop — prochaines actions bornées

Date : 2026-09-27  
Source : `reports/planning/QA_CROSS_SURFACE_FOLLOWUP_2026-09-25.md` et
`reports/antigravity/playwright-latest.json`

## État confirmé

- Smoke Playwright critique : 22/22, reproductible sur deux runs.
- Pricing/status lint et budget des bundles actifs : verts.
- Parcours fidélité, suppléments, deux sauces, KDS et libellés cuisine : verts.
- Production HTTP/healthz : verte.
- Borne distante : provisioning auto-login maintenant actif; le parcours
  interactif catégories/produits passe 5/5 en distant.
- Sentinel frozen : drift limité à trois commentaires, mais lock propriétaire
  `LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` non signé.
- Site public : copie livraison encore « Uber Eats », contrairement aux clés
  checkout FoodKing versionnées.
- Auth POS distante/F5 : les deux scénarios reçoivent HTTP 400
  « Identifiants invalides ou compte bloqué » avec le compte E2E configuré;
  aucun test F5 distant ne peut être déclaré vert sans compte de test actif.
  La fixture locale correspondante est active (`status=5`, branche 1) et
  accepte le mot de passe de test; l’action est donc strictement distante.
  Le dernier re-run a déclenché le rate-limit distant (HTTP 429, fenêtre 600 s);
  ne pas relancer avant expiration ou changement de compte E2E.
- **Revalidation web distante (27/09/2026, 18:49 CEST)** : le wizard borne
  repasse **5/5 en 19,7 s** sur le VPS et `/api/healthz` reste 200; aucun
  incident borne n’est reproduit. Le texte public Uber Eats reste le blocage
  contenu distinct.
- **Panier public contrôlé dans Chrome (27/09/2026)** : Tacos L 17,30 € +
  Tacos M 6,90 € donnent un total affiché de 24,20 €; vérification arrêtée
  avant paiement.
- **Quantité panier contrôlée (27/09/2026)** : Tacos M ×2 affiche 13,80 € et
  total 31,10 €, puis le retour à ×1 restaure 24,20 €.
- **Checkout public contrôlé (27/09/2026)** : `#payment` confirme le mode
  « À emporter », le retrait et le paiement au comptoir; la livraison pointe
  toujours vers Uber Eats et reste à remplacer par le texte validé.
- **Smoke E2E locale complète (27/09/2026, 18:54 CEST)** : **22/22 tests
  passés en 1,5 min**, incluant POS/F5, caisse, borne, KDS et isolation de
  rupture de stock multi-branche.
- **Fidélité/inscription locale (27/09/2026, 18:54 CEST)** : **4/4 tests en
  7,3 s**, sans page blanche sur solde réel, code inconnu ou inscription.
- **Backend Kiosk/Loyalty/Pricing (27/09/2026)** : **62/62 + 93/93 + 31/31
  tests passés**; prix au centime, suppléments, fidélité et auto-login borne
  restent validés.
- **Inscription post-register (27/09/2026, 18:56 CEST)** : **1/1 test en
  4,0 s**; le solde revient après `register`, sans page blanche.
- **Sécurité/isolation (27/09/2026)** : **221/221 + 6/6 + 8/8 tests passés**
  pour sécurité générale, borne et multi-branche.
- **Commande/outbox (27/09/2026)** : **109/109 + 81/81 tests passés**;
  snapshots de prix, statuts, idempotence et dispatch post-commit validés.
- **Reprobe VPS/public (27/09/2026, 18:59 CEST)** : healthz toujours 200 et
  services critiques OK; le contenu livraison reste inchangé (« Uber Eats »).
- **Frontend/hardware (27/09/2026)** : **58/58 + 164/164 tests passés**;
  tickets, sauces, viandes, frites et total serveur restent validés.
- **Fiscalité/paiement/caisse (27/09/2026)** : **307 tests fiscaux passés
  (8 skips MySQL), 86/86 paiement et 386/386 POS**; NF525, split tender,
  tiroir, fidélité caisse et totaux serveur restent verts.
- **Release guards (27/09/2026)** : pricing/status lint verts (**86/38 fichiers**),
  bundles **15/15**; i18n parse les **80 fichiers Laravel** sans erreur, avec
  dette de clés connue et sign-off pricing encore en attente jusqu’au 27/10.
- **Backend global (27/09/2026)** : **6 080 passés, 36 skipped, 6 incomplets,
  1 échec** en 1 301,71 s; le seul échec reste le sentinel frozen-zone
  `KioskWizardComponent.vue`. Ne pas toucher à la baseline sans gate propriétaire.
- **Forensic frozen recheck (27/09/2026)** : hash réel `f8ecb111…06465` contre
  baseline `fcbe3755…256ac`; l’écart est limité au commit `c21628767`, qui ne
  fait que normaliser trois commentaires de sign-off pricing. Le lock owner
  reste non signé : verdict **NEEDS_OWNER_ACTION**, aucune baseline modifiée.
- **Reprobe runtime (27/09/2026, 19:28 CEST)** : VPS HTTP 200 et sous-systèmes
  critiques `ok`; `queue_pending=1` sur trois mesures consécutives. Surveiller
  le worker/outbox, sans relancer ni purger manuellement une file de production.
- **Reprobe contenu public (27/09/2026, 19:28 CEST)** : Uber Eats est toujours
  affiché dans le HTML; la copie « livraison par nos livreurs bientôt » reste
  un blocage de déploiement distinct.
- **Healthz local (27/09/2026)** : `HealthzEndpointTest` **7/7 passés**.
- **Borne distante (27/09/2026, 19:29 CEST)** : avec Node **20.20.2** requis
  par Playwright, `03-kiosk-wizard.spec.js` passe **5/5 en 19,9 s**.
- **Queue distante (27/09/2026, 19:30 CEST)** : `queue_pending=2` persiste
  après quatre mesures et le test borne; surveiller le worker/outbox sans
  purge manuelle.
- **Readiness/sondes (27/09/2026, 19:31 CEST)** : `/api/health/live` et
  `/api/health/ready` sont HTTP 200; `restore_drill` reste `degraded` faute de
  restauration mesurée. `/api/health` voit 0 job tandis que `/api/healthz` en
  voit 2 : corrélation de configuration/déploiement à faire avant toute action.
- **Fidélité borne (27/09/2026, 19:31 CEST)** : **4/4 en 6,8 s** sur les
  scénarios réels solde/erreur/inscription/auto-check, plus **1/1** register.
- **Frontend/tickets (27/09/2026)** : `Feature/Frontend` **58/58 en 11,48 s**
  et `Feature/Hardware` **164/164 en 12,24 s**; sauces HH/X, suppléments,
  tacos, frites, viandes, tickets et tiroir restent verts.
- **Restore-drill (27/09/2026)** : contrats locaux verts (**17/17 + 5/5 +
  5/5**), mais la prod reste `degraded` tant qu’un drill de restauration
  mesuré et attesté n’est pas fourni. Ne pas lancer d’opération destructive
  depuis l’agent.
- **Release guards (27/09/2026, 19:34 CEST)** : pricing/status lint verts
  (**86/38 fichiers**), bundles **15/15**; i18n parse **80 fichiers Laravel
  sans erreur**. Les clés manquantes restent une dette connue et le sign-off
  pricing est averti jusqu’au 27/10, sans échec actuel.
- **Smoke E2E complet (27/09/2026, 19:34–19:36 CEST)** : **22/22 passés en
  1,5 min** avec Node 20.20.2; POS/F5, caisse, borne, KDS et rupture de stock
  multi-branche validés.
- **Healthz post-smoke (19:36 CEST)** : HTTP 200 et `queue_pending=0`.
- **Backend invariants (27/09/2026)** : **62/62 Kiosk, 93/93 Loyalty,
  31/31 Pricing, 109/109 Order, 81/81 Outbox, 221/221 Security**; prix,
  fidélité, statuts, dispatch post-commit et isolation multi-branche restent
  verts.
- **Vitest complet (27/09/2026, 19:38–19:41 CEST)** : **556 fichiers,
  4 511 passés, 3 skipped** en **193,29 s**, sans échec. Les warnings de
  stubs Vue/i18n et réseau happy-dom sont non bloquants.
- **Healthz post-Vitest (19:41 CEST)** : HTTP 200 et `queue_pending=0`.
- **Copie publique (27/09/2026, 19:42 CEST)** : `checkoutTakeawayCopy.spec.js`
  local **2/2**, mais `lecayenne.fr` sert toujours « Uber Eats ». Identifier
  la surface externe et déployer la copie validée avant de fermer ce point.
- **Healthz associé (19:42 CEST)** : HTTP 200 et `queue_pending=0`.
- **Source publique identifiée (27/09/2026, 19:43 CEST)** : le site vient du
  dépôt externe `~/Downloads/lecayenne-web-deploy/Site lecayenne`, où
  `index.html`/`commander.html` portent encore Uber Eats. L’arbre est déjà
  dirty; aucune modification automatique effectuée.
- **SEO externe (27/09/2026, 19:43 CEST)** : **17 contrôles OK, 1 échec**
  (`bol-frites.html`/`bol-riz.html` similarité 81%). À corriger et retester
  dans le dépôt externe après validation propriétaire.
- **Comportement app/site externe (27/09/2026, 19:44 CEST)** :
  `verify-app-behaviour.mjs` **13/13 passés** sur serveur local temporaire;
  paiement/app-versus-web et API HTTPS conformes.
- **HTTP publication externe (27/09/2026)** : cinq ressources critiques en
  **200**, HTTP→HTTPS en **308**, CSP/HSTS/nosniff actifs. Vercel sert une
  réponse en cache (`HIT`, âge ~6 330 s); purger/redéployer la surface externe
  est nécessaire pour remplacer la copie Uber Eats.
- **Cache vs déploiement (27/09/2026)** : URL normale et cache-bustée ont le
  même SHA `91ea556e…332c48bf`, ETag/date identiques et quatre mentions Uber
  Eats. `origin/main` = `b7bc1763181e…`; les changements corrigés sont dans
  l’arbre externe dirty et ne sont pas déployés. Action propriétaire : commit,
  push et redeploy Vercel, puis recontrôle HTML.
- **Diff externe inspecté (27/09/2026)** : les modifications non committées de
  `index.html`, `commander.html` et `livraison-henin-beaumont.html` concernent
  surtout l’UX (cibles `/#menu`, zones tactiles, dates/cache-bust); elles ne
  remplacent pas la phrase « livraison passe par Uber Eats ». Aucun patch
  public prêt à pousser n’est donc confirmé par cet audit; ne pas sélectionner
  ni committer l’arbre externe dirty sans validation du propriétaire.
- **Sonde live relancée (27/09/2026, 19:48 CEST)** :
  `https://vps-418872ac.vps.ovh.net/api/healthz` répond HTTP **200** avec
  `db=ok`, `redis=ok`, `websocket=ok`, `fiscal_chain=ok` et `queue_pending=0`.
  Le HTML live de `lecayenne.fr` contient toujours **4** mentions « Uber Eats »;
  les phrases cibles « livraison par nos livreurs bientôt » et
  « commander/confirmer à emporter » sont absentes. Le défaut public est donc
  toujours reproductible après le dernier audit.
- **Rejeu distant et navigation protégée (28/09/2026, 02:00 CEST)** : avec
  Node 20.20.2, `03-kiosk-wizard.spec.js` sur le VPS passe **4/5**, le cinquième
  scénario étant **skipped** car la borne n’est pas provisionnée. Un navigateur
  headless sans session confirme `/login` sans erreur JS, et `/admin/dashboard`
  redirige correctement vers `/login`. `/kiosk/login` affiche toutefois
  « Borne momentanément indisponible » : disponibilité produit toujours non
  démontrée en production.
- **Écart de readiness (28/09/2026, 02:00 CEST)** : `/api/health` et
  `/api/healthz` sont OK avec des files à zéro, mais `/api/health/ready` répond
  HTTP 200 tout en signalant `restore_drill=degraded` (« restauration jamais
  mesurée »). Le statut HTTP `ok` ne doit donc pas être interprété comme une
  readiness opérationnelle complète; un drill attesté reste requis.
- **Régression ciblée prix/paiement (28/09/2026, 02:00 CEST)** : huit suites
  Vitest couvrant preview pricing, modifications depuis le récapitulatif,
  payload panier, crudités payantes, plan B paiement, retry, ticket et montant
  facturé passent **51/51 en 4,70 s**. Le sous-ensemble PHPUnit
  `tests/Feature/Pricing` passe **31/31 en 8,29 s**, y compris sauces,
  suppléments, quantités, TVA TTC et refus d’extras invalides.
- **Revalidation après transmission aux développeurs (28/09/2026, 02:01 CEST)** :
  le dépôt externe reste sur `b7bc1763181e…`, identique à `origin/main`, avec
  **58** entrées dirty; aucune des trois pages ciblées ne contient les phrases
  de livraison/emporter demandées. Le HTML live conserve le SHA
  `91ea556e…332c48bf`, quatre mentions « Uber Eats » et zéro phrase cible.
  Le changement n’a donc pas été publié depuis le dernier rapport.
- **Sécurité HTTP revalidée (28/09/2026)** : CSP, HSTS, `nosniff`,
  `frame-ancestors`/SAMEORIGIN et Permissions-Policy sont présents; Vercel
  répond toujours `x-vercel-cache: HIT` avec ETag/date inchangés. La protection
  transport est saine, mais ce cache stable confirme le décalage de contenu.
- **Audit dépendances (28/09/2026)** : `npm audit --omit=dev --audit-level=high`
  signale **21 vulnérabilités** (3 critiques, 11 hautes, 5 moyennes, 2 faibles),
  dont `protobufjs` (exécution de code), `swiper` (prototype pollution),
  `websocket-driver`, `ws`, `socket.io-parser`, `fast-uri`, `postcss` et
  `nanoid`; `quill` reste sans correctif disponible. `composer audit` signale
  **8 advisories** sur `laravel/framework`, `maatwebsite/excel`,
  `spatie/laravel-medialibrary` et `firebase/php-jwt`, dont plusieurs hautes.
  Aucun `audit fix` automatique n’a été lancé : plusieurs corrections exigent
  des changements majeurs et une revue de compatibilité/gate.
- **Cartographie des versions (28/09/2026)** : les versions directement
  concernées incluent `firebase@9.23.0`, `swiper@11.2.10` et `vue3-quill@0.3.1`
  qui embarque `quill@1.3.7`; côté PHP, `laravel/framework v9.52.21`,
  `maatwebsite/excel 3.1.67`, `spatie/laravel-medialibrary 10.15.0` et
  `firebase/php-jwt v6.11.1` (tiré par Google API/Auth). Les mises à niveau
  doivent être traitées comme une mission sécurité séparée, pas comme un simple
  nettoyage automatique du lockfile.
- **Sécurité applicative fraîche (28/09/2026)** : `php artisan test
  tests/Feature/Security` passe **221/221 en 67,79 s**; permissions admin,
  rotation de clés, rate limits, anti-hijack signup, CSP et désactivation
  super-admin restent verts. En sondage live sans session, plusieurs chemins
  `/api/*` non reconnus (`/api/orders`, `/api/settings`, `/api/kiosk/config`)
  renvoient HTTP 200 avec le shell HTML au lieu d’un 404/401 JSON; ce n’est pas
  une fuite observée, mais c’est un risque d’observabilité/client (un appel API
  peut recevoir une page HTML valide en HTTP 200). À clarifier dans le routage
  API avant de conclure à une protection live complète.
- **Confirmation fallback API (28/09/2026)** : `php artisan route:list --path=api`
  ne déclare pas `/api/orders`, `/api/settings` ni `/api/kiosk/config`; sur le
  VPS, ces trois chemins répondent **200 HTML**, y compris avec
  `Accept: application/json`. Le comportement vient donc du fallback SPA,
  pas d’une route métier exposée, mais le contrat HTTP reste ambigu et doit
  être corrigé ou explicitement documenté pour éviter qu’un client croit avoir
  reçu une réponse API valide.
- **Intégrité métier fraîche (28/09/2026)** : les suites PHPUnit passent
  `tests/Feature/Order` **109/109 en 24,25 s**, `tests/Feature/Loyalty`
  **93/93 en 22,25 s** et `tests/Feature/Outbox` **81/81 en 17,84 s**.
  Elles couvrent l’idempotence des paiements/points, la parité des services,
  les transitions d’état, l’isolation par branche, les prix scellés et le
  dispatch après commit. Aucun échec actuel; la transaction POS réelle reste
  non vérifiée faute de compte de test provisionné.
- **Smoke E2E complet frais (28/09/2026, 02:51–02:53 CEST)** : les cinq
  surfaces critiques passent **22/22 en 1,5 min** sous Node 20.20.2 : auth/F5
  POS, caisse cash, borne, KDS et synchronisation rupture multi-branche. Les
  scénarios adversariaux de panier, statuts et quota quotidien passent aussi.
  Les seuls messages restent des dépréciations PHP du serveur de test, sans
  échec fonctionnel.
- **Accessibilité et release guards (28/09/2026, 02:53 CEST)** : les suites
  axe/composables/drawer/checkout passent **27/27**; pricing lint (86 fichiers),
  status lint (38 fichiers) et bundle budget (15/15) passent. Les tests A11y
  émettent toutefois des erreurs réseau `ECONNREFUSED 127.0.0.1:3000` et un
  fallback IndexedDB→localStorage; ils restent verts grâce aux mocks, mais un
  environnement réel sans API disponible peut donc masquer un écran dégradé.
- **Dette i18n quantifiée (28/09/2026, 02:53 CEST)** : `npm run i18n:audit`
  termine en échec contrôlé : Vue manque fr 11/en 112/ar 644/de 922/bn 923
  clés; Laravel manque fr 5/en 21/ar 62/de 89/bn 86. Les 80 fichiers Laravel
  sont parsés sans erreur, mais cette dette peut produire des libellés anglais
  ou des clés brutes sur des parcours non couverts.
- **Borne et restauration revalidées (28/09/2026, 02:54 CEST)** :
  `tests/Feature/Kiosk` passe **62/62 en 12,58 s** (auto-login, paiement,
  reconciliation, jetons, disponibilité, upsell et isolation); les contrats
  santé/restore ciblés Vitest passent **18/18 en 1,91 s**. Cela confirme la
  robustesse logique locale, mais ne lève pas l’indisponibilité de la borne
  live faute de provisioning machine.
- **Fiscalité/branches/déploiement (28/09/2026, 02:55 CEST)** : suites
  PHPUnit fraîches : Fiscal **307 passés, 8 skipped en 69,93 s**, Branch
  **20/20**, Deploy **5/5**. Les 8 skips exigent MySQL/MariaDB pour vérifier
  les triggers `SIGNAL`; un test supplémentaire reste conditionné au LOCK
  M6-002 non contresigné (split `order_payments`). La seed de menu émet aussi
  des warnings : 12 catégories attendues sont inconnues et trois libellés
  contiennent « Sandwich/Burger »; l’intégrité technique passe, mais la
  couverture catalogue/traduction réelle n’est pas complète.
- **Recontrôle publication/santé (28/09/2026, 02:57 CEST)** : le HTML public
  conserve le SHA `91ea556e…332c48bf`, quatre mentions « Uber Eats » et zéro
  phrase cible; le dépôt externe reste identique à `origin/main` avec **58**
  fichiers dirty. Le VPS reste healthz 200/queue 0, tandis que readiness 200
  conserve `restore_drill=degraded`. Aucun changement développeur n’est visible
  sur la surface déployée.
- **Vitest complet (28/09/2026, 02:58–03:01 CEST)** : **554 fichiers verts,
  1 en échec; 4 497 tests passés, 5 échoués, 3 skipped; 1 erreur non gérée**.
  Les cinq échecs sont regroupés dans `tests/js/playwrightConfig.spec.js` et
  proviennent du runtime local Node **18.20.7**, incompatible avec Playwright
  (Node 20 minimum). L’erreur non gérée vient de l’interopérabilité ESM/CJS
  `html-encoding-sniffer`/`@exodus/bytes` chargée par jsdom. La suite métier
  reste largement verte, mais ces deux défauts d’outillage empêchent un PASS
  global honnête et doivent être rejoués sous Node 20+ après alignement des
  dépendances.
- **Vitest complet revalidé sous Node 20.20.2 (28/09/2026, 03:02–03:06 CEST)** :
  **556 fichiers passent, 4 511 tests passent, 3 skipped, zéro erreur non
  gérée**. Les cinq erreurs observées sous Node 18 sont donc un défaut de
  runtime de la machine d’audit, pas une régression applicative. Les warnings
  indirects restent à surveiller : composants Vue non enregistrés, connexions
  `127.0.0.1:3000` refusées, actions Vuex inconnues et clés i18n manquantes.
- **Recontrôle API live (28/09/2026, 03:06 CEST)** : healthz HTTP 200 et queue
  vide; readiness HTTP 200 mais `restore_drill` reste `degraded` car jamais
  mesuré. Trois chemins API non déclarés (`/api/orders`, `/api/settings`,
  `/api/kiosk/config`) renvoient le HTML SPA en HTTP 200 au lieu d’une réponse
  JSON/404 explicite, y compris sous `Accept: application/json`. Risque direct
  pour les clients et le monitoring, sans fuite de données observée.

## Prochaine action A — borne (revalidation après provisioning)

1. Conserver les secrets `KIOSK_MACHINE_*` hors dépôt et surveiller le
   provisioning lors des prochains redéploiements.
2. Rejouer périodiquement `/kiosk/login` → auto-login → `/kiosk/idle`.
3. Preuves actuelles : test interactif catégories/produits **5/5**, healthz
   toujours 200; aucun secret n’est stocké dans le rapport.

## Prochaine action B — contenu public

1. Identifier le dépôt/surface qui sert `lecayenne.fr` (hors checkout FoodKing
   versionné) et faire valider le texte cible par le propriétaire.
2. Déployer uniquement la copie validée : « Commander à emporter »,
   « Confirmer à emporter », « Livraison par nos livreurs bientôt ».
3. Vérifier en Chrome le footer/checkout public et conserver une capture ou un
   DOM snapshot; aucun panier/compte réel ne doit être modifié durant le test.

## Prochaine action C — compte E2E POS distant

1. Le propriétaire déploie/active un compte POS de test dédié (ou fournit les
   variables `E2E_POS_USER`/`E2E_POS_PASS` valides hors dépôt).
2. Rejouer `PLAYWRIGHT_BASE_URL=https://vps-418872ac.vps.ovh.net npx playwright
   test --config=playwright.config.js tests/e2e/01-auth-refresh.spec.js`.
3. Clôturer uniquement avec 2/2 scénarios verts et URL `/admin/pos` conservée
   après F5; ne pas modifier le code auth sur la seule base d’un compte rejeté.

## Gate frozen

Ne pas mettre à jour `frozen-zone-sha256-baseline.json` tant que le sign-off
propriétaire du lock n’est pas explicite. Après gate uniquement : mise à jour
atomique baseline + fichier frozen, puis sentinel ciblé et suite globale.

## Routage QA

- Provisioning et gate : validation propriétaire / déploiement, pas d’édition
  automatique par l’agent.
- Contenu public : cycle séparé, surface à identifier avant toute modification.
- Validation finale : `playwright-critical-flow` puis relecture du rapport principal.

- **Rejeu public HTTP (28/09/2026, 03:07 CEST)** : le site public est sain
  techniquement (HTTP 200, cache HIT, headers de sécurité présents), mais son
  contenu reste hors demande : **4** « Uber Eats », aucune formulation emporter
  / livraison par nos livreurs. C’est une preuve live de non-déploiement du
  copy attendu, pas un défaut que les tests locaux peuvent masquer.
- **Invariant guard (28/09/2026, 03:08 CEST)** : le script CI retourne **FAIL
  (3 invariants / 19 hits)**. Le hit critique est la lecture de `branch_id`
  depuis le payload dans `FrontendOrderService:175`; les événements Item sont
  techniquement protégés par le trait `DispatchableAfterCommit`, et les hits
  d’audit mélangent code réel, imports et commentaires. À traiter comme
  risque de sécurité/isolation et comme dette de précision du guard, sans
  auto-approuver ces violations.
- **PHPUnit Feature complet (28/09/2026, après 03:08 CEST)** : **5 709 passés,
  1 échec, 4 incomplets, 36 skipped**. L’échec bloquant est le sentinel
  `FrozenZoneSha256BaselineSentinelTest` :
  `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` a dérivé
  de la baseline (`fcbe3755…` → `f8ecb111…`). Comme aucun LOCK/sign-off n’est
  fourni, la baseline n’a pas été modifiée et le fichier n’a pas été revert.
  Les 4 incomplets et 36 skips sont documentés par les contraintes SQLite,
  websockets réels et gates frozen/onboarding. Les tests pricing/suppléments,
  fidélité et isolation de branche passent dans cette même exécution.
- **Unit + bundle (28/09/2026, 03:32 CEST)** : PHPUnit Unit **367/367** et
  contrôle de budget des 15 bundles passent. Le budget n’est donc pas la
  cause du blocage borne observé.
- **Contrat API live (28/09/2026, 03:31 CEST)** : malgré la clé API publique
  lue dans `/login`, `guest-signup`, quote POS et loyalty config renvoient
  **302 vers `/login` avec HTML** au lieu du JSON attendu. Le dépôt local
  contient bien `ApiKeyMiddleware`/`Installed` qui devraient renvoyer JSON :
  écart probable entre code déployé, route cache et VPS à corriger avant tout
  test réel de souscription ou de panier.
- **Load/Rush midi (28/09/2026, 03:34 CEST)** : **4 passés / 2 incomplets**.
  Les scénarios `s72` et `s73` ne prouvent pas encore le paiement HTTP kiosk
  ni la monotonie POS+kiosk mixte; la couverture concurrence inter-surfaces
  reste donc un risque ouvert malgré les invariants POS et multi-branches verts.
- **Audit dépendances (28/09/2026, 03:33 CEST)** : Composer **8 advisories**;
  npm production **21 vulnérabilités (3 critiques, 11 hautes, 5 modérées,
  2 basses)**. Aucun `audit fix` n’a été lancé car plusieurs corrections sont
  breaking et Quill n’a pas de correctif disponible.
- **Artefacts live vs local (28/09/2026, 03:36 CEST)** : les hashes Mix du VPS
  (`app 274114…`, `vendor f9fe8…`, `manifest e6b5…`, `css b919…`) ne
  correspondent pas au manifeste local (`ae40…`, `293c…`, `a68c…`, `561b…`).
  Le vendor date du 02/09 tandis que l’app date du 27/09 : le déploiement est
  probablement partiel ou son cache incohérent. Toute validation web doit
  désormais être rattachée au hash effectivement servi.
- **Revalidation API corrigée (28/09/2026, 03:40 CEST)** : avec la clé live et
  la méthode attendue, guest signup invalide donne **422 JSON**, loyalty config
  **200 JSON**, quote POST non authentifié **401 JSON**. L’alerte précédente
  « 302 généralisés » est annulée. Reste un problème de contrat pour les
  mauvaises méthodes : GET sur des routes POST API revient en **200 HTML SPA**
  au lieu d’un 405/JSON explicite, ce qui reste un risque de monitoring.
- **E2E réel VPS borne (28/09/2026, 03:45 CEST)** : Playwright Chromium sous
  Node 20.20.2 passe **4/5** tests; le cinquième est skipped car le VPS ne
  possède pas de borne provisionnée. La page login, le montage Vue, l’absence
  d’erreur fatale et `kioskMenuPricing` sont vérifiés, mais la navigation et
  l’ajout panier tactile restent non vérifiés en environnement réel.
- **E2E public Chromium (28/09/2026, 03:50 CEST)** : homepage et hash menu
  fonctionnels, aucun log console critique; `carte.html`, horaires, CGV et
  confidentialité sont 200. Le deep-link `/menu` est toutefois **404** alors
  que c’est une URL attendue intuitivement : dette SEO/compatibilité à traiter
  séparément du contenu public non déployé.
- **CTA « Commander » (28/09/2026, 03:55 CEST)** : les cinq boutons publics
  portant « Commander » ou « Voir le menu » redirigent tous vers `/#menu`;
  aucun ne démarre un checkout, le choix à emporter ou le paiement. Le panier
  peut s’ouvrir mais reste vide. Le parcours commande public est donc
  fonctionnellement absent, au-delà du défaut de wording déjà signalé.
- **Pages commande/livraison (28/09/2026, 04:00 CEST)** : les pages dédiées
  sont 200 mais les CTA « Commander en ligne » pointent tous vers `/`; aucune
  composition de produit ni paiement n’est accessible malgré le texte qui les
  promet. La livraison Uber Eats est seulement documentée, pas intégrée au
  flux de commande interne.
- **Correction E2E commande public (28/09/2026, 04:08 CEST)** : le parcours
  réel depuis `/#menu` fonctionne : Tacos M + Mexicanos + Harissa, panier à
  **6,90 €**, upsells, puis écran retrait/paiement atteint sans erreur réseau
  ou JS. Aucun email/code/ordre n’a été envoyé. Le problème est UX/deep-link :
  les CTA des pages dédiées repartent vers `/` au lieu de garder le contexte;
  le précédent verdict « checkout totalement absent » est annulé.
- **Fidélité/inscription publique (28/09/2026, 04:15 CEST)** : le parcours
  Fidélité → Créer un compte ouvre correctement l’étape 1/2. Une adresse
  invalide est rejetée avec un message français explicite, sans page blanche,
  erreur JS ou réponse réseau ≥400. L’envoi d’un vrai code n’a pas été déclenché
  afin de ne pas créer de compte ou envoyer un email réel.
- **Prix adversarial public (28/09/2026, 04:22 CEST)** : scénario réel Tacos M
  avec viande, sauce incluse, seconde sauce et Cheddar : **6,90 → 7,40 →
  8,30 €**, panier stable à **8,30 €**, choix conservés et +83 points.
  Aucun crash/HTTP ≥400; encaissement réel non déclenché.
- **KDS/cuisine (28/09/2026, 03:42 CEST)** : suite ciblée **48/48 tests JS**
  et **26/26 tests PHP** passés. HH, X, sauces frites séparées, suppléments
  quantifiés et tacos sans taille sont donc verrouillés en tests locaux. Il
  manque encore la vérification sur l’imprimante/écran physique provisionné.

**VERDICT QA LOOP : NEEDS_OWNER_ACTION**

- **Paiement public — modes visibles (28/09/2026, 03:52 CEST)** : l'écran live
  `/#payment` expose bien les deux options `Payer sur place` et `Carte bancaire
  (en ligne)` sous forme de radios, avec le total affiché à **24,20 €** et le
  bouton de confirmation présent. Le clic automatisé sur la radio carte a
  échoué car l'élément était hors viewport dans la session Chrome; aucune
  confirmation, aucun paiement Mollie et aucune donnée sensible n'ont été
  envoyés. Ce n'est pas classé comme régression fonctionnelle, mais le test
  carte reste à refaire sur une session fraîche/viewport maîtrisé.
- **Régression/obsolescence du test public Wave B (28/09/2026, 03:57 CEST)** :
  `tests/e2e/_audit-B-home-legal-2026-07-21.spec.js` a été rejoué sur
  `https://www.lecayenne.fr` avec Chromium Node 20.20.2. Le test échoue sur
  les sélecteurs historiques `hero`, bouton Facebook et galerie (0 élément),
  alors que le parcours public moderne testé séparément fonctionne et qu'aucun
  log console ni HTTP 4xx/5xx n'a été observé. Les assertions sont donc
  obsolètes ou signalent une disparition de contenu à arbitrer; les cinq tests
  légaux n'ont pas produit de verdict exploitable car la suite est sérialisée
  derrière l'échec B1.
- **Dashboard admin live non concluant (28/09/2026, 03:59 CEST)** :
  `09-admin-dashboards-ui.spec.js` est resté bloqué plus de quatre minutes
  pendant le flux login/chargement VPS; le processus a été arrêté pour éviter
  un faux résultat. Il faut un run borné sur session authentifiée fraîche.
- **Health live (28/09/2026, 03:59 CEST)** : `/api/health` répond 200 JSON,
  DB/Redis/queue indiqués OK, mais le champ `version` vaut **dev** sur le VPS.
  C'est un signal de configuration/déploiement à traiter avant une clôture
  production, même si le endpoint de santé est vert.
- **Matrice HTTP publique (28/09/2026, 04:00 CEST)** : revalidation directe
  Node fetch : `/`, `/#menu`, `commander.html`, `livraison-henin-beaumont.html`,
  `carte.html`, `horaires.html` et les cinq pages légales répondent 200; `/menu`
  répond encore **404**. Les deux pages commande/livraison contiennent des
  CTA `Commander en ligne` avec `href="/"`, donc la promesse de commande
  contextuelle boucle vers l'accueil au lieu de conserver le parcours.
- **Intégrité assets + en-têtes (28/09/2026, 04:03 CEST)** : les 35 assets
  référencés par la homepage publique (JS/CSS/fonts/images) répondent tous 200,
  sans HTML servi à la place d'un asset. En revanche, le VPS `/login` ne
  renvoie ni HSTS, ni CSP, ni `X-Content-Type-Options`, et `/api/health` n'a
  pas HSTS/CSP/Permissions-Policy. La vitrine Vercel possède ces protections;
  l'écart d'en-têtes entre vitrine et VPS est un risque de durcissement/deploy,
  même sans erreur fonctionnelle visible.
- **Cookies VPS (28/09/2026, 04:04 CEST)** : la réponse HTTPS `/login` pose
  `XSRF-TOKEN` et `le_cayenne_session` avec `SameSite=Lax`, mais aucun des deux
  n'a l'attribut `Secure`. La session est bien `HttpOnly`; l'absence de
  `Secure` reste un défaut de durcissement à corriger dans la configuration
  Laravel/proxy avant exposition production.
- **Scan bundles et CORS (28/09/2026, 04:07 CEST)** : les bundles live
  contiennent encore des replis `127.0.0.1:8766`, mais les metas déployées
  remplacent correctement la base par le VPS HTTPS et `menu-image-base` par
  `assets/menu/`; aucun appel localhost n'est prouvé dans le runtime observé.
  Le CORS restreint bien l'origine à `https://www.lecayenne.fr`. Écart restant :
  les réponses OPTIONS annoncent `Allow-Methods: POST` même pour `/api/health`
  GET et renvoient un content-type HTML sur 204; à aligner pour des clients
  stricts, sans impact reproduit sur le checkout actuel.
- **Gardes dépôt (28/09/2026, 04:12 CEST)** : pricing guard et OrderStatus
  guard passent (86 et 38 fichiers); le pricing guard émet toutefois un
  avertissement `signoff-pending` jusqu'au 27/10/2026. Le contrôle budgets
  bundles passe 15/15. L'audit i18n échoue : 11 clés FR Vue, 112 EN, 644 AR,
  922 DE, 923 BN manquantes (et 5/21/62/89/86 côté Laravel). Ce n'est pas un
  crash du checkout observé, mais c'est une dette de traduction mesurable qui
  peut laisser des libellés vides sur les surfaces non françaises.
- **Contrats auth live revalidés (28/09/2026, 04:16 CEST)** : les vraies routes
  publiques `/api/auth/guest-signup/email-otp`, `email-login` et `verify`
  répondent 422 JSON avec corps vide (validation serveur explicite); loyalty
  config répond 200 JSON avec la clé publique attendue; quote POST non
  authentifié répond 401 JSON. Le précédent essai sur
  `/api/frontend/loyalty/guest-signup` était une route inexistante et ne doit
  pas être interprété comme panne du parcours fidélité.
- **Readiness/temps réel VPS (28/09/2026, 04:20 CEST)** : `/api/health/ready`
  répond **200 `status: ok`** tout en exposant `broadcast_config.broadcast=log`
  (pas de broadcast temps réel) et `restore_drill.status=degraded` (aucune
  restauration mesurée). `/api/healthz` annonce aussi websocket `ok` malgré le
  driver `log`. Le contrat de readiness est donc trop permissif et peut
  déclarer la caisse/KDS saine alors que les événements temps réel et la preuve
  de restauration ne sont pas opérationnels. `/api/health/live` renvoie par
  ailleurs `OK` avec un content-type HTML au lieu d'un JSON uniforme.
- **Abus contrôlés / CORS (28/09/2026, 04:24 CEST)** : 7 requêtes invalides
  consécutives sur `guest-signup/email-login` donnent 422 jusqu'à la 5e puis
  429 avec `Retry-After: 59`; le throttle est donc actif sans envoyer d'email.
  Les origines `https://evil.example` et `null` reçoivent 200 mais aucun
  `Access-Control-Allow-Origin`, donc le navigateur ne peut pas lire la
  réponse. Les health-checks sont `no-cache, private` et varient bien par
  Origin.
- **Responsive mobile public (28/09/2026, 04:30 CEST)** : Chromium 390×844
  charge la homepage en 200, sans erreur JS ni réponse >=400, avec largeur
  document 390 = viewport 390 (aucun overflow horizontal). Le bouton Menu
  ouvre `/#menu`, affiche les 9 catégories et 39 produits; aucun écran blanc
  n'a été reproduit sur ce parcours mobile.
- **Personnalisation mobile (28/09/2026, 04:34 CEST)** : sur le même viewport,
  Tacos M → Personnaliser → Mexicanos → Continuer ouvre bien l'étape sauce;
  les 15 choix de sauce et le prix 6,90 € restent visibles, largeur 390 =
  viewport 390, sans erreur JS ni HTTP >=400. Le cas mobile multi-sauce reste
  à compléter jusqu'au panier, mais aucun blocage n'est reproduit aux étapes
  1–2.
- **Deux sauces mobile (28/09/2026, 04:39 CEST)** : en sélectionnant Mexicanos,
  puis Harissa et Andalouse, l'étape sauce affiche `2 sélectionnés`,
  `Sauce en plus : +0,50 € chacune (× 1)` et le total **7,40 €**; l'étape 3
  suppléments s'ouvre à 390 px sans overflow ni erreurs JS/HTTP. La tentative
  d'automatisation de la suite a ciblé un libellé emoji exact et a été arrêtée
  sans soumettre de commande; ce n'est pas classé comme panne produit.
- **Panier mobile multi-sauce complet (28/09/2026, 04:45 CEST)** : après
  `Ajouter au panier` puis attente de la vérification serveur, le panier contient
  exactement **1 article Tacos M**, `Mexicanos, Harissa, Andalouse`, sous-total
  et total **7,40 €**, fidélité **+74 pts**, bouton `Passer commande` visible.
  Aucun écran blanc, erreur JS ou HTTP >=400; aucune commande n'a été confirmée.
- **Conservation avant modification (28/09/2026, 04:52 CEST)** : le panier
  nouvellement rempli conserve bien `Mexicanos, Harissa, Andalouse`, total
  **7,40 €** et **+74 pts** après la vérification serveur. Le clic sur le
  contrôle visuel `MODIFIER` n'a pas pu être rendu déterministe par le harnais
  (aucun rôle/bouton accessible stable); la perte de sauces pendant une vraie
  modification reste donc un cas ouvert à valider manuellement ou via un
  sélecteur dédié.
- **Régression POS deux sauces + édition (28/09/2026, 04:12 CEST)** : le test
  Playwright dédié `pos-two-sauces-edit-e2e.spec.js` passe **1/1 en 12,3 s**
  sur Laravel local : Andalouse + Algérienne survivent à l'ouverture du panier,
  la réouverture de l'éditeur et la confirmation, prix et détail inchangés.
  Le test vide le panier avant paiement. Le serveur local émet seulement des
  warnings PHP de dépréciation `smartisan/laravel-settings`; aucun échec métier.
- **Supplément manuel POS (28/09/2026, 04:13 CEST)** : `pos-manual-supplement-
  e2e.spec.js` passe **1/1 en 7,3 s** en local. Le caissier saisit `Olives` et
  `1,25`, la ligne panier affiche le libellé et le montant, et le grand total
  contient `1,25`. Le flux reste avant paiement et ne crée aucun effet externe.
- **Impression/ticket backend (28/09/2026, 04:13 CEST)** : suites ciblées
  PHPUnit **21/21 passées** : ReceiptPrintController 10/10 (idempotence,
  audit, isolation de branche, auth), PosReceiptPrintFlow 3/3 (client/cuisine),
  PosTicketBytesEndpoint 3/3 et CounterCollectAndPrintIdempotency 5/5. Cela
  confirme les bytes ESC/POS et le non-double-compteur côté backend. Les specs
  navigateur d'impression historiques codent encore le port `8766`; elles
  doivent être réalignées sur le port standard avant un verdict E2E navigateur.
- **Pricing/quote/isolation (28/09/2026, 04:15 CEST)** : **27/27 PHPUnit
  ciblés passés** (quote binding et supplément manuel fiscal, variation
  obligatoire, anti-tamper/replay, remise backend, isolation cashier/KDS,
  intégrité quote kiosk et override du `branch_id` forgé). Aucun total client
  falsifié ni fuite inter-branche n'est accepté par ces scénarios.
- **KDS/borne/tickets (28/09/2026, 04:17 CEST)** : **19/19 PHPUnit passés** :
  autorisation chef vs caisse sur bytes cuisine/client, board actif et ordre
  stable, historique du jour borné à 50 avec fuseau Paris, endpoint ESC/POS
  borne client+cuisine, et encaissement borne différé. Isolation de branche et
  refus sans auth confirmés; aucun test matériel réel d'imprimante n'est inclus.
- **E2E impression navigateur réel (28/09/2026, 04:14 CEST)** : après
  réutilisation du serveur local attendu sur `127.0.0.1:8766`, les deux tests
  espèces/carte passent **2/2 en 40,4 s** : la question d'impression apparaît,
  `Non merci` n'imprime pas et `Oui, imprimer` déclenche la vraie requête.
  Les deux tests d'encaissement téléphone passent aussi **2/2 en 41,9 s** :
  aucune impression automatique, refus sans impression, et impression explicite
  via `/escpos`. Le VPS et l'imprimante physique restent hors de cette preuve.
- **Fidélité borne E2E (28/09/2026, 04:16 CEST)** : les specs dédiées passent
  **4/4** (inscription rapide 2/2 en 4,6 s; contrôle solde réel 2/2 en 3,1 s).
  Numéro inconnu : bascule automatique vers prénom unique, numpad qui déclenche
  au 10e chiffre, sans retaper le téléphone. Code réel : solde exact affiché;
  code inconnu : erreur visible, jamais page blanche. Aucun email réel envoyé
  (register mocké uniquement dans le scénario d'inscription pour éviter le
  throttle externe).
- **Inscription fidélité complète borne (28/09/2026, 04:18 CEST)** : la spec
  `kiosk-loyalty-register-e2e.spec.js` passe **1/1 en 2,5 s** avec prénom,
  téléphone, email, consentement RGPD et affichage du solde après register.
  Aucun écran blanc, erreur page/console, ni commande/notification réelle
  (endpoint register intercepté pour isoler l'UI; contrat serveur couvert par
  les tests Feature).
- **Contrats fidélité serveur (28/09/2026, 04:20 CEST)** : **51/51 PHPUnit
  ciblés passés** : email OTP (18), durcissement guest OTP (6), scope token
  (1), inscription borne/conservation email (6), variantes/canonicalisation
  téléphone (7), liaison compte web (7), unicité téléphone (2) et absence de
  fuite PII (4). Les conflits email, comptes invités et formats 06/+33 sont
  explicitement couverts; aucune donnée d'un autre compte n'est divulguée.
- **Admin dashboard smoke (28/09/2026, 04:24 CEST)** : le test historique
  `tests/e2e/09-admin-dashboards-ui.spec.js` reste non fiable tel quel : il
  attend un bouton accessible nommé `Login`, alors que l'interface rend
  `Connexion`; le runner a donc attendu jusqu'à être tué, sans verdict produit.
  Rejeu direct corrigé sur `127.0.0.1:8766` avec `#formEmail`,
  `#formPassword` et `Connexion` : redirection réelle vers
  `/admin/dashboard`, titre `Le Cayenne`, chrome admin et contenu dashboard
  visibles, zéro URL `/api/api/`. Les seules erreurs console sont les
  WebSocket Pusher `127.0.0.1:6001` refusés (service realtime local non lancé),
  à traiter comme risque d'infrastructure séparé; elles n'empêchent pas le
  rendu HTTP du dashboard.
- **Smoke VPS après reprise (28/09/2026, 04:24 CEST)** : `GET /admin/dashboard`
  répond **200 HTML**, `GET /api/health` répond **200 JSON** (`status: ok`) et
  la vitrine `https://www.lecayenne.fr/` répond **200 HTML**. Cela confirme que
  les surfaces HTTP sont joignables; cela ne clôt pas le risque temps réel
  Pusher ni le mismatch d'artefacts/hash déjà signalé.
- **Matrice routes re-testée (28/09/2026, 04:25 CEST)** : sur le VPS backoffice,
  `/` et `/menu` redirigent vers `/login` (302), tandis que `/admin/dashboard`
  reste servi en HTML 200; ce domaine n'est donc pas la vitrine publique.
  Sur `www.lecayenne.fr`, `/` et les pages statiques (`carte.html`,
  `commander.html`, `livraison-henin-beaumont.html`) répondent 200, mais
  `/menu` répond toujours 404. Les pages commander/livraison contiennent
  plusieurs CTA `href="/"`, qui renvoient l'utilisateur à l'accueil plutôt
  qu'au parcours de commande : défaut UX/routage reproductible, non corrigé.
- **Headers/cookies VPS re-testés (28/09/2026, 04:25 CEST)** : `/login` émet
  les cookies `XSRF-TOKEN` et `le_cayenne_session` avec `SameSite=Lax`, mais
  sans attribut `Secure`; `/login` n'expose pas HSTS, CSP, ni
  `X-Content-Type-Options`. `/api/health` n'expose que `Referrer-Policy`.
  À comparer à la vitrine `www` qui expose déjà `Permissions-Policy` et
  `Referrer-Policy`. Risque de durcissement/déploiement incomplet à traiter
  côté reverse-proxy/production, sans modifier les secrets ni contourner le
  contrôle de sécurité dans ce cycle.
- **Préflight CORS (28/09/2026, 04:26 CEST)** : pour `/api/health`,
  `/api/health/ready`, `loyalty/config` et `order/quote`, la réponse OPTIONS
  est 204 mais annonce systématiquement `Access-Control-Allow-Methods: POST`,
  y compris pour les health-checks GET; le content-type reste HTML. Le
  navigateur tolère souvent ce préflight simple, mais le contrat est incohérent
  et peut casser des sondes/clients stricts. À corriger côté middleware CORS,
  sans affaiblir la liste d'origines autorisées.
- **Copy livraison/emporter revalidé (28/09/2026, 04:27 CEST)** : la vitrine
  contient bien `à emporter`, mais le texte live précise encore que la
  livraison passe par **Uber Eats** et ne contient ni `livreur` ni la mention
  attendue « livré par nos livreurs bientôt ». Le rapport ancien qui disait
  « aucune formulation emporter » est donc corrigé : le défaut actuel est la
  promesse de livraison interne absente, pas l'emporter.
- **Contrat méthode API re-testé (28/09/2026, 04:28 CEST)** : sans clé
  publique, les POST `guest-signup/email-otp` et `loyalty/config` refusent
  proprement en JSON (400), et `POST order/quote` envoie 401 JSON. En revanche,
  les GET sur les deux routes POST (`/api/frontend/order/quote` et
  `/api/auth/guest-signup/email-otp`) renvoient encore **200 HTML SPA** au lieu
  de 405 JSON. C'est une détection directe d'une erreur de méthode masquée,
  gênante pour les sondes et intégrations strictes; aucune commande n'a été
  créée.
- **CSP VPS re-testée (28/09/2026, 04:29 CEST)** : le backoffice renvoie
  uniquement `Content-Security-Policy-Report-Only`, donc la politique n'est
  pas bloquante en production; elle autorise en outre des connexions vers
  `localhost:9100/9101` et `127.0.0.1`. La vitrine publique, elle, expose une
  CSP active. Ce décalage peut masquer une régression de sécurité ou des
  dépendances locales oubliées sur la borne/caisse.
- **Scan mixed-content public (28/09/2026, 04:29 CEST)** : aucun script/CSS
  réellement chargé depuis `http://localhost` ou `127.0.0.1` n'a été trouvé
  dans les bundles publics; la seule occurrence est un commentaire HTML
  documentaire. Ce risque précis n'est donc pas actif sur la vitrine, malgré
  les fallbacks localhost présents dans le code source local.
- **Méthodes HTTP dangereuses (28/09/2026, 04:29 CEST)** : `TRACE /api/health`
  est refusé par nginx (405), `PUT` et `DELETE` retournent 405 JSON, et
  `HEAD /api/health` reste 200 JSON sans corps. Aucun verbe inattendu n'est
  ouvert sur ce endpoint; le défaut reste limité aux mauvaises méthodes GET
  qui tombent dans le catch-all HTML.
- **Validation API avec clé publique (28/09/2026, 04:30 CEST)** : les payloads
  vides/`null`/malformés de `guest-signup/email-otp` sont rejetés en 422 JSON
  avec les champs obligatoires détaillés; aucune notification ou inscription
  n'est créée. Après cinq essais contrôlés, le serveur renvoie bien 429 avec
  `Retry-After`, `X-RateLimit-Limit: 5` et `Remaining: 0`. Le throttle est
  effectif; la fenêtre IP partagée doit simplement être prise en compte dans
  les tests E2E parallèles.
- **Authz admin/KDS/POS live (28/09/2026, 04:31 CEST)** : avec et sans clé
  publique mais sans session Sanctum, les routes réelles dashboard, KDS,
  online-order, POS-order et customer renvoient bien 401 JSON; aucune donnée
  métier n'est exposée. Le endpoint public `frontend/order/wait-estimate`
  répond 200 avec une estimation neutre. En revanche, un GET accidentel sur
  `admin/pos/kitchen-tickets/pending` (route POST) retombe en 200 HTML SPA,
  confirmant que le masquage des mauvaises méthodes touche aussi les surfaces
  POS/KDS.
- **Contrat authentification live (28/09/2026, 04:32 CEST)** : `POST
  /api/auth/login` avec la clé publique valide renvoie 422 JSON pour des
  identifiants invalides/incomplets; sans clé il renvoie 400 JSON. `POST
  /api/auth/logout` sans session renvoie 401 JSON. Le formulaire web `/login`
  n'est pas un endpoint de soumission (POST direct = 405 JSON), ce qui est
  cohérent avec le login SPA qui appelle l'API; aucun faux écran de succès ni
  session créée.
- **CORS origines proches (28/09/2026, 04:33 CEST)** : l'origine publique
  `https://www.lecayenne.fr` et son alias HTTPS apex sont autorisés; slash
  final, HTTP, port explicite, casse différente et domaine suffixé malveillant
  ne reçoivent aucun `Access-Control-Allow-Origin`. La réponse varie bien sur
  `Origin`; aucune fuite CORS n'a été reproduite.
- **Vérification boucle locale (28/09/2026, 04:34 CEST)** : `npm run
  verify:boucle` passe la validation de cycle et trouve `claude` installé, mais
  reste conditionnel car les smoke tests API Claude/Codex sont désactivés par
  défaut (`VERIFY_BILLING_FULL=1` requis). Ce n'est pas un défaut produit,
  mais une limite de traçabilité de la validation agentique actuelle.
- **Crawl liens publics/PWA (28/09/2026, 04:35 CEST)** : 22 ressources
  same-origin issues des pages publiques (pages menu, légales, horaires,
  manifest et assets) répondent 200; aucun lien interne mort ou redirection
  inattendue n'a été découvert dans ce périmètre. Les quatre icônes PWA du
  manifest répondent également 200 en `image/png`. Le 404 `/menu` reste donc
  un deep-link isolé non référencé par les liens HTML crawlé.
- **SEO canonical live (28/09/2026, 04:36 CEST)** : `/`, `carte.html`,
  `commander.html` et `livraison-henin-beaumont.html` portent chacun un
  canonical et un `og:url` cohérents avec leur URL HTTPS; aucun canonical
  croisé ou HTTP n'a été détecté dans cet échantillon.
- **Scan systématique routes API (28/09/2026, 04:37 CEST)** : 183 routes GET
  statiques déclarées ont été sondées avec `Accept: application/json` et la
  clé publique; aucune réponse 5xx n'est apparue et 182 réponses étaient
  non-HTML/attendues. L'unique anomalie est `/api/health/live`, qui répond
  encore 200 `text/html` (`OK`) au lieu d'un JSON de santé uniforme. Cela
  confirme que le problème de contrat n'est pas généralisé à toutes les routes
  déclarées, mais concentré sur ce health endpoint et les mauvaises méthodes
  tombant dans le catch-all.
- **Scan POST protégés (28/09/2026, 04:38 CEST)** : 98 routes POST statiques
  nécessitant une authentification ont été appelées avec un corps vide et la
  clé publique, sans session. Zéro 200 HTML, zéro 5xx et aucune mutation : les
  garde-fous d'authentification rejettent correctement ces appels.
- **Scan mutations protégées (28/09/2026, 04:39 CEST)** : 16 routes statiques
  PUT/PATCH/DELETE protégées ont été sollicitées sans session; zéro 200 HTML,
  zéro 5xx et aucune écriture observée. La surface mutationnelle testée est
  correctement refusée avant validation métier.
- **Scan GET paramétrés (28/09/2026, 04:40 CEST)** : 107 routes avec
  paramètres ont été sondées avec des IDs/tokens inexistants. Quatre routes
  renvoient 200 HTML SPA au lieu d'une réponse API d'absence/autorisation :
  `admin/ingredients/{id}`, son endpoint `usage`, et les deux suivis publics
  `order/track`/`track-qr`. Surtout, `frontend/offer/show/{slug}` renvoie une
  **500 JSON `Server Error`** pour un slug inexistant. Le contrôleur local
  appelle le service avec un modèle nul, ce qui explique une erreur serveur au
  lieu d'un 404/422 maîtrisé; défaut produit reproductible à corriger.
- **Fuzz slug offre (28/09/2026, 04:41 CEST)** : le 500 de
  `frontend/offer/show/{slug}` se reproduit pour slug inconnu, guillemets,
  espaces encodés, Unicode et `null`; la réponse reste générique sans stack
  trace, mais le statut 500 est incorrect pour une ressource publique absente.
  Les tokens de suivi invalides continuent de tomber en HTML SPA; `%00` est
  bloqué proprement par nginx en 400.
- **Suivi token valide/invalide (28/09/2026, 04:42 CEST)** : avec un token de
  48 caractères au format contractuel mais inexistant, `order/track` renvoie
  correctement `200 {found:false}` et `track-qr` renvoie 404 JSON. Les retours
  HTML observés précédemment concernent seulement les tokens de longueur ou
  caractères hors contrainte, qui sont interceptés par le catch-all SPA; c'est
  une dette de contrat HTTP/monitoring, pas une fuite de commande.
- **Couverture test offre (28/09/2026, 04:43 CEST)** : aucune spec sous
  `tests/` ne cible actuellement `frontend/offer/show` ou un slug inexistant;
  le 500 live n'était donc pas protégé par une régression automatisée. Une
  correction devra ajouter au minimum le cas 404/422 avant de considérer le
  endpoint couvert.
- **Scan routes publiques sans clé (28/09/2026, 04:44 CEST)** : les 5 routes
  GET statiques explicitement exemptées d'auth ont été sondées sans clé. Seuls
  `/api/login` (401 JSON attendu) et `/api/health/live` (200 HTML déjà signalé)
  sont notables; aucune route publique inattendue n'expose de données métier.
- **Catch-all API inconnu (28/09/2026, 04:45 CEST)** : quatre chemins
  volontairement inexistants (`/api/definitely-not-a-route`, frontend, admin,
  v1) répondent tous **200 `text/html`** malgré `Accept: application/json`.
  Le catch-all SPA masque donc aussi les fautes de chemin, pas seulement les
  mauvaises méthodes; cela peut faire croire à un client/monitoring qu'une API
  existe et dégrade fortement la détection d'incidents.
- **E2E navigateur pages légales (28/09/2026, 04:46 CEST)** : Chromium et un
  User-Agent Chrome reproduisent des **404** sur les anciens aliases
  `/mentions-legales.html`, `/politique-confidentialite.html` et
  `/conditions-generales.html`. Les URLs canoniques réellement liées,
  `/legal/mentions.html`, `/legal/privacy.html` et `/legal/cgv.html`, répondent
  200. Le rapport précédent qui comptait les anciens aliases comme 200 était
  donc obsolète; toute campagne/SEO qui utilise ces trois URLs casse encore.
- **Service worker public (28/09/2026, 04:47 CEST)** : Chromium installe et
  active `sw.js` sur le scope `/`. Le script n'intercepte ni API cross-origin,
  ni POST/paiement; les pages navigables utilisent réseau-d'abord et le cache
  seulement en filet hors ligne. Aucun stale-cache ou erreur console n'a été
  reproduit sur la session fraîche; ce contrôle est PASS.
- **Fallback offline deep-link (28/09/2026, 04:48 CEST)** : après avoir
  préchauffé uniquement `/`, puis coupé le réseau, une navigation vers
  `/carte.html` reçoit 200 mais le document de fallback est la homepage
  (`<title>Le Cayenne…>` au lieu de `La carte du Cayenne…`). Le service worker
  masque donc une page indisponible par une page différente, sans bannière
  hors-ligne; risque UX/SEO indirect à corriger ou à signaler explicitement.
- **Branche publique wait-estimate (28/09/2026, 04:49 CEST)** : le query
  `branch_id` accepte `0`, négatif, texte et IDs arbitraires, toujours en 200
  JSON; le code local caste directement la valeur et ne vérifie pas la branche
  active. Les branches testées sont actuellement vides, donc aucune fuite de
  comptage n'est prouvée en production, mais ce paramètre est un risque
  d'isolation multi-branches dès qu'une seconde branche aura des commandes.
- **Suite locale wait-estimate (28/09/2026, 04:50 CEST)** :
  `WaitEstimateEndpointTest` passe **11/11** (file, statuts, stale, branche
  différente, JSON et throttle). La suite ne couvre toutefois pas un
  `branch_id` arbitraire ou invalide fourni par un client public; le risque
  signalé reste donc non verrouillé par test malgré les scénarios nominaux
  verts.
- **Catalogue public et branch_id (28/09/2026, 04:51 CEST)** : les endpoints
  publics `frontend/item`, `featured-items` et `popular-items` acceptent eux
  aussi `branch_id=0`, `2`, `999999` ou texte et renvoient 200 JSON. Les jeux
  d'items observés sont identiques entre ces valeurs (aucune fuite distincte
  démontrée sur la base live actuelle), mais l'absence de validation/branche
  autorisée élargit le même risque d'isolation déjà relevé sur wait-estimate.
- **Erreur catalogue item inexistant (28/09/2026, 04:52 CEST)** :
  `frontend/item/details/999999` et `item/upsell/999999` renvoient bien 404,
  mais le JSON porte `code: ORDER_NOT_FOUND` et `message: Commande introuvable`.
  C'est un contrat d'erreur copié du domaine commande; un client catalogue
  peut afficher un message faux ou router vers le mauvais écran. Les IDs
  valides 1 et 121 répondent correctement 200.
- **Assets catalogue live (28/09/2026, 04:54 CEST)** : 58 URLs d'images
  distinctes renvoyées par `frontend/item` (thumb/cover/preview, y compris les
  chemins `/storage/...` relatifs) ont été résolues sur le domaine VPS et
  répondent toutes HEAD 200. Aucun produit live ne pointe vers une image
  cassée dans cet échantillon.
- **Auth auxiliaire (28/09/2026, 04:55 CEST)** : `broadcasting/auth` sans
  session renvoie correctement 401 JSON; `refresh-token` sans clé renvoie 400
  JSON. En revanche, `GET /api/auth/authcheck` (route POST) retombe en 200 HTML
  SPA, encore une occurrence du masquage des mauvaises méthodes sur un chemin
  d'authentification.
- **Projection catalogue publique (28/09/2026, 04:56 CEST)** : la réponse
  `frontend/item` expose au navigateur des identifiants internes (`tax_id`,
  `item_type`, `status`, `kds_station`, `order`, IDs de catégorie) en plus du
  prix et du contenu. Aucune donnée personnelle n'est visible et ces champs
  peuvent être nécessaires à l'admin, mais leur présence sur la route publique
  élargit la surface d'information; à arbitrer comme durcissement API (DTO
  public séparé) plutôt qu'une panne fonctionnelle.
- **Preuve branch-isolation catalogue (28/09/2026, 04:57 CEST)** : la branche
  live valide est uniquement `1` (`branch/show/2` et `999999` renvoient 404).
  Pourtant `frontend/item?branch_id=1` marque 3 articles indisponibles
  (Fanta Citron, Glace, Bol Riz — `stock_rupture`), alors que les mêmes items
  deviennent `is_available:true` avec `branch_id=2` ou `999999`. Un client peut
  donc contourner l'overlay de rupture par un ID de branche inexistant; ce
  n'est plus seulement un risque théorique, mais une divergence live
  reproductible à corriger/valider côté quote serveur.
- **Rejeu surface catalogue (28/09/2026, 04:58 CEST)** : le phénomène est
  indépendant de `surface=web|pos`; branche 1 conserve les trois ruptures,
  branche 999999 les efface. Une valeur de surface inconnue est, elle,
  correctement ignorée sans élargir le catalogue. Le défaut est donc bien le
  fallback d'ID de branche, pas le filtre de canal.
- **Détail item branché (28/09/2026, 04:59 CEST)** : la preuve se reproduit
  sur la fiche produit elle-même : `item/details/114?branch_id=1` renvoie
  Fanta Citron `is_available:false / stock_rupture`, tandis que le même appel
  avec `branch_id=999999` renvoie `is_available:true`. Le problème touche donc
  la liste **et** le détail avant panier, pas seulement un badge de catalogue.
- **Kiosk upsell branch bypass (28/09/2026, 05:00 CEST)** :
  `item/kiosk-upsell?branch_id=1` exclut Fanta Citron en rupture, mais la même
  route avec `branch_id=999999` le propose comme upsell 1-tap (`is_available:
  true`). C'est le chemin le plus dangereux pour la borne : un client peut
  sélectionner automatiquement un produit indisponible avant le quote.
- **Catalogue sans branch_id (28/09/2026, 05:01 CEST)** : le même article 114
  est `is_available:true` quand `branch_id` est absent, `false/stock_rupture`
  avec la branche 1, puis `true` avec 999999. Le client qui perd le paramètre
  (cache, URL directe ou bug d'intercepteur) contourne donc aussi les ruptures;
  le backend doit imposer/résoudre la branche au lieu de traiter l'absence comme
  « disponibilité globale ».
- **Kiosk upsell sans branche (28/09/2026, 05:02 CEST)** : un appel sans
  `branch_id` a effectivement inclus l'article 114 en rupture dans les 12
  suggestions 1-tap; avec la branche 1 il est exclu. La sélection étant
  aléatoire, ce résultat a été observé sur une réponse live et confirme le
  risque d'exposition côté borne dès qu'un paramètre est perdu.
- **Matrice GET sans clé exhaustive (28/09/2026, 04:53 CEST)** : sur les 183
  routes GET statiques déclarées, les réponses sans clé sont 153×401, 26×400,
  3×200 JSON (`health`, `ready`, `healthz`) et 1×200 HTML (`health/live`).
  Aucun endpoint métier ne répond 200 sans authentification; l'anomalie de
  format est bien isolée à `health/live`.
- **Quote final contre branche forgée (28/09/2026, 05:03 CEST)** : la suite
  ciblée `KioskQuoteForgesBranchId`, `KioskQuoteIntegrity`, `QuoteBinding` et
  `QuoteTamper` passe **16/16 tests, 54 assertions**. Le quote kiosk remplace
  la branche client par celle de la machine et le commit refuse replay,
  changement d'items, totals falsifiés et cross-branch. Le défaut branch_id
  reste donc un affichage/upsell dangereux, mais le garde-fou de commande
  réelle est confirmé vert.
- **Suites disponibilité locales (28/09/2026, 05:04 CEST)** : les tests
  `PublicMenuAvailabilityChannel`, `ItemDetailsBranchAvailability`,
  `OrderRejectsUnavailableBranchItem` et `KioskUpsellRequiredAttributeExclusion`
  passent **17/17, 49 assertions**. Ils couvrent la branche fournie et le
  rejet au quote, mais acceptent explicitement le fallback « global » quand
  aucun `branch_id` n'est fourni; aucun test ne couvre un ID invalide comme
  `999999`, d'où l'écart live nouvellement démontré.
- **Requête réellement utilisée par la vitrine (28/09/2026, 05:05 CEST)** :
  Chromium confirme que `www.lecayenne.fr` appelle actuellement
  `frontend/item?branch_id=1`; l'affichage live voit donc les ruptures de la
  branche 1. Le risque reste conditionnel à une perte/altération de ce
  paramètre, mais il est exploitable par URL directe et n'est pas neutralisé
  par l'API catalogue elle-même.
- **CORS production trop permissif (28/09/2026, 06:03 CEST)** : avec la clé
  API actuellement injectée dans le HTML public, `OPTIONS` sur le catalogue
  accepte `http://localhost:3000`, `http://localhost:5173`,
  `http://127.0.0.1:3000`, `http://127.0.0.1:9100` et même `https://localhost`,
  en renvoyant `Access-Control-Allow-Origin` égal à l'origine et
  `Access-Control-Allow-Credentials: true`. Les origines externes arbitraires
  (`evil.example`, `evil.localhost`) sont correctement refusées. Cela ne
  permet pas à un site distant de lire la réponse, mais une application
  locale compromise peut effectuer des appels credentialed vers le VPS si un
  opérateur y possède une session; les origines localhost doivent être
  limitées au profil de développement et exclues de la configuration de
  production.
- **Clé publique déployée différente du dépôt local (28/09/2026, 06:02 CEST)** :
  le HTML VPS injecte une clé API qui n'est pas celle des `.env` locaux/
  fixtures historiques. Les appels avec l'ancienne clé retournent 400
  `Clé API invalide`, tandis que la clé injectée permet les routes publiques.
  C'est une dérive de configuration qui peut masquer des régressions en E2E et
  doit être traitée par une source de secret de test dédiée, sans copier la
  clé de production dans les fixtures.
- **CORS sur endpoint authentifié (28/09/2026, 06:05 CEST)** :
  `GET /api/frontend/loyalty/balance` depuis `http://localhost:3000` renvoie
  bien 401 sans session, mais avec `Access-Control-Allow-Origin` égal à
  localhost et `Access-Control-Allow-Credentials: true`; la même requête
  depuis `https://evil.example` n'expose pas ces headers. La règle doit être
  vérifiée sur les appels authentifiés (pas uniquement le catalogue public),
  car une application locale peut alors faire des requêtes avec les cookies
  du VPS si un opérateur y est connecté.
- **Fuzz du paramètre de branche (28/09/2026, 06:08 CEST)** : les valeurs
  `0`, `-1`, `foo`, `null` et espace sur `frontend/item?branch_id=...`
  répondent HTTP 200 et remettent l'article 114 en `is_available:true`, au
  lieu de rejeter la branche ou de conserver la disponibilité de la branche
  active. `1.0` et `01` sont implicitement normalisés vers la branche 1,
  tandis que `1,999999` retombe aussi sur 1. Le défaut est donc un fail-open
  de validation/type et pas seulement le cas d'un ID numérique inexistant;
  il faut une validation stricte (entier positif, branche existante et
  autorisée par la surface) avant toute requête de disponibilité.
- **Propagation du fail-open (28/09/2026, 06:09 CEST)** : le même fuzz sur
  `item/details/114` confirme `is_available:true` pour `0`, `foo`, `null` et
  `999999`; sur `item/kiosk-upsell`, `0` et `foo` reproposent également
  Fanta Citron en 1-tap. Le problème traverse donc les trois projections
  publiques et ne peut pas être traité uniquement dans le composant de liste.
- **Sondes VPS revalidées (28/09/2026, 06:11 CEST)** : `/api/health`,
  `/api/health/ready` et `/api/healthz` répondent tous 200 avec DB, Redis,
  worker, websocket, chaîne fiscale et `queue_pending=0`; le restore drill est
  maintenant `ok` (`daily-2026-09-28.sql.gz`, vérifié il y a 0,0 h). Les
  alertes historiques de queue/restore doivent donc être remplacées par cette
  mesure courante, sans conclure à une panne persistante.
- **Fuzz `limit` upsell (28/09/2026, 06:14 CEST)** :
  `item/kiosk-upsell?limit=-1` renvoie **16 éléments**, alors que la borne
  supérieure documentée est 12; `0`, `foo` et `null` renvoient une liste vide,
  `1.5` est tronqué à 1. Le `min((int)$limit, 12)` laisse donc les valeurs
  négatives sans borne basse et peut supprimer le `LIMIT` SQL (aujourd'hui le
  pool est petit, mais le défaut deviendrait un coût/volume non borné après
  enrichissement du catalogue). Validation stricte `integer|min:1|max:12` à
  ajouter avant la requête.
- **Paramètre branche dupliqué (28/09/2026, 06:16 CEST)** :
  `frontend/item?branch_id=1&branch_id=999999` renvoie Fanta Citron comme
  disponible, alors que l'ordre inverse (`999999&branch_id=1`) le marque en
  rupture. Le parseur prend donc le dernier scalaire et permet de contourner
  une URL correctement générée en lui ajoutant un second paramètre; la
  branche doit être normalisée/rejetée lorsqu'elle apparaît plusieurs fois,
  puis vérifiée côté serveur avant projection.
- **Propagation du doublon (28/09/2026, 06:17 CEST)** : le même ajout de
  paramètre rend l'article disponible dans `item/details/114` et le repropose
  dans `item/kiosk-upsell`; ce n'est donc pas un comportement limité à la
  liste principale.
- **Écran OSS public — branche non validée (28/09/2026, 06:20 CEST)** :
  `oss-order/popular-items?branch_id=1` renvoie le top habituel (Cayenne,
  menus, burgers), alors que `branch_id=999999` ou un doublon
  `1&branch_id=999999` bascule silencieusement vers un autre jeu de 9
  produits. `oss-order` lui-même passe d'une file active à une liste vide
  pour 999999. Ce n'est pas une fuite PII observée, mais c'est une
  incohérence de branche sur l'écran cuisine/public : une borne ou un écran
  mal paramétré peut afficher une file ou des produits qui ne correspondent
  pas à sa branche au lieu d'un refus explicite.
- **Validation abonnement newsletter (28/09/2026, 06:24 CEST)** : le POST
  public `/api/frontend/subscriber` accepte `not-an-email` et `a@b.co` en
  HTTP 201 et crée réellement deux abonnés (IDs live 1 et 2 dans la réponse).
  La FormRequest locale confirme la cause : `email` est seulement
  `required|string|max:100|unique`, sans règle `email`; la validation du
  parcours fidélité ne couvre donc pas cette surface d'inscription parallèle.
  Le test a utilisé des données synthétiques et a laissé ces deux enregistrements
  de test dans la base de production : le développeur doit les supprimer via
  la procédure d'administration/audit prévue, sans suppression aveugle par
  l'agent.
- **Throttle abonnement (28/09/2026, 06:25 CEST)** : après cinq tentatives,
  la même route répond correctement 429 avec `X-RateLimit-Limit: 5`,
  `Remaining: 0` et `Retry-After`; le défaut porte sur la validation du
  contenu, pas sur l'anti-spam.
- **Contrat d'erreur public systématiquement faux (28/09/2026, 06:32 CEST)** :
  les IDs/slugs inexistants de `branch/show`, `language/show`, `page/show`,
  `page-info` et `item/details` répondent 404 mais avec
  `code: ORDER_NOT_FOUND` et `message: Commande introuvable`. Le défaut déjà
  observé sur les offres est transversal au model binding frontend : un
  client peut afficher une erreur de commande pour une branche, une langue ou
  une page absente, et les intégrateurs ne peuvent pas distinguer les domaines
  via le code d'erreur.
