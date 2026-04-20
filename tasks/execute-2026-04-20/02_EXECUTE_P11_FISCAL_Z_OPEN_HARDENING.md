# EXECUTE — P11_FISCAL_Z_OPEN_HARDENING — 2026-04-20

## Status
**STATUS:** `PENDING_HUMAN_GATE`
**GATE_ARTIFACT:** `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` §5 (Cycle C3)
**VAGUE:** V1 (Critique — NF525 fiscal + pré-requis cohérence sealed-Z pour C1/C2)
**BLOCKING:** Gate signé requis. `human-gates.mdc:19,23,24` (schema + frozen + NF525 invariant).
**Dépendance aval:** cycles 01 (RETURNED_IDEMPOTENCY) et 04 (RETURNED_KDS_BYPASS_LOCKDOWN) sont symbiotiques — partagent la garde sealed-Z centralisée.

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 30
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-08-01, 08-02, 03-03, 09-*
- `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `GPT-5.4` (AGENTS.md:15 — fiscal NF525 + schéma + frozen zone)
- **SUBAGENT:** `foodking-complex-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/Fiscal/ZReportService.php` — `open()`, `close()`, nouveau statut `CLOSING`
- `app/Services/OrderService.php` — `changeStatus()` L1499-1567 + `changePaymentStatus()` L1592-1646 : **garde sealed-Z** (reject mutation si Z de la branche scellé et ordre antérieur à Z.open)
- `app/Enums/ZReportStatus.php` (à vérifier présence, sinon créer) — ajout `CLOSING`
- `database/migrations/2026_04_20_*_add_closing_status_to_z_reports.php` (schéma — déclenche gate)

### SCOPE_FILES (whitelist stricte)
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/OrderService.php` (méthodes changeStatus, changePaymentStatus uniquement — pas de touche aux autres)
- `app/Enums/ZReportStatus.php`
- `database/migrations/<new>_add_closing_status_to_z_reports.php`
- `tests/Feature/Fiscal/ZReportSealedGuardTest.php` (nouveau)
- `tests/Feature/Fiscal/ZReportVerifyChainTest.php` (nouveau)
- `tests/Feature/Fiscal/ZReportClosingAtomicTest.php` (nouveau)

### SUBSYSTEMS_OFF_LIMITS
- `app/Services/FrontendOrderService.php` (pas de mutation fiscale directe côté front)
- `app/Services/PaymentService.php` (traité par C4)
- `app/Services/LoyaltyService.php`, `app/Services/AuditLogService.php` (lecture seule OK)
- Routes, controllers, requests (aucune modif)

## Invariants at Risk
- **NF525 sealed-Z chain** — `ZReport::open()` DOIT valider le HMAC de la clôture précédente avant d'ouvrir une nouvelle Z ; drift chain = corruption preuve fiscale
- **OrderStatus SSOT** — `RETURNED` post-sealed-Z DOIT rejeter HTTP 423 Locked (pas 400/422) pour différencier "erreur métier" vs "commande gelée par fiscal"
- **Branch isolation** — garde sealed-Z scope strict `branch_id` (pas de fuite cross-branche)
- **Atomicité CLOSING** — transition `OPEN → CLOSING → CLOSED` doit être lock pessimiste (`lockForUpdate`) pour éviter double-close concurrent

## Dependencies
- Gate signé (bloquant)
- **Aucun cycle préalable applicatif** — C3 est autonome mais ses guards sont exploitées par C1/C2/C4
- Migration schéma = déclencheur hard gate additionnel `human-gates.mdc:19`

## Plan bref

1. **Lire** `ZReportService::open()`/`close()`, enum `ZReportStatus`, tests fiscaux existants (`tests/Feature/Fiscal/*`).
2. **Garde sealed-Z** dans `OrderService::changeStatus` et `changePaymentStatus` :
   ```
   $currentZ = ZReport::where('branch_id', $order->branch_id)->where('status', ZReportStatus::CLOSED)->latest()->first();
   if ($currentZ && $order->created_at <= $currentZ->closed_at) {
       abort(423, 'Order is locked by sealed Z-report. Late mutation requires fiscal re-open procedure.');
   }
   ```
3. **Statut CLOSING** :
   - Migration : ajouter valeur enum colonne `z_reports.status` (mysql enum ALTER)
   - `ZReportService::close()` : `OPEN → CLOSING` (lockForUpdate) → finaliser HMAC → `CLOSING → CLOSED`
4. **Verify-chain Z.open** :
   - `ZReportService::open()` : avant création nouvelle Z, charger dernière `CLOSED` sur branche, appeler `verifyChain($previousZ)`, abort 500 si chaîne cassée
5. Tests Feature :
   - `ZReportSealedGuardTest::test_changeStatus_returned_after_sealed_z_returns_423`
   - `ZReportSealedGuardTest::test_changePaymentStatus_after_sealed_z_returns_423`
   - `ZReportSealedGuardTest::test_cross_branch_sealed_z_does_not_block`
   - `ZReportClosingAtomicTest::test_double_close_concurrent_no_race`
   - `ZReportVerifyChainTest::test_open_fails_if_previous_hmac_tampered`
6. Écrire `reports/execution/RUN_P11_FISCAL_Z_OPEN_HARDENING_2026-04-20.md`.

## Acceptance Tests
- [ ] 5/5 tests Feature verts (sealed guard ×3, atomic close ×1, verify chain ×1)
- [ ] Migration réversible (`migrate:rollback --step=1` propre)
- [ ] `git diff` ≤ ~150 lignes, strictement dans SCOPE_FILES
- [ ] Pas de régression `tests/Feature/Fiscal/*` existants (24+ tests à faire passer)

## Exit Criteria
- [ ] Garde sealed-Z active sur **les 2 mutations** (changeStatus ET changePaymentStatus)
- [ ] Réponse HTTP **423 Locked** (pas 400/422/500) avec message fiscal explicite
- [ ] Verify-chain en `open()` détecte HMAC tampering (test preuve)
- [ ] État CLOSING visible brièvement en DB pendant close (test temporel OK)
- [ ] `reports/execution/RUN_*.md` avec Final report gabarit

## Scope Pressure Protocol
Migration schéma = scope déjà déclaré GATE (via brief §5). Toute autre migration requise → STOP + ESCALATION.

## Remediation
- Cible principale bugs probables : race close atomic, HMAC verify strict-trop, enum ALTER MySQL
- Attempt 1-2 → diagnostic + replan. Attempt 3 = HUMAN_GATE bug irrésolu.

## Deliverables
- Diff applicatif ciblé
- 1 migration schéma réversible
- 5 tests Feature + tests existants verts
- `reports/execution/RUN_P11_FISCAL_Z_OPEN_HARDENING_2026-04-20.md`

## Communication
Subagent renvoie résumé court + file:line + commandes phpunit executées et leur sortie.
