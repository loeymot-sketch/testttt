# LOCK_A — `PricingService` (cross-item guard systématique POS/Table/Web)

**Date.** 2026-04-18
**Track.** A (Kiosk)
**Vague.** P9.5
**Item.** 9.5.6

## Fichiers verrouillés

- `app/Services/PricingService.php` (uniquement si une extension du CrossItemGuard est strictement nécessaire — sinon noop, voir "Raison" ci-dessous)
- `app/Http/Requests/PricingRequest.php` (root ou `forPos`/`forTable`/`forWeb` selon arborescence)
- `app/Http/Requests/PosPricingRequest.php` (si existe séparément)
- `app/Http/Requests/TablePricingRequest.php`
- `app/Http/Requests/WebPricingRequest.php`

## Lignes prévues

- Instancier / appeler le CrossItemGuard existant (aujourd'hui activé côté kiosk) depuis chaque variante `forPos/forTable/forWeb` de la `PricingRequest`.
- Zéro modification du cœur SSOT de `PricingService` : on passe uniquement par le point d'entrée guard déjà en place.
- Si l'audit révèle que le guard n'est réellement branché qu'au niveau kiosk et que le service a besoin d'une extension pour recevoir le flag `surface`, escalade via `tasks/phase9/P9_5_BLOCKER_PricingService_core.md` AVANT de toucher `PricingService.php`.

## Raison

- Gate invariant SSOT pricing : `grep -n 'forPos\|forTable\|forWeb' app/Http/Requests/*` doit montrer chaque variante instanciant `CrossItemGuard` (ou l'équivalent) après P9.5.6.
- Le handoff `HANDOFF_P9_5_2026-04-18.md` §4.4 précise que 9.5.6 ne doit **pas** toucher `PricingService`, uniquement les requests. Ce lock confirme cette contrainte et conserve `PricingService` en frozen même pendant P9.5 sauf escalation.

## Coordination Track B

- `PricingService.php` est listé en zone partagée (SYNC_PROTOCOL §2). Track B n'a pas de PR active touchant `PricingService` (POS-9.4 a livré fiscal/audit sans toucher au service).
- Aucun LOCK_B_* actif sur `PricingService` au moment de la pose (CROSS_TRACK_STATUS §"Locks shared actifs" vide au 2026-04-18).

## Tests obligatoires

- `tests/Feature/Orders/CrossItemGuardTest::test_pos_also_enforces`
- Assertions `grep` dans le verifier : chaque `forPos/forTable/forWeb` instancie le guard.

## ETA libération

- Lock levé après le commit `feat(kiosk/phase-9.5.6)` et confirmation verte de `CrossItemGuardTest`.

## Status

- **RELEASED (`this commit`)** le 2026-04-18.
- `PricingRequest::forPos/forTable/forWeb` vérifiés/alignés sans modification du cœur `PricingService`.
