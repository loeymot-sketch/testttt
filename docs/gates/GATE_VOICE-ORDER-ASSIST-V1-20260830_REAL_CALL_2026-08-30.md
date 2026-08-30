# Gate Brief – VOICE-ORDER-ASSIST-V1-20260830 real-call activation – 2026-08-30

## Trigger

The new employee-assisted phone flow sends live restaurant-call audio to Deepgram after the employee informs the caller. Automated tests can prove protocol behavior, but only a real Free Pro SIP call can prove the notice timing, both-party audio path, latency and operational fallback. This is a deferred activation gate: VOICE_ORDER_ENABLED remains false by default, and this document blocks production activation, not disabled local/simulated implementation or validation.

## Affected Subsystems

- Free Pro VoIP/SIP line and restaurant telephone endpoint
- Local Asterisk PBX and FoodKing voice gateway
- Deepgram Flux EU transcription
- FoodKing POS assistant, deferred phone order, KDS and collection queue
- Caller transcript retention and access

## Invariants at Risk

- branch_id isolation of caller/transcript/order data
- Backend pricing SSOT and existing deferred phone-order semantics
- PII/RGPD caller information and consent timing
- Restaurant continuity when STT or extraction is unavailable

## Decision Required

After the implementation passes automated validation, does the owner approve the exact caller-information wording and confirm that one real Free Pro call passes every checklist item below before production activation?

## Options

1. Approve activation after all real-call checks pass and the wording is recorded.
2. Keep the feature disabled; manual phone ordering remains unchanged.
3. Cancel the voice-assistant activation.

## Required human-verification evidence

- [ ] Owner records the exact French sentence employees must say before transcription.
- [ ] Call is visible before consent, but no pre-consent audio/transcript reaches Deepgram.
- [ ] After pressing « Client informé », both caller and employee are transcribed with acceptable latency.
- [ ] A correction and an unusual supplement remain editable and visibly marked when uncertain.
- [ ] Employee validates through the existing wizard and phone-order button.
- [ ] Order reaches KDS and pending collection with backend-computed total.
- [ ] Transcript is retrievable by same-branch authorized staff and denied cross-branch.
- [ ] Disabling network/Deepgram leaves the ordinary manual phone-order flow usable.

## Approval

- [ ] Approved — option selected: ___
- [ ] Cancelled

Approved by: ___

Date: ___

## Resumption Protocol

Production activation resumes only after a human fills this approval, records the decision in docs/gates/GATE_LOG.md, and the active plan/report links the resulting evidence. No model may approve this gate.
