# Synthèse W2 — dispute du contre-audit Codex (round 1)

Date : 2026-09-02 · HEAD `ef0e41d01` · superviseur : Claude (thread principal)

## Méthode, et ce qui n'a pas marché

Quatre agents adversaires en lecture seule (RED-Sync, RED-Analytics, RED-Cockpit, RED-Preuves) ont été
lancés à 02:49 et **tués par la limite de session** (HTTP 429, réinitialisation 06:40) avant d'écrire une
ligne. La dispute ci-dessous a donc été menée par le thread principal, avec la même règle : **aucun
verdict sans `file:line` lu et sans reproduction exécutée**. Une seconde passe indépendante par agents est
due en W6 (post-correctifs), pour disputer à la fois Codex et mes propres correctifs.

Baselines mesurées avant tout correctif (`PARITE_SERVEUR.md`, `BASELINE.md`,
`phpunit-baseline-cible.log`) :
- source = bundle = serveur **prouvé** (sha256 `f7dd10d7…` identique disque / :8000 / :8766) ;
- PHPUnit ciblé (Dashboard|Observability|Pilotage|Outbox|Backup) : **359 tests, 1 erreur, 2 sautés** ;
- Vitest voisins : 4 fichiers / 61 tests verts ; cache global 504 fichiers / 0 échec ;
- base servie `foodking_e2e` : `audit_logs` 8 529 lignes (max id 8 584), `domain_events` 13 676 dont
  **2 149 réclamés sans diffusion depuis le 2026-08-04**, 17 pending avec `last_error`, 1 `failed_jobs`
  (`ReverseRawMaterialsOnOrderCanceled`, pas un job outbox), dernière sauvegarde `.sql.gz` du 2026-08-05.

## Verdicts

| Id | Verdict | Preuve (lue / exécutée) | Sévérité retenue |
|---|---|---|---|
| P1-J outbox compte un claim comme livré | **CONFIRMED** | `SyncOverviewController.php:321,343-344,540-542,571-577` sur `dispatched_at` ; job pose `broadcast_at` en `DispatchDomainEventsJob.php:151-154` ; `OutboxRescueCommand.php:44-54` lit déjà `broadcast_at IS NULL` (seuil 10 min) — le cockpit est le seul lecteur resté sur l'ancienne sémantique. Sur la base servie : 2 149 lignes claimées sans broadcast, invisibles du cockpit. | **P1** — faux vert sync, rôle même de l'écran |
| P1-K actions outbox sans audit ni lock | **CONFIRMED** | `:390-431` retry web (reset sans lock ni audit) vs `OutboxRetryFailedCommand.php:71-135` (lock + `AuditLogService::write`) ; `:440-457` drain `DELETE failed_jobs` sans filtre de classe (le seul `failed_jobs` de la base est un listener stock, pas un job outbox) ni audit. Aucun middleware d'audit global sur ces routes. | **P1** en prod (preuve forensique effaçable d'un clic) ; impact V1 mono-Admin : moyen |
| P1-L composant aveugle en panne | **CONFIRMED** | `OutboxOverviewComponent.vue:358-371` sans `catch` ; `:60` retry activé sur `pending.count` alors que l'endpoint sélectionne `last_error` non nul (`:406-408`). | **P1** (cockpit opérationnel) |
| P1-A restauration aveugle | **CONFIRMED** | `BackupVerifyRestoreCommand.php:421-461` : verdict seulement en `Log::channel('observability')` ; planifié 05:00 (`Kernel.php:204-210`, sortie vers `storage/logs/backup-verify-restore.log` **absent** sur cette machine : jamais tourné) ; `systemHealth` `:611-621` et `HealthController.php:196-207` ne lisent que `filemtime`. | **P1** |
| P1-H fraîcheur à faux vert | **CONFIRMED** (3/3) | `php -r` : `(int) round(26.4) = 26 → > 26 false` (cockpit vert) vs readiness `26.4 > 26 true` ; timestamp illisible → `strtotime=false` → `mesureAgeMin=null` → 0 alerte (`:636-645`) ; timestamp futur → âge `-120` → 0 alerte. | **P2** (cas limites) — corrigé en W3 |
| P1-I widget NF525 | **CONFIRMED** | `AuditTrailComponent.vue:9` « Le préfixe de hash atteste l'intégrité de la chaîne » ; `DashboardService.php:1013` = `substr(current_hash,0,8)` sans vérification ; `FiscalVerifyChainCommand` ne persiste rien (`grep Cache::|Storage::` vide) ; `Kernel.php:132-142` ne fait que journaliser. | **P1** (libellé faux sur une surface fiscale) — libellé corrigé W4 ; statut vérifié = **G2** |
| P1-E contrat dates | **CONFIRMED** | garde `assertSalesDateWindow` appelé seulement si les deux bornes (`:194,258,332`) ; `orderStatistics` → `resolveDayBoundaryParis` `:167-182` sans garde ; aucune FormRequest (`grep first_date app/Http/Requests` vide). Atténuant : les 3 composants envoient toujours `e[0], e[1]` du sélecteur de plage. | **P2** (API) — corrigé W3 |
| P1-F popular-items | **CONFIRMED (étroit)** | `ItemService.php:635-643` `withCount('orders')` ; `Item::orders()` = `OrderItem` (`Item.php:165-168`), scopé `BranchScope` qui **ne filtre pas** à `branch_id = 0` sans regarder le rôle (`BranchScope.php:31-35`) ; `dashboardBranchId()` refuse ce cas partout ailleurs. Base servie : 245 comptes à `branch_id = 0` (Admin, Customer, et des comptes **sans rôle**) — exploitable seulement avec la permission `dashboard` mal attribuée. | **P2** (incohérence fail-closed) — corrigé W3 |
| P1-G(1) boucle `+86 400 s` | **CONFIRMED** | `php -r` TZ Paris : `2026-03-28→03-31` rend **3 jours (31 omis)** ; `2026-10-24→10-26` rend `24, 25, 25` (**25 doublé, 26 omis**). `DashboardService.php:286`. | **P2** (2 jours/an, graphique + moyenne) — corrigé W3 |
| P1-G(2) Ticket Moyen vs `business_date` | **REFUTED** | `business_date` = `Carbon::parse(order_datetime)->toDateString()` (`OrderService.php:3821-3832`, idem `FrontendOrderService.php:1463`) : c'est la **date civile Paris**, pas un jour fiscal décalé. `scopePeriod('today')` (`:411-418`) et `realtimeReport` (`Carbon::today` → `order_datetime` minuit→minuit, `:495-520`) sélectionnent donc la **même population**. Le commentaire de `scopePeriod` (« jour FISCAL … 00h30 ») décrit une intention, pas le code. | — (commentaire trompeur à corriger, pas de défaut de données) |
| P1-G(3) PDF EOD | **CONFIRMED** | `DashboardController.php:259-261` : regex `\d{4}-\d{2}-\d{2}` seulement → `2026-02-31` accepté puis normalisé par Carbon ; `DashboardComponent.vue:227-235` envoie `new Date()` **du navigateur**. | **P2** — corrigé W3 |
| P2-D fuite de messages | **CONFIRMED (étroit)** | `dashboardFailure` `:30-40` renvoie `getMessage()` ; `QueryExceptionLibrary::message` masque les `QueryException` hors debug mais pas le reste : ex. `salesSummary` non enveloppé → `Carbon::parse('abc')` → `InvalidFormatException` → 422 avec le message interne de Carbon. Pas de secret exposé ; contrat instable. | **P3** — corrigé W3 (validation amont + message stable) |
| P2-B interrupteurs sans audit durable | **CONFIRMED** | `InterrupteurController.php:52-58` `Log::info` ; `Pilotage/InterrupteurService.php:95-163` sans `AuditLog`/`ActionLog`. | **P2** — corrigé W3 |
| P1-D gate frozen + faux négatif du certificat | **CONFIRMED** | `safety-check.sh` → `BLOCKED` ; `git diff --stat -- KioskWizardComponent.vue` **vide** alors que `--cached` montre 53 insertions ; contresignature `☐` (LOCK `:…` dernière ligne). | **Porte G1** |
| P1-B campagne E2E absente | **CONFIRMED** | `playwright-latest.json` : 1 test attendu / 1 passé, `startTime 2026-08-29T05:56`, unique spec `_grok-dashboard-wave-A-2026-08-29.spec.js`. | **W5** |
| P1-C non-convergence | **CONFIRMED** | `78a878e23` annonce « Vitest 465/465, 3833 verts » et « PHPUnit complet en cours — résultat reporté » ; aucun log brut. Le run ciblé du jour révèle l'un des rouges : `OrderStatisticsSingleGroupedQueryTest` (admin factice sans rôle Admin → 403 depuis le fail-closed du 29/08, `:29-31`). | **W5** + test à réparer W3 |
| P2-A couverture | **CONFIRMED** | recomptage : 0 référence HTTP pour les 6 routes ; 0 référence spec pour les 10 composants (hors `__screenshots__`). `DashboardComponent.vue:48-63` ne monte ni `CustomerStats` ni `TopCustomers`. | **W3/W4** (tests des composants touchés) |
| P2-C contraste | **CONFIRMED (état)** | correctif présent en HEAD et dans le bundle servi (parité prouvée) ; aucune capture postérieure au rebuild. | **W5** |

## Ce que Codex n'a pas vu (trouvé pendant la dispute)

1. **Test rouge en baseline** : `tests/Feature/Dashboard/OrderStatisticsSingleGroupedQueryTest.php:29-31` crée un
   « Admin » `branch_id=0` **sans rôle** ; depuis `dashboardBranchId()` fail-closed il reçoit 403. Le correctif du
   29/08 a cassé ce test sans que personne ne le relance. À réparer (assigner le rôle Admin), pas à affaiblir.
2. **Le drill de restauration n'a jamais tourné ici** (`storage/logs/backup-verify-restore.log` absent) et la
   dernière sauvegarde a 28 jours : le cockpit doit déjà être rouge sur cette machine — à vérifier en W5.
3. `OutboxRescueCommand` réanime les claims orphelins après **10 min** (`:34`) ; le cockpit doit adopter le même
   seuil pour `stale_claimed`, sinon deux écrans diront deux choses.

## Ordre d'attaque W3 (par impact)

Sub 5.1 (sémantique livraison) → 5.2 (lock + audit + drain borné) → 4.1 (drill persisté) → 4.2 (fraîcheur) →
3.1 (dates + DST + EOD) → 3.2 (popular-items + matrice authz) → 3.3 (erreurs) → 4.3 (audit interrupteurs) →
réparation `OrderStatisticsSingleGroupedQueryTest`. Puis W4 : composant outbox, libellé NF525, carte drill.

Amendement au GOAL §3 Sub 3.1 : une **date future n'est pas rejetée** (le mois courant par défaut se termine
dans le futur ; le sélecteur envoie `endOfMonth`). Seuls sont rejetés : borne isolée, date invalide, inversion,
fenêtre > 366 jours.
