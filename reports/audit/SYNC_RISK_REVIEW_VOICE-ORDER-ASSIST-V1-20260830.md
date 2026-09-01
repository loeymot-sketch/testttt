# Sync and business-risk review — VOICE-ORDER-ASSIST-V1-20260830

## Verdict

PASS for disabled implementation and simulated validation. Production activation remains blocked by the real Free Pro call human gate.

## Architecture

- PASS — Free Pro/Asterisk, ephemeral STT transport, transcript/draft and canonical order creation are separate boundaries.
- PASS — no direct model-to-order write exists. The only creation call remains the existing `posOrder/save` path inside `phoneOrderSubmit`.
- PASS — Deepgram/OpenAI failure leaves the ordinary employee-driven phone order operational.
- DEFERRED — actual SIP registration, bridge mixing, both-party capture and perceived latency require the real-call gate.

## State consistency

- PASS — live state and locks include `branch_id` plus hashed `call_id`; gateway branch is server configured and cannot come from JSON.
- PASS — call-to-order linking happens only after a concrete saved order id, is cache-lock serialized, DB transactional, same-branch, idempotent and non-reassignable.
- PASS — failed linking persists only the scoped technical tuple for 24 hours and retries the link without replaying order creation.
- PASS — completed text is transactionally chunked, archived calls are read-only, and purge handles transcript chunks plus orphan links branch by branch.

## Business invariants

- PASS — backend pricing remains the sole source of truth; assistant output contains no price field or calculation.
- PASS — existing `OrderStatus`, `PaymentStatus` and `PosPaymentMethod` enums guard the link; no lifecycle transition changed.
- PASS — `OrderService` and `FrontendOrderService` are untouched, so no symmetry change is introduced.
- PASS — no new dispatch/job/event path was added; existing post-commit order dispatch remains authoritative.
- PASS — no migration and no frozen POS wizard file were modified.

## Authorization and privacy

- PASS — gateway ingress uses timestamped HMAC, constant-time compare, bounded payloads, atomic replay reservation and dedicated throttles.
- PASS — admin routes retain installed/API key/Sanctum/kiosk-block/localization middleware plus `permission:pos`; branch zero is rejected.
- PASS — no audio is stored. Text access and persistence are explicitly branch scoped with bounded retention.
- RESIDUAL — regex PII redaction cannot remove personal data that is spelled or paraphrased; optional OpenAI extraction therefore stays off by default pending provider data-control review.

## Validation coverage

- PASS — signed gateway, replay, stale signature, body-selected branch rejection, pre-consent rejection, branch isolation, transcript persistence/purge, catalog-bounded extraction, safe replies and order linking are covered by targeted PHPUnit.
- PASS — existing deferred phone-order and phone-channel regressions pass unchanged.
- PASS — POS assistant static contracts, SFC/JS compilation, RTP framing/batching, consent attachment, HMAC parity and Flux shutdown ordering pass.
- DEFERRED — browser visual QA against the production-built bundle and the real Free/Asterisk/Deepgram path remain human-verification items; generated public assets were intentionally not rewritten in this dirty worktree.
