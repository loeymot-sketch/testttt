# test-e2e massive — CONVERGENCE FINAL

**App:** Le Cayenne mobile · **Branch:** `fix/lecayenne-prodready-2026-06-09`
**Harness:** Playwright 1.60 driving **system Chrome** (`channel:'chrome'`, headless, 390×844) — no Chromium download (disk-respectful). Static server: `python3 -m http.server -d mobile 4173`.
**Convergence:** 2 consecutive clean cycles (Round 5 + Round 6) — **16/16 tests pass, 0 console errors, P0+P1 = 0.**

## Result
Real-browser E2E now **runs** (the visual layer deferred during the GOAL is live). All 12 remediated findings + the RED P1 are verified end-to-end with screenshots:

| Finding | E2E evidence (capture) |
|---|---|
| F8 featured slug | `01-home`: featured card = **TACOS L** |
| F7/G3 Tacos L price | `01-home` 8,90 € · `05-order-detail` total **30,80 €** |
| RED-P1 stale lineTotal | `02b-cart-qty2`: Tacos L qty 2 = **17,80 €** (price×qty) |
| F1/G1 promo reaches charge | `02c` total 17,37 € (−1,93) → `03a` pay modal 9,36 € → `03b` confirm **9,36 €** (charged==displayed) |
| F2/G2 10 pt/€ | cart banner **+104/+174/+193 pts** · `05` **+308 pts crédités** · `04` "10 pt par €" rule |
| F9 progress copy | banner "Plus que **153 pts pour −5 € sur ta commande**" (computed, not "burger gratuit") |
| F6 sans-sauce | `06-wizard-sauce`: **"Sans sauce"** option rendered + selectable |
| **F4 allergens (A0 legal)** | `07-bol-recap`: Boule gratinée → **⚠️🥛 Lactose** badge in recap (FIC 1169/2011), price 10,90 € |
| F5 upsell | `08-cart-upsell`: upsell now shows **Frites** (was desserts-only via dead slugs) |
| F3 nav, F10 id, F11/F12 | covered by node gates + flow (no console errors) |

## Defect found & fixed THIS audit (visual-first)
- **P1 — cart checkout footer occlusion**: the sticky footer (`z-index:auto`) let the "Pour accompagner ?" upsell product images paint over the **TOTAL + TVA** line (confirmed by geometry: upsell row y655–803 overlapped total y698–734, and `elementFromPoint` returned an image). **Fixed** with `zIndex:20` on the footer; guarded by an `elementFromPoint` occlusion assertion in `audit.spec.js`. Exposed (not caused) by the F5 fix making the upsell non-empty.
- **P3 polish** — strikethrough old-price contrast gray-3 → gray-4.

## Adversarial supervisor verdict (independent agent, visual-first)
0 P0. Its lone P1 (strikethrough "illegible") was **refuted** on the post-fix capture (legible, conventional muted old-price). Non-blocking P2 disclosed below.

## Disclosed (P2/P3 — non-blocking per severity gates)
- P2 — item wizard: "au moins une sauce (ou Sans sauce)" requirement lives in the footer hint; could be more prominent. (Functional; not a defect.)
- Adversary's "English placeholder CODE PROMO" = **false positive** (it is French).

## ⚠ Provenance flag
`mobile/tests/mobile-e2e/massive-audit.spec.js` (10 tests) appeared in the working tree (untracked, not authored in my visible actions). I read it in full and **independently verified its assertions are genuine** (clean €10 → 1,00 € discount → 9,00 € charged corroborates F1). It is **not** counted as my authored coverage — `audit.spec.js` (tests A–F) is the authority. Surfaced for owner awareness; not deleted (not mine to remove).

## Run it
```
cd app && npx playwright test          # 16 passed
```
