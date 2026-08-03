# Intersection POS x Fiscal — STATUS

**Date** : 2026-05-18
**Branch** : heal/cms-pr1-quickwins-2026-05-18
**Master sub-agent** : POS x Fiscal (NF525 — LEGAL CRITICAL, FROZEN scope)
**Round** : 1
**Wall-clock** : ~30 min

---

## NF525 chain attestation (BEGIN vs END — bit-identical)

| Metric | BEGIN | END | Delta |
|---|---|---|---|
| `audit_logs.count` | 97 | 97 | 0 |
| `audit_logs.last_hash` | `af02d7895d412654bf86b468e1d6f2e7375237d8361a10bcfcdd3e48424ac36f` | `af02d7895d412654bf86b468e1d6f2e7375237d8361a10bcfcdd3e48424ac36f` | bit-identical |
| `z_reports.count` | 4 | 4 | 0 |
| `z_reports.last_signature` | `2c7ef3d479bf334ad75e6d8b3dacb48da445a9b6dfa70fc5afb096a4dac30034` | `2c7ef3d479bf334ad75e6d8b3dacb48da445a9b6dfa70fc5afb096a4dac30034` | bit-identical |
| `cash_drawer_sessions.count` | 6 | 6 | 0 |
| `cash_movements.count` | 4 | 4 | 0 |
| `order_payments.count` | 0 | 0 | 0 |
| `fiscal_seq_allocated` | 183 | 183 | 0 |
| `fiscal_seq_errors` | 0 | 0 | 0 |
| `php artisan fiscal:verify-chain` | `CHAIN OK (audit_logs + z_reports) (branch=1)` | `CHAIN OK (audit_logs + z_reports) (branch=1)` | unchanged |

**ATTESTATION** : NF525 chain integrity preserved. Audit was strictly READ-ONLY on all frozen anchors. Zero writes to fiscal services, fiscal migrations, audit_logs, z_reports, cash_movements, order_payments.

---

## Specialist outputs

| Role | File | P0 | P1 | P2 | KEEP-AS-IS | Verdict |
|---|---|---:|---:|---:|---:|---|
| Architect | `PFIS-1-Architect/architect.json` | 0 | 0 | 2 | 8 | GREEN |
| Security  | `PFIS-2-Security/security.json`   | 0 | 0 | 2 | 8 | GREEN |
| DBA       | `PFIS-3-DBA/dba.json`             | 0 | 0 | 2 | 8 | GREEN |
| RED       | `PFIS-4-RED/red.json`             | 0 | 0 | 3 | 8 | GREEN |
| **TOTAL** | — | **0** | **0** | **9** | **32** | **GREEN** |

---

## Cross-validated findings (any P0/P1 that >=2 specialists raised)

**NONE.** RED could not produce a P0 or P1 finding. Architect / Security / DBA all converged on GREEN.

---

## P2 backlog (V1.0.X informational — operator/deploy-doc dependencies, no code change required for V1)

1. **TRUNCATE bypass mitigation lives in deploy doc** (Architect + DBA + RED converged on the same observation).
   - Add `php artisan fiscal:assert-grants` command for V1.0.X. Reads `INFORMATION_SCHEMA.USER_PRIVILEGES` and refuses boot if app_user has DROP / DELETE / TRUNCATE on `audit_logs`, `z_reports`, `cash_movements`, `cash_drawer_sessions`, `order_payments`.

2. **`env('FISCAL_AUDIT_SECRET_BRANCH_'.$id)` bypasses Laravel config cache** (Security).
   - Document in `docs/FISCAL_SECRETS.md` that per-branch override env vars require `php artisan config:cache` reload to take effect.

3. **dev_sentinels blocklist is finite (7 entries)** (Security).
   - Optional V1.0.X enhancement: add Shannon-entropy heuristic to reject low-entropy production secrets (>=3.5 bits/char).

4. **FiscalChainValidator lazy resolution comment** (Architect).
   - Add a one-line lifetime-contract comment to clarify cycle-break design.

5. **RefundCreated dispatchAfterCommit fires on `$parent`, not `$mirror`** (Architect).
   - Document for V1.0.X integrator clarity (outbox/webhook payload references parent order_id).

6. **audit_logs DELETE trigger missing on PostgreSQL / SQL Server** (DBA).
   - V1 targets MySQL exclusively. Future SaaS port requires porting `CREATE TRIGGER ... LANGUAGE plpgsql RAISE EXCEPTION` blocks.

7. **`FiscalArchiveCommand --no-verify` CLI flag** (RED).
   - Manifest should carry a prominent `z_chain_verified=false` flag + warning string when bundle produced with `--no-verify`.

8. **`FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false` is a rollback knob** (RED).
   - Add a fiscal:assert-config boot guard for V1.0.X that refuses production boot if any of `{kiosk_auto_allocate_sequence, sealed_z_guard_enabled, chain_validation_enabled, verify_chain_before_archive}` is false without `FISCAL_ROLLBACK_REASON` env override.

9. **Anti-drift CI guard on UNIQUE constraint** (RED).
   - Add a CI guard that fails any PR introducing `dropUnique('orders_branch_fiscal_seq_unique')` or `dropUnique('audit_logs_branch_prev_unique')`.

---

## KEEP-AS-IS — 32 frozen-anchor attestations

All four specialists independently re-attested the same eight frozen anchors as production-grade. Consensus:

1. **`FiscalSequenceService::next` 3-layer concurrency** (Cache::lock + DB::tx + lockForUpdate + UNIQUE)
2. **`AuditLogService::write` per-branch lock + UNIQUE retry-once + canonical JSON**
3. **`ZReportService::open`/`close` lock + verifyChain pre-flight + bounded audit-chain tail**
4. **`ZReportService::aggregate` half-open window + withTrashed + per-tax-rate from order_items**
5. **PaymentService gateway-context guard + counter-payment fiscal alloc**
6. **`SplitPaymentService` validateBreakdown + per-tranche audit + simulation guard scope**
7. **`RefundWithCounterEntryService` mirror + audit + payment tranches mirror (iter15-P0-10)**
8. **POS_SIMULATION_HARDWARE bypass perimeter** (HARDWARE only, NEVER fiscal/pricing/audit)
9. **`audit_logs` INSERT-only triggers (MySQL + SQLite parity)**
10. **`audit_logs` UNIQUE(branch_id, prev_hash) anti-fork constraint**
11. **`orders_branch_fiscal_seq_unique` gap-free gate**
12. **`z_reports_branch_sequence_unique` + DELETE trigger (UPDATE intentionally allowed for cash enrichment + archive)**
13. **`cash_movements`/`order_payments` restrictOnDelete + DELETE triggers (P0-FIX-4)**
14. **`cash_drawer_sessions` partial UNIQUE (driver-aware: MySQL generated column, SQLite/PG native WHERE)**
15. **`fiscal_alloc_error_at` observability marker + retry cron `foodking:fiscal:retry-alloc`**
16. **`composition_snapshot` 5+1 INSERT-site contract** (OrderService×3 lines 466/821/1277 + FrontendOrderService×1 line 441 + PricingService×1 line 291 + RefundWithCounterEntry×1 line 136 mirror); no UPDATE path exists anywhere in app/
17. **`assertProductionSafe` dev_sentinels + min_secret_length=32 refusal**
18. **Cache::lock idempotent finally-release everywhere**
19. **`SealedOrderGuard::assertMutable` + `assertSealed` symmetric pair**
20. **Payload canonicalisation `sortRecursive` + `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`**

---

## Anchors touched (READ-ONLY)

Files read (confirmed unchanged at end):

- `app/Services/Fiscal/FiscalSequenceService.php` (FROZEN §7) — 105 LOC
- `app/Services/Fiscal/AuditLogService.php` (FROZEN §7) — 375 LOC
- `app/Services/Fiscal/ZReportService.php` (FROZEN §7) — 727 LOC
- `app/Services/Fiscal/FiscalSealingService.php` — 116 LOC
- `app/Services/Fiscal/FiscalChainValidator.php` — 189 LOC
- `app/Services/Order/RefundWithCounterEntryService.php` — 234 LOC
- `app/Services/PaymentService.php` — 617 LOC
- `app/Services/Payments/SplitPaymentService.php` — 305 LOC
- `app/Services/Cash/CashDrawerService.php` (head 300) — 549 LOC total
- `app/Services/Order/SealedOrderGuard.php` — 120 LOC
- `app/Http/Controllers/Admin/PosController.php` — 242 LOC
- `app/Console/Commands/RetryFiscalAllocCommand.php` — 133 LOC
- `app/Services/OrderService.php` (FiscalSequenceService call sites lines 900-960) — 3000+ LOC
- `app/Services/FrontendOrderService.php` (finalizePaidKioskOrder section lines 1066-1200)
- `config/fiscal.php` — 192 LOC

Migrations read (NF525-tagged, all FROZEN):

- `2026_04_22_000001` (orders.fiscal_sequence_no + UNIQUE)
- `2026_04_22_000002` (audit_logs + INSERT-only triggers)
- `2026_04_22_000003` (z_reports)
- `2026_04_22_100000` (audit_logs UNIQUE chain anti-fork)
- `2026_05_09_160000` (z_reports DELETE trigger)
- `2026_05_09_200000` (fiscal_alloc_error_at)
- `2026_05_10_010000` (P0-FIX-4 cash tables immutability + restrictOnDelete)
- `2026_05_10_020000` (cash_drawer_sessions partial UNIQUE driver-aware)
- `2026_05_16_130000` (cash_movements SQLite DELETE trigger)
- `2026_05_17_100000` (cash_drawer_sessions actor columns)

---

## Final verdict

**GREEN — Production-grade NF525 maturity at the POS x Fiscal intersection.**

- 0 P0, 0 P1, 9 P2 (informational / V1.0.X backlog / operator-deploy-doc dependencies).
- 32 KEEP-AS-IS attestations (8 frozen anchors × 4 specialist lenses).
- Chain bit-identical begin vs end (read-only respected absolutely).
- All concurrency primitives, immutability triggers, canonical hashing, secret rotation, gateway-context guards, sealed-Z guards, simulation perimeter, retry orphan path, and split-payment refund mirror are at production-grade.

**No LOCK plan recommended.** No P0 found. No write to any frozen anchor.

The P2 backlog is operational / deploy-doc / future-port concerns, not V1 blockers.
