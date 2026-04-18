# AUDIT_KIOSK_PAYMENT_DEFERRED_CARD_TR_018 — Paiements différés Card & TicketRestaurant

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016
- **Estimation** : 1 j-h
- **Vague** : C3

## Contexte

Card kiosk = interaction TPE intégré ou externe. TR (TicketRestaurant) = carte électronique, parfois papier scanné. Les deux flows sont **différés** : l'Order est créé en PENDING / UNPAID, le TPE interagit, puis un endpoint `paymentConfirm` côté backend valide. Risques :
- Race : deux confirmations concurrent → double PAID.
- TPE renvoie OK mais réseau coupé côté kiosk → Order reste UNPAID → client déjà débité.
- Timeout TPE non géré → client bloqué sur écran "patientez".
- Rejet carte → retour au choix paiement propre ou kiosk coincé ?

## Questions d'audit

1. Quel endpoint backend confirme le paiement kiosk différé (`paymentConfirm`) ? Signature de sécurité (HMAC) ?
2. Cet endpoint est-il idempotent (double call même transaction_id) ?
3. L'identifiant transaction TPE est-il stocké (`payment_reference`) pour audit / remboursement ?
4. En cas de réponse TPE KO : l'Order reste UNPAID, message utilisateur clair, retour au choix paiement possible ?
5. En cas de timeout TPE : kiosk propose-t-il annulation avec confirmation + alerte cuisine/POS ?
6. Le TR (TicketRestaurant) a-t-il un plafond (19€/jour) enforced côté backend ?
7. Le split TR + carte (si TR insuffisant) est-il supporté ? Ou TR only ?
8. Le hardware bridge (`window.borne.tpeCharge(amount)`) renvoie-t-il un promise bien typé avec success/error ?
9. L'event `OrderStatusChanged` est-il émis après confirmation paiement ET transition ACCEPT ?
10. Les logs d'échec paiement sont-ils consultables admin (alerting sur taux d'échec TPE par kiosk) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/FrontendOrderService.php` — `paymentConfirm` ou équivalent
- `app/Http/Controllers/Frontend/PaymentController.php` (si existe)
- `routes/api.php` — endpoint confirm
- `resources/js/components/frontend/kiosk/**/Payment*.vue`, `TPE*.vue`
- Hardware bridge

## Invariants at Risk
- [x] OrderStatus enum
- [x] Dispatch after DB commit
- [x] Backend pricing SSOT (montant débité = total Order)
- [x] Frozen zone (FrontendOrderService)

## Fichiers à lire
1. `app/Services/FrontendOrderService.php` — grep paymentConfirm / confirm
2. `app/Http/Controllers/Frontend/*Payment*`
3. `routes/api.php` — endpoints kiosk payment
4. `resources/js/components/frontend/kiosk/**/*Payment*`, `*TPE*`
5. `docs/BUSINESS_RULES.md`

## Grep patterns

```
grep -rn "paymentConfirm\|confirmPayment" app/Services/FrontendOrderService.php app/Http/Controllers/Frontend/
grep -rn "transaction_id\|payment_reference\|tpe_ref" app/ database/
grep -rn "ticket_restaurant\|TR\b\|TicketResto" app/ resources/js/
grep -rn "tpeCharge\|borne.tpe\|cardCharge" resources/js/
grep -rn "Cache::lock\|idempotent" app/Http/Controllers/Frontend/
grep -rn "timeout" resources/js/components/frontend/kiosk/
```

## Evidence required
- Séquence exacte card / TR kiosk.
- Idempotency de paymentConfirm.
- Gestion timeout, KO, annulation.
- Plafond TR (si existe).
- Logs + alerting.

## Grille de verdict
- **PASS** : endpoint idempotent, timeouts gérés, fallback propre, event post-commit, ref TPE stockée.
- **WARN** : timeout ultime sans annulation automatique mais bouton utilisateur présent.
- **BLOCKED** : double PAID possible, ref TPE non stockée, Order ACCEPT sans confirm, kiosk peut se bloquer.

## Livrable
`reports/review/AUDIT_KIOSK_PAYMENT_DEFERRED_CARD_TR_018_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
