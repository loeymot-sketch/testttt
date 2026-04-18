# AUDIT_POS_STATUS_TRANSITIONS_003 — Transitions d'états POS

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001
- **Estimation** : 0.5 j-h
- **Vague** : A3

## Contexte

Pipeline canonique : PENDING(1) → ACCEPT(4) → PREPARING(7) → PREPARED(8) → OUT_FOR_DELIVERY(10) → DELIVERED(13). Terminaux : CANCELED(16), REJECTED(19), RETURNED(22).

`OrderService::changeStatus` (~L1363) doit s'appuyer sur `OrderStateMachine::allows($from, $to)` et `apply()`. Toute transition hors machine d'état = régression.

## Questions d'audit

1. `changeStatus` passe-t-il systématiquement par `OrderStateMachine::allows()` avant d'appliquer ? Existe-t-il un chemin qui écrit directement `$order->status = X`-> save ?
2. Chaque transition génère-t-elle un `OrderStatusHistory` avec ancien/nouveau + acteur + timestamp ?
3. L'event `OrderStatusChanged(old, new, order)` est-il dispatché **après commit** avec payload conforme EventContract V1 (required keys : order_id, old_status, new_status) ?
4. Les transitions terminales (CANCELED/REJECTED/RETURNED) bloquent-elles toute transition ultérieure ?
5. Le POS peut-il forcer une transition non canonique (ex PENDING → DELIVERED direct) ? Seulement si rôle autorisé ?
6. Les rôles / permissions (Spatie) sont-ils vérifiés par transition (ex : cuisine peut PREPARING→PREPARED mais pas REJECT) ?
7. `docs/ORDER_FLOW.md` liste les 11 transitions légales : le code respecte-t-il exactement cette liste ?
8. Que se passe-t-il en cas de conflit optimiste (deux POS changent le statut simultanément) ? Locks ? Version colonne ?
9. Les reports Playwright antérieurs (reports/antigravity/) confirment-ils le comportement UI ↔ backend ?
10. Les transitions déclenchent-elles des side effects non atomiques (push notif, email) ? Sont-ils en `afterCommit` ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` — `changeStatus` (~L1363)
- `app/Domain/Order/OrderStateMachine.php`
- `app/Enums/OrderStatus.php`
- `app/Models/OrderStatusHistory.php`, `app/Models/Order.php`
- `app/Events/OrderStatusChanged.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `docs/ORDER_FLOW.md` (référence)

### SUBSYSTEMS_OFF_LIMITS
- KDS / OSS (audit séparé)
- Flows Stripe / Paypal

## Invariants at Risk
- [x] OrderStatus enum
- [x] Dispatch after DB commit
- [x] OrderService / FrontendOrderService symmetry
- [x] Frozen zone

## Fichiers à lire
1. `app/Domain/Order/OrderStateMachine.php`
2. `app/Enums/OrderStatus.php`
3. `app/Services/OrderService.php` (section changeStatus)
4. `app/Models/OrderStatusHistory.php`
5. `app/Events/OrderStatusChanged.php`, `app/Listeners/PersistOrderStatusChangedToOutbox.php`
6. `docs/ORDER_FLOW.md`
7. Tests : `tests/Unit/Domain/Order/OrderStateMachineTest.php` (s'ils existent)

## Grep patterns

```
grep -n "changeStatus" app/Services/OrderService.php
grep -n "->status\s*=" app/Services/ app/Http/Controllers/
grep -n "OrderStateMachine::\|->allows(\|->apply(" app/
grep -rn "OrderStatusHistory::create\|OrderStatusHistory::" app/
grep -rn "OrderStatusChanged::dispatch" app/
grep -n "STATUS_" app/Enums/OrderStatus.php
grep -rn "Gate::\|authorize(" app/Http/Controllers/Admin/Order/
```

## Evidence required
- Extrait de `OrderStateMachine` : mapping des transitions.
- Comparaison vs `docs/ORDER_FLOW.md` (tableau 11 transitions).
- Liste des endroits qui écrivent `->status =` hors machine d'état (doit être vide).
- Vérification OrderStatusHistory créé pour chaque transition (pas seulement certaines).
- Check des permissions par transition.

## Grille de verdict
- **PASS** : 100% des transitions passent par StateMachine, history systématique, events `afterCommit`, permissions par rôle.
- **WARN** : ≤ 1 écriture directe `->status =` dans un chemin non-critique (ex : seed, migration).
- **BLOCKED** : écriture directe dans un flux POS live, absence d'history, event avant commit, transition interdite atteinte.

## Livrable
`reports/review/AUDIT_POS_STATUS_TRANSITIONS_003_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
