# Plan Excerpt — CV1-M11-KIOSK-RUNTIME

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

M-11 — `CAISSE_V1_KIOSK_RUNTIME_2026-04-25` (`GATE_OFFLINE_SCOPE_V1` + `GATE_FISCAL_KIOSK_V1`)

Plan goal: replace kiosk `status: 16` literal with enum; keep strict `offline_` prefix on all local offline IDs; under offline gate Option A, refuse CB/TR offline with UI disabled and server-side refusal; preserve kiosk fiscal gate Option B where POS finalizes.

Gate decisions recorded in `docs/gates/GATE_LOG.md`:

- `GATE_OFFLINE_SCOPE_V1_2026-04-25`: Approved — Option A — Read-only menu, paiement desactive / CB+TR refused offline.
- `GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25`: Approved — Option B — POS finalize.

Allowlist from plan: `resources/js/components/frontend/kiosk/*.vue`, `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`, `app/Http/Controllers/Frontend/OrderController.php`, Vitest and Playwright sentinels #17/#18.
