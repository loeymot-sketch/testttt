# Vérification adversaire — CAISSE / Paiement-encaissement-split

## Finding sous revue (candidate P3)
DB `foodking_e2e` : `orders WHERE pos_payment_method=6` (16 PAID) + `order_payments WHERE mode=6` (13 rows) sans audit de persistance → test-pollution, combo incohérent, non atteignable par le service.

## VERDICT : CONFIRMÉ comme P3 (test-pollution, AUCUN defect code, AUCUN heal requis)

La finding est exacte sur les faits ET sur sa conclusion (non-service-reachable). La sévérité P3 est correcte en V1-LOCAL : pas de perte de commande, pas de fuite, chaîne fiscale intacte. Aucune action code. La seule "action" possible est cosmétique (purge test-data) ou no-op.

---

## Repro re-exécutée (toutes reproduites)

```
mysql -u root foodking_e2e -e "SELECT payment_status, COUNT(*) FROM orders WHERE pos_payment_method=6 GROUP BY payment_status;"
  => 5(PAID):16, 15(PENDING_COUNTER):91, 10(UNPAID):1   [IDENTIQUE]

mysql -u root foodking_e2e -e "SELECT COUNT(*) FROM order_payments WHERE mode=6;"  => 13
mysql -u root foodking_e2e -e "SELECT COUNT(*) FROM audit_logs WHERE resource='order_payment' AND resource_id IN (50,51,54,101,116,141,202);"  => 0
mysql -u root foodking_e2e -e "SELECT COUNT(*) FROM audit_logs WHERE action='order.payment_tranche_persisted';"  => 4 (le service écrit BIEN un audit quand utilisé ; ces 13 n'en font pas partie)
```

Les 16 PAID mode=6 :
```
ids 4247,4248,4564,4565,4568,4624,4629,4634,4639,4644,4649,4654,4659,4664,4797,4984
- TOUS payment_method=1, source pos (sauf 4984 kiosk), fiscal_sequence_no alloué (gap-free, 2043..2497)
- 0 audit 'order.counter_payment_confirmed' sur l'ensemble (devrait être 16 si collectés via service)
- chaque order : SEUL 'order.created.pos' en audit_logs
- timestamps en rafale (10:24:04 x2 ; cluster 13:57-13:58 ; montants ronds 2/4/14/19.5) = batch factory/seed
```

## Evidence code (file:line re-Read, gardes confirmées triple-couche)

1. `app/Enums/PosPaymentMethod.php:19` — COUNTER_DEFERRED = 6.
2. `app/Http/Requests/PosOrderRequest.php:141` — `'payment_breakdown.*.mode' => [...,'in:1,2,3,4,5']` → HTTP rejette mode=6.
3. `app/Services/Payments/SplitPaymentService.php:66-87` (validateBreakdown) — `$allowedModes`=[CASH,CARD,MOBILE,OTHER,TICKET] → service rejette mode=6 ; `persistTranches:247` est le SEUL OrderPayment::create du chemin paiement, et il écrit TOUJOURS un audit `order.payment_tranche_persisted` (l.259-273).
4. `app/Services/PaymentService.php:203-215` (confirmCounterPayment) — même whitelist (rejette mode=6) ET **flippe `pos_payment_method = $mode` (l.326) vers 1-5** + écrit audit `order.counter_payment_confirmed` (l.389). Donc un order collecté au comptoir ne RESTE JAMAIS à pos_payment_method=6, et confirmCounterPayment ne crée AUCUN order_payment (il crée un `Transaction` l.376).
5. `grep OrderPayment::create app/ database/` → exactement 2 sites : `SplitPaymentService:247` et `RefundWithCounterEntryService:204`. Les DEUX écrivent un audit. Aucun n'écrit mode=6 (Refund recopie le mode du parent ; le parent legitime n'a jamais mode=6 en order_payments).

## Pourquoi PAID + pos_payment_method=6 est IMPOSSIBLE via le service
Le seul chemin →PAID pour un counter-deferred est `confirmCounterPayment`, qui (a) exige mode∈1-5 et (b) écrase pos_payment_method par ce mode et (c) écrit `order.counter_payment_confirmed`. Les 16 rows sont PAID, gardent 6, et n'ont AUCUN audit de confirmation → insérées directement (factory/seed mettant payment_status=PAID + pos_payment_method=6 ensemble, hors state-machine).

## Sévérité V1-LOCAL = P3 (pas davantage)
- NF525 : chaîne fiscale intacte, fiscal_sequence_no gap-free, ces rows ne créent pas de gap. Pas un P0/P1.
- Argent / perte commande / fuite : aucun. order_payments mode=6 (Σ ~133€) pourrait théoriquement tomber dans `card_total` de `ZReportCashEnrichmentService:171` (`mode <> CASH`), mais c'est une propriété de l'enrichissement déclenchée UNIQUEMENT par de la donnée polluée, pas un defect de chemin service ; et le dashboard bucket lit `orders.pos_payment_method` (DashboardService:700 → 'counter'), pas order_payments.mode. Single-box, aucun impact opérationnel réel.
- Tests existants couvrent la garde (SplitPaymentServiceTest, CounterDeferredPaymentLifecycleTest, PosCounterCollectRaceProtectionSentinel...).

## Reco
NON-frozen, **aucun fix code**. Les gardes (HTTP in:1-5 + 2 whitelists service + flip pos_payment_method) sont correctes — NE PAS toucher PaymentService/SplitPaymentService/PosOrderRequest.
- Option A (cosmétique) : purge ciblée de la test-data (pattern `catalog:clean-test-data`) pour rapports/réconciliations propres. Hors-frozen, sans risque NF525 (rows non liées à un Z signé légitime).
- Option B : laisser tel quel (V1 LOCAL single-box, sévérité hors-scope).
