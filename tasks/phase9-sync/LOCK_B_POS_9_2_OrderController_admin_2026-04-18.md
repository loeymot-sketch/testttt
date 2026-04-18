# LOCK_B — `app/Http/Controllers/Admin/OrderController.php` — POS-9.2.10

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] Aucun `LOCK_A_*` actif sur `OrderController`.
- [x] Aucun autre `LOCK_B_*` actif.

## Fichier et lignes prévues

**Fichier.** `app/Http/Controllers/Admin/OrderController.php`.

**Ajouts planifiés** :

| Vague | Scope |
|---|---|
| POS-9.2.10 | Ajout 5 actions : `cancel`, `accept`, `preparing`, `ready`, `delivered`. Chacune fine et uniforme : permission `pos`, résout `$order`, appelle `OrderStateMachine::apply($order, $targetStatus, $user, $reason)` dans `DB::transaction` + `DB::afterCommit(fn() => Event::dispatch(...))`. Retourne `OrderResource::make($order)`. |

## Règles de respect invariants pendant ce lock

- **Gatekeeper unique** : toute transition passe par `OrderStateMachine::apply`. Aucun `$order->status = X` direct.
- **Permission Spatie** : `$this->authorize('pos', $order)` ou équivalent, pas de policy custom non documentée.
- **Branch scope** : `branch_id` implicite via `$user->branch_id`, pas de lecture request.
- **Tests E2E** : `AdminOrderActionsTest` couvre les 5 routes avec transitions légales et illégales.

## ETA libération

**Release par.** Commit POS-9.2.10.
**Durée estimée.** ~1 heure.
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit 9.2.10.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.
