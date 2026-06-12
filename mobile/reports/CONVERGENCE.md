# GOAL convergence report — Le Cayenne Mobile prod-readiness

**Branch:** `fix/lecayenne-prodready-2026-06-09` · **Date:** 2026-06-09 · **Tag:** `v0.1.0-mobile-prodready`
**GOAL:** `plans/GOAL_LECAYENNE_MOBILE_PRODREADY_2026-06-09.md`

## Status: 12/12 findings fixed · verified (technical + source layers) · RED-adversary clean · gates resolved

| Task | Finding | Wave | State | Verified by |
|---|---|---|---|---|
| T-1.1 | F1 promo charged full (A0 billing) | 6 | ✅ DONE | source gate + RED (P1 undercharge caught & fixed) |
| T-1.2 | F3 nav arg-forwarding | 1 | ✅ DONE | source gate + RED clean |
| T-1.3 | F10 order-id collision | 1 | ✅ DONE | source gate + RED clean |
| T-2.1 | F2 earn rate 10≠1 | 6 | ✅ DONE | data + source gate + RED clean |
| T-2.2 | F9 progress copy | 3 | ✅ DONE | source gate + RED clean |
| T-3.1 | F4 bol allergens (A0 legal) | 2 | ✅ DONE | data gate (Boule gratinée→lactose) + RED clean |
| T-3.2 | F11 drink allergen slug-map | 2 | ✅ DONE | source gate + RED clean |
| T-4.1 | F7 Tacos L price | 6 | ✅ DONE | data gate parity sentinel + RED clean |
| T-4.2 | F5 upsell slugs | 4 | ✅ DONE | source gate + RED clean |
| T-4.3 | F8 featured slug | 4 | ✅ DONE | data + source gate + RED clean |
| T-5.1 | F6 sans-sauce render | 5 | ✅ DONE | source gate + RED clean |
| T-5.2 | F12 image fallback | 5 | ✅ DONE | source gate + RED clean |

## Owner gates — RESOLVED
| Gate | Decision | Applied |
|---|---|---|
| G1 | **Real −10% reaches the charge** | promo lifted to App; snapshotOrder/pay/Stripe/confirm bill the discounted total |
| G2 | **10 pt/€** (fix grant logic) | cart preview + gain modal use `config.earn_ratio`; order-detail shows recorded points |
| G3 | **8,90 € (menu SSOT)** | order C-1234 reconciled (total 30,80 €); parity sentinel test added |
| G4 | **Allergens confirmed** | Boule gratinée→lactose; others none |

## Verification gates (runnable, no browser)
- `node mobile/tests/node/data-layer.test.mjs` → **10 pass · 0 fail · 0 pending**
- `node mobile/tests/node/source-assert.test.mjs` → **10 pass · 0 fail**
- JSX parse (Babel) → all edited files + index.html inline App parse cleanly.
- RED adversary (2 passes) → cycle 1 clean on 8 ungated fixes; cycle 2 caught **1 P1**
  (stale-`lineTotal` undercharge on qty-change in the billing refactor) → **fixed + regression-guarded**.

## Deferred: Playwright VISUAL (V) capture
Disk at 100% / 1.3 GiB free (caused one ENOSPC this session) → Chromium download not
attempted. Technical (T) + DOM-pattern (I) layers ran in Node. To run the visual layer:
```
cd app && npm i -D @playwright/test && npx playwright install chromium
python3 -m http.server -d mobile 4173   # serve the app
# author specs under mobile/tests/mobile-e2e/ against the data-testid hooks + LC.dev.*
```
Also vendor the unpkg React/Babel `<script>` (index.html) locally for determinism.

## Commits (branch `fix/lecayenne-prodready-2026-06-09`)
Wave 0 (manifest+GOAL, gates) → W1 nav/id → W2 allergens → W3 progress → W4 menu SSOT →
W5 wizard/visual → W6 billing/earn/price (gated) + RED P1 fix → tag.
