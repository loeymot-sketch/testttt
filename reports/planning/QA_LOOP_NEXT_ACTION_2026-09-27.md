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
