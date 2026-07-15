# GOAL — MAX Sécurité + Synchronisation + Gestion de données → e2e adversarial → UI/UX persona
— 2026-07-15 · HEAD départ `6b2d762ea` · branche `pos/category-first-caisse-2026-06-23` · DB `foodking_e2e` (3218 orders)

## §0 Préambule

**Mission owner (verbatim résumé)** : audit max sécurité + synchro + gestion de données, avec test-e2e, pour CAISSE + GESTION d'abord, puis BORNE entière, KDS, SITE WEB. Agents adversaires jusqu'à validation totale. Ensuite passe UI/UX max en prenant la place de l'utilisateur. Pas de limite — meilleur résultat final corrigé.

**§0.1 Working-tree decision (exécutée en W0)** :
- Les 4 bundles modifiés (`public/js/{admin-kds,pos-app,pos-shell}.js`, `public/mix-manifest.json`) sont **à jour vs HEAD** (vérifié : `_kitchenInFlight` du heal `718eb1a82` présent dans admin-kds.js ; timestamps build 16:38-16:47 > commits JS ; `6b2d762ea` 18:31 = backend only) → **commit `chore(build)`**.
- PNG racine supprimés (débris captures) → commit la suppression.
- Churn `reports/i18n/*` + `reports/goal-web-app-sync/*` (artefacts régénérés) → **revert** (git checkout) : bruit non-attribuable.
- `.playwright-mcp/*.yml` untracked = débris, ignorés. `.claude/worktrees/*` = interne, non touché.
- `tools/deploy-now-2026-07-14.sh` untracked → scan secrets avant de le laisser traîner.

**§0.2 Environnement vérifié** : serve :8000 UP + :8766 UP (`APP_URL=8766`), soketi UP, redis (cache+queue), **queue:work ABSENT → à démarrer W0 (`--queue=high,default`)**, scheduler NON piloté sur ce box (dernier backup daily **2026-06-24** = 21 j stale), migrations 0 pending.

**§0.3 Exclusions (déjà auditées/escaladées — interdits de re-finding)** : Stripe sk_test_ owner-action ; 2442 orphelins fiscaux pré-C33 (dette doc) ; PREPARED→CANCELED (OrderStateMachine frozen, escaladé) ; fidélité redeem `order_id=NULL` (escaladé) ; RBAC online-orders POS Operator (décision owner) ; TPE simulé (choix assumé) ; UNI-03 cache driver boot-guard ; contenus owner site web (mentions légales, Uber Eats, photos Insta, page fidélité) ; multi-tenant/cloud/scale (V1 LOCAL).

**§0.4 Pipeline par tâche** : `ultra-audit-profond` (audit 5 lentilles → synthèse → heal scope-minimal → RED dispute → test → visual) ; e2e adversarial : règle de convergence `test-e2e` (2 cycles consécutifs P0+P1=0 et findings identiques). Frozen-zone touch → skill `lock-plan` + gate owner.

**§0.5 Critères de convergence GOAL (§F détaillé)** : 0 P0/P1 nouveaux non-healés ; e2e personas verts sur 6 surfaces ; suites PHPUnit/Vitest baseline vertes ; NF525 chain OK ; frozen diff 0 ; UI/UX validée visuellement (captures lues) ; BRAIN+memory à jour ; commits locaux (push = gate owner).

## §1 Systèmes + ancres (vérifiées 2026-07-15 via find/ls/grep)

### S1 CAISSE (priorité 1)
- Back : `app/Http/Controllers/Admin/{PosController,PosOrderController,AdminPosV4Controller,PosCategoryController,PosLoyaltyController,CashOverviewController,CashSessionReportController}.php` + `Admin/Pos/{CashDrawerController,CashDrawerSessionController,PosCustomerDisplayController,PosReceiptPrintController,PosTicketBytesController}.php` + `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php`, `app/Services/Cash/*`, `config/pos.php`
- Front : `resources/js/components/admin/pos/**` (hors frozen), bundle `pos-shell.js` + entry `pos-app.js`
- FROZEN : `public/js/pos-wizard.js` + css + `admin-pos-v4.blade.php` (strict) ; `PaymentComponent.vue`, `v5/PosV5TrancheRow.vue` (gate)
- Tests : `tests/Feature/Cash/`, `tests/Feature/Pos*` (existants, cf. ls) ; nouveaux → `tests/Feature/Cash/…Test.php`
- Surface : `http://127.0.0.1:8000/admin/pos`

### S2 GESTION (priorité 1)
- Back : `app/Http/Controllers/Admin/` (94 contrôleurs : Item*, ItemCategory*, Stock*, User/Administrator*, Coupon*, Dashboard*, rapports EOD…) + `app/Services/{ItemCategoryService,CouponService,DashboardService}.php`, `app/Services/Catalog/*`
- Données : backups `storage/backups/db-daily/` + crons `foodking:backup-daily`, `backup:verify-restore`, prunes (outbox 90j, webhook 180j, quotes 7j, sanctum), `fiscal:verify-z-membership`
- Tests : `tests/Feature/Admin/`, `tests/Feature/AdminCrudComprehensiveTest.php`, `tests/Feature/Authz/`, `tests/Feature/Branch/`
- Surfaces : `/admin/items`, `/admin/stock/rupture`, `/admin/dashboard`, `/admin/users`, `/admin/order-history` (à confirmer route exacte)

### S3 BORNE
- Back : `app/Services/Kiosk/{PricingPreviewService,KioskPromoService}.php`, `Auth/KioskMachineLoginController.php`, `Frontend/KioskEventController.php`, `Admin/{KioskMachineController,KioskSetupController}.php`, `config/kiosk.php`
- Front : `resources/js/components/frontend/kiosk/**` (3 composants frozen auditable), `kioskRoutes.js`, `kioskCart.js`, `kioskOfflineQueue.js`, bundles `kiosk-*.js`
- Tests : `tests/Feature/KioskPhase1/KioskEndpointsTest.php` (+96 l. ajoutées `6b2d762ea`)
- Surface : `/kiosk/idle`

### S4 KDS + OSS
- Back : `Admin/{KitchenDisplaySystemController,KdsSyncController,OrderStatusScreenController}.php`, `app/Services/KitchenDisplaySystemOrderService.php`, Resources KDS*, `app/Events/KdsOrderRecalled.php`
- Front : `admin/kitchenDisplaySystem/**`, `admin/orderStatusScreen/**`, `OssSyncService.js`, `kdsCustomization.js`, `kitchenLocalPrinter.js`, pont `tools/kitchen-bridge`
- Tests : `tests/js/kdsManualReprint.spec.js` + suites KDS (301 tests JS cf. memory)
- Surfaces : `/kds`, `/admin/order-status-screen`

### S5 SITE WEB (repo séparé Vercel)
- Repo : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (remote `loeymot-sketch/Site-lecayenne`) — la copie `/Downloads/web` est DIVERGÉE, ne pas l'éditer.
- P0 connu : API base `localhost:8766` (index.html) → commande impossible en prod. Gate owner = URL backend prod.
- Surface : `https://site-lecayenne.vercel.app`

### Zones partagées (lock, jamais 2 écrivains)
Bus synchro (`app/Events/*`, outbox `domain_events`, `DispatchDomainEventsJob` file `high`, canal `private-branch.{id}`, soketi :6001, `WebSocketService.js`) ; `PricingService` (frozen) ; chaîne fiscale (frozen) ; `OrderService`/`FrontendOrderService` ; `BranchScope`/Sanctum/Idempotency (frozen).

## §2 Vagues (ajustées advisor : audits parallèles, heals sérialisés)

- **W0 Pré-vol** : §0.1 exécuté ; queue worker up ; `fiscal:verify-chain --all` + `fiscal:verify-immutability-triggers` (gate dur — incident 2026-07-11) ; baseline PHPUnit série en background ; constat scheduler/backup stale (→ finding W2 d'office).
- **WA Audit parallèle 5 systèmes** (read-only, workflow adversarial) : lentilles sécurité / synchro / données-intégrité / fiscal-adjacent par système + lentilles transverses (idempotency, sessions multi-onglets caisse, purge/rétention, scheduler). Chaque finding → réfutation 2-3 verdicts diversifiés. Sortie : findings CONFIRMÉS avec file:line + repro.
- **WB Heals sérialisés** (ordre data-model → GESTION → CAISSE → BORNE → KDS/OSS ; site web = lane parallèle indépendante) : scope-minimal, tests nommés par fix, max 3 boucles puis escalade.
- **WC e2e personas + drills jamais couverts** : caissier (encaissements, split, park/recall, remboursement, **2 onglets caisse simultanés**) ; gérant (CRUD produit/prix → propagation borne+caisse live, rupture stock, **drill backup→restore→verify-chain sur DB scratch**, scheduler liveness) ; client borne (parcours complet → n° file → KDS) ; cuisinier (bump/recall, pont impression, WS down→poll) ; client web mobile.
- **WD UI/UX MAX** : personas sur les 6 surfaces, captures analysées (labels bruts, layout, i18n FR, contrastes, états vides/erreur), heals visuels.
- **WE Convergence finale** : suites complètes, chain NF525, frozen diff 0 sur toute la plage, BRAIN §2/§3 + memory, commits checkpoint par vague. Push = gate owner.

**Checkpoint fin de vague** (6 points, Axis 3) : tasks PASS ou fail documenté ; frozen diff 0 ; chain NF525 inchangée/append-only ; visual gate si frontend ; RED dispute close ; BRAIN à jour.
**Interrupt-resume** : commit WIP `wip(<vague>)` + manifest `reports/goal-max-secu-sync-2026-07-15/INTERRUPT_<vague>.md` + BRAIN §2.
**Stuck (3 heals)** : STOP + analyse Plan-agent + options A/B/C/D à l'owner.

## §A Armée d'agents

Rapports persistés : `reports/goal-max-secu-sync-2026-07-15/<vague>/<système>-<lentille>.md`. Schéma finding : `[P0..P3] file:line — titre · repro exacte · evidence · fix scope-minimal proposé`. Anti-hallucination : tout P0/P1 sans file:line vérifiable + repro = REJETÉ (skill verify-before-report).
- Finders (read-only, parallèles) : par système × lentille (sécu, synchro, données, fiscal-adjacent) + transverses (concurrence multi-onglets, rétention/purge/backup, scheduler, idempotency).
- Réfuteurs (read-only) : 2-3 par finding, lentilles diverses (correctness / exploitabilité / repro-réelle), majorité requise.
- Implémenteur : moi (main loop) ou code-editor 1-à-la-fois par zone ; JAMAIS 2 écrivains même fichier.
- e2e : navigateur réel (Chrome MCP / Playwright), captures lues, adversaire visuel indépendant en WD.

## §G Gates owner (WHO/WHAT/WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Push origin des commits de la session | Owner | « pousse » explicite | chat | PENDING |
| G2 | URL API backend prod pour site web (remplace localhost:8766) | Owner | URL réelle | chat → `index.html` | PENDING |
| G3 | Toute modification frozen-zone découverte nécessaire | Owner | LOCK doc contresigné | `plans/LOCK_*.md` | — |
| G4 | Scheduler prod (cron `schedule:run`) sur la machine cible | Owner | preuve crontab/launchd | BRAIN §2 | PENDING (constat 21j sans backup) |
| G5 | Contenus site web (mentions légales, Uber Eats, photos) | Owner | contenus réels | repo Vercel | PENDING |

## §F Règle finale — DONE
GOAL convergé quand : WA→WE fermées checkpoint 6/6 ; 0 P0/P1 ouverts (healés ou escaladés avec décision) ; e2e personas 6 surfaces verts 2 cycles consécutifs ; suites vertes ; chain NF525 OK ; frozen diff 0 ; UI/UX attestée captures ; BRAIN+memory+Graphiti à jour ; commits locaux propres. Production-perfect, pas « presque ».
