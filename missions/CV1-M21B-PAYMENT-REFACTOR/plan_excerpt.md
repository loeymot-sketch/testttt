# Plan Excerpt — CV1-M21B-PAYMENT-REFACTOR

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` and `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`.

M-21b maps remaining POS/KDS finishings, but this queue task is explicitly `CV1-M21B-PAYMENT-REFACTOR`; keep scope to the signed payment refactor.

Relevant master plan section: LOT-6 — PaymentComponent + 401 retry.

Gate decision:

- `GATE_PAYMENT_PROP_MUTATION_2026-04-26`: Approved — Option A — Refactor complet sous gate.

Success criteria:

- 0 direct prop mutation in `PaymentComponent.vue`.
- Parent state update contract covered by tests.
- One-shot 401 retry covered by tests.
- No backend changes.
