# G7-PURGE — Clone hygiene + the 6 NULL-tax ghosts (round-3)
**Agent:** G7-PURGE · **Date:** 2026-06-07 · **DB:** `foodking_e2e` (disposable clone) :8766 ONLY · operating `foodking` NOT touched.
**Verdict:** **PASS** — 6 NULL-tax ghosts confirmed soft-deleted/unsold + defensively bound to VAT-10; live 45-item catalogue 100% VAT-covered; 1257 non-fiscal stress/soak test orders purged conservatively with the NF525 chain intact and gap-free.

---

## 1. The 6 NULL-tax ghosts — CONFIRMED soft-deleted, unsold (round-1 §4/DBH-04 re-proven)

Query (clone):
```sql
SELECT id, name, tax_id, status, deleted_at FROM items WHERE tax_id IS NULL ORDER BY id;
```
Result — 6 rows, ALL `deleted_at='2026-05-28 19:32:39'` (soft-deleted), status=5:

| id | name | tax_id (before) | deleted_at |
|----|------|-----------------|------------|
| 16 | Bacon (Supplément) | NULL | 2026-05-28 19:32:39 |
| 28 | Bol Curry | NULL | 2026-05-28 19:32:39 |
| 29 | Bol Tandoori | NULL | 2026-05-28 19:32:39 |
| 30 | Bol Mariné | NULL | 2026-05-28 19:32:39 |
| 31 | Bol Crousti | NULL | 2026-05-28 19:32:39 |
| 32 | Bol Gratiné | NULL | 2026-05-28 19:32:39 |

`SELECT COUNT(*) total_null_tax, SUM(deleted_at IS NOT NULL) soft_deleted, SUM(deleted_at IS NULL) live_null` → **total=6, soft_deleted=6, live_null=0.**

**Not referenced by any non-test order** (proven, all = 0):
- `order_items WHERE item_id IN (16,28,29,30,31,32)` → 0
- JSON columns (`item_variations`/`item_extras` REGEXP) → 0
- `composition_snapshot` (`JSON_SEARCH ... 16/28/29/30/31/32`) → 0
- `item_branch_availability` → 0 · `offer_items` → 0 · `item_wizard_profiles` → 0

(They DO carry catalogue-config children — `item_variations`=75, `item_addons`=5 — i.e. they were real composer items before soft-delete; that is exactly why a restore is dangerous. None of those are order/fiscal references.)

---

## 2. Defensive fix APPLIED on clone — restore-safety (before → after)

Raw SQL (Eloquent would update 0 rows — SoftDeletes scopes them out):
```sql
UPDATE items SET tax_id = 3, updated_at = NOW()
WHERE id IN (16,28,29,30,31,32) AND tax_id IS NULL;
```
`tax_id=3` = taxes row `id=3, code='VAT-10%', tax_rate=10.000000` (confirmed in `taxes`).

| | before | after |
|---|--------|-------|
| 6 ghosts tax_id | NULL | **3 (VAT-10%)** |
| NULL-tax items anywhere (incl. trashed) | 6 | **0** |
| deleted_at on the 6 | 2026-05-28 (preserved) | 2026-05-28 (preserved — still soft-deleted) |

**Did NOT touch the 8 intentional 0%-supplements** (ids 4-11, tax_id=1) — the `tax_id IS NULL` gate excludes them by construction. Re-confirmed they remain tax_id=1 (round-1 DBH-04 warned `assign-menu-vat` would wrongly force these to 10%).

`fiscal:verify-chain --all` BEFORE and AFTER this write = **CHAIN OK** (write touched `items` only, not the fiscal chain).

---

## 3. Live catalogue — 100% on a non-null VAT rate (CONFIRMED)

```sql
SELECT COUNT(*) live_items, SUM(tax_id IS NULL) live_null_tax FROM items WHERE deleted_at IS NULL;
-- live_items=45, live_null_tax=0
SELECT tax_id, COUNT(*) FROM items WHERE deleted_at IS NULL GROUP BY tax_id;
-- tax_id=3 -> 45  (ALL 45 live items on VAT-10%)
```
**Live catalogue = 45 items, 100% on tax_id=3 (VAT-10%), zero NULL-tax.** No live NF525 violation. (This is the live-violation disproof: §4's "P1: 6 items NULL" is a soft-deleted-ghost residual, correctly P3.)

---

## 4. Test-pollution purge — CONSERVATIVE, fiscal-safe (1257 rows)

### Gate (intersection, not union)
Only deleted rows that are BOTH an **anchored** test-prefix token AND **non-fiscal**:
`(token LIKE 'STRESS-%' OR token LIKE 'SOAK-%') AND fiscal_sequence_no IS NULL` → **1257 rows** (kiosk, payment_status=10 unpaid, status=1, 2026-05-28 stress/soak campaign).

### HARD EXCLUSIONS (NOT touched)
- **636 fiscalized SOAK/STRESS orders** (629 SOAK + 7 STRESS carry `fiscal_sequence_no`) — deleting fiscalized rows would gap the NF525 chain on the baseline the supervisor sweeps. EXCLUDED.
- The `token` substring REGEXP hit 1897 — **false positive** (random base62 tokens contain "test"/"e2e" substrings); only **anchored** `LIKE 'PREFIX-%'` used.
- 4 `E2E-ADMIN-*` items (ids 61-64) — soft-deleted, unsold, tax_id=1 already set → harmless, left as-is.
- Pre-existing orphan `order_status_transitions` (order_ids 4119-4123, 4129-4130; round-1 DBH-03) — not mine, left as-is.

### FK-completeness (all 7 FK children of `orders` enumerated via information_schema)
Children referencing the 1257: `order_items`=1257, `order_quotes.consumed_order_id`=1257, `order_status_transitions`(no FK)=1184. All others (order_addresses, order_coupons, order_payments, webhook_events, orders.parent_order_id self-ref) = **0**. `audit_logs (resource='order')` refs = **0**.

### Executed in ONE transaction, children-first, identical predicate
| step | rows |
|------|------|
| del order_items | 1257 |
| del order_quotes (consumed_order_id) | 1257 |
| del order_status_transitions | 1184 |
| del orders | 1257 |
| **orders total** | **3471 → 2214 (−1257)** |

### POST-PURGE safety evidence
- `fiscal:verify-chain --all` → **CHAIN OK**.
- Fiscal sequence (branch 1): `min=1, max=2029, count=2029, gap_count=0, dup_count=0` → **gap-free + dup-free** (purge touched zero fiscalized rows, as designed).
- Live catalogue still **45 / 0-null**.
- New orphans from purge: `order_items`→orders orphan = **0**. (The 10 `order_status_transitions` orphans are the pre-existing round-1 DBH-03 set, ids 4119-4123/4129-4130, NOT in my purge id-range.)

---

## 5. OWNER ACTION — G7 (precise)

**G7-a (TVA policy):** Confirm takeaway-vs-dine-in VAT rate policy + the intended status of the 6 ghost items (purge for good, or keep restorable). Owner-decided — not a code matter.

**G7-b (restore-safety on operating `foodking`):** Apply the SAME defensive bind on the operating DB so a future restore of a Bol Gourmand / Bacon cannot resurrect a 0%-VAT sellable item. **The standard tool does NOT fix this** — round-1 proved `fiscal:assign-menu-vat --dry-run` sees **0** of these (SoftDeletes hides them). Use a `withTrashed()`-aware targeted update:
```sql
-- raw SQL (operating foodking), or an artisan command:
UPDATE items SET tax_id = 3, updated_at = NOW() WHERE tax_id IS NULL;
-- Eloquent equivalent: Item::withTrashed()->whereNull('tax_id')->update(['tax_id' => 3]);
```
First verify on `foodking` that `WHERE tax_id IS NULL` matches ONLY the soft-deleted ghosts (live catalogue must already be 0-null, as on the clone) — never widen to a rate-based rebind (that would wrongly bump the intentional 0%-supplements to 10%).

**Note:** the clone is disposable; the §2 write here is a PROOF, not a durable remediation — a re-clone wipes it. The durable fix is G7-b on `foodking`.

---

## Summary
6 NULL-tax ghosts = soft-deleted, unsold, now VAT-10-bound on the clone (0 NULL-tax anywhere). Live 45-item catalogue 100% VAT-covered. 1257 non-fiscal stress/soak test orders purged conservatively; NF525 chain CHAIN-OK and gap-free (min1/max2029/0-gap/0-dup) before and after; 636 fiscalized test rows deliberately preserved. Owner G7 = TVA policy + a `withTrashed()`-aware bind on operating `foodking` (the standard assign-menu-vat command cannot see these rows).
