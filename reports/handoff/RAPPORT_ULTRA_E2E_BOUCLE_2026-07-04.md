# ULTRA A→Z — TEST-E2E EN BOUCLE (exécution LIVE) — 2026-07-04
**Goal** : « va plus deep et test-e2e en boucle » — exécuter les vrais flux bout-en-bout en live, boucler
jusqu'au vert, valider les heals sur données réelles (pas seulement audit statique). HEAD `58c86aa13`.

## 1. Chaîne LIVE borne Plan B → encaissement → fiscal (navigateur réel, VERT en 3 itérations)
Spec `tests/e2e/_ultra-e2e-borne-planB-encaissement-2026-07-04.spec.js` (live-DB, non-CI). Pilote la vraie
borne headless : idle → takeaway → wizard produit → panier → upsell → « confirmer au comptoir » →
`POST /api/frontend/order`, puis vérifie chaque maillon en base + encaisse.

**Commande #5475 (preuve, `e2e-borne-planB-result.json`)** :
| Maillon | Attendu | Obtenu |
|---|---|---|
| Création borne UI | order créé, kiosk | ✅ #5475 `source_surface=kiosk`, `order_type=10` |
| Routing Plan B | PENDING_COUNTER, pas de fiscal | ✅ `payment_status=15`, `pos_payment_method=6` (COUNTER_DEFERRED), `fiscal=NULL` |
| KDS board-release | visible malgré impayé | ✅ `released_for_board=true` |
| Encaissement (`confirmCounterPayment`) | PAID + fiscal alloué | ✅ `payment_status=5`, **`fiscal_sequence_no=2624`** (2623→2624 monotone) |
| Runtime navigateur | 0 erreur | ✅ `pageerrors: []` |

**Boucle red→red→green** (discipline e2e) : it.1 fixture supprimée par retry+cleanup + signature `confirmCounterPayment` → corrigé (retries off, Order arg) ; it.2 upsell skip raté (bouton se re-rend toutes les 100 ms, timer autoskip 30 s) → clic en boucle + attente 45 s > autoskip ; it.3 **VERT**.

## 1bis. Chaîne LIVE cuisine→client : KDS bump → OSS mur client + timing (navigateur réel, VERT en 4 itérations)
Spec `tests/e2e/_ultra-e2e-kds-oss-timing-2026-07-04.spec.js`. Commande kiosk née à ACCEPT sur le board →
bump ACCEPT→PREPARING→PREPARED via l'**endpoint KDS réel** (`admin/kds-order/change-status`) → mur client OSS.

**Commande #5484 (`e2e-kds-oss-result.json` + captures)** :
| Maillon | Attendu | Obtenu |
|---|---|---|
| Naissance ACCEPT | `accepted_at` posé (hook timing Wave 1) | ✅ `accepted_at_stamped=true` |
| KDS reçoit + affiche le produit | visible | ✅ (assertion produit passée) |
| Bump ACCEPT→PREPARING (endpoint réel) | `preparing_at` posé sur transition réelle | ✅ `status=7`, `preparing_at=true` |
| Bump PREPARING→PREPARED | `prepared_at` + `actual_prep_seconds` | ✅ `status=8`, **`actual_prep_seconds=7`** (durée réelle entre bumps) |
| Mur client OSS | commande visible colonne « Prêt » | ✅ `oss_shows_queue_number=true` — **N°K19C** dans « Prêt » (capture `e2e-oss-01-wall.png`) |

C'est le heal timing Wave 1 prouvé **sur le vrai chemin de transition** (endpoint KDS → changeStatus → hook modèle),
avec `actual_prep_seconds` = durée RÉELLE (7 s), pas 0/instant. Boucle red→red→green : it1/it2 `order_items`
NOT-NULL (`branch_id`, `discount`) → corrigés ; it3 vert ; it4 assertion OSS durcie (queue_number). Note design :
l'OSS affiche le **queue_number** (repère client), pas le `order_serial_no`.

## 2. Validation LIVE des heals au niveau service (rollback-wrappé, non-destructif)
Exécuté sur la base live sous `DB::transaction`+`rollBack` (0 pollution, 0 fiscal_seq consommé) :
| Heal | Assertion live | Résultat |
|---|---|---|
| **Wave 1 timing centralisé** (hook modèle Order) | commande née à ACCEPT → `accepted_at` posé ; `preparing_at` NULL | ✅ |
| | → PREPARING → `preparing_at` posé | ✅ |
| | → PREPARED → `prepared_at` posé ; `actual_prep_seconds` calculé par `KDSOrderDetailsResource:51` (diff accepted↔prepared) | ✅ |
| **Wave 2 UNPAID→PAID off-book** | `changePaymentStatus(UNPAID→PAID)` → `fiscal_sequence_no` alloué | ✅ |
| **Wave 2 fiscal-at-encaissement** | cf. §1 borne #5475 → fiscal 2624 | ✅ |
| **Wave 3 split cash+card** | `SplitPaymentEndToEndTest` 8/8 (flux HTTP complet, dominant+tendered partiel réels) | ✅ |

Note méthode : `actual_prep_seconds` n'est PAS une colonne DB mais un champ calculé de la resource → une 1ʳᵉ
assertion (sur l'attribut modèle) fausse-négative a été corrigée en interrogeant la resource (le heal est sain).

## 2bis. File d'encaissement counter-collect (fonction partagée borne→caisse) — validée LIVE
Réplique EXACTE de la query de l'endpoint `GET /api/admin/pos/counter-collect/pending` (routes/api.php:818)
exécutée live sur 4 variantes (rollback-wrappé) → valide les DEUX moitiés du fix `258f74722` :
| Commande | Attendu | Live |
|---|---|---|
| kiosk PENDING_COUNTER actif | dans la file | ✅ IN |
| kiosk PENDING_COUNTER **CANCELED** | exclue (pas de fantôme 422) | ✅ OUT |
| **source_surface NULL** kiosk/emporter PENDING_COUNTER | rattrapée (filet anti-NULL) | ✅ IN |
| kiosk PAID | absente | ✅ OUT |
+ test `CounterCollectQueueRobustTest` 3/3 vert.

## 3. GATES
- Chaîne LIVE borne verte (1 test, 3 itérations) · heals service-layer 5/5 verts live · **NF525 CHAIN OK** (4 branches) après les runs · 0 pageerror.
- Le nettoyage e2e supprime la commande fixture + son order → 1 gap de fiscal_seq sur la base DEV (artefact de test attendu, pas de prod ; la chaîne HMAC audit_logs/z_reports reste intacte).

## 4. CONVERGENCE e2e
La chaîne cross-surface la plus riche (borne → PENDING_COUNTER → KDS board → encaissement → PAID+fiscal) est
prouvée LIVE bout-en-bout, et les 4 heals de la campagne sont validés sur données réelles. La boucle e2e a
convergé (vert). Combiné aux 4 waves d'audit, le backend V1 LOCAL est validé A→Z en statique ET en exécution.
