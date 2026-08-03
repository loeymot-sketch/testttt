# V4 AUDIT — CIBLE : CAISSE (prise commande + paiement INLINE)

HEAD `61e9ea7b7` + working-tree. Live `127.0.0.1:8766` / DB `foodking_e2e`.
Posture : GREEN = hypothèse à réfuter. Auth POS operator (uid 3, branch 1), x-api-key.

## VERDICT : GREEN_HELD — 0 P0/P1/P2 reproduit sur le chemin caisse inline.

Baseline établie (LIVE) : commande CASH #5429 → `payment_status=PAID(5)`, `fiscal_sequence_no=2604`,
`status=PREPARING(7)`, **exactement 1 cash_movement** (`amount=4.00 in order_payment`). Conforme au
modèle owner (walkin=false → payé inline).

## Attaques LIVE (toutes réfutées)

| # | Angle | Attaque | Attendu | Résultat LIVE |
|---|-------|---------|---------|---------------|
| A | Correctness/quote | Quote pour Petite(33,2.50€) puis POST Grande(34,4.00€) | rejet | **401 "Order quote intent mismatch"** (order non créée) |
| B | Correctness/underpay | CASH `received=1` < total réel 4€, champ `total` client OMIS (bypass check UI l.187) | 422 serveur | **422** `OrderService:1071-1078` "montant reçu (1€) < total réel (4€)" |
| C | NF525 CARD | CARD pm=2, note "1234", terminal 1 | PAID+fiscal, 0 cash_movement | **#5444 PAID(5) fiscal=2605, cash_movements=0** |
| E | Validation | CARD sans `pos_payment_note` (JSON int) | 422 last-4 requis | **422 "Last 4 digits of card is required"** |
| RACE | Concurrency/idempotence | 5 POST concurrents, même `X-Idempotency-Key`+body | 1 order/1 seq/1 mvt | **1 order #5445, 4 replayed, fiscal=2606, cash_movements=1** |
| — | Idempotence | POST sans header (route `api/admin/pos` required) | 422 | 422 (config idempotency.required_routes) |
| F1 | Failure | item_id 999999 | 422 | **422** "Article introuvable" |
| F2 | Failure | items = tableau (pas string JSON) | 422 | **422** JSON invalide |
| F3 | Failure | order_type=9999 | pas 500 | **422** "erreur de base de données" (msg générique, pas 500) |
| F4 | Security | branch_id=2 (caissier branch 1) | rejet | **422** "pas créer une commande pour une autre branche" |
| D1/D2 | Security/discount | remise 2€/4€ (50%) — au quote ET sneak via quote sans remise | rejet 2 surfaces | **422** "Discount above 10% requires manager approval" (sealForCommit ré-exécute quote → authz au store) |
| INT | Intersection | file `counter-collect/pending` | 0 order inline | **7 rows = kiosk pm=6 seulement**, #5429/5444/5445 absents |
| DEL | NF525 immutab. | DELETE order PAID #5429 (POS operator) | 403 | **403 "Paid orders cannot be destroyed without elevated permission"** |

## NF525 — chaîne fiscale branche 1
`count=2604, distinct=2604, DUPLICATES=[]`. 3 gaps `[2506,2507,2508]` → **AUCUNE ligne même `withTrashed()`**.
`OrderService::destroy` = soft-delete seul + `FiscalSequenceService::next` compte `withTrashed()` (l.97-101) →
soft-delete ne crée JAMAIS de gap. Ces 3 gaps sont des **hard-deletes de teardown test hors application**,
NON reproductibles via aucun chemin API (destroy PAID → 403, sealed-Z → 409). Verify-before-report → REJETÉ.

## V2 cash-trail heal confirmé
`OrderService:1268-1276` gate `!$splitActive && !$deferToCounter && pos_payment_method==CASH` →
inline CASH = exactement 1 mouvement (vérifié RACE + baseline). Le bug V2 (double/phantom) ne se reproduit pas.

## Observations mineures (NON bugs, non chiffrantes — non signalées comme cassé)
- CARD : `cash_back_amount=-4` dans la ressource (`received null → 0 - total`). Cosmétique ; rendu ticket owner-BON.
- `PosOrderRequest:117-118` `===` string/int : n'impacte que le form-encoded (string) ; le front envoie du JSON int
  (note+received DONC enforced, cf. attaque E). Le mouvement enregistre le total complet quelle que soit la valeur
  `received` → pas de perte d'argent. Connu/"laissé exprès".
- F3 : message 422 "erreur de base de données" générique (DX, non exploitable, pas 500).

## Held-green attesté
Re-pricing SSOT (total client unset l.705), quote HMAC+intent+branch+expiry+single-consume, underpay serveur,
NF525 CASH/CARD 1↔0 mouvement, idempotence concurrente at-most-once, cross-branch bloqué, discount authz
au store, isolation file d'encaissement, immutabilité destroy. Reproduit ≥2× (baseline + RACE).
