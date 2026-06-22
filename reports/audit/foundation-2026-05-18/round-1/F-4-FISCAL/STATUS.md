# F-4 FISCAL (NF525) — Foundation Audit STATUS

**Zone**: F-4 — Fiscal NF525 (Loi de Finance France — prison-risk subsystem)
**Round**: 1
**Date**: 2026-05-18
**Mode**: READ-ONLY (all 3 core services in CLAUDE.md §7 frozen list)
**Audit team**: Architect + DBA + Security + RED-team (4 specialists, parallel read-only)
**Baseline**: count=56, last_hash=f9283cecb383522fc0ba3f70d44618c2f95fce472a39ff2d0cf986439c12a279, CHAIN OK

---

## 1. Overall verdict

**GREEN at code level / AMBER at operational level.**

The fiscal subsystem is mature and correctly implemented. Defense-in-depth is consistently applied across crypto, schema, application logic, and operator tooling. No P0 code findings, no P1 code findings. All 5 NF525 invariants verified intact:

1. `audit_logs.count` strictly monotonic — protected by UPDATE+DELETE triggers (MySQL+SQLite), UNIQUE(branch_id, prev_hash) blocks forks, Eloquent layer rejection, retention-aware migration rollback guard. *Confirmed.*
2. `last_hash` recalculable from chain — `AuditLogService::verifyChain` + `FiscalChainValidator::verifyAuditChainTail` both re-walk and `hash_equals`-compare. *Confirmed.*
3. `composition_snapshot` 5 builder INSERT sites + 1 copy-forward refund — all 6 sites enumerated: `OrderService.php:466/821/1277`, `FrontendOrderService.php:441`, `PricingService.php:291`, `RefundWithCounterEntryService.php:136`. *Confirmed.*
4. `fiscal_sequence_no` strictly monotonic per branch, gap-free — triple defense: `Cache::lock + DB lockForUpdate + UNIQUE(branch_id, fiscal_sequence_no)`. Failed allocations recovered via `RetryFiscalAllocCommand` (everyMinute cron). *Confirmed.*
5. DELETE/UPDATE triggers active — 5 trigger pairs deployed: `audit_logs` (UPDATE+DELETE both drivers), `z_reports` (DELETE MySQL), `cash_movements` / `cash_drawer_sessions` / `order_payments` (DELETE MySQL). *Confirmed.*

The frozen-zone discipline is correct: extension classes (`FiscalChainValidator`, `FiscalSealingService`) and the operator CLI (`FiscalVerifyChainCommand`) wrap the frozen services without modifying them. Recent Wave 3 / Wave 2c-2 / Wave 2d / Wave 3b heals all landed in non-frozen surfaces.

**However**, there is ONE operational risk that requires immediate verification BEFORE shipping V1: see Section 3 ESCALATE.

---

## 2. Four-list summary

### KEEP-AS-IS (frozen, working, no change recommended)

| Item | Why |
|---|---|
| `FiscalSequenceService` triple-defense allocation (Cache::lock + lockForUpdate + UNIQUE) | Production-grade. Zero P0/P1. |
| `AuditLogService` HMAC chain with sortRecursive + JSON_UNESCAPED_SLASHES canonical | Cryptographically correct, timing-safe verification via `hash_equals`. |
| `ZReportService` half-open window `(from, to]` aggregate | Correct boundary semantics — no double count, no skip. |
| `FiscalSealingService` HMAC isolation + secret assertion duplication | Defense in depth at 3 sign-paths is intentional. |
| `audit_logs` schema + immutability triggers + UNIQUE chain index | All 4 P0-FIX hardening migrations landed. |
| `z_reports` schema + DELETE trigger | UPDATE intentionally allowed for state machine — correct. |
| `composition_snapshot` JSON column on order_items | Receipt SSOT pattern is right; immutability enforced at application layer. |
| `FiscalVerifyChainCommand` --branch / --all / exit-code semantics | Operator UX is excellent post Wave 3 heals. |
| `Kernel::activeBranchIds()` canonical helper | Aligns archive + monitor crons on one source of truth (whereIn 1, 5). |
| Production-secret assertions (`assertProductionSafe` × 3) | Dev sentinel rejection + 32-char minimum + per-branch override. Correctly loud-fail. |

### FIX (P0/P1 — must address)

| ID | Source | Severity | Action |
|---|---|---|---|
| **ESCALATE-1** | SEC-RED-1 + DBA-OBS-1 + RED-1 | **P0-AUDIT (operational)** | **Verify `docs/DEPLOY.md` and/or `docs/FISCAL_SECRETS.md` contain explicit `REVOKE TRUNCATE, DROP, TRIGGER ON audit_logs, z_reports, cash_movements, order_payments, cash_drawer_sessions FROM '<prod_app_user>'@'%'` runbook AND that production-applied GRANTs match. Without this, immutability triggers are bypassable in 1 statement.** See Section 3. |

No P0 or P1 code findings. All P0-class items are operational verification only.

### REFACTOR (V1.1 backlog — structural improvements, not blockers)

| ID | Source | Priority | Label |
|---|---|---|---|
| DBA-REC-V11-1 | DBA | P1 | Add `composition_snapshot` UPDATE rejection trigger on PAID rows (defense in depth). Echoes RED-6. |
| DBA-REC-V11-2 | DBA | P1 | Lock signed aggregate columns on closed Z reports via trigger — cash enrichment must not touch signed fields. |
| RED-REC-V11-2 | RED | P1 | Block `orders` hard DELETE when `fiscal_sequence_no IS NOT NULL` (defense in depth on top of FK restrictOnDelete). |
| RED-REC-V11-4 | RED | P1 | **Force strict=true in `ZReportService::close`'s `verifyChain` call regardless of env config** — closes the FISCAL_VERIFY_CHAIN_STRICT=false bypass at Z close (Z open is already safe via FiscalChainValidator). *Frozen file modification — requires LOCK doc + owner gate.* |
| SEC-REC-V11-2 | Security | P1 | Daily monitor asserting `config('fiscal.kiosk_auto_allocate_sequence')=true` in production. |
| ARCH-REC-V11-2 | Architect | P2 | Validate `composition_snapshot` JSON schema before INSERT in PricingService. |
| RED-REC-V11-5 | RED | P2 | SIEM CRITICAL alert on `event=fiscal.chain.verification_failed` (today: logs only, escalation depends on monitor wiring). |
| RED-REC-V11-6 | RED | P2 | Quarterly `FISCAL_AUDIT_SECRET` rotation runbook. |
| SEC-REC-V11-3 | Security | P2 | Replace `FISCAL_GENESIS_PREV_HASH` env coupling with a DB-stored branch-genesis audit row. |
| ARCH-REC-V11-3 | Architect | P3 | Extract `assertProductionSafe` duplication across 3 frozen services into a single validator class (requires LOCK). |
| DBA-REC-V11-4 | DBA | P3 | Per-row HMAC on z_reports cash-enrichment columns (post-close mutable columns are not signed today). |
| ARCH-REC-V11-1 | Architect | P3 (doc) | Document multi-region cache-lock constraint (V1 single-region only). |

### REMOVE / DEPRECATE
None. The fiscal subsystem has no dead code, no superseded paths. Every line earns its keep.

---

## 3. ESCALATE URGENT — P0 AUDIT (operator verification, not code)

### Issue
TRUNCATE bypasses MySQL `BEFORE DELETE` triggers. The fiscal subsystem documents this risk in 3 migrations (`2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:30-34`, `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:48-50`, CLAUDE.md §8), all of which defer mitigation to "deploy doc — GRANT level".

The READ-ONLY scope of this audit cannot confirm the deploy doc actually contains the runbook nor that production GRANTs are configured correctly. Without explicit `REVOKE TRUNCATE` (and ideally `DROP, TRIGGER, ALTER`) on the production app runtime user, a single SQL statement can erase the entire fiscal trail — a direct NF525 prison-risk violation.

### Prison-risk assessment
If a malicious or compromised app-user runs `TRUNCATE TABLE audit_logs;` against the production DB:
- All immutability triggers fire on `BEFORE DELETE` semantics — they do NOT fire on TRUNCATE.
- `count=0`, `last_hash=null`, verifyChain returns CHAIN OK on empty table.
- Daily archive shipping receives an empty bundle.
- Detection from inside the fiscal pipeline = **impossible**. Only cross-reference with payment-processor / kiosk-app records would surface the loss.
- NF525 mandates 6-year retention with provable integrity. A wipe → criminal liability for the operating company.

### Required action BEFORE next production deploy
1. **Open** `docs/DEPLOY.md` and `docs/FISCAL_SECRETS.md`.
2. **Confirm** at least the following runbook is present:
   ```sql
   -- on production MySQL/MariaDB, as a DBA user:
   REVOKE DROP, TRUNCATE, ALTER, TRIGGER, CREATE
     ON foodking.audit_logs FROM 'foodking_app'@'%';
   REVOKE DROP, TRUNCATE, ALTER, TRIGGER, CREATE
     ON foodking.z_reports FROM 'foodking_app'@'%';
   REVOKE DROP, TRUNCATE, ALTER, TRIGGER, CREATE
     ON foodking.cash_movements FROM 'foodking_app'@'%';
   REVOKE DROP, TRUNCATE, ALTER, TRIGGER, CREATE
     ON foodking.cash_drawer_sessions FROM 'foodking_app'@'%';
   REVOKE DROP, TRUNCATE, ALTER, TRIGGER, CREATE
     ON foodking.order_payments FROM 'foodking_app'@'%';
   ```
3. **Confirm** the migration runner uses a SEPARATE user (`foodking_migrate`) with DDL privileges, and the app runtime uses `foodking_app` with the revoked privileges above.
4. **Verify on production** by running `SHOW GRANTS FOR 'foodking_app'@'%';` and confirming TRUNCATE is absent.
5. **If runbook is missing**: write it (V1.1 sprint 0 item), DO NOT ship V1 to production until verified.

### Severity
- Code: GREEN (the codebase did its part — triggers + chain HMAC + retention guards).
- Operations: **CRITICAL UNVERIFIED**.

This is the single item that determines whether V1 is production-shippable for NF525 compliance.

---

## 4. Specialists summary

| Specialist | Verdict | P0 code | P1 code | V1.1 items |
|---|---|---|---|---|
| Architect | GREEN | 0 | 0 | 3 (1 P2, 2 P3) |
| DBA | GREEN | 0 | 0 (2 P1 are defense-in-depth recos) | 4 |
| Security | GREEN | 0 | 0 | 3 (1 P0-AUDIT, 1 P1, 1 P2) |
| RED-team | AMBER | 0 | 0 (3 P1 V1.1 + 1 P0-AUDIT) | 6 |

**Combined P0 code findings**: 0
**Combined P1 code findings**: 0
**Combined P0-AUDIT (operational)**: 1 — TRUNCATE GRANT verification (ESCALATE)
**Combined V1.1 backlog**: 12 items

---

## 5. Recommended next steps

### Before V1 prod ship
1. ESCALATE-1: verify TRUNCATE GRANT runbook + production state. Block ship if not confirmed.

### V1.1 sprint 0 (defense-in-depth)
1. DBA-REC-V11-1 — composition_snapshot UPDATE trigger (P1, small migration).
2. DBA-REC-V11-2 — z_reports signed-column lock trigger (P1, small migration).
3. RED-REC-V11-2 — orders DELETE block when fiscal_sequence_no NOT NULL (P1, small migration).
4. SEC-REC-V11-2 — kiosk_auto_allocate_sequence guard alert (P1, 1 cron entry).
5. RED-REC-V11-5 — SIEM CRITICAL alert wiring (P2, monitor config).

### V1.1 sprint 1 (frozen-file LOCK)
1. RED-REC-V11-4 — force strict=true in ZReportService::close verifyChain. *Frozen file change. Requires LOCK doc + owner gate + cross-validation that NF525 chain remains bit-identical.*

### V1.2+
1. Quarterly secret rotation procedure (RED-REC-V11-6).
2. Branch-genesis row in DB (SEC-REC-V11-3).
3. Multi-region distributed lock service (ARCH-REC-V11-1).

---

## 6. Files cited (absolute paths)

### Read for this audit
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalSequenceService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/AuditLogService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/ZReportService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalChainValidator.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalSealingService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/FiscalVerifyChainCommand.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php` (fiscal lanes + `activeBranchIds`)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/fiscal.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/AuditLog.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/OrderItem.php` (composition_snapshot)
- 8 fiscal migrations under `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/`

### Specialist deliverables (this round)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/foundation-2026-05-18/round-1/F-4-FISCAL/architect.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/foundation-2026-05-18/round-1/F-4-FISCAL/dba.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/foundation-2026-05-18/round-1/F-4-FISCAL/security.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/foundation-2026-05-18/round-1/F-4-FISCAL/red-team.json`

---

## 7. User-facing questions

Two questions the owner should answer before this audit closes:

**Q1 (P0-AUDIT, blocking V1 prod ship):**
Le runbook "REVOKE TRUNCATE/DROP/TRIGGER/ALTER on audit_logs + z_reports + cash_movements + cash_drawer_sessions + order_payments from the production app user" est-il bien documenté dans `docs/DEPLOY.md` ou `docs/FISCAL_SECRETS.md`, et appliqué sur la prod actuelle ? Si non, on doit l'écrire et l'appliquer avant tout deploy V1 — sinon les triggers d'immutabilité sont contournables en 1 statement TRUNCATE.

**Q2 (V1.1 scope, non-blocking):**
On a 5 items P1 V1.1 "defense in depth" qui ne touchent pas aux fichiers frozen (4 nouvelles migrations + 1 cron), plus 1 item P1 qui touche `ZReportService.php` (frozen, requiert LOCK doc). Tu veux:
- (a) Tous les 5 non-frozen items dans V1.1 sprint 0, l'item frozen en sprint 1 ?
- (b) Seulement les 5 non-frozen items en V1.1, frozen item reporté V1.2 ?
- (c) Autre arbitrage ?

---

*End STATUS.md F-4 FISCAL Round 1.*
