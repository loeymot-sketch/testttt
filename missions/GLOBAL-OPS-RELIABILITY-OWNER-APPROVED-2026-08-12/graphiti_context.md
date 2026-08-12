# Durable context fallback

Graphiti may be absent. The durable facts required for this master mission are:

- Owner approved D1-D7 Option A on 2026-08-12.
- POS CARD is manual external only: cashier completes payment on a disconnected TPE, then FoodKing records CARD for fiscal/management and prints/proceeds.
- No current-scope integrated TPE; no mono terminal selector/value if backend discards it.
- Kiosk CARD must fail closed without trusted proof.
- Operator attention is delivery/seen/claimed-with-lease/resolved, scoped by branch and responsibility.
- Printing requires one active lease per logical printer; spool acceptance is not paper proof.
- Drawer likely uses printer DK RJ11/RJ12 but exact hardware topology must be inventoried; Winspool is not opening proof.
- Stock requires one saga with separate physical/availability proofs and lifecycle-aware consume/release/waste.
- Hardware and commercial GO remain pending human execution/signoff.
- Product edits are bounded child cycles executed by Codex and double-audited.

