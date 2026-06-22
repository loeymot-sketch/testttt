# Axis A1 — Database & Schema **Adversarial Review** (Round 1)

**Agent role** : Adversarial Red-Team auditor
**Date** : 2026-05-13 ~04:30 CEST
**Primary report** : `reports/audit/ultra-goal-2026-05-13/axis-A1-DBA-round1.md` (DBA, score 92/100, verdict GO-CONDITIONAL)
**Adversarial verdict** : **REQUIRES-HEAL** (re-scored 76/100, delta = -16)
**Confidence** : HIGH on disputed findings, MEDIUM on missed cross-axis flags

---

## Executive Summary

The primary DBA agent did solid work on the **structural** layer (FKs, triggers, indexes, charset, migrations, CHECK constraints, HMAC chain linkage) — these claims **survive adversarial cross-validation**.

However, three claims **collapse** under hostile inspection :

1. **A1-01 (Fiscal sequence) PASS is wrong.** The DBA explained the seq gap as "30 non-finalized + 18 soft-deleted = 48". Reality : **0 soft-deleted orders carry a fiscal_sequence_no** (queried directly). The gap is **162 missing seq numbers** in range 1..293 for branch=1, none of which are attributable to soft-deletes. They appear to come from hard-deleted rows pre-deletion_log (only 1 hard-delete logged for `Order`, the rest predates the audit logging). For a fiscal-of-record branch this is a P0 NF525 gap-free violation ; for branch=1 treated as dev/test, P1 procedural with a hard production-deploy gate.

2. **A1-07 (composition_snapshot) PASS is incomplete.** The DBA verified the schema permits NULLs (correct). They failed to flag that **194 of 301** order_items with non-NULL snapshot have `{"lines":[],"addons":[],"extras":[]}` — i.e., the composition_snapshot is structurally present but **semantically empty**. The schema accepts this, so the schema-axis verdict can stay PASS, but a **cross-axis A2/A11 NF525 invariant breach** was missed (the PricingService freeze-at-creation invariant requires the snapshot to actually contain the composition).

3. **21 PAID active orders without fiscal_sequence_no** — discovered during adversarial verification, missed by primary. These are pre-2026-05-09 legacy rows (before `fiscal_alloc_error_at` flag was added). I confirmed `post_iter_paid_no_seq=0`, so this is **legacy data drift, not ongoing breakage**. Still warrants explicit acknowledgment as a known-debt item before any commercial cutover.

The off-by-one counting errors (12 SoftDeletes models, not 11 ; 84 tables, not 85) are **P3 nits**, mentioned for completeness but not load-bearing for verdict.

---

## Disputed findings

### A1-01 — Fiscal sequence monotonic integrity **(DBA: PASS / Adversarial: DISPUTED)**

**DBA claim** : "branch_id=1 max=293, with_seq=131, total=179. 48 orders without seq (30 active non-finalized + 18 soft-deleted). 0 allocated+deleted violations. Chain integrity preserved."

**Adversarial verification** :

```sql
-- DBA claim: 18 soft-deleted have legitimate fiscal seq retention
SELECT COUNT(*) AS soft_deleted_with_seq
FROM orders WHERE branch_id=1
  AND fiscal_sequence_no IS NOT NULL AND deleted_at IS NOT NULL;
-- Result: 0  ← DBA narrative collapses here.

-- Reality check on gap
WITH RECURSIVE seq(n) AS (
  SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < 293
)
SELECT COUNT(*) FROM seq
WHERE n NOT IN (
  SELECT fiscal_sequence_no FROM orders
  WHERE branch_id=1 AND fiscal_sequence_no IS NOT NULL
);
-- Result: 162 missing seq values in 1..293.

-- Hard-delete trace
SELECT COUNT(*) FROM deletion_log WHERE model_type='App\\Models\\Order';
-- Result: 1 (only order #187).
```

Summary :
- 293 sequence numbers issued (MAX(fiscal_sequence_no))
- 131 distinct seqs in active rows
- 0 seqs on soft-deleted rows (DBA was wrong about this carrying retention)
- 1 hard-deleted row logged via Eloquent deletion_log
- **161 unaccounted seq numbers** — most plausibly lost when `migrate:fresh` was run before iter11 added the deletion logger, or via direct `DB::table('orders')->delete()` bypassing the Eloquent observer

NF525 / Loi Finance France requires fiscal_sequence to be **strictly monotonic and gap-free per branch**. The MySQL UNIQUE index `orders_branch_fiscal_seq_unique` enforces uniqueness, **not gap-freeness**. The DBA conflated the two.

**Severity correction** : DBA marked PASS. Adversarial : **P1 in dev-context, P0 if branch=1 ever becomes fiscal-of-record**. Recommend gate flag `BRANCH_1_FISCAL_OF_RECORD=false` plus a documented "must wipe + re-seed before commercial use" in the deploy doc.

**Cross-axis** : A2 (FiscalSequenceService), A11 (compliance), A0 (env discipline).

---

### A1-07 — composition_snapshot NULL handling **(DBA: PASS / Adversarial: INCOMPLETE)**

**DBA claim** : "Nullable JSON by design; known P1-3 backlog 187 with NULL name. Schema correct."

**Adversarial verification** :

```sql
SELECT id, JSON_EXTRACT(composition_snapshot, '$') AS snap
FROM order_items WHERE composition_snapshot IS NOT NULL LIMIT 3;
-- All three samples: {"lines": [], "addons": [], "extras": [], "captured_at": "...", "schema_version": 1}

SELECT COUNT(*) FROM order_items
WHERE composition_snapshot IS NOT NULL
  AND JSON_LENGTH(composition_snapshot, '$.lines') = 0
  AND JSON_LENGTH(composition_snapshot, '$.addons') = 0
  AND JSON_LENGTH(composition_snapshot, '$.extras') = 0;
-- Result: 194 of 301 non-NULL snapshots are structurally empty.
```

The DBA's verdict is **technically defensible on the schema axis** (the column is nullable JSON, the application validates structure), but :
- 194 / 301 ≈ **64% of populated snapshots carry no composition data**
- This contradicts the NF525 design invariant ("composition_snapshot JSON frozen à création d'order — NEVER overwritten") cited in `CLAUDE.md §8`
- A real audit (TVA fraud investigation) on these orders would see "snapshot says 0 lines, but order_items.tax_amount > 0" — a forensic red flag

**Severity** : schema PASS stands, but this is a **MISSED cross-axis finding (writer-side data quality)**. Should appear as a P1 in axis **A2 (OrderService/PricingService)** with a backfill task.

---

### A1-02 — Soft-deletes count **(DBA: PASS / Adversarial: OFF-BY-ONE)**

**DBA claim** : "11 entities have trait + column" with enumerated list "Order, OrderItem, Item, ItemCategory, Branch, User, ItemAddon, ItemExtra, ItemVariation, KioskPromo, UpsellRule".

**Adversarial verification** :

```bash
grep -l "SoftDeletes;" app/Models/*.php | sort
# Returns 12 files (Branch, FrontendOrder, Item, ItemAddon, ItemCategory,
# ItemExtra, ItemVariation, KioskPromo, Order, OrderItem, UpsellRule, User).
```

DBA missed `FrontendOrder` (which extends `Model` with same `orders` table alias). 12 entities, not 11. **P3 nit** — doesn't move the verdict but worth correcting in the inventory.

---

## Confirmed findings (DBA was right)

The following claims survive adversarial cross-validation :

1. **HMAC chain linkage** — All 26 audit_logs rows verified prev_hash[N] = current_hash[N-1] (full chain walk, 0 breaks). ✓
2. **Triggers active with correct SIGNAL body** :
   - `audit_logs_no_update` : `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is INSERT-only (NF525 / POS-9.4.3)'`
   - `audit_logs_no_delete` : same SIGNAL message
   - `z_reports_no_delete` : `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'z_reports is immutable post-close (NF525 / POS-9.4.6) — DELETE forbidden'`
   - Note : did not test by actually executing UPDATE/DELETE (out of scope for static review).
3. **FK RESTRICT on payment paths** : `cash_movements.cash_drawer_session_id` and `order_payments.order_id` both DELETE_RULE=RESTRICT, confirmed via information_schema. ✓
4. **`uniq_domain_events_idempotency_key`** : UNIQUE BTREE on `idempotency_key` column, cardinality 301/475 (matches DBA). ✓
5. **Migrations 153 Ran / 0 Pending** : verified via `php artisan migrate:status`. ✓
6. **CHECK constraints active** : 6 constraints verified (item_wizard_steps min/max/position, item_wizard_profiles XOR, stock_levels on_hand/reserved). ✓
7. **Critical indexes present** : `idx_orders_branch_status`, `idx_order_items_order_id`, `item_branch_availability_item_id_branch_id_unique`. ✓
8. **Item soft-deletes on Item.php:17** : verified `use HasFactory, InteractsWithMedia, SoftDeletes;`. ✓
9. **cat 315 channels=[]** : `SELECT DISTINCT channels FROM item_categories` → {NULL, []}. ✓
10. **item_wizard_profiles XOR** : both_set=0, both_null=0, only_item=7, only_cat=0. CHECK constraint `item_wizard_profiles_owner_xor_check` enforces it at DB level. ✓
11. **0 orphan order_items** : LEFT JOIN orders shows 0 rows where order is missing. ✓
12. **stockable polymorphic clean** : stock_levels has 0 rows, no type pollution. ✓
13. **Backup file exists** : `storage/backups/ultra-goal-2026-05-13/foodking-pre-goal.sql` 5.5 MB present. ✓
14. **kiosk_machines.branch_id FK** : exists, DELETE_RULE=NO ACTION. ✓
15. **No orphan reference on `composition_snapshot`** : schema permits NULL by design, structural integrity OK.

---

## Hallucinated findings

None outright fabricated. All DBA claims have at least partial basis in DB/code. The defects are :
- **Misleading narrative** (A1-01 explanation of gap via soft-deletes — false ; soft-deletes carry no seq)
- **Incomplete evidence** (A1-07 only checked NULL counts, missed structural emptiness)
- **Counting nits** (84 tables claimed as 85, 11 SoftDeletes claimed instead of 12)

---

## Severity corrections

| ID | DBA severity | Adversarial severity | Reason |
|----|--------------|----------------------|--------|
| A1-01 | P0-PASSING | **P1 (dev) / P0 if fiscal-of-record** | Gap-free invariant not verified ; soft-delete explanation false ; needs production-deploy gate |
| A1-07 | P2-PASSING | **P2 schema PASS + missed P1 cross-axis** | 194/301 empty snapshots is a writer-bug finding belonging in A2 |
| A1-02 (count) | "11 entities" | **12 entities** | FrontendOrder missed (P3) |
| Inventory ("85 tables") | 85 | **84** | Off-by-one (P3) |

---

## Missing findings (DBA didn't surface)

### MISS-1 — Legacy PAID-active orders without fiscal_sequence_no (21 rows)

```sql
SELECT COUNT(*) FROM orders WHERE branch_id=1
  AND payment_status=5 AND fiscal_sequence_no IS NULL AND deleted_at IS NULL;
-- Result: 21
```

All from 2026-05-07 through 2026-05-11 10:48 — **before** the orders that reliably allocate seqs (post-iter11). 0 of them post-2026-05-09 20:00:00 (when `fiscal_alloc_error_at` flag was added). So this is **legacy drift, not active breakage**, but a real NF525 inconsistency that should be acknowledged before any commercial cutover.

**Severity** : P1 (legacy debt). Cross-axis : A2.

### MISS-2 — `composition_snapshot` semantically empty in 194/301 rows

Already detailed under "Disputed findings — A1-07". **P1 in A2 axis.**

### MISS-3 — `order_payments` table missing FK on `branch_id`

```sql
-- order_payments has FK on order_id only:
CONSTRAINT order_payments_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
-- No FK constraint on branch_id (even though column exists and is indexed).
```

Means an `order_payments` row could carry a `branch_id` that doesn't correspond to an existing branch (or that differs from `orders.branch_id`). Branch isolation invariant relies on Eloquent global scope — DB-level integrity is incomplete.

**Severity** : P2 (defense in depth). Cross-axis : A0 (multi-tenant integrity).

### MISS-4 — `printers.branch_id` FK is CASCADE, not RESTRICT

```sql
SELECT k.TABLE_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r USING(CONSTRAINT_NAME)
WHERE k.TABLE_NAME='printers' AND k.REFERENCED_TABLE_NAME='branches';
-- Result: DELETE_RULE = CASCADE
```

If a branch is hard-deleted, all printer config is silently wiped. Inconsistent with the RESTRICT philosophy applied to cash_movements / order_payments. Probably benign (branches aren't deleted in production), but inconsistent.

**Severity** : P3 (consistency).

### MISS-5 — `kiosk_offline_queue` table referenced in plan but absent

```bash
grep -rln "kiosk_offline_queue" plans/ docs/
# Returns: plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md, plans/caisse-v1-ultra-finition/PHASE_B_TEST_STABILIZATION_2026-04-26.md

mysql -e "SHOW TABLES LIKE 'kiosk_offline%';"
# Result: empty
```

Mentioned in 2 plan documents but **does not exist** in DB. Low-confidence finding — may be a future-scoped feature, not active blocker.

**Severity** : P3 (plan drift, not P0).

### MISS-6 — Empty cardinalities in `webhook_events`

Table has 0 rows. UNIQUE index `uk_webhook_provider_id` exists but never been exercised in production. The DBA claimed "iter11 idempotency UNIQUE — confirmed". The constraint exists ; it's just untested by data. Not a defect but worth noting.

**Severity** : P3 (informational).

---

## Adversarial score breakdown

| Sub-axis | DBA | Adversarial | Δ |
|----------|-----|-------------|----|
| FK constraints | 95 | 90 | -5 (missed order_payments.branch_id FK absence ; printers CASCADE inconsistency) |
| Indexes | 95 | 95 | 0 |
| Triggers | 95 | 95 | 0 |
| Soft-deletes | 90 | 80 | -10 (off-by-one count, FrontendOrder missed) |
| Fiscal sequence | 95 | 50 | -45 (gap analysis collapses, 162 unexplained missing seqs) |
| HMAC chain | 95 | 95 | 0 |
| Migrations / charset | 95 | 90 | -5 (table count off by 1) |
| JSON / composition | 90 | 65 | -25 (194/301 empty snapshots not flagged) |
| Backups | 90 | 90 | 0 |
| Branch isolation FK | 90 | 75 | -15 (order_payments.branch_id FK missing) |

**Weighted re-score** : ~76/100 (down from 92).

---

## What was NOT exercised (honest scope statement)

- **Active trigger test** : did not trigger UPDATE/DELETE on audit_logs to confirm SIGNAL fires (verified body exists, sufficient for static review).
- **HMAC payload validity** : verified prev_hash → current_hash linkage only ; did not recompute HMAC against payload to confirm signature.
- **payment_attempts table** : did not deep-search — no clear plan reference, likely future feature.
- **Audit of `migrate:fresh` history** : did not trace which migrations historically wiped data ; pattern-inferred from auto_increment gaps.
- **deletion_log completeness audit** : checked `App\Models\Order` only, not all 12 SoftDelete models.

---

## JSON verdict

```json
{
  "agent_role": "adversarial",
  "axis": "A1",
  "primary_score": 92,
  "adversarial_score": 76,
  "delta": -16,
  "confirmed_findings": [
    {"id": "A1-HMAC-chain", "claim": "26 rows prev_hash[N]=current_hash[N-1]", "verified": true, "method": "full-chain SELECT walk, 0 breaks"},
    {"id": "A1-triggers", "claim": "audit_logs no_update/no_delete + z_reports no_delete active with SIGNAL SQLSTATE 45000", "verified": true, "method": "SHOW TRIGGERS body inspection"},
    {"id": "A1-FK-restrict", "claim": "cash_movements + order_payments DELETE_RULE=RESTRICT", "verified": true},
    {"id": "A1-idempotency-unique", "claim": "uniq_domain_events_idempotency_key BTREE", "verified": true},
    {"id": "A1-migrations", "claim": "153 Ran / 0 Pending", "verified": true, "method": "php artisan migrate:status"},
    {"id": "A1-check-constraints", "claim": "6 CHECK constraints on wizard/stock/profiles", "verified": true, "method": "information_schema.CHECK_CONSTRAINTS"},
    {"id": "A1-critical-indexes", "claim": "idx_orders_branch_status + idx_order_items_order_id", "verified": true},
    {"id": "A1-charset", "claim": "All tables utf8mb4_unicode_ci + InnoDB", "verified": true, "note": "table count was 84 not 85"},
    {"id": "A1-backup", "claim": "5.5 MB backup at storage/backups/ultra-goal-2026-05-13/foodking-pre-goal.sql", "verified": true},
    {"id": "A1-orphan-order-items", "claim": "0 orphan rows", "verified": true},
    {"id": "A1-xor-wizard", "claim": "item_wizard_profiles XOR enforced", "verified": true, "method": "DB CHECK constraint + counts"},
    {"id": "A1-cat-315", "claim": "cat 315 channels=[] for hidden state", "verified": true}
  ],
  "disputed_findings": [
    {
      "id": "A1-01",
      "claim": "Fiscal sequence monotonic with gap explained by non-finalized + 18 soft-deleted",
      "dispute": "0 soft-deleted rows carry a fiscal_sequence_no; 162 unaccounted gap entries in 1..293 (max=293, only 131 distinct seqs in active rows + 1 in deletion_log)",
      "method": "WITH RECURSIVE seq() check + soft-deleted-with-seq COUNT = 0",
      "corrected_status": "REQUIRES-HEAL with deploy gate"
    },
    {
      "id": "A1-07",
      "claim": "composition_snapshot null handling PASS",
      "dispute": "Schema-PASS valid, but 194 of 301 non-NULL snapshots have JSON_LENGTH(lines)=JSON_LENGTH(addons)=JSON_LENGTH(extras)=0. Cross-axis A2 NF525 freeze-at-creation invariant breach.",
      "corrected_status": "Schema PASS, cross-axis A2 P1"
    }
  ],
  "hallucinated_findings": [],
  "severity_corrections": [
    {"id": "A1-01", "from": "P0-PASSING", "to": "P1-dev / P0 if branch fiscal-of-record"},
    {"id": "soft-deletes-count", "from": "11 entities", "to": "12 entities (FrontendOrder missed)"},
    {"id": "table-count", "from": "85", "to": "84"}
  ],
  "missing_findings": [
    {
      "id": "MISS-1",
      "title": "21 legacy PAID-active orders without fiscal_sequence_no",
      "severity": "P1 legacy debt",
      "cross_axis": "A2",
      "note": "All pre-2026-05-09 (before fiscal_alloc_error_at flag); 0 post-iter11. Legacy drift, not active breakage."
    },
    {
      "id": "MISS-2",
      "title": "194/301 composition_snapshot rows are structurally empty",
      "severity": "P1 (writer bug)",
      "cross_axis": "A2 OrderService/PricingService"
    },
    {
      "id": "MISS-3",
      "title": "order_payments.branch_id missing FK constraint",
      "severity": "P2 defense-in-depth",
      "cross_axis": "A0 multi-tenant"
    },
    {
      "id": "MISS-4",
      "title": "printers.branch_id FK is CASCADE (inconsistent with RESTRICT philosophy)",
      "severity": "P3 consistency"
    },
    {
      "id": "MISS-5",
      "title": "kiosk_offline_queue mentioned in plans but absent from DB",
      "severity": "P3 plan drift",
      "confidence": "LOW (may be future-scoped)"
    },
    {
      "id": "MISS-6",
      "title": "webhook_events has 0 rows — UNIQUE constraint untested in real data",
      "severity": "P3 informational"
    }
  ],
  "verdict": "REQUIRES-HEAL",
  "rationale": "Primary DBA solid on structural integrity (FKs, triggers, indexes, charset, migrations, HMAC linkage) — those claims survive hostile cross-validation. The A1-01 fiscal-sequence PASS verdict however collapses: the DBA's narrative attributed the gap to soft-deletes, but adversarial query proves 0 soft-deleted rows carry a fiscal_sequence_no, leaving 161-162 seq numbers unexplained. Additionally A1-07 composition_snapshot PASS is technically defensible on schema axis but missed a 194/301 structural-emptiness issue that crosses into A2 NF525 invariant territory. Net adversarial score 76/100. Heal actions: (1) document branch_id=1 fiscal status as dev-only with deploy gate, (2) backfill composition_snapshot lines from order_items.item_id where empty (P1 in A2), (3) add FK on order_payments.branch_id (P2 defense-in-depth), (4) fix the SoftDeletes inventory + table count off-by-ones (P3 nits). No P0 confirmed for branch=1 in dev context; commercial cutover requires explicit re-verification."
}
```

---

*Adversarial review completed. Primary DBA achieved strong structural coverage but the fiscal-sequence narrative and composition_snapshot data quality require heal. Verdict: REQUIRES-HEAL with documented deploy gate before any commercial branch creation.*
