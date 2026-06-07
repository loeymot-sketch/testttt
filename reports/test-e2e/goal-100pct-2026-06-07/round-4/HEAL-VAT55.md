# HEAL-VAT55 — Create the legal French reduced VAT rate 5,5 % (GOAL 100% / G7 code-side)

**Date:** 2026-06-07 · 5,5%-VAT-rate prep agent (round-4, central supervisor)
**Branch:** `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`)
**Scope:** ADDITIVE, non-frozen — create a correctly-valued `taxes` row. NO item re-pointed (owner gate G7).
**Verified on:** `foodking_e2e` CLONE only (NEVER the operating `foodking` DB).
**Commit:** nothing committed (supervisor commits).

---

## The finding (legal fact, not a business decision)

The French reduced VAT rate ("taux réduit") for applicable food/drink — **sealed
bottles/cans, bottled water, conservable/packaged cold items** — is **5,5 %** (CGI
art. 278-0 bis). The seeded `taxes` table (Bangladeshi GST-template legacy) had:

| id | name | code | rate | note |
|----|------|------|------|------|
| 1 | No-VAT | VAT-0 | 0,000000 | intentional 0% supplements |
| 2 | VAT | VAT-5% | **5,000000** | ⚠️ **LEGALLY WRONG for France** — see below |
| 3 | VAT | VAT-10% | 10,000000 | the 45 live menu items |
| 4 | GST | GST-5% | 5,000000 | unused (non-FR template) |
| 5 | GST | GST-10% | 10,000000 | unused (non-FR template) |

**`id2` is 5,0 %, NOT 5,5 %.** Binding any conservable/bottled product to id2 would
**under-collect** vs the legal 5,5 % — a fiscal under-declaration for a VAT-registered
business (E.DELICE SAS). And there was **NO 5,5 % row at all**. Currently LATENT (all
45 live items are on id3=10%, no live item uses id2), but a correct 5,5 % rate must
EXIST before any reduced-rate SKU can be sold compliantly.

> **id2 (5,0 %) is left UNTOUCHED on purpose.** It may be referenced by historical
> data; deleting/repurposing it could break audit traceability. It is simply legally
> wrong for France and should be **deprecated / ignored** (owner + accountant decision)
> — NOT silently used. The correct reduced rate to bind SKUs to is the NEW 5,5 % row.

---

## What was changed (2 files, both NON-frozen)

1. **NEW migration** — `database/migrations/2026_06_07_120000_add_french_reduced_vat_5_5_tax_row.php`
   - ADDITIVE: `Tax::updateOrCreate(['code' => 'VAT-5.5%'], [...])` → inserts ONE row
     `name='VAT 5.5'`, `code='VAT-5.5%'`, `tax_rate=5.5` (decimal 13,6 → `5.500000`),
     `type=TaxType::PERCENTAGE` (10), `status=Status::ACTIVE` (5).
   - Matches the existing series exactly and uses enum constants like its neighbors.
   - Logs to the `fiscal` channel on apply (audit trail).
   - `down()` removes the row **only if unused** (no item references it) — a rollback
     can never orphan a live `tax_id`; if the owner has bound a SKU to 5,5 %, down() is
     skipped + warns.
   - Does NOT touch id2, the 45 live items, or any other row. Does NOT assign the rate
     to any item (owner gate G7).

2. **Seeder** — `database/seeders/TaxTableSeeder.php` (fresh installs get the rate too)
   - Appended the same 5,5 % row after the GST rows, keyed by `code='VAT-5.5%'`, using
     the seeder's existing `Tax::updateOrCreate(['code'=>...])` loop. Comment flags that
     id2 (5,0 %) is legally wrong and reduced-rate SKUs must bind to the 5,5 % row.

### Idempotency — the one real trap, handled

`taxes.code` has **NO unique DB index** (verified: create migration declares
`string('code')` with no `->unique()`; no later migration adds one). So `insertOrIgnore`
would **NOT** dedupe and would create a DUPLICATE 5,5 % row on re-run. Idempotency is
therefore done via an **existence-check** — `Tax::updateOrCreate(['code' => ...])` — the
exact pattern `TaxTableSeeder` already uses. Proven below: running the upsert logic twice
yields exactly ONE row.

---

## Clone verification (foodking_e2e — `DB: foodking_e2e` confirmed on every command)

### taxes table AFTER migration
```
1 | No-VAT | VAT-0    | rate=0.000000  | type=10 | status=5
2 | VAT    | VAT-5%   | rate=5.000000  | type=10 | status=5   (legally wrong, UNTOUCHED)
3 | VAT    | VAT-10%  | rate=10.000000 | type=10 | status=5
4 | GST    | GST-5%   | rate=5.000000  | type=10 | status=5
5 | GST    | GST-10%  | rate=10.000000 | type=10 | status=5
6 | VAT 5.5| VAT-5.5% | rate=5.500000  | type=10 | status=5   ← NEW (correct reduced rate)
```

### 5,5 % row asserts
- exists: **YES** id=6
- `tax_rate` raw = `5.500000`, `(float)===5.5` = **true**
- `type==PERCENTAGE(10)` = **true** · `status==ACTIVE(5)` = **true**
- count of `VAT-5.5%` rows = **1**

### Live catalogue UNCHANGED (the assertion that proves nothing was silently re-pointed)
- live items = **45** (expect 45)
- items on `tax_id=3` = **45** (expect 45)
- live NULL-tax items = **0** (expect 0)
- items on the NEW row (id=6) = **0** (expect 0 — owner has NOT assigned it; gate G7)

### Pricing path computes correctly (HT × 1.055)
PricingService builds `$taxes = AppLibrary::pluck(Tax::get(),'obj','id')` (the full table
keyed by id). After the migration the map includes id=6:
- resolved via taxes map (key id=6): **YES** rate=`5.500000`
- HT 10.00 @ 5,5 % → tax = **0.55**, TTC = **10.55** (= HT × 1.055) ✓
- existing id=3 still resolves at `10.000000` (no collateral change) ✓

### Idempotency proofs
- **Migration tracker:** second `migrate` → `INFO Nothing to migrate.`
- **down()/up() reversibility:** rollback removed the unused row (0 rows) → re-migrate re-added exactly 1.
- **Upsert existence-check (the insertOrIgnore trap):** running `Tax::updateOrCreate(['code'=>'VAT-5.5%'],…)` **twice** → still **1** row, total taxes still **6**. (This is what `insertOrIgnore` would have silently failed, given no unique index.)

---

## Frozen-clean + tests

- **Frozen-zone diff** (`PricingService`, `Fiscal/*`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`, `pos-wizard.js/.css`, `admin-pos-v4.blade.php`, kiosk components): **EMPTY — 0 lines**.
- Only files touched this task: `database/migrations/2026_06_07_120000_add_french_reduced_vat_5_5_tax_row.php` (new) + `database/seeders/TaxTableSeeder.php` (modified).
- `vendor/bin/phpunit --filter FrozenZoneSha256BaselineSentinel` → **1/1 PASS** (5 assertions).
- `vendor/bin/phpunit --filter Tax` → **47/47 PASS** (97 assertions).
- `vendor/bin/phpunit --filter Pricing` → **105/105 PASS** (267 assertions) — pricing SSOT regression-free with the new row present.
- NEVER ran `php artisan test` (shared-infra DEVDB footgun). All commands `APP_ENV=e2e` → `foodking_e2e` clone.

---

## What remains the OWNER's decision (gate G7 — NOT done here)

- **Does Le Cayenne sell any drink in a sealed bottle/can, or any conservable cold/
  packaged item?** If **YES** → bind those SKUs to the new 5,5 % row (id=6 on the clone;
  the operating `foodking` id will be whatever the migration assigns there). If **NO** →
  10%-only stays correct and the 5,5 % row simply sits unused-but-ready.
- Deprecating/ignoring id2 (5,0 %) formally with the accountant.
- Applying this migration to the operating `foodking` DB (Claude must not write the
  operating fiscal data — owner pulls the trigger).

---

## VERDICT

- 5,5 % row added? **YES** (id=6 on clone, `tax_rate=5.500000`, PERCENTAGE/ACTIVE).
- Idempotent? **YES** (updateOrCreate-by-code; proven 1 row after 2× upsert + re-migrate no-op; insertOrIgnore trap avoided — no unique index on `taxes.code`).
- Live catalogue unchanged? **YES** (45/45 on tax_id=3, 0 NULL, 0 items on the new row).
- Frozen-clean? **YES** (0 lines in §7 files; FrozenZoneSha256BaselineSentinel 1/1).
- Tests: Pricing 105/105, Tax 47/47, sentinel 1/1 — all GREEN.
- Item assignment = **owner gate G7** (deliberately not done).

**Status: DEFECT-FIX COMPLETE on the clone, frozen-clean, NOT committed (supervisor commits).**
