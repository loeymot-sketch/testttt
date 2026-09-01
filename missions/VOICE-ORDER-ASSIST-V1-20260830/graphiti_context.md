# Graphiti context

Graphiti tools were not loaded in this Codex desktop session on 2026-08-30. The required fallback was used:

- `memory/INDEX.md`
- targeted `memory/episodes/*.jsonl` search for pricing SSOT, branch isolation, phone orders and wizard parity
- current repository code is authoritative

Durable facts relevant to this mission:

- Backend quote/order services are the only pricing authority.
- Existing POS `phone_order` submission already produces deferred counter payment and the kitchen/collection behavior; reuse it.
- `branch_id` is a strict business-data boundary.
- The POS vanilla wizard is frozen; it may be invoked, not changed.
- No OrderService/FrontendOrderService edit is required for this V1.

