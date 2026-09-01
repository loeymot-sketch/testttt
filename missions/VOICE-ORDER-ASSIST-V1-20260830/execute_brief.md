# Execute brief — VOICE-ORDER-ASSIST-V1-20260830

Read the active plan in full. Implement only its allowlist.

The core product decision is deliberately conservative: this is an employee copilot, not an autonomous caller agent. Deepgram turns live audio into text; the structured extractor proposes catalog-bound lines and uncertainties; the staff member validates in the existing POS wizard; only the existing `phoneOrderSubmit` creates the deferred order.

Critical implementation notes:

- The repository is heavily dirty. Never clean or reformat it. `routes/api.php` already has an owner hunk at composer category `available-sources`; preserve it exactly.
- Avoid `resources/js/router/index.js`; add the dedicated route in `posRoutes.js` and mirror it in `pos-app.js` only.
- Reuse `ItemComponent.variationModalShow` / the existing POS wizard from the parent. Do not edit `ItemComponent.vue` or the frozen vanilla wizard.
- The extractor is advisory. Invalid IDs, incomplete required choices, low confidence and odd supplements must remain visibly unresolved.
- The phone order response returned by `posOrder/save` contains the created order. Linking the call is a separate best-effort request after success; a link failure must not roll back or duplicate the order.
- `ActionLog` is operational evidence, not an immutable fiscal proof. Use action/resource namespaces and chunking; do not write to fiscal `audit_logs`.
- The gateway derives branch from its credential mapping. Never accept `branch_id` from event JSON as authority.
- Consent is fail-closed per call. Before the employee confirms the caller-information notice, discard media locally and make zero Deepgram connection/write; do not buffer and replay it.
- Use the canonical authenticated POS branch context and reject branch 0/missing. Every `ActionLog` read/link/delete needs an explicit branch predicate.
- HMAC ingress must use constant-time comparison, strict body/schema limits, dedicated throttles and atomic shared-cache replay reservation. Cover concurrent duplicates.
- Redact caller name, phone and e-mail patterns before any OpenAI request. Never put transcript/PII in logs.
- Keep a scoped pending call→order link after `posOrder/save`; link idempotently, reject reassignment/non-phone/cross-branch orders and retry independently of the cleared cart.
- Default `VOICE_ORDER_ENABLED=false`. The deferred real-call gate blocks activation, while disabled implementation and simulated validation may proceed.
- Store pending links for at most 24 hours under a branch+user localStorage key. Recover after navigation/reload, reject user/branch mismatch, remove on success/expiry, and never resubmit an order.
- Build recommended replies deterministically from validated unresolved slots. Never let an LLM assert price, stock, timing, allergen facts or order acceptance.
- Use existing PosPaymentMethod/PaymentStatus/other applicable enums for linkage checks; no magic status/payment values.
- OpenAI payload may include only best-effort-redacted transcript plus catalog matching fields, with request storage disabled where supported. Exclude all known metadata and test the outgoing HTTP body.
- Keep Deepgram and OpenAI optional at boot. Missing keys show a clear degraded state while manual phone ordering still works.

Before product edits, run the required activity reservation and safety hook. After implementation, return the strict execution JSON and let the wrapper generate its self-audit.

Validation vocabulary is binding: `local-validation`, `playwright-critical-flow`, `human-verification`. The real Free Pro call is a pending human activation gate, not an automated PASS.
