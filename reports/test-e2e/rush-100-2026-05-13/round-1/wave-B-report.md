# Wave B — POS V4 capture report (rush-100 round-1)

**Run** : 2026-05-13 09:47-09:49 UTC
**Spec** : `tests/e2e/rush-100-pos-capture.spec.js`
**Playwright** : 6/6 PASS in 2m13s
**Surface** : `/admin/pos-v4` (composer-aware wizard via `public/js/pos-wizard.js`)

## Coverage

| State | Path | Captures |
|---|---|---|
| Login + admin landing | `/login` → `/admin/dashboard` | 2 |
| POS V4 ready | `/admin/pos-v4` | 1 |
| 5 scenarios × 6 states | S6, S8, S3, S4, S10 | 29 |

Total 32 PNG quartets (png + dom.html + console.json + network.json) in `tests/e2e/__screenshots__/rush-100/pos/`.

## DB checks (orders persisted)

`wave-B-db-checks.json` recorded. Mapping :

| Scenario | Item | Pay HTTP | Order ID | fiscal_seq | Total DB | Persisted? |
|---|---|---|---|---|---|---|
| S6 Big Tacos | id 479 (11.50€) | 200 | **1324** | **294** | 11.50€ | YES |
| S8 Bol Gratiné | id 484 (12.50€) | 200 | 1324 (same) | 294 | 11.50€ | **NO — 429** |
| S3 Galette Cayenne | id 476 (7.00€) | 200 | 1324 (same) | 294 | 11.50€ | **NO — 429** |
| S4 Sandwich Classique | id 477 (7.00€) | 200 | 1324 (same) | 294 | 11.50€ | **NO — 429** |
| S10 Multi-cart | id 474+486+405 | 200 | **1325** | null | 2.50€ | PARTIAL |

Only S6 produced a fully-fiscal-valid persisted order. S10 produced a new row id 1325 but with item_id=485 (Petite Frites 2.50€) instead of the intended Sandwich Cayenne — the spec's tile-fallback selector matched the first visible tile when the data-item-id was offscreen.

## Throttle finding (NOT a P0)

S6-05/S8-05/S3-05/S4-05 receipt screenshots show the toast **"Trop de requêtes — patientez 30s avant de réessayer"**. Network logs confirm `POST /api/admin/pos → 429`. Three rate limiters guard this endpoint: `admin-mutation` (30/min) + `pos-order-create` (60/min) + `idempotency`. With the admin user firing ~6 calls/scenario × 5 scenarios in <90s, the `admin-mutation` ceiling triggered after S6. The spec calls `clearFoodKingRateLimits()` before each scenario but the bucket re-fills mid-scenario before the final confirm-payment POST.

Production behaviour : correct (limits protect against burst-double-submit). Test pattern : would benefit from a `--throttle-bypass` env knob or per-scenario 30s sleep — not a code defect.

## Findings flagged (for adversarial review)

- **F-W-B-01 [P2]** : Cart fallback selector picks first visible tile when `data-item-id` not matched (S10 picked Petite Frites instead of Sandwich Cayenne id 474). The Sandwich Cayenne category 344 was visible in sidebar but the tile may not render in the default view. **Action** : adversarial reviewer should compare `S10-02-wizard-popup.png` against intent.
- **F-W-B-02 [P2]** : Confirmation receipt overlay does not visibly close before next scenario starts; second navigation to `/admin/pos-v4` reloads the surface but is a fragile state. No P0.
- **F-W-B-03 [P3]** : The "Toutes les ..." category pill is selected by default and shows ALL items mixed — S6 success path (Big Tacos tile) is reached only because it appears in the "all" grid, not because the spec navigated to Tacos category 306. Reduced data-driven confidence for selector regression on multi-category catalogue.
- **NF525 invariant OK** : order 1324 has `fiscal_sequence_no=294` (gap-free with prior 293 at id 1302). Chain healthy on the one persisted real order.
- **No A03-1 menu addon mirror bug** observed in DOM dumps (`S6-02-wizard-popup.dom.html` shows the wizard but did not reach a menu+frites+boisson step — composer didn't compose).

## Frozen-zone integrity

`public/js/pos-wizard.js` not modified (verified `git status -s` clean for that file). All wizard interactions used Vanilla DOM selectors (`#item-variation-modal`, `[data-action="add-to-cart"]`, `.wizard-btn-cart`) read-only.

## Status

Wave B captures complete and durable. Recommend adversarial supervisor cross-inspect the 32-state quartet and confirm visual completeness before round-2 (which should rerun under 30s spacing or apply a one-off throttle bypass).
