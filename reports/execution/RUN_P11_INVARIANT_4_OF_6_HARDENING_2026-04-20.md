# RUN — P11_INVARIANT_4_OF_6_HARDENING (2026-04-20)

**Statut : SUCCESS** (durcissement du script appliqué ; l’invariant **4/6 est volontairement FAIL** jusqu’à V5 #1.)

**TASK_ID:** P11_INVARIANT_4_OF_6_HARDENING  
**Fichiers modifiés :** `scripts/check-invariants.sh` uniquement (aucun fichier sous `app/`, `routes/`, `tests/`, `database/`).

---

## Events broadcast retenus pour le grep (liste inline, 9 noms)

Aligné sur le plan V5 #2 — domain events menu / commande surveillés par `(… )::dispatch(` :

| # | Event |
|---|--------|
| 1 | `OrderCreated` |
| 2 | `OrderStatusChanged` |
| 3 | `ItemAvailabilityChanged` |
| 4 | `ItemCreated` |
| 5 | `ItemUpdated` *(nom dans la liste du plan ; pas de `ItemUpdated.php` dans `app/Events/` actuellement)* |
| 6 | `ItemDeleted` |
| 7 | `CategoryCreated` |
| 8 | `CategoryUpdated` |
| 9 | `CategoryDeleted` |

**Exclus volontairement :** `Send*` (`SendOrderSms`, `SendOrderMail`, `SendOrderPush`, etc.) — notifications file d’attente, hors périmètre broadcast de cette sentinelle.

**Note codebase :** plusieurs classes ci-dessus sont des events `Dispatchable` « plain » avec commentaires outbox ; le critère opérationnel ici est la **liste du plan** + exclusion `Send*`, pas une preuve `ShouldBroadcast` ligne par ligne dans chaque fichier.

---

## Diff inline — bloc invariant 4/6 (`scripts/check-invariants.sh`)

```diff
-# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* only
-#    (jobs are already queued/async; this rule targets broadcast events).
+# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* broadcast events.
+#    Detects both FQN (\App\Events\X::dispatch) AND short-name (X::dispatch with `use`).
+#    NOTE 2026-04-20: this check WILL fail until P11_DISPATCH_AFTER_COMMIT_REMEDIATION
+#    (V5 #1) implements ShouldDispatchAfterCommit on event classes. Pre-existing
+#    violations in OrderService.php / FrontendOrderService.php are tracked and
+#    will resolve automatically once events implement the contract.
 run_check "4/6 App\\Events\\* dispatch afterCommit" \
-    'App\\\\Events\\\\[A-Za-z]+::dispatch\(' \
-    'afterCommit|shouldDispatchAfterCommit|// allow:' \
+    '(OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted)::dispatch\(' \
+    'afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events' \
     app/Services/OrderService.php \
-    app/Services/FrontendOrderService.php
+    app/Services/FrontendOrderService.php \
+    app/Services/Menu/AvailabilityService.php \
+    app/Services/ItemService.php \
+    app/Services/ItemCategoryService.php \
+    app/Http/Controllers/Admin/AvailabilityController.php
```

---

## Sortie complète : `bash scripts/check-invariants.sh -v`

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... FAIL (8 hit(s))
      app/Services/OrderService.php:541:                \App\Events\OrderCreated::dispatch($this->order);
      app/Services/OrderService.php:961:                    \App\Events\OrderCreated::dispatch($order);
      app/Services/OrderService.php:1266:                \App\Events\OrderCreated::dispatch($this->order);
      app/Services/OrderService.php:1423:                \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
      app/Services/OrderService.php:1478:                        \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);
      app/Services/OrderService.php:1575:                    \App\Events\OrderStatusChanged::dispatch($order, $oldStatusForBroadcast, (int) $request->status);
      app/Services/FrontendOrderService.php:842:        OrderCreated::dispatch($frontendOrder);
      app/Services/FrontendOrderService.php:848:            OrderStatusChanged::dispatch($frontendOrder, $oldStatus, $newStatus);
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> 1 invariant(s) violated (8 total hit(s)).
    Run with -v to see offending lines.
    Reference: tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md §3
```

---

## Hits 4/6 — `fichier:ligne` (8 hits)

| # | Hit |
|---|-----|
| 1 | `app/Services/OrderService.php:541` |
| 2 | `app/Services/OrderService.php:961` |
| 3 | `app/Services/OrderService.php:1266` |
| 4 | `app/Services/OrderService.php:1423` |
| 5 | `app/Services/OrderService.php:1478` |
| 6 | `app/Services/OrderService.php:1575` |
| 7 | `app/Services/FrontendOrderService.php:842` |
| 8 | `app/Services/FrontendOrderService.php:848` |

---

## Cohérence — attendus (plan) vs détectés

| Attendu (plan) | Détecté |
|----------------|---------|
| `OrderService.php:541` | Oui |
| `OrderService.php:961` | Oui |
| `OrderService.php:1266` | Oui |
| `FrontendOrderService.php:842` | Oui |
| — | En plus (cohérent avec le nouveau pattern short-name / FQN) : `OrderService.php` lignes 1423, 1478, 1575 (`OrderStatusChanged::dispatch`), `FrontendOrderService.php:848` (`OrderStatusChanged::dispatch`) |

Aucun hit dans les fichiers élargis (`AvailabilityService`, `ItemService`, `ItemCategoryService`, `AvailabilityController`) sur l’instantané actuel — soit pas de `::dispatch(` non filtré, soit déjà couvert par `afterCommit` / exclusions.

---

## Autres invariants

| Invariant | Statut |
|-----------|--------|
| 1/6 | OK |
| 2/6 | OK |
| 3/6 | OK |
| 4/6 | **FAIL** (8 hits, dans la plage 3–15 attendue) |
| 5/6 | OK |
| 6/6 | OK |

---

## Risque résiduel / suivi validateur

- **BSD grep :** exécution validée sur l’environnement du run ; si un autre OS impose un retry, envisager le fallback « deux patterns » décrit dans le plan (non nécessaire ici).
- **CI :** `check-invariants.sh` échouera tant que V5 #1 n’aura pas traité les violations — comportement voulu par le plan.

**Path du rapport :** `reports/execution/RUN_P11_INVARIANT_4_OF_6_HARDENING_2026-04-20.md`

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Diff stat | `scripts/check-invariants.sh` +13/-5 (1 fichier, conforme à ~15-25 lignes attendues) |
| 2 | Re-run `bash scripts/check-invariants.sh` | 4/6 FAIL (8 hits), 5 autres invariants OK — exit 1 attendu et voulu |
| 3 | Aucun fichier `app/`, `tests/`, `routes/` modifié | confirmé via `git status` |
| 4 | Hits attendus présents | OrderService.php:541,961,1266 + FrontendOrderService.php:842 — TOUS présents |
| 5 | Hits BONUS révélés | OrderService.php:1423,1478,1575 + FrontendOrderService.php:848 — **4 violations supplémentaires sur OrderStatusChanged détectées qu'on ignorait** |

**Convergence avec V5 #3 (test runtime)** : statique 8 violations sur OrderCreated + OrderStatusChanged ↔ runtime 3/3 events broadcast échouent rollback. **Cohérence parfaite des 2 sentinelles.**

**Valeur produite** :
- Faux négatif du grep résolu (pattern court-nom + scope élargi)
- Visibilité statique des 8 call-sites à corriger en V5 #1
- Sentinelle 2/2 active (statique + runtime) — toute future régression future ou rollback de la remédiation V5 #1 sera doublement détectée

**Impact CI** : `check-invariants.sh` n'est pas dans les workflows GitHub Actions (`vitest.yml`, `phpunit.yml`, `playwright.yml`) — le check rouge n'impacte donc pas la CI. Il sert de pre-commit hook local optionnel et de doc d'invariants.
