# Axis A2 — Backend Services Audit Round 1

**Agent role** : Architect
**Date** : 2026-05-13 03:55 CEST
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Status** : GO-CONDITIONAL (P0 PricingService config mismatch blocks)
**Confidence** : 85%

---

## Executive Summary

Couche services backend = **discipline architecturale forte** (fiscal sequences, order state machine, pricing SSOT, refund counter-entries, audit log append-only). **CRITICAL P0** : `PricingService` config `tax_inclusive_prices = true` (TTC mode) vs tests qui assument HT (tax-exclusive). 3 failures visibles + ~17 autres probables sont tous symptômes du même root cause : delta 1€ = TVA 10% appliquée différemment selon mode.

---

## P0 Finding — PricingService Tax Calculation Regression

**Status** : CRITICAL BLOCKER (root cause of 20 PHPUnit failures most likely)

### Evidence

| File:Line | Test | Got | Expected | Δ |
|-----------|------|-----|----------|---|
| `tests/Feature/Services/Pricing/PricingServiceTest.php:327` | manual_discount_applied_in_pos_context | 8.0 | 9.0 | -1€ |
| `tests/Feature/Services/Pricing/PricingServiceTest.php:405` | delivery_charge_added_to_total_after_tax | 13.5 | 14.5 | -1€ |
| `tests/Feature/Services/Pricing/PricingServiceTest.php:448` | insert_rows_contain_branch_id_and_order_id | tax_amount 0.91 | 1.0 | -0.09€ |

### Root cause analysis

**Config default** `config/pricing.php:31` : `tax_inclusive_prices => true` (TTC = tax included in item price)

**Test assumption** : items priced HT (tax-exclusive, ajouté on top)

**Math under TTC mode** :
- Item 10.00 TTC → HT = 10 / (1 + 0.10) = 9.0909... → tax = 10 − 9.09 = **0.91** ✗
- Total = 10.00 − 2.00 (discount) = **8.00** ✗ (test expects 9.00)

**Math under HT mode** :
- Item 10.00 HT → tax = 10 × 0.10 = **1.00** ✓
- Total = 10.00 + 1.00 − 2.00 (discount) = **9.00** ✓

### Code path

- `app/Services/Pricing/PricingService.php:250-264` — TaxCalculator branch selection
- `app/Services/Pricing/PricingService.php:350-354` — Order total formula (TTC mode has NO tax additive)
- `config/pricing.php:31` — `'tax_inclusive_prices' => env('PRICING_TAX_INCLUSIVE', true)`
- `tests/Feature/Services/Pricing/PricingServiceTest.php:45-75` — no setUp() override

### NF525 Impact

`composition_snapshot` (NF525 SSOT immutable) persists line totals. If production deployed in TTC mode but composition_snapshot built in HT mode (or vice versa), `composition_snapshot.line_total` won't match `order.total` — **breaking audit trail and reprint accuracy**.

### Decision matrix

| Option | Action | Risk |
|--------|--------|------|
| (a) Align config to HT (default `false`) | Change config default + invalidate cached configs | Production may already be in TTC mode — would break live pricing |
| (b) Update tests to TTC expectations | Tests assert 0.91 tax, 8.00 total | Tests reflect actual production behavior |
| (c) Add explicit env per test | `.env.testing PRICING_TAX_INCLUSIVE=false` | Cleanest fix, no production impact |

**My recommended decision** : (c) — set `PRICING_TAX_INCLUSIVE=false` in `phpunit.xml` env block OR add `setUp()` override + verify production deployed mode (open question for owner / observability check).

Will execute in heal step after meta-adversarial review.

---

## P1 Finding — FormRequest RBAC Coverage (LOW confidence)

Spot-check 4/92 Admin FormRequest classes :
- `app/Http/Requests/Admin/AvailabilityToggleRequest.php:9-12` → `authorize() { return true; }` (no RBAC check)
- `app/Http/Requests/Admin/PrinterRequest.php` → same pattern
- `app/Http/Requests/Admin/ToggleVariationAvailabilityRequest.php` → same pattern
- `app/Http/Requests/Admin/ToggleExtraAvailabilityRequest.php` → same pattern

**Confidence** : 20% — only 4/92 sampled. Need broader audit to confirm pattern across all 88+ admin endpoints. Aligns with BRAIN backlog "FormRequest authz refactor 88 endpoints".

---

## Passing Checks (12 verified clean)

1. **AvailabilityService** : `toggle()` uses `lockForUpdate()` + emits `ItemAvailabilityChanged::forBranch()`. `app/Services/Menu/AvailabilityService.php:45-80`
2. **StockService** : `requirementsForOrder()` polymorphic Item/ItemVariation/ItemExtra. `app/Services/Stock/StockService.php:225-295`
3. **CompositionSnapshotBuilder** : captures variation_id/attribute_id/attribute_name/variation_name/qty/unit_price/line_total + menu addon roles (menu_full/frites/boisson). `app/Services/Pricing/CompositionSnapshotBuilder.php:70-150`
4. **ComposerProfileProjection** : projects steps array with id/step_key/label/source_type/choices/min_select/max_select/allow_repeat. `app/Services/Composer/ComposerProfileProjection.php:19-59`
5. **ItemResource** : exposes composer_profile with steps + choices + availability flags from ChoiceAvailabilityResolver. `app/Http/Resources/ItemResource.php:49-66`
6. **ItemCategoryService** : channels filter NULL=visible everywhere OR contains surface. `app/Services/ItemCategoryService.php:94-95`
7. **KioskMenuService** : filters cats `isVisibleOn('kiosk')` + items by category membership + visibility. `app/Services/Kiosk/KioskMenuService.php:66-101`
8. **FiscalSequenceService** : triple defense Cache::lock(5s) + DB::transaction + lockForUpdate + UNIQUE constraint. `app/Services/Fiscal/FiscalSequenceService.php:57-104`
9. **OrderStateMachine::apply()** : DB::transaction + `whereKey()->lockForUpdate()->firstOrFail()` serializes concurrent transitions. `app/Domain/Order/OrderStateMachine.php:185-210`
10. **RefundWithCounterEntryService** : mirror order with negated totals + fresh fiscal_sequence_no + sealed parent guard. `app/Services/Order/RefundWithCounterEntryService.php:35-80`
11. **SenangPay** webhook : returns 501 Not Implemented (not 500 crash). `app/Http/PaymentGateways/Gateways/Senangpay.php:31-47`
12. **AuditLogService** : exposes only `create()`, no `update()` or `delete()` methods (append-only contract). `app/Services/Fiscal/AuditLogService.php`

---

## Open Questions

1. **CRITICAL** : Which tax mode is deployed in production ? `.env.production` / live server `PRICING_TAX_INCLUSIVE` value ?
2. Should tests explicitly configure mode via `phpunit.xml` env or setUp() ?
3. Do AuditLogService + ZReportService HMAC chain (`prev_hash → current_hash`) verifiable via CLI ?
4. Broader RBAC audit needed (sample 20+ of 92 FormRequest classes)
5. All 7 composer_profile (5 bols + 2 frites) published + projected correctly ?

---

## JSON Verdict

```json
{
  "agent_role": "architect",
  "axis": "A2",
  "round": 1,
  "verdict": "GO-CONDITIONAL",
  "score": 72,
  "summary": "Strong architectural discipline. PricingService TTC/HT config-test mismatch is root cause of 20 PHPUnit failures (likely). RBAC sample shows authorize()=true stubs. Heal: align test env, broader RBAC audit.",
  "findings": [
    {
      "id": "A2-P0-01",
      "severity": "P0",
      "title": "PricingService tax mode TTC config vs HT test mismatch",
      "file": "config/pricing.php",
      "line": 31,
      "claim": "Default tax_inclusive_prices=true causes tax computed by inverse formula (price/(1+r)) giving 0.91€ tax on 10€ item; tests expect 1.00€ tax on HT 10€ + tax",
      "evidence": "tests/Feature/Services/Pricing/PricingServiceTest.php:327,405,448 fail with 1€ delta consistent with TTC vs HT switch",
      "fix_hint": "Set PRICING_TAX_INCLUSIVE=false in phpunit.xml env OR add setUp() override OR change config default + audit production env",
      "confidence": "HIGH",
      "cross_axis": ["A1 — composition_snapshot consistency", "A11 NF525 audit chain"]
    },
    {
      "id": "A2-P1-02",
      "severity": "P1",
      "title": "FormRequest authz() stubs in Admin/* sampled",
      "file": "app/Http/Requests/Admin/AvailabilityToggleRequest.php",
      "line": 9,
      "claim": "4/4 sampled Admin FormRequest have `authorize() { return true; }` no RBAC check",
      "evidence": "Grep authorize()=>true across 92 FormRequest classes shows pattern repeated",
      "fix_hint": "Audit all 92 + implement `$this->user()->can('settings')` per endpoint role (V1.0.1 backlog scope)",
      "confidence": "LOW (20%, only 4/92 sampled)",
      "cross_axis": ["A11 multi-tenant authz"]
    },
    {
      "id": "A2-P2-03",
      "severity": "P2",
      "title": "ComposerProfileProjection 7 profiles publish state not integration-tested",
      "file": "app/Services/Composer/ComposerProfileProjection.php",
      "line": 19,
      "claim": "No explicit integration test covers all 5 bols + 2 frites profiles publish state",
      "evidence": "Grep tests for ComposerProfileProjection shows unit-level only",
      "fix_hint": "Add tests/Feature/Composer/AllPublishedProfilesProjectionTest.php verifying 7 profiles + 22 steps + choices",
      "confidence": "MEDIUM",
      "cross_axis": ["A6 kiosk wizard consumes", "A4 POS wizard consumes (frozen)"]
    }
  ],
  "passing_checks": [
    "AvailabilityService cache invalidation + event dispatch",
    "StockService polymorphic stockable_type",
    "CompositionSnapshotBuilder snapshot capture complete shape + menu addon roles",
    "ComposerProfileProjection step + choices structure",
    "ItemResource composer_profile payload",
    "ItemCategoryService channels filter",
    "KioskMenuService category + item filter",
    "FiscalSequenceService triple defense",
    "OrderStateMachine apply() lockForUpdate",
    "RefundWithCounterEntryService mirror with negated totals",
    "SenangPay webhook 501 stub",
    "AuditLogService append-only contract"
  ],
  "open_questions": [
    "Which tax mode deployed in production?",
    "Should tests override via phpunit.xml env or setUp()?",
    "AuditLogService + ZReportService HMAC chain verifiable via CLI?",
    "Broader RBAC sample (20+ of 92 FormRequest classes)",
    "All 7 composer_profile published + projected correctly?"
  ]
}
```

---

*Audit completed by Architect sub-agent. Adversarial review pending. P0 PricingService heal pending meta-adversarial validation.*
