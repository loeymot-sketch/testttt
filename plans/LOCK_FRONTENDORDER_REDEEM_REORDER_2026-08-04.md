# LOCK — Réordonnancement du pré-rachat fidélité (applyKioskLoyaltyDiscount)

**Date** : 2026-08-04 · **Gate owner** : ✅ EXPLICITE (/goal « assure au max … utilisation des point max logique et raisonne max »).

## Zone
`app/Services/FrontendOrderService.php::applyKioskLoyaltyDiscount` = **zone partagée §6** (SYSTEM_MAP §6, CONSTITUTION §47). Modification coordonnée requise. `app/Services/Pricing/DiscountCalculator.php::kioskLoyaltyRedemption` (ajout param) + `app/Http/Controllers/Frontend/LoyaltyController.php::redeem` (min).

## Racine (audit adversarial RED-utilisation, prouvé exécuté)
Le `/loyalty/redeem` DÉBITE immédiatement les points (ledger `order_id=null`). Puis `applyKioskLoyaltyDiscount` à la création de commande :
1. **RED-1** — appelle `kioskLoyaltyRedemption` qui vérifie le solde **APRÈS débit** (`DiscountCalculator:66`) → si le client a racheté > la moitié de son solde (« j'utilise tous mes points »), `points=0` → `return` AVANT le rattachement → **commande PLEIN TARIF, points partis**.
2. **RED-2** — le lookup du pré-rachat filtre `source_surface='kiosk'` ; un client web/mobile écrit `'pos'` → non trouvé → **double débit** (frais en plus du pré-rachat).
3. **RED-3 [sécu]** — la garde IDOR (Mission-28) est écrite **APRÈS** la branche de rattachement (qui `return`) → un invité peut consommer le pré-rachat d'autrui.
4. **RED-4** — `/loyalty/redeem` ignore `loyalty_min_redeem_points` → débit sous le plancher, jamais consommable.

## Contrat cible (ordre CORRECT : autz → rattachement → contrôle du seul débit frais)
1. Résoudre `loyaltyUser` verrouillé.
2. Calculer `pointsRequired`/`maxDiscount` depuis le montant demandé **sans la barrière de solde** (nouveau param `skipBalanceGate=true` ; min conservé).
3. **Garde IDOR EN PREMIER** (borne OU propriétaire OU staff) — couvre rattachement ET débit frais.
4. Chercher un pré-rachat récent (`user + type=redeem + order_id null + <10 min`, **TOUTE surface**) :
   - montant ≠ `pointsRequired` → 422 ; sinon **rattacher (order_id) + appliquer la remise, SANS re-vérifier le solde** (déjà débité).
5. Sinon **débit FRAIS** : ICI seulement, contrôle `solde >= pointsRequired`, débit + ledger + remise.
6. `/loyalty/redeem` : refuser (400) un rachat `< loyalty_min_redeem_points` AVANT tout débit.

## Frozen touchés
Aucun §7. `DiscountCalculator::kioskLoyaltyRedemption` : ajout d'un param optionnel `skipBalanceGate=false` (rétro-compatible : les autres appelants gardent la barrière). `kioskLoyaltyRedemption` reste la SSOT du calcul (rate + floor + min) — pas de duplication.

## Preuves attendues (TDD)
- RED-1 : solde 100 → rachat 100 (endpoint) → commande → remise 1,00 € appliquée, total juste (pas plein tarif).
- RED-2 : pré-rachat `source_surface='pos'` → rattaché (1 seul débit, pas 2).
- RED-3 : token non-propriétaire/non-borne/non-staff → 422, aucun rattachement.
- RED-4 : `/loyalty/redeem` sous le min → 400, aucun débit.
- Non-régression : `KioskLoyaltyEarnCycleProof`, `KioskRedeemWholePointSnap`, `PosRedemptionTtcTaxDoubleCount`, `OrphanRedeemReaper`, `LoyaltyRefundOwnerAndStatus`, `KioskLoyaltyDoubleRedeemRefused` (fixture à durcir : rachat consommant TOUT le solde utile).

## Réversibilité
Revert de la méthode (un bloc) + retrait du param `skipBalanceGate`. Aucun schéma modifié.
