# CAISSE r1 — Lentille LOGIQUE/DATA/NF525 — Paiement / encaissement / split

Agent : lentille logique/data/NF525 sur Sub 1.b (PaymentService / SplitPaymentService / alloc fiscale).
Mode : READ-ONLY (SELECT seul sur `foodking_e2e`, PHPUnit sur base test isolée, ZÉRO ordre placé).
Verdict : **0 P0 / 0 P1 / 0 P2** prouvé reproductible. Toutes les ancres tiennent. 1 note P3 data-hygiène (test-pollution, hors-scope sévérité V1).

---

## RÉSULTAT : les vecteurs d'abuse ancrés sont TOUS défendus (preuves)

### V1 — Double-encaissement (double-clic confirm) → 1 seul PAID / 409 — TIENT
- Ancre disait « race lockForUpdate UNHEALED » (commentaire `PaymentService.php:227-243`).
- **Vérifié** : ce commentaire documente le comportement PRE-heal. Le correctif **K2-HEAL-01** est présent et actif à `PaymentService.php:278-310` : reread sous `lockForUpdate`, si déjà PAID → discrimine via l'audit `order.counter_payment_confirmed` : même caissier = no-op 200 ; caissier différent / collecteur inconnu = `PaymentAlreadyCollectedException`.
- Route `routes/api.php:858-875` catch typé AU-DESSUS du fallback 422 → **409 + `error_code: payment_already_collected`**. 409 non caché par idempotency (2xx only).
- Repro : `vendor/bin/phpunit --filter PosCounterCollectRaceProtectionSentinelTest` → **OK 4 tests, 26 assertions** (service throw, same-cashier no-op, route 409, jamais 200/resource sur race).

### V2 — Montant reçu < dû (monnaie négative ?) → 422, double couche — TIENT
- Couche service CASH : `PaymentService.php:315-318` `if mode==CASH && received!==null && received < total → ValidationException`. Si `received` omis → fallback `total` (`:327-328`), pas de sous-paiement possible.
- Couche OrderService (vente directe) : `OrderService.php:1071-1077` re-check contre le **total recalculé serveur** (SSOT) → `InvalidArgumentException(422)`. + `PosOrderRequest.php:187-190` (check client). 3 couches.
- Split CASH : `SplitPaymentService.php:104-108` `round(tendered*100) < round(amount*100) → ValidationException` (cents, pas de float-drift).

### V3 — Split Σ ≠ total → bloque — TIENT
- `SplitPaymentService::validateBreakdown` `:147-155` `Σcents < totalcents → ValidationException` ; `:157-166` `Σ > total + 1,00€ (TOLERANCE_OVERPAY) → ValidationException`. Calcul **en cents entiers** (`round($x*100)`), zéro erreur d'arrondi float.
- Repro : `phpunit --filter SplitPaymentEndToEndTest` → **OK 6/6** ; `npx vitest run posSplitPaymentValidation posPaymentComponentContract` → **42/42**.
- Donnée réelle : seul 1 ordre multi-tranche en prod (#4937, CARD+CASH), Σ=4,00 == total 4,00, diff=0,00. Réconciliation parfaite.

### V4 — Mode hors-liste injecté → rejeté — TIENT
- `confirmCounterPayment` `:203-215` whitelist `[CASH,CARD,MOBILE_BANKING,OTHER,TICKET_RESTAURANT]` (`in_array(...,true)`), sinon ValidationException.
- `validateBreakdown` `:66-87` même whitelist + `PosOrderRequest.php:141` `in:1,2,3,4,5`. **COUNTER_DEFERRED(6) exclu** des deux services → ne peut être ni encaissé ni persisté en tranche.

### V5 — Re-payer order déjà PAID → no-op/409 — TIENT
- Même garde que V1 : `:278` `if payment_status===PAID` → no-op même caissier / 409 sinon. `PaymentStateMachine::assertCanTransition` `:313` borne aussi la transition.

### V6 — Alloc fiscale gap si échec → gap-free prouvé sur la donnée — TIENT (NF525)
- Allocateur `FiscalSequenceService::next` `:100-101` `MAX(fiscal_sequence_no)+1` sous `lockForUpdate` (+ Cache::lock per CLAUDE.md).
- **Preuve DB live branch_1** : `min=1, max=2573, count=2570, dups=0`. Manquants = **exactement {2506,2507,2508}** (3 consécutifs, run unique, entre #4974 seq2505 @06-19 00:31 et #5019 seq2509 @06-20 01:13).
- Gap de rollback (3 tx consommées puis annulées) = comportement **documenté & acceptable NF525** : monotone, 0 doublon, jamais réutilisé. `fiscal_alloc_error_at` flaggés = 0. **PAS un défaut.**

---

## ANOMALIES DATA EXPLIQUÉES (vérifiées, non-findings)

### A. Ligne `order_payments` à montant NÉGATIF (id=2, amount=-11, change=-4)
- `SELECT * FROM order_payments WHERE amount<0 OR change_amount<0` → 1 seule ligne (id=2, order 227).
- Order 227 = `order_serial_no='RTN-TEST-S1-...'`, status=22 (RETURNED), total=-11. C'est un **ordre-miroir de refund** créé par `RefundWithCounterEntryService.php:204-220` qui négocie amount/tendered/change pour équilibrer le Z (NF525 by-design). Légitime.

### B. 13 lignes `order_payments` mode=6 (COUNTER_DEFERRED) positives + 16 orders `pos_payment_method=6 & payment_status=5(PAID)`
- mode=6 est exclu de `validateBreakdown`/`confirmCounterPayment`. Ces lignes n'ont **AUCUN audit `order.payment_tranche_persisted`** (ex : order 4564 → seul audit = `order.created.pos`) → elles n'ont PAS traversé `SplitPaymentService` (seul site de création audité). Combo PAID+COUNTER_DEFERRED est incohérent (le canon = PENDING_COUNTER=15, 91 orders corrects). Round amounts + serials/timestamps en rafale (19:55:43-47, 13:57-58) = **seeder/factory test-pollution**, non atteignable par le service. Sévérité V1 : test-pollution = hors-scope (P3 data-hygiène au plus).

---

## FINDINGS

### [P3] orders:(16 lignes) — Test-pollution : orders PAID avec pos_payment_method=COUNTER_DEFERRED + order_payments mode=6 sans audit
- **Repro** : `SELECT payment_status, COUNT(*) FROM orders WHERE pos_payment_method=6 GROUP BY payment_status;` → `5(PAID):16, 15(PENDING_COUNTER):91, 10(UNPAID):1`. `SELECT * FROM audit_logs WHERE resource='order_payment' AND resource_id=50;` → **0 ligne** (la tranche mode=6 #50 n'a pas d'audit de persistance).
- **Evidence** : mode=6 absent des whitelists service ; 0 audit `order.payment_tranche_persisted` ; combo PAID+COUNTER_DEFERRED contradictoire ; amounts ronds + timestamps en rafale = batch seedé.
- **Lentille** : DATA (cohérence DB) — pas NF525 (pas dans le Z via flux service), pas sécurité.
- **Reco** : NON-frozen. Purge ciblée test-data (cf. `catalog:clean-test-data` pattern) OU laisser tel quel (V1 single-box, hors-scope sévérité). Aucun fix code requis — le service rejette déjà ce combo.

---

## CONCLUSION
Cœur paiement/encaissement/split **SOLIDE** : 409 race healed (anchor « UNHEALED » périmée), sous-paiement bloqué 3 couches, split borné en cents, modes whitelistés, fiscal gap-free (0 doublon, gaps=rollback by-design). Aucune sous-facturation / perte-commande / gap fiscal silencieux trouvé. 0 P0/P1/P2.
