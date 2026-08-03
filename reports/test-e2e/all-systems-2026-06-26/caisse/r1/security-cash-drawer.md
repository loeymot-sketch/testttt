# CAISSE r1 — Lentille SÉCURITÉ/RBAC — Tiroir-caisse / cash / Z

Rôle: lentille sécurité/RBAC sur le sous-système CAISSE « Tiroir-caisse / cash / Z »
(Sub 1.c). Audit READ-ONLY. DB live `foodking_e2e`. Serveur :8766.

## VERDICT: 0 P0 / 0 P1 — défenses SOLIDES, vecteurs d'abuse tenus.

Tous les vecteurs d'abuse demandés ont été testés et TIENNENT. Aucun finding
P0/P1/P2 reproductible. 1 observation P3 (forensique, correctement scopée V1-local).

---

## VECTEURS D'ABUSE TESTÉS — tous HOLD (preuves)

### V1 — Double-ouverture session / 2 sessions par user (I1) — HOLD
`CashDrawerService::openSession` (app/Services/Cash/CashDrawerService.php:52-118)
défense-en-profondeur 3 couches: `Cache::lock(cash_drawer_open_b{branch}_u{user})`
(:78) + `DB::transaction`+`lockForUpdate` sur la probe (:80-85) + UNIQUE partiel
`(branch_id, opened_by_user_id) WHERE status='open'` (migration 2026_05_10_020000,
cité :69-71). Probe existante → 409 (:88).
- evidence DB: `SELECT branch_id, opened_by_user_id, COUNT(*) FROM cash_drawer_sessions
  WHERE status='open' GROUP BY ... HAVING COUNT(*)>1` → **0 ligne** (aucune
  double-ouverture en prod ; 9 sessions open mais toutes user distincts).
- evidence test: `CashDrawerConcurrentSessionTest` PASS (16/16 avec ownership+variance).

### V2 — Ownership session: caissier B ferme/reconcilie le tiroir de A — HOLD
`CashDrawerSessionController::assertSessionVisibleToUser` (:317-338) appelé AVANT
close (:134) et reconcile (:164). Gate same-branch: `isOwner` OU
`cash.reconcile.variance.override` / role Admin / Branch Manager (:333-337). Admin
global branch_id=0 voit tout (:326). Cross-branch → 403 (:330).
- evidence DB: `cash.reconcile.variance.override` accordée à **Admin + Branch Manager
  uniquement** (PAS POS Operator). Session 30 (variance -135,90) reconciliée par
  user 1 = Admin (override ✓) ; session 22 par user 11 = Branch Manager (✓).
- evidence test: `CashDrawerSessionOwnershipTest` PASS.

### V3 — Reconcile écart masqué / closing_amount forgé — HOLD (gate variance)
`reconcileSession` (:225-354): expected = opening + Σ(movements signés) (:262),
variance = closing − expected (:263). Si `|variance| > threshold(2,00€)` ET
`variance_manager_approval_required=true` (défaut) → exige `variance_reason`
non-vide (:277) ET permission override (:298) sinon `CashVarianceRequiresApproval
Exception` 422. Le closing est déclaré par l'opérateur, mais tout écart > seuil
est BLOQUÉ pour un POS Operator (pas d'override) → ne peut pas figer un RECONCILED
masquant un écart.
- evidence DB: `SELECT id,variance,variance_reason FROM cash_drawer_sessions WHERE
  status='reconciled' AND ABS(variance)>2.00` → **toutes les lignes over-threshold
  ont variance_reason NON-NULL** (id 30/-135,90, 6/+10, 3/-5, 2/+3 — toutes avec
  raison + reconciled_by user 1 Admin).
- evidence test: `CashVarianceGateTest` PASS.
- NOTE limitation inhérente (non-bug): un opérateur peut sous-déclarer le closing
  pour qu'il matche l'expected (variance=0 → aucun gate). C'est intrinsèque à tout
  comptage cash physique (l'opérateur déclare ce qu'il compte). Le manager-gate
  optionnel `CASH_MANAGER_GATE_ROUTINE_CLOSE=true` (:151-160, défaut false pour
  V1 mono-caissier) couvre ce cas en SaaS multi-caissier.

### V4 — simulation_hardware=true en prod doit REFUSER le boot — HOLD
`config/pos.php:37` `simulation_hardware` = env bool. Boot-guard
`AppServiceProvider.php:165-178`: si `app()->environment('production')` ET
`config('pos.simulation_hardware')` true → `throw RuntimeException` (refuse boot).
- evidence test: `PosSimulationHardware4ScenariosTest` PASS (4 scénarios).
- portée: le flag bypasse UNIQUEMENT la précondition session-ouverte pour ventes
  CASH (pas pricing/fiscal/snapshot/audit/isolation — documenté :11-16).

### V5 — Fermer Z avec commande impayée / PENDING_COUNTER en file — HOLD
`ZReportService::aggregate` (:337-341) exclut `payment_status = UNPAID` et
`whereNotNull('fiscal_sequence_no')`, plus exclut terminaux CANCELED/REJECTED/
RETURNED (:349-357). Une commande impayée/PENDING_COUNTER n'a pas de
fiscal_sequence_no (alloué seulement au PAID comptoir) → JAMAIS agrégée dans un Z
signé. Fermer le Z ne fiscalise pas d'impayé.
- evidence DB: `SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL AND
  payment_status='paid' AND fiscal_sequence_no IS NULL` → **0** (aucune commande
  payée n'échappe à l'agrégation Z — pas de FISCAL-CPS-01 résiduel ici).

### V6 — Token kiosk:order → routes admin cash-drawer (escalade) — HOLD
Routes `cash-drawer/sessions/*` (routes/api.php:928-942) imbriquées dans
`prefix('pos')` (:792) ⊂ `prefix('admin')` (:295) middleware
`['auth:sanctum','block_kiosk_token_admin',...]`. `BlockKioskTokenFromAdminRoutes`
(app/Http/Middleware/BlockKioskTokenFromAdminRoutes.php:57-128, registered
Kernel.php:146) refuse 403 tout token portant `kiosk:order` sans wildcard `*`.
Controllers ajoutent `permission:pos` (CashDrawerController:22,
CashDrawerSessionController:31).
- evidence live: `curl GET /api/admin/pos/cash-drawer/sessions/current` (no auth)
  → **HTTP 401** ; `curl POST .../30/reconcile` (no auth) → **HTTP 401**.

### V7 — Traçabilité movements / mouvement sur session fermée — HOLD
`recordMovement` (:365-470): valide type whitelist (:382) + direction in/out
(:391) + amount≥0 (:400) ; `DB::transaction`+`lockForUpdate` (:422-426) bloque un
close concurrent entre le check status et l'INSERT (:437-444) — empêche d'écrire
un mouvement sur une session CLOSED (corruption agrégat Z). Audit_logs HMAC écrit
DANS la même transaction (:459-466). Triggers DB `cash_movements_no_delete` /
`cash_drawer_sessions_no_delete` (migration 2026_05_10_010000, cité :536-538).
- evidence test: `CashMovementsDeleteForbiddenTest`, `CashAuditLogChainTest`,
  `CashDrawerActorColumnsTest`, `PosCashTrailTest` PASS (31/31).

---

## P3 — config/pos.php:37 — simulation_hardware bypasse l'ancrage cash-trail (dev only)
- repro: `POS_SIMULATION_HARDWARE=true` (dev V1-local) → ventes CASH passent sans
  session tiroir ouverte → pas de `CashMovement TYPE_ORDER_PAYMENT` ancré ; le
  flag `flagCashMovementSkipped` (PaymentService.php:519) surface le trou au
  caissier (TRAP-3) mais aucun mouvement n'est créé.
- evidence: PaymentService.php:507-548 (recordCashOrderMovement non-strict log+
  flag, ne bloque pas l'order) ; CashDrawerController.php:59-64 (drawer-pop sans
  session = warning forensic). Documenté/intentionnel: fiscal_sequence + audit
  chain restent SSOT NF525 (pas cash_movements).
- lentille: commerçant (forensique cash).
- reco: AUCUNE pour V1 — comportement correct et scopé. Le boot-guard prod
  (AppServiceProvider:172) refuse `true` en production ; en dev mono-poste sans
  tiroir physique c'est le comportement attendu. Noté pour complétude.
- frozen_touch: non (observation, pas de fix).

---

## RÉSUMÉ TESTS (DB-safe, base test phpunit séparée)
- CashDrawerSessionOwnershipTest + CashVarianceGateTest + CashDrawerConcurrentSessionTest: 16/16 OK
- PosSimulationHardware4ScenariosTest + CashDrawerEndpointsTest + CashMovementsDeleteForbiddenTest: 30/30 OK
- PosCashTrailTest + CashDrawerActorColumnsTest + CashAuditLogChainTest + CashDrawerServiceTest: 31/31 OK
- DB live: 0 double-open, 0 variance>seuil sans raison, 0 paid-sans-fiscal, 401 sur endpoints non-auth.

Frozen touché: ZReportService (audité read-only, NON modifié) ; toutes les
défenses cash résident en NON-frozen (CashDrawerService, CashDrawerSessionController,
BlockKioskTokenFromAdminRoutes).
