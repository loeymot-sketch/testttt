# RUN — P13_KDS_409_OBSERVABILITY (2026-04-20)

**TASK_ID:** P13_KDS_409_OBSERVABILITY  
**Statut:** SUCCESS

## Contexte

- **Méthode:** `changeStatus(Order $order, OrderStatusRequest $request)`
- **Emplacement:** dans la closure `DB::transaction`, avant l’unique `abort(409, ...)`.
- **Variables réellement utilisées dans le log:** `$locked->id`, `$locked->branch_id`, `$locked->status`, `$newStatus`, `auth()->id()`  
  (équivalent plan : `order_id`, `branch_id`, `current_status`, `attempted_status`, `user_id` — pas de `$payload` ni `$request` dans la closure `use`.)

## Diff inline (`app/Services/KitchenDisplaySystemOrderService.php`)

```diff
@@ -133,6 +133,17 @@ class KitchenDisplaySystemOrderService
                 }
 
                 if ((int) $locked->status !== $expectedFrom) {
+                    try {
+                        Log::channel('stack')->warning('[KDS_409]', [
+                            'op'                => 'kds.change_status',
+                            'order_id'          => $locked->id ?? null,
+                            'branch_id'         => $locked->branch_id ?? null,
+                            'current_status'    => $locked->status ?? null,
+                            'attempted_status'  => $newStatus,
+                            'user_id'           => auth()->id(),
+                            'reason'            => 'optimistic_lock_conflict',
+                        ]);
+                    } catch (\Throwable $logEx) { /* never break the abort flow */ }
                     abort(409, 'Order status was updated elsewhere — please refresh the KDS.');
                 }
```

## Validation

### `grep -nc "abort(409" app/Services/KitchenDisplaySystemOrderService.php`

```
1
```

### `bash scripts/check-invariants.sh`

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... OK
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> All 6 POS invariants clean.
```

### PHPUnit

- `vendor/bin/phpunit --filter KitchenDisplay` → OK (1 test)
- `vendor/bin/phpunit --filter Kds` → OK (23 tests)

## Fichiers touchés

- `app/Services/KitchenDisplaySystemOrderService.php` (seul fichier modifié)

## Risque résiduel

- Aucun sur la logique 409 ; le log est non bloquant via `try/catch`.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Diff stat | 1 fichier, 1 hunk, **11 lignes ajoutées** (conforme au plan) |
| 2 | `grep -nc "abort(409"` | toujours **1** (pas de duplication) |
| 3 | check-invariants.sh | 6/6 OK |
| 4 | Tests KDS | 23 tests OK + 1 KitchenDisplay OK |
| 5 | Variables réelles | `$locked->id`, `$locked->branch_id`, `$locked->status`, `$newStatus`, `auth()->id()` — toutes en scope, vérifié via lecture du `changeStatus()` |
| 6 | try/catch autour Log | présent — l'abort 409 ne peut pas être bloqué par un log défaillant |
| 7 | Aucune autre méthode touchée | confirmé via diff (1 hunk unique) |

**Valeur produite** : observability runtime des conflits optimistic-lock KDS. Permet de mesurer :
- Volume 409/jour, par branche (anomalie si > seuil)
- Patterns d'attempted_status (un staff qui essaye toujours `READY` depuis `PREPARING` = mauvaise vue UI ?)
- Corrélation user_id ↔ pic 409 (formation ou bug front à investiguer)

Combiné avec V4 #3 (`P12_KDS_VUEX_REFRESH`), la boucle est désormais : (1) UI rafraîchie après 409, (2) 409 mesuré côté serveur. Cohérence cross-cycle parfaite.
