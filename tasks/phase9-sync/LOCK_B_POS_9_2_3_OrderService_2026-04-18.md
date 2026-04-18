# LOCK_B — `app/Services/OrderService.php` — POS-9.4.BL + POS-9.2 + POS-9.3

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.
**Base branche.** `main` @ `209103cef` (merge P9.5) @ `3914ae059` (merge Phase H).

## Pré-conditions vérifiées

- [x] `LOCK_A_P9_5_OrderService_2026-04-18.md` → **RELEASED** (mentionné dans status, P9.5 mergé sur main).
- [x] Aucun autre `LOCK_A_*` ni `LOCK_B_*` actif sur ce fichier.
- [x] 6 greps témoins P9.5 green + invariants 6/6 clean.

## Fichier et lignes prévues

**Fichier.** `app/Services/OrderService.php`.

**Méthodes touchées à travers les 3 vagues** :

| Vague | Méthode | Lignes actuelles | Scope |
|---|---|---|---|
| POS-9.4.BL.1 | `posOrderStore` | **546-929** | wire `FiscalSequenceService::next()` + `hydrateAllergenSnapshots()` |
| POS-9.4.BL.2 | `changeStatus`, `destroy`, `changePaymentStatus` | **1377-1546, 1607-1662** | wire `AuditLogService::write()` |
| POS-9.4.BL.3 | `destroy` | **1607-1662** | 409 Conflict si order scellé par Z clos |
| POS-9.2.2 | `changeStatus(auth=false)` | **1440-1497** | refacto via `OrderStateMachine::apply` |
| POS-9.2.3 | `changeStatus(auth=true)` | **1377-1439** | refacto via `OrderStateMachine::apply` |
| POS-9.2.5 | `changePaymentStatus`, `selectDeliveryBoy` | **1502-1546, 1554-1580** | wrap `DB::transaction` |
| POS-9.2.6 | tous dispatches | various | forcer `DB::afterCommit()` explicite |
| POS-9.2.7 | `posOrderStore` | **586-595, 898-908** | émettre `OrderStatusChanged(PENDING→ACCEPT)` via state machine |
| POS-9.2.8 | sites queue_number | **467, 794, 1143** | remplacer fallback timestamp par `QueueNumberLockTimeoutException` |
| POS-9.3.4 | `changePaymentStatus` | **1502-1546** | via `OrderPaymentStateMachine::apply` |
| POS-9.3.7 | cancel path dans `changeStatus` | **1405-1477** | dispatch `OrderCancelled` (remplace `OrderStatusChanged` générique) |

## Règles de respect invariants pendant ce lock

- **Frozen zone dérogation exceptionnelle** : ce fichier est frozen côté "nouvelle logique métier". Toutes les modifications doivent être (a) wire-in d'un service existant, (b) refacto vers l'unique state machine, (c) propagation d'event via afterCommit, (d) guard de sécurité. **Aucune nouvelle règle métier** (pricing, stock, branch, permissions hors déjà existantes).
- **Branch_id serveur** : aucune lecture de `$request->input('branch_id')`. Toujours `$user->branch_id` ou équivalent serveur.
- **SSOT pricing** : aucun calcul de prix. `PricingService` déjà appelé, on ne touche pas.
- **Atomicité** : toute transaction DB entoure `FiscalSequenceService::next()` + `Order::save()` dans la même transaction.
- **AuditLog dans la transaction** : `AuditLogService::write()` appelé **dans** la transaction DB (HMAC chain a son propre `Cache::lock` séparé).

## ETA libération

**Release par.** Commit final POS-9.3.7 (dispatch `OrderCancelled` sur cancel) ou équivalent dernier commit qui édite ce fichier.
**Durée estimée.** ~4 jours ouvrés (BL 1j + 9.2 1.5j + 9.3 1.5j).
**Procédure de release.** Mettre à jour `## Status` ci-dessous en `RELEASED` avec SHA du dernier commit.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.
