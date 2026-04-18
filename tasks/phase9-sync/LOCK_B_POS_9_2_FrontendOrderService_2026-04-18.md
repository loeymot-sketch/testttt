# LOCK_B — `app/Services/FrontendOrderService.php` — POS-9.2.4

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] `LOCK_A_P9_5_FrontendOrderService_2026-04-18.md` → **RELEASED** (release marquée sur commits `e5be3763f` + `1f145bdbe`).
- [x] Aucun autre `LOCK_A_*` ni `LOCK_B_*` actif sur ce fichier.

## Fichier et lignes prévues

**Fichier.** `app/Services/FrontendOrderService.php`.

**Modifications planifiées** :

| Vague | Lignes | Scope |
|---|---|---|
| POS-9.2.4 | **~L550** | refacto écriture status vers `OrderStateMachine::apply` |
| POS-9.2.4 | **~L661** | idem |
| POS-9.2.4 | **~L736** | idem |

Les lignes exactes peuvent dériver de quelques unités par rapport au plan (P9.5 a ajouté `hydrateAllergenSnapshots` et `$lockBranchId` qui ont shifté les offsets). Avant édition : refresh via `grep -n "->status = " app/Services/FrontendOrderService.php`.

## Règles de respect invariants pendant ce lock

- **Zone shared sensitive** (kiosk path) : modifications additives uniquement, comportement kiosk préservé à l'identique.
- **State machine unique** : `OrderStateMachine::apply()` est la seule écriture de status autorisée après ce refacto.
- **Pas de changement de signature publique** : les méthodes restent appelables par les routes kiosk existantes.

## ETA libération

**Release par.** Commit POS-9.2.4 (seul commit qui édite ce fichier).
**Durée estimée.** ~1 heure.
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit 9.2.4.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.
