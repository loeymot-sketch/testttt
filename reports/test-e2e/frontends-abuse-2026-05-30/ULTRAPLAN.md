# ULTRAPLAN — Abuse-E2E Goal: Mobile + Web Standalone Frontends (2026-05-30)

> Owner /goal abuse-e2e. Boss/supervisor/dev/brain. Validate to production-ready the
> TWO forgotten standalone frontends. Backend (Caisse/Borne/KDS/OSS) = GO, OUT OF SCOPE.

## NON-NEGOTIABLE MANDATES
1. **STANDALONE, UN-WIRED**: mobile + web stay disconnected from backend APIs. Future sync
   = read DB + transfer system LATER. No useless complexity. composer_profile hardcoded
   mirror = accepted pattern. DON'T wire.
2. **SSOT MENU**: 45 canonical V1 items. Sources: DB items table / config/menu.php /
   mobile/data/menu.js / /Users/1millnonstop/Downloads/web/data/menu.js. NEVER invent
   product/category/price. grep-verify before writing.
3. **IMAGES**: owner updated kiosk photos 2026-05-30. Each product must show correct,
   current image on mobile AND web — not stale, not broken placeholder. Judge EMPIRICALLY
   on rendered screen.
4. **PALETTE**: mobile = BLACK/ORANGE/YELLOW/WHITE (NOT Cayenne red #F4501E). Web = its own
   standalone charter. Confirm surface before applying color.
5. **Zero useless complexity. P3/cosmetic → backlog. Never break green for a P3.**

## ENV (confirmed)
- Mobile: http://127.0.0.1:8081/ (php -S on mobile/, JSX-in-browser) → 200
- Web:    http://127.0.0.1:8095/ (php -S on /Users/1millnonstop/Downloads/web/) → 200
- DB SSOT: 45 items exported → DB_CANONICAL_ITEMS.txt (../supervisor-full-campaign-2026-05-30/)
- Baseline gates (backend, for ref): PHP 2716/0, vitest 1881/0 — NOT in scope.
- IMAGE DIVERGENCE CONFIRMED: kiosk photos 2026-05-30 fresh; mobile+web assets frozen 2026-05-17.

## ARCHITECTURE
- MAIN LOOP (me): drives Playwright serially, captures + analyzes EVERY screenshot (both surfaces).
- PARALLEL read-only agents: data parity (menu.js × DB × kiosk), wizard-logic audit, ADVERSARIAL dispute.
- Convergence: 2 consecutive rounds with P0+P1 = 0 and identical findings set.
- Heal: P0/P1 only, scope-minimal, root-cause. P2 disclose. P3 backlog. Max 3 heal cycles/cluster → escalate.
- Anti-hallucination: every finding = file:line + real repro (screenshot/DOM), else REJECTED.

## WAVE DECOMPOSITION

### MOBILE (palette black/orange/yellow/white)
- **M1** Home/menu shell + all categories + item cards + IMAGE freshness/correctness per product
- **M2** Wizard composition — every template branch: sandwich (viande/crudités/sauces/suppléments),
  tacos, big variants, bols 3-step (viande→sauce→suppléments), frites 1-step, menu/formule (drink cascade)
- **M3** Abuse + cart/recap + checkout-stop: qty 0/max, all option combos, empty/full cart,
  add/remove/re-add, back, double-tap, item-without-image; standalone stop clean/intentional
- **M4** Responsive (≥2 sizes) + palette/branding audit + technical hygiene (raw labels/console/404)

### WEB (own charter)
- **W1** Home/menu + categories + item cards + IMAGE freshness/correctness × viewports
- **W2** Wizard composition — same template matrix
- **W3** Abuse + cart/recap + checkout-stop
- **W4** Responsive mobile(390)/tablet(768)/desktop(1280) + branding + hygiene

### CROSS
- **P** PARITY: data-level mobile.js × web.js × DB SSOT × kiosk photos (read-only agent). Any divergence = finding.
- **RED** ADVERSARIAL: dispute every captured artifact (visual-first), hunt hidden defects.
- **SYNC-READY** (doc-only): confirm composer_profile/item_id/options aligned to DB for future mechanical transfer. DOCUMENT, don't wire.

## WAVE TRACKER (round 1)
| Wave | Status | Evidence |
|------|--------|----------|
| W0 baseline | ✅ | PHP 2716/0, vitest 1881/0 (backend, ref only) |
| P parity (agent) | ✅ | parity-findings.md — 0 invented products, 0 missing items, images resolve; 5 price divergences vs DB → F-PRICE-01 ESCALATED (owner decision, documented intent) |
| M1-M4 mobile abuse (agent a96ff01b) | 🔄 | scripted headless capture+audit running; my own: home+menu analyzed, palette ✓, 17/17 realignment spec PASS |
| W1-W4 web abuse (agent a0658fec) | 🔄 | scripted headless capture+audit ×3 viewports running |
| IMG kiosk-vs-app (agent ac6172aa) | 🔄 | read-only image divergence audit running |
| RED adversarial | ⏳ | after captures land |
| SYNC-READY doc | ⏳ | parity agent noted: composer_profile shape aligned, id-keys synthetic (101/201) vs DB (22/25) — mapping table needed |

### My own verified observations (main reasoner)
- Mobile palette = black/orange/yellow/white ✓ (onboarding, menu, login/OTP all compliant — NO Cayenne red).
- Login = mock SMS OTP (demo code 1234), 2 steps render clean; standalone, un-wired.
- Images: all 41 products mapped to real generated_*.png (none broken); image REUSE within app (cayenne==big-cayenne, 8 bowls share 1) = P3 backlog B-ML-04.
- Mobile internal consistency: 17/17 realignment logic+pricing+wizard+cart tests PASS.

## FINDINGS LEDGER (verify-before-report gated)
(none yet)

## HEAL LEDGER
(none yet)

## VERDICT (per surface, written at convergence)
(pending)
