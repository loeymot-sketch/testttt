# LOCK_B — `routes/api.php` — POS-9.2.10 + POS-9.3.10

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] Aucun `LOCK_A_*` actif sur `routes/api.php`. P9.5 a édité ce fichier (auto-merged) mais n'y a pas posé de lock.
- [x] Aucun autre `LOCK_B_*` actif.

## Fichier et lignes prévues

**Fichier.** `routes/api.php`.

**Ajouts planifiés (additifs uniquement)** :

| Vague | Lignes cibles | Scope |
|---|---|---|
| POS-9.2.10 | **~625-638** zone admin orders | Ajout `POST /admin/orders/{id}/cancel`, `accept`, `preparing`, `ready`, `delivered` — 5 routes via `OrderStateMachine::apply` + middleware permission `pos` |
| POS-9.3.10 | zone admin pos-order | Ajout `POST /admin/pos-order/{order}/split-payment` |

## Règles de respect invariants pendant ce lock

- **Additif uniquement** : aucune suppression ou renommage de route existante.
- **Middleware auth+tenant** : nouvelles routes respectent `auth:api` + middleware de scope branch_id.
- **Rate-limit** : `throttle:60,1` ou adapté selon la criticité ; split-payment reçoit `throttle:10,1` (aligné sur policy fiscale Phase H).

## ETA libération

**Release par.** Commit POS-9.3.10 (dernier ajout de route).
**Durée estimée.** ~4 jours ouvrés (2 ajouts sur 2 vagues distantes).
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit 9.3.10.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.
