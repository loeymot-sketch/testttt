# Live E2E findings — web+app 2026-06-04

## F-LIVE-01 [P1] WEB wizard "Menu complet" displays +2,50€ but charges +3,00€
- Repro (live): web 8083, Tacos M → Personnaliser → step 3 "Faire un menu?" → card shows "Menu complet +2,50€ / -1,50€ savings". Selecting it: TOTAL 6,90 → 9,90 (=+3,00 actually charged).
- Root cause: `web/wizard-v2.jsx:93` hardcodes `price: 2.50, savings: 1.50` for the 'full' menu option DISPLAY, while `web/data/menu.js:145` f-menu price=3.00 (charged via priceFor line 429).
- Cause: introduced by the 2026-05-30 caisse-sync (formule 2,50→3,00) which updated the SSOT but not the duplicated hardcoded wizard display constant.
- Fix (trivial-safe): wizard-v2.jsx:93 price 2.50→3.00, savings 1.50→1.00 (frites 2,00 + boisson 2,00 = 4,00 separate; menu 3,00 → saves 1,00).
- Severity: P1 (user-visible price-label ≠ actual charge; final TOTAL is correct so not P0 fiscal).

## F-LIVE-02 [P1] MOBILE wizard "Menu complet" option card displays +2,50€ but charges +3,00€
- Root cause: `mobile/screens-item-steps.jsx:525` hardcodes `price: 2.50` for the 'full' menu option card. The RECAP (line 784) was made dynamic in 05-30 sync (`_fMenuPrice`=3,00 canonical) but the STEP-5 option card constant (line 525) was left at 2,50 → HALF-FIX.
- Result: mobile option card shows "+2,50€", recap + charge use 3,00€ (canonical).
- Fix (trivial-safe): screens-item-steps.jsx:525 price 2.50→3.00 (align option card with canonical FORMULES + recap). Verify live on mobile E2E.
- Severity: P1 (price-label ≠ charge). Same class as F-LIVE-01.

## WEB FUNNEL — RESULT: ✅ FULLY FUNCTIONAL end-to-end
- Menu → Tacos M detail (6,90€) → wizard 7 steps (viande req / suppléments +0,90 / menu / drink / frites style / frites sauce / recap) → add to cart → cart slide-over (2 items, qty steppers, promo, pickup time, kitchen note, +17pts) → checkout (day+time picker) → payment (Payer en caisse CONSEILLÉ / CB Stripe / Apple Pay / Google Pay) → confirmation #C-8242 + pickup QR + TOTAL 16,90€ "Tu paies sur place".
- Price integrity in funnel: base 6,90 ✓ · +Cheddar 7,80 ✓ · +Menu TOTAL 9,90 ✓ · cart sous-total 16,90 ✓ · recap 16,90 ✓ · confirmation 16,90 ✓. All TOTALS correct.
- Allergen warning banner on recap ("Contient: gluten") — good safety/a11y.
- Console: clean (only Babel in-browser dev warning). Network: 0 failed.

## F-LIVE-03 [P3, low-confidence] funnel pages load retaining previous scroll position
- checkout→payment→confirm each loaded at scrollY ~567-655 instead of top. Partly confounded by Preview screenshot canvas (1600x900) > browser viewport (1280x720) artifact. Real impact minor (content reachable by scroll). NOT a blocker; verify with a viewport-matched capture if pursued.

## NOTE (not a bug): earlier "blank step 3" + "blank confirmation" were screenshot-viewport/contaminated-state artifacts — re-tested clean, render fine. Dropped.

## WEB other screens — RESULT
- Orders (Commandes): clean logged-out empty state (CTA "Me connecter"). Expected standalone (no persistence).
- Loyalty (Fidélité) logged-OUT: branded intro ("1€=1pt, 500pts=5€, 1000pts=burger, +25 inscription").
- Auth: DEMO login WORKS ("DÉMO V1", +25 pts credited, nav → "Ikyes"). Connexion(email/pwd) + Inscription(OTP) + Google/Apple social. Functional in demo mode.
- Loyalty dashboard (logged in): RICH & polished — 347 pts solde, QR identifier (LECAY-347-A9F2C), tier ladder Niveau 2/4 (Novice→Pepper→Master→Légende), weekly challenge (3 cmd +50pts, 2/3), 4/8 streak, referral (CAYENNE-IKYES +100pts), leaderboard (#12 Ikyes TOI 347pts), 8 trophies (3 unlocked), rewards catalog (Frites Style 300pts ÉCHANGER / reachable+unreachable "453 pts manquants" states). Renders clean at tall viewport.
- F-LIVE-04 [P2, verify] redeem: clicking "Échanger" on a 300-pt reward (balance 347) did NOT deduct points (still 347) nor show a confirm modal in my test. Could be: needs confirmation step, or demo no-op. Re-verify on mobile WizardRedeem.jsx (dedicated redeem component) before asserting.
- Branding consistent (orange #F4501E / jaune / noir / cream), bold display type, "//" section markers throughout. Strong visual identity.
- NOTE web loyalty rules cross-check (logged-out "500pts=5€/1000=burger" vs dashboard "échange dès 200pts" vs tiers) — possible internal threshold inconsistency, owner_gate (product copy). Flag for synthesis.

## F-LIVE-02 VERIFIED LIVE (mobile): "Menu complet +2,50€" displayed, selecting it → Suivant 9,90€ (=6,90+3,00 charged). Identical to web F-LIVE-01. Both frontends confirmed visual+arithmetic. Root: mobile screens-item-steps.jsx:525 price 2.50 / web wizard-v2.jsx:93 price 2.50,savings 1.50. Both = trivial-safe heal to 3.00.

## MOBILE wizard — works: step 1 Viandes (req) → 2 Suppléments (+0,90) → 3 Faire un menu → cascade. Tacos M base 6,90 ✓. iOS frame, palette noir/orange/jaune/blanc ✓. Console clean.

## MOBILE loyalty REDEEM — ✅ FULLY WORKS (priority flow b)
- Profil → Carte fidélité → rotating QR (#FK-12345, expire 4:59 security) → 347 pts = 3,47€ → rewards (Petite Frites 100pts UTILISER) → WizardRedeem 2-step: "Confirmer l'échange?" (Solde avant 347, Coût −100, Solde après 247 ✓) → "Quand utiliser? Appliquer maintenant / Garder 30j" → ÉCHANGÉ! voucher LCY-967568, Solde 247 pts (deducted ✓), next threshold updated (247/250 → 3pts pour −2,50€). Point math correct throughout. Apple/Google Wallet integration UI present.
- MOBILE result: home, menu (Tacos M 6,90/Tacos L 8,90 ✓), wizard (F-LIVE-02 confirmed), loyalty+redeem all functional. Console clean. Palette noir/orange/jaune/blanc ✓.

## POST-HEAL VERIFICATION (advisor gaps closed)
- HEAL re-test WEB (live): P0 diet filter Épicé 41→3 ✓ · CTA "Commander Big Cayenne" opens modal ✓ · wizard "Menu complet" displays +3,00€ ✓ · console 0 error.
- HEAL re-test MOBILE (live): wizard "Menu complet" displays +3,00€ ✓ AND selecting it → Suivant 9,90€ (display=charge) ✓.
- MOBILE checkout→pay→confirm (now exercised live, was previously inferred): wizard 7 steps → cart "TA COMMANDE" 1 art Tacos M 9,90€ TVA incluse +10pts → "COMMENT TU PAIES?" (Payer à la caisse / Payer maintenant CB Stripe) → order placed "PRÊT À 05h18, TOTAL 9,90€, EN PRÉPARATION ~12min" tracking + "+10 POINTS GAGNÉS". Fully functional.
- Minor (non-repro): mobile add-to-cart button briefly showed "·19,90€" then cart settled to correct 9,90€ (1 article) — possible transient/own-state-contamination, not confirmed reproducible.
