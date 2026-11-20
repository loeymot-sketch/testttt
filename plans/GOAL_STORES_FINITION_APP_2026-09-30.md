# GOAL — Finition globale de l'application Le Cayenne pour l'App Store et Google Play

> Ouvert le 2026-09-30 (`/goal` propriétaire : « test-e2e … méga plan architecturé … corriger
> tout … finition globale et finale de l'application pour Apple et Google … vérifie-moi tout
> jusqu'au bout »). Plan vivant : une tâche est cochée quand elle est **prouvée** (test nommé +
> capture lue), jamais quand elle est écrite. Pipeline par tâche : `ultra-audit-profond` ;
> boucle d'audit : `test-e2e` (équipe GStack + superviseur adverse, convergence = deux cycles
> consécutifs P0+P1 = 0 à constats identiques). Conseiller consulté avant écriture : 9 trous,
> 5 risques de rejet classés, ordre de vagues revu — chaque affirmation porteuse **re-vérifiée**.

## §0 — Préambule

### 0.1 Décision de cible (lue dans le code, pas devinée)
**L'application des stores EST le site `www.lecayenne.fr` empaqueté par Capacitor 8.5** dans
`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/app/` — `appId
fr.lecayenne.app`, iOS 15+ iPhone seul portrait, Android minSdk 24 / targetSdk 36,
`versionCode 1 / versionName 1.0.0` (`app/android/app/build.gradle:7-11`),
`PrivacyInfo.xcprivacy` posé et inscrit dans la cible, captures store dans
`app/store-assets/fiches/`, procédure `app/PUBLICATION.md` (état 19/08), décision d'origine
`plans/GOAL_APP_MOBILE_APPSTORE_2026-09-02.md §0`.
`testttt/mobile/` est un **prototype navigateur** (React UMD + Babel à la volée, données
locales, `api/client.js` jamais branché, pas de `package.json`, ni `ios/` ni `android/`) —
**hors chemin**. CLAUDE.md:109 (« Mobile RN »), CONSTITUTION.md:38 et SYSTEM_MAP.md:78 sont
périmés sur ce point → tâche T-5.4.1.

### 0.2 Arbres de travail
- **Backend** : worktree `qa-corrige-2026-09-28`, branche `qa/corrige-rapports-2026-09-28`,
  HEAD `5a7c57e9a` (lot QA du 30/09 **+** correctif SAGA `3133cba3` déjà en production). Prod
  VPS = `3133cba3`. GitHub refuse les poussées du compte (« verify your email ») → déploiement
  par **dépôt direct** (bundle + variante de script, lancement propriétaire, mémoire
  `deploiement-direct-sans-github`). Commits par chemin explicite, jamais `git add .`.
- **Site** : dépôt `Site lecayenne`, `main` = `b9e1f64` déployé (Vercel), `compile-jsx
  --check` et `check-asset-versions --check` **verts** ce jour ; hunks étrangers non commités
  (index.html, legal/*, racine.jsx routage hash) **à ne pas emporter** : commits par hunk.
- **Paquet app** : `app/www` porte `funnel.js?v=20260902mail2` contre `20260929audit1`
  servi — `npm run check:www` : **31+ fichiers divergents**. Tout correctif site des 4
  dernières semaines est absent du paquet.

### 0.3 État vérifié en production (2026-09-30)
Routes `POST /api/auth/guest-signup/email-login`, `/api/auth/social/apple|google`,
`POST /api/auth/delete-account` **existent** (400 = validation) ; contrat « e-mail d'abord »
`8fc877521` ∈ prod ; CORS `Access-Control-Allow-Origin: https://localhost` (origine des
coques, `iosScheme: https`) ✓ ; certificat API Let's Encrypt valable jusqu'au **21/11/2026** ✓ ;
`healthz` ok ; site : `manifest.webmanifest` + `sw.js` + `legal/privacy.html` (200, mentionne la
suppression) ; `assetlinks.json` / `apple-app-site-association` 404 (liens universels non
activés — non exigé). `tools/verify-app-behaviour.mjs` : **13/13** sur le site d'aujourd'hui.

### 0.4 Pipeline mandaté par tâche
`~/.claude/skills/ultra-audit-profond/` (5 spécialistes lecture seule → synthèse → TDD →
RED → tests → visuel → adverse visuel). Zones gelées CLAUDE.md §7 / CONSTITUTION §3 =
lecture seule ; NF525 intouchable ; locale FR. Toute zone gelée à toucher → `lock-plan`.

### 0.5 Critères de convergence (production-perfect)
Rejet automatique si : libellé brut visible · débordement à 390×844 ou bureau · erreur
console hors bruit fournisseur · 4xx/5xx sans alerte visible (**P0 silencieux**) · écart
numérique entre deux surfaces (**P0**) · diff zone gelée ≠ 0 · P0 adverse non traité ·
critère d'acceptation sans chemin de test · « presque » ou « suffisant ». Convergence =
deux cycles consécutifs P0+P1 = 0 **et** ensembles de constats identiques **et** specs vertes
les deux fois (`~/.claude/skills/test-e2e/references/CONVERGENCE_RULES.md`).

## §1 — Carte des 5 systèmes (tous requis pour la soumission)

| # | Système | Maturité | Ancres vérifiées (find/grep/curl du jour) | Tests existants |
|---|---|---|---|---|
| S1 | **Coque stores** (Capacitor) | prête sauf signature, natif inerte, paquet périmé | `app/capacitor.config.json`, `app/package.json` (Node ≥ 22, scripts `check:www`/`build:www`/`sync`), `app/ios/App/App/{Info.plist,PrivacyInfo.xcprivacy}`, `app/android/{variables.gradle,app/build.gradle}`, `app/PUBLICATION.md`, `app/store-assets/fiches/` | `tools/verify-app-behaviour.mjs` (13), `tools/build-app-www.mjs --check` |
| S2 | **Site client** (= l'app) | production, corrigé cette semaine | `api.js` (`emailLogin:511`, `emailSignup:523`, `guestVerify:550`, `socialLogin:579`, `deleteAccount:650`, `placeOrder:1165`, `waitEstimate:1244`, `getOrder:1248`), `account-v2.jsx:312-349`, `funnel.jsx` (`formaterAttente:65`, retrait immédiat `:468`, idempotence `:883-891`, `onlineCard:908`, 3DS `:1221`), `racine.jsx:271-315`, `loyalty-v2.jsx:153-193`, `app-native.js:194-330`, `components.jsx:418` | `tests-e2e/` 52 bancs, `helpers-mock-backend.js`, `nav-smoke.local.js` |
| S3 | **API backend client** | production | `routes/api.php:208-298` (auth), `:2059-2082` (suivi public), `Frontend/OrderController.php` (`waitEstimate:53`, `track:70`, `store:113`, `show:141`, `changeStatus:197`, `paymentConfirm:214`), `Auth/DeactivateController.php:49-55`, `Auth/GuestSignupController.php`, `Services/OrderTrackingService.php:31-90`, `Services/WaitEstimateService.php:44-55`, `config/cors.php:6-21`, `Providers/RouteServiceProvider.php` (limiteurs `otp-send:136`, `oss-public:507`, `login-lockout:516`) | `tests/Feature/Auth/EmailLoginFlowTest.php` (sentinelle `:310`), `tests/Feature/Frontend/*` (18), `tests/Feature/Order/{OrderTrackingTest,WaitEstimateEndpointTest}.php` |
| S4 | **Chemin doré récepteur** (KDS → encaissement → OSS → suivi) | production, corrigé cette semaine | `KitchenTicketQueueController.php`, `resources/js/components/admin/kitchenDisplaySystem/*.vue`, `admin/encaissement/EncaissementComponent.vue`, `admin/orderStatusScreen/PreparingAndReadyComponent.vue`, `services/OssSyncService.js`, `helpers/kdsSymbolic.js` | `tests/Feature/KDS/KdsCustomerNameFromOrderTest.php`, `tests/Feature/Oss/*` (4), `tests/Feature/Pos/FileEncaissementVidageGroupeTest.php`, `tests/e2e/{web-order-sync-caisse-kds,tracker-web-order-visibility-proof,web-50-real-orders-mobile}-2026-07-20.spec.js` |
| S5 | **Exploitation & livraison** | outillé, bloqué GitHub | `tools/deploy-lecayenne.sh`, variante `deploy-direct.sh` (tmp), `PosSystemHealthController.php`, `HealthzController.php`, `.env` VPS (lecture interdite), `tools/check-asset-versions.mjs`, `tools/compile-jsx.mjs` | `tests/Feature/Pos/PosSystemHealthImpressionTest.php`, `tests/Feature/Observability/*` (134) |

## §2 — Systèmes séparés (hors chemin, dits explicitement)
- `testttt/mobile/` + `tests/mobile-e2e/` : prototype mai 2026, **aucune tâche**. Ne pas y
  faire cibler les équipes E2E (T-5.4.1 corrige la doc qui y mène).
- `/Users/1millnonstop/Downloads/web/` : copie périmée du site (CLAUDE.md:96) — jamais.

---

## §3 — S1 · Coque stores (Capacitor)

### Contrat
Le même site, dans une coque iPhone/Android honnête : rien d'annoncé qui ne marche, rien de
factice, permissions justifiées par un usage réel, paquet identique au site déployé.

### Zones gelées : aucune. Contraintes : Node 22 obligatoire (`app/package.json:7`), `npm run
sync` refuse tout `.jsx` non recompilé.

### Sub 1.1 — Paquet `www` & constructions
**Ancres** : `tools/build-app-www.mjs`, `app/www/`, JDK 21 `~/.local/android-build`, SDK 36
`~/Library/Android/sdk` (sans `emulator/` ni `system-images/`), artefacts 19/08 non signés.
- T-1.1.1 `check:www` en **gate de release** (rouge tant que le paquet diverge du commit site
  déployé) · anchor `app/package.json:11` · test : `npm run check:www` (existant, exit ≠ 0
  attendu aujourd'hui → 0 après T-5.3.3).
- T-1.1.2 Resync : `build:www` + `cap copy ios android` **depuis le commit site déployé, après
  backend déployé** (PUBLICATION.md §1bis, ordre) · test : `check:www` = 0 divergence +
  `grep 'funnel.js?v=' app/www/index.html` == jeton servi (test TO BE CREATED at
  `tools/verify-www-matches-served.mjs`).
- T-1.1.3 Émulateur Android : installer `emulator` + `system-images;android-36;google_apis;arm64-v8a`
  + AVD sans sudo · APK debug `./gradlew assembleDebug` · test : `adb devices` liste l'AVD,
  APK installé et ouvert (preuve capture) — (procédure TO BE CREATED at
  `app/PUBLICATION.md §4bis`).
- T-1.1.4 **Signature** : `signingConfigs` + keystore **propriétaire** (G3) ; AAB signé UNE fois
  en fin (W5) · anchor `app/android/app/build.gradle` (aucune `signingConfigs` aujourd'hui) ·
  test : `./gradlew bundleRelease` + `jarsigner -verify` (test TO BE CREATED at
  `tools/verify-aab-signed.sh`).
**Acceptation** : `check:www` = 0 · APK debug tourne sur AVD · AAB signé vérifié · aucune
divergence entre paquet et site déployé.

### Sub 1.2 — Natif honnête (risque **Apple 4.2** — vérifié : pont câblé, zéro appelant)
**Ancres** : `app-native.js:194` haptique, `:199` ouvrirLien, `:204` partager, `:214`
demanderPermissionNotifs, `:221` notifier ; appelants dans le site : **3, tous `account-v2.jsx`
(social)**. Permissions déclarées §7 PUBLICATION (POST_NOTIFICATIONS, SCHEDULE_EXACT_ALARM,
VIBRATE) sans fonction qui les justifie aujourd'hui.
- T-1.2.1 Haptique légère à l'ajout au panier et à la validation (`funnel.jsx`, garde
  `LC.native` absent = no-op) · test TO BE CREATED at `tests-e2e/app-natif-honnete-2026-09-30.spec.js`.
- T-1.2.2 Notification locale « ta commande devrait être prête » à l'heure estimée
  (`api.waitEstimate:1244` + `tracking.wait_low/high`), demande de permission **au moment
  utile** (après commande), jamais au démarrage · même test.
- T-1.2.3 Partage du lien de suivi `order/track/{token}` depuis l'écran commande
  (`routes/api.php:2059`) · même test.
- T-1.2.4 Dernière commande en `Preferences` (reprise après fermeture) · même test.
- T-1.2.5 `tools/verify-app-behaviour.mjs` étendu : bouton retour Android, bandeau hors-ligne,
  ouverture externe via Browser, permission notifs — pont simulé **et** trace sur AVD (T-1.1.3).
**Acceptation** : chaque permission du §7 correspond à une fonction observable en capture ;
`verify-app-behaviour` ≥ 17 contrôles verts ; aucune régression `tests-e2e/*.regression.js`.

### Sub 1.3 — Conformité & fiche
**Ancres** : `app/PUBLICATION.md §6-§8`, `components.jsx:49-51,413,418` (« bientôt »),
`legal/privacy.html` (200), `index.html:130-132` (metas OAuth vides), `app/store-assets/`.
- T-1.3.1 Arbitrage « bientôt » (G8) : retirer la note ou fournir les liens · anchor
  `components.jsx:418` · test : `grep -c "bientôt" components.jsx` = 0 **ou** URLs réelles ;
  banc `tests-e2e/nav-smoke.local.js`.
- T-1.3.2 **Mode examen** (G5/G8) : par défaut boîte de démonstration + compte **pré-créé** +
  notes FR+EN réécrites (l'« Option 1 » Apple est cachée : ne plus la citer) ; si le
  propriétaire rouvre un code borné → nouvelle décision **contre** la sentinelle
  `EmailLoginFlowTest:310` (LOCK). Test : `EmailLoginFlowTest` vert + notes relues.
- T-1.3.3 Commandes d'examen : marquage `TEST-` + libération/annulation automatique (voir
  T-3.2.3) pour ne pas polluer le vrai KDS à 3 h du matin (`funnel.jsx:468`).
- T-1.3.4 Métadonnées 2026 (G10) : questionnaire d'âge Apple (fiche dit « 4+ »), statut
  commerçant DSA, D-U-N-S si société, tableau « Sécurité des données » = manifeste réel
  (§7) · test : check-list cochée dans `PUBLICATION.md`, manifeste APK inspecté
  (`aapt dump permissions`).
- T-1.3.5 Captures store **régénérées en fin** (`tools/build-store-screenshots.mjs`,
  `build-store-frames.mjs`), URL d'assistance dédiée (pas la page d'accueil).
**Acceptation** : PUBLICATION.md §6-§8 exacts et datés ; 0 « bientôt » ou liens réels ; notes
examinateur ne citent que des chemins qui marchent.

### Sub 1.4 — Preuve sur cible (le paquet livré parle au **VPS**, pas au backend local)
- T-1.4.1 Build release Android sur AVD contre **PROD** : connexion e-mail, commande « sur
  place », suivi, suppression de compte, retour, hors-ligne, Browser, notif · captures ·
  (test TO BE CREATED at `tests-e2e/app-release-contre-prod-2026-09-30.md` — protocole manuel
  outillé, pas un spec).
- T-1.4.2 iOS : `cap sync ios` + archive **TestFlight** (G2) ; même parcours sur iPhone réel.
- T-1.4.3 Test interne/fermé Play (G9) puis soumission (G1).
**Acceptation** : parcours complet prouvé sur les deux OS contre la production, captures lues.

## §4 — S2 · Site client (le code qui est l'app)

### Contrat
Un client compose, commande, paie (web) ou paie au comptoir (app), suit, cumule des points,
gère son compte — à 390×844 et au bureau, sans libellé brut, sans erreur muette.
### Zones gelées : aucune côté site. Chaque asset modifié → bump `?v=` (`check-asset-versions`).

### Sub 2.1 — Compte « e-mail d'abord », examen, suppression
**Ancres** : `account-v2.jsx:299-349`, `api.js:511-650`, `loyalty-v2.jsx:153-193`.
- T-2.1.1 En conditions réelles (backend prod porte `8fc877521`) : e-mail connu → code ;
  inconnu → dépliage prénom + téléphone ; social sans numéro → `phone_required` · test :
  `tests-e2e/compte-email-dabord-*.spec.js` (existant, 24) + capture 390×844.
- T-2.1.2 Suppression **après** une commande « sur place » non retirée : aujourd'hui 422
  `account_not_delete` (`DeactivateController.php:49-55`) → message client clair + chemin de
  sortie (annuler la commande non commencée puis supprimer, T-3.1.3) · test TO BE CREATED at
  `tests-e2e/suppression-compte-apres-commande-2026-09-30.spec.js`.
- T-2.1.3 Mémoire d'appareil, renvoi du code, erreurs réseau honnêtes · test :
  `compte-memoire-appareil` (14), `coordonnees-erreurs` (7).
**Acceptation** : bancs cités verts + captures lues + 0 P0/P1 adverse.

### Sub 2.2 — Wizard → récapitulatif → panier
**Ancres** : `funnel.jsx`, `screens-*`/`components.jsx`, bancs `upsell-*`, `compteurs-articles`,
`carrousel-cartouche` ; règle SSOT : produits lus dans le menu servi, jamais devinés.
- T-2.2.1 Parcours Tacos L / Sandwich Cayenne à 390×844 : étapes obligatoires, compteurs,
  « Modifier » par étape, prix dans le CTA · test : `tests-e2e/*.regression.js` ciblés + spec
  wave B (TO BE CREATED at `tests/e2e/test-e2e-stores-finition-wave-B.spec.js`).
- T-2.2.2 Bureau 1280 : même parcours, aucun débordement · même spec.
- T-2.2.3 P2 connus (« — » vs « Sans formule », ~86 px de blanc) : corriger ou documenter.
**Acceptation** : 0 libellé brut, 0 débordement, totaux identiques wizard = récap = panier.

### Sub 2.3 — Paiement, retour 3-D Secure, idempotence
**Ancres** : `funnel.jsx:883-891` (clé `lc.funnel.idem`), `:908` (`onlineCard`), `:1221`
(`lc.mollie.pending`), `:1231` (`showPayError`), `racine.jsx:283-315`, `api.js:66`.
- T-2.3.1 Web : carte Mollie → retour `?order=` → vérité serveur (`paymentTruth`) ; annulée /
  payée / indisponible → messages honnêtes, jamais « confirmé » à tort · test : banc
  `banc-suivi-commande` (session 29/09) + spec wave C (TO BE CREATED at
  `tests/e2e/test-e2e-stores-finition-wave-C.spec.js`).
- T-2.3.2 App : paiement en ligne coupé → « Payer sur place » seul, aucun bouton mort · test :
  `verify-app-behaviour` (« paiement en ligne COUPÉ ») + capture pont injecté.
- T-2.3.3 Double envoi impossible (même signature → même clé ; conflit → message « peut-être
  déjà enregistrée ») · test : `tests/e2e/helpers/idempotency-key.js` + spec wave C.
**Acceptation** : aucune commande dupliquée en base après 3 renvois ; états 3DS tous capturés.

### Sub 2.4 — Suivi, attente, fidélité, mes commandes
**Ancres** : `formaterAttente:65`, `tracking.wait_low/high`, `position_ahead ≤ 1` (règle
propriétaire : jamais de temps court sauf ≤ 1 commande devant, jamais de compteur),
`loyalty-v2.jsx`, `api.js:1367-1391`.
- T-2.4.1 Page de suivi : temps réel (S5.1) ou repli 15 s, libellés d'attente conformes ·
  test : `tests/Playwright/order-tracking-wait-estimate-2026-09-23.spec.js` + spec wave D
  (TO BE CREATED at `tests/e2e/test-e2e-stores-finition-wave-D.spec.js`).
- T-2.4.2 Points : gagnés = affichés = historique (`LoyaltyPointsLifecycleTest.php`).
- T-2.4.3 « Mes commandes » hors session / en session, états vides avec texte + CTA.
**Acceptation** : même montant et même statut sur suivi, mes commandes, OSS et encaissement.

## §5 — S3 · API backend client

### Contrat
Le serveur décide de tout ce qui vaut de l'argent ou de l'identité : prix, statut, compte.
### Zones gelées (lecture seule) : `PricingService.php`, `OrderStateMachine.php`,
`IdempotencyKeyMiddleware.php`, `BranchScope.php`, `Services/Fiscal/*`.

### Sub 3.1 — Contrat d'authentification
**Ancres** : `routes/api.php:208-298`, `GuestSignupController.php`, `DeactivateController.php:49-55`,
`config/services.php:114-118` (audiences), `RouteServiceProvider.php:136` (`otp-send`).
- T-3.1.1 E-mail d'abord : `EmailLoginFlowTest.php` (11) vert sur la fusion ; sentinelle `:310`
  conservée.
- T-3.1.2 Social : audiences vides ⇒ refus (voulu) ; décision v1 « sans social » (G4) ⇒
  retirer entitlement `applesignin` + greffon, et les permissions `USE_CREDENTIALS/USE_BIOMETRIC`
  du manifeste · test : `aapt dump permissions` sans ces trois-là.
- T-3.1.3 **Suppression après commande** : autoriser la suppression quand les seules commandes
  ouvertes sont des commandes comptoir non commencées (annulation via le service existant, pas
  d'écriture directe du statut — `OrderStateMachine` gelé), sinon message précis ; anonymisation
  fiscale 6 ans conservée · test TO BE CREATED at
  `tests/Feature/Auth/DeleteAccountAfterCounterOrderTest.php` (rouge avant, vert après).
**Acceptation** : tests cités verts ; Play « URL de suppression » = `legal/privacy.html#droits`.

### Sub 3.2 — Commande web → prix SSOT → garde
**Ancres** : `Frontend/OrderController.php:113` (`store`), `WebOrderExpectedTotalGuardTest.php`,
`WebCardOrderPaidPathReleasesAllOrderTypesTest.php`, `OrderItem::insertRows` (panier mixte).
- T-3.2.1 Total client ≠ total serveur ⇒ refus explicite (jamais silencieux) · test :
  `WebOrderExpectedTotalGuardTest.php`.
- T-3.2.2 Supplément libre / panier mixte depuis l'app · test :
  `tests/Feature/Pos/SupplementLibreDansUnPanierMixteTest.php` (existant).
- T-3.2.3 Commandes marquées `TEST-` (examinateur) : libération KDS différée + annulation
  automatique à H+2 si non encaissées — via `CounterCollectStale` existant, jamais de
  suppression · test TO BE CREATED at `tests/Feature/Order/ReviewOrdersNeverPolluteKitchenTest.php`.
**Acceptation** : 0 écart de total en 50 commandes (`web-50-real-orders-mobile` spec).

### Sub 3.3 — Suivi, attente, version minimale
**Ancres** : `OrderTrackingService.php:53,90`, `WaitEstimateService.php:44-55`,
`routes/api.php:2059-2082`.
- T-3.3.1 Enveloppe `tracking` (wait_low/high, position_ahead) stable · test :
  `OrderTrackingTest.php`, `WaitEstimateEndpointTest.php`.
- T-3.3.2 `GET /api/app/config` → `{min_version, message}` + bandeau « mise à jour requise »
  dans le site quand `LC.native` présent (dérive www ↔ backend, aucun mécanisme aujourd'hui) ·
  test TO BE CREATED at `tests/Feature/App/AppConfigMinVersionTest.php` + banc site.
**Acceptation** : un paquet trop ancien affiche le bandeau et n'envoie aucune commande.

### Sub 3.4 — Fidélité & débits publics
- T-3.4.1 Cycle des points (`LoyaltyPointsLifecycleTest.php`), plancher effectif
  (`LoyaltyConfigEffectiveFloorTest.php`).
- T-3.4.2 Débits : `otp-send` (5/min e-mail + 20 global), `forgot-password`, `oss-public`,
  `login-lockout` — un examinateur qui se trompe 3 fois ne doit pas être verrouillé une heure ·
  test : `tests/Feature/Auth/*Throttle*` existants + cas examinateur (TO BE CREATED at
  `tests/Feature/Auth/ReviewerNeverLockedOutTest.php`).
**Acceptation** : tests verts ; aucun 429 dans un parcours d'examen normal.

## §6 — S4 · Chemin doré récepteur (commande app → cuisine → comptoir → client)

### Contrat
Ce que le client a composé arrive tel quel en cuisine, se règle au comptoir sans ambiguïté,
s'affiche au client et se retrouve dans son suivi — **même montant partout**.

### Sub 4.1 — KDS reçoit la commande de l'app
**Ancres** : `KitchenTicketQueueController.php` (`applyScheduledBoardFilter`),
`KitchenDisplaySystemOrderService.php`, `kdsSymbolic.js`, `KdsCustomerNameFromOrderTest.php`.
- T-4.1.1 Nom client, lignes, suppléments (libre compris), notes, libération programmée ·
  test : `KdsCustomerNameFromOrderTest.php`, `tests/js/kdsSupplementLibreLibelleComplet.spec.js`,
  `tests/e2e/web-order-sync-caisse-kds-2026-07-20.spec.js`.
- T-4.1.2 Temps réel ≤ 8 s (soketi) sinon polling ; capture KDS après commande app.

### Sub 4.2 — Encaissement comptoir d'une commande app
**Ancres** : `EncaissementComponent.vue` (croix « client non venu », purge en deux temps),
`routes/api.php` `counter-collect/*`, `FileEncaissementVidageGroupeTest.php`.
- T-4.2.1 Commande « payer sur place » visible dans la file, badge de date, encaissement, ticket ·
  test : `tracker-web-order-visibility-proof-2026-07-20.spec.js` + `FileEncaissementVidageGroupeTest.php`.
- T-4.2.2 Client non venu → annulation (jamais suppression), fiscal intact.

### Sub 4.3 — OSS et suivi cohérents
**Ancres** : `PreparingAndReadyComponent.vue` (écoute `OrderPaidAtCounter`), `OssSyncService.js`
(15 s), `Oss*Test.php` (4). Écart connu : OSS n'affiche pas ACCEPT+PAID (gate propriétaire).
- T-4.3.1 Statuts OSS = statuts suivi client à chaque transition · spec wave E (TO BE CREATED
  at `tests/e2e/test-e2e-stores-finition-wave-E.spec.js`).

### Sub 4.4 — Intégrité numérique
- T-4.4.1 Panier = ticket = KDS = OSS = suivi = points, à 0,01 € · spec wave E, données `TEST-`,
  nettoyage `scripts/cleanup_orphans.sh`.
**Acceptation §6** : 3 contextes navigateur (client, cuisine, caisse) sur une même commande,
captures lues, 0 écart.

## §7 — S5 · Exploitation & livraison

### Sub 5.1 — Temps réel
**Ancres** : soketi `127.0.0.1:6001` (dev), `.env` `BROADCAST_DRIVER=pusher`, `routes/channels.php`,
`WebSocketService.js`, `PreparingAndReadyComponent.vue`.
- T-5.1.1 Page de suivi reçoit les changements d'état sans rechargement ; repli 15 s prouvé
  en coupant soketi · test : `tests/Playwright/pos-receives-kiosk-realtime.spec.js` (modèle) +
  spec wave D.

### Sub 5.2 — Gates de release (tous automatisables)
- T-5.2.1 `healthz` ok · santé caisse sans « tout va bien » à tort (`PosSystemHealthImpressionTest.php`)
  · CORS `https://localhost` · certificat > 30 j · `check:www` = 0 · `compile-jsx --check` ·
  `check-asset-versions --check` — script unique (TO BE CREATED at
  `tools/release-gates-stores.sh`, sortie une ligne par gate).

### Sub 5.3 — Déploiements ordonnés (PUBLICATION.md §1bis)
- T-5.3.1 Backend `5a7c57e9a` (fusion) sur le VPS — **dépôt direct, lancement propriétaire**
  (G7) ; vérification `verifier-apres-deploy.sh`.
- T-5.3.2 Site : commits par hunk sur `main` → Vercel ; jetons `?v=` bumpés.
- T-5.3.3 Resync `www` **depuis le commit site déployé** → builds (T-1.1.2, T-1.1.4).
- T-5.3.4 Dès GitHub débloqué (G6) : pousser la fusion, avancer la release, revenir au script
  officiel.

### Sub 5.4 — Documentation SSOT
- T-5.4.1 CLAUDE.md:109, CONSTITUTION.md:38, SYSTEM_MAP.md:76-78 : « l'app = site Capacitor
  `Site lecayenne/app/` ; `mobile/` = prototype hors chemin » · test : `grep -c "Mobile RN"
  CLAUDE.md` = 0.
- T-5.4.2 `PUBLICATION.md` réécrit après W5 (mode examen, natif, signature, émulateur, 2026).
- T-5.4.3 PROJECT_BRAIN §2/§3/§4 à chaque fin de vague.

---

## §A — Armée d'agents

| Rôle | Type | Outils | Gabarit |
|---|---|---|---|
| Plan (vagues E2E) | `Plan` | lecture | `test-e2e/references/PROMPT_PLAN.md` |
| GStack capture/fix (1 par vague) | `general-purpose` | Edit/Write/Bash/Playwright | `PROMPT_GSTACK.md`, `PROMPT_FIX.md` |
| Superviseur adverse (1 par vague) | `general-purpose` | lecture + vision | `PROMPT_ADVERSARIAL.md` + `REVIEWER_PROTOCOL.md` (copié dans `reports/test-e2e/stores-finition-2026-09-30/`) |
| Architect / Security / A11y / DBA / SRE | `general-purpose` | lecture | `superpower-gstack/agents/*` |
| Store-compliance (Apple/Play) | `general-purpose` | lecture + web | brief : guidelines 2.1, 2.3.3, 4.2, 5.1.1(v), 5.1.2 ; Play Data safety, App access, User data |

**Fan-out** : visuel/site = Architect + A11y + Implementer + RED + QA Vis + RED Vis · backend =
Architect + Security + DBA + Implementer + RED · chemin doré = tous + SRE. Spécialistes lecture
seule en **un seul message** ; jamais deux implémenteurs en parallèle ; RED **après** commit,
**avant** DONE. Rapports persistés `reports/test-e2e/stores-finition-2026-09-30/round-<N>/
wave-<W>-<rôle>.json` (≤ 1 500 mots).

## §X — Vagues de convergence

| W | Portée | Parallélisme | Checkpoint (6 points §3 du skill) |
|---|---|---|---|
| **W0** pré-vol & gates longs | pré-vol test-e2e 10/10 (serveurs `:8000/:8899/:6001` **détachés**, 0 migration, workers 1, aides, scaffold ✓) · déclencher G1-G3, G9, G10 · les 4 arbitrages G8 posés au propriétaire avec défauts · T-5.4.1 · T-1.1.3 (télécharger émulateur) · inventaire prod/arbre | tout en parallèle (lecture + docs) | doc corrigée, émulateur prêt, arbitrages consignés dans BRAIN §2 |
| **W1** « le site est l'app » | T-1.2.1-5, T-2.1.2, T-3.1.3, T-3.2.3, T-3.3.2, T-5.2.1 ; APK debug sur AVD | backend ∥ site (arbres disjoints) ; 1 implémenteur par arbre | tests nommés verts, `verify-app-behaviour` ≥ 17, gel = 0 |
| **W2** E2E round 1 | Plan agent → vagues A compte+examen+suppression · B wizard/panier · C paiement/retour · D suivi/fidélité/notif ; site `:8899` contre backend **local** (`gotoDevLocal`, `tests/e2e/web-order-sync…:26-35`) ; variante pont Capacitor injecté ; quartet PNG/DOM/console/réseau | 4 GStack ∥ puis 4 adverses ∥ | specs vertes, findings JSON schéma, verdict par vague |
| **W3** chemin doré | vague E : commande app → KDS → encaissement → OSS → suivi, 3 contextes, données `TEST-`, soketi coupé/relancé | séquentiel (état partagé) | 0 écart numérique, captures des 3 surfaces lues |
| **W4** boucle adverse | correctifs par grappe (commit avant round suivant, `STASH_DEFENSE.md`) → re-capture → re-revue, jusqu'à **2 cycles P0+P1 = 0 identiques** | fix agents ∥ par grappe | `CONVERGENCE_FINAL.md` |
| **W5** livraison ordonnée | backend (G7) → site Vercel → resync `www` depuis le commit déployé → AAB **signé** (G3) → captures régénérées → PUBLICATION.md + notes FR/EN | séquentiel strict | `release-gates-stores.sh` tout vert, `check:www` = 0 |
| **W6** preuve sur cible | release Android sur AVD contre **PROD** (T-1.4.1) · iOS TestFlight (G2) · test interne/fermé Play (G9) | Android ici ∥ iOS propriétaire | parcours complet capturé sur les 2 OS |
| **W7** soumission & clôture | soumission (G1) · veille retours d'examen · suites complètes · gel = 0 · NF525 `fiscal:verify-chain --all` · BRAIN §2/§3/§6 · tag `v1.0.0-stores-ready` | — | DONE §F |

**Interruption** (limite de session) : commit `wip(W<n>): partiel jusqu'à T-x.y.z` par chemin,
manifeste `reports/test-e2e/stores-finition-2026-09-30/INTERRUPT_W<n>_<horodatage>.md`
(dernier commit vert, tâche en cours, suivante, rapports sur disque), BRAIN §2. Reprise :
lire le manifeste, rejouer la dernière tâche, continuer.
**Non-convergence** (3 boucles sur la même grappe) : STOP, agent `Plan` « pourquoi », fichier
`STUCK_W<n>_<horodatage>.md`, choix propriétaire A/B/C/D — jamais de 4ᵉ boucle silencieuse.

## §G — Gates propriétaire (WHO / WHAT / WHERE)

| Gate | Quoi | WHO | WHAT (débloque) | WHERE (preuve) | État |
|---|---|---|---|---|---|
| G1 | Comptes Apple Developer (99 €/an) + Google Play Console (25 €) ; D-U-N-S si société ; statut commerçant DSA | propriétaire | comptes actifs, accès partagé | BRAIN §2 | PENDING |
| G2 | Mac avec Xcode 26 : ici macOS 26.4.1 mais **25 Gio libres** (≈ 40 requis) → libérer ou autre Mac | propriétaire | `xcodebuild -version` | BRAIN §2 | PENDING |
| G3 | Keystore Android **créé et sauvegardé hors machine** (le perdre interdit toute mise à jour) | propriétaire | fichier `.jks` + mots de passe en coffre | `PUBLICATION.md §4` | PENDING |
| G4 | Social v1 : garder (créer 3 ID OAuth + Services ID) **ou retirer** (recommandé v1 : moins de permissions, aucune capacité portail) | propriétaire | décision écrite | BRAIN §6 | PENDING |
| G5 | Boîte e-mail de démonstration + compte pré-créé pour l'examinateur | propriétaire | identifiants dans les notes de revue | `PUBLICATION.md §8` | PENDING |
| G6 | Vérification e-mail GitHub (`github.com/settings/emails`) — débloque poussées + script officiel | propriétaire | `git push` accepté | BRAIN §2 | PENDING |
| G7 | Lancer le déploiement backend direct : `! bash /Users/1millnonstop/.claude/jobs/48fdb176/tmp/lancer-deploy-direct.sh` | propriétaire | journal `deploy-direct.log` | `verifier-apres-deploy.sh` | PENDING |
| G8 | 4 arbitrages produit : « bientôt » retiré ou liens · paiement app = comptoir (recommandé) · notif locale + haptique (recommandé) · **règle de suppression après commande** + commandes d'examen (défaut : `TEST-` auto-annulées H+2) | propriétaire | réponses | BRAIN §6 | PENDING |
| G9 | Testeurs Play (12 pendant 14 j si compte personnel) | propriétaire | test fermé validé | Play Console | PENDING |
| G10 | Questionnaire d'âge Apple 2026, DSA trader, Data safety cohérente | propriétaire (avec check-list fournie) | fiches validées | consoles | PENDING |

**Pendant l'attente** : W0-W4 tournent (rien n'y dépend d'un gate sauf T-1.3.2 si code borné) ;
W5 attend G7+G3 ; W6 attend G2/G9 ; W7 attend G1/G10.

## §R — Références
`~/.claude/skills/test-e2e/` (PROMPT_*, REVIEWER_PROTOCOL, FINDINGS_SCHEMA, CONVERGENCE_RULES,
STASH_DEFENSE, scripts `mega-audit-snap.js`, `aggregate_findings.sh`, `cleanup_orphans.sh`) ·
`~/.claude/skills/ultra-audit-profond/` · `app/PUBLICATION.md` · `plans/GOAL_APP_MOBILE_APPSTORE_2026-09-02.md` ·
`reports/planning/QA_CORRECTIONS_2026-09-30.md` · mémoires `app-stores-est-le-site-capacitor`,
`deploiement-direct-sans-github`, `site-cache-buster-sinon-correctif-invisible`,
`e2e-exige-le-diffuseur-temps-reel`, `caisse-deux-boutons-ajouter-panier`.

## §F — Règle finale
DONE seulement quand : W7 close · deux cycles E2E consécutifs P0+P1 = 0 identiques · paquet
prouvé contre la **production** sur Android **et** iOS · `check:www` = 0 · AAB signé ·
gel = 0 lignes · NF525 chaîne OK · PUBLICATION.md exact · fiches soumises. Pas « presque ».
Partiel > faux ; bloqué > silencieusement dangereux.
