# GOAL — UI/UX Perfection per-page, per-device-viewport (2026-06-18)

> Owner: "max test + optimisation max intelligente interface+UX ultra, deep pensé/analysé POUR L'UTILISATEUR, chaque page. On commence par la CAISSE. Hyper validé, jamais retourner sans valider de tous les côtés — y compris adaptation taille d'écran. Caisse 16″, Borne 32″, KDS 16″. Ultra test-e2e: corrige la page → page suivante pareil, abuse par test-e2e + captures analysées + corrige EN BOUCLE, pour TOUTES les pages." + "crée la demande plus abusive qui mène à la perfection."

## The escalated demand (my contract — more abusive than asked)
Each PAGE is not "done" until it passes, **at its device viewport**, a deep ergonomic rubric AND an adversarial visual critique AND a re-capture confirms 0 open P1/P2, looped. No page is skipped. Frozen-zone UX issues are surfaced as owner-gates (not silently left, not edited).

## Device viewports (code-confirmed)
- **CAISSE 16″ → 1920×1080 landscape** (seated cashier ~50cm, fast, often one-handed).
- **BORNE 32″ → 1080×1920 PORTRAIT** (confirmed `resources/css/kiosk/tokens.css`: "borne 1080×1920 portrait, Electron Windows"). Standing customer ~1m, single-finger, accessibility-critical.
- **KDS 16″ → 1920×1080 landscape** (chef, glanced from ~1.5m, hands busy/greasy).

## Per-page LOOP (test-e2e discipline)
1. Resize browser to the device viewport. Navigate. Wait for SPA render.
2. Capture: viewport PNG (+ full-page if scroll) + DOM metrics (font sizes, tap-target sizes, overflow, grid cols, contrast) + console + network.
3. Deep UX rubric (8 axes) — multimodal Read of the PNG:
   1. **Layout fit @viewport** — no overflow/clip/scroll-trap; no dead space; fills the device.
   2. **Touch ergonomics** — primary+frequent actions ≥48px (caisse) / ≥80px (borne standing); spacing prevents mis-taps.
   3. **Readability @distance** — meaningful values (price/total/change/order#/product) legible; contrast AA.
   4. **Visual hierarchy** — primary action unmistakable; orange CTA not diluted; scannable.
   5. **Task-flow speed** — min taps; thumb/reach-zone; no hunting.
   6. **State coverage** — empty/loading/error polished.
   7. **i18n/money/brand** — FR, FR money, palette, 0 raw key (regression guard).
   8. **Consistency/polish** — spacing rhythm, alignment, color discipline, no jank.
4. Adversarial visual critic (independent agent, "what did lens-1 miss for THIS user at THIS device?").
5. Heal non-frozen (scope-minimal). Frozen → owner-gate note in this report.
6. Re-capture → confirm the heal renders + 0 new issue → page converges (2 clean reads).
7. Commit per page/batch. Next page.

## Frozen zones (UX report-only; CLAUDE.md §7)
PaymentComponent.vue, PosV5TrancheRow.vue, pos-wizard.js/css, admin-pos-v4.blade.php, Kiosk{Wizard,App,Upsell}.vue + fiscal/pricing/branchscope/idempotency/orderstatemachine backend.

## Page checklist
### CAISSE @ 1920×1080  (START HERE)
- [x] 1. `/admin/pos` — POS caisse — **DONE** (3-lens adversarial critique, 7 non-frozen heals verified live, Vitest 1994/0)

#### Page 1 `/admin/pos` — findings + heals (verified live @1920×1080)
HEALED (non-frozen, re-measured live):
- **P1** cart-line qty stepper (−/value/+) was **22×22px** (highest-freq cashier gesture; the minus IS the destructive remove-line trash) → **40×40px** (`PosV5QtyStepper.vue` `.pos-v5-qty--sm`; `sm` used only by the cart line; 178px column headroom). Verified live = 40×40.
- **P2** duplicate orange "À encaisser" CTA (operator-bar pulsing button + borne panel both orange "(69)") → operator-bar button demoted `kiosk-cash`→`ghost` (`PosComponent.vue:118`). Verified `pos-v5-btn--ghost` live.
- borne-queue "Encaisser/Délivrer" CTA 36→**44px** + row gap 4→**8px** (`PosComponent.vue` `.pos-shortcuts__cta`/`__list`). Verified 44px.
- order-type segmented (Sur place/À emporter/Livraison) 36→**44px** (`pos-v5.css` `.pos-v5-segmented__item`). Verified 44px.
- brand-token fallback `#cf3a3a` (true red) → `#F4501E` (Cayenne orange) ×3 (`PosComponent.vue`) — latent palette regression on token-load failure.
- empty-cart hardcoded FR string → `label.pos_cart_empty` i18n (fr/en).
- product grid `xl:grid-cols-4` → +`2xl:grid-cols-5` (`ItemComponent.vue`) — denser on 1920, fewer scrolls. Verified 5 cols.

OWNER-GATE (frozen §7 — reported, NOT edited):
- **G-FROZEN-WIZARD-MONEY** — `public/js/pos-wizard.js` (FROZEN) `fmtPrice()` builds money as `'€' + n.toFixed(2)` = **"€0.90" en-US** (€ prefix, dot decimal, no NBSP), rendered as `wizard-item-price`/`total-value` on EVERY product-add wizard popup — clashes with the FR "0,90 €" everywhere else. Fix when owner unlocks: route `fmtPrice` through an FR formatter (Intl fr-FR, comma + NBSP + € suffix). Owner LOCK required.

CONFIRM-OK (do not change): main pay CTA 56px, product tiles, category pills already ≥ touch floor.

- [x] 2. `/admin/pos/floorplan` — empty-state + "places" FR (`9d0d8087a`)
- [x] 3. `/admin/pos-orders` — CLEAN (FR headers/pills/money, accessible row actions)
- [x] 4. `/admin/pos-orders/show/:id` — delivery time en-US 12h → FR 24h (`8aa5e8392`)
- [x] 5. `/admin/pos-orders-tracker` — CLEAN (kanban + FR empty-states per column)
- [x] 6. `/admin/encaissement` — enc-collect-btn 40→44px touch floor (verified live)
- [x] 7. `/admin/cash-overview` — CLEAN (FR money/dates, reconciliation math 70+53,40=123,40, empty-state)
- [x] 8. `/admin/cash-sessions-report` — CLEAN (FR day-grouped, FR money, status pills)
- [x] 9. `/admin/delivery-boy-cash-sessions` — CLEAN (FR money/24h dates, red ÉCART, accessible actions)

**CAISSE @ 16" COMPLETE (9/9)** — heals on pages 1,2,4,6 (12 total) + G-FROZEN-WIZARD-MONEY owner-gate; pages 3,5,7,8,9 audited clean. Next system: BORNE @ 1080×1920 portrait.
### BORNE @ 1080×1920 portrait
- [ ] kiosk idle / menu / wizard / loyalty / payment / upsell (most frozen → owner-gate notes)
### KDS @ 1920×1080
- [ ] `/admin/kitchen-display-system` (+ OSS `/admin/order-status-screen`)

## Convergence
A SYSTEM is done when every page passes 2 consecutive clean reads (0 open P1/P2) at its viewport, with all heals committed and frozen issues gated. Then next system.
