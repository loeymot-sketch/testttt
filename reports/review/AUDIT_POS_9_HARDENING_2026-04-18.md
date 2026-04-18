# AUDIT_POS_9_HARDENING — Post-POS-9.1 + POS-9.4 deep-dive — 2026-04-18

## Méthode

4 subagents readonly parallèles ont ré-audité le code **mergé / sur branche** :

| Subagent | Périmètre | Trouvailles |
|---|---|---|
| A — POS-9.1 deep-dive | 14 items merged sur `main` | 16 (P0:1 · P1:6 · P2:5 · P3:4) |
| B — POS-9.4 deep-dive | 12 items sur `feat/pos-phase-9-4` | 22 (P0:2 · P1:7 · P2:7 · P3:3 + 3 GDPR/NF525) |
| C — Cross-cutting sécurité/ops | système entier | 15 (P0:4 · P1:4 · P2:5 · P3:2) |
| D — Alignement plan/vision | FINDINGS tracker + invariants + BLOCKERs | 3 drifts tracker + 5 gaps couverture + 2 drifts scope |

**Deltas clés vs les rapports VERIFY précédents** :
- Le verifier POS-9.1 avait validé 14/14 ; l'audit A trouve **1 P0 sécurité** et 6 P1 cachés.
- Le verifier POS-9.4 avait validé 9/12 (3 BLOCKER légitimes) ; l'audit B trouve **2 P0** (race chain + dev-secret) et 7 P1 dans le code livré.
- Le subagent C remonte **F-C2** : les services `FiscalSequenceService` et `AuditLogService` n'ont **aucun appelant live** dans `app/` → POS-9.4 est infrastructure-only, pas fiscal-compliant en production.
- Le subagent D confirme : tracker surclassé (F-04 / F-14 / F-38 marqués `resolved` alors que partial), invariants 3/15 complets / 6/15 partiels / 6/15 pending.

---

## Findings P0 (bloquants — impact production immédiat)

### F-A1 · `abort(403)` avalé par `catch (Exception)` dans 4 méthodes `OrderService`
- **Zone** : `app/Services/OrderService.php`
  - `changeStatus(auth=false)` L1405-1491
  - `changePaymentStatus()` L1485-1537
  - `tokenCreate()` L1540-1560
  - `deliveryBoyOrderChangeStatus()` L1312-1369
- **Symptôme** : Chaque méthode wrappe la logique dans `try { ... } catch (Exception $e)` et re-throw en `HttpException(422)`. Les gardes `abort(403)` (tenant check) sont des `HttpException` → avalées → remontées comme 422.
- **Impact** : 
  - Les denials branche/ownership sortent en **HTTP 422 au lieu de 403** → régression silencieuse de sécurité ; un attaquant sait qu'il a tapé une ressource existante d'une autre branche.
  - `BranchIsolationTest` (F-A4) accepte `[403, 404, 422]` → **tests passent malgré la régression** (faux-positif 100%).
- **Fix** : injecter le pattern POS-9.1.2 déjà prouvé sur `destroy()` (commit `20eeddd47`) :
  ```php
  try { ... }
  catch (\Symfony\Component\HttpKernel\Exception\HttpException $h) { throw $h; }
  catch (\Exception $e) { ... }
  ```

### F-C1 / F-B2 · Dev-secret fiscal shippé en défaut config
- **Zone** : `config/fiscal.php:25,33` — défaut = `'dev-fiscal-audit-secret-do-not-use-in-prod'`.
- **Symptôme** : `AuditLogService::secretFor()` ne throw **que sur chaîne vide** (`''`). Si `FISCAL_AUDIT_SECRET` absent du `.env` prod, l'app signe avec une valeur **publique dans le repo** → signatures forgeables, NF525 HS.
- **Preuves corroborantes** : `.env.example` **ne contient pas** `FISCAL_AUDIT_SECRET=` / `FISCAL_Z_REPORT_SECRET=` ; aucune doc `docs/` ne mentionne ces clés.
- **Impact** : Compromission totale de la chaîne d'audit et des signatures Z en production par défaut.
- **Fix** :
  1. Changer défaut en `''`.
  2. Ajouter entrées vides dans `.env.example`.
  3. Étendre `secretFor()` et `ZReportService::sign()` pour throw aussi si `app()->environment('production') && str_starts_with($secret, 'dev-')`.

### F-C3 / F-B1 · Race TOCTOU sur `AuditLogService::write()` + absence d'unique DB
- **Zone** : `app/Services/Fiscal/AuditLogService.php:43-74`, migration `2026_04_22_000002_create_audit_logs_table.php:34-56`.
- **Symptôme** : `lastHashFor()` → compute → `create()` sans lock, sans transaction, sans `UNIQUE(branch_id, prev_hash)`. Deux écritures concurrentes sur la même branche lisent le même `prev_hash` → toutes deux s'insèrent → chaîne forkée.
- **Impact** : `verifyChain()` flaggue le 2e comme "tampered" sur trafic légitime → **faux-positifs fraude** sous charge → perte de confiance / audits NF525 rejetés.
- **Fix** :
  1. `Cache::lock("audit_chain_b{$branchId}", 5)->block(3)` autour de `write()`.
  2. Ajout d'un index `UNIQUE(branch_id, prev_hash)` (nullable-tolérant sur MySQL qui autorise multi-NULL — envisager `UNIQUE(branch_id, current_hash)` en complément).
  3. Envelopper dans `DB::transaction()` avec `lockForUpdate` sur `lastHashFor`.

### F-C2 · `FiscalSequenceService` et `AuditLogService` ont **zéro appelant live**
- **Zone** : tout `app/` en dehors des services eux-mêmes + tests.
- **Symptôme** : `grep -rn 'FiscalSequenceService\|AuditLogService' app/` → uniquement les services. `OrderService::posOrderStore`, `cancel`, `destroy`, `applyDiscount`, `PaymentService`, `DiscountCalculator` écrivent uniquement dans `ActionLog` (mutable).
- **Impact** : 
  - `orders.fiscal_sequence_no` → **toujours NULL** en production.
  - `audit_logs` → **table vide**.
  - Les tests `AuditLogHashChainTest` et `ZReportCloseTest` passent sur données synthétiques.
  - La claim "NF525 infra" devient *marketing only*.
- **Fix** : BLOCKERs 9.4.2b + 9.4.5 doivent être levés **avant** toute déclaration de conformité. En attendant, **downgrade F-04/F-38 à `partial`** dans le tracker (F-14 déjà partial via 9.4.10).

### F-C4 · Premier Z après deploy agrège des orders legacy NULL
- **Zone** : `app/Services/Fiscal/ZReportService.php:155-201` (méthode `aggregate`).
- **Symptôme** : `aggregate()` filtre par `branch_id` + `created_at` window + `payment_status != UNPAID`. **Aucun filtre `whereNotNull('fiscal_sequence_no')`**. Avec des orders legacy (et à cause de F-C2, toutes les orders post-deploy tant que BLOCKER 9.4.2b actif) → le premier Z signe des agrégats incluant des orders sans identité fiscale.
- **Impact** : Z signé sur données non-séquencées → non-conformité NF525 définitive (le Z est immuable, donc la non-conformité l'est aussi).
- **Fix** :
  ```php
  ->whereNotNull('fiscal_sequence_no')
  ```
  à ajouter dans `aggregate()` (L155) et `XReportService::snapshot()`. + data-migration report avant premier Z.

---

## Findings P1 (haute priorité — à corriger avant POS-9.2)

### F-A2 · Seeder RolePermissionTableSeeder silencieusement cassé
- **Zone** : `database/seeders/RolePermissionTableSeeder.php:25-73, 80-86` — `->whereIn('name', [['name'=>'x'], …])` (array of arrays) matche **0 lignes**.
- **Impact** : Branch Manager + le premier bloc POS Operator ne reçoivent **aucune permission** ; `pos-discount-*`, `pos-manage-fiscal`, `pos-reopen-z` ne vivent que sur Admin via `Permission::all()`.
- **Fix** : remplacer par strings plates (pattern L106-113 déjà correct).

### F-A3 · `DashboardService::auditTrail` fuite cross-tenant
- **Zone** : `app/Services/DashboardService.php:316-319` — `->where('branch_id', $b)->orWhereNull('branch_id')`.
- **Impact** : Toute ligne `action_logs` avec `branch_id=NULL` (= actions admin où `ActionLog::booted()` skippe `!empty` sur `branch_id=0`) est visible depuis **chaque** branche.
- **Fix** : drop `orWhereNull` OU restreindre à un flag `scope='system'` explicite. Corriger aussi `ActionLog::booted()` L27-36 : `is_null` au lieu de `!empty`.

### F-A4 · `BranchIsolationTest` trop permissif
- **Zone** : `tests/Feature/BranchIsolationTest.php:112, 126, 145, 161` — `assertStatus([403, 404, 422])`.
- **Impact** : Test passe même avec F-A1 actif (régression 403→422 invisible).
- **Fix** : Une fois F-A1 corrigé, passer à `assertStatus(403)` pur sur les gardes tenant explicites ; `assertStatus(404)` pour les bindings scopés ; retirer le `return;` early-out dans `test_chef_kds_does_not_see_other_branch_orders`.

### F-A5 · Dine-in gate serveur manquant + comparaison string/int
- **Zone** : `app/Http/Requests/PosOrderRequest.php:50-53` — `request('order_type') === OrderType::DINING_TABLE` (string HTTP vs int enum → toujours false).
- **Impact** : `dining_table_id` est **toujours nullable** ; le flag `pos_dine_in_enabled` n'est consulté **que côté UI**. Attaquant curl `order_type=15` crée dine-in sans table même flag OFF.
- **Fix** : `int` cast + check `Settings::group('pos')->get('dine_in_enabled') == 1` en gate serveur.

### F-B3 · Z report gap / double-count à la frontière
- **Zone** : `ZReportService::aggregate()` filtre `created_at >= opened_at` ; `XReportService::defaultFrom` utilise `lastClose.closed_at` avec `>=`.
- **Impact** : Gap `[closed_at_{N-1}, opened_at_N)` orphelin. Boundary order counté 2 fois entre X et Z.
- **Fix** : demi-intervalle `(prev.closed_at, now]` partout.

### F-B4 · Timezone drift casse les signatures Z historiques
- **Zone** : `ZReportService::sign()` L247 — `$closedAt->toIso8601String()` embarque l'offset.
- **Impact** : Changement `APP_TIMEZONE` post-close → tous les `verifySignature` historiques échouent.
- **Fix** : `->utc()->format('Y-m-d\TH:i:s\Z')` dans `canonical`.

### F-B5 · Z agrège les orders soft-deleted
- **Zone** : `ZReportService::aggregate()` utilise `withoutGlobalScopes()` qui strippe aussi `SoftDeletingScope`.
- **Impact** : Orders soft-deleted payées incluses dans le Z → fraude possible (delete puis soft-restore après Z).
- **Fix** : `withoutGlobalScope(BranchScope::class)` explicite.

### F-B6 · `total_by_tax_rate` toujours `[]`
- **Zone** : `ZReportService.php:171,196`.
- **Impact** : NF525 impose TVA ventilée par taux sur chaque Z → non-conformité de format même avec HMAC valide.
- **Fix** : calculer la ventilation via `PricingService::taxBreakdown()` par `order_id`.

### F-B7 · `audit_logs` wipable par `migrate:rollback` / `migrate:fresh` / `TRUNCATE`
- **Zone** : `2026_04_22_000002_create_audit_logs_table.php:62-69` (`down()` supprime les triggers puis la table).
- **Impact** : "Registre infalsifiable" NF525 cassé opérationnellement.
- **Fix** :
  1. `down()` throw si `APP_ENV=production` (guard).
  2. Artisan command `foodking:fiscal:verify-integrity` lancé en cron pour signaler les gaps.
  3. Documentation DBA : `REVOKE DROP, TRUNCATE, ALTER ON audit_logs FROM app_user`.

### F-A6 · POS-9.1.11 notifications bruit cashier
- **Zone** : `resources/js/components/admin/pos/PosComponent.vue:1033-1040`.
- **Impact** : Beep + toast sur **chaque** `OrderCreated`, y compris les ventes du caissier lui-même. Operators désactiveront.
- **Fix** : filter `payload.source ∈ {KIOSK, WEB, APP}` && `order.user_id !== authUser.id`. `AudioContext.resume()` sur `'suspended'`.

### F-A7 · Destroy children hard-delete vs parent soft-delete
- **Zone** : `OrderService.php:1622-1626`.
- **Impact** : Order restauré → lignes perdues → facture re-générée incomplète.
- **Fix** : `SoftDeletes` sur `orderItems` ou interdire `restore` de l'Order.

### F-B10 · `FiscalSequenceService::next()` sans `lockForUpdate`
- **Zone** : `FiscalSequenceService.php:77-81`.
- **Impact** : Cache::lock protège inter-process ; REPEATABLE READ sous queue-worker peut lire un MAX stale → trou de séquence.
- **Fix** : `->lockForUpdate()` dans la query MAX, à l'intérieur du transaction.

### F-B11 · Z reports pas DB-immutable
- **Zone** : migration z_reports.
- **Impact** : Une Z CLOSED peut être modifiée par SQL raw.
- **Fix** : BEFORE UPDATE trigger "si `status=closed` → SIGNAL". FK `ON DELETE RESTRICT` sur `opened_by`/`closed_by`/`branch_id`.

### F-C5 · AuditLog `branch_id=null` conflate chaînes
- **Zone** : `AuditLogService::lastHashFor(null)` L123-131 → query sans filter.
- **Impact** : CLI/cron sans branch pinnée poisonne la chaîne de n'importe quelle branche.
- **Fix** : `write()` throw si `$branchId <= 0`. Chaîne dédiée "system" avec `branch_id=0`.

### F-C6 · `ActionLog` coexiste avec `AuditLog` sans plan de migration
- **Zone** : 7 call-sites live (`OrderService`, `OrderController`, `KioskEventController`, `DashboardService::auditTrail`).
- **Impact** : Double audit mutable + immuable = ambiguïté forensique.
- **Fix** : Plan POS-9.5 / 9.6 : shim `ActionLog::create` → `AuditLogService::write` pour actions sensibles, ou deprecation @deprecated + sunset date.

### F-C7 · Pas de rate-limit / observabilité sur endpoints fiscaux
- **Zone** : `routes/api.php:784-794`.
- **Impact** : Open/close Z brute-force-able ; pas de canal log structuré.
- **Fix** : `throttle:10,1` dédié + `Log::channel('fiscal')` + Sentry breadcrumbs.

### F-C8 · `FiscalArchiveCommand` mémoire / timeout non-bornés
- **Zone** : `FiscalArchiveCommand.php:115-135` — `->get()->toArray()` sans `chunk/lazy`.
- **Impact** : 6 ans de données sur grosse branche = OOM.
- **Fix** : `lazy()` + stream ZipArchive.

---

## Tracker drift (à corriger immédiatement)

- POS-GA-F-04 → `resolved` → doit passer à **`partial (gated by BLOCKER_POS_9_4_5)`**.
- POS-GA-F-14 → `resolved` → doit passer à **`partial (destroy gate ok mais Z-seal manquant via BLOCKER_POS_9_4_10)`**.
- POS-GA-F-38 → `resolved` → doit passer à **`partial (sequence allocator ok mais wire-in gated by BLOCKER_POS_9_4_2b)`**.

## Scope drift (à ticketiser)

- **D-1** POS-9.4.9 PDF : `ZReportController::pdf()` retourne JSON. Plan exigeait PDF signé. → ticket POS-9.4.H.PDF.
- **D-2** POS-9.4.11 archive : zip plain. Plan exigeait `zip chiffré (gpg)`. → ticket POS-9.4.H.CRYPT.

## Compliance gaps NF525 / RGPD (arbitrage produit requis)

- **G-1** Certificat digital vs HMAC (NF525 tolère HMAC dans logiciel certifié AFNOR — décision produit).
- **G-3** Secrets stockés en `.env` seul (pas de vault). Pour V2 : `storage/keys/` chiffré ou KMS.
- **G-4** RGPD vs 6 ans : payload audit_logs non-scrubbed → PII immuable 6 ans. Besoin d'une allow-list + procédure pseudonymisation.
- **G-5** Rétention archive : pas de réplication off-site, pas de cron purge, pas de HMAC sur le zip.
- **G-6** Tests concurrence serialisés (cache array) → faux-confiance.
- **G-7** `migrate:rollback` + TRUNCATE cassent l'immuabilité.

---

## Conclusion stratégique

**POS-9.4 est infrastructure-only.** La claim "NF525 compliant" est prématurée. 2 P0 de sécurité résiduels dans POS-9.1 mergé (F-A1, F-A2), 2 P0 fiscaux dans POS-9.4 (F-C1 dev-secret, F-C3 race + F-C2/C4 services non-wirés).

**Avant POS-9.2, exécuter vague H de hardening** (voir `PLAN_PHASE_POS_9_HARDENING_2026-04-18.md`) :
- H.1 : fermer les 5 P0 sur zone disponible (OrderService mergé sur main, donc modifiable).
- H.2 : correctness fiscale (secrets, race, boundaries, NF525 format).
- H.3 : opérationnel (observabilité, rate-limit, archive, docs).

Ensuite seulement démarrer POS-9.2 (state-machine), puis lever BLOCKERs 9.4.2b/5/10.

---

*Auteur : assistant session (Track B / audit transverse post POS-9.4).*
*Date : 2026-04-18.*
*Sources : 4 subagents readonly (AUDIT_A, AUDIT_B, AUDIT_C, AUDIT_D).*
