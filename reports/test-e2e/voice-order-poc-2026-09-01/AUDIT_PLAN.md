# Audit Plan — voice-order-poc-2026-09-01

Scope: the voice-order/assist-v1-2026-08-31 branch's user-facing surface. The
feature itself is disabled by default (VOICE_ORDER_ENABLED=false in production);
this audit exists to prove (a) the disabled-state UI is correct and (b) the new
code touching the shared PosComponent.vue causes zero regression on the existing,
already-shipped phone-order flow.

Base URL: http://127.0.0.1:8790
Branch: voice-order/assist-v1-2026-08-31
Login: pos@lecayenne.fr / 123456 (loginAsPosOperator helper)

## Wave A — POS caisse core + regression on existing phone-order flow

Purpose: PosComponent.vue was modified to mount VoiceOrderAssistantPanel and add
call-linking logic around the existing `phoneOrderSubmit` path. Prove the
pre-existing, shipped phone-order flow is byte-for-byte unaffected in behavior.

Spec: tests/e2e/__screenshots__/test-e2e-A/spec.spec.js (agent writes this)
Screenshots dir: tests/e2e/__screenshots__/test-e2e-A/

States:
1. 01-cash-drawer-modal — /admin/pos, drawer-opening modal as seen on fresh login
2. 02-caisse-idle — after opening drawer with a 50€ float, main caisse view, item grid visible
3. 03-item-wizard-open — click a product with variations, wizard modal opens
4. 04-cart-with-item — item added to cart, cart panel shows line + total
5. 05-channel-telephone — switch order channel/type to "Commande téléphone" (or
   equivalent existing toggle), customer name + phone fields visible
6. 06-phone-order-submitted — after filling name/phone and clicking the existing
   phone-order submit button, success state / cart cleared / order appears in the
   encaissement queue

Scenario verified: numeric integrity — cart total at state 04 must equal the
total shown for the same order once it appears in the "à encaisser" / tracker
queue after state 06. Also verify NO new UI element (voice panel, "Assistant"
route content) leaks into this flow — this flow must look and behave exactly as
it did before this branch.

## Wave B — Voice-assistant route (disabled state) + Assistant entry point

Purpose: prove the new `/admin/pos/voice-assistant` route and the new
"Assistant" button on the caisse toolbar are correct, and that the disabled-flag
messaging is unambiguous to an employee (no dead/broken UI, no raw i18n keys).

Spec: tests/e2e/__screenshots__/test-e2e-B/spec.spec.js (agent writes this)
Screenshots dir: tests/e2e/__screenshots__/test-e2e-B/

States:
1. 01-caisse-toolbar-assistant-button — /admin/pos, zoom/crop-relevant area
   showing the "🎧 Assistant" button in the COMMANDES row (data-testid
   `pos-voice-assistant-open`)
2. 02-voice-assistant-disabled-state — navigate to /admin/pos/voice-assistant,
   full panel showing the disabled-safe message
3. 03-voice-assistant-back-to-caisse — verify a way back to the normal caisse
   exists and works (router-link round trip), land on caisse still functional
4. 04-mobile-or-tablet-viewport — same disabled-state route at 1024x600 (kiosk/
   tablet POS viewport) to catch responsive breakage the desktop capture would miss

Scenario verified: the disabled-state message must never claim the assistant is
"listening" or "active" — it must clearly state manual phone ordering remains
available. No `[object Object]`, no raw key like `voiceOrder.foo`, no empty white
panel.

## Non-goals for this audit

- The live-transcript / active-call UI is NOT capturable — VOICE_ORDER_ENABLED
  stays false and no real call exists. Do not simulate or fake this state.
- Backend/gateway contract tests (PHPUnit, Vitest unit, Python pytest) are
  already covered by the automated suites re-run this session (13/13, 4/4,
  16/16) — this audit is for the browser-rendered surface only.
