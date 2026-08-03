# CRITIC DE COMPLÉTUDE — W1 ultra-review (2026-07-02)

Rôle : adversaire de la carte des 11 lecteurs. Read-only. Toute zone citée ci-dessous
a été prouvée par un `ls`/`grep` exécuté dans cette session (pas de mémoire, pas de doc).

## VERDICT : GAPS_MAJOR

La carte couvre très bien le **cœur produit** (borne, caisse, KDS/OSS, central, storefront,
noyau commandes/fiscal, sync-bus, auth). Mais un **système entier n'a aucun owner : la couche
OPS/scheduler** (~20 tâches cron dans `app/Console/Kernel.php`, dont la **clôture/ouverture
fiscale Z automatique de toutes les branches** et la **chaîne backup + verify-restore** —
NF525-critiques), plus **4 surfaces legacy PUBLIQUES routables** (installer `/install`,
routes web `/payment/{order}/pay` + success/fail par gateway, commande à table QR `Table/*`,
webhook Uber côté mapping) que personne ne lit alors qu'elles sont les cibles n°1 d'un W4
sécurité. Ce n'est pas un raté de lecture des systèmes mappés — c'est un 9ᵉ système absent.

---

## 1. ZONES NON COUVERTES (chaque preuve = commande exécutée ici)

### A. CRITIQUES (à couvrir impérativement)

**A1. Couche scheduler/ops — `app/Console/Kernel.php` (AUCUN owner global)**
Preuve : `grep -n "schedule" app/Console/Kernel.php` →
- L401 `fiscal:close-all-active-branches` + L446 `fiscal:open-all-active-branches` — **clôture Z automatique quotidienne** : personne ne lit ces commandes ni leur interaction avec ZReportService frozen.
- L144 `foodking:backup-daily` + L153 `backup:verify-restore` (`app/Console/Commands/Backup/{RunDailyBackup,BackupVerifyRestoreCommand}.php` — `ls` confirmé) — rétention NF525 6 ans dépend de cette chaîne, 0 lecteur.
- L297/L339 closures schedulers NF525 chain-verify + archive quotidienne (FiscalArchiveCommand).
- L91 `fiscal:verify-z-membership`, L105 job `CleanupStalePendingKioskOrders` (purge des PENDING borne — interagit avec la file counter-collect !), L128 `pos:purge-parked-orders`, L211 `sanctum:prune-expired`, L238 `stripe:drain-stranded-cpn`, L248 `SloEvaluatorJob`, L253 `stock:scan-rupture`, L266 `foodking:fiscal:retry-alloc`, L279 reset-stale-quota, L119 healthz, L162 storage:cleanup, L176/L192 prune outbox/webhooks.
Le lecteur SYNC-BUS ne cite Kernel.php que pour le monitoring outbox. Tout le reste est orphelin.

**A2. Installer legacy PUBLIC — `routes/web.php:22-35`**
Preuve : `sed -n 15,60p routes/web.php` → groupe `/install` avec middleware **`['web']` uniquement**
(pas de `installed` sur le groupe), incluant `POST /install/database` (databaseStore) et
`POST /install/license`. `app/Http/Controllers/Installer/` + `InstallerService.php` existent
(`ls` confirmé). Si le guard anti-réinstallation est seulement dans le controller, c'est à
prouver en W4 — une réinstallation = reconfiguration DB de la caisse en prod. 0 lecteur.

**A3. Routes web paiement gateway legacy PUBLIQUES — `routes/web.php:48-53`**
Preuve : `GET /payment/{order}/pay`, `match GET|POST /payment/{gateway}/{order}/success|fail|cancel`,
`GET /payment/successful/{order}` — middleware `installed` seul, `{order}` = id devinable.
Cible IDOR/forge de succès de paiement classique (le succès Stripe est-il signé ? cf.
`config/services.php:65-67` webhook stripe + `stripe:drain-stranded-cpn` **schedulé** L238 —
donc ce chemin Stripe est VIVANT, pas mort). `PaymentGatewayService/PaymentManagerService/
PaymentAbstract` (ls app/Services) sans owner. Le storefront-reader liste
`PaymentReconcileController` mais aucun flow ne couvre ces routes web.

**A4. Dine-in / commande à table QR + floorplan — dormant mais routé**
Preuve : `ls app/Http/Controllers/Table/` → `DiningTableController, ItemCategoryController,
OrderController` (surface CLIENT table) ; `grep -n "table" routes/api.php` → L1042-1052
admin table-order (change-status, change-payment-status, token-create), L944-945
`FloorplanController assign/release`, L1183 dining-table ; `ls resources/js/components/table`
+ `adminTableOrderRoutes.js`. La mission W1 demandait explicitement les « features dormantes
(dine-in) » : 0 lecteur. Question W4 : le `Table\OrderController` crée-t-il des commandes
prix-SSOT et avec quelle auth (token QR table) ?

**A5. Pipeline Uber Eats interne (au-delà du HMAC)**
Preuve : `ls app/Services/Uber/` → `UberClient.php, UberOrderMapper.php` ;
`ls app/Jobs/` → `ProcessWebhookEventJob.php` ; route publique `POST /api/webhooks/uber`
(api.php:161) ; `OutboxWebhookRetryFailedCommand` schedulé L76. Le lecteur AUTH couvre le
flow HMAC, mais **personne ne possède UberOrderMapper → composition_snapshot → KDS**
(correctness du mapping prix/items = risque NF525 si un montant Uber entre mal).

### B. IMPORTANTES

**B6. Loyalty end-to-end** — preuve : `ls app/Services/Loyalty/` → `LoyaltyQrSigner,
PosRedemptionService, …` ; routes publiques throttlées `POST /loyalty/register`
(api.php:1429-1435, anti-énumération AUDIT-P0-D), opt-in RGPD frontend (1493-1496),
loyalty-setup admin (354). CAISSE couvre seulement le redeem POS. Manquent : signature QR,
énumération, math des soldes, LoyaltyService/LoyaltySetupService.

**B7. Composer profiles / Catalog Studio backend** — preuve : `ls app/Services/Composer/`
→ 5 services (Profile/Step/Template/Diff/Projection) ; routes api.php:773-791
(publish/unpublish/diff/apply-template). Directement lié à la question ouverte CAISSE
« wizard hardcodé vs posWizardComposerAware » — personne ne lit le côté serveur.

**B8. Menu projection + stock/ingredients services** — preuve : `ls app/Services/Menu/`
→ `MenuProjectionService, PosMenuProjection, MenuSnapshot, AvailabilityService` ;
`ls app/Services/Stock/` → `StockService, ChoiceAvailabilityResolver` ;
`ls app/Services/Ingredients/`. CENTRAL possède les controllers dashboard/availability mais
pas ces services (la Q3 du central « qui consomme menu-projection » reste sans owner).

**B9. Afficheur client (customer display)** — preuve : `ls app/Services/Hardware/` →
`CustomerDisplayService, CustomerDisplayCommandBuilder, DisplayTransport/{WindowsSerial,Null}` ;
route `POST .../customer-display` api.php:934 ; `config/printing.php:99`. 0 lecteur
(les lecteurs hardware ne couvrent que les tickets).

**B10. Observability/SLO** — preuve : `SyncOverviewController` routé api.php:1221-1223
(dont `POST /client-metrics` throttle ?), `SloEvaluatorJob` schedulé Kernel L248,
`app/Services/Observability/{SloMetricCollector,SyncMetricsRecorder}`, composants
`resources/js/components/admin/observability` + `observabilityRoutes.js`. SYNC-BUS l'a
explicitement laissé en « non lu ».

**B11. Exports/Imports Excel** — preuve : `ls app/Exports` → 18 exports + `ItemImport,
ItemCategoryImport`. Routes d'export partout (ex. api.php:1046 table-order/export).
Angles W4 : authz de chaque export (fuite PII clients), injection de formule CSV à l'import.

**B12. Delivery-boy ops** — preuve : routes api.php:644 (delivery-boy), 681 (cash-sessions),
1418 (delivery-boy-order auth:sanctum), `DeliveryBoyCashSessionService`, composants
`deliveryBoyCashSession`. Web-delivery est mappé (storefront) mais le côté livreur
(session cash NF525-adjacente, app livreur ?) n'a pas d'owner.

### C. MINEURES (passe rapide suffit)

- **C13. Notifications FCM/SMS/Mail** : `FirebaseService, FcmNotificationService,
  SendFcmNotificationJob`, 9 builders `Order*NotificationBuilder` (ls). V1 0-cloud →
  vérifier fail-safe no-op seulement.
- **C14. Health endpoints publics** : `/api/health` full sans auth (api.php:140-149) —
  divulgation d'état db/redis/ws ? 1 grep W4.
- **C15. Admin surfaces résiduelles** : `CashOverviewController, CashSessionReportController,
  CreditBalanceReportController, TransactionController, OnlineOrderController,
  MyOrderDetailsController` + composants (cashOverview, creditBalanceReport, transactions,
  onlineOrders) — le CENTRAL en liste certains rapports mais pas ceux-ci (cash overview =
  NF525-adjacent).
- **C16. Middlewares transverses** : `ls app/Http/Middleware/` → `ApiKeyMiddleware,
  ContentSecurityPolicyHeader, CorrelationIdMiddleware, ValidateKioskLocale,
  EnsureUserStatusActive, TrustProxies/TrustHosts` — la liste de fichiers du lecteur AUTH
  est tronquée dans le brief ; à confirmer qu'ils sont dedans (surtout ApiKeyMiddleware :
  qui l'exige, quelle clé ?).
- **C17. Commandes artisan de test/cleanup** : `SimulateKioskOrders, E2ESoak/Stress,
  CleanupWebTestOrders, Iter15Cleanup, CleanupDemoData, FreshOrderSeed, Menu*Heal*` —
  vérifier qu'aucune n'est destructrice sans garde env (NF525 : suppression de commandes).
- **C18. `routes/console.php`** : inoffensif (inspire uniquement — cat confirmé).

---

## 2. JUGEMENT DE LA DÉCOMPOSITION

**GAPS_MAJOR.** Les 8 périmètres lus sont profonds et honnêtes (bons réflexes
anti-hallucination : les lecteurs ont débunké /kds et orderStatusScreenRoutes.js). Mais :
1. La couche **scheduler/ops** est un système à part entière (clôture fiscale AUTOMATIQUE,
   backups, purges qui touchent la file d'encaissement) — son absence peut invalider des
   conclusions des autres lecteurs (ex. « recall TTL updated_at » × purge nocturne ;
   « PENDING_COUNTER entre en file » × `CleanupStalePendingKioskOrders`).
2. Les surfaces **legacy publiques routables** (installer, /payment web, table QR) sont
   exactement ce qu'un attaquant grep en premier — les exclure de W4 rendrait le verdict
   sécurité non fiable.
3. Uber mapping, loyalty QR, composer backend = zones où un bug est un bug d'ARGENT.

## 3. CRITIQUE DU PLAN W2-W5

**Angles morts à corriger :**
- **W2** : ajouter un 9ᵉ reviewer « OPS/CRON + legacy dormant » (Kernel schedule ligne à
  ligne, Backup/RunDailyBackup, fiscal close/open all, CleanupStalePendingKioskOrders vs
  file counter-collect, purges outbox/webhook/parked). Ajouter UberOrderMapper + Loyalty +
  Composer services au reviewer du système le plus proche.
- **W3** : inclure Observability (SyncOverviewController/ws:heartbeat — déjà question
  ouverte SYNC) et tester la dégradation AVEC le scheduler actif (rescue/retry-failed
  changent le comportement observé en e2e live : un event « perdu » peut réapparaître à
  :10 → faux positif OU faux négatif selon le timing).
- **W4** : ajouter explicitement — installer re-exécutable ?, IDOR /payment/{order}/pay +
  forge success, authz Table/* + token-create, authz exports (PII) + injection CSV import,
  /api/health disclosure, ApiKeyMiddleware consommateurs, throttle loyalty/register réel,
  POST observability/client-metrics (spam non authentifié ?).
- **W5** : les surfaces avec matériel (pont 9100, customer display serial, TPE) ne sont PAS
  validables ici — étiqueter d'avance « owner-only physical » pour éviter de re-boucler
  (leçon mémoire projet : ne pas re-boucler sur les blockers physiques). Ajouter les
  surfaces admin non couvertes par le plan visuel actuel : /admin/encaissement,
  cash-overview, historique, observability, catalog studio.

**Risques de faux positifs à briefer aux finders W2 :**
- Deux fichiers du périmètre sont **modifiés non-commités** (guard KIOSK OrderRequest:205-208
  — dit par le lecteur storefront) : un finder qui diff HEAD hurlera à tort ; brief =
  l'état working-tree est l'état à auditer, noter séparément « absent du HEAD/VPS ».
- `.claude/worktrees/*` supprimés + sentinelles F001/F009 = bruit connu (mémoire projet).
- Vitest : 1 fail focus-visible PRÉ-EXISTANT (regex/minif) — ne pas l'attribuer à W2.
- `env('DEMO')` deux branches identiques (ItemController:137-141) : déjà trouvé par CENTRAL,
  dédupliquer.
- PosOrderRequest:117 `===` string/int : connu et « laissé exprès » (mémoire projet) — le
  re-signaler comme P0 serait un doublon ; le traiter comme « decision à confirmer owner ».
- 59 items DB vs « 45 » CONSTITUTION : écart doc, pas bug code.

**Ordre :** OK (comprendre → correctness → sync → security → e2e), mais faire lire le
rapport du reviewer OPS/CRON par les reviewers KDS et Cœur AVANT leur passe W2 (les purges
et la clôture auto changent leurs invariants temporels).
