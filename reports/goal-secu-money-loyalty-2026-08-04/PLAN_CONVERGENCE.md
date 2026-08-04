# GOAL — Durcissement total paiement · annulation · cumul · utilisation points · compte
_Convergence adversariale jusqu'à « tout validé, testé, raisonné » — 2026-08-04_

## Systèmes (4) + état d'entrée
1. **Paiement + annulation** (Mollie) — déjà durci ce jour (2 P0 + 3 P1 healés `ba4d16a2a`). Re-disputé.
2. **Cumul de points** (earn) — `AwardLoyaltyPointsOnDelivery`, `ClawbackLoyaltyPointsOnRefund`, `LoyaltyService::clawbackEarnedPoints`.
3. **Utilisation des points** (redeem) — `applyKioskLoyaltyDiscount`, `LoyaltyController`, `refundPoints` per-owner, `reapOrphanRedemptions`.
4. **Gestion de compte** — `GuestSignupController` (email-OTP, channel-confusion, rename Guest User, first/last), `LoyaltyController /check /register` (PII), `OtpManagerService` (GAP-20), phone-unique.

## Findings pré-confirmés par l'orchestrateur (corroboration indépendante)
- **P1-D coupon brûlé** : `CouponService.php:441-448` compte `OrderCoupon` sans filtrer le statut → une commande annulée (webhook cancel carte) consomme le coupon 1-usage. FIX : exclure CANCELED/REJECTED/RETURNED du comptage (relation `order` + `whereHas`).
- **P1-A refund/chargeback avalé** : Mollie reste `paid`+amountRefunded, dédup `tr_x:paid` → commande PAID à vie (`SUIVI-CLASSE-paiement.md`).

## Waves
- **W1 — AUDIT adversarial** (EN COURS) : 4 auditeurs RED (paiement, cumul, utilisation, compte), preuve file:line + repro par finding.
- **W2 — DISPUTE / verify-before-report** : chaque P0/P1 re-grep contre le code vivant à HEAD ; hallucinés jetés + listés à part. Skill `verify-before-report`.
- **W3 — HEAL TDD** : par finding confirmé — test rouge (comportement, pas récap) → fix scope-minimal → vert. Frozen/NF525 → LOCK + gate. DB-safe (`safe-test.sh`), jamais `php artisan test`.
- **W4 — E2E RÉEL** : parcours bout-en-bout sur www.lecayenne.fr + backend VPS :
  - money : commande carte payée (sealed+cuisine) · carte refusée (jamais « payé ») · annulation 3DS (annulée+rien débité) · comptoir.
  - cumul : commande livrée → points crédités exacts (floor, 1 fois) ; remboursée → clawback exact.
  - utilisation : redeem → remise scellée == affichée ; redeem+annulation → points rendus au bon porteur ; redeem > solde refusé.
  - compte : signup email-OTP (nom complet scellé) ; login ; anti-takeover canal ; 0 fuite PII.
- **W5 — CONVERGENCE** : 2 cycles adversariaux consécutifs P0+P1=0 ET findings identiques (discipline §6). BRAIN + mémoire + deploy.

## Acceptance (DONE)
- Gates : MollieStructureTest + Loyalty/* + Auth/* + Pos web verts ; vitest sentinels ; chaîne NF525 OK ×4 ; frozen diff 0 hors LOCK.
- E2E réel : chaque parcours ci-dessus prouvé (DB VPS + captures analysées), 0 erreur JS.
- Adversarial : dernier cycle P0+P1=0, findings stables.
- Reste éventuel : classé owner (TAMPER chaîne, volumes refund) — jamais un blocker silencieux.

## Invariants gardés
Pricing SSOT backend · webhook re-fetch seule source PAID · grand-livre fidélité = source de vérité du solde · token guest kiosk:order only · identité = possession prouvée (OTP) · money-path au centime.
