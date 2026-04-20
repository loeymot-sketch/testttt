# RUN — P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY (V7 #1)

**Date** : 2026-04-20  
**Plan** : `tasks/execute-2026-04-20/V7_01_P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY.md`  
**Executor** : foodking-routine-implementer (Composer)  
**Final status** : **CLOSED — NO_OP**

---

## Executive summary

All five in-scope `app/Events/*` classes (`ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted`) were read in full. None implement `ShouldBroadcast` or `ShouldBroadcastNow`; each uses only `Dispatchable` and has no custom parent class. Per the plan, they must **not** be added to the invariant 4/6 broadcast regex. `scripts/check-invariants.sh` was **not** modified. `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` gained section **Other broadcast events under static surveillance (V7 #1)** with the audit table, verdict, and dispatch grep result.

Static re-run: invariant **4/6** still reports **8 hits**, identical to the V5 #2 baseline (only `OrderCreated` / `OrderStatusChanged` call-sites). **No** `EXTENDED_BUG_FOUND` on Item/Category (no matches for `*::dispatch` grep in `app/` for those five).

---

## Audit table (Étape 1)

| Event | Path | Parent / traits | `ShouldBroadcast` or `ShouldBroadcastNow` ? | `ShouldDispatchAfterCommit` ? | Decision |
|---|---|---|---|---|---|
| `ItemCreated` | `app/Events/ItemCreated.php` | Plain class; `Dispatchable` only | No | No | **SKIP** — not broadcast |
| `ItemDeleted` | `app/Events/ItemDeleted.php` | Plain class; `Dispatchable` only | No | No | **SKIP** |
| `CategoryCreated` | `app/Events/CategoryCreated.php` | Plain class; `Dispatchable` only | No | No | **SKIP** |
| `CategoryUpdated` | `app/Events/CategoryUpdated.php` | Plain class; `Dispatchable` only | No | No | **SKIP** |
| `CategoryDeleted` | `app/Events/CategoryDeleted.php` | Plain class; `Dispatchable` only | No | No | **SKIP** |

**Note** : `ItemUpdated` was not audited as a file — **the class does not exist** in the repo (plan interdit).

No abstract parent or shared broadcast base was involved; each of the five is an independent plain event class.

---

## Étape 2 — `scripts/check-invariants.sh`

**Decision** : **No change** (NO_OP). Adding these names to the broadcast surveillance regex would violate the plan rule: do not add events that do not implement `ShouldBroadcast`.

**Diff** : *none* (file untouched).

*Observation (informational only)* : the current 4/6 pattern in the tree already lists `ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted` from earlier work (V5 #2). V7 #1 does not expand that list further and does not remove historical pattern entries per NO_OP scope.

---

## Étape 3 — Dispatch grep scope

Condition “new events added to regex” was **false** → no new files added to `run_check` 4/6.

Grep executed for traceability:

```text
Command: grep -rn "ItemCreated::dispatch|ItemDeleted::dispatch|CategoryCreated::dispatch|CategoryUpdated::dispatch|CategoryDeleted::dispatch" app/
Result: no matches
```

---

## Étape 4 — KI-001

**File** : `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md`

**Added** : section `## Other broadcast events under static surveillance (V7 #1)` immediately after the **Affected events** table, including:

- Audit table (5 rows)
- Verdict **CLOSED — NO_OP**
- Note that `ItemUpdated.php` does not exist
- Dispatch grep result (no matches under `app/`)

---

## Étape 5 — `bash scripts/check-invariants.sh -v` (4/6 excerpt)

Captured with: `bash scripts/check-invariants.sh -v 2>&1 | grep -A 30 "4/6 App"`

```text
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
```

**Comparison to V5 #2** : Still **8 hits**; same files/lines; **no new** Item/Category-related hits → not **EXTENDED_BUG_FOUND**.

---

## Validator / planner follow-up

- If product later makes Item/Category lifecycle events broadcast, re-run this audit and align 4/6 + KI with `ShouldBroadcast` + `ShouldDispatchAfterCommit`.
- Optional future cleanup (outside V7 #1 NO_OP): tighten 4/6 regex to drop non-broadcast names and the non-existent `ItemUpdated` token if orchestrator approves a small script-only hygiene task.

---

## Files touched

| File | Action |
|---|---|
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | Added V7 #1 section; removed stale “possibly affected” line (superseded by audit) |
| `reports/execution/RUN_P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY_2026-04-20.md` | Created (this report) |
| `scripts/check-invariants.sh` | **Unchanged** |

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — NO_OP — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | `git diff scripts/check-invariants.sh` | aucune modif V7 #1 (le diff +13/-5 visible est V5 #2 pré-existant) |
| 2 | Vérif indépendante `grep -l ShouldBroadcast app/Events/{Item,Category}*.php` | 0 fichier — confirme indépendamment l'audit subagent |
| 3 | Vérif indépendante `grep -rn "ItemCreated::dispatch..." app/` | 0 hits — confirme événements orphelins (jamais dispatched) |
| 4 | KI-001 nouvelle section V7 #1 | présente lignes 23-37, structure conforme + verdict NO_OP + mention `ItemUpdated` n'existe pas |
| 5 | Aucun fichier app/, services, controllers modifié | confirmé via `git status` |

**Découverte significative** : les 5 events Item*/Category* sont **orphelins** — ni `implements ShouldBroadcast`, ni dispatched depuis le code applicatif. Ce sont des classes "fantômes" probablement héritées d'un cycle de développement antérieur ou prévues pour une feature jamais finalisée.

**Recommandation orchestrateur** : ajouter à `PLAN_POST_VERIFY` un cycle Composer optionnel `P11_DEAD_EVENTS_CLEANUP` pour évaluer la suppression de ces classes orphelines (réduction de la surface d'attaque + clarification du modèle d'events).

**Valeur produite** :
- Hypothèse "Item/Category broadcast" infirmée → pas de scope creep V5 #1
- KI-001 enrichi avec analyse négative explicite (évite ré-investigation future)
- Confirmation que la sentinelle V5 #2 reste correctement scopée (8 hits OrderCreated + OrderStatusChanged uniquement)
