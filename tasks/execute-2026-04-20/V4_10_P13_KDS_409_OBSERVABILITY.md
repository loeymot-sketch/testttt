# EXECUTE V4 #10 — P13_KDS_409_OBSERVABILITY

TASK_ID: P13_KDS_409_OBSERVABILITY
WAVE: V4 salve 4b (logs structurés, faible risque)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: PLAN_POST_VERIFY §1.4 cycles supp. + lien V4 #3 (P12_KDS_VUEX_REFRESH F-VERIFY-04-03)

---

## Goal

Ajouter un log structuré juste avant le `abort(409, ...)` du KDS pour permettre :
- compteur de 409/jour
- segmentation par `branch_id`, `order_id`, `current_status`, `attempted_status`, `user_id` (KDS staff)
- corrélation avec les events `OrderStatusChanged`

Cible unique : `app/Services/KitchenDisplaySystemOrderService.php:136` (vérifié par grep).

---

## Scope

| Fichier | Action |
|---|---|
| `app/Services/KitchenDisplaySystemOrderService.php` | EDIT — 1 ajout : `Log::channel('stack')->warning('[KDS_409]', [...])` immédiatement avant le `abort(409, ...)` ligne ~136. |

**SUBSYSTEMS_TOUCHED**: KDS service observability (1 ligne d'ajout, 0 modification de logique).
**SUBSYSTEMS_OFF_LIMITS**: OrderService (frozen), KDS UI Vue, schéma DB, autres services. **Aucune autre méthode de KitchenDisplaySystemOrderService ne doit être touchée.**
**INVARIANTS_AT_RISK**: aucun (log non bloquant — wrap `try/catch` autour de `Log`).

---

## Spécification

### Avant édition
1. Lire `app/Services/KitchenDisplaySystemOrderService.php` autour de la ligne 136 (au moins lignes 100-160) pour identifier :
   - Les variables locales disponibles dans le scope (probablement `$order`, `$payload`, `$user`).
   - Le contexte (méthode `changeStatus()` ou similaire).
2. Vérifier qu'il n'y a **qu'une seule** occurrence de `abort(409` dans le fichier (déjà confirmé par grep audit).

### Édition

Insérer **juste avant** l'unique `abort(409, '...');` :

```php
try {
    \Illuminate\Support\Facades\Log::channel('stack')->warning('[KDS_409]', [
        'op'                => 'kds.change_status',
        'order_id'          => $order->id ?? null,
        'branch_id'         => $order->branch_id ?? null,
        'current_status'    => $order->status ?? null,
        'attempted_status'  => $payload['status'] ?? ($request->input('status') ?? null),
        'user_id'           => auth()->id(),
        'reason'            => 'optimistic_lock_conflict',
    ]);
} catch (\Throwable $logEx) { /* never break the abort flow */ }
abort(409, 'Order status was updated elsewhere — please refresh the KDS.');
```

Adapter les noms de variables (`$payload` / `$request` / `$order`) **strictement** à ce qui existe dans le scope local — ne **pas** introduire de nouvelles variables non définies.

---

## VALIDATE

1. `bash scripts/check-invariants.sh` → reste OK 6/6 (en particulier 3/6 status via OrderStateMachine — on n'écrit pas de status, on logge avant un abort, donc neutre).
2. `git diff app/Services/KitchenDisplaySystemOrderService.php` → un seul hunk, ~10 lignes ajoutées avant l'`abort(409, ...)`. Aucune autre modification.
3. `grep -nc "abort(409" app/Services/KitchenDisplaySystemOrderService.php` → toujours `1` (on n'a pas dupliqué l'abort).
4. `vendor/bin/phpunit --filter KitchenDisplay --filter Kds` (si tests existent) → reste vert. Si pas de tests, OK.

---

## REPORT_FILE

`reports/execution/RUN_P13_KDS_409_OBSERVABILITY_2026-04-20.md` — diff inline + greps de validation.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier la logique de détection du conflit (le check qui mène à l'abort).
- ❌ NE PAS toucher d'autres méthodes du fichier.
- ❌ NE PAS toucher d'autres fichiers (KDS controller, KDS Vue, etc.).
- ❌ NE PAS ajouter d'instrumentation similaire ailleurs (autres services, autres 4xx) — scope limité au 409 KDS unique.
- ❌ Pas de `git add/commit`.
- ⚠️ `try/catch` autour de `Log::...` **OBLIGATOIRE** — un log défaillant ne doit jamais empêcher l'abort 409 d'être renvoyé au client.
