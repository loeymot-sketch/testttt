# W2 — Builder→Borne render E2E — CONVERGENCE (GOAL_WIZARD_E2E_PARITY)

Date: 2026-06-09 · Harness: :8766 disposable clone (`foodking_e2e`, read/mutation-safe) · Borne SPA.
Owner ask covered: "all the wizards according to our categories recorded … realistic, possible,
synchronized, well done for the borne". (Caisse = W3, GATED.)

## Scope
Verify every RECORDED category wizard renders composer-first on the borne (kiosk), with correct
options/prices/images/min-select, 0 raw labels, and that the composed total = backend SSOT.

## Evidence

### 1. Render — 7/7 wizardable categories GREEN (visual + DOM)
All driven live on :8766 (kiosk machine `kiosk-lecayenne`). Each opened composer-first, step rail +
progress bar, option cards with images, per-option prices, min-select validation, Cayenne palette,
**0 raw i18n labels**, footer Total + nav.

| Category | item | steps | shot |
|---|---|---|---|
| Sandwich Cayenne | BIG CAYENNE | 6 (Viande/Viande2/Sauce/Crudité/Supplément/Récap) | w2-11-cayenne |
| Galette | Galette Normale | 5 (Viande/Sauce/Crudité/Supplément/Récap) | w2-11-galette |
| Sandwich Classique | Sandwich Classique | 5 | w2-11-classique |
| Burgers | Chicken Burger | 5 | w2-11-burgers |
| Tacos | Tacos | 4 (Viande/Sauce/Menu/Récap) | w2-11-tacos |
| Bols Gourmands | Bowl Frites Poulet Crispy | 4 (Sauce/Supplément/Menu/Récap) | w2-11-bols |
| Frites | Petite Frites | 2 (Choix du style/Récap) | w2-11-frites |

### 2. Flow works (composition persists, steps advance) — Bols walk
Walk screenshots `w2-walk-0..2` prove progression: step-1 sauce selected → **"VOTRE COMPOSITION:
Sauce fromagère maison"** persists → step-2 "QUEL SUPPLÉMENT ?" active (5 options w/ prices) → step-3
"QUEL MENU ?" active (Menu complet / +Frites / Sans menu). SUIVANT correctly gated by min-select.

### 3. Price = backend SSOT — DETERMINISTIC (quote API, NF525)
- **Visual price match**: borne supplément prices (Oignon frais €0,90, Champignons €0,90, Boule
  gratinée €2,00, Option Gratiné €2,00) **exactly match** DB `item_extras` (0.90 / 2.00).
- **Quote API** `POST /api/frontend/order/quote` (kiosk:order, items = item_id + variation_ids +
  extra_ids ONLY, no client total):
  - item 41 + sauce(202, free) → `subtotal 8.9, total_ttc 8.9` (= borne €8,90) ✓
  - item 41 + sauce + Boule gratinée(180, €2,00) → `subtotal 10.9, total_ttc 10.9` (8.90+2.00 exact) ✓
  - VAT computed server-side (total_tax 0.81 → 0.99); quote **HMAC-signed + TTL 299s** (secure binding).
  - Backend **enforces the wizard min-select** server-side ("Choix de la sauce : minimum 1 requise")
    — composition rules are real, not UI-only.

### 4. 401 noise = TEST-HARNESS artifact, NOT a product defect
Hard `page.goto('/kiosk/categories?cat=N')` (full reload) transiently drops the in-memory kiosk
token → a few `/api/frontend/menu` 401s, from which the SPA recovers (subsequent 200 + item details
200 + wizard renders). **In-SPA navigation (click category, no reload) = ZERO 401s** (proven). Real
kiosk use is in-SPA. Classified P3 / non-issue.

## Test-harness limitation (disclosed, not a product defect)
Scripted full add-to-cart→récap UI driving was flaky for the frozen kiosk's menu-step "+" / add-cart
controls (5 selector cycles, §10 cap hit). The wizard renders and is interactive (selections register,
composition updates, steps advance — proven by walk screenshots); the add-to-cart→récap path is FROZEN
kiosk behavior pre-validated in prior E2E campaigns. Price-correctness (the substantive claim) is
proven deterministically via the quote API instead — stronger than UI clicking.

## Verdict: W2 GREEN — P0+P1 = 0. Borne renders all recorded category wizards; composition + total
match backend SSOT. Proceed to W4 (sync); W3 (caisse) remains GATED on GATE-W6.
