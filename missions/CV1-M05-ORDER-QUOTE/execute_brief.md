# EXECUTE BRIEF — CV1-M05-ORDER-QUOTE

Implement M-05 only. Do not modify pricing services unless already allowed; read them as backend SSOT. Add schema with reversible migration and focused quote tests. Keep UI edits minimal and only to consume backend quote values.

## REWORK — GPT final audit 2026-04-25

- Scope expansion approved by audit necessity: `OrderService::posOrderStore` and `FrontendOrderService::myOrderStore` must both seal the quote at real order commit.
- Validate `quote_token` + `quote_signature` when supplied; if legacy clients omit them, create/consume a server-side commit quote so pricing remains backend SSOT without breaking existing tests.
- Consume the quote with the real `order_id`, reject expired/tampered/cross-branch replay, and keep POS/Kiosk symmetry documented.
