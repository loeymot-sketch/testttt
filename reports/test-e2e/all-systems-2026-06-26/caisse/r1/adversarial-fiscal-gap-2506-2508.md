# Adversarial verification — CAISSE / Tiroir-caisse-cash-Z

## Finding under test
[P2] app/Models/Order.php:17 — Gap de séquence fiscale réel (2506-2508) en DB live + aucune garde hard-delete sur Order + aucun vérificateur de contiguïté order-level NF525

## Verdict: REAL but DOWNGRADED to P3 (defense-in-depth hardening, NOT a realized NF525 breach in V1-LOCAL)

The data facts are all reproducible and accurate. But the finding **overstates exploitability**:
(a) the proposed root (model-level force-delete guard / orders DELETE trigger) is an ADJACENT-to-frozen hardening, and
(b) the only known deletion vectors are dev/test commands ALREADY guarded against `APP_ENV=production`.
The gap corrupted **no signed Z**, lost **no money**, and is **not reproducible via the caisse subsystem nor in production**. Per V1-LOCAL severity (NF525/argent/perte = P0/P1; cosmetic dev-DB residue + missing hardening = P3), this is P3.

## Repro re-executed (all CONFIRMED)
- Gap query → 2506, 2507, 2508 (3 missing). 
  `mysql -u root foodking_e2e -e "SELECT id,fiscal_sequence_no,deleted_at FROM orders WHERE fiscal_sequence_no IN (2505,2506,2507,2508,2509)"` → only 2505(order 4974) & 2509(order 5019) exist, both `deleted_at=NULL`. 2506-2508 = 0 rows (not soft-deleted either).
- `seq_cnt=2570, min=1, max=2573` → span 2573, 3 missing. CONFIRMED.

## Evidence verified
- **FiscalSequenceService::next()** l.97-103 = `Order::withoutGlobalScope(BranchScope)->withTrashed()->where(branch)->lockForUpdate()->max('fiscal_sequence_no') + 1`. A soft-delete keeps the row → MAX still sees it → no gap. A rollback reuses the number. ⇒ a gap can ONLY come from a HARD-DELETE of rows holding 2506-2508. CONFIRMED.
- **Order.php** uses `SoftDeletes` (l.17). Only model hook touching delete is `static::restoring(throw)` (l.139) — blocks RESTORE, not force-delete. No `forceDeleting`/`deleting` guard. CONFIRMED.
- **Triggers**: `SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='foodking_e2e'` → **0**. NB: this dev DB has NO triggers at all — not on `orders`, AND NOT on `audit_logs`/`z_reports` either. The trigger migrations (e.g. 2026_05_09_160000) are MySQL-only but were never applied here (or dropped). The finding's premise "audit_logs/z_reports HAVE the trigger, orders does not" is FALSE *in this DB* — none do. The real production guard for audit_logs/z_reports is the migration; an equivalent for `orders` does not exist anywhere.
- **OrderFiscalSequenceSchemaTest** (l.25-69) = column-exists + UNIQUE(branch,seq) + per-branch independence only. NO contiguity/gap check. CONFIRMED.
- **fiscal:verify-chain** (FiscalVerifyChainCommand) walks audit_logs + z_reports HMAC; its `sequence_gap` (l.144-160) is a **z_reports.id kind** (Z-number gap), NOT order-level fiscal_sequence_no. CONFIRMED.
- **VerifyZMembershipCommand** (l.53-108) flags fiscalized orders absent from a signed Z, but reads EXISTING rows (`whereNotNull fiscal_sequence_no`) — structurally cannot see MISSING numbers (deleted rows). CONFIRMED.
- **Z impact**: gap window orders created 2026-06-19/20 (order 4974 created 2026-06-19, order 5019 2026-06-20). z_reports around that window: Z#22 closed 2026-06-19 12:34 (order_count=1, total_ttc=13.00); Z#21/23/24/25/26 all order_count=0/total 0. ⇒ the deleted 2506-2508 allocations were never aggregated into a non-trivial signed Z. **No signed Z corrupted.** CONFIRMED.

## ROOT CAUSE found (finding's repro under-specified it — it claimed "non reproducible via caisse")
The deletion vector exists and is NOT just "tinker":
- `app/Console/Commands/Iter15CleanupTestOrdersCommand.php:97` → `DB::table('orders')->whereIn('id',$matchedIds)->delete()` (raw query-builder HARD delete, bypasses Eloquent/SoftDeletes/restoring-guard entirely), matching test tokens AUDIT-*/E2E-*/TEST-*/RED-TEAM-*/ZZ-TEST-*.
- `app/Console/Commands/CleanupTestFixturesCommand.php:118` → `deleteWhereIn('orders','id',...)` → `DB::table('orders')->whereIn(...)->delete()` (l.305), same raw hard delete.
- `app/Console/Commands/FreshOrderSeed.php:28` → `DB::table('orders')->truncate()`.
The 2026-06-19/20 e2e/livreur/KDS sessions (MEMORY: project_execute_livreur_kiosktwin_2026-06-19, project_kds_doublure_2026-06-20) ran token-prefixed e2e orders; one cleanup swept 2506-2508.

## Severity-lowering MITIGATION the finding UNDERSTATES
All three deletion commands ALREADY guard production:
- Iter15CleanupTestOrders l.65: `if ($apply && app()->environment('production')) { error('Refusing to delete orders in production.'); return 2; }`
- CleanupTestFixtures l.46: `if (app()->environment('production')) { error('Refusing to delete test fixtures in production.'); }` + requires `--confirm=PW-FIXTURES`.
- FreshOrderSeed l.21: refuses `APP_ENV=production`.
⇒ The gap mechanism is **not reachable in production** via the known commands. The realized harm in V1-LOCAL is a cosmetic gap in a dev DB (`foodking_e2e`), with zero fiscal/money/Z impact.

## Lentille
Jumeau-systémique (raw `DB::table('orders')->delete()/truncate()` bypasses every Eloquent guard) + verify-before-report (the env-guards + zero-Z-impact were the decisive refutation of P2-level harm).

## Reco (NON-frozen, if owner wants the hardening — optional, P3)
Root candidate (Order.php force-delete guard) is model-adjacent to NF525 services; the orders DELETE-trigger reco explicitly needs owner-gate (table `orders`). For V1-LOCAL the env-guards already block the only known prod vector, so this is defense-in-depth only:
- TDD NON-frozen `Order::forceDeleting(fn($o) => throw if $o->fiscal_sequence_no !== null)` in `Order.php` booted() (NOT a frozen file) — but note raw `DB::table('orders')->delete()` STILL bypasses it; the Eloquent guard only stops Eloquent force-deletes. The truly robust guard is the MySQL `BEFORE DELETE SIGNAL` trigger (owner-gated, table orders).
- Optional read-only `fiscal:verify-sequence-gaps` command + deploy-gate (non-frozen, additive).
Given V1-LOCAL + env-guards + zero realized harm, recommend DEFER to cloud-prep backlog (mirror UNI-03 style), not a V1 blocker.
