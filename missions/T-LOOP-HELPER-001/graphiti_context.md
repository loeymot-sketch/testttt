# Graphiti facts — group=foodking (snapshot 2026-04-23)

(Simulated facts — in a real cycle these come from `search_memory_facts(query="POS cents arithmetic helpers", group_ids=["foodking"])`.)

- Invariant: backend pricing SSOT — frontend never computes prices, only sums display-side amounts already provided by backend (in cents).
- Invariant: integer cents arithmetic on POS to avoid floating point bugs (0.1 + 0.2 != 0.3). Existing payment splitter relies on integer cents (ref: `resources/js/components/admin/pos/PaymentComponent.vue`).
- Convention: helpers under `resources/js/helpers/` are pure, no Vuex/axios deps, tested via vitest in `tests/js/`.
- ADR: ESM with both named and default exports for cross-import flexibility (ref: `resources/js/helpers/kdsLineSemantics.js`).
- Recent test pattern: `tests/js/kdsLineSemantics.spec.js` is the reference style.
