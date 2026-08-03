# WF-5 — Fiscal Cascade NF525 Sync Confirmation

**Wave:** WF-5 (Wave F — Sync Confirmation)
**Date:** 2026-05-19
**Branch:** `v1-0-1-hardening-2026-05-17`
**Mode:** READ-ONLY (frozen-zone discipline absolute)
**Verdict:** **INTACT — 5/5 NF525 invariants verified**
**Frozen-zone diff:** 0 lines

---

## NF525 Baseline Attestation

| | START | END | Delta |
|---|---|---|---|
| `audit_logs` count | 97 | 97 | 0 (bit-identical) |
| `audit_logs` last_hash | `af02d7895d412654...` | `af02d7895d412654...` | unchanged |
| `z_reports` count | 4 | 4 | 0 (bit-identical) |
| `z_reports` last_signature | `2c7ef3d479bf334a...` | `2c7ef3d479bf334a...` | unchanged |
| `fiscal:verify-chain` exit | 0 (CHAIN OK) | 0 (CHAIN OK) | unchanged |

Full attestation: `baseline-start-end.txt`.

---

## Sync Cascade Verified

Per the WF-5 task specification, the 8-step fiscal cascade was traced end-to-end:

1. **PaymentService::process -> fiscal_sequence_no allocation** (Cache::lock 5s + DB FOR UPDATE)
   - POS at-creation path: `OrderService.php:938` (inside `saveOrderWithQueueNumber` savepoint)
   - Kiosk auto-allocate path: `FrontendOrderService.php:1130-1153` (inside `finalizePaidKioskOrder` lockForUpdate)
2. **Dual allocation paths differ by lifecycle**: POS allocates pre-persist (rollback releases seq via MAX+1 next call); kiosk allocates post-payment (failure writes `fiscal_alloc_error_at` marker)
3. **composition_snapshot frozen at creation** — 5 INSERT sites confirmed:
   - `app/Services/Pricing/PricingService.php:291`
   - `app/Services/OrderService.php:466, 826, 1282`
   - `app/Services/FrontendOrderService.php:441`
   - + 1 copy-forward: `app/Services/Order/RefundWithCounterEntryService.php:136`
4. **AuditLogService::write extends HMAC chain** — `audit_chain_b{N}` cache lock + DB transaction + UNIQUE(branch_id, prev_hash) + exactly-one retry on collision
5. **BEFORE DELETE/UPDATE triggers active on**:
   - `audit_logs` (MySQL SIGNAL '45000' + SQLite RAISE ABORT; both UPDATE and DELETE)
   - `z_reports` (MySQL DELETE only — UPDATE allowed for state machine)
   - `cash_movements`, `cash_drawer_sessions`, `order_payments` (MySQL DELETE)
6. **RefundWithCounterEntryService copy-forward** extends chain via `audit.write('order.refund.counter_entry')` inside same DB::transaction as mirror order INSERT
7. **ZReportService::close daily seal** — HMAC chain entry signed via `FiscalSealingService::signZReport` (closed_at canonicalised in UTC for tz-stability)
8. **fiscal:verify-chain validates entire chain integrity** — dual-chain (audit_logs + z_reports) sweep, exit codes 0/1/2/3, daily cron at 03:30 via `self::activeBranchIds()`

---

## Five NF525 Invariants (cross-validated by 4 specialists)

| # | Invariant | Architect | Security | DBA | RED | Cross-Verdict |
|---|---|---|---|---|---|---|
| **I-1** | Sequence monotonic + gap-free per branch | INTACT | INTACT | INTACT | BLOCKED (A1+A2) | **INTACT** |
| **I-2** | HMAC chain extension atomic | INTACT | INTACT | INTACT | BLOCKED (A3) | **INTACT** |
| **I-3** | composition_snapshot frozen at creation | INTACT | INTACT | INTACT | BLOCKED (A8) | **INTACT** |
| **I-4** | DB-level UPDATE/DELETE triggers active | INTACT | INTACT | INTACT | BLOCKED (A5+A6) | **INTACT** |
| **I-5** | TRUNCATE bypass mitigation (deploy doc) | INTACT (caveat) | INTACT | INTACT (caveat) | MITIGATED (A7) | **INTACT** |

All 5 invariants confirmed by independent reasoning across 4 specialist lenses. No invariant rated DEGRADED, AT-RISK, or BREACHED.

---

## Specialist Deliverables

| Specialist | File | Lens | Findings |
|---|---|---|---|
| Architect | `specialist-architect.json` | Service composition, dependency discipline | 6 INFO findings, all KEEP-AS-IS |
| Security | `specialist-security.json` | Cryptography, secret hygiene, authz | 7 INFO findings, all KEEP-AS-IS |
| DBA | `specialist-dba.json` | Schema, indexes, triggers, retention | 6 INFO findings, all KEEP-AS-IS |
| RED | `specialist-red.json` | Adversarial — 16 attack vectors | 0 successful kill-chains, all BLOCKED or MITIGATED at correct layer |

---

## 4-List Classification

### KEEP-AS-IS (production-validated, no recommended change)

Cross-specialist consensus on the following hardened mechanisms:

- **FiscalSequenceService triple-defense** (Cache::lock + Order::lockForUpdate + UNIQUE `orders_branch_fiscal_seq_unique`)
- **AuditLogService HMAC chain** (HMAC-SHA256 + canonical JSON sort + UNIQUE(branch_id, prev_hash) + exactly-one retry-on-collision)
- **FiscalChainValidator dual-chain orchestrator** (Z chain full re-walk + audit_logs bounded tail to avoid deadlock under Z lock)
- **ZReportService half-open aggregate** ((from, to] interval prevents boundary double-count) + `whereNotNull('fiscal_sequence_no')` excludes orphans
- **RefundWithCounterEntryService mirror-order pattern** (NEGATED total/subtotal/tax + items + payments + parent_order_id FK self-ref + audit trail)
- **SealedOrderGuard single-predicate SSOT** (shared across destroy / changeStatus / aggregate / refund — no semantic drift)
- **RetryFiscalAllocCommand orphan recovery** (every minute, `withoutOverlapping(5)`, `onOneServer`)
- **FiscalVerifyChainCommand exit-code discipline** (0/1/2/3 distinct for monitoring routing)
- **DB triggers**: dual-driver parity for audit_logs (MySQL+SQLite), MySQL-only for z_reports + cash tables (production targets MySQL exclusively per `config/database.php`)
- **FK cascadeOnDelete -> restrictOnDelete** for cash_movements + order_payments (FK-layer fail-fast before trigger)
- **audit_logs migration down() blocked in APP_ENV=production** (NF525 6y retention defense)
- **Production secret hardening** (sentinel + min-length floor + per-branch env override + dev/CI bypass)
- **PaymentService::payment gateway-context backtrace guard** (P0-POS-02 2026-05-18) — non-spoofable from non-gateway callers
- **BypassAuditLogger limits bypass to HARDWARE only** — fiscal sealing always runs even when `payment.bypass.enabled=true`
- **Daily fiscal-chain-monitor cron at 03:30** sweeps every active branch via shared `self::activeBranchIds()` SSOT
- **docs/FISCAL_SECRETS.md** (existence confirmed) — secret rotation runbook

### HEAL

**None.** No P0 or P1 issues identified in fiscal cascade code paths.

### LOCK-PLAN

**None.** No frozen-file modification required.

### DEFER (V1.0.2 backlog)

**None.** No deferred items surfaced. Fiscal cascade is production-shippable as-is for V1.

---

## P0 / P1 in Frozen Zone

- **P0 findings in frozen files**: **0**
- **P1 findings in frozen files**: **0**

No `STUCK report` filed; no escalation required.

The frozen files audited READ-ONLY:
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/FiscalChainValidator.php`
- `app/Services/Fiscal/FiscalSealingService.php`
- Migration `2026_04_22_000002_create_audit_logs_table.php`
- Migration `2026_04_22_000003_create_z_reports_table.php`
- Migration `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
- Migration `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`

---

## Operator-Visible Caveats (not findings — process-level)

These are documented in service docblocks and migration comments. Listed here for situational awareness only:

1. **HMAC secret rotation** must follow `docs/FISCAL_SECRETS.md` runbook. Naive `.env` change breaks `verifyChain` for all historical rows on the rotated branch — this is INHERENT to HMAC chains, not a vulnerability (RED A15).
2. **TRUNCATE bypass mitigation** is delegated to deploy-time DB GRANT restriction (revoke TRUNCATE permission on production DB user). Documented in 3 migration docblocks; cannot be enforced from PHP code (RED A7).
3. **Orphan kiosk-paid orders** (fiscal_alloc_error_at IS NOT NULL) are surfaced via `warnOnOrphanedPaidOrders` at Z close — operator may delay close until cron `foodking:fiscal:retry-alloc` clears the backlog (best-effort observability, not correctness — RED A12/A13).
4. **Feature flags** (`fiscal.chain_validation_enabled`, `fiscal.kiosk_auto_allocate_sequence`, `fiscal.sealed_z_guard_enabled`, `fiscal.verify_chain_strict`) provide emergency rollback paths. All default to NF525-strict behaviour; turning any OFF in production is operator-visible via `fiscal` log channel.

---

## Conclusion

**WF-5 verdict: INTACT.**

The NF525 fiscal cascade is production-validated through 17+ documented iterations (POS-9.4, iter11-15, Wave 3/3b, P11-FZH, P0-FIX-1/2/3/4, WAVE5-POS-001, GOAL round-2). Cross-specialist consensus across 4 independent lenses (Architect, Security, DBA, RED) finds zero P0/P1 issues in fiscal-cascade code paths and zero successful kill-chains across 16 RED-team attack vectors.

The chain is **bit-identical** between session start and session end. READ-ONLY discipline was preserved. No patches recommended. Recommendation: **KEEP-AS-IS** across all 14 fiscal-cascade files.

**No HEAL, no LOCK-PLAN, no DEFER, no STUCK report.**
