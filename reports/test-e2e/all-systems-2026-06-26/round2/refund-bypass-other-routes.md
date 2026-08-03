# Round 2 — Refund-bypass : AUTRES routes que change-status

**Lane** : un POS Operator (role 7, sans `pos-refund`) peut-il sortir de l'argent par un autre chemin que le POS change-status déjà healé ?
**HEAD probé** : `c8e1378dd` (post-heal `10e462149` + `4fe7c2a7f`)
**Méthode** : Read contrôleurs + middleware + state machine + service, SELECT-only sur `foodking_e2e`. READ-ONLY (0 écriture, 0 ordre placé).

---

## VERDICT lane

- **Role 7 (POS Operator) = DÉFENSE TIENT (HOLD).** Les 5 sous-chemins (a–e) sont bloqués pour role 7. Preuve ci-dessous.
- **MAIS le heal est un pansement par-contrôleur** → **NEW_FINDING [P3 latent]** : le jumeau `table-order` (et `online-order`) n'a PAS la garde `pos-refund` ajoutée sur le POS. Exploitable en théorie par le rôle **Waiter (role 4)** — mais **0 utilisateur Waiter provisionné** ⇒ latent.

---

## Preuve : role 7 NE PEUT PAS rembourser (HOLD)

Matrice permissions live (`foodking_e2e`) :

| role | online-orders | table-orders | pos-refund | pos | pos-orders | n_users |
|------|:---:|:---:|:---:|:---:|:---:|:---:|
| 1 Admin | 1 | 1 | 1 | 1 | 1 | 21 |
| 4 Waiter | 0 | **1** | **0** | 0 | 0 | **0** |
| 6 Branch Manager | 1 | 1 | 1 | 1 | 1 | 5 |
| **7 POS Operator** | **0** | **0** | **0** | 1 | 1 | **44** |

Le money-out réel (`PaymentService::cashBack` → Transaction `cash_back` sign `-` + `User->balance += total` + `RefundCreated` release stock + drawer-out) n'est atteignable QUE via `OrderService::changeStatus` → RETURNED/CANCELED/REJECTED (`app/Services/OrderService.php:2188-2194`). `changePaymentStatus` n'appelle JAMAIS cashBack.

- **(a) `OnlineOrderController::changeStatus`** (`app/Http/Controllers/Admin/OnlineOrderController.php:94-101`, group `routes/api.php:1014`) — middleware `permission:online-orders` (constructeur l.34). Role 7 ne l'a pas → **403**. BLOQUÉ.
- **(b) `TableOrderController::changeStatus`** (`app/Http/Controllers/Admin/TableOrderController.php:63-70`, group `routes/api.php:1027`) — middleware `permission:table-orders` (constructeur l.26). Role 7 ne l'a pas → **403**. BLOQUÉ.
- **(c) `counter-collect/{order}/cancel`** (`routes/api.php:880`) — `abort_unless(can('pos'),403)` : role 7 l'A. MAIS `PaymentService::cancelCounterPayment` (`app/Services/PaymentService.php:621-690`) **n'appelle PAS cashBack** ; `assertCounterDeferredOrder` (l.700-719) exige `pos_payment_method=COUNTER_DEFERRED` + `payment_method=CASH_ON_DELIVERY` (ordre JAMAIS encaissé). Commentaire l.649-650 : « no fiscal-seq was allocated (never collected), so nothing enters the signed Z ». **Aucun argent ne sort.** BLOQUÉ.
- **(d) double `refund-with-counter-entry`** — `PosOrderController::refundWithCounterEntry:58-62` exige `pos-refund` : role 7 → **403**. Et `cashBack` est idempotent (`PaymentService.php:97-103`, early-return si Transaction `cash_back` existe déjà) ⇒ pas de double money-out même avec pos-refund. BLOQUÉ.
- **(e) `changePaymentStatus` → REFUNDED**` (`pos-order/change-payment-status`, role 7 a `pos-orders`) — `OrderService::changePaymentStatus` (`OrderService.php:2292-2484`) **n'appelle jamais cashBack**, et `PaymentStateMachine::TRANSITIONS` (`app/Domain/Order/PaymentStateMachine.php:9-18`) a `PAID(5) => []` (terminal) ⇒ PAID→REFUNDED rejeté (422). BLOQUÉ.

Défense en profondeur supplémentaire : pour les états PRÉ-livraison, `OrderStateMachine::allows` gate lui-même RETURNED sur `pos-refund` (`app/Domain/Order/OrderStateMachine.php:48,59,67` — ACCEPT/PREPARING/PREPARED→RETURNED requièrent pos-refund). Role 7 serait donc bloqué AU NIVEAU state-machine même s'il atteignait le service sur un ordre non-livré.

**⇒ Le heal POS `10e462149` tient pour role 7. Aucun chemin de sortie d'argent.**

---

## [P3 LATENT] `app/Http/Controllers/Admin/TableOrderController.php:63-70` — jumeau refund non-gardé (Waiter)

**Titre** : Le heal `pos-refund` (PosOrderController:328) n'a PAS été mironé sur le sibling `table-order` → la transition RETURNED (money-out cashBack) y est sans garde `pos-refund`.

**Mécanique** :
1. `OrderStateMachine.php:76-77` : `case DELIVERED: return $to === RETURNED;` → **DELIVERED→RETURNED est INCONDITIONNEL** (owner-locked `LOCK-OSM-PREZ-REFUND`). Donc pour un ordre LIVRÉ, le SEUL garde-fou d'autorisation est la couche contrôleur.
2. `PosOrderController::changeStatus:328-334` ajoute `abort_unless(can('pos-refund'))` pour RETURNED. `TableOrderController::changeStatus:63-70` et `OnlineOrderController::changeStatus:94-101` délèguent à `OrderService::changeStatus` **sans cette garde**.
3. `OrderService::changeStatus:2188-2194` fire `cashBack()` quand `$locked->transaction` existe (ordre payé).

**Repro (statique — pas de Waiter live pour exécuter, READ-ONLY)** :
`POST /api/admin/table-order/change-status/{order}` body `{status:22, reason:"x"}` par un user role 4 (Waiter : `table-orders`=1, `pos-refund`=0) sur un ordre **DELIVERED(13)+PAID(5)** → `permission:table-orders` passe → `ValidStatusTransition` passe (DELIVERED→RETURNED inconditionnel) → `cashBack` → argent sorti + `RefundCreated` + Z impacté, **sans `pos-refund`**.

**Evidence** :
- DB : role 4 = `{table-orders:1, pos-refund:0}` ; **2159 ordres DELIVERED+PAID branch 1** = cibles abondantes.
- `OrderStateMachine.php:77` inconditionnel ; `TableOrderController.php:26` n'a que `permission:table-orders`.

**Pourquoi P3 (et pas P1/P2)** :
- **0 utilisateur Waiter (role 4) provisionné** (`SELECT COUNT(*) FROM model_has_roles WHERE role_id=4` = **0**). Aucun acteur existant ne peut l'exploiter aujourd'hui ; les 44 users role 7 sont totalement bloqués.
- Entièrement **tracé/auditable** : `cashBack` écrit `payment.cash_back_issued` (HMAC) + `changeStatus` écrit `order.returned`. Pas de vol silencieux.
- Pré-livraison protégé par la state-machine elle-même (l.48/59/67). Seule l'arête DELIVERED→RETURNED est exposée.
- Jumeau `online-order` = **moot** : seuls Admin + Branch Manager ont `online-orders`, et **les deux ont déjà pos-refund** (ensemble online-orders ⊆ pos-refund).

**Devient P2/P1 si** : l'owner provisionne un user role 4 (Waiter) via `/admin/role/{id}/edit` — le rôle est seedé avec exactement `table-orders ∧ ¬pos-refund`.

**Lentille** : jumeau-systémique / heal-par-instance. Le fix a gardé 1 des 3 contrôleurs siblings qui délèguent tous au même `OrderService::changeStatus`→cashBack non-gardé.

**Reco (non-frozen)** : mirorer `PosOrderController:328-334` sur `TableOrderController::changeStatus` et `OnlineOrderController::changeStatus` (couche contrôleur, NON-frozen). NE PAS toucher `OrderStateMachine.php` (frozen + owner-locked `LOCK-OSM-PREZ-REFUND`). Option robuste : centraliser l'autorisation RETURNED dans `OrderService::changeStatus` (un seul point pour les 3 siblings).
