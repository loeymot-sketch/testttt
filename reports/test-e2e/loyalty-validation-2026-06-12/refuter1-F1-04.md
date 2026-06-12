# REFUTER n°1 — F1-04 (2 écarts ledger↔solde = artefacts seeds direct-DB)

## Repro indépendante (tinker foodking_e2e, 2026-06-12)
- Requête GROUP BY SUM(points) vs users.loyalty_points sur tous users avec points>0 OU transactions → **TOTAL_MISMATCH=2**, exactement les 2 users du finding:
  - user 44 "Victim Secret" : bal=165 vs sum=-85 → IDENTIQUE au finding. Chaîne: tx#10 +15 bal_after=265, tx#11 -100 bal_after=165 → ouverture implicite 250 hors-ledger, last balance_after==solde (165==165). created_at=2026-06-08 11:29:15 (fixture héritée du clone, antérieure aux lanes).
  - user 61 "F2 Lane Customer" : bal=400 vs sum=-800 (chiffres du finding 200/-300 = PÉRIMÉS, lane F.2 concurrente a continué). Discontinuité tx#30→tx#31 (300 → -600 → 400) = **2e reset direct-DB à 1000 documenté** dans F2-redeem-reversal.md ligne 52 ("balance 400 = 1000 − 600 ✅ arithmétique exacte post-reset").

## Preuve de la cause "seed direct-DB" (smoking gun)
- `reports/test-e2e/loyalty-validation-2026-06-12/f2-setup.php` : `DB::table('users')->where('id', $cust->id)->update(['loyalty_points' => 500, ...])` — écriture directe SANS ligne ledger. Explication du finding PROUVÉE pour user 61.
- User 44 : créé 2026-06-08 (avant les lanes), chaîne interne cohérente depuis 250, aucune voie produit ne pose 250 sans ledger → même classe d'artefact (fixture adversariale "Victim Secret"/VICT1234 d'une session antérieure).

## Vérification invariant produit (grep + Read app/)
Enumération des writers de `users.loyalty_points` (hors lectures/Resources) — **8 chemins, TOUS ledgerisés**, vérifiés par Read du code adjacent :
1. `app/Http/Controllers/Frontend/LoyaltyController.php:197/206` register/welcome → LoyaltyTransaction::create (~:210-219) ✓
2. `LoyaltyController.php:273` addPoints increment → insert ledger (derrière `Schema::hasTable` :278, note théorique du finding confirmée) ✓
3. `LoyaltyController.php:397` redeem web/kiosk decrement → LoyaltyTransaction::create juste après ✓
4. `app/Listeners/AwardLoyaltyPointsOnDelivery.php:118` increment → insert ledger DANS le même DB::transaction (:126-137) ✓
5. `app/Services/LoyaltyService.php:86` refundPoints increment → LoyaltyTransaction::create (manual_add) ✓
6. `LoyaltyService.php:186` clawback update → LoyaltyTransaction::create (manual_deduct) ✓
7. `app/Services/FrontendOrderService.php:902` kiosk redeem update → createKioskLoyaltyRedeemLedger ✓
8. `app/Services/Loyalty/PosRedemptionService.php:172` POS redeem update → LoyaltyTransaction::create (:180-190) ✓

Users mutés par F1 lui-même restent cohérents : user 67 bal=599==sum=599 ; user 70 bal=25==sum=25 (re-vérifié).

## VERDICT: NON RÉFUTÉ (refuted=false)
- Repro ✓ (2 mismatches, mêmes users), cause direct-DB ✓ (script de seed dans le repo de rapports), invariant produit ✓ (8/8 writers ledgerisés, file:line confirmés).
- Seul écart factuel : chiffres user 61 périmés (drift lane concurrente), sans impact sur la substance — le drift est lui-même expliqué par un 2e seed direct-DB documenté.
- Sévérité P3 correcte (note d'hygiène data e2e, "aucun fix code", pas de surcote SaaS/multi-tenant). Pas un dedup des lots release/v1 A-H ni dashboard-deep 06-08 (artefact de données de CETTE campagne).
