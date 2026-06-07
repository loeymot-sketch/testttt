# LOCK-G7-PREP — round-4 report

**Agent:** LOCK-G7-PREP · **Date:** 2026-06-07 · round-4 under central supervisor
**Task:** PREPARE a frozen-zone LOCK doc for the G7 finding (do NOT apply). Verdict: **DONE — gate doc ready for owner countersign.**

## Deliverable
**LOCK doc:** `plans/LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD_2026-06-07.md` (10 sections, project lock-plan pattern, mirrors `LOCK_PRICING_W5_DB_UPDATE_TRIGGER_2026-05-18.md`).
Status: **DRAFT — OWNER GATE PENDING.** Nothing applied; PricingService untouched.

## The G7 defect (verified, not inferred)
- **Frozen file:** `app/Services/Pricing/PricingService.php` (CLAUDE.md §7 Pricing SSOT, §8 NF525; item #`PricingService` in `.cursor/hooks/safety-check.sh:30`).
- **Exact site:** lines **241–245** (inline; **no `resolveTax` method** — grep-confirmed single tax-resolution site in the file). `$taxId=(int)($dbItem->tax_id ?? 0); $taxObj=$taxes[$taxId]??null; $taxRate=$taxObj?...:0.0;` → NULL/unresolvable tax_id ⇒ **silent 0% VAT, tax_name=null**, no throw/log.
- **Ingress:** `app/Http/Requests/ItemRequest.php:50` — `'tax_id'=>['nullable','numeric','not_in:0']` allows a new item with no tax.
- **Why GATED not active-P0:** round-1 DBH-04 + round-3 G7-PURGE proved live catalogue = 45/45 on `tax_id=3` (VAT-10%), zero NULL-tax; the 6 NULL-tax rows (ids 16,28,29,30,31,32) are soft-deleted ghosts the PricingService query can't resolve. Latent — bites on a restore-from-trash / new-item / direct DB write.

## What the LOCK specifies
- **Scope:** the single 5-line block (241–245) only. No fiscal-chain/signature/audit_logs/z_reports touch.
- **Critical discriminator (caught via advisor):** fix fires **only when `$taxObj === null`**, NOT when `$taxRate === 0.0`. The 8 intentional 0%-supplements (ids 4–11) are bound to `tax_id=1` = real `taxes` "No-VAT" 0% row (`TaxTableSeeder.php:24`) — they resolve a non-null `$taxObj` and MUST stay byte-identical. A naïve "rate==0 ⇒ throw" would break them (round-1 DBH-04 warned this).
- **Before/after sketch:** two options. **Option F (RECOMMENDED)** = fall back to `config('menu.settings.default_tax_id')` — verified `=3` (VAT-10%) at `config/menu.php:80` — + loud `Log::warning`, throw only if default row also missing. **Option T** = fail-loud 422. Owner picks in §10.
- **Test (TO BE CREATED):** `tests/Feature/Pricing/PricingServiceNullTaxResolutionTest.php`, **3 cases** — NULL→fix (RED today), `tax_id=3` byte-identical (regression), **`tax_id=1` 0%-supplement still legit, no throw** (the safety guard).
- **Rollback:** single `git revert`; **no data restore** (no NULL-tax line live, no signed Z mutated).
- **safety-check override config** (§7) + **sub-agent instructions** (§8, foodking-complex-implementer, TDD-first, scope-locked) + **decision matrix** (§9) + **owner sign-off** (§10, CLAUDE.md §10 human gate — frozen + VAT policy).

## INTERIM MITIGATION (non-frozen — owner can take TODAY)
`app/Http/Requests/ItemRequest.php` is **NOT frozen**. Change line 50:
`'tax_id'=>['nullable','numeric','not_in:0']` → `['required','numeric','not_in:0','exists:taxes,id']`.
- Closes the **new-item ingress** (no future NULL-tax item via admin form).
- **Two caveats flagged in the LOCK:** (1) `ItemRequest::rules()` is **shared create+update** (uses `->ignore($this->route('item.id'))`) so `required` hits UPDATE too — confirm the item-update form posts `tax_id` + run item controller tests before merge (else use `sometimes`/`required_with`). (2) **NOT a substitute** for the frozen fix — it does not close the calc-time hole (restore-from-trash / direct DB write / seeder still hit silent-0%).

## Cross-references in the doc
GOAL §4 + §G G7 · CONVERGENCE_VERDICT.md (G7 = lone GATED round-3 finding) · round-1 `02-DB-HIST.md` DBH-04 · round-3 `CLUSTER-KIOSK-ERRORS-CRUD.md` F-08-1 · round-3 `AGENT.md` G7-PURGE (defensive bind + G7-b operating-DB action).

## Boundaries respected
- PricingService.php **NOT edited**. ItemRequest.php **NOT edited** (interim only proposed). No mutation. No commit/push.
- Advisor consulted before writing; its 5 points (discriminator, accurate-to-code/no-resolveTax, 3-case test, throw-vs-fallback=owner-call, honest-interim) all encoded.

## Owner next steps
1. (optional, now) apply §0bis interim after confirming item-update posts tax_id.
2. Countersign LOCK §10, pick Option F or T.
3. Sub-agent lands the frozen patch (§8) → closes G7-code. G7-data (TVA policy + operating-DB defensive bind) remains owner action.
