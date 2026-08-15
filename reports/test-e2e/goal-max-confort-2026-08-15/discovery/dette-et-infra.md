# RECONNAISSANCE — Dette ouverte dans les GOALs + état RÉEL de l'infra de preuve

**Date** : 2026-08-15 · **HEAD** : `e2d2ca3b4` · **Branche** : `pos/category-first-caisse-2026-06-23`
**Mode** : READ-ONLY. Aucun fichier du projet modifié.
**Règle appliquée** : tout fait ci-dessous provient d'une exécution réelle (`ls`, `Read`, `git log`,
`php artisan test`, `npx playwright --list`, `npx vitest run`). Ce qui n'a pas été vérifié porte
la mention `(à vérifier)`.

---

## PARTIE A — DETTE OUVERTE DANS LES GOALs EXISTANTS

`ls plans/GOAL_*.md` → **60 plans GOAL** sur disque. Les 6 plus récents ont été lus intégralement.

### A.1 — Verdict par GOAL

| GOAL | Verdict | Preuve |
|---|---|---|
| `GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13` (295 l.) | **PARTIEL** | Commits `fb8914768` (plan), `2381d0bfc` (T-1.1.1 test RBAC direct-API), `bd17406f1` (fix SLO), `26667af86` (brain §2 « 5 gates owner »). Wave 2 partiellement faite ; **les 5 gates §G restent PENDING dans le doc** ; aucun rapport `reports/` dédié. |
| `GOAL_CAYENNE_FINITION_2026-08-13` (210 l.) | **PARTIEL** | Commits `f84dd5843` (plan), `63d9c6fe5` + `075ab3050` (§2 Vague 1 : collision d'énumérations Z), `f3d853b36` (§3 2.2 solde client admin), `858449114`/`e688fe9e4` (§2 1.1 alerte session tiroir), `60faeba6e`+`dbbe877a3` (§6 Vague 5 écran d'ajustement matières), `b77465fdb` (brain : « convergence Vague 1 + Vague 5 … pas déployé »). **Vagues 3 (roue) et 4 (Uber) non exécutées.** |
| `GOAL_ROUE_UX_IDENTITE_2026-08-13` (380 l.) | **PARTIEL** | Sub 1.1 porte un bloc **« ✅ SUB 1.1 CONVERGÉE 2026-08-13 »** écrit dans le doc lui-même (`plans/GOAL_ROUE_UX_IDENTITE_2026-08-13.md:136-143`). Rapport `reports/test-e2e/roue-account-e2e-2026-08-13/CONVERGENCE_FINAL.md` + 5 rounds sur disque, commit `f2dce23ea`. Sub 1.2 / 1.3 / 2.1 / 2.2 / 2.3 : **aucun bloc de convergence, aucun commit identifié**. |
| `GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13` (229 l.) | **PARTIEL — la plus grosse dette du repo** | Mémoire `goal_admin_nav_breadth_convergence_2026-08-13.md` atteste 70-72/73 pages parcourues + 2 bugs corrigés. Mais le catalogue de tâches du doc (**~80 tâches nommées**, dont ~60 marquées `(TO BE CREATED)`) n'a **aucun** fichier de test correspondant sur disque. Vérifié : `reports/test-e2e/admin-nav-breadth-2026-08-13/` **n'existe pas**. |
| `GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12` (439 l.) | **PARTIEL (W0-W3 seulement)** | Rapports réels : `reports/goal-ops-swap-2026-08-12/{w0,w1,W2_CORRECTIONS.md,W3_TROP_DE_REQUETES_CAISSE.md}`. BRAIN §4 confirme W0+W1, W2, W3 exécutés — **« AUCUN commit, AUCUNE poussée »** pour les trois. **W4→W8 jamais démarrées.** Chantier A : `0/18 exigences PROVEN` (`reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md:8`). Gate G1 bloque W2+. |
| `GOAL_UX_MOBILE_CAISSE_WEB_2026-08-06` (31 l.) | **CONVERGÉ (code) / PARTIEL (deploy)** | Mémoire `goal_ux_mobile_caisse_webintel_2026-08-06.md`. Les 2 gates §G (G-D deploy, G-B précompile Babel) : G-B a été **exécuté ensuite** (mémoire `perf_precompilation_photos_2026-08-08.md`). Reste : rien de matériel. |

### A.2 — Tâches explicitement NON FAITES et encore pertinentes

Format : `[GOAL-source] <id> <titre> — statut — preuve`

**Décisions owner en attente (bloquent du code, coût = une phrase de l'owner)**

- `[COMMERCANT] G1` Allowlist imprimante LAN host+port (`SAFE_REMOTE_HOST_ALLOWLIST` vide → impossible de créer une imprimante `127.0.0.1:9100`) — statut:NON-FAIT — preuve:`plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md:282` (PENDING)
- `[COMMERCANT] G2` `User`/BranchScope : corriger la doc OU le code — statut:**PARTIEL** — preuve:`CLAUDE.md §9` a été corrigé (option (a) actée 2026-08-14) ; `plans/…:283` dit encore PENDING → **le doc GOAL est en retard sur la réalité**
- `[COMMERCANT] G3/G4/G5` OrderSetup frais de livraison cosmétique · KioskSetup PIN sans porte · LoyaltySetup valeur rétroactive — statut:NON-FAIT — preuve:`plans/…:284-286` (PENDING ×3)
- `[FINITION] G1..G6` barème fidélité · `KIOSK_MACHINE_*` · `UBER_FISCALIZE` · carte Uber réelle · `WHEEL_ENABLED` · **vérifier que `facebook.com/LeCayenne` est bien le compte de l'owner** — statut:NON-FAIT — preuve:`plans/GOAL_CAYENNE_FINITION_2026-08-13.md:177-187` (G6 marqué « le plus urgent des six »)
- `[OPS-SWAP] G1` arbitrage des fichiers sales non attribués — statut:NON-FAIT, **bloque W2+** — preuve:`plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md:398`
- `[OPS-SWAP] G3` amendement `CONSTITUTION.md §1` pour autoriser le swap multi-marque — statut:NON-FAIT, bloque W7 — preuve:`plans/…:400`
- `[ADMIN-NAV] G1..G4` contrat rapport cartes-vs-lignes · fuite secret passerelle · route morte `NC-09` · pages orphelines — statut:NON-FAIT — preuve:`plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md:220-223`

**Bugs documentés, jamais corrigés (les plus rentables — déjà diagnostiqués)**

- `[ADMIN-NAV] NC-09` `PUT/PATCH /admin/message/{message}` route vers une méthode de contrôleur inexistante → 500 latent. Le doc le qualifie lui-même de *« highest-severity single finding in this whole GOAL »* — statut:NON-FAIT — preuve:`plans/…:159`
- `[ADMIN-NAV] SET-T02` `payment-gateway index` expose la valeur secrète à tout utilisateur authentifié non-settings — statut:NON-FAIT — preuve:`plans/…:87`
- `[ADMIN-NAV] REP-04` compte à l'écran ≠ compte à l'export du rapport articles (deux requêtes différentes) — statut:NON-FAIT — preuve:`plans/…:174`
- `[ADMIN-NAV] REP-05` le filtre de date du rapport articles filtre sur la date de CRÉATION de l'article, pas la date de commande — statut:NON-FAIT — preuve:`plans/…:175`
- `[ADMIN-NAV] REP-02/03` tuiles KPI (payé seul) ≠ tableau (toutes commandes) ≠ export Excel — statut:NON-FAIT — preuve:`plans/…:172-173`
- `[ADMIN-NAV] NC-06` sujet du mail de masse abonnés codé en dur en anglais (`'Subscriber Notification'`, viole ADR-007 FR) — statut:NON-FAIT — preuve:`plans/…:157`
- `[ADMIN-NAV] NC-07` `changeStatus` message sans garde `permission:messages` au niveau route — statut:NON-FAIT — preuve:`plans/…:158`
- `[ADMIN-NAV] SET-T06` `payment-terminals` + `dining-tables` sans entrée de navigation (URL directe seulement) — statut:NON-FAIT — preuve:`plans/…:91`
- `[FINITION] §2 1.2` 19 ventes payées sans mode de paiement → ventilation Z fausse. **Correctif partiel livré** (`63d9c6fe5` collision d'énumérations) mais le sentinel `ZReportUnknownMethodSentinelTest` **existe désormais sur disque** — statut:PARTIEL — preuve:`tests/Feature/Fiscal/ZReportUnknownMethodSentinelTest.php` (existe)
- `[FINITION] §2 1.3` ventilation fausse sur paiement mixte — le test de verrouillage **est SKIPPÉ** — statut:NON-FAIT (piège armé) — preuve:`tests/Feature/Fiscal/ZReportSplitBucketingLockTest.php` contient `markTestSkipped`

**Chantiers entiers jamais démarrés**

- `[ADMIN-NAV] Wave 2/3/4/5` Settings (31 tâches) · Users-RBAC (~15) · Notifications (~14) · Rapports (~13) — statut:NON-FAIT — preuve:`reports/test-e2e/admin-nav-breadth-2026-08-13/` **n'existe pas** ; aucun des ~60 fichiers `(TO BE CREATED)` n'est sur disque
- `[OPS-SWAP] W4→W8` stock · rapports/réglages · durables chantier A · swap multi-marque · qualification — statut:NON-FAIT — preuve:`reports/goal-ops-swap-2026-08-12/` s'arrête à `W3_…md`
- `[OPS-SWAP] Chantier A` RQ-01..RQ-18 — statut:**0/18 PROVEN** — preuve:`reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md:8`
- `[OPS-SWAP] T-B3.1.1` `tests/Feature/Report/` ET `tests/Feature/Reports/` coexistent — statut:NON-FAIT — preuve:les deux dossiers existent toujours
- `[OPS-SWAP] T-B4.1.1` corriger `SYSTEM_MAP.md:95` (annonce ~26 contrôleurs Settings sous `Admin/`, réel = 0) — statut:NON-FAIT `(à vérifier sur le fichier actuel)`
- `[ROUE-UX] Sub 1.2` bandeau des lots dans `roue.html` — statut:NON-FAIT — preuve:aucun bloc de convergence dans le doc, dépôt web séparé
- `[ROUE-UX] Sub 1.3` CTA final différencié par `prize_type` — statut:NON-FAIT — preuve:idem
- `[ROUE-UX] Sub 2.1/2.2/2.3` mémoire d'appareil (`lc_known_first`, TTL 90 j, « Ce n'est pas moi ») — statut:NON-FAIT — preuve:idem, dépôt `lecayenne-web-deploy`
- `[FINITION] §4 Vague 3` roue : abonnement débloque le tour · titre écran 2 · fin de parcours (compte+CGU+mail) · bloc « N cadeaux vous attendent » — statut:NON-FAIT — preuve:`plans/GOAL_CAYENNE_FINITION_2026-08-13.md:116-137`
- `[FINITION] §5 Vague 4` décision Uber `UBER_FISCALIZE` + `config/uber_menu_map.php` — statut:NON-FAIT (bloqué G3/G4)
- `[FINITION] §3 2.1` fidélité : **0 vente rattachée en production** au moment du plan. **Fortement avancé depuis** (`ccd15c96b`, `db0261e5`, `4cd80851` déployés) — statut:PARTIEL, à re-mesurer en prod

### A.3 — Restes déclarés dans PROJECT_BRAIN.md

**§2 (état courant, `PROJECT_BRAIN.md:48-240`)**
- Écran d'historique des points **pour le gérant** (vue globale, pas par client) : **jamais construit** (`:152`)
- Heuristique viande Uber basée sur le TITRE du groupe de modificateurs : **jamais confrontée à un vrai payload Uber de production** — à confirmer sur la prochaine vraie commande (`:228-233`)
- Confirmation papier physique du ticket cuisine par l'owner : **encore en attente** (`:214-216`)
- Accès `/admin/roue-reglages` ouvert à tout compte `can('pos')` : documenté « voulu » dans le code, **jamais signé par l'owner** (`:808`)

**§4 (NEXT TO DO, `:2569-2692`)** — file de gates owner, la plupart ANTÉRIEURE à 2026-06 et jamais purgée :
- `PROP-pos-wizard-001-xss` P0 sécurité, contresign en attente
- `PROP-PricingService-003-F1/F2` P0 NF525
- `PROP-PosV5TrancheRow-001` multi-TPE (dormant à 1 TPE)
- Choix architecture KDS ≥6 commandes (Option A/B/C)
- **`OrphanSettingsRatchetSentinelTest` existe sur disque** → le cliquet « réglages orphelins » de W2 a bien été livré
- 2 leviers non actionnés sur le 429 caisse : (A) un compte par écran, (B) relever `API_THROTTLE_PER_MINUTE`

---

## PARTIE B — INFRA DE TEST : CE QUI TOURNE VRAIMENT

### B.1 — PHPUnit

`phpunit.xml` : **3 suites** — `Unit` (`tests/Unit`), `Feature` (`tests/Feature`), `Load` (`tests/load`).
`memory_limit=2G` (souverain, écrase tout `php -d` en CLI — commentaire du fichier), `DB_CONNECTION=sqlite`
`:memory:`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`, groupe `manual` **exclu**.

- **972** fichiers `tests/Feature/**/*Test.php` · **29** dans `tests/Unit`
- **Exécution prouvée aujourd'hui** : `php artisan test --filter=FormRequestAuthzDriftSentinelTest` → **1 passed, 0.16 s**. PHPUnit fonctionne.
- **Dernière exécution COMPLÈTE connue** : `PROJECT_BRAIN.md:814` — **4690 tests, 964 s, 4686 verts, 2 rouges** (`WithoutGlobalScopesAuditSentinelTest` corrigé ; `FiscalArchiveMemoryBoundedTest` flake de concurrence). Datée ~2026-08-12/13.
- **Baseline rouge historique** : 7 tests du chemin ARGENT (`PaymentServiceCashHookTest`, `ChangeStatusReturnedSelfAuditR2Test` ×3, `RefundDirectPathSplitCashPortionTest`, `RefundSplitCashPortionOnlyTest`, `F003CashReconciliationSentinelTest`) laissés rouges par **décision owner** le 2026-08-07 (`PROJECT_BRAIN.md:1033`), puis **corrigés le 2026-08-08** avec « Feature 3890 tests / 0 ÉCHEC pour la première fois » (`:1029`). Statut actuel `(à vérifier par un run complet)`.
- **Skips** : `markTestSkipped` dans **29 fichiers**, **44 occurrences**. Motifs dominants (mesurés) : MySQL/Redis requis (`JSON_CONTAINS`, `lockForUpdate`, `Cache::lock`), triggers DELETE non installés sous SQLite, introspection d'index non supportée. **Conséquence dure : sous SQLite, tout ce qui prouve la concurrence et l'immutabilité NF525 est SAUTÉ.**
- 🔴 **TROU MAJEUR — 14 fichiers de test JAMAIS EXÉCUTÉS.** `phpunit.xml` exige le suffixe `Test.php` ; 13 sentinelles + 1 test de parité ne l'ont pas et **ne sont donc jamais collectées**. Vérifié par exécution : `php artisan test --filter=InnodbLockWaitTimeoutSentinel` → **« No tests executed! »**. **87 méthodes de test mortes** :

  | Méthodes | Fichier |
  |---:|---|
  | 23 | `tests/Feature/Database/IndexCoverageSentinel.php` |
  | 11 | `tests/Feature/Stripe/DrainStrandedCpnCronSentinel.php` |
  | 11 | `tests/Feature/Security/FileUploadHardenedSentinel.php` |
  | 10 | `tests/Feature/Security/LanguageServicePathContainmentSentinel.php` |
  | 5 | `tests/Feature/Refund/RefundCashMovementRecordedSentinel.php` |
  | 5 | `tests/Feature/Security/IdempotencyPendingTtlSentinel.php` |
  | 4 | `tests/Feature/Security/InnodbLockWaitTimeoutSentinel.php` |
  | 4 | `tests/Feature/Security/CashierAttributionAndLoginAuditSentinel.php` |
  | 4 | `tests/Feature/Migration/SqliteMysqlParitySentinel.php` |
  | 3 | `tests/Feature/Security/UserSuperAdminDisableHardenedSentinel.php` |
  | 3 | `tests/Feature/Security/LoginPasswordValidationParity.php` |
  | 2 | `tests/Feature/Orders/OrderServiceChangeStatusRaceSentinel.php` |
  | 1 | `tests/Feature/Deploy/DeployScriptBackupBeforeMigrateSentinel.php` |
  | 1 | `tests/Feature/Deploy/ServerSetupShHardeningSentinel.php` |

  Précédent : `OrderResourceCompletenessSentinel.php` a été renommé `*Test.php` le 2026-05-24 pour exactement cette raison (`PROJECT_BRAIN.md:1518`) — **les 14 autres n'ont jamais suivi**. Correctif = 14 `git mv`, coût quasi nul, gain : 87 gardes de sécurité/fiscal/index qui redeviennent vivantes.

### B.2 — Vitest

`vitest.config.mjs` : `environment: happy-dom`, `include: tests/js/**/*.spec.js`, **`fileParallelism: false`**
(sérialisation assumée — commentaire : `--no-file-parallelism` = 371/371 verts reproductibles, parallèle = flaky).

- **401** fichiers `tests/js/**/*.spec.js`
- **Exécution prouvée aujourd'hui** : `npx vitest run tests/js/sentinels/{kdsBundleFreshness,appBundleFreshness}Sentinel.spec.js` → **1 échec / 5 verts**.
- 🔴 **`kdsBundleFreshnessSentinel` est ROUGE AUJOURD'HUI** (mesuré, pas cité) : `KitchenDisplaySystemComponent.vue` (mtime 2026-08-14 21:50) est plus récent que `public/js/admin-kds.66e1c8f0.js` (mtime 2026-08-13 03:07). **Sentinelle basée sur les mtime de l'espace de travail local** → rouge pour quiconque édite une source KDS sans recompiler. Le même rouge est signalé depuis le 2026-08-12 dans `PROJECT_BRAIN.md:2575` avec la consigne explicite : *« À trancher par la voie KDS ; ne pas ajuster le seuil. »*
- **Dernière exécution complète connue** : `PROJECT_BRAIN.md:2572` — **2887/2891, 1 rouge** (le même). Une exécution complète prend **> 10 min** (sérialisation) — mesuré : mon run complet a dépassé 600 s sans terminer.

### B.3 — Playwright

`playwright.config.js` : `baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000'`,
`testDir: './tests'`, `testMatch: ['e2e/**', 'playwright/**', 'Playwright/**']`, **`workers: 1`**
(rafale de `POST /api/auth/login` → `throttle:login-lockout` en parallèle), `timeout: 600 s`, `retries: 1`,
`globalSetup: tests/Playwright/global-setup.js`.

**Serveur** : le config lance lui-même `php artisan serve --host=127.0.0.1 --port=8000` si rien ne répond
(`reuseExistingServer: true`). Désactivable par `PLAYWRIGHT_NO_WEB_SERVER=1`.
`global-setup.js` ne fait quelque chose **que si `E2E_BACKEND_AVAILABLE=1`** : il sème alors
`foodking:ensure-admin` / `ensure-pos-operator` / `ensure-chef-operator` + 4 seeders
(`KioskMachineTableSeeder`, `E2EPlaywrightPermissionsHealSeeder`, `ComposerPermissionsMinimalSeeder`,
`E2EPlaywrightIngredientRowsSeeder`). **Sans ce drapeau, aucun compte n'est garanti et les specs
authentifiées restent sur `/login`.**

**Inventaire** : `tests/e2e/` = **370** specs (dont **360 à la racine**, à plat) ·
`tests/Playwright/` = **57** specs. macOS étant insensible à la casse, `playwright/` et `Playwright/`
sont **le même dossier** — pas de double comptage (vérifié : 427 fichiers collectés au total).

Sous-dossiers de `tests/e2e/` et surface visée :

| Dossier | Specs | Surface |
|---|---:|---|
| `tests/e2e/` (racine, à plat) | 360 | **toutes** — nommage par date/vague, aucune arborescence par surface |
| `tests/e2e/kiosk-full-process/` | 1 | borne (`c1-kiosk-process-audit`) |
| `tests/e2e/pos-full-process/` | 1 | caisse (`c2-pos-process-audit`) |
| `tests/e2e/design/` | 4 | visuel / design |
| `tests/e2e/max-test-2026-05-28/` | 1 | campagne datée |
| `tests/e2e/sentinels/` | 1 | garde |
| `tests/e2e/helpers/` | 0 spec (16 fichiers) | `login.js`, `rate-limit.js` … |
| `tests/e2e/scripts/`, `__fixtures__/`, `screenshots/`, `__screenshots__/` | 0 | outillage / artefacts |
| `tests/Playwright/` | 57 | caisse (`zz-audit-caissier-s1..s10`), KDS, borne, promo-flyer, uber-photo, livreur, web |

🔴 **TROU MAJEUR — LA SUITE PLAYWRIGHT COMPLÈTE NE SE COLLECTE MÊME PAS.**

```
$ npx playwright test --config=playwright.config.js --list
Error: ENOENT: no such file or directory, open '/tmp/livreur-e2e-token.txt'
   at e2e/goal-functional-livreur-2026-05-28.spec.js:28
> 28 | const TOKEN = fs.readFileSync('/tmp/livreur-e2e-token.txt', 'utf-8').trim();
Total: 0 tests in 0 files
```

Un `fs.readFileSync` **au niveau module** (hors de tout `test()`) lève au chargement et **avorte la
collecte de TOUTE la suite**. Preuve du contraire par un config-sonde en scratchpad qui n'ignore que
ce fichier : **1580 tests dans 427 fichiers**. Donc `npm run test:e2e:full` rend **0 test** aujourd'hui.

Ancienneté : `git log --diff-filter=A` → introduit par `eaf3e5ceb`, **2026-05-29**. La suite complète
est donc morte depuis **~2,5 mois**. Tout « Playwright vert » rapporté depuis vient d'exécutions
**ciblées fichier par fichier**, jamais de la suite. C'est le seul fichier de la suite portant ce motif
(vérifié par grep sur `tests/e2e/*.spec.js` + `tests/Playwright/*.spec.js`). **Correctif = déplacer la
lecture dans un `beforeAll` ou ajouter `testIgnore`. 1 ligne.**

**Sous-ensemble qui, lui, fonctionne** : `npm run test:e2e:smoke` → **22 tests dans 5 fichiers**,
collecte confirmée (`01-auth-refresh`, `02-pos-cash`, `03-kiosk-wizard`, `04-kds-status`,
`stock-rupture-sync` — les 5 existent).

**Parcours complet bout-en-bout (commande → caisse → KDS → ticket) ?**
**Il n'existe AUCUN spec unique couvrant la chaîne entière jusqu'au ticket.** Les 3 plus proches :

1. `tests/e2e/_teste2e-massive-E4-caisse-kds-2026-07-24.spec.js` — **le meilleur candidat**. En-tête
   vérifiée : « S2 Cycle web→caisse→KDS — PENDING web → Accepter → Encaisser (idempotent) → KDS ».
   Couvre web → caisse → KDS. **S'arrête avant le ticket.** Exige un lancement manuel :
   `E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000`.
2. `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js` — 10 commandes POS+Borne, mode BYPASS
   paiement+impression **activé**. Limitations déclarées en tête du fichier : websockets probablement
   DOWN, `DispatchDomainEventsJob` non drainé. Donc **ne prouve pas le temps réel**, et l'impression
   est justement contournée.
3. `tests/e2e/_goal-reel-local-2026-08-12.spec.js` — parcours humains + relevé d'erreurs JS/HTTP/
   libellés bruts. Excellente sonde de **santé d'écran**, pas une chaîne métier.

Specs touchant l'impression : `_kds-historique-impression-2026-08-13`, `pos-receipt-kds-instruction-sync`,
`promo-flyer-2026-08-07`, `promo-flyer-caisse-2026-08-08`, `_teste2e-blocCD-2026-07-18`. Aucun ne
s'enchaîne à une commande créée dans le même spec.

### B.4 — Sentinelles

**173** fichiers PHP `*Sentinel*.php` (101 dans `tests/Feature/Sentinels/`, 72 ailleurs) ·
**42** specs JS `tests/js/**/*Sentinel*.spec.js` · **1** spec e2e.

Sentinelles à baseline chiffrée trouvées (grep sur les constantes) :

| Sentinelle | Baseline dans le code | À jour ? |
|---|---|---|
| `FormRequestAuthzDriftSentinelTest.php:67` | `RETURN_TRUE_BASELINE = 64` — **PASS vérifié aujourd'hui** | ✅ code à jour, ❌ **docs périmés** : `CLAUDE.md §9` dit « baseline still set to **69** » et `GOAL_OPS_RELIABILITY §6 T-B4.3.3` demande de « resserrer à **66** » — la tâche est **déjà dépassée** (64 < 66) |
| `FormRequestAuthzDriftSentinelTest.php:66` | `MIN_COVERAGE_PCT = 80.0` | non contredit |
| `FrozenZoneSha256BaselineSentinelTest.php` + `frozen-zone-sha256-baseline.json` | **15 fichiers** empreintés | ❌ **`CLAUDE.md §7` n'en liste que 13**. Écart confirmé par `reports/test-e2e/verif-globale-2026-08-14/CONVERGENCE_FINAL.md:47` (« plan miscount, 13 vs real 15 frozen files »). Les 2 non listés dans §7 : `public/css/pos-wizard.css` et `resources/views/admin-pos-v4.blade.php` sont bien cités en §7 en prose — l'écart est de **forme de liste**, pas de couverture `(à vérifier fichier par fichier)` |
| `BranchScopeCoverageSentinelTest.php:48` | `EXEMPTED_MODELS` (liste, pas un compteur) | ⚠️ vérifie la **présence textuelle** du scope, pas le comportement — `CLAUDE.md §9` le dit explicitement pour `User` |
| `ClaudeMdBranchScopeCountSentinelTest.php` | assertions textuelles sur `CLAUDE.md` | lie doc et code — bon motif |
| barre latérale admin | plancher **18** (commit `dbbe877a3`, « remonte le plancher sentinelle à 18 ») | à jour au 2026-08-14 |

### B.5 — Scripts d'exécution groupée

**`package.json` (extraits utiles)**
| Script | Ce qu'il fait RÉELLEMENT |
|---|---|
| `npm test` / `npm run vitest` | `vitest run` — les 401 specs JS, sérialisées, > 10 min |
| `npm run test:e2e:full` | `playwright test --config=playwright.config.js` — 🔴 **rend 0 test** (cf. B.3) |
| `npm run test:e2e:smoke` | 5 specs nommés → **22 tests**, fonctionne |
| `npm run production` / `dev` / `watch` | Laravel Mix (webpack) |
| `npm run perf:bundle-check` | `tools/perf/check_bundle_budget.mjs` |
| `npm run pos:lint:pricing` / `pos:lint:status` | gardes grep POS (SSOT prix, `orderStatus`) |
| `npm run i18n:audit` | `tools/i18n/audit_locale_keys.mjs` |
| `npm run verify:boucle` | ⚠️ **ne teste PAS le produit** — vérifie la présence des binaires `claude`/`codex` sur le PATH (orchestration d'agents). Exit 1 si `claude` absent. |
| `npm run validate:active-cycle` | `scripts/validate-active-cycle.sh` `(contenu à vérifier)` |
| `npm run audit:guard`, `execute:preflight`, `execute:guard`, `team:*`, `codex:*` | outillage d'orchestration multi-agents, pas des tests produit |

**`composer.json`** : un seul script utile — `composer invariants` → `scripts/check-invariants.sh`
= **6 greps d'invariants POS** en échec rapide (source `POS_INVARIANTS_AND_GATES.md §3`), pensé pour
un hook pre-push / la CI.

**`tools/*.sh`** : 8 scripts, dont **6 scripts de déploiement datés** (`deploy-final-2026-07-07.sh`,
`deploy-lecayenne.sh`, `deploy-now-2026-07-14.sh`, `deploy-now-2026-07-15.sh`, `deploy-owner8.sh`) —
seul `deploy-vps.sh` est vivant (dernier commit `72cf928d4`, 2026-08-14). Plus
`check-codebase-drift.sh` et `kds-poll-tune-2026-07-14.sh`.

**`bin/`** : 2 scripts Graphiti seulement.

**CI GitHub (`.github/workflows/`)** — 5 workflows : `phpunit.yml` (PHPUnit sur **MySQL** + `check-invariants.sh`),
`playwright.yml`, `vitest.yml`, `ci-sync-rupture-harness.yml`, `legacy-guards.yml`.
🔴 **Les 3 principaux se déclenchent uniquement sur `pull_request`/`push` vers `main` ou `develop`.**
La branche de travail est `pos/category-first-caisse-2026-06-23` depuis le **2026-06-23** →
**AUCUNE CI n'a tourné sur ce travail.** Dernier commit touchant `.github/` : `1b38e64a3`, **2026-05-07**.
Corollaire : la CI est le **seul** endroit où PHPUnit tourne sur MySQL (donc où les ~44 skips
SQLite s'exécutent vraiment) — et elle ne tourne pas.

### B.6 — Gate de déploiement (`tools/deploy-vps.sh`, 116 lignes, à lancer SUR le VPS)

Séquence exacte :
1. Sauvegarde `PREV=$(git rev-parse HEAD)` + `DEPLOY_START` (pour rollback auto)
2. `git fetch origin <BRANCH>` puis **`git reset --hard origin/<BRANCH>`** ⚠️ écrase tout travail local non committé sur le serveur
3. `npm ci` (ou `npm install`) + **`npm run production`** (build complet, jamais de SCP partiel)
4. `php artisan migrate --force`
5. `config:clear` + `cache:clear` + `view:clear` + `route:clear`
6. **VÉRIF 1** — `public/mix-manifest.json` existe **et** son mtime ≥ `DEPLOY_START` ; puis **chaque** fichier référencé par le manifeste doit exister sur disque. Échec → `rollback`. *(La fraîcheur se juge sur le manifeste SEUL — corrigé le 2026-08-14 après un faux positif STALE : webpack saute l'écriture d'un asset au contenu identique.)*
7. **VÉRIF 2** — sonde de contenu : `public/js/admin-kds.js` ne doit plus contenir la chaîne `"Imprimer ticket"`. Échec → `rollback`
8. `php artisan queue:restart` + **avertissement bruyant** si aucun `queue:work …high` détecté (`pgrep`)
9. **ATTESTATION NF525** — `php artisan fiscal:verify-chain --all`. Échec → `rollback`

`rollback()` = `git reset --hard $PREV` + `npm run production` + `config:clear` + `queue:restart` + `exit 1`.

**Ce que le gate NE fait PAS** : il ne lance **aucun test** — ni PHPUnit, ni Vitest, ni Playwright.
La sonde de contenu est **une seule chaîne codée en dur** datant d'un incident particulier. Le message
final le dit lui-même : *« Teste une vraie commande borne→caisse→KDS : synchro temps réel + 2 tickets »* —
c'est-à-dire **un humain**.

---

## PARTIE C — VERDICT FRANC

### C.1 — Peut-on prouver un parcours client complet automatiquement ?

**NON. Il faut un humain devant la machine.** Trois raisons cumulatives, chacune suffisante :

1. **Aucun spec ne couvre la chaîne entière.** Le meilleur (`_teste2e-massive-E4-caisse-kds-2026-07-24.spec.js`)
   s'arrête à l'affichage KDS. Le seul qui prétende au « parcours complet »
   (`mega-parcours-e2e-2026-05-08.spec.js`) **désactive explicitement l'impression et le paiement**
   (mode BYPASS) et déclare en tête que les websockets sont probablement DOWN.
2. **Le dernier maillon est physiquement inatteignable depuis le CI.** L'impression passe par un pont
   local `127.0.0.1:9100`/`9101` **sur le poste physique** (caisse / cuisine). Aucun agent, aucun
   runner ne peut observer une sortie papier. `PROJECT_BRAIN.md:214-216` : la confirmation papier de
   l'owner est *encore en attente* alors même que la preuve serveur est jugée solide.
3. **La suite Playwright complète ne démarre pas** (B.3). Même si le spec existait, on ne pourrait pas
   le lancer avec le reste.

Ce qui EST automatisable et prouvé aujourd'hui : chaque maillon séparément (PHPUnit sur les services,
Vitest sur les composants, Playwright ciblé par écran). Ce qui ne l'est pas : **la jonction entre les
maillons** — exactement ce que `GOAL_COMMERCANT §3.2 T-3.2.1` (test narratif « journée type ») avait
identifié et qui n'a **jamais été écrit**.

### C.2 — Les 3 plus gros trous de l'infra de preuve

1. **La suite E2E complète est morte depuis 2,5 mois et personne ne l'a vu.**
   `tests/e2e/goal-functional-livreur-2026-05-28.spec.js:28` fait tomber la collecte entière →
   `0 tests in 0 files` au lieu de **1580 tests dans 427 fichiers**. Introduit le 2026-05-29
   (`eaf3e5ceb`). Coût du correctif : **1 ligne**. Conséquence : tous les « Playwright vert » depuis
   juin sont des exécutions ciblées — personne n'a jamais mesuré le rayon de souffle inter-fichiers.
   *(Motif exact déjà appris le 2026-07-04, `PROJECT_BRAIN.md:1149` : « les runs par-répertoire ratent
   le blast radius cross-directory ».)*

2. **87 méthodes de test sont sur disque et ne s'exécutent jamais** — 14 fichiers sans suffixe
   `Test.php`, donc invisibles pour `phpunit.xml`. Elles couvrent précisément les zones qu'on croit
   gardées : couverture d'index DB (23), upload de fichiers durci (11), cron Stripe (11),
   containment de chemin i18n (10), mouvement de tiroir au remboursement (5), TTL d'idempotence (5),
   `innodb_lock_wait_timeout` (4), attribution caissier + audit de connexion (4), parité SQLite↔MySQL (4),
   parité de validation du mot de passe (3), désactivation super-admin (3), course
   `OrderService::changeStatus` (2), durcissement des scripts de déploiement (2).
   **Un rapport « 357 sentinelles vertes » ne dit rien de ces 87-là.** Coût du correctif : 14 `git mv`.

3. **Zéro CI depuis le 2026-06-23** — les 3 workflows ne se déclenchent que sur `main`/`develop`,
   la branche de travail est `pos/category-first-caisse-2026-06-23`. Deux effets :
   (a) chaque vert est un **run local, manuel, ciblé**, jamais reproductible par un tiers ;
   (b) **PHPUnit ne tourne jamais sur MySQL** — or c'est là que s'exécutent les ~44 tests aujourd'hui
   `markTestSkipped` sous SQLite : concurrence (`lockForUpdate`), triggers `BEFORE DELETE` NF525,
   `JSON_CONTAINS`, contraintes CHECK. Ce sont exactement les invariants fiscaux et monétaires que
   `GOAL_OPS_RELIABILITY §0.5-5` déclare non prouvables sous SQLite. **La sûreté NF525 n'est donc
   attestée par aucune exécution de test dans le mode où elle compte.**

*Mention honorable (4ᵉ)* : `kdsBundleFreshnessSentinel` est **rouge aujourd'hui** et l'est depuis le
2026-08-12 au moins. Une sentinelle qui compare des `mtime` d'espace de travail est rouge pour tout
développeur qui édite sans recompiler → **elle érode la confiance dans la couleur rouge elle-même**.
BRAIN §4 interdit explicitement d'ajuster son seuil : le fond (bundle KDS périmé ou sentinelle mal
ciblée) n'est toujours pas tranché.

### C.3 — La commande unique la plus proche d'un « gate complet » aujourd'hui

**Il n'y en a aucune.** Le plus proche existant est `bash tools/deploy-vps.sh` — mais il **ne lance
aucun test** : il vérifie le build (manifeste complet + frais), une chaîne de contenu codée en dur,
la présence du worker, et la chaîne NF525. C'est un gate de **déploiement**, pas de **correction**.

Le meilleur substitut réaliste **aujourd'hui, en une ligne** :

```bash
php artisan test && npx vitest run && npm run test:e2e:smoke && \
  bash scripts/check-invariants.sh && php artisan fiscal:verify-chain --all && \
  git diff --stat HEAD -- $(python3 -c "import json;print(' '.join(k for k in json.load(open('tests/Feature/Sentinels/frozen-zone-sha256-baseline.json')) if k!='_meta'))")
```

Ce que cette ligne couvre : ~4690 tests PHPUnit (SQLite), ~2891 Vitest, **22** tests E2E, 6 greps
d'invariants, la chaîne fiscale, le diff de zone gelée.
Ce qu'elle **ne** couvre **pas** : les 1558 autres tests E2E (suite morte), les 87 méthodes non
collectées, tout ce qui exige MySQL/Redis, et l'impression physique.
Durée estimée : **≥ 25 min** (PHPUnit ~16 min mesuré à 964 s, Vitest > 10 min sérialisé).

**Trois correctifs, tous à coût quasi nul, transformeraient ce bricolage en vrai gate** :
1 ligne pour ressusciter les 1580 tests E2E · 14 `git mv` pour ressusciter 87 méthodes ·
1 ligne de `branches:` dans 3 workflows pour retrouver la CI et MySQL.
**À faire AVANT toute nouvelle vague de tests — sinon la nouvelle vague héritera d'une preuve creuse.**

---

## Annexe — commandes de vérification rejouables

```bash
# Suite E2E : montre 0 test aujourd'hui
npx playwright test --config=playwright.config.js --list | tail -3

# Sentinelles jamais exécutées
find tests -iname '*Sentinel*.php' ! -name '*Test.php'
php artisan test --filter=InnodbLockWaitTimeoutSentinel   # → "No tests executed!"

# Vitest rouge du jour
npx vitest run tests/js/sentinels/kdsBundleFreshnessSentinel.spec.js

# Déclencheurs CI
grep -A3 '^on:' .github/workflows/{phpunit,playwright,vitest}.yml
```
