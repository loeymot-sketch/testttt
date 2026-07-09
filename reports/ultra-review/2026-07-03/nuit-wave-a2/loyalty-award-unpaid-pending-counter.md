# CONFIRMED (P2) — Points fidélité crédités sur commande NON PAYÉE (PENDING_COUNTER) + jamais repris à l'annulation

## Verdict
CONFIRMED — sévérité P2 maintenue.

## Repro rejouée (READ-ONLY, DB foodking_e2e)
tinker sur commande #4489 :
```
id=4489 status=13(DELIVERED) payment_status=15(PENDING_COUNTER) order_type=10(KIOSK)
loyalty_points_awarded=15 loyalty_customer_code=VICT1234
order_payments=0 lignes
loyalty_transactions id=10 type=earn points=+15 balance_after=265
```
=> Une commande borne Plan B, non réglée (0 ligne de paiement, payment_status=PENDING_COUNTER),
a bien crédité 15 points au client VICT1234. Reproduit exactement la donnée du finding.

## Analyse code (confirme le mécanisme)
1. `AwardLoyaltyPointsOnDelivery::handle` (app/Listeners/AwardLoyaltyPointsOnDelivery.php:27-164) :
   - Gate = `status != CANCELED` (l.33, l.55) + trigger sur PREPARED/DELIVERED pour kiosk (l.40).
   - AUCUN contrôle `payment_status === PAID`. L'accrual se fait donc dès que la cuisine passe
     PREPARED, avant tout encaissement comptoir.
2. Annulation (`OrderService.php:2068` self-cancel, `:2204` admin/POS) appelle
   `LoyaltyService::refundPoints`.
3. `LoyaltyService::refundPoints` (app/Services/LoyaltyService.php:27-29) ne requête QUE
   `type='redeem'` — il re-crédite les points dépensés, il ne retire JAMAIS les `earn`.
   Sur #4489 : 0 redeem → aucun clawback des 15 points gagnés.
4. Le seul clawback des `earn` (`ClawbackLoyaltyPointsOnRefund.php:76` → `clawbackEarnedPoints`)
   est câblé sur l'événement `RefundCreated` UNIQUEMENT — déclenché pour un remboursement de
   commande PAYÉE, pas pour l'annulation d'une commande PENDING_COUNTER non réglée.

## Conclusion
Faille réelle de comptabilité fidélité : un client accumule des points sur une commande jamais
payée (kiosk Plan B PENDING_COUNTER → cuisine PREPARED → accrual). Si la commande est ensuite
annulée, `refundPoints` (redeem-only) ne reprend pas les points gagnés ; le seul chemin de
clawback `earn` est réservé aux vrais remboursements (RefundCreated). Points conservés =
dérive fidélité répétable et exploitable (accumuler des points sans payer).

Non-NF525 (fidélité ≠ fiscal), impact monétaire faible (10 pts/€ = remise), mono-poste local →
P2 correct. Ni P0/P1 (pas fiscal, pas sécurité critique) ni downgrade (défaut d'intégrité réel,
reproduit LIVE).

## Fix proposé (conforme au finding)
- Gater l'award sur `payment_status === PaymentStatus::PAID` dans AwardLoyaltyPointsOnDelivery,
  et re-déclencher l'accrual à l'encaissement comptoir (hook PENDING_COUNTER→PAID) pour ne pas
  perdre les commandes payées plus tard.
- Cloner `clawbackEarnedPoints` sur CANCELED/REJECTED pour toute commande
  `loyalty_points_awarded > 0` (idempotent via UNIQUE(user_id, order_id, type)).
