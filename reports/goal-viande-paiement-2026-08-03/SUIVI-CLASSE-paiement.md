# Suivi classé — findings paiement en ligne restants (post-heal 2026-08-04)

4 auditeurs adversariaux ont balayé le money-path carte web après la plainte owner
« annuler = validé ». Corrigés + TDD + déployés ce jour :

| ID | Sévérité | Statut |
|---|---|---|
| Cancel 3DS = commande validée (plainte owner) | P0 | ✅ FIXÉ `a80643441` (webhook cancel + écran honnête) |
| Vente carte web hors Z NF525 (finalizePaidKioskOrder no-op web) | P0 | ✅ FIXÉ `306c61075` (LOCK_WEB_CARD_FISCAL_SEAL — alloc + auto-cuisine) |
| Carte refusée sync affichée « payé » (inline sans vérité) | P1-B | ✅ FIXÉ `306c61075`+`08f68f1` (inline=paid seulement) |
| Caissier accepte carte web UNPAID → zombie | R1/F1 | ✅ FIXÉ `306c61075` (garde 422 + filtre file) |
| Webhook paid échoué transitoire → double-encaissement | P1-C | ✅ FIXÉ `306c61075` (DLQ Mollie branché) |

## RESTE (classé — à traiter en prochaine passe, non bloquant terrain immédiat)

### P1-A — Remboursement / chargeback Mollie AVALÉ (money-path ops)
Mollie n'a pas de statut `refunded` : le paiement reste `paid` + `amountRefunded`, webhook renvoyé
avec le MÊME id → dédupliqué (`tr_x:paid` déjà processed) → une commande remboursée/chargebackée
reste PAID en caisse, Z ≠ payout, stock non relâché, points non clawback.
- **Preuve** : `Mollie.php:253,269-277` (dedup par payment_id:status) vs `Stripe.php:380-440` (`charge.refunded`→`RefundCreated`→cascade REFUNDED).
- **Fix** : au fetch, si `amountRefunded>0` → dispatch `RefundCreated` (miroir Stripe) + discriminer le webhook_id (`tr_x:paid:r{montant}`).
- **Impact réel V1** : faible tant qu'aucun remboursement Mollie n'est émis (geste ops rare). À câbler avant volume.

### P1-D — Coupon compté sur commandes annulées → retry brûle le coupon 1-usage
`CouponService.php:441-448,457-460` comptent les `order_coupons` sans filtrer le statut ; aucun
chemin ne libère la ligne d'une commande annulée. Depuis l'auto-cancel (retry = parcours nominal),
un client au coupon 1-usage se prend 422 « limite atteinte » en recommandant.
- **Fix** : exclure les commandes CANCELED/REJECTED du comptage d'usage coupon.

### P2-A — Badge caisse « paiement en ligne »
Même après R1 (carte UNPAID exclue), afficher un badge « 💳 payé en ligne » sur une carte web
PAYÉE pour que le caissier ne tente jamais un 2ᵉ encaissement. Champs déjà dans `SimpleOrderResource`
(`payment_method`/`payment_status`). Corriger aussi le tooltip « encaissement comptoir » (mensonger).

### P2-B — Sentinelle « encaissé sur commande annulée »
Cas rare : commande web PAID encore PENDING annulée par le client (seuil TAKEAWAY) → PAID+CANCELED
sans refund Mollie. Sentinelle `orders WHERE transaction_id LIKE 'mollie:%' AND status IN (16,19,22)
AND payment_status=5` → compteur AMBRE dans `/admin/pos/system-health` + PushNotification admin.
(Le garde client-cancel « refuser si PAID » couvrirait la source — defense-in-depth non encore posé.)

### P3 (cosmétique / edge, non chiffré)
authorized/hosted sans method forcé · recovery idempotency sans filtre statut · 2ᵉ paid re-ack
sans log · checkout DELIVERED non borné · pending sans cartLines · alreadyPaid stamping silencieux.

## ⚠ Go-live carte (rappel owner, hors code)
La chaîne NF525 VPS est en **TAMPER** connu (`audit_logs.id` du 30/06) — blocage go-live séparé.
Le scellage des ventes carte est correct, mais la production légale exige AUSSI la résolution du TAMPER.
