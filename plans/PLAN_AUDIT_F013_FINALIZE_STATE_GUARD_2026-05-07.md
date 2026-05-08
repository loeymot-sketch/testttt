# PLAN_AUDIT_F013 — finalizePaidKioskOrder State Guard
**Severity:** P3 — Cohérence pattern frozen
**Owner agent:** Agent A
**Sprint:** Backlog

## THINK

[app/Services/FrontendOrderService.php:797](app/Services/FrontendOrderService.php:797) :
```php
if ((int) $locked->status >= OrderStatus::ACCEPT) {
    return;
}
```

Le check `>= ACCEPT (4)` skip si l'order est déjà avancé. Mais il skip aussi pour CANCELED (16), REJECTED (19), RETURNED (22) — ce qui est correct pour ACCEPT mais l'intent n'est pas clair, et la state machine logic n'est pas centralisée.

Pas un bug actif (la logique est correcte par accident des numérotations), mais **fragile** : un futur ajout d'un statut intermédiaire entre PENDING (1) et ACCEPT (4) (ex. status 2 ou 3 pour "auth fraud check pending") casserait cette logique.

## PLAN

1. Remplacer le check par une whitelist explicite : `if (!in_array((int)$locked->status, [OrderStatus::PENDING], true))` — seul PENDING peut être promu à ACCEPT.
2. Ajouter un `OrderStateMachine::canPromote($from, $to)` helper si pas existant, et l'utiliser ici.
3. Aligner avec la doc state machine.

## BUILD

1. Pré-test : `tests/Feature/Kiosk/FinalizePromotionGuardTest.php` :
   - Order CANCELED (16) → finalizePaidKioskOrder ne promote pas.
   - Order ACCEPT (4) → no-op.
   - Order PENDING → promote.
2. Modifier le check.

## Contraintes
- ✅ Pattern frozen `recordTransition` conservé.
- ❌ Pas de modification de `OrderStateMachine` (frozen).

## Decision
`continue`. Faible risque, faible gain — backlog.
