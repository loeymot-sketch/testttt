# LOCK_B — `app/Http/EventContract.php` + `resources/js/services/eventContract.js` — POS-9.3.6

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] Aucun `LOCK_A_*` actif sur `EventContract` ou `eventContract.js`.
- [x] Aucun autre `LOCK_B_*` actif.

## Fichiers et scope

**Fichiers (pair shared, locked ensemble pour garantir la sync)** :
- `app/Http/EventContract.php` — enum/classe qui énumère les event types canoniques + contrainte `BROADCAST_MAP`.
- `resources/js/services/eventContract.js` — miroir front : `validateEnvelope` + `BROADCAST_MAP` front.

**Ajouts planifiés** :

| Vague | Scope |
|---|---|
| POS-9.3.6 | Ajouter 3 events canoniques à `BROADCAST_MAP` back + front : `OrderCancelled`, `PaymentRecorded`, `OrderRefunded`. Chacun avec son channel broadcast (e.g. `branch.{branch_id}`) et son payload shape documenté. |

## Règles de respect invariants pendant ce lock

- **Envelope V1 stricte** : `{version, type, aggregate_id, aggregate_type, branch_id, correlation_id, occurred_at, payload}`. Les 3 nouveaux events respectent ce contrat.
- **Miroir back↔front** : toute addition `BROADCAST_MAP` backend a un pendant front dans le même commit. CI future (post-9.3.6) peut vérifier par grep croisé.
- **Pas de breaking change** : les types existants restent intacts.

## ETA libération

**Release par.** Commit POS-9.3.6.
**Durée estimée.** ~1 heure.
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit 9.3.6.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.
