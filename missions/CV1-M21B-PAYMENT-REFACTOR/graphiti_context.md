# Graphiti Context — CV1-M21B-PAYMENT-REFACTOR

- FoodKing forbids routine implementers from modifying payment/auth logic; this mission uses GPT-only Codex execution because the payment path is sensitive.
- Payment refactor is gate-approved under `GATE_PAYMENT_PROP_MUTATION_2026-04-26` Option A.
- Maintain backend pricing SSOT and avoid changing backend services. This mission is frontend state-contract cleanup plus 401 retry only.
