# EXECUTE V5 #2 — P11_INVARIANT_4_OF_6_HARDENING

TASK_ID: P11_INVARIANT_4_OF_6_HARDENING
WAVE: V5 salve K (durcissement check statique, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: sub-finding V4 #8 audit (cf. `reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md` §AUDIT)

---

## Goal

Renforcer l'invariant 4/6 de `scripts/check-invariants.sh` pour qu'il **détecte** les patterns short-name d'`App\Events\*::dispatch()` actuellement passés en faux négatif.

**Bug détecté V4 #8** : le pattern grep actuel `'App\\\\Events\\\\[A-Za-z]+::dispatch\('` ne matche QUE le FQN absolu (`\App\Events\OrderCreated::dispatch(...)`). Les services utilisent partout :
```php
use App\Events\OrderCreated;
// ...
OrderCreated::dispatch($order);  // ← non détecté par le grep actuel
```

---

## Scope

| Fichier | Action |
|---|---|
| `scripts/check-invariants.sh` | EDIT — bloc invariant 4/6 ligne ~109-115 |

**SUBSYSTEMS_TOUCHED**: 1 fichier script bash, 1 bloc.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif (`app/`, `routes/`, `tests/`, frozen zones). Aucune ligne PHP touchée.
**INVARIANTS_AT_RISK**: aucun (l'invariant 4/6 lui-même est durci, pas affaibli).

---

## Spécification

### Étape 1 — Identifier la liste des events broadcast à surveiller

Lire `app/Events/` pour identifier les events qui sont **broadcast** (cross-surface temps réel via Pusher/Soketi). Critère : implémente `ShouldBroadcast` OU est dispatch dans une closure broadcast OU est dans la liste connue : `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`, `ItemCreated`, `ItemUpdated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted`.

**Exclure** les `Send*` events (SendOrderSms, SendOrderMail, SendOrderPush, etc.) qui sont des notifications queue async, hors scope de l'invariant `dispatch-after-commit` broadcast.

### Étape 2 — Élargir le pattern grep

Stratégie : pattern alternation qui matche **soit** le FQN absolu **soit** le short-name d'un event broadcast connu.

```bash
# Ancien (faux négatifs)
'App\\\\Events\\\\[A-Za-z]+::dispatch\('

# Nouveau — capture FQN ET short-names des events broadcast
'(App\\\\Events\\\\(OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted)|^|[^A-Za-z\\\\_])(OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted)::dispatch\('
```

**ALTERNATIVE plus simple** (préférée si la regex ci-dessus est trop ER-fragile pour `grep -E`) :

```bash
# Liste des events broadcast (un par ligne) inline dans le script
BROADCAST_EVENTS_RE='(OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted)'

# Pattern : match short-name OR FQN, suivi de ::dispatch(
# Le `\b` (word-boundary) BSD-grep-compatible via [^A-Za-z_\\] négatif
PATTERN_4_6="${BROADCAST_EVENTS_RE}::dispatch\("
```

Le exclude reste : `'afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events'` (ajouter `use App\Events` pour ne pas matcher la ligne `use` elle-même).

### Étape 3 — Élargir le scope

Ajouter au scope du run_check 4/6 (en plus de `OrderService.php` et `FrontendOrderService.php`) :
- `app/Services/Menu/AvailabilityService.php` (utilise `ItemAvailabilityChanged`)
- `app/Services/ItemService.php` (utilise `Item*` events)
- `app/Services/ItemCategoryService.php` (utilise `Category*` events)
- `app/Http/Controllers/Admin/AvailabilityController.php` (déjà identifié dans le grep `afterCommit`)

### Étape 4 — Documenter le résultat attendu

Le check 4/6 va **probablement échouer** après ce durcissement (les bugs détectés par V4 #8 sont confirmés statiquement). C'est **VOULU** — c'est exactement la 2e sentinelle pour le bug `dispatch-after-commit`.

Ajouter dans le commentaire du bloc 4/6 :
```bash
# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* broadcast events.
#    Detects both FQN (\App\Events\X::dispatch) AND short-name (X::dispatch with `use`).
#    NOTE 2026-04-20: this check WILL fail until P11_DISPATCH_AFTER_COMMIT_REMEDIATION
#    (V5 #1) implements ShouldDispatchAfterCommit on event classes. Pre-existing
#    violations in OrderService.php / FrontendOrderService.php are tracked and
#    will resolve automatically once events implement the contract.
```

---

## VALIDATE

1. `bash scripts/check-invariants.sh -v 2>&1 | head -40` → check 4/6 marqué FAIL avec un nombre raisonnable de hits (3-10). Vérifier que les hits incluent au moins :
   - `app/Services/OrderService.php:541, 961, 1266` (OrderCreated::dispatch direct)
   - `app/Services/FrontendOrderService.php:842` (OrderCreated::dispatch short-name via `use`)
2. Les autres invariants (1/6, 2/6, 3/6, 5/6, 6/6) restent OK.
3. `git diff scripts/check-invariants.sh` → un seul fichier modifié, ~15-25 lignes (regex + scope).
4. Aucun fichier `app/`, `tests/`, `routes/` modifié.

---

## REPORT_FILE

`reports/execution/RUN_P11_INVARIANT_4_OF_6_HARDENING_2026-04-20.md` — diff inline + sortie verbose `check-invariants.sh -v` montrant les hits détectés.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier le moindre fichier `app/`, `routes/`, `tests/`, `database/`
- ❌ NE PAS modifier d'autres invariants (1/6, 2/6, 3/6, 5/6, 6/6) — uniquement le 4/6
- ❌ NE PAS désactiver l'invariant 4/6 (rendre warning-only) — il DOIT rester failing pour bloquer les PR jusqu'à V5 #1
- ❌ NE PAS ajouter de `// allow:` dans les services pour faire passer le check (ce serait masquer le bug)
- ❌ Pas de `git add/commit`
- ⚠️ Les Send* events (SendOrderSms, etc.) restent EXCLUS — ce sont des notifications queue, pas du broadcast.
