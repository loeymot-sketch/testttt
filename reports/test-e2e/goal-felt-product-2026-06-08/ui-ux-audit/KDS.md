# KDS — Exhaustive UI/UX Audit (page-by-page + every control)

**Goal:** goal-felt-product-2026-06-08 · **Surface:** Kitchen Display System
**Date:** 2026-06-08 · **Harness:** disposable clone http://127.0.0.1:8766 (DB `foodking_e2e`)
**Login:** `loginAsChefOperator` (chef@lecayenne.fr) · **Viewports:** 1280×800 + 1920×1080
**Screenshots:** `tests/e2e/__screenshots__/uiux-kds-2026-06-08/`
**Scratch specs:** `tests/e2e/zz-uiux-kds-2026-06-08.spec.js`, `zz-uiux-kds-cta-2026-06-08.spec.js`, `zz-uiux-kds-onset-2026-06-08.spec.js`

**Default layout = V2 single-FIFO 4×2 grid** (`KitchenDisplaySystemComponent.useV2Layout()` returns `true` by default; rollback to legacy 4-lane layout only via URL `?v2=0` or `localStorage kds.v2_enabled=0`). The audit treats the **V2 board as the production surface** the chef actually sees, and the legacy layout as rollback-only.

---

## VERIFIED-WORKING (bounded "not-broken" list — do not re-litigate)

Every one of these was exercised live, not assumed:

| Control / surface | Evidence |
|---|---|
| **Bump CTA "Prêt"/"Démarrer"** | `cta.click()` → `POST admin/kds-order/change-status/7` → **202 Accepted** (`/tmp/kds-cta.log` `CTA_POST=202 POST kds-order/change-status/7`). State-aware label: NOUVELLE→"Démarrer", EN COURS→"Prêt" (`KdsOrderCard.ctaLabelKey` 397-401, screenshot 01 card [D]). |
| **Keyboard [A]–[H] shortcut** | `keyboard.press('a')` → `POST change-status` → **409** (idempotency/state conflict on the just-bumped order, silently swallowed = correct) (`KEY_A_POST=409 POST`). |
| **Overflow safety chip** | `+12 en attente` renders when active>8 (`OVERFLOW_CHIP=1 txt=+12 en attente`, screenshots 01/03). |
| **"Récemment servies" strip** | present (`SERVED_STRIP=1`); rollup label past 60min→h, 24h→j (`KdsV2Grid.servedAgoLabel` 378-402). |
| **History drawer** | opens via 📚 Historique, header "Historique du jour (14)", per-order PRÊT badge + PASSÉE/TERMINÉE + line-items + customization, ✕ close, scrollable (screenshot 05). |
| **Status-conflict UI** | code-verified only (not forced-rendered): `onV2ChangeStatus` 1649-1676 → 409 = silent `_debouncedRefresh`; non-409 = error banner `message.kds_status_conflict` (key resolves: "Cette commande a été modifiée ailleurs…"). Live 409 observed in `KEY_A_POST`. |
| **Cash-pending note** | "EN ATTENTE ENCAISSEMENT" amber dashed note, non-blocking, CTA stays enabled (screenshot 01 card [D]; `KdsOrderCard.isCashPending` 390-392). |
| **Allergen pill / recall badge / source chips / state pills** | all FR-resolved, themed. |
| **i18n** | **Zero raw labels** on board AND in drawer (`RAW_LABELS_BOARD=[]`, `RAW_LABELS_DRAWER=[]`). All `label.kds_*` / `message.*` / `button.*` keys resolve in `resources/js/languages/fr.json` (43 keys spot-checked OK). |

**Dead-control count = 1 functional dead-feature (the new-order chime) + a cluster of V2-unreachable toolbar controls (sound toggle, volume, station filter, group-by-table). No dead *button* on the V2 board itself** — every visible control fires.

---

## PAGE-BY-PAGE BREAKDOWN

### 1. The board — V2 4×2 FIFO grid (default) @ 1280×800 — screenshot `01-board-1280.png`
- Header bar (Le Cayenne logo, Tableau De Bord, printer icon, Bonjour Chef), 📚 Historique trigger top-right, single consolidated status banner ("Les pastilles « Prêt » (bump)… LOCAL").
- 8 order cards in a 4×2 grid + placeholders; oldest top-left. Each card: meta row [shortcut] STATE pill + SOURCE chip + ALLERGIE pill; main row N°queue (left) + ATTENTE timer (right); body line-items; footer bump CTA.
- **BROKEN — ATTENTE timer overflows/clips (P2).** All 8 cards measured `timerOverflowsMain: true` at 1280px (the elapsed box's right edge exceeds the card main-row right edge). Visible in screenshot 01: "ATTENTE" eyebrow clipped to "ATTENT"/"ATTEN", and the timer touches/exceeds the 3px card border. See §FIX timer.

### 2. The board @ 1920×1080 TV — screenshot `03-board-1920.png`
- Clean 4×2 grid; wider cards absorb the timer, `overflow:false` at 1920 for the sampled cards. **The clip is viewport-dependent — present ≤~1280, gone at 1920.** A real Le Cayenne TV at 1080p/1920 is fine; a smaller/zoomed display is not.
- **MINOR — overflow chip overlaps the "LOCAL" banner tag (P3).** The orange `+12 en attente` chip sits over the banner's right-edge "LOCAL" tag despite `reserveRightGutter` (152px estimate, `KdsStatusBanner.vue:206-208`, the comment itself flags it as an unverified estimate). Cosmetic.

### 3. Order card detail — screenshot `02-card-zoom.png` / `08-card-onset-zoom.png`
- Layout intact: stripe, header, scrollable body (visible 8px scrollbar + fade), 52px CTA. Customization rows (Choix/Sauce/Viandes) render correctly. Allergen + delivery blocks present.
- **The elapsed column has ZERO horizontal headroom even at a 2-digit timer:** with a 5-char alpha queue ("A0001"/"A0002") the "00:57" timer already measures `overflow:true` (`ONSET_METRICS` cards B/C/D). A 3-digit-minute value ("100:00", reached at 1h40m) overflows by construction.

### 4. History drawer — screenshot `05-history-drawer.png`
- Well-built, read-only, scrollable. **MINOR (P3):** timestamp copy is en-US-flavoured for FR locale — "PASSÉE À 10:09 **AM**" (12h+AM) next to "TERMINÉE À 10:10" (bare) is inconsistent; FR convention is 24h "10:09". Cosmetic.

### 5. Legacy 4-lane layout (`?v2=0`) — screenshot `06-legacy-1280.png`
- Full toolbar present here ONLY: **Station KDS** dropdown, **Regrouper par table**, **Son nouveau ticket** toggle + **Volume** slider, items board ("Préparations"), filter tabs (Toutes/Confirmées/En Préparation/Terminées), search box, 4 lanes. Legacy bump/recall buttons ("Démarrer la préparation"/"Marquer comme terminé") render.
- `LEGACY_STATION_FILTER=1`, `LEGACY_AUDIO_EL=1` (vs `…_in_V2=0`).

### Fullscreen toggle — ABSENT (both layouts)
- `grep -rni "fullscreen|requestFullscreen|plein écran"` over the KDS components returns **nothing**. There is **no in-app fullscreen control** in either layout; the TV board relies on the browser/OS (F11) to go fullscreen. A missing expected control for a TV-mounted KDS is itself a gap (P3 — owner may accept F11).

---

## PRIORITIZED FIX LIST (non-frozen)

### [P2] ATTENTE timer never rolls up past 59 min → unreadable at all sizes + clips ≤1280px
- **file:line** `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:333-338` (`elapsedFormatted`) — emits raw `mm:ss` with `Math.floor(s/60)` for minutes and NO hours/days rollup. CSS column at `:572-626` (`.kds-card__main` / `.kds-card__elapsed-wrap` / `.kds-card__elapsed`) under `.kds-card { overflow: hidden }` (`:427`).
- **broken (two layers, one root cause):**
  1. *Readability* — at ANY resolution, `15592:35` (and even `100:00` at 1h40m) is meaningless: the timer never says "1h40" or "2h". Confirmed values `["15593:04","15590:13",…]` (`ALL_ELAPSED`).
  2. *Clip* — `overflow:hidden` cuts the digits/label at ≤~1280px. Measured `timerOverflowsMain:true` for all 8 cards at 1280 (`TIMER_METRICS`); even fresh 2-digit "00:57" overflows on 5-char alpha queues (`ONSET_METRICS`). The column is tuned to *just* fit 5 chars — the in-file Wave T comment (`:565-571`) documents a prior regression where 2-digit `14:26` clipped to `14:2` before the clamp fix, proving a 6th char overflows by construction.
- **repro:** any KDS active order older than ~100 min (a forgotten/aged ticket on a slow night) renders 3-digit minutes; on a ≤1280 display the seconds + "ATTENTE" label clip.
- **screenshot:** `01-board-1280.png` (clip onset/extreme), `02-card-zoom.png`, `03-board-1920.png` (no-clip-but-still-nonsense `15592:35`), `07/08-onset`.
- **fix:** roll up in `elapsedFormatted` — `<60min → mm:ss`; `<24h → {h}h{mm}`; `≥24h → {j}j {h}h`. Mirrors the rollup ALREADY shipped in this same component family at `KdsV2Grid.servedAgoLabel:387-402` (min→h→j) — reuse that pattern + the existing `label.kds_served_ago_hours/_days` keys (or add `label.kds_attente_hours`). Fixes BOTH readability and clip. Optional defense: relax `.kds-card { overflow: hidden }` on the timer column or shrink the clamp min so 3 digits fit. **NOT owner_gated** (KdsOrderCard is non-frozen).

### [P2] New-order chime is DEAD on the default V2 board (no audio element rendered)
- **file:line** `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:234` — `<audio ref="kdsNewOrderAudio" src="/sounds/kds-new-order.mp3">` lives **inside the `<template v-else>` legacy block (lines 48-1073)**, so it is **absent in the default V2 layout**.
- **broken:** the chime call site (`orders` watcher, `:1459-1471`) fires `playKdsNewOrderSound()` on every new order ID **regardless of layout** — but `playKdsNewOrderSound` (`:1824-1841`) early-returns at `:1835-1837` when `this.$refs.kdsNewOrderAudio` is `undefined`, which is ALWAYS true in V2. Net effect: **a chef on the default board gets NO audible alert for new tickets** — same operational risk class as the overflow chip (orders age unnoticed while the chef is plating / facing the line). Only one audio path exists (grep `new Audio|AudioContext|.play(` → only this element); no Web-Audio fallback.
- **repro (live-verified by absence):** `AUDIO_EL_in_V2=0` on the default board vs `LEGACY_AUDIO_EL=1` under `?v2=0`.
- **screenshot:** `01-board-1280.png` (no audio/sound control anywhere on the V2 board) vs `06-legacy-1280.png` ("Son nouveau ticket" + Volume present).
- **fix:** **hoist the single `<audio>` element out of `<template v-else>`** so it renders in both layouts (e.g. place it next to the always-rendered `<KdsHistoryDrawer>` at the top, lines 9-26). The watcher + `playKdsNewOrderSound` already work — this is purely a render-location bug. Low cost, high operational value. **NOT owner_gated** (this component is non-frozen).
- **Priority note:** set at P2 (single-box, chef usually at the screen). Escalate to **P1 if the owner asserts the chime is the primary new-order alert** for a chef who steps away from the board.

### [P2/P3 — owner_gated DESIGN question] Sound toggle, volume, station filter, group-by-table are V2-unreachable
- **file:line** `KitchenDisplaySystemComponent.vue:207-234` — the entire toolbar (`#kds-station-filter`, group-by-table checkbox, `soundEnabled` toggle, `soundVolume` range) is inside the `v-else` legacy block → `STATION_FILTER_in_V2=0`, `SOUND_TOGGLE_in_V2=0`.
- **broken:** on the default V2 board the chef CANNOT mute/adjust the chime (moot today only because the chime is dead — once the chime is fixed per the finding above, an un-mutable chime becomes a live annoyance), CANNOT filter by station, CANNOT group by table. Changing any of these requires manually appending `?v2=0`.
- **repro:** counts above; toolbar visible only in `06-legacy-1280.png`.
- **fix:** **owner decision** — V2 may have been intentionally designed as a clean TV board without a toolbar. If so, at minimum port the **sound mute + volume** into V2 (paired with the chime fix). Station-filter/group-by-table may legitimately stay legacy-only for a single-chef takeaway box. **owner_gated** (design intent, not a defect).

### [P3] Overflow chip overlaps "LOCAL" banner tag at 1920
- **file:line** `KdsStatusBanner.vue:206-208` (`.kds-banner--reserve-gutter { padding-right: 152px }` — the in-file comment admits 152px is an unverified estimate) vs `KdsV2Grid.vue:451-467` (`.kds-overflow-chip` absolute top:16 right:16).
- **broken:** chip sits over the banner's "LOCAL" tag (screenshot `03-board-1920.png`).
- **fix:** widen the reserved gutter or right-align the tag below the chip's z-stack. **NOT owner_gated.**

### [P3] FR locale: history-drawer timestamp "10:09 AM" (12h+AM) inconsistent with FR 24h
- **file:line** `KdsHistoryDrawer.vue` (timestamp formatting) — "PASSÉE À 10:09 AM" next to bare "TERMINÉE À 10:10".
- **fix:** format both as 24h "10:09" (FR convention). Mirrors the known POS-ERG-07 en-US locale class of finding. **NOT owner_gated.**

### [P3] No in-app fullscreen control for the TV board
- **file:line** N/A (absence — `grep fullscreen` over KDS components empty).
- **fix:** optional fullscreen toggle button (Fullscreen API) so the TV board can be kiosk-ed without OS F11. **owner_gated** (owner may accept F11/kiosk-mode launch).

---

## OUT-OF-SCOPE (noted, not UI/UX)
- **Media N+1** (falsification-flagged P2): the KDS feed eager-loads `orderItems.orderItem`, `address`, `user` (`app/Services/KitchenDisplaySystemOrderService.php:73,229`) — NO media loaded, and the resources (`KDSOrderItemsResource`, `KDSOrderDetailsResource`) reference no `media`/`image`. The card UI renders no product images. Any media N+1 is backend-only and does not manifest in this surface → deferred to a backend-perf pass, outside this UI/UX mission.

## PRIOR HEALS — confirmed still good (not re-litigated)
- FP-11/FP-12 (unpaid badge + conflict i18n namespaces): cash-pending note renders FR-clean (screenshot 01); `message.kds_status_conflict` resolves. ✅
- FP-20 (N+1 eager-load): the feed eager-loads its relations (service:73/229). ✅

---

## TOP MUST-FIX
1. **[P2] ATTENTE timer hours/days rollup** (`KdsOrderCard.vue:333-338`) — reuse the `servedAgoLabel` rollup pattern; fixes both the ≤1280 clip and the all-resolution unreadability of `15592:35`.
2. **[P2] Hoist the `<audio>` chime out of `v-else`** (`KitchenDisplaySystemComponent.vue:234` → top-level) — the chef gets zero audible new-order alert on the default board today; one-line render-location fix.
3. **[owner_gated] Decide V2 toolbar scope** — at minimum port sound mute+volume into V2 alongside the chime fix.
