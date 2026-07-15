# Journey A — CAISSE (POS) — Round 1 — 2026-07-15

Serveur LIVE http://127.0.0.1:8000. Token admin forgé `e2e-A-caisse-r1`, token POS Operator `e2e-A-pos` (pos@lecayenne.fr, branch_id=1, can pos / NO pos-refund).

## Parcours exécuté (preuves)

Le POST /api/admin/pos EXIGE un quote scellé (`quote_token`+`quote_signature`) — sinon 401 « Order quote token and signature are required together » (OrderQuoteService.php:117). Flux réel = POST /pos/quote → POST /pos.

- **Vente ESPÈCES** — quote 2×Frites Cheddar (item 107) subtotal 7,00 → POST /pos received=10 → **HTTP 201, order 5688, total 7,00, fiscal_sequence_no=2645**, cash_movement `in` 5688 = 7,00 (direction in). GET /pos-order/show/5688 = total/subtotal cohérents, ligne « Petite Frites Cheddar fondu » qty 2 = 7,00. OK.
- **Vente CARTE** — item 108 (4,50) pos_payment_method=2, note 1234, terminal_id=1 → **HTTP 201, order 5692, fiscal_sequence_no=2647**, 0 cash_movement (pas de fantôme tiroir). OK. ⚠️ `order.terminal_id` = NULL et 0 order_payment (voir Finding #1).
- **Commande TÉLÉPHONE différée** — pos_payment_method=6, phone_order=true → **HTTP 201, order 5697, payment_status=15 (PENDING_COUNTER), fiscal_sequence_no=NULL** (alloué à l'encaissement). OK.
- **Encaissement counter-collect** — POST /pos/counter-collect/5697/confirm mode=1 received=10 → **HTTP 200, payment_status=5, fiscal_sequence_no=2650**, cash_movement `in` 5,00. Séquence fiscale allouée à l'encaissement, gap-free (2643→2650 continu). OK.
- **Annulation NON payée** (POS Operator) — order 5701 (PENDING_COUNTER) → POST /pos-order/change-status/5701 status=16 reason=… → **HTTP 200**. OK (geste opérationnel non gardé).
- **Annulation PAYÉE** (POS Operator) — order 5688 (PAID cash) → status=16 → **HTTP 403 « Permission insuffisante pour effectuer un remboursement »** (PosOrderController.php:315). OK (le drainage tiroir déguisé est bloqué).
- **Park / Recall** (POS Operator) — POST /pos/parked-orders → parked 44 → GET /pos/parked-orders/44 → **HTTP 200** payload restitué. OK.
- **Idempotency replay** — même X-Idempotency-Key 2× → même order 5708 (pas de doublon). OK.

## Adversaire (invariants qui tiennent)
- Sous-encaissement cash à la création (total=5/received=5 pour 7,00) → **422** (garde serveur OrderService:1135, recalcul SSOT). OK.
- Sous-encaissement au comptoir (received=2 pour 5,00) → **422 « Le montant recu est inferieur au total a encaisser »**. OK.
- Remise > sous-total (discount=100 sur 3,50) → **422** (OrderQuoteService::assertManualDiscountAllowed:406). OK.

## FINDINGS

### Finding #1 — P2 — terminal_id CARTE single-tender exigé mais jamais persisté → ventilation par TPE du Z toujours vide
- **repro** : quote CARTE item 108 → POST /pos {pos_payment_method:2, terminal_id:1, note:1234} → HTTP 201 order 5692. `Order::find(5692)->terminal_id` = **NULL** ; `OrderPayment::where('order_id',5692)->count()` = **0**.
- DB globale : `OrderPayment` mode=2 = 57 lignes mais 74 commandes POS carte payées → l'écart = ventes single-tender carte SANS order_payment (les 57 viennent du split-tender).
- La règle PosOrderRequest.php:157-163 REND `terminal_id` **required_if** carte single-tender (le caissier DOIT le saisir) — commentaire ligne 147-156 affirme que « legacy single-tender CARD sales were still bucketed as Sans TPE » a été « closed ». Or `posOrderStore` n'écrit un order_payment QUE via SplitPaymentService (payload payment_breakdown) — jamais pour le single-tender. `ZReportCashEnrichmentService::aggregateByTerminal` (ligne 156) lit EXCLUSIVEMENT `order_payments` → pour une journée de ventes carte single-tender, la ventilation « par TPE » (volume + frais) est **vide / 0**. La valeur terminal_id saisie va au néant.
- **Note** : les totaux Z signés NF525 restent corrects — `ZReportService::applyOrderToTotals:792` agrège `pos_payment_method` depuis l'ordre, pas order_payments. Donc c'est le rapport secondaire de ventilation TPE (frais Plan B) qui est faux, pas le total fiscal.

### Finding #2 — P3 — terminal_id inexistant/étranger accepté pour une vente CARTE single-tender (pas de contrôle d'existence/propriété)
- **repro** : quote CARTE item 12 → POST /pos {pos_payment_method:2, note:9999, terminal_id:999} → **HTTP 201, order créé, fiscal_sequence_no=2648**. Le seul TPE en base est id=1 ; 999 n'existe pas.
- Le contrôle profond (existence + branch + status ACTIVE) de `PosOrderRequest::withValidator` (lignes 261-294) ne boucle QUE sur `payment_breakdown` (tranches split). Le `terminal_id` top-level single-tender n'a qu'une règle de forme (`nullable/integer/min:1`, PosOrderRequest.php:157-163) — aucun `exists`. Impact atténué par Finding #1 (la valeur n'est de toute façon pas persistée), mais l'invariant « le TPE doit appartenir à la branche et être actif » n'est PAS appliqué au chemin single-tender.

### Finding #3 — P3 — message d'erreur user-facing avec nombre brut non formaté « 7.000000€ »
- **repro** : POST /pos {total:5, pos_received_amount:5} pour un ordre à 7,00 → **HTTP 422 « Le montant reçu (5€) est inférieur au total réel (7.000000€). »**
- OrderService.php:1140 concatène `$this->order->total` (cast decimal 6 chiffres) directement dans le message → le caissier voit « 7.000000€ » au lieu de « 7,00 € ». Chemin défense-en-profondeur atteignable quand le check requête (client `total`) passe. Non bloquant, cosmétique.

## Verdict
Money-path CAISSE robuste : fiscal_seq alloué/gap-free (cash à la création, différé à l'encaissement), pas de fantôme tiroir carte, sous-paiement bloqué, annulation payée vs non-payée gardée correctement, idempotency sans doublon, remise > sous-total refusée. Défauts trouvés = P2/P3 sur la ventilation TPE (Plan B, non fiscal) + 1 cosmétique.
