# CAISSE r1 — Lentille ADVERSAIRE-RED · Paiement / encaissement / split

Rôle: caissier qui se trompe/fraude + client qui abuse. DB live `foodking_e2e` (READ-ONLY).
Fichiers ancrés lus: `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php`
(le path d'ancre `app/Services/SplitPaymentService.php` n'existe PAS — réel = `app/Services/Payments/`),
`routes/api.php:838-879`, `app/Http/Requests/PosOrderRequest.php`, `app/Domain/Order/PaymentStateMachine.php`,
`app/Enums/PosPaymentMethod.php`, `app/Services/Fiscal/ZReportCashEnrichmentService.php`,
`app/Services/Order/RefundWithCounterEntryService.php`.

---

## VECTEURS ABUSÉS — TENUE PROUVÉE (fausses certitudes réfutées)

- **Double-clic confirm (2 caissiers / replay)** → TENU. `confirmCounterPayment` (`PaymentService.php:278-310`)
  discrimine collecteur via l'audit `order.counter_payment_confirmed`: même caissier = no-op 200 ; autre caissier
  (perdant du `lockForUpdate`) = `PaymentAlreadyCollectedException` → route `api.php:858-875` convertit en **409**
  `payment_already_collected` (NON caché par IdempotencyKeyMiddleware, 2xx-only). Le mot "UNHEALED" dans le
  commentaire ligne 228 réfère à l'HISTORIQUE J-CASCADE ; le bloc EST healé (K2-HEAL-01).
  Preuve live: `SELECT order_id,COUNT(*) FROM transactions WHERE type='payment' GROUP BY order_id HAVING COUNT(*)>1`
  → **0 ligne** (aucun double-encaissement réel). Tests `phpunit --filter 'CounterCollect|PaymentAlreadyCollected|EncaisserKds|C5_'` = **16/16**.

- **Re-payer un order déjà PAID** → TENU. `PaymentStateMachine.php:17` `PAID => []` (aucune transition sortante) ;
  garde au-dessus court-circuite avant même d'atteindre `assertCanTransition` (l.313). Impossible de re-sceller.

- **Mode hors-liste injecté (ex. 6=COUNTER_DEFERRED, 99)** → TENU 2 couches. `confirmCounterPayment` allowedModes
  = [CASH,CARD,MOBILE,OTHER,TICKET] (`:203-215`) → 422 ; split `validateBreakdown:83` rejette mode∉[1..5] +
  `PosOrderRequest:141` `in:1,2,3,4,5`. Route `counter-collect/confirm` valide `mode` integer libre MAIS le
  service filtre. Preuve live order_payments: aucune ligne mode∈{7,8,99}.

- **Split Σ tranches ≠ total bloque** → TENU. `validateBreakdown:147-166` rejette Σ<total ET Σ>total+1€ (tolérance).
  Preuve live: `SELECT SUM(op.amount) vs o.total` sur TOUTES les commandes splitées → **0 écart >1cent**.

- **Tranche CASH tendered < amount (monnaie négative)** → TENU. `validateBreakdown:104` `(int)round(tendered*100) <
  (int)round(amount*100)` → 422 ; `change_amount` est DÉCORATIF (Z fiscal n'utilise que `amount`,
  `ZReportCashEnrichmentService:170-171` `SUM(... amount ...)`).

- **Alloc fiscale gap si échec** → TENU. `confirmCounterPayment:321-323` alloue `fiscal_sequence_no` DANS la
  transaction (rollback atomique si throw). Preuve live:
  `SELECT count(*) FROM orders WHERE payment_status=2 AND fiscal_sequence_no IS NULL AND deleted_at IS NULL` → **0**.
  `phpunit --filter SplitPaymentServiceTest` = **11/11**.

- **Bypass/simulation (.env `PAYMENT_BYPASS_MODE=true`, `POS_SIMULATION_HARDWARE=true`)** → NON-finding V1.
  `BypassAuditLogger` + `config/payment.php:60-93`: court-circuite UNIQUEMENT l'appel hardware TPE/USB ;
  invariants (FiscalSequenceService, audit HMAC, idempotency, Outbox) PRÉSERVÉS ; boot-guard prod refuse
  (`forbidden_environments`). V1 LOCAL mono-poste = état assumé (CONSTITUTION).

- **Tranche `amount`/`tendered` négatifs en DB** → NON-finding (refund by-design). La ligne order_payments id=2
  (amount=-11, order 227) est le MIROIR de refund (order 226 +11 → 227 -11, `RefundWithCounterEntryService:211`
  `-1*parent->amount`). Comptabilité de remboursement correcte (Z balance parent +N / mirror -N), JAMAIS passée
  par validateBreakdown.

---

## FINDINGS PROUVÉES

### [P2] app/Services/Fiscal/ZReportCashEnrichmentService.php:157-175 — Le breakdown TPE informatif sur-déclare le CA carte de 128,50€ (tranches COUNTER_DEFERRED non-encaissées)
- **repro (DB live)**:
  `mysql -u root foodking_e2e -e "SELECT COUNT(*) n, SUM(paid_at IS NOT NULL) pa, ROUND(SUM(amount),2) s FROM order_payments WHERE mode=6;"`
  → `13 | 13 | 128.50`. Puis
  `mysql -u root foodking_e2e -e "SELECT o.payment_status,COUNT(*) FROM order_payments op JOIN orders o ON o.id=op.order_id WHERE op.mode=6 GROUP BY o.payment_status;"`
  → `5 (PENDING_COUNTER) | 13`. Ces 13 lignes ont `paid_at` non-null → entrent dans la fenêtre
  `aggregateByTerminal` (`WHERE paid_at <= to`, AUCUN filtre `payment_status`/`order_type`), mode=6 `<> CASH`
  → cumulé dans `card_total`. Or l'order n'est JAMAIS encaissé (payment_status=5, pas de fiscal_sequence_no).
- **evidence**: `aggregateByTerminal:157-175` (pas de jointure orders / pas d'exclusion PENDING_COUNTER) ;
  consommé par `enrich:110-124` (`net_after_fees`/`grossTotal`) → écran **CashSessionReport** (informatif).
  Le total Z SIGNÉ est CORRECT (`ZReportService.php:340 whereNotNull('fiscal_sequence_no')` exclut ces orders),
  et `persistForClosedReport:263` n'appelle que `aggregateForWindow` (sessions tiroir, pas order_payments) →
  **rien de phantom n'est persisté dans le ZReport ni la chaîne HMAC**. Impact = panneau "encaissé par TPE"
  runtime ment au commerçant vs le Z légal.
- **lentille**: commerçant (perte de confiance dans les chiffres TPE ; réconciliation manuelle faussée de +128,50€).
- **reco (NON-frozen)**: dans `aggregateByTerminal`, restreindre aux paiements réellement encaissés —
  jointure `orders` `whereIn('payment_status',[PAID, REFUNDED])` OU exclure `mode = COUNTER_DEFERRED`
  (les 13 placeholders sont des dépôts pré-encaissement). Le docblock l.139 reconnaît déjà le cas COUNTER_DEFERRED
  → l'intention est de NE PAS le compter comme CA. Ajouter test `phpunit` ZReportCashEnrichment qui place un
  PENDING_COUNTER mode=6 et asserte `card_total` inchangé. (Pré-existant: comprendre AUSSI quel writer crée ces
  lignes mode=6 — `validateBreakdown` rejette 6, donc soit antérieures au `in:1..5`, soit chemin tiers à fermer ;
  toutes datées 2026-06-12/13 → vérifier qu'aucun flux courant ne réémet mode=6.)

### [P3] PaymentService.php:315 + OrderService.php:1071-1073 — Garde "montant reçu < dû" sautée quand `received === null` (monnaie/honnêteté caissier, pas de perte argent)
- **repro (lecture code)**: `confirmCounterPayment:315` `if ($mode===CASH && $received !== null && $received < total)`
  → si le front omet `received` (route `api.php:851` passe `null` quand absent), la garde ne tire pas et
  `pos_received_amount` retombe sur `total` (`:328`). Idem vente directe `OrderService.php:1071-1073`
  (`pos_received_amount !== null`). Preuve d'empreinte historique:
  `mysql -u root foodking_e2e -e "SELECT id,total,pos_received_amount,created_at FROM orders WHERE pos_payment_method=1 AND pos_received_amount<total AND pos_received_amount IS NOT NULL AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5;"`
  → 14 commandes `pos_received_amount=0.00 < total` (ex. #4677 total 2€ reçu 0€). Ces lignes seraient REJETÉES
  aujourd'hui (received=0 non-null < total → throw), donc historiques/pré-garde — MAIS le trou `received=null`
  reste ouvert.
- **evidence**: aucun écart fiscal (le total Z = `order.total` correct ; `pos_received_amount` est un champ d'affichage
  "monnaie rendue"). C'est une faille de SAISIE-honnêteté: un caissier peut valider un encaissement cash sans
  renseigner le reçu → le ticket/écran ne calcule pas la monnaie, et la traçabilité "combien donné" est perdue.
- **lentille**: commerçant (audit interne du cash : impossible de prouver le rendu-monnaie a posteriori).
- **reco (NON-frozen, route/service)**: pour `mode=CASH`, rendre `received` REQUIS et `>= total` au niveau route
  `counter-collect/confirm` (`api.php:842-846` ajouter `required_if mode=1` + min) et vente directe
  (`PosOrderRequest:118` déjà `required` pour CASH — vérifier qu'il n'accepte pas 0<total: ajouter
  `withValidator` received>=total côté serveur). Bas risque, P3 (pas d'argent perdu, pure honnêteté/UX caisse).

---

## CONVERGENCE
P0=0, P1=0. 2 findings (1×P2 non-frozen TPE-info, 1×P3 honnêteté-cash). Tous les vecteurs argent/fiscal/double-
encaissement/split TIENNENT (prouvé live + 27 tests verts: SplitPaymentService 11/11 + CounterCollect 16/16).
Aucun fichier frozen touché (reco P2 = `ZReportCashEnrichmentService` NON-frozen ; reco P3 = route + `PosOrderRequest`
NON-frozen). Z signé NF525 = correct, chaîne HMAC intacte (lecture seule).
