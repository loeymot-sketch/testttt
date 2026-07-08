# W6 R1 — TRIAGE (2026-07-08)
32 agents lancés (25 done, 4 erreurs session-limit/retry — RED visual web+mobile + order-sync-regression + 1 retry cap). Verdicts : 17 PASS, 7 PARTIAL, 1 FAIL. Findings : 3 P0, 8 P1, 7 P2, 12 P3.

## PROUVÉ VERT (PASS, preuve d'exécution réelle)
- **EARN e2e** : commande réelle #5570 (Coca 1,90 → PREPARED → +1 pt floor, idempotent, ledger 1 ligne). ✓
- **QR signé + scan + anti-replay** : mint lqr.* → scan borne ok → replay `qr_replay` → falsifié `qr_invalid_signature` → expiré `qr_expired` → legacy `qr_legacy_rejected`. 6/6. ✓
- **Sécurité authz/IDOR** : cross-compte refusé, add-points staff-only 403, throttles mordent. ✓
- **Secrets/PII** : aucun secret exposé ; token QR ne contient pas de PII (cust/code/nonce). ✓
- **Stripe OFF** : web checkout counter-only, mobile ModalPayChoice sans CB, row id=4 status=10, webhook 503 si non config. ✓
- **Frozen/NF525** : diff 0 (11 chemins), audit_logs append-only, Loyalty 83/83, Stripe 34/34. ✓
- **Parity gate** : web+mobile VERT (mais angle mort formule → voir P0 ci-dessous). Node tests OK.
- **e2e web/mobile order API** : items composés résolus, prix serveur cohérents. ✓ (sauf formule partielle → P0)

## RÉEL — HEALÉ (workflow wf_fbf30d83-bb6)
- **P0 formule partielle add-ons (web+mobile)** : « Ajouter Frites »/« Ajouter Boisson » ciblaient l'addon side/drink avec role menu_frites/menu_boisson → **422** (backend exige addon menu_component) ET prix affiché **+2,00** vs borne réelle **+1,50 / +1,00** (prouvé orders 5580-5590). Fix : cibler menu_component (H1/H4) + prix f-frites=1,50/f-boisson=1,00 (H2/H5) + display wizard.
- **P1 redeem branch_id** : loyaltyRedeem web+mobile omettait branch_id → IdempotencyKeyMiddleware 422 avant controller. Fix H1/H4.
- **P1 Ikyes/IB nav résidu** : components.jsx:137-138 littéraux démo. Fix H3.
- **P0/angle-mort gate** : check-parity ne validait pas f-frites/f-boisson. Fix H7 (constantes canoniques 2,50/1,50/1,00).
- **P1 honnêteté hors-ligne mobile** : commande locale dégradée pas assez marquée. Fix H6.

## BY-DESIGN / NON-RÉEL (rejetés avec preuve)
- **P0 mobile « Frites 2 items au lieu de 6 »** = divergence STRUCTURELLE ACCEPTÉE (contrat §5 D2) : mobile a `FRITES_STYLES`+`buildFritesComposerProfile`+`has_frites_style:true` (menu.js:242,359,495,498) → les 4 SKU cheddar sont servis par le wizard frites_style, prix atteignables au centime (2,50+1,00=3,50 etc.). IDENTIQUE au web validé. Le gate parity passe sur l'atteignabilité. NON une régression.
- **P2 pas d'endpoint client pour PREPARED/DELIVERED** = contrôle anti-fraude correct (le client ne s'auto-crédite pas ; earn via staff/KDS). By-design.
- **P3 fallback Config accept_legacy_plaintext=true au controller:724** = non exploitable (config existe, effectif=false prouvé). Backlog cosmétique, hors périmètre goal (fichier LoyaltyController non touché).

## PROCESS / W7 (proof gaps, pas des bugs code)
- « Soldes synchrones web ET app » : web api.profile() et mobile client.profile() lisent le MÊME GET /api/profile → même balance backend. À prouver explicitement en re-verify.
- Convergence §0.5 : 1 cycle W6 fait, 2e (post-heal) requis pour sceller.
- Specs rerunnables : goal-sync-smoke web + mobile-e2e adaptés committés ; les 8 specs nommées du GOAL restent un idéal partiellement couvert (documenté honnêtement).
