# EXECUTE V7 #1 — P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY

TASK_ID: P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY
WAVE: V7 salve Q (sentinelle statique enrichie, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V5 #2 (P11_INVARIANT_4_OF_6_HARDENING) — TODO list: étendre aux events Item/Category après vérification `ShouldBroadcast`

---

## Goal

Vérifier si les events `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` (existants dans `app/Events/`) implémentent `ShouldBroadcast`. Si oui, les ajouter à la liste surveillée par `scripts/check-invariants.sh` invariant 4/6 ET à la liste documentée dans le KI-001.

**Note importante** : `ItemUpdated.php` n'existe PAS dans le repo. La liste de V5 #2 est trop large — corriger.

---

## Scope

| Fichier | Action |
|---|---|
| `scripts/check-invariants.sh` | EDIT — bloc invariant 4/6, ajustement liste events + scope si nécessaire |
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | EDIT — ajouter section "Events broadcast monitorés mais hors bug actuel" |

**SUBSYSTEMS_TOUCHED**: 1 script bash + 1 doc markdown.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif (`app/`, `routes/`, `tests/`, frozen zones).
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Étape 1 — Audit des classes Event

Lire en entier les 5 fichiers :
- `app/Events/ItemCreated.php`
- `app/Events/ItemDeleted.php`
- `app/Events/CategoryCreated.php`
- `app/Events/CategoryUpdated.php`
- `app/Events/CategoryDeleted.php`

Pour chacun, déterminer :
1. Implémente `ShouldBroadcast` ou `ShouldBroadcastNow` ? (broadcast Pusher cross-surface)
2. Si NON → c'est un event "outbox/queue async" → **HORS scope** check 4/6 (qui cible le broadcast, pas le queue notification)
3. Si OUI → DANS le scope check 4/6 → vérifier aussi s'il implémente déjà `ShouldDispatchAfterCommit`

Tableau récap à inclure dans le RUN report :

| Event | Path | `ShouldBroadcast` ? | `ShouldDispatchAfterCommit` ? | Action check 4/6 |
|---|---|---|---|---|
| ItemCreated | app/Events/ItemCreated.php | ? | ? | ADD / SKIP |
| ItemDeleted | ... | ? | ? | ... |
| ... | ... | ... | ... | ... |

### Étape 2 — MAJ pattern grep si events broadcast trouvés

Si **AU MOINS 1** event sur les 5 implémente `ShouldBroadcast` ET PAS `ShouldDispatchAfterCommit` → l'ajouter à la liste de la regex dans `scripts/check-invariants.sh` ligne ~110-115.

Modèle attendu (reprendre le bloc actuel et ajouter les events confirmés) :
```bash
BROADCAST_EVENTS_RE='(OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted)'
```

(Adapter selon les résultats Étape 1 — ne PAS ajouter un event qui n'a pas `ShouldBroadcast`).

### Étape 3 — Étendre scope si nécessaire

Si un event ajouté est dispatched depuis un fichier non-couvert actuellement (ex : `app/Services/ItemService.php`, `app/Services/ItemCategoryService.php`, `app/Http/Controllers/Admin/ItemController.php`), ajouter ces fichiers au scope du `run_check 4/6` (déjà élargi en V5 #2).

Pour confirmer où chaque event est dispatched, faire un `grep -rn "ItemCreated::dispatch\|ItemDeleted::dispatch\|..." app/` court.

### Étape 4 — MAJ KI-001

Ajouter dans `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` une nouvelle section APRÈS la table "Affected events" :

```markdown
## Other broadcast events under static surveillance (V7 #1)

The following events are **also monitored** by `check-invariants.sh` invariant 4/6 because they implement `ShouldBroadcast`. They may or may not exhibit the same bug — pending V5 #1 remediation will fix them all uniformly.

| Event | Path | Status |
|---|---|---|
| ... (filled by audit V7 #1) ... |

If any of these is dispatched without `afterCommit` AND has `ShouldBroadcast`, V5 #1 should also add `implements ShouldDispatchAfterCommit` to it.
```

### Étape 5 — Re-run check-invariants

`bash scripts/check-invariants.sh -v 2>&1 | grep -A 30 "4/6 App"` → capturer la nouvelle sortie.

Comparer aux 8 hits de V5 #2 :
- Si nouveaux hits trouvés (events Item/Category aussi dispatched sans afterCommit) → **EXTENDED_BUG_FOUND**, MAJ KI-001 pour mentionner ces events comme aussi affectés
- Si pas de nouveau hit → les events Item/Category sont peut-être tous OK, ou pas dispatched dans le scope. C'est une bonne nouvelle.

---

## VALIDATE

1. `bash scripts/check-invariants.sh -v 2>&1 | head -40` → check 4/6 (peut être plus ou moins de hits selon découverte ; tous les hits initiaux V5 #2 doivent rester présents)
2. Les autres invariants restent OK
3. KI-001 enrichi avec la table V7 #1
4. Aucun fichier `app/`, `tests/`, `routes/` modifié
5. `git diff scripts/check-invariants.sh` → ajustement BROADCAST_EVENTS_RE + éventuels scope files

---

## REPORT_FILE

`reports/execution/RUN_P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY_2026-04-20.md` — tableau récap par event + diff script + nouvelle sortie verbose check + diff KI-001.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier les fichiers `app/Events/*.php` (le check observe, ne corrige pas)
- ❌ NE PAS modifier les services/controllers même si on découvre un nouveau hit (laisser à V5 #1 étendu)
- ❌ NE PAS ajouter à la regex un event qui N'implémente PAS `ShouldBroadcast` (ce serait du noise)
- ❌ NE PAS ajouter `ItemUpdated` à la regex — la classe n'existe pas
- ❌ Pas de `git add/commit`
- ⚠️ Si TOUS les 5 events n'implémentent pas `ShouldBroadcast`, le cycle se termine en **CLOSED — NO_OP** (aucun event à ajouter), MAJ le KI-001 pour mentionner cette analyse négative explicitement
