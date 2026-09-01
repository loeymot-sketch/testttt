# Plan – VOICE-ORDER-ASSIST-V1-20260830 – 2026-08-30

## TASK_ID
VOICE-ORDER-ASSIST-V1-20260830

## PRIMARY_EXECUTION_MODEL
gpt-5.6-sol

## MODEL_OVERRIDE_REASON
The repository default gpt-5.5-pro is rejected by the current ChatGPT-authenticated Codex CLI with HTTP 400. The supported frontier coding successor gpt-5.6-sol is used on the same codex-extension channel with xhigh reasoning.

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## OBJECTIVE

Livrer la V1 d'assistance aux commandes téléphoniques FoodKing : l'employé reste au téléphone, le flux audio est transcrit en direct, un brouillon structuré et des réponses conseillées apparaissent sur la caisse/tablette, puis l'employé vérifie la composition dans le wizard existant et valide la commande avec le chemin « commande téléphone » déjà en production. La commande reste impayée jusqu'au retrait, rejoint le KDS et la file d'encaissement existants, et la transcription est rattachable à la commande.

La V1 doit préparer la V2 autonome sans la simuler : les frontières téléphonie → transcription → extraction → commande restent découplées, mais aucun bot ne parle au client dans ce cycle.

## DECISIONS RECORDED FROM OWNER

- Téléphonie : utiliser les identifiants SIP de la ligne VoIP Free Pro et un PBX Asterisk local ; ne pas ajouter Twilio/Telnyx ni un abonnement voix intermédiaire.
- STT : Deepgram, endpoint européen, Flux Multilingual français par défaut. Aucun SDK Deepgram n'est requis ; WebSocket direct pour réduire coût et dépendances.
- Extraction : réutiliser la clé OpenAI serveur déjà supportée par FoodKing avec un modèle économique configurable (`gpt-5.6-luna` par défaut), uniquement pour transformer les tours finalisés en brouillon JSON. Deepgram reste le seul moteur audio.
- Validation : jamais d'envoi automatique cuisine sur une hypothèse IA. L'employé valide les lignes ambiguës dans le wizard puis déclenche le bouton téléphone existant.
- Données : transcript texte uniquement, pas d'enregistrement audio. Rétention configurable et information du client obligatoire avant transcription.

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: codex-extension
PLAN_REVIEW_MODEL: gpt-5.6-sol
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS — codex-extension gpt-5.6-sol/xhigh after two REWORK corrections.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Free Pro / Asterisk adapter | Exemples PJSIP + ARI External Media, aucun secret | Write new infra files | No | No |
| Voice gateway Python | RTP µ-law → Deepgram Flux EU → webhook signé FoodKing | Write new service | Gateway fixed to one configured branch credential | No |
| Voice gateway ingress | Verify timestamp/HMAC/replay, derive branch from gateway config, never body | Write new controller/service/routes | Yes — strict derivation and tests | No |
| API rate limiting | Dedicated gateway/admin polling buckets | Minimal write in RouteServiceProvider | Gateway id + authenticated user/IP | No |
| Live transcript store | Cache branch-scoped while active; chunked `ActionLog` persistence at end | Write new service using existing table | Yes — every key/query includes authorized branch | No |
| Order draft extraction | Catalog-bounded JSON draft, confidence/ambiguities, no price or status | Write new service/controller | Yes — catalog comes from authenticated branch | No |
| POS assistant UI | Dedicated route/panel, live transcript, recent calls, recommended response, review queue | Write Vue + router | Reads only authenticated branch endpoints | No |
| Existing phone order submit | Link active call after successful existing `posOrder/save`; no backend order mutation change | Minimal write in `PosComponent.vue` only | Existing branch behavior unchanged | Existing OrderService dispatch path only, untouched |
| Retention/operations | Purge only voice transcript ActionLog chunks and installation runbook | Write command/docs | Filtered by action/date; no cross-branch UI query | No |

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php` and `app/Services/FrontendOrderService.php` — the existing phone-order flow is reused unchanged.
- `app/Services/Pricing/PricingService.php`, quote/seal code and every frontend price calculation — no price logic is introduced.
- `app/Domain/Order/OrderStateMachine.php`, `app/Enums/OrderStatus.php`, KDS services and payment services — no lifecycle change.
- Database migrations/schema — transcript persistence reuses `action_logs`; no schema gate.
- Frozen POS vanilla wizard (`public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`) and `ItemComponent.vue` — only public existing methods may be called.
- Audio recording — raw audio must never be written to disk or database.
- Autonomous voice/TTS/avatar — V2 only.

## ALLOWLIST — PRODUCT FILES

New files may be introduced under these exact namespaces; existing shared files may be modified only for the stated surgical integration:

- `config/voice_order.php`
- `.env.example`
- `app/Console/Commands/PurgeVoiceOrderTranscriptsCommand.php`
- `app/Http/Controllers/Admin/VoiceOrderAssistantController.php`
- `app/Http/Controllers/VoiceOrderGatewayController.php`
- `app/Services/VoiceOrder/**`
- `app/Providers/RouteServiceProvider.php` — add only the two named voice-order rate limiters; no existing auth/rate policy rewrite.
- `routes/api.php` — additive voice routes only; preserve the user's existing unstaged `availableSourcesForCategory` change byte-for-byte.
- `resources/js/components/admin/pos/VoiceOrderAssistantPanel.vue`
- `resources/js/components/admin/pos/PosComponent.vue` — import/mount panel, open existing item wizard, propagate customer data, and link the returned order only.
- `resources/js/router/modules/posRoutes.js`
- `resources/js/pos-app.js`
- `services/voice-gateway/**`
- `docs/operations/VOICE_ORDER_ASSISTANT_V1.md`
- Targeted tests under `tests/Feature/VoiceOrder/**`, `tests/Unit/VoiceOrder/**`, `tests/js/voiceOrder*.spec.js`, and `services/voice-gateway/tests/**`.

No other product path may be changed without ESCALATION.

## DIRTY WORKTREE CONTRACT

- The worktree contains extensive owner changes. They must be preserved.
- `routes/api.php` and `resources/js/router/index.js` are already dirty; `resources/js/router/index.js` is not in scope and must not be touched.
- Do not reset, checkout, reformat, stage, commit, or clean any owner file.
- Before and after each shared-file edit, inspect the targeted diff and retain unrelated hunks.

## INVARIANTS_AT_RISK

- Backend Pricing SSOT — the extractor may return catalog IDs and free-text instructions only; it must never invent or calculate a price. Existing quote + `PosController::store` stay authoritative.
- `branch_id` isolation — public gateway payload cannot select a branch. The server derives it from a configured gateway identity after HMAC verification; admin reads derive branch from authenticated staff context. Cache keys and ActionLog queries are branch-prefixed/scoped.
- Dispatch after commit — no new events/jobs. Existing order dispatch remains in untouched `OrderService`.
- OrderService / FrontendOrderService symmetry — N/A; neither file may be modified.
- Frozen zones — no frozen file may be modified.
- PII/RGPD — telephone number and transcript are sensitive operational data. No audio storage, explicit notice in UI/runbook, permission `pos`, bounded retention, no transcript in application logs.

## GATE_CONDITIONS

- New service product decision: resolved by the owner's explicit written choice of Deepgram and acceptance of a cheap GPT model for text normalization in this conversation.
- No schema, auth middleware, frozen zone, payment, status, pricing or dispatch gate is opened by the planned implementation.
- Production activation remains blocked by the explicit human gate `docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md`: real Free Pro SIP credentials supplied out of band, Deepgram/OpenAI keys supplied via environment, the restaurant's exact caller-information wording approved by the owner, and a manual call QA sign-off. Implementation and simulated/local validation may proceed; production traffic may not. None of these secrets or approvals may be fabricated.
- This is a deferred activation gate, not the current run-cycle implementation gate. `VOICE_ORDER_ENABLED=false` is the mandatory default and fail-closed production state. Local tests may enable it explicitly. The run-cycle may execute/validate code while disabled; it cannot activate production or close the deferred human-verification checklist on its own.
- If implementing a safe route requires changing auth middleware/guards or if `ActionLog` proves unsuitable for branch-scoped retrieval, stop with ESCALATION; do not add a migration or auth change.

## SECURITY AND DATA CONTRACT

1. Gateway event headers include gateway id, Unix timestamp, event id and HMAC-SHA256 over `timestamp + "\n" + event_id + "\n" + raw_body`.
2. Server rejects unknown gateway, stale timestamp, malformed/oversized body, invalid HMAC (constant-time comparison) and replayed event id. Replay reservation uses atomic Cache::add on a shared production cache with bounded TTL; concurrent duplicates yield one accepted event only. Branch id is read from server config only.
3. Admin endpoints use the existing installed/apiKey/auth:sanctum admin group plus `permission:pos`.
4. Caller number is normalized for display/storage but never used as authentication.
5. Live state expires automatically after two hours. Completed transcripts are chunked below MySQL TEXT limits with deterministic call id/chunk order and inserted in one DB transaction. Purge targets only the voice action namespace and cutoff; every read/delete keeps explicit branch_id predicates because ActionLog has no global branch scope.
6. Extraction prompts never include prices, secrets, caller number/name or another branch's catalog. Redact phone/e-mail patterns from transcript text sent to OpenAI. Response is schema-validated; invalid/unavailable IDs become `needs_review`.
7. The UI must label transcript confidence and ambiguities. It must not call the transcript an infallible legal proof.
8. Consent is per call and fail-closed: call.started may expose caller metadata to authorized staff, but the gateway discards media locally and must not open/send a Deepgram stream until the employee explicitly presses « Client informé — démarrer la transcription ». Consent state is branch-scoped, timestamped and non-reusable.
9. The canonical admin branch resolver must reject branch 0, missing branch and unauthorized branch selection. Gateway branch comes only from gateway config. Every transcript read, link and purge query states where(branch_id, ...) explicitly.
10. Call→order linking is idempotent. It verifies the call and order are same branch, the order source is phone, the deferred-phone semantics are present, and an existing call cannot be reassigned to a different order.
11. Any deferred payment/status check uses existing PHP/JS enums (including PosPaymentMethod and PaymentStatus) and never numeric/string magic values. No new OrderStatus transition is introduced.
12. Recommended employee replies are deterministic server output from validated unresolved slots only. Allowed forms: ask for one missing canonical wizard choice, request clarification of one ambiguity, or ask the employee to recap the validated draft. They may not state a price, stock/availability promise, preparation/delivery time, allergen claim or order acceptance. Unknown questions are handed back to the human without generated factual claims.

## REAL-TIME AND PROVIDER CONTRACT

- UI transport: authenticated polling every 750 ms against a branch-scoped endpoint, capped by named voice-order-admin throttle. Polling backs off on 429/network failure; live cache TTL is two hours.
- Gateway control: after call.started, the gateway queries the signed authorization state and does not connect Deepgram before consent. No pre-consent audio buffer is replayed.
- Deepgram: wss://api.eu.deepgram.com/v2/listen, model flux-general-multi, repeated language_hint=fr, encoding=mulaw, sample_rate=8000, RTP payload batched to the recommended ~80 ms cadence. Only final TurnInfo.event=EndOfTurn creates durable transcript turns; updates are live/replaceable.
- Link retry: after posOrder/save, keep call_id, order_id, branch_id, user_id, created_at and expires_at in localStorage key `voice_order_pending_link:v1:b{branch_id}:u{user_id}` until the separate idempotent link endpoint succeeds. TTL is 24 hours; expired records are removed. Cart reset/navigation/reload must not erase it; branch/user mismatch must never load it. Retry with bounded exponential backoff and expose a visible « commande créée, transcription à relier » state.
- OpenAI minimization: the outbound request contains only best-effort-redacted transcript text plus current-branch catalog ids/names/options needed for matching. It excludes caller metadata, name, known phone/e-mail, call id, user id, branch id, timestamps and prices, and sets provider request storage off where supported. Regex redaction cannot guarantee anonymity when a caller spells or paraphrases personal data; the runbook must state this residual risk and require the account's appropriate EU/data-retention controls.

## Execution Steps

1. Add provider/config contracts, dedicated throttles, strict signed gateway ingestion, branch-scoped live cache and transactionally chunked transcript persistence/retrieval with atomic replay protection.
2. Add deterministic catalog matcher plus optional OpenAI structured extractor with timeout, cache, minimum interval, strict schema validation and safe unresolved fallback.
   Recommended replies remain deterministic from validated missing/ambiguous slots and are not free-form LLM answers.
3. Build the Asterisk/Deepgram Python gateway using raw RTP µ-law and WebSockets, with ARI external-media bridge, explicit per-call consent control, reconnect/finalization handling, signed FoodKing events, unit-testable RTP/HMAC helpers, and no audio storage.
4. Build the POS assistant panel in a high-density, service-friendly visual language: caller identity, live transcript, active turn, structured draft, ambiguities, recommended employee response, recent calls and explicit final-review state.
5. Integrate the panel into a dedicated POS route while reusing `PosComponent`; let draft lines open the existing item wizard. Populate phone/name only after employee confirmation.
6. After the existing `phoneOrderSubmit` succeeds, persist a scoped pending link and link the call id to the returned order id via an idempotent same-branch endpoint. Retry without duplicating the order. Do not change order submission semantics.
7. Add retention command, environment/runbook, Free SIP/Asterisk templates and degraded-mode instructions.
8. Run targeted PHPUnit/Vitest/Python checks, route list, build/static checks and browser visual QA. Verify frozen-zone diff remains empty and unrelated owner diffs unchanged.

## ACCEPTANCE CRITERIA

1. A signed simulated gateway call produces a live transcript on the authenticated branch assistant page; invalid/replayed/cross-gateway events fail closed.
2. Before the employee confirms « Client informé », the call is visible but Deepgram receives zero audio and no transcript exists. Consent from another branch/call cannot unlock it.
3. The transcript updates without page reload and remains usable when extraction is disabled or temporarily fails.
4. Extraction returns only IDs present in the current branch catalog, marks uncertain/bizarre requests for review, and never returns or calculates a price.
5. An employee can open each suggested product in the existing wizard, correct it, and submit through the existing phone-order CTA.
6. Successful submit creates the same deferred phone order behavior already covered by `PhoneOrderDeferredTest`: visible KDS/collection flow and backend-computed total.
7. Caller number/name and call id are linked idempotently to the created phone order; completed transcript is retrievable only by authorized staff in the same branch. Failed linking survives cart reset and can be retried.
8. Raw audio is absent from repository storage, Laravel logs and database; caller name/number are absent from OpenAI requests.
9. Deepgram/OpenAI outage leaves manual POS phone ordering fully operational.
10. No migration, no frozen file, no OrderService/FrontendOrderService change, no pricing/status/payment change.
11. Runbook states exact remaining secret inputs and the Free Pro SIP validation steps without exposing credentials.

## TEST STRATEGY

- Strategy vocabulary: `local-validation` for all automated tests below; `playwright-critical-flow` for the assistant/POS path; `human-verification` for the real Free Pro call gate.
- PHPUnit feature: HMAC valid/invalid/stale/replay including simultaneous duplicates; malformed/oversized payload and dedicated throttle; configured branch derivation; branch 0/missing rejected; branch A cannot read/link branch B; pre-consent media authorization denied; cache lifecycle; transactional transcript chunks; targeted purge; recent calls; idempotent call-order linking/non-reassignment/non-phone rejection; unavailable/foreign catalog IDs rejected; OpenAI PII redaction/timeout/failure fallback.
- Access negatives: unauthenticated admin calls, authenticated user without `permission:pos`, kiosk-scoped token against admin endpoints, invalid/stale/replayed signed gateway event and invalid/stale/replayed gateway authorization-control request.
- Recommended-reply tests: one canonical missing slot at a time; ambiguity clarification; recap fallback; explicit rejection/sanitization of price, availability, timing, allergen and acceptance claims.
- PHPUnit regression: existing `PhoneOrderDeferredTest`, `SimpleOrderResourcePhoneChannelTest`, order quote phone path.
- Vitest: panel live/empty/error states, ambiguity blocking, no auto-submit, caller apply, link after success, dedicated route presence.
- Vitest pending-link persistence: navigation + reload recovery, 24-hour expiry, no restore after user or branch switch, retry success removal, and no duplicate order submission.
- Python unittest: RTP payload extraction including CSRC/extension, 80 ms frame batching, webhook signature parity fixture, pre-consent discard/zero Deepgram writes, Flux TurnInfo update/dedupe/finalization, no audio file writes.
- Static: `php artisan route:list` voice routes; scan no Deepgram/OpenAI key in frontend/bundle; scan no price calculation introduced in assistant files.
- Browser/Playwright (`playwright-critical-flow`): desktop 1366×768 and tablet viewport; active pre-consent call, consent activation, no-call, STT-down, ambiguous-order and pending-link-retry states; existing POS remains usable.
- Human (`human-verification`, hard activation gate): one real inbound Free Pro call, audible caller/employee both transcribed after notice only, no pre-consent segment, draft correction, deferred order on KDS/collection, transcript same-branch retrieval, outage fallback.

## SUBTASKS

| SUBTASK_ID | Description | Difficulty | Owner (planned) | Invariants at risk | Mini-audit policy | Status | Retry |
|---|---|---|---|---|---|---|---|
| VOICE-ORDER-ASSIST-V1-20260830-S01 | Backend signed events, cache, transcript, extraction | complex | codex-extension fallback | branch_id, PII | 1:1 | DONE | 0 |
| VOICE-ORDER-ASSIST-V1-20260830-S02 | Asterisk/Deepgram gateway and runbook | complex | codex-extension fallback | secrets, availability | 1:1 | DONE | 0 |
| VOICE-ORDER-ASSIST-V1-20260830-S03 | POS assistant UI and existing phone-order integration | complex | codex-extension fallback | pricing SSOT, critical UX | 1:1 | DONE | 0 |
| VOICE-ORDER-ASSIST-V1-20260830-S04 | Regression, visual QA and deploy evidence | complex | codex-extension fallback | all declared | 1:1 | DONE — source/static validation; deployment visual + real call deferred gate | 0 |

## SYMMETRY_NOTE
N/A — `OrderService` and `FrontendOrderService` are explicitly off-limits. The existing phone-order submission endpoint is reused unchanged.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
- [x] Pending resolved
- [x] PLAN_REVIEW_VERDICT: PASS
- [x] AUDIT_VERDICT: PASS
- [x] GPT_FINAL_AUDIT_VERDICT: PASS
- [x] Passed — disabled implementation cycle closed
- [x] Deferred production activation gate pending — `docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md` (does not block disabled implementation/validation)
