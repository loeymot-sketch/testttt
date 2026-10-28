# Plan — MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016

## Objective

Remove warning-like allergen chips from dense product/catalog surfaces while preserving a prominent, accessible allergen disclosure on the item detail and recap surfaces.

## Product behavior

1. Keep the existing `ALLERGEN_META` labels as the single display vocabulary.
2. Add a compact, text-first disclosure component that exposes the exact allergen names, without emoji icons or exclamation styling.
3. Do not render the disclosure in menu cards or over product images.
4. Use the same aggregate allergen list in the personalization recap; selected supplements and drinks must remain included.
5. Ensure the disclosure is usable by keyboard and assistive technology and does not alter pricing or order composition.

## Allowlist

- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Risks / gates

- This is a presentation-only change. No frozen zone, migration, pricing service, auth, payment, loyalty, branch isolation, dispatch, or order-service symmetry is in scope.
- If the implementation requires changing menu data or backend allergen metadata, stop and escalate rather than guessing.

## Required evidence

- Static UI contract passes.
- Existing catalog visual E2E remains green and read-only.
- No `POST`, `PUT`, `PATCH`, or `DELETE` request is introduced by the catalog flow.
- Diff check and safety check pass.
