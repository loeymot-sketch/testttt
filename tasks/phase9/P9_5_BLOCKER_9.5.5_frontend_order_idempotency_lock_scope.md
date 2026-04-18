# P9.5 BLOCKER — 9.5.5 cross-branch idempotency still blocked by cache lock scope

## Date

2026-04-18

## Item bloqué

- `9.5.5` — E2E kiosk full flow (assertion idempotency same key, different branch => new order)

## Ce qui échoue

Le test E2E du flow complet révèle que :

- la contrainte DB `(branch_id, idempotency_key)` est bien en place (`9.5.4`) ;
- mais la création de commande kiosk échoue encore en cross-branch avec la **même** `idempotency_key` avant insertion de la seconde commande.

Evidence observée pendant le test :

- première branche : création OK ;
- replay même branche : retourne la commande existante OK ;
- seconde branche, même clé : réponse `422` vide ;
- le nombre de commandes avec cette `idempotency_key` reste `1`.

## Cause probable

Dans `app/Services/FrontendOrderService.php`, le verrou d'idempotence reste global à la clé seule :

- `Cache::lock('frontend_order_idempotency_' . sha1($idempotencyKey), 10);`

Donc deux branches différentes partageant la même `idempotency_key` se bloquent encore au niveau cache, même si la DB autorise désormais `(branch_id, idempotency_key)`.

Autrement dit :

- **DB scope = correct**
- **cache lock scope = encore global**

## Pourquoi je m'arrête

Corriger ce point exige de modifier `FrontendOrderService.php` sur la logique d'idempotence, alors que l'autorisation P9.5 pour ce fichier était explicitement limitée à `9.5.1` et à une modification **additive uniquement** pour `allergens_snapshot`.

La correction nécessaire serait par exemple :

1. résoudre `branch_id` serveur **avant** le lock, puis
2. scoper le lock à `(branch_id, idempotency_key)` au lieu de `idempotency_key` seul.

Cela touche une zone frozen / sensible hors autorisation active actuelle.

## Ce qu'il faut pour débloquer

Validation humaine / extension de scope explicite pour :

- `app/Services/FrontendOrderService.php`

sur la seule logique suivante :

- rendre le lock d'idempotence kiosk **branch-scoped**
- sans toucher pricing SSOT, state machine, ou autres comportements du pipeline

## Impact sur P9.5

- `9.5.5` ne peut pas être finalisé proprement tant que ce verrou cache n'est pas aligné sur la même portée que l'index DB.
- Par conséquence, je n'avance pas vers le commit `9.5.5`, le commit tracker final, ni la batterie de validation globale.

## Résolution — 2026-04-18

**Scope extension validée.** Le PLAN P9.5 `SUBSYSTEMS_TOUCHED` autorise désormais une seconde modification additive de `app/Services/FrontendOrderService.php` strictement limitée à :

- résoudre `branch_id` serveur-side (`auth()->user()->branch_id` ou équivalent déjà utilisé ailleurs dans le service) **avant** la pose du lock,
- scoper `Cache::lock('frontend_order_idempotency_' . sha1($branchId . '|' . $idempotencyKey), ...)` au lieu de la clé seule,
- **aucune autre modification** (pricing, state machine, transitions, observers, events — tous intouchés).

`LOCK_A_P9_5_FrontendOrderService_2026-04-18.md` passe en `RE-OPENED` pour ce diff puis `RELEASED` après le commit `test(kiosk/phase-9.5.5)` (qui inclura aussi le fix idempotency lock scope).

Un test dédié dans `KioskFullFlowE2ETest` (cross-branch replay) suffit à prouver l'alignement runtime / DB.

**Status** : RESOLVED (scope étendu, reprise autorisée).
