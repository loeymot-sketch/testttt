# Gate Brief — `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE` — 2026-05-02

**TASK_ID:** `CV1-LIFECYCLE-UX-001` — sub-action 2.6
**Author:** Claude (in-session orchestrator, AUDIT_FALLBACK = cursor-session)
**Plan reference:** `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md` §2.6 (line 328-330)
**Audit reference:** `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` §A.2 #15
**Hard gate type:** **Schema migration (table / index / constraint)** — per `.cursor/rules/human-gates.mdc` requires **Human written approval**.

---

## Trigger

Plan §2.6 requires adding `UNIQUE` constraint to `stock_movements.idempotency_key`. Schema migration = hard gate per `human-gates.mdc`. The intent is a defense-in-depth safety belt: even if `StockService::mutateForOrder` is called twice with the same idempotency key (retry storm, worker overlap), the DB rejects the second insert and the service treats the rejection as a no-op (idempotent contract).

## Affected Subsystems

| Subsystem | File | Read / Write | Notes |
|---|---|---|---|
| Schema (DDL) | `database/migrations/202X_XX_XX_add_unique_to_stock_movements_idempotency_key.php` | NEW | Adds `unique('idempotency_key')` after backfill of historical rows. |
| Stock service | `app/Services/Stock/StockService.php::mutateForOrder` | WRITE (small) | Wrap insert in `try { } catch (QueryException $e) { /* unique constraint = no-op */ }`. |
| Sentinel | `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` | UN-SKIP + IMPLEMENT | 4 cases as documented in the file's docblock. |

## Invariants at Risk

1. **#3 branch_id isolation** — *not at risk*: idempotency key already encodes branch context.
2. **#5 OrderService / FrontendOrderService symmetry** — at risk: both paths call `StockService::mutateForOrder`. Behavior must be unchanged from the caller's perspective (still idempotent, still no exception).
3. **#6 Frozen zones** — schema migration itself is a hard gate; `StockService::mutateForOrder` change is small and adjacent.
4. **Append-only guard** (existing `StockMovementsAppendOnlyTest`) — at risk: the catch block must NOT mask other QueryException causes. Filter by SQLSTATE 23000 / Error Code 1062 (MySQL) / 19 (SQLite).

## Decision Required

Authorize a 3-step migration sequence:

### Phase 1 — Backfill (executable in production immediately)
- New migration that:
  1. Identifies `stock_movements` rows where `idempotency_key IS NULL`.
  2. Sets a synthetic key per row: `sha1(order_item_id . ':' . stockable_type . ':' . stockable_id . ':' . movement_type . ':' . created_at)`.
  3. **Does NOT** add the unique constraint yet.
- Reversible: a `down()` that nullifies the synthetic keys (matched via a marker prefix `sha1:bfx:` so we can tell synthetic from real keys).
- Run on production: `php artisan migrate` — no downtime, no lock on hot path.

### Phase 2 — Identify duplicates (read-only audit)
- New artisan command `php artisan stock:audit-idempotency-key-duplicates --branch={id}` that lists pre-existing duplicate `idempotency_key` rows. If any duplicates found in production, **the gate is NOT cleared** for Phase 3 until they are deduplicated by hand (with operations team).
- Output: `reports/audit/STOCK_MOVEMENTS_DUP_IDEMPOTENCY_KEY_PROD_<DATE>.md`.

### Phase 3 — Add UNIQUE constraint (separate migration, post-Phase-2 verification)
- After Phase 2 confirms zero duplicates, a follow-up migration adds `unique('idempotency_key')`.
- This migration is **only run after** the operations team verifies Phase 2's report on production data.
- Reversible: `down()` drops the unique index.

### Phase 4 — Service-level guard
- `StockService::mutateForOrder` wraps the insert in `try { } catch (QueryException $e) { if (Str::contains($e->getMessage(), 'idempotency_key')) { return /* no-op */; } throw $e; }`.
- Sentinel `StockMovementIdempotencyKeyUniqueTest` un-skipped with the 4 documented cases.

## Options

1. **Approve full 4-phase rollout** (recommended). Implementation via `codex-extension` complex EXECUTE for Phase 1 + Phase 2 (immediate). Phase 3 deferred to post-prod-audit. Phase 4 batched with Phase 3.

2. **Approve Phase 1 + 4 only** (defer unique constraint). Add the synthetic key backfill + service-level idempotency guard via `try/catch` on duplicate-detection at the application layer (via `where('idempotency_key', X)->exists()` pre-check). Skips DDL change. Lower blast radius but loses the "DB safety belt" property. Documented as a known gap.

3. **Defer to V2.** Document the open race in `docs/audit/V1_KNOWN_LIMITATIONS.md`. Only relevant if duplicate writes are observed in production telemetry. Today, no incident on record.

## Test Strategy (post-clearance)

- Sentinel: `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` — 4 cases (constraint present, duplicate insert is no-op, append-only guard unaffected, concurrent decrement still passes).
- Regression: `php artisan test --filter='Stock|StockMovement'` must remain green.
- Migration test: spin up sqlite, run migration up + down, verify reversibility.
- Production audit: Phase 2 dry-run on production replica before Phase 3.

## Risk Analysis

- **Backfill collisions:** the synthetic SHA1 includes `created_at` to second precision. Collisions theoretically possible if two rows for the same (order_item_id, stockable, type) were written in the same second. Mitigation: pre-Phase-3 audit catches them.
- **Application-layer race:** even with UNIQUE constraint, two concurrent transactions can both pass the pre-check `where(...)->exists()`. The catch-block on `QueryException` is the actual protection.
- **Down migration risk:** dropping the unique index is fast (DDL). Re-applying after a regression requires Phase 2 audit again.

## Approval

```
[ ] Approved — option selected: ___
    Rationale: ____________________________________
    Approved by (DB lead): ________________________
    Approved by (operations): _____________________
    Date: 2026-__-__
[ ] Cancelled — defer to V2 with logging-only mitigation.
    Approved by: __________________________________
    Date: 2026-__-__
```

After approval, record in `docs/gates/GATE_LOG.md`. Implementation traced as `EXECUTE_DELEGATION: codex-extension` with `EXECUTION_TIER: complex`.

---

**Resumption protocol:** identical to `human-gates.mdc` general flow — approval populated, recorded in `GATE_LOG.md`, implementing agent reads cleared brief and updates plan section.
