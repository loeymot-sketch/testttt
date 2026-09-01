# External tooling audit — VOICE-ORDER-ASSIST-V1-20260830

- verdict: ADOPT_NOW
- install_decision: Adopt self-hosted Asterisk plus Deepgram Flux EU through a direct WebSocket. Install only `aiohttp` in the isolated gateway virtualenv; do not install a Deepgram SDK, telephony SaaS, avatar stack or browser agent. Keep optional OpenAI extraction disabled until the deterministic flow is accepted.
- safe_use_cases: Employee-assisted live transcription after explicit per-call notice; catalog-bounded draft extraction; deterministic clarification prompts; text-only transcript retention with branch-scoped staff access.
- rejected_use_cases: Autonomous order acceptance; price, availability, allergen or preparation-time claims; pre-consent streaming; raw audio recording; HeyGen/ElevenLabs/TTS/avatar in V1; direct model writes to orders; third-party SIP intermediary while Free Pro SIP and local Asterisk are available.
- local_changes: Added a fail-closed FoodKing gateway API, branch-scoped transcript store, POS assistant panel, Asterisk templates, a small `aiohttp` gateway, retention tooling, tests and an operations runbook. `VOICE_ORDER_ENABLED=false` remains the default.
- sources_checked: Free Pro VoIP configuration (`https://support-pro.free.fr/comment-parametrer-mon-poste-telephonique-voip/`); Asterisk PJSIP outbound registration and ARI External Media (`https://docs.asterisk.org/Configuration/Channel-Drivers/SIP/Configuring-res_pjsip/Configuring-Outbound-Registrations/`, `https://docs.asterisk.org/Development/Reference-Information/Asterisk-Framework-and-API-Examples/External-Media-and-ARI/`); Deepgram Flux quickstart, force-end-turn, close-stream and pricing (`https://developers.deepgram.com/docs/flux/quickstart`, `https://developers.deepgram.com/docs/flux/force-end-turn`, `https://developers.deepgram.com/docs/flux/close-stream`, `https://deepgram.com/pricing`); OpenAI API model catalog (`https://platform.openai.com/docs/models`).
- next_safe_poc_step: Configure secrets out of band on a wired Linux staging gateway, keep production disabled, then execute the signed real-call checklist in `docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md` with pre-consent network observation, both-way audio, latency, correction, KDS and outage fallback evidence.

## Guardrails retained

- The caller is handled by an employee; the assistant cannot submit an order.
- Deepgram is opened only after FoodKing confirms explicit consent for that exact branch/call/gateway tuple.
- Audio packets are memory-only and discarded under backpressure; no WAV/file API exists in the gateway.
- The optional OpenAI request excludes caller identity, branch/user/call identifiers, timestamps and prices, uses `store=false`, and validates every returned item against the current POS catalog.
- Production activation remains a human gate even though the dependency choice is approved for implementation.
