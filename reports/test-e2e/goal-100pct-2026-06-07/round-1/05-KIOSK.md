# AGENT 05 — SYSTÈME KIOSK / BORNE — Round 1 report (2026-06-07)

**Scope:** Borne client complète — WIZARD composeur (4 templates), écrans erreur, panier,
upsell, loyalty, paiement Plan-B, 10 commandes variées + intégrité composition_snapshot.
**Harness:** disposable clone `foodking_e2e` @ `http://127.0.0.1:8766` (auto-login kiosk),
headless Playwright, retries=0, serial. **Frozen components piloted, never modified.**

**Verdict: PASS — blocking=false, 0 P0 / 0 P1.** The borne is functionally complete in
software: the FROZEN composer wizard works across all 4 template families, every order
seals a correct composition_snapshot (NF525 SSOT), error/cart/loyalty/upsell/payment screens
all render in FR with recovery affordances and zero raw labels / JS errors. Only software
gap to physical hardware = printing (out of my scope, agent 09).

---

## Specs authored (additive, untracked — `tests/e2e/zz-kiosk-*-2026-06-07.spec.js`)
1. `zz-kiosk-wizard-composer-2026-06-07.spec.js` — drives the FROZEN wizard for Sandwich(22)/Tacos(26)/Burger(38)/Bowl-profile(41), walks every step, reaches "Ajouter au panier". **5/5 passed.**
2. `zz-kiosk-wizard-order-snapshot-2026-06-07.spec.js` — composes Tacos → places counter order → inspects DB composition_snapshot. **1/1 passed.**
3. `zz-kiosk-error-cart-loyalty-2026-06-07.spec.js` — 4 error screens + loyalty + cart ops (qty±/remove/clear/promo/fidélité). **7/7 passed.**
4. `zz-kiosk-10-orders-2026-06-07.spec.js` — 10 varied borne orders (simple+wizard+multi) + batch DB integrity. **1/1 passed (12 min).**
5. `zz-kiosk-upsell-add-2026-06-07.spec.js` — upsell ADD path (not just skip). **1/1 passed.**

---

## AXE A — TECHNIQUE
- **A4 prix backend SSOT:** front opens wizard with item_id+options only; price computed server-side.
  Tacos order 4178 → `price=8.50, item_variation_total=0, item_extra_total=0` (Tacos base; 1ère sauce gratuite, viande incluse). PASS.
- **A5 composition_snapshot figé:** order 4178 / item 26 sealed EXACTLY my wizard picks:
  `lines:[{attribute_name:"Viande 1",variation_name:"Poulet mariné",variation_id:43},{attribute_name:"Sauce (1ère Gratuite)",variation_name:"Algérienne",variation_id:311}], addons:[], extras:[], schema_version:1, captured_at:"2026-06-07T18:15:59+02:00"`. No silent drop. **STRONGEST EVIDENCE** (single order placed + immediately queried). PASS.
- **A6 régression:** out of my read-only scope (no product edits); frozen kiosk diff = 0 lines.

## AXE B — INTERFACE
- **B1 WIZARD composeur (4 templates) — the mission core, never tested before:**
  - Sandwich Cayenne (22): 6 steps Viande→Sauce→Crudité→Supplément→Menu→Récap, heuristic-open via variations/extras (no published profile). PASS.
  - Tacos (26): 4 steps (Viande→Sauce→Menu→Récap). PASS.
  - Chicken Burger (38): 6 steps. PASS.
  - Bowl Frites Poulet (41): 4 steps from PUBLISHED composer profile (template=custom: sauce min1 / supplements min0 / drink min0 / récap). PASS.
  - Required steps enforce min_select≥1 (Next disabled until a choice picked); composition chips update live ("VOTRE COMPOSITION"). Recap renders "RÉCAPITULATIF DE VOTRE COMMANDE" + instructions field + orange "AJOUTER AU PANIER".
- **B (panier):** qty `+` 1→3, qty `−` →2, remove → cart empty, "Vider le panier" (clear w/ confirm modal), Code promo input+Appliquer, **"Avez-vous une carte fidélité ?"** prompt — all DRIVEN + asserted. PASS.
- **B2 écrans erreur (4):** all render clear FR + recovery btn, no raw label, no crash:
  - `/kiosk/error/network` → "📡 Connexion perdue…" (3 btns)
  - `/kiosk/error/menu-unavailable` → "🍽️ Menu momentanément indisponible…" (3 btns)
  - `/kiosk/error/product-removed` → "🚫 Cet article n'est plus disponible…" (3 btns)
  - `/kiosk/error/payment-refused` → "❌ Paiement refusé… réessayer, régler en caisse…" (4 btns)
- **B3 raw labels:** 0 across ALL screens (regex `\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b`). PASS.

## AXE C — VISUEL (captures analysées, vue CLIENT, light mode)
- Idle "Bienvenue ! / À emporter / Je récupère ma commande" + Le Cayenne branding. PASS.
- Categories: sidebar nav + product grid + HALAL/VÉGÉTARIEN badges + cart indicator. PASS.
- Wizard steps + recap: Cayenne orange `#F4501E` / yellow accents, light mode, product images present. PASS.
- Payment Plan-B "PAIEMENT À LA CAISSE / TOTAL À RÉGLER €X,XX / Confirmer ma commande". PASS.
- Upsell "ET POUR TERMINER ?" 3 cards + "Non merci, continuer sans". PASS (minor: upsell card thumbnails render as emoji fallback — cosmetic, P3).
- Loyalty: star + numeric keypad + "Vérifier mon code" + "Continuer sans fidélité". PASS.
- Captures: `tests/e2e/__screenshots__/kiosk-*-2026-06-07/`.

## AXE D — FLUIDITÉ / UX
- D2 full parcours commande→encaissement(counter)→retour idle, no freeze, 0 JS pageerror across all runs.
- D3 reprise erreur: 4 error screens each offer a recovery affordance (réessayer / retour menu / caisse).
- D4 upsell ADD verified joins cart (€1 drink + upsell → €3 payment). PASS.

## AXE F — DONNÉES (kiosk slice; full DB = agent 02)
- **10 commandes variées placées** — recipe→row 1:1 item-set match (clean attribution despite
  concurrent agents in the shared `foodking_e2e` clone):
  O1→4202(58) O2→4203(49) O3→4204(22) O4→4205(26) O5→4207(38) O6→4208(41)
  O7→4210(52,50) O8→4211(26,53) O9→4212(54,59) O10→4213(22,55).
- All `source_surface=kiosk`, every order ≥1 order_item, multi-item orders sealed N lines + correct totals (O7 €5.30, O8 €10.00, O9 €3.00, O10 €8.50).
- **6 wizard-composed items** (O3-O6,O8,O10) sealed composition_snapshot lines (matches my 6 wizard recipes).
- **fiscal_sequence_no = NULL on every PENDING_COUNTER kiosk order** — CORRECT NF525 (§8): kiosk Plan-B fiscal allocation happens at COUNTER ENCASHMENT, not kiosk creation. payment_status=15 (PENDING_COUNTER), status=4 (ACCEPT).
- `fiscal:verify-chain --all` on e2e clone post-volume → **CHAIN OK** (kiosk PENDING orders don't touch audit chain until paid).

## AXE G — CLIENT vs OPÉRATEUR
- G1 Client borne: parcours commande clair, composition lisible, prix visible à chaque étape, escape hatches partout ("Abandonner", "Continuer sans"). Evaluated usable for self-order. PASS.

---

## i18n
FR verrouillé (ADR-007), 0 raw label on any surface. Menu "none" option = "Sans menu" (`kiosk.wizard.menu.none_name`). PASS.

## Frozen-zone discipline
`git diff --stat` on KioskWizardComponent.vue / KioskAppComponent.vue / KioskUpsellComponent.vue = **0 lines**. Only additive untracked spec files created. PASS.

---

## FINDINGS

### Dropped (false positive — anti-hallucination)
- **NOT a defect:** a surplus kiosk order (4209, item 58, €1, created 2s before O7's 4210) initially
  looked like a double-submit. **Refuted:** its item (58/Eau Plate) matches O1's recipe not O7's; a
  self-double-submit of O1 (placed 7 min earlier as 4202) can't land at 18:30:14; order 4206 in the
  same window is `source_surface=pos` (agent 04). → **parallel-agent contamination of the shared
  `foodking_e2e` clone** (W5 runs agents 04-08 concurrently), not a borne bug. Filter `id>baseline AND
  source_surface='kiosk'` catches every agent's kiosk orders. Dropped per CLAUDE.md §3ter.

### P3 (cosmetic, non-blocking)
- **F-KIOSK-P3-01** — `KioskUpsellComponent.vue:45-48` — upsell suggestion cards render the emoji
  fallback (`kiosk-upsell-img-fallback`) instead of a product thumbnail (`item.thumb||item.image` empty
  for the upsell payload). Repro: cart→checkout→"ET POUR TERMINER ?" screen; cards show grey/emoji, not
  food photos. Evidence: `__screenshots__/kiosk-full-order-2026-06-07/2b-upsell.png`. Reco: ensure the
  upsell API resource includes `thumb`. (Frozen component — UI is fine; the gap is the data payload.)

### Transient (noted, not escalated)
- Bowl-profile full-sweep logged `consoleErr=1` once; a clean re-open showed `CONSOLE_ERRORS=[]` and
  0 pageerror across all runs. Transient resource hiccup during rapid step-walking, not functional.

---

## PASS BAR (mission file)
WIZARD complet (4 templates) ✅ · écrans erreur (4) ✅ · 10 commandes ✅ · chaque écran capturé+analysé ✅
· composition_snapshot intègre ✅ · upsell add+skip ✅ · loyalty ✅ · cart ops ✅ · i18n FR 0 raw ✅
· frozen diff=0 ✅ · NF525 chain OK ✅. **BAR MET.**

## Concurrency caveat (for the supervisor)
The shared `foodking_e2e` clone had ≥1 other writer (agents 04/POS, others) during my batch. My
DB claims are re-based on **recipe→row item-set attribution** (clean), not raw counts. No mutation
of the operating DB `foodking`. NF525 chain on the clone = OK.
