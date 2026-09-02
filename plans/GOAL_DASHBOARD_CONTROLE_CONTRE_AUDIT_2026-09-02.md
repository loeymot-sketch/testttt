# GOAL — Dashboard & contrôle : contre-audit Codex, dispute adversaire, correction en boucle

**Date :** 2026-09-02 · **Branche :** `pos/category-first-caisse-2026-06-23` · **HEAD au départ :** `ef0e41d01`
**Source auditée :** `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_CONTINUATION_2026-09-02.md` (+ matrice
`DASHBOARD_CONTROL_TRACEABILITY_MATRIX_2026-09-02.md`, UI `UI_DASHBOARD_CONTROL_GUIDELINES_2026-09-02.md`)
**Mandat propriétaire :** « crée un goal, lance-toi dessus en superviseur : audit et vérification de l'audit Codex,
dispute avec des petits agents adversaires, puis raisonne, applique, corrige et teste en boucle jusqu'à ce que
tout soit validé ; ensuite planifie, toujours avec du raisonnement. »
**Rapports de mission :** `reports/test-e2e/dashboard-controle-2026-09-02/`

---

## §0 — Préambule

### §0.1 Décision arbre de travail (documentée, non négociable pendant la mission)

État mesuré au départ : **223 fichiers stagés** (GOAL CAISSE VISION 2026-08-24, images menu, LOCK kiosk),
4 fichiers non stagés (`ComposerProfileController.php`, `reports/grok/JOURNAL.md`, 2 specs Grok), pas de
`MERGE_HEAD`, 0 marqueur de conflit dans `PROJECT_BRAIN.md`, disque **99 % (7,7 Gio libres)**.

- L'index stagé **n'est pas à moi** : je ne le touche pas (ni `git add .`, ni `restore --staged`, ni stash).
- Chaque commit de cette mission se fait par **pathspec explicite** (`git commit -- <fichiers>`), donc le hook
  `.git/hooks/pre-commit` (bloc 5) ne voit que mes fichiers. Ce n'est pas un contournement : la modification
  frozen n'entre dans aucun de mes commits.
- `bash .cursor/hooks/safety-check.sh` restera **BLOCKED** tant que `KioskWizardComponent.vue` (53 insertions,
  LOCK `docs/locks/LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25.md`, contresignature `☐`) est stagé.
  C'est la porte **G1** (§G). Elle bloque la **certification release** (W7), pas l'audit ni les correctifs (W2–W6).
- Aucun `composer dump-autoload` sur serveur vivant (CLAUDE.md §3ter). Aucun push (§3quater).

### §0.2 Environnement servi (mesuré)

| Port | Processus | cwd | Rôle |
|---|---|---|---|
| 8000 | `php -S 127.0.0.1:8000` (artisan serve) | `testttt/public` (arbre principal) | serveur réel |
| 8766 | `Python /tmp/foodking-proxy-8766.py` | `testttt` | proxy transparent → 8000 (l'axios compilé parle à 8766) |

`.env` : `APP_URL=http://127.0.0.1:8766`, `DB_DATABASE=foodking_e2e`. PHPUnit : `phpunit.xml` → sqlite, `APP_ENV=testing`.
Playwright : `PLAYWRIGHT_BASE_URL` sinon `http://localhost:8000`, `workers: 1`. **Preuve source = bundle = serveur**
exigée en W1 (SHA du `public/js/app.js` servi vs disque, `mix-manifest` hash) — Codex a raison : sans ça, la campagne
teste peut-être un autre état.

### §0.3 Drapeaux d'extension de périmètre

- Le worktree `port-e2e-0902` (HEAD `ef0d7b0f1`, Vitest rouge 8 fichiers) est **hors périmètre** ; sa régression
  `canFetchAlerts() → true sur permissions vides` **ne doit pas être portée** (Codex, confirmé par le commentaire du
  spec `stockLowAlertsWidgetCountBadge.spec.js:20-25` de l'arbre courant).
- Les 4 fichiers non stagés (Grok composer) sont hors périmètre : ni touchés, ni commités.
- `fiscal:verify-chain` signale un TAMPER **faux positif** connu (BRAIN §2 2026-08-26, secret par branche vs
  cache config). Toute exposition de ce résultat dans un widget passe par **G2**.

### §0.4 Pipeline par tâche et convergence

Chaque tâche W3–W4 suit `ultra-audit-profond` (spécialistes lecture seule → synthèse → TDD → RED → test → visuel).
Convergence (Axis 6) : **deux cycles consécutifs, P0+P1 = 0, ensembles de constats identiques.** Un « presque »
est un rejet. Deux essais par défaut ; au 3ᵉ, arrêt + `STUCK_<wave>.md` + décision propriétaire.

### §0.5 Verdict de départ (mien, avant dispute)

Lecture directe du code sur 12 P1 + 4 P2 Codex : **9 P1 confirmés au file:line** (A, E, F, G, H, I, J, K, L),
**2 P1 = constats de processus** (B campagne E2E absente, C non-convergence), **1 P1 = porte propriétaire** (D).
P2-A/B/C/D confirmés (D atténué : `QueryExceptionLibrary::message()` masque déjà les `QueryException` hors debug,
mais pas les autres exceptions). La dispute W2 doit tenter de **réfuter** ces confirmations, pas les répéter.

---

## §1 — Carte des systèmes (ancrages vérifiés 2026-09-02 par `find`/`grep`/`sed`)

| # | Système | Maturité | Ancrage principal (vérifié) | Tests existants |
|---|---|---|---|---|
| S1 | Dashboard analytique (16 routes) | Corrigé partiellement le 29/08 + 02/09 | `routes/api.php:1492-1512`, `app/Http/Controllers/Admin/DashboardController.php:30-76`, `app/Services/DashboardService.php:46-80,167-182,252-325,953-1050` | 15 fichiers `tests/Feature/Dashboard/` + `tests/Feature/Grok/DashboardControlAuditFixesTest.php` |
| S2 | Cockpit santé / interrupteurs / sauvegarde | Fraîcheur ajoutée 02/09, restore aveugle | `SyncOverviewController.php:606-720` (`systemHealth`), `Pilotage/InterrupteurController.php:38-66`, `HealthController.php:196-207`, `Commands/Backup/BackupVerifyRestoreCommand.php:77-240,421-461` | `tests/Feature/Observability/SystemHealthTest.php` (9 tests), `tests/Feature/Pilotage/Interrupteur*Test.php`, `tests/Unit/Backup/BackupVerifyRestoreLogicTest.php` |
| S3 | Cockpit outbox (pipeline domain_events) | Sémantique `broadcast_at` non suivie | `SyncOverviewController.php:317-380,390-457,518-593`, `app/Jobs/DispatchDomainEventsJob.php:54-160`, `Commands/OutboxRetryFailedCommand.php:71-135`, `resources/js/components/admin/observability/OutboxOverviewComponent.vue:50-135,340-400` | `tests/Feature/Observability/OutboxOverviewControllerTest.php` (9 tests), `tests/Feature/Outbox/OutboxConcurrentRetryLockTest.php`, `OutboxReplayAuditTest.php`, `tests/js/observabilityOutboxRoute.spec.js` |
| S4 | Certification (preuves, E2E, gate) | Rouge : reporter Playwright du 29/08, safety BLOCKED | `playwright.config.js:12-41,74-83`, `reports/antigravity/playwright-latest.json` (mtime 29/08 07:56), `.cursor/hooks/safety-check.sh`, `.git/hooks/pre-commit:88-113` | `tests/e2e/_grok-dashboard-wave-A-2026-08-29.spec.js` (1 scénario), `tests/e2e/dashboard-visual-integrity.spec.js`, `tests/e2e/09-admin-dashboards-ui.spec.js` |

Composants Vue du périmètre (18, `resources/js/components/admin/dashboard/` + `observability/`) : dont **10 sans
test direct** (matrice Codex, confirmée) : AuditTrail, ChannelStats, CustomerStats, FeaturedItems, MostPopularItems,
OrderStatistics, RealtimeReport, SalesSummary, TopCustomers, SystemHealth.

## §2 — Systèmes séparés

Mobile RN et web standalone : **hors périmètre** (mandat owner : aucun câblage API V1).

---

## §3 — S1 Dashboard analytique

### Contrat
16 routes sous `permission:dashboard` (15) + `pos-manage-fiscal` (`eod-pdf`). Scope branche fail-closed pour tout
non-Admin/Tenant Admin (`dashboardBranchId()` → 403 si `branch_id ≤ 0`). Dates : ordre + fenêtre ≤ 366 j.
Erreurs : jamais de message interne au client.

### Zones gelées adjacentes
`app/Models/Scopes/BranchScope.php` (lue, jamais modifiée — le garde se met **avant** le scope, côté service).

### Sub 3.1 — Contrat dates unique (P1-E, P1-G)
**Anchors** : `DashboardService.php:46-58` (`assertSalesDateWindow`), `:167-182` (`resolveDayBoundaryParis`, sans
garde), `:194-197`, `:258-261`, `:332-335` (garde uniquement si les DEUX bornes), `:286` (boucle `+86400`).
**Tasks** :
- T-3.1.1 Un seul résolveur `resolveDashboardWindow(Request)` : les 4 endpoints datés passent par lui ; borne isolée
  → 422 (pas de repli silencieux) ; `orderStatistics` reçoit le garde inversion/366 j.
  • test : `tests/Feature/Dashboard/DashboardDateContractMatrixTest.php` (TO BE CREATED) — table 4 endpoints × {inversé,
  >366 j, borne isolée, date invalide `2026-02-31`, date future} → 422 avec message FR ; cas nominal 200.
- T-3.1.2 `salesSummary` : générer les jours par `CarbonPeriod` (jours civils Paris), plus jamais `+86400`.
  • test : `tests/Feature/Dashboard/SalesSummaryDstBoundarySentinelTest.php` (TO BE CREATED) — 2026-03-28→03-31 = 4
  jours distincts ; 2026-10-24→10-26 = 3 jours sans doublon ; `dayCount` = nb de jours.
- T-3.1.3 EOD PDF : `DashboardController::eodPdf` (`:253-283`) valide `date` en `Y-m-d` réel (rejet `2026-02-31`)
  et le jour par défaut est choisi **côté serveur** (Paris), pas par le navigateur.
  • test : `tests/Feature/Dashboard/EodPdfRecapSentinelTest.php` (existant, à étendre : cas date invalide → 422).
**Acceptance** : les 3 tests ci-dessus verts + `SisterServicesTzAwareV2Test`, `SalesSummaryPerDayTest`,
`DashboardSalesReportParisBoundsSentinelTest` inchangés verts.

### Sub 3.2 — Fail-closed uniforme (P1-F)
**Anchors** : `DashboardController.php:176-187` (`mostPopularItems` → `ItemService::mostPopularItems`
`app/Services/ItemService.php:635-643`, `withCount('orders')` sous `BranchScope` qui **ne filtre pas** quand
`branch_id = 0`, `BranchScope.php:31-35`), `DashboardService.php:60-77`.
**Tasks** :
- T-3.2.1 `popular-items` passe par le garde `dashboardBranchId()` avant tout `withCount` (méthode publique
  `DashboardService::popularItems()` ou garde explicite dans le contrôleur) ; scope branche appliqué au `withCount`.
  • test : `tests/Feature/Dashboard/PopularItemsFailClosedTest.php` (TO BE CREATED) — non-admin `branch_id=0` → 403 ;
  staff branche 1 → compte de la branche 1 seulement ; Admin → global.
- T-3.2.2 Matrice HTTP des 6 routes sans test direct (`order-statistics`, `order-summary`, `customer-states`,
  `top-customers`, `popular-items`, `audit-trail`) : 401 / 403 sans permission / 403 non-admin sans branche / 200 Admin.
  • test : `tests/Feature/Dashboard/DashboardRoutesAuthzMatrixTest.php` (TO BE CREATED).
**Acceptance** : 2 tests verts + `DashboardBranchScopeMatrixTest`, `AnalyticReadAuthzSentinelTest` inchangés.

### Sub 3.3 — Erreurs publiques stables (P2-D)
**Anchors** : `DashboardController.php:30-40` (`dashboardFailure` renvoie `getMessage()`),
`app/Libraries/QueryExceptionLibrary.php:16-27`.
**Tasks** :
- T-3.3.1 Message public stable (`trans('all.message.database_error_message')` ou équivalent FR) pour toute exception
  non-HTTP/non-validation ; `Log::error` corrélé (`correlation_id` si dispo) côté serveur.
  • test : `tests/Feature/Dashboard/DashboardFailureDoesNotLeakInternalsTest.php` (TO BE CREATED) — service mocké
  lançant `RuntimeException('SQLSTATE[...] secret')` → 422 sans la chaîne interne.
**Acceptance** : test vert ; réponse 422 ne contient ni `SQLSTATE` ni chemin de fichier.

### Sub 3.4 — Widget NF525 honnête (P1-I)
**Anchors** : `DashboardService.php:1013` (`hash_prefix` = 8 caractères, aucune vérification),
`AuditTrailComponent.vue:6-9,19,31-34` (« Le préfixe de hash atteste l'intégrité de la chaîne » — **faux**).
**Tasks** :
- T-3.4.1 Libellés honnêtes : « préfixe du hash de chaînage » ; l'intégrité n'est attestée que par
  `fiscal:verify-chain`. Ajouter `branch_id` et horodatage exact (`created_at` ISO) à chaque ligne du payload.
  • test : `tests/js/auditTrailWidgetHonestLabels.spec.js` (TO BE CREATED) — le DOM ne contient plus « atteste
  l'intégrité » ; chaque ligne montre branche + horodatage exact ; `tests/Feature/Dashboard/AuditTrailUsesAuditLogSentinelTest.php`
  étendu (clés `branch_id`, `created_at`).
- T-3.4.2 (**G2**) Exposer un statut « dernière vérification de chaîne » daté par branche, **seulement si** le
  propriétaire tranche le faux positif TAMPER. Sinon : « non vérifiée par ce widget » explicite.
**Acceptance** : T-3.4.1 verts ; T-3.4.2 documenté PENDING G2 dans le rapport.

---

## §4 — S2 Cockpit santé / interrupteurs / sauvegarde

### Contrat
`GET /admin/observability/system-health` (Admin/Tenant Admin) agrège `healthz:last`, fraîcheur backup, tic
planificateur. Un panneau qui ne mesure rien doit le dire ; un drill de restauration raté ne peut pas laisser
« Tout va bien ».

### Zones gelées adjacentes
`app/Services/Fiscal/AuditLogService.php` — **utilisée via `write()` (`:70`), jamais modifiée** (même patron que
`OutboxRetryFailedCommand.php:116-135`).

### Sub 4.1 — Restauration mesurée (P1-A)
**Anchors** : `BackupVerifyRestoreCommand.php:421-461` (verdict `$green`/`$reasons`, seulement `Log::channel('observability')`),
`SyncOverviewController.php:611-621,676-685,700-707`, `HealthController.php:196-207`.
**Tasks** :
- T-4.1.1 La commande persiste `{status, verified_at, file, sha256, duration_s, reasons[]}` dans
  `storage/app/backups/restore-drill-last.json` (+ `Cache::forever('backup:restore_drill:last')`), succès **et** échec.
  • test : `tests/Unit/Backup/BackupVerifyRestoreLogicTest.php` (existant, étendre) + `tests/Feature/Backup/RestoreDrillResultIsPersistedTest.php` (TO BE CREATED).
- T-4.1.2 `systemHealth` et `HealthController::checkBackupAge` lisent ce résultat : drill échoué ou > 26 h →
  alerte « restauration non vérifiée / échouée », verdict ≠ `ok`. Payload `sauvegarde.restauration = {...}`.
  • test : `tests/Feature/Observability/SystemHealthTest.php` (existant, étendre : drill échoué → `attention`).
**Acceptance** : 3 tests verts ; le composant `SystemHealthComponent.vue` affiche l'état du drill (visuel W5).

### Sub 4.2 — Fraîcheur sans faux vert (P1-H)
**Anchors** : `SyncOverviewController.php:636-645` (timestamp non numérique/invalide → `$mesureAgeMin = null` →
aucune alerte ; futur → âge négatif → aucune alerte), `:620` (`round()` avant seuil : 26,4 h → 26 → vert) vs
`HealthController.php:203-204` (heures décimales → `degraded`).
**Tasks** :
- T-4.2.1 Timestamp présent mais illisible ou futur → alerte « horodatage de mesure invalide ».
- T-4.2.2 Comparer l'âge backup en **décimal** avant arrondi d'affichage ; même règle que `HealthController`.
  • test : `tests/Feature/Observability/SystemHealthFreshnessEdgeCasesTest.php` (TO BE CREATED) — 4 cas.
**Acceptance** : test vert ; `SystemHealthTest` inchangé vert.

### Sub 4.3 — Audit durable des interrupteurs (P2-B)
**Anchors** : `InterrupteurController.php:38-66` (`Log::info` seulement).
**Tasks** :
- T-4.3.1 Écrire une ligne `AuditLogService::write()` (action `pilotage.interrupteur.bascule`, acteur, avant/après,
  branche, corrélation) **avant** de répondre ; échec d'audit → 500 explicite, pas de bascule silencieuse.
  • test : `tests/Feature/Pilotage/InterrupteurBasculeEstAuditeeTest.php` (TO BE CREATED) — ligne `audit_logs`
  présente, chaîne intacte (`verifyChain` sur la branche de test).
**Acceptance** : test vert ; `InterrupteurTest`, `CashierCannotToggleInterrupteurTest` inchangés.

---

## §5 — S3 Cockpit outbox

### Contrat
Depuis la migration du 2026-08-04, `dispatched_at` = **claim** (posé avant broadcast, `DispatchDomainEventsJob.php:74-78`),
`broadcast_at` = **livraison réelle** (`:151-154`). Le cockpit doit compter la livraison, pas le claim.

### Sub 5.1 — Sémantique de livraison (P1-J)
**Anchors** : `SyncOverviewController.php:321` (pending = `dispatched_at IS NULL`), `:342-345` (livrés 24 h =
`dispatched_at`), `:539-552,571-577` (probes sur `dispatched_at`), tests verrouillant l'ancienne sémantique
`OutboxOverviewControllerTest.php:85,125,141,171`.
**Tasks** :
- T-5.1.1 `outboxOverview` expose `pending` (jamais claim), `in_flight` (claim sans broadcast, < 5 min),
  `stale_claimed` (claim sans broadcast ≥ 5 min), `delivered_24h` (`broadcast_at`), `terminal_failures`
  (`last_error` non nul, `dispatched_at` nul, attempts épuisés). Clé `dispatched_24h` conservée en alias déprécié
  une release (le composant est migré en W4).
- T-5.1.2 `probeHealth` : signal positif = `broadcast_at` (queue et websocket), plus jamais `dispatched_at`.
  • test : `tests/Feature/Observability/OutboxDeliverySemanticsTest.php` (TO BE CREATED) — crash Phase 1→2 simulé
  (`dispatched_at` posé, `broadcast_at` nul) : n'est ni pending ni livré, apparaît en `stale_claimed`, probes `down`.
  `OutboxOverviewControllerTest` mis à jour pour renseigner `broadcast_at`.
**Acceptance** : 2 tests verts + `DispatchDomainEventsFailedCallbackTest`, `WsHeartbeatWriteSentinelTest` inchangés.

### Sub 5.2 — Actions auditées et bornées (P1-K)
**Anchors** : `SyncOverviewController.php:390-431` (retry web : reset sans lock ni audit), `:440-457` (drain :
`DELETE failed_jobs` sans filtre de classe, sans audit), référence conforme `OutboxRetryFailedCommand.php:71-135`
(lock `Cache::lock` + `AuditLogService::write`).
**Tasks** :
- T-5.2.1 Extraire `App\Services\Outbox\OutboxReplayService` partagé commande/contrôleur : lock, audit
  `outbox.replay`, dispatch-après-commit. Le contrôleur web l'appelle ; conflit de lock → 409 explicite.
  • test : `tests/Feature/Observability/OutboxWebRetryUsesSharedReplayServiceTest.php` (TO BE CREATED) — audit row,
  lock contention → 409 ; `OutboxConcurrentRetryLockTest` + `OutboxReplayAuditTest` inchangés verts.
- T-5.2.2 Drain : limité à `failed_jobs` dont la classe est `DispatchDomainEventsJob` (filtre `payload` JSON),
  export JSON des lignes supprimées dans `storage/app/outbox/drained-<ts>.json`, audit `outbox.drain` (acteur,
  compte, cutoff), réponse `{deleted, exported_to}`.
  • test : `OutboxOverviewControllerTest::test_drain_failed_deletes_only_old_rows` étendu + nouveau cas « job étranger
  non supprimé ».
**Acceptance** : tests verts ; aucune modification de `DispatchDomainEventsJob.php`.

### Sub 5.3 — Composant honnête en panne (P1-L)
**Anchors** : `OutboxOverviewComponent.vue:358-371` (`loadAll` sans `catch`), `:60,68` (boutons activés sur
`pending.count`), `:372-391` (retry/drain sans confirmation ni retour).
**Tasks** :
- T-5.3.1 États `loading/ok/degraded/unknown/stale` : `catch` → bannière `role="alert"` + âge des données + bouton
  réessayer ; requêtes concurrentes empêchées ; l'ancien vert ne survit pas à une panne.
- T-5.3.2 Retry activé seulement s'il existe des `terminal_failures` ; drain avec confirmation explicite (pas de
  `confirm()` natif — modale in-page) et retour succès/échec `role="status"`.
- T-5.3.3 Migration du composant vers `delivered_24h`/`in_flight`/`stale_claimed` (T-5.1.1).
  • test : `tests/js/outboxOverviewPanneReseau.spec.js` (TO BE CREATED) — mock axios rejet 500/403 → bannière, valeurs
  marquées périmées ; deux `loadAll` concurrents → une requête ; drain sans confirmation → aucun POST.
**Acceptance** : test vert + `observabilityOutboxRoute.spec.js` inchangé ; visuel W5 (capture lue).

---

## §6 — S4 Certification et preuves

### Contrat
« Tout testé » n'est affirmable que si : snapshot gelé (SHA), source = bundle = serveur prouvés, suites ciblées et
complètes vertes **avec logs bruts joints**, campagne Playwright dédiée sans skip, postconditions DB, safety PASS.

### Sub 6.1 — Parité serveur (P1-B pré-requis)
- T-6.1.1 Preuve : `curl -s http://127.0.0.1:8766/js/app.js | shasum` = `shasum public/js/app.js` ; `mix-manifest`
  hash ; `git rev-parse HEAD` ; `lsof` cwd des PID 8000/8766 ; DB réellement utilisée (`SELECT DATABASE()` via tinker).
  • preuve : `reports/test-e2e/dashboard-controle-2026-09-02/PARITE_SERVEUR.md`.

### Sub 6.2 — Campagne Playwright dédiée (P1-B)
- T-6.2.1 `tests/e2e/dashboard-controle-2026-09-02.spec.js` (TO BE CREATED) : Admin (dashboard + cockpit + outbox
  + 16 API 200), POS Operator (redirection, GET 403 sur system-health/interrupteurs/outbox), non authentifié (401),
  dates inversées → 422 visible, backup stale / drill échoué (fixture disque) → carte rouge, outbox panne réseau
  (route abort) → bannière, toggle interrupteur + restauration, 0 erreur console, viewports 1366×768 + 1024×600.
  Reporter JSON publié dans `reports/antigravity/playwright-latest.json` **et** copie datée dans la mission.
- T-6.2.2 Captures lues (Read) : dashboard, cockpit, outbox en état sain et en état dégradé.

### Sub 6.3 — Logs bruts (P1-C)
- T-6.3.1 PHPUnit ciblé (filtres Dashboard|Observability|Pilotage|Outbox|Backup) puis **complet**, sortie brute
  `phpunit-full.log` ; Vitest complet `vitest-full.log` ; comptes exacts dans le rapport.
- T-6.3.2 Frozen diff `git diff HEAD --stat -- <13 fichiers §7>` **index inclus** (leçon Codex P1-D) ; NF525
  `SELECT COUNT(*), MAX(id) FROM audit_logs` avant/après = append-only.

---

## §A — Armée d'agents

| Rôle | Type | Outils | Mandat |
|---|---|---|---|
| RED-Sync | `general-purpose` | lecture seule | réfuter/confirmer P1-J, P1-K, P1-L (+ crash Phase 1→2 reproduit en test jetable) |
| RED-Analytics | `general-purpose` | lecture seule | réfuter/confirmer P1-E, P1-F, P1-G, P2-D (+ preuve DST par script PHP jetable) |
| RED-Cockpit | `general-purpose` | lecture seule | réfuter/confirmer P1-A, P1-H, P1-I, P2-B |
| RED-Preuves | `general-purpose` | lecture seule | réfuter/confirmer P1-B, P1-C, P1-D, P2-A, P2-C : certificat Grok, reporter Playwright, cache Vitest, parité bundle |
| Implementer | moi (thread principal) | Edit/Write/Bash | TDD, un sous-système à la fois, jamais deux implémenteurs |
| RED-post-fix ×2 | `general-purpose` | lecture seule | après W3/W4 : disputer MES correctifs (mémoire `auditer-mon-propre-correctif-du-jour`) |
| QA Visual + RED Visual | `general-purpose` | Playwright + Read | W5 : capture puis relecture indépendante des captures |

**Contrat de rapport** (chaque agent, sur disque, ≤ 1 500 mots) :
`reports/test-e2e/dashboard-controle-2026-09-02/round-<n>/wave-<W>-<role>.md`, par constat :
`[CONFIRMED|REFUTED|UNPROVEN] <id> <file>:<line> — titre / reproduction / preuve / correctif minimal`.
Un constat sans `file:line` **et** sans reproduction = REJETÉ (CLAUDE.md §3ter). Fan-out lecture seule = un seul
message, N appels `Agent`. Instrument neuf = faux par défaut : tout nouveau test doit **mordre** (défaut restauré →
rouge) avant d'être compté.

---

## §X — Vagues de convergence

| W | Portée | Parallélisme | Checkpoint | Reprise |
|---|---|---|---|---|
| W1 Pré-vol | snapshot SHA, parité serveur (T-6.1.1), baselines PHPUnit ciblé / Vitest / `audit_logs` count+MAX(id), disque | séquentiel | `PARITE_SERVEUR.md` + `BASELINE.md` écrits | relire les deux fichiers |
| W2 Dispute | 4 RED lecture seule sur les 16 constats Codex | **parallèle (1 message, 4 Agent)** | 4 rapports sur disque, synthèse `SYNTHESE_W2.md` : liste CONFIRMED ordonnée par impact | relire la synthèse |
| W3 Heal backend | Sub 5.1 → 5.2 → 4.1 → 4.2 → 3.1 → 3.2 → 3.3 → 4.3 (ordre d'impact) | séquentiel, TDD, 1 commit par sous-système (pathspec explicite) | tests nommés verts, sentinelles voisines vertes, chaque nouveau test prouvé mordant | `git log`, dernier test rouge |
| W4 Heal frontend | Sub 5.3 → 3.4.1 → affichage drill (4.1) | séquentiel, TDD Vitest | specs verts, `npx mix` rebuild, captures lues | idem |
| W5 Certification | T-6.2, T-6.3, captures QA + RED Visual | QA/RED visuel parallèle | logs bruts joints, reporter Playwright daté du jour, 0 skip, 0 erreur console | relancer la spec |
| W6 RED post-fix | 2 agents disputent W3+W4 | parallèle | 2 cycles consécutifs P0+P1 = 0, constats identiques | relancer W6 |
| W7 Clôture | rapport, BRAIN §2/§3/§4, liste des portes | séquentiel | `RAPPORT.md` + BRAIN à jour ; verdict GREEN/YELLOW/RED honnête | — |

Règle d'interruption : commit `wip(W<n>): …` par pathspec + `INTERRUPT_W<n>_<ts>.md` + BRAIN §2.
Règle de blocage : 3ᵉ échec sur le même groupe → `STUCK_W<n>_<ts>.md` + arrêt + choix propriétaire (A accepter
documenté / B pivot / C différer / D porte humaine).

---

## §G — Portes propriétaire

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G1 | Contresigner le LOCK kiosk **ou** retirer légitimement `KioskWizardComponent.vue` de l'index | Propriétaire physique | `☑` dans `docs/locks/LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25.md` §Contresignature, puis commit du LOCK + du fichier gelé avec citation `LOCK_…md` dans le sujet (mémoire `hook-frozen-zone-lit-commit-precedent`) | `bash .cursor/hooks/safety-check.sh` → PASS ; BRAIN §2 | **PENDING** — bloque W7 « release », pas W2–W6 |
| G2 | Exposer le résultat de `fiscal:verify-chain` dans le widget NF525 alors qu'il rend un TAMPER faux positif connu | Propriétaire | décision : corriger d'abord `AuditLogService::secretFor()` (zone gelée, LOCK requis) ou afficher « non vérifiée » | BRAIN §6 DECISIONS LOG | **PENDING** — T-3.4.2 différée, T-3.4.1 livrée |
| G3 | Push / déploiement VPS | Propriétaire | ordre explicite | — | jamais automatique (CLAUDE.md §3quater) |

Protocole d'attente : W2–W6 s'exécutent pendant que G1 est ouverte ; W7 déclare **YELLOW** au mieux tant que
`safety-check` ≠ PASS, et le dit.

---

## §R — Références

Skills : `ultra-audit-profond`, `superpower-gstack`, `test-e2e`, `verify-before-report`, `lock-plan`.
Docs : `CLAUDE.md` §3ter (instrument avant produit), §7, §8, §9 ; `docs/PLAYWRIGHT_MCP_OPS.md §7`.
Mémoires : `prouver-qu-un-banc-mord`, `sentinelle-au-mauvais-perimetre`, `auditer-mon-propre-correctif-du-jour`,
`git-checkout-diagnostic-stage-l-index`, `served-ports-main-vs-worktree`.
Rapports antérieurs : `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_2026-08-29.md`,
`reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md` (ciblé, non convergent — Codex a raison),
`plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md`, `plans/GOAL_VALIDATION_ABSOLUE_GROK_2026-09-01.md`.

## §F — Règle finale

Le GOAL est DONE quand, **sur un même SHA** : tous les tests nommés ci-dessus existent, mordent et sont verts ;
PHPUnit et Vitest complets verts avec logs bruts joints ; campagne Playwright dédiée du jour verte sans skip,
captures lues ; frozen diff **index inclus** = 0 hors G1 ; `audit_logs` append-only ; deux cycles RED consécutifs à
P0+P1 = 0 ; BRAIN à jour. Tant que G1 est ouverte, le verdict release est au mieux **YELLOW** et le rapport l'écrit
en première ligne. Pas de « presque ».
