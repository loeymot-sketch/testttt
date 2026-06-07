# LOCK — PricingService NULL tax_id fail-loud / configured-default fallback

**ID:** LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD_2026-06-07
**Date:** 2026-06-07
**Status:** DRAFT — OWNER GATE PENDING (closes GOAL §G gate **G7**, code half)
**Frozen file touched:** `app/Services/Pricing/PricingService.php` (CLAUDE.md §7 — Pricing SSOT; §8 NF525 pricing invariant)
**Author:** Claude orchestrator (LOCK-G7-PREP, round-4 under central supervisor)
**Branch:** `heal/pre-cloud-exec-2026-06-05`
**Cross-ref:** GOAL_100PCT_VALIDATION_LECAYENNE_2026-06-07 §4 ("6 items tax_id NULL") + §G G7 ·
CONVERGENCE_VERDICT.md (G7 = the lone GATED finding of round-3) ·
round-1 `02-DB-HIST.md` DBH-04 (live-violation disproof + the restore-safety residual) ·
round-3 `CLUSTER-KIOSK-ERRORS-CRUD.md` F-08-1 (code path UNCHANGED) ·
round-3 `AGENT.md` (G7-PURGE: defensive VAT-10 bind on the clone).

> **This LOCK is a PREPARED gate doc. NOTHING has been applied.** PricingService is
> untouched. The owner countersigns §10, picks throw-vs-fallback, and only THEN does
> the patch land via the sub-agent in §8. Until signed, the interim mitigation in §0bis
> (non-frozen `ItemRequest`) is the only available risk-reducer.

---

## §0 — Why a LOCK exists (the G7 defect, exactly)

`app/Services/Pricing/PricingService.php` is **frozen** (CLAUDE.md §7 — "Pricing SSOT
prices") because it is the single backend source of truth for every order's line price
and per-line VAT. NF525 (CLAUDE.md §8) requires 100% of prices and tax to be computed
here; the frontend sends only `item_id, quantity, option_ids`.

The defect (G7 / round-1 DBH-04 / round-3 F-08-1), at **lines 241–245**:

```php
$taxId  = (int) ($dbItem->tax_id ?? 0);   // NULL tax_id collapses to 0
$taxObj = $taxes[$taxId] ?? null;         // taxes has no row at key 0 → null
$taxName = $taxObj?->name;                // → null  (no tax name on the line)
$taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;   // ← THE DEFECT: silent 0%
$taxType = $taxObj ? (int) $taxObj->type : TaxType::FIXED;
```

When an item's `tax_id` is **NULL** (or any value with no matching `taxes` row), the
service **silently sells the line at 0% VAT with `tax_name = null`** — no exception, no
log, no flag. For a **VAT-registered** business (owner confirmed VAT-registered, GOAL §G
G1 ✅), that means an item can be sold collecting **no VAT** while presenting a fiscal
receipt — a silent under-declaration. The companion ingress, `app/Http/Requests/
ItemRequest.php:50`, allows `'tax_id' => ['nullable','numeric','not_in:0']`, so a NEW
item can be created with **no tax at all**, feeding exactly this path.

**Current live status (why this is GATED, not an active P0):** round-1 DBH-04 + round-3
G7-PURGE proved the live `foodking_e2e` catalogue is **45/45 items on `tax_id=3`
(VAT-10%), zero NULL-tax**; the only 6 NULL-tax rows (ids 16,28,29,30,31,32 = Bacon +
5 Bols Gourmands) are **soft-deleted ghosts**, unsold, and the `items` query in
PricingService (`PricingService.php:58`, no `withTrashed`) cannot resolve them — so
there is **no live violation today**. The defect is **latent**: it bites if (a) an owner
restores a soft-deleted ghost from trash (returns with `tax_id=NULL`), (b) a new item is
created with a NULL tax via the admin form, or (c) a direct DB write nulls a `tax_id`.
That is a real fiscal time-bomb, not an academic one — hence the prepared fix.

> ⚠️ The operating DB `foodking` is **out of scope / unverifiable** by the army (hard
> boundary — read-only, never browser-write). Owner gate **G4/G7-b** must confirm the
> operating catalogue is 0-null and apply the same defensive bind there.

---

## §0bis — INTERIM MITIGATION (NON-FROZEN — owner can take TODAY, no LOCK needed)

`app/Http/Requests/ItemRequest.php` is **NOT frozen**. Making `tax_id` **required** closes
the **new-item ingress** so no future item can be created (or updated to) NULL-tax via the
admin form:

```php
// app/Http/Requests/ItemRequest.php:50  (BEFORE)
'tax_id' => ['nullable', 'numeric', 'not_in:0'],

// (AFTER — interim, non-frozen)
'tax_id' => ['required', 'numeric', 'not_in:0', 'exists:taxes,id'],
```

- Adds `required` (no NULL on create/update) + `exists:taxes,id` (must be a real `taxes`
  row, so the value can never collapse to the unresolvable key-0 path).
- **Caveat 1 — shared create+update:** `ItemRequest::rules()` is shared by the item
  create AND update routes (it already uses `Rule::unique(...)->ignore($this->route('item.id'))`).
  So `required` applies to UPDATE too. **Before merging, confirm the admin item-update
  form always posts `tax_id`** (it should — the form binds the item's current tax) and run
  the item controller/feature tests; if any update path omits `tax_id`, use
  `'required_with:...'` or `'sometimes','required'` instead.
- **Caveat 2 — NOT a substitute for the LOCK.** This closes the *ingress* only. It does
  **NOT** close the calculation-time hole: a **restored soft-deleted ghost**, a **direct DB
  write**, a seeder, or a legacy NULL row still hits `PricingService.php:241-245` and sells
  silent-0%. The owner must read the interim as a *stopgap that buys time*, not a fix.
  Only the frozen patch below makes the SSOT itself fail-safe.

**Recommended owner action sequence:** apply §0bis NOW (today, no gate) → then countersign
this LOCK to land the durable frozen fix → then apply the `withTrashed()` defensive bind on
operating `foodking` (G7-b, round-3 AGENT.md §5).

---

## §1 — Scope of the frozen change (surgical, ONE block)

**Exactly one tax-resolution site exists in PricingService** (verified
`grep -nE 'taxes\[|tax_id|tax_rate|taxObj' app/Services/Pricing/PricingService.php`):
the inline block at **lines 241–245** (there is **no `resolveTax` method** — the resolution
is inline). The other `?? 0` hits in the file are addon/attribute/quantity math, NOT tax.
**Scope = these 5 lines only.** No method signature change, no new dependency, no change to
the snapshot builder, the discount math, or anything in `§7 frozen` fiscal services.

**THE CRITICAL DISCRIMINATOR (must be encoded exactly):** the fix fires **only when
`$taxObj === null`** (tax_id NULL / unresolvable). It must **NOT** fire on a legitimately
resolved 0%-rate row. There are **8 intentional 0%-supplements (item ids 4–11) bound to
`tax_id=1` = the real `taxes` row "No-VAT" `tax_rate=0`** (confirmed `TaxTableSeeder.php:24`;
round-1 DBH-04 explicitly warned about this). Those resolve a **non-null** `$taxObj` with
`tax_rate=0.0` — a legitimate 0%, NOT a defect. A naïve "if rate == 0 then throw/rebump"
would **break the intentional supplements**. The condition is `$taxObj === null`, never
`$taxRate === 0.0`.

---

## §2 — Before / after code sketch

**BEFORE (lines 241–245, frozen — current):**
```php
$taxId  = (int) ($dbItem->tax_id ?? 0);
$taxObj = $taxes[$taxId] ?? null;
$taxName = $taxObj?->name;
$taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;
$taxType = $taxObj ? (int) $taxObj->type : TaxType::FIXED;
```

**AFTER — Option F (FALLBACK, RECOMMENDED):** on unresolvable tax, fall back to the
configured default VAT rate (`config('menu.settings.default_tax_id')` — already `= 3` =
VAT-10% at `config/menu.php:80`) and **log loudly**. Never under-collects, never crashes a
live kiosk checkout.
```php
$taxId  = (int) ($dbItem->tax_id ?? 0);
$taxObj = $taxes[$taxId] ?? null;

// [LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD] NF525: an item with NULL / unresolvable
// tax_id must NEVER silently sell at 0%. Fall back to the configured default VAT row
// and log. Fires ONLY when no tax row resolved — a legitimate 0%-row (e.g. taxes id=1
// "No-VAT" for the intentional supplements) keeps $taxObj non-null and is untouched.
if ($taxObj === null) {
    $defaultTaxId = (int) config('menu.settings.default_tax_id');
    $taxObj = $taxes[$defaultTaxId] ?? null;
    Log::warning('[NF525] Item {item} has NULL/unresolvable tax_id; fell back to default VAT row {default}.', [
        'item' => $item->item_id, 'raw_tax_id' => $dbItem->tax_id, 'default' => $defaultTaxId,
    ]);
    if ($taxObj === null) {            // default row itself missing → fail loud, never 0%
        throw new \RuntimeException(
            "NF525: item {$item->item_id} has no resolvable tax_id and no default VAT row (config menu.settings.default_tax_id={$defaultTaxId}).",
            500
        );
    }
}
$taxName = $taxObj->name;
$taxRate = (float) $taxObj->tax_rate;
$taxType = (int) $taxObj->type;
```
> VERIFIED: `$taxes` is built at `PricingService.php:63` as
> `AppLibrary::pluck(Tax::get(), 'obj', 'id')` — the **full** `taxes` table (no `whereIn`
> filter), keyed by `id`. So `$taxes[$defaultTaxId]` (id=3) resolves as long as the seeded
> VAT-10% row exists (`TaxTableSeeder` guarantees it). Option F's primary fallback is
> therefore the live path; the inner `throw` is a true safety net that only fires if the
> default row itself is missing from the table.

**AFTER — Option T (FAIL-LOUD, stricter alternative):** refuse the order outright on
unresolvable tax. Safest against under-collection in a **multi-rate** setup (no guessing a
rate), but it **crashes a live checkout** if a NULL-tax item ever reaches the cart.
```php
$taxId  = (int) ($dbItem->tax_id ?? 0);
$taxObj = $taxes[$taxId] ?? null;
if ($taxObj === null) {
    throw new \InvalidArgumentException(
        "NF525: item {$item->item_id} has no resolvable tax_id (raw={$dbItem->tax_id}); refusing to price at 0% VAT.",
        422
    );
}
$taxName = $taxObj->name;
$taxRate = (float) $taxObj->tax_rate;
$taxType = (int) $taxObj->type;
```

**Owner picks F or T in §10.** Both are byte-identical to today on the 45 live VAT-10%
items AND on the intentional 0%-supplements (id=1) — the only behavioural change is the
previously-silent `$taxObj === null` branch.

---

## §3 — Frozen-zone touch + safety-check

`app/Services/Pricing/PricingService.php` is item #`PricingService` of `FROZEN_ZONES` in
`.cursor/hooks/safety-check.sh` (line 30). Staging it HALTs the commit pending a LOCK
(`safety-check.sh:44` — "Frozen zone staged … LOCK doc required"). This LOCK, committed in
the same PR as the patch, satisfies that gate. No other frozen file is touched. **No NF525
chain / signature / `audit_logs` / `z_reports` / `FiscalSequenceService` / `ZReportService`
logic is touched** — this is a tax-rate *resolution* fix on the input side, not a fiscal
chain change.

---

## §4 — Test that proves it (TO BE CREATED — TDD-first)

**NEW** `tests/Feature/Pricing/PricingServiceNullTaxResolutionTest.php` (the `Pricing`
feature dir currently holds only `TaxInclusivePricesTest.php`). **Three cases — the third
is the one that proves the fix is safe and is the easiest to omit:**

1. **`test_null_tax_id_does_not_silently_price_at_zero_percent`** — item with `tax_id=NULL`
   priced via `PricingService` →
   - Option F: line resolves to the default VAT rate (10%), `tax_amount > 0`, `tax_name`
     set, and a `Log::warning` was emitted (assert via `Log::shouldReceive`/fake).
   - Option T: `expectException(InvalidArgumentException::class)` + message match.
   RED on current code (returns `tax_rate=0.0`, `tax_name=null`, no throw/log).

2. **`test_live_vat10_item_is_byte_identical`** — item with `tax_id=3` (VAT-10%, the 45 live
   items) → `tax_rate=10.0`, `tax_name='VAT'/'VAT-10%'`, `tax_amount` unchanged vs pre-fix.
   GREEN before AND after (regression guard — proves no behavioural change for the live
   menu).

3. **`test_intentional_zero_percent_supplement_still_resolves_legitimately`** — item with
   `tax_id=1` (the real "No-VAT" 0% row, the 8 supplements ids 4–11) → `tax_rate=0.0`,
   `tax_name='No-VAT'`, **and NO exception / NO fallback-rebump fired**. This is the guard
   that the fix keys on `$taxObj === null`, not on `$taxRate === 0.0`. GREEN before and
   after; would go RED if a careless "rate==0 ⇒ throw" implementation were used.

**Regression to run alongside:** `vendor/bin/phpunit --filter Pricing` +
`vendor/bin/phpunit --filter 'Order|Frontend'` (PricingService is the SSOT for both order
paths) — expect zero new failures. NF525 chain `APP_ENV=e2e php artisan fiscal:verify-chain
--all` must remain CHAIN OK (no signed Z is mutated — this only changes pricing of *new*
NULL-tax lines, of which there are none live).

---

## §5 — Risk / blast radius

- **Live behaviour unchanged.** 45 live items are `tax_id=3`; the 8 supplements are
  `tax_id=1` (a real row). Neither hits the `$taxObj === null` branch. Every existing
  order, every signed Z, every live price = byte-identical. The fix only changes the
  previously-impossible-to-reach silent-0% branch.
- **Option F (fallback):** worst case is over-collecting on a genuinely-meant-0% item that
  was misconfigured as NULL — but a meant-0% item should be `tax_id=1`, not NULL, so this is
  the correct fail-safe direction (never under-collect). The loud log surfaces the
  misconfiguration for the owner to fix the data.
- **Option T (fail-loud):** a NULL-tax item reaching the cart returns 422/500 instead of
  mispricing — louder, but blocks a checkout. Acceptable because no live item is NULL-tax;
  the throw is a guardrail that should never fire in a correctly-seeded catalogue.
- **`composition_snapshot`** is built independently (`PricingService.php:270`) and is not
  affected by the tax-resolution branch.
- **No multi-rate regression:** the fix does not assume a single rate — it resolves the
  item's own `taxes` row (Option F falls back to the *configured* default only when none
  resolves; Option T refuses rather than guess).

---

## §6 — Rollback plan

- **Code:** `git revert` the single frozen-patch commit. The change is self-contained to
  lines 241–245 + the new test file; reverting restores the prior (silent-0%) resolution.
- **Data:** **none required.** The patch changes pricing only for NULL-tax lines, of which
  there are zero live; no historical order, no signed Z, no `audit_logs`/`z_reports` row is
  written or altered by this change, so a revert cannot leave fiscal state inconsistent.
- **If Option F's default row were ever wrong:** the loud `Log::warning` is the tripwire —
  search logs for `[NF525] … fell back to default VAT row`; any hit means a NULL-tax item
  was priced and the owner should fix that item's `tax_id` (and re-run the defensive bind).
- **Interim (§0bis) rollback:** revert the one-line `ItemRequest.php` rule change —
  independent atom, no schema/data impact.

---

## §7 — safety-check.sh override config

```yaml
LOCK_FILE:          app/Services/Pricing/PricingService.php
LOCK_LINES:         241–245 (single inline tax-resolution block) + new test file
LOCK_RATIONALE:     NF525 — NULL/unresolvable tax_id must not silently price at 0% VAT (G7)
OWNER_GATE:         REQUIRED (closes GOAL §G G7, code half)
NF525_IMPACT:       STRENGTHENS invariant (no chain/signature/audit_logs/z_reports touch;
                    only the input-side tax-rate resolution)
DISCRIMINATOR:      fires only when $taxObj === null; legitimate 0%-row (taxes id=1) untouched
ROLLBACK_COMPLEXITY: trivial (single git revert; no data/state restore)
APPLIES_LIVE:       no behavioural change on current catalogue (0 NULL-tax live items)
INTERIM_NONFROZEN:  ItemRequest.php tax_id nullable→required (closes ingress only — §0bis)
```

---

## §8 — Sub-agent instructions (ONLY after §10 sign-off)

```
Sub-agent: foodking-complex-implementer (surgical frozen patch — NOT routine-implementer).
Pre-req: §10 signed + owner picked Option F or Option T.

Step 1. TDD-first — write tests/Feature/Pricing/PricingServiceNullTaxResolutionTest.php
        (§4, all THREE cases). Run → case 1 RED on current code, cases 2 & 3 GREEN.
Step 2. Apply the chosen sketch (§2) to PricingService.php lines 241–245 ONLY.
        Confirm $taxes is keyed by id and contains the default row before relying on it.
Step 3. Run vendor/bin/phpunit --filter PricingServiceNullTaxResolution → 3 GREEN.
Step 4. Regression: vendor/bin/phpunit --filter Pricing ; --filter 'Order|Frontend'
        → zero new failures. APP_ENV=e2e php artisan fiscal:verify-chain --all → CHAIN OK.
Step 5. Frozen SHA-256 baseline for PricingService.php updated in the sentinel if one
        pins it; confirm frozen diff = ONLY PricingService.php (this LOCK) + new test.
Step 6. Commit the patch as a SEPARATE atom from this LOCK, message referencing
        LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD_2026-06-07, and close G7-code.
Never widen scope beyond lines 241–245. If $taxes turns out not to contain the default
row, STOP and escalate — do not silently keep 0%.
```

---

## §9 — Decision matrix

| Option | What | LOC | Live behaviour | Risk | Owner gate | Verdict |
|---|---|---|---|---|---|---|
| **F — fallback to default VAT** | NULL/unresolvable tax → `config('menu.settings.default_tax_id')` (=3, VAT-10%) + loud log; throw only if default row also missing | ~8 frozen + ~40 test | byte-identical (0 NULL-tax live) | never under-collects; over-collects only on a mis-seeded meant-0% item (which should be tax_id=1) | sign §10 | **RECOMMENDED** |
| **T — fail-loud** | NULL/unresolvable tax → throw 422, refuse to price | ~5 frozen + ~40 test | byte-identical (0 NULL-tax live) | safest vs under-collection (no rate guess); crashes a live checkout if a NULL-tax item ever reaches cart | sign §10 | stricter alt |
| **I — interim only (§0bis)** | `ItemRequest` `tax_id` required (non-frozen) | 1 (non-frozen) | closes new-item ingress only; calc-time hole stays open (restore/DB-write/seeder) | latent defect survives | **none** (do TODAY) | stopgap, NOT a fix |
| **D — defer** | keep as-is | 0 | silent-0% on any NULL-tax item | latent fiscal under-declaration | none | NOT acceptable for VAT-registered go-live |

---

## §10 — Owner sign-off (CLAUDE.md §10 human gate)

Required because this touches a **§7 frozen file** AND sets a **VAT policy** (throw vs
fallback rate) — a fiscal decision only the owner can make.

```
G7 — code half. Pick the frozen-fix option:

[ ] Option F — FALLBACK to configured default VAT row (=3, VAT-10%) + loud log  (RECOMMENDED)
[ ] Option T — FAIL-LOUD (refuse to price a NULL-tax line)
[ ] Defer    — accept latent silent-0% risk (NOT recommended for VAT-registered)

Interim (independent — recommended to apply immediately, no gate needed):
[ ] Apply §0bis ItemRequest tax_id nullable→required (after confirming item-update form posts tax_id)

G7 — data half (owner action, separate, see round-3 AGENT.md §5 / G7-b):
[ ] Confirm takeaway-vs-dine-in VAT policy + intended status of the 6 soft-deleted ghosts
[ ] On operating `foodking`: Item::withTrashed()->whereNull('tax_id')->update(['tax_id'=>3])
    (verify it matches ONLY the soft-deleted ghosts before running)

Owner signature : __________________________________
Date            : 2026-06-____
Patch commit ref: __________________________________
```

---

**Status: DRAFT — pending owner countersign.** Until signed, PricingService is untouched and
the only available risk-reducer is the non-frozen interim (§0bis). Nothing in this LOCK has
been applied.
