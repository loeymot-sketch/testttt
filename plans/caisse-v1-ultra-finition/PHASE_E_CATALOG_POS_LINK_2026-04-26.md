# PHASE E — Catalogue To POS Completeness

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex after C.6 and B.2.

## Goal

Make catalogue data fully actionable in POS without moving pricing or availability truth to the frontend.

## Tasks

### E.1 `CV1-FEAT-POS-CATALOG-SEARCH`

Objective: provide branch-aware POS product search by name/barcode.

Allowlist:
- item search controller/service
- POS catalog component
- tests for search

Mandatory tests:
- `php artisan test --filter=PosCatalogSearchTest`
- `npx vitest run tests/js/posCatalogSearch.spec.js`

Exit criteria:
- search is branch-aware.
- unavailable items handled consistently.
- no price calculation in frontend.

### E.2 `CV1-FEAT-POS-BESTSELLERS-CONFIG`

Objective: remove hardcoded best-seller fallback names.

Allowlist:
- POS catalog component/store
- tests

Mandatory tests:
- `npx vitest run tests/js/posBestSellersFallback.spec.js`
- `rg -n "cayenne|terminator|double cheese" resources/js/components/admin/pos`

Exit criteria:
- no hardcoded product names.
- empty featured state is explicit or data-driven.

### E.3 `CV1-FIX-PRICING-MANDATORY-ADDONS`

Objective: enforce mandatory addon/variation selection in backend pricing.

Allowlist:
- `app/Services/Pricing/PricingService.php`
- pricing request/exception classes
- pricing tests

Mandatory tests:
- `php artisan test --filter='PricingServiceMandatoryAddonsTest|PricingServiceTest|PosKioskPricingParityTest'`

Exit criteria:
- required modifiers missing returns controlled 422.
- POS and kiosk receive consistent error mapping.

### E.4 `CV1-FEAT-POS-FAVORITES`

Objective: expose cashier favorites without new schema unless existing columns suffice.

Allowlist:
- item admin flag surface if existing
- POS catalog UI
- tests

Exit criteria:
- no duplicate design if a favorites system already exists.
- no schema migration unless gate exists.

### E.5 `CV1-MIGRATE-POS-TO-MENU-PROJECTION`

Objective: make POS consume `MenuProjectionService::forChannel('pos', branch_id)` or equivalent.

Allowlist:
- menu projection service/tests
- POS item/category endpoints
- POS store call sites

Mandatory tests:
- `php artisan test --filter='PosCatalogUsesProjectionTest|PosKioskPricingParityTest'`
- relevant Vitest POS catalog tests

Exit criteria:
- `channels` and `visible_on` are respected.
- branch availability remains correct.

### E.6 `CV1-FIX-POS-VISIBILITY-RESPECT`

Objective: POS must not show kiosk-only variations/extras.

Mandatory tests:
- `php artisan test --filter='PosVariationVisibilityRespectedTest|PosExtraVisibilityRespectedTest'`

Exit criteria:
- `ItemVariation.visible_on` respected.
- `ItemExtra.visible_on` respected.

## Deferred V1.5

- `V15-FEAT-CATEGORY-BRANCH-AVAILABILITY`
- `V15-FEAT-PRODUCT-MODIFIERS`
