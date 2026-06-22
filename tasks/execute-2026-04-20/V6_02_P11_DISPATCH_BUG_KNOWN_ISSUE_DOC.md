# EXECUTE V6 #2 — P11_DISPATCH_BUG_KNOWN_ISSUE_DOC

TASK_ID: P11_DISPATCH_BUG_KNOWN_ISSUE_DOC
WAVE: V6 salve O (gouvernance / onboarding, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: bug confirmé V4 #8 + élargi V5 #2 + V5 #3

---

## Goal

Créer un document `KNOWN_ISSUE` formel sous `docs/known-issues/` pour le bug `dispatch-after-commit` (3 events broadcast confirmés affectés). Sert :
1. **Onboarding** — un nouveau dev/agent comprend immédiatement le contexte sans relire 4 cycles d'historique.
2. **Production** — l'équipe ops sait quoi surveiller (orders fantômes en KDS/OSS) et comment workaround si urgence.
3. **Gouvernance** — référence stable pour le gate humain V5 #1 (étendu).
4. **Testing** — explique pourquoi `DispatchAfterCommitTest` est rouge en CI et **ne doit PAS être muté/skipped** sans approbation.

---

## Scope

| Fichier | Action |
|---|---|
| `docs/known-issues/.gitkeep` | CREATE — initialiser le dossier (n'existe pas encore) |
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | CREATE — documentation complète du bug |

**SUBSYSTEMS_TOUCHED**: documentation uniquement.
**SUBSYSTEMS_OFF_LIMITS**: code applicatif.
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification du document

### Structure obligatoire

```markdown
# KI-001 — `dispatch-after-commit` invariant broken on broadcast events

**Status** : OPEN — awaiting human gate C9 (extended) for V5 #1 remediation
**Severity** : HIGH (production data inconsistency, no data loss)
**Discovered** : 2026-04-20 by V4 #8 sentinel test (`P11_DISPATCH_AFTER_COMMIT_AUDIT`)
**Extended** : 2026-04-20 by V5 #2 (statique grep) + V5 #3 (runtime data provider)
**Tracking** : `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`

---

## TL;DR

Three broadcast events (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`) are dispatched **immediately** during a database transaction, instead of **after the transaction commits**. If the transaction subsequently rolls back, the broadcast already left the application — KDS/OSS/Kiosk surfaces show "ghost" orders/status changes that don't exist in the database.

## Affected events

| Event class | File | Implements `ShouldDispatchAfterCommit` ? |
|---|---|---|
| `App\Events\OrderCreated` | `app/Events/OrderCreated.php` | ❌ NO |
| `App\Events\OrderStatusChanged` | `app/Events/OrderStatusChanged.php` | ❌ NO |
| `App\Events\ItemAvailabilityChanged` | `app/Events/ItemAvailabilityChanged.php` | ❌ NO |

**Possibly affected (untested)** : `Item/CategoryCreated/Updated/Deleted` events. To be verified in V5 #1.

## Confirmed call-sites (from `scripts/check-invariants.sh -v` invariant 4/6 after V5 #2 hardening)

| File | Line | Pattern |
|---|---|---|
| `app/Services/OrderService.php` | 541 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 961 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 1266 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 1423 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/OrderService.php` | 1478 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/OrderService.php` | 1575 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/FrontendOrderService.php` | 842 | `OrderCreated::dispatch(...)` |
| `app/Services/FrontendOrderService.php` | 848 | `OrderCreated::dispatch(...)` |

(Adapter à la sortie réelle au moment de l'écriture du doc — vérifier ligne par ligne.)

## Production impact

- **KDS** (Kitchen Display System) : a "ghost" order may appear and disappear in the kitchen UI when an order creation transaction rolls back. Cooks may start prep on a non-existent order.
- **OSS** (Order Status Screen / customer display) : a status update may flash to "ready" then revert, confusing customers.
- **Kiosk** : if a kiosk receives `ItemAvailabilityChanged` for a rolled-back availability toggle, the cart may incorrectly prune lines.
- **Severity proportional to**: % of order/availability transactions that roll back. In normal ops < 0.5%, but spikes during DB issues, fiscal Z conflicts, optimistic lock conflicts.

**No data loss** — the database remains consistent. Only the real-time UI is temporarily inconsistent.

## Active sentinels

| Sentinel | File | Type | Current state |
|---|---|---|---|
| Runtime | `tests/Feature/DispatchAfterCommitTest.php` | PHPUnit data provider × 3 events | 3 ✔ commit + 3 ✘ rollback (rouge en CI volontaire) |
| Static | `scripts/check-invariants.sh` invariant 4/6 | grep | FAIL (8 hits, exit 1) — NOT in CI workflows, local only |

**Both sentinels MUST stay active and red until V5 #1 remediation lands.** Do not :
- Add `@group dispatch_after_commit_invariant` exclusion to CI without orchestrator approval
- Mark tests as `@incomplete` or `@skip`
- Add `// allow:` comments to the call-sites above to silence the static check
- Disable invariant 4/6 in `check-invariants.sh`

## Remediation plan (V5 #1 — awaiting human gate C9 extended)

See `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`.

**Recommended strategy A** : add `implements ShouldDispatchAfterCommit` to the 3 event classes (1-line change each).

```php
// app/Events/OrderCreated.php (and 2 others)
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OrderCreated implements ShouldDispatchAfterCommit
{
    // ... existing code unchanged ...
}
```

**Why human gate** : touches frozen-zone-adjacent event classes. Although the change is minimal (1 line + 1 use statement per file), the contract change affects EVERY existing dispatch call-site simultaneously. Risk of side effects in queue workers, listeners ordering, and the broadcast envelope contract.

**Why strategy A is preferred over strategy B** (refactor every caller to `dispatchAfterCommit()`) :
- A : 3 files × 2 lines each = 6 lines total. Fixes ALL existing AND future call-sites.
- B : 8+ call-sites × 1 line each = 8+ lines. Easy to forget a future caller.

## Workarounds (none recommended for production)

There is **no clean dev workaround**. The only mitigation is to wrap every dispatch in a manual `DB::afterCommit(fn() => Event::dispatch(...))`, which is exactly what `ShouldDispatchAfterCommit` does automatically.

If a hotfix is needed before V5 #1 lands, the surgical patch is to add `implements ShouldDispatchAfterCommit` to the most critical event (`OrderCreated`) only. This still requires the same human gate.

## Detection in production

Logs to monitor (added by V4 #9 `P13_FISCAL_TIMING_METRICS` and V4 #10 `P13_KDS_409_OBSERVABILITY`) :
- `[FISCAL_TIMING]` with `outcome=failure` followed by no compensating broadcast
- `[KDS_409]` correlated with order_id that has only a transient lifetime in the DB

**Recommended Grafana/SIEM alert** : count of `[KDS_409]` events with `current_status` mismatching expected initial state — proxy for ghost orders.

## Closure criteria

- [ ] V5 #1 remediation merged
- [ ] `vendor/bin/phpunit --filter DispatchAfterCommitTest` → 6/6 ✔
- [ ] `bash scripts/check-invariants.sh` → 4/6 OK (0 hits)
- [ ] This KI updated to status `RESOLVED` with merge SHA + date
- [ ] 7 days of production logs without ghost-order patterns
```

---

## VALIDATE

1. Fichier `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` créé, ~150 lignes, structure ci-dessus respectée
2. Fichier `docs/known-issues/.gitkeep` créé (vide, pour tracker le dossier)
3. **Vérification croisée** : les call-sites listés dans la table doivent correspondre à la sortie réelle de `bash scripts/check-invariants.sh -v` du moment. Si divergence, ré-extraire les vrais call-sites.
4. **Vérification croisée** : ouvrir `app/Events/OrderCreated.php`, `OrderStatusChanged.php`, `ItemAvailabilityChanged.php` pour confirmer qu'AUCUN n'implémente `ShouldDispatchAfterCommit` (sinon ajuster la table)
5. Aucun fichier `app/`, `tests/`, `routes/`, `resources/` modifié

---

## REPORT_FILE

`reports/execution/RUN_P11_DISPATCH_BUG_KNOWN_ISSUE_DOC_2026-04-20.md` — chemin fichier créé + extrait des sections clés + confirmation que les vérifications croisées (4) ont été faites avec leurs résultats (call-sites réels, état réel des classes Event).

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier les classes Event (le doc constate le bug, ne le corrige pas — c'est le rôle de V5 #1)
- ❌ NE PAS modifier les services
- ❌ NE PAS modifier les tests (V4 #8 + V5 #3 sont déjà en place)
- ❌ NE PAS modifier `scripts/check-invariants.sh` (déjà durci en V5 #2)
- ❌ Pas de `git add/commit`
- ⚠️ Si une vérification croisée révèle une divergence (par ex. un call-site supplémentaire, ou un Event qui implémente déjà le contrat), MAJ le doc pour refléter la réalité — pas de copier-coller aveugle des données du plan
