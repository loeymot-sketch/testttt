# Plan – MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016 – 2026-10-16

## TASK_ID

MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016

## PRIMARY_EXECUTION_MODEL

gpt-5.5-pro

## REASONING_EFFORT

xhigh

## PRIOR_CONTEXT

- La Phase 17 avait aligné l'application sur les vignettes WebP historiques.
- La source actuelle `config/menu_images.php` du site/borne résout les produits
  concernés vers les PNG pleine définition de `public/images/menu/`.
- La Phase 21 E2E reste en REWORK historique ; cette demande propriétaire ouvre
  explicitement un nouveau lot borné d'assets produit, sans reprendre son scope.

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: multi_agent_v1 reviewer Avicenna
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Mobile product asset mapping | latest site/kiosk PNG mappings for sandwich, galette, tacos | Write | No | No |
| Mobile asset pack | byte parity copies already present or synchronized from public pack | Write if needed | No | No |
| Mobile catalogue tests | static SHA and critical E2E image contracts | Write | No | No |
| QA cycle artifacts | task, mission, report and cycle trace | Write | No | No |

## SOURCES_READ_ONLY

- `public/images/menu/`
- `config/menu_images.php`

These canonical site/kiosk sources are read-only verification inputs and are
not part of the writable scope.

## CANONICAL_MAPPING_ALLOWLIST

| Mobile references | Canonical PNG |
|---|---|
| Cayenne | `sandwich-cayenne.png` |
| Suprême | `sandwich-supreme.png` |
| Méga | `sandwich-mega.png` |
| Terminator | `sandwich-terminator.png` |
| Galette Normale, Galette Cayenne, pain-galette | `galette.png` |
| Tacos M, Tacos L, Tacos XL | `tacos-cayenne.png` |
| Category Sandwichs, Galette, Tacos | `cat-sandwich-cayenne.png`, `cat-galette.png`, `cat-tacos.png` |

There are nine product references and six unique product files. Any missing
source, unexpected mapping or SHA mismatch is an ESCALATION; no substitution
is permitted.

## SUBSYSTEMS_OFF_LIMITS

Backend PHP/config, pricing/order data, database/migrations, payment/Mollie,
loyalty policy, auth/SMS, branch isolation, dispatch, OrderService,
FrontendOrderService, POS/caisse/KDS/kiosk runtime and native iOS packaging.

## INVARIANTS_AT_RISK

- Backend pricing remains the only price authority; no catalog business fields
  may change.
- `OrderStatus`, `branch_id`, dispatch and order services are untouched.
- No generated or cross-brand asset substitution.
- Existing unrelated dirty worktree changes remain untouched.

## GATE_CONDITIONS

None anticipated. If the canonical PNG is absent or its source mapping differs
from `config/menu_images.php`, stop and add an ESCALATION instead of guessing.

## Execution Steps

1. Verify every scoped mobile PNG against `public/images/menu/` and record SHA
   parity; synchronize only missing/different copies.
2. Change `mobile/data/menu.js` product, hero, category and `pain-galette`
   mappings from historical WebP paths to the canonical PNG paths.
3. Update the static and Playwright catalogue contracts to assert the PNG
   source, exact bytes, navigation and read-only network behavior.
4. Run syntax checks, static catalogue validation, targeted Playwright E2E,
   `git diff --check`, safety check, post-execute hook and scope guard.

## SYMMETRY_NOTE

N/A — no order service or frontend order service is in scope.

## SCOPE_PRESSURE

No scope expansion. The requested beverage list, payment/Mollie and loyalty
retention remain separate owner/gate decisions.

## ESCALATION

None.

## Audit Status

[ ] Pending
[x] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened
