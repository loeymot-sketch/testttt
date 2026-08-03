# V2 Révalidation adversariale — CAISSE (commande + paiement espèces & carte)

Cible : `POST /api/admin/pos`, `POST /api/admin/pos/quote`, `POST /api/admin/pos/counter-collect/{order}/confirm`
Serveur LIVE 127.0.0.1:8766 (DB foodking_e2e), HEAD 61e9ea7b7 + working tree.
Auth : admin@lecayenne.fr / 123456 → token Sanctum, header `x-api-key`.
Config runtime observée : `split_payment.enabled=true`, `pos.walkin_route_to_counter=false` (modèle **inline-paid** actif ; `.env POS_WALKIN_ROUTE_TO_COUNTER=false`). Le modèle B différé est atteint via `defer_to_counter=1` (branche `$deferToCounter` **identique** à celle qu'active `walkin_route_to_counter=true`).

## VERDICT : BROKEN (1× P2 cash-trail) — le reste HELD GREEN

---

## BROKEN — P2 : double / phantom mouvement de caisse sur les commandes DIFFÉRÉES

`app/Services/OrderService.php:1258-1266` (posOrderStore) enregistre un `cash_movement` dir=in
dès la **CRÉATION** dès que `(int)$request->pos_payment_method === CASH`, **sans vérifier `$deferToCounter`**.
Pour une commande différée (PENDING_COUNTER, non encore payée, fiscal=NULL), le vrai encaissement
réenregistre un `cash_movement` à `PaymentService::confirmCounterPayment` (`recordCashOrderMovement`, si mode=CASH).

Repro LIVE (defer_to_counter=1, pos_payment_method=1) :

```
# 5426 : commande différée espèces puis encaissée espèces
create=201  -> order 5426 pay=15(PENDING_COUNTER) posm=6(COUNTER_DEFERRED) fiscal=NULL
AFTER CREATE : cash_movements(5426)=1  (cm 390 dir=in amt=7.00)   <-- prématuré, avant paiement
confirm mode=1 received=10 -> 200
AFTER CONFIRM: cash_movements(5426)=2  (cm 390=7.00 + cm 391=7.00) => 14,00€ enregistrés pour une commande de 7€
transactions=1 (tx 1055 counter_cash 7,00) ; fiscal alloué 1 seule fois

# 5425 : commande différée (créée en espèces) puis encaissée CARTE
confirm mode=2 -> pay=5 posm=2 recv=NULL note='CARTE7' fiscal=2601 ; tx counter_card 7
cash_movements(5425)=1 (phantom cash-in de 7€ alors que le paiement est CARTE => aucun espèce en tiroir)
```

Contrôle (modèle inline actif, held green) : `5416` inline cash → 1 cash_movement ✓ ; `5417` inline card → 0 cash_movement ✓.

Impact : corruption de la piste caisse NF525 / réconciliation tiroir (même `cash_drawer_session_id=32`).
- Modèle B (`walkin_route_to_counter=true`, config validée owner) : **chaque** walk-in espèces double son cash-in (le front caisse envoie `pos_payment_method=CASH` par défaut — `PosComponent.vue:1749/3570`).
- `defer_to_counter=1` est atteignable par API pour n'importe quel caissier même en config inline par défaut → phantom/double cash-in à la demande.
- La séquence fiscale reste correcte (allouée 1× à l'encaissement) ; le défaut est isolé à `cash_movements`.
Fix suggéré (non appliqué) : gater la ligne 1258 par `&& ! $deferToCounter` — un ordre différé ne doit écrire son mouvement de caisse qu'à l'encaissement.

Non pré-trié : distinct du garde-fou « fiscal à l'encaissement = correct » (le seq est bon) et des « counter-collect-closures déférés ». Concerne la table cash_movements.

---

## HELD GREEN (attaques tentées, échouées = robuste)

- **Idempotency obligatoire** : POST /pos sans `X-Idempotency-Key` → 400 `MISSING_IDEMPOTENCY_KEY`.
- **Quote signé obligatoire** : store sans quote_token+signature → 401 « token and signature are required together ».
- **Intent-hash lie pos_payment_method + discount** : quote(pm=0)→store(pm=1) → 401 « intent mismatch » ; ajout d'un `discount=6` non présent dans le quote → 401. Impossible d'injecter une remise/tender hors quote signé.
- **Re-pricing SSOT** : store avec `total=1&subtotal=1` (sans discount) → 201 mais total facturé=7, subtotal=7 (serveur recalcule, ignore le client).
- **Cash received<total (création)** : `total=1,received=1` (spoof) → 422 « montant reçu (1€) inférieur au total réel (7€) » (garde service-layer `==` lâche, compense le bug FormRequest `===`).
- **Received négatif** → 422 min:0.
- **Carte sans terminal_id** → 422 required_if.
- **Double-POST même clé + même payload** → même order id (5422 puis 5424 identiques), aucune commande dupliquée.
- **Même clé + payload différent (qty 1→2)** → 409 `IDEMPOTENCY_KEY_CONFLICT`.
- **confirmCounterPayment cash received<total** → 422 « montant reçu inférieur au total à encaisser ».
- **confirmCounterPayment carte** → 200, PAID, fiscal alloué **à l'encaissement** (pas à la création) ; montant carte persisté via `Transaction(counter_card=7)` (pos_received_amount=null = normal, la monnaie rendue ne concerne que l'espèce).
- **Double-confirm même caissier** → 200 no-op idempotent : exactement 1 transaction, 1 cash_movement, 1 audit_log, 1 fiscal seq (zéro doublage).
- **NF525 séquence** : orders 5416→5426 = seq 2594→2602 consécutifs, monotone, gap-free, 0 doublon sur branch 1.

## Non re-signalés (déjà triés v1)
- `PosOrderRequest:117` `===` string/int : format note carte (`min_digits:4`) NON appliqué (note « abc » / « 12345 » acceptées 201) et `pos_received_amount` non « required ». Garde-fou v1 : connu / laissé exprès ; compensé par le contrôle received<total service-layer. Non re-signalé.
