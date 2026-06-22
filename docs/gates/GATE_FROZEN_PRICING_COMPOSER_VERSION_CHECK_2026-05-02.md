# Gate Brief — `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` — 2026-05-02

**TASK_ID:** `CV1-LIFECYCLE-UX-001` — sub-action 2.2
**Author:** Claude (in-session orchestrator, AUDIT_FALLBACK = cursor-session due to terminal Anthropic Pro quota)
**Plan reference:** `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md` §2.2 (lines 273-298)
**Audit reference:** `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` §B.5 (composer-version race)

---

## Trigger

Plan §2.2 requires extending `PricingService::validateComposerSelections` to detect a stale `composer_profile_version_at_open` submitted by the kiosk/POS client and return **HTTP 409 Conflict** with a remediation payload. This **edits a frozen zone** (`app/Services/Pricing/PricingService.php`), which is gated per `.cursor/rules/project-invariants.mdc` §6 and `.cursor/rules/human-gates.mdc` (Frozen zone file edit required).

## Affected Subsystems

| Subsystem | File | Read / Write | Notes |
|---|---|---|---|
| Pricing (FROZEN) | `app/Services/Pricing/PricingService.php` | **WRITE** | Add a *gated* version-check branch inside `validateComposerSelections`. |
| HTTP request validation | `app/Http/Requests/OrderRequest.php` | WRITE | Optional `composer_profile_version_at_open` (`integer\|nullable`). |
| Kiosk cart store | `resources/js/store/modules/kioskCart.js` | WRITE | Capture published version at wizard open, pass to checkout. |
| POS cart store | `resources/js/store/modules/posCart.js` | WRITE (symétrie) | Same capture path. |
| Frontend modal (new) | `resources/js/components/frontend/kiosk/ComposerProfileVersionConflictModal.vue` | NEW | Renders the 409 remediation payload. |
| Sentinel | `tests/Feature/Composer/ProfileVersionMismatchTest.php` | NEW | Reproduces the race; asserts 409 shape. |

**Off-limits for this gate clearance:** any other frozen file (`Payments/*`, `Orders/OrderService::create` core, fiscal archive code).

## Invariants at Risk

1. **#1 Backend Pricing SSOT** — *not violated*: change is **server-side**; the version check happens before pricing logic returns. No frontend-derived price.
2. **#5 OrderService / FrontendOrderService symmetry** — at risk: both surfaces submit composer carts; the version field must be honored symmetrically. Sentinel must cover both.
3. **#6 Frozen zone** — directly invoked: hence this gate.
4. **Dispatch-after-commit** — not at risk: rejection happens **before** order creation transaction.

## Decision Required

Authorize a *gated, flag-driven* extension of `PricingService::validateComposerSelections` that:

1. Accepts an optional `composer_profile_version_at_open` from the request payload.
2. Compares it to the currently published `ItemWizardProfile::version` for each line item.
3. If `submitted_version !== current_version`:
   - Emit a `ValidationException` (or controller-level `ConflictHttpException`) with HTTP 409 and JSON body:
     ```json
     {
       "error_code": "composer_profile_version_changed",
       "item_id": 42,
       "current_version": 3,
       "submitted_version": 2,
       "removed_options": [ { "step_id": 1, "choice_id": 7 } ],
       "added_options": [ { "step_id": 1, "choice_id": 9 } ]
     }
     ```
4. Behavior is **gated** by `config('catalog_v15.composer_profile_version_check.enabled', false)` — already wired in `config/catalog_v15.php` lines 103-113. Default OFF: zero behavior change without explicit flag flip.
5. Rollback: set the flag to `false` via env, no redeploy needed.

## Options

1. **Approve the gate as scoped above.** Implementation proceeds via `codex-extension` complex EXECUTE. Estimated: 1 file modified in frozen zone (`PricingService.php`, +~40 lines, single new private method + branch in existing path), 5 files modified outside frozen zone (request validation, 2 stores, modal, sentinel), 1 new sentinel file, 1 new modal file. Total diff target ≤300 lines.

2. **Approve a narrower scope: server-only.** Implement only the backend 409 response; defer the frontend modal + cart-store capture to a follow-up cycle. Reduces frozen-zone exposure to a single behavioral change, smaller blast radius. Front-end behavior remains unchanged until the second cycle.

3. **Defer to V2.** Do not touch the frozen zone in CV1-LIFECYCLE-UX-001. Document the open race condition as a known limitation in `docs/audit/V1_KNOWN_LIMITATIONS.md`. Remediation: server logs the mismatch at order submit (read-only, non-blocking) so we observe frequency in production before deciding.

## Test Strategy (post-clearance)

- **Sentinel:** `tests/Feature/Composer/ProfileVersionMismatchTest.php` — 5 cases: flag-off no-op, version match passes, version mismatch returns 409 with payload, missing field accepts (backward compat), POS+Kiosk symmetry.
- **Vitest:** `tests/js/composerProfileVersionConflictModal.spec.js` — 2 cases: renders payload, emits cancel/continue.
- **UAT staging:** 7 days minimum at staging, flag ON, observe error rate before production rollout.
- **Production rollout:** flag-flip in production after 7 days at staging without incident.

## Risk Analysis

- **Frozen-zone exposure:** narrowed by flag-default-OFF + single new private method + sentinel. Rollback is one env flip.
- **False-positive 409s:** if the kiosk fails to capture version at wizard open, EVERY submit returns 409. Mitigation: keep field optional; if absent → skip check (backward compat).
- **Race window:** between version capture and submit, cart can become stale. The 409 is precisely the desired behavior.
- **POS/Kiosk symmetry:** sentinel asserts both paths.

## Approval

```
[ ] Approved — option selected: ___
    Rationale: ____________________________________
    Approved by: __________________________________
    Date: 2026-__-__
[ ] Cancelled — defer to V2 with logging-only mitigation.
    Approved by: __________________________________
    Date: 2026-__-__
```

After approval, record the decision in `docs/gates/GATE_LOG.md` and update `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md` §2.2 with the cleared gate reference. Implementation proceeds via `codex-extension` (PRIMARY) with `EXECUTE_DELEGATION: codex-extension` traced in `reports/post_execute_latest.log`.

---

**Resumption protocol:** loop resumes only when (1) approval populated above, (2) decision recorded in `GATE_LOG.md`, (3) the implementing agent reads the cleared brief and updates the plan section.
