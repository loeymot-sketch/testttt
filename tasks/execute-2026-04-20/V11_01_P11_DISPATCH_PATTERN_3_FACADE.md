# EXECUTE V11 #1 — P11_DISPATCH_PATTERN_3_FACADE

TASK_ID: P11_DISPATCH_PATTERN_3_FACADE
WAVE: V11 salve Z2 (audit pattern façade `Event::dispatch(...)`, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V8 #1 lessons learned — vérifier le 4e pattern potentiel

---

## Contexte

Le check 4/6 actuel (post V5+V8+V9) couvre 3 patterns de dispatch :
- ✅ FQN statique : `\App\Events\OrderCreated::dispatch(...)` (V5 #2)
- ✅ Short-name statique : `OrderCreated::dispatch(...)` (V5 #2)
- ✅ Helper : `event(new OrderCreated(...))` ou `Event::dispatch(new OrderCreated(...))` (V8 #1)

**4e pattern potentiel** (jamais audité) : façade Laravel **`Event::dispatch('event.name', $payload)`** ou **`Event::dispatch(SomeEvent::class, $payload)`** où l'event est passé comme argument string/class-string sans `new`.

L'audit orchestrateur pré-V11 a fait `grep -rn "Event::dispatch(" app/` → **0 hits**. Ce cycle vérifie cette absence en profondeur (greps multiples) et soit confirme NO_OP (rien à ajouter), soit étend le check si trouvaille.

---

## Goal

1. Auditer en profondeur la présence/absence du pattern façade `Event::dispatch(...)` (sans `new`) dans `app/`
2. **Cas A — 0 hit** : documenter dans KI-001 + RUN report. CLOSED — NO_OP.
3. **Cas B — N hits trouvés** : étendre le check 4/6 dans `scripts/check-invariants.sh`. CLOSED — PASSED.

---

## Scope

| Fichier | Action (Cas A) | Action (Cas B) |
|---|---|---|
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | EDIT — section V11 #1 NO_OP | EDIT — section V11 #1 + nouveaux call-sites |
| `scripts/check-invariants.sh` | inchangé | EDIT — pattern façade ajouté |

**SUBSYSTEMS_TOUCHED**: 1 doc (Cas A) ou 1 script + 1 doc (Cas B).
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif (audit lecture seule en Cas A).
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Étape 1 — Audit exhaustif

Exécuter ces 3 greps et conserver les sorties dans le RUN report :

```bash
# 1. Façade explicite Event::dispatch (sans `new`, peut être string ou class::class)
grep -rn "Event::dispatch(" app/ --include='*.php' | grep -v "::dispatch(new "

# 2. Pattern alternatif: dispatch_now / dispatch_sync de jobs (hors scope events mais à noter)
grep -rn "dispatch_now\|dispatch_sync\|Bus::dispatchNow" app/ --include='*.php'

# 3. Pattern App::make('events')->dispatch (DI runtime)
grep -rn "events.*->dispatch\|app('events')\->dispatch\|\$dispatcher->dispatch" app/ --include='*.php' | head -20
```

### Étape 2 — Décision Cas A vs Cas B

**Si grep #1 = 0 hit** → Cas A. Aller Étape 3A.
**Si grep #1 = N hits** → Cas B. Aller Étape 3B.

### Étape 3A — Cas A : NO_OP

Ajouter dans `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` (à la fin, après la section V9 #1) :

```markdown
## V11 #1 — façade pattern `Event::dispatch(string|class-string)` audit

**Audit** : 2026-04-20. Exhaustive grep across `app/` for the façade pattern `Event::dispatch(...)` (event passed by name/class-string, without `new`):

```bash
grep -rn "Event::dispatch(" app/ --include='*.php' | grep -v "::dispatch(new "
```

**Result** : **0 matches**. The façade pattern is not used in this codebase. The 3 patterns currently surveilled by `scripts/check-invariants.sh` invariant 4/6 (FQN static, short-name static, helper `event(new ...)`/`Event::dispatch(new ...)`) cover the entire dispatch surface for broadcast events.

**Verdict** : **CLOSED — NO_OP**. No change to `scripts/check-invariants.sh`.

**Lesson** : V7 #1 audited "are events dispatched at all" but missed the helper pattern. V11 #1 audits "is there a 4th pattern we don't surveil" — answered : no. The 3-pattern coverage is complete for this codebase as of 2026-04-20. Re-audit recommended after any major upgrade (Laravel version bump, EventBus refactor).
```

### Étape 3B — Cas B : étendre le check

Si N hits trouvés, modifier `scripts/check-invariants.sh` bloc 4/6 pour ajouter une 3e variante au `PATTERN_4_6` :

```bash
PATTERN_4_6="(${BROADCAST_EVENTS_4_6})::dispatch\\(|(event\\(new |Event::dispatch\\(new )(${BROADCAST_EVENTS_4_6})\\b|Event::dispatch\\((${BROADCAST_EVENTS_4_6})::class"
```

Re-run `bash scripts/check-invariants.sh -v 2>&1 | grep "4/6"` pour confirmer hits cohérents.

Documenter dans KI-001 :

```markdown
## V11 #1 — façade pattern detected

**Audit** : 2026-04-20. Found N matches of `Event::dispatch(<BroadcastEvent>::class, ...)` pattern. Extended invariant 4/6 to cover.

**Sites** :
- ...

**Verdict** : **CLOSED — PASSED**. Check pattern extended.
```

### Étape 4 — Run report

Écrire `reports/execution/RUN_P11_DISPATCH_PATTERN_3_FACADE_2026-04-20.md` avec :
- Sortie complète des 3 greps Étape 1
- Cas final (A ou B)
- Diff KI-001
- Diff `scripts/check-invariants.sh` (si Cas B)
- Justification

---

## VALIDATE

**Cas A** :
1. Grep `Event::dispatch(` (sans `new`) dans `app/` → 0 hit
2. KI-001 enrichi section V11 #1 NO_OP
3. `scripts/check-invariants.sh` non modifié

**Cas B** :
1. Grep révèle ≥1 hit
2. KI-001 + script modifiés
3. Re-run check 4/6 montre les nouveaux hits ou OK selon contexte

---

## REPORT_FILE

`reports/execution/RUN_P11_DISPATCH_PATTERN_3_FACADE_2026-04-20.md`

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier le code applicatif (Cas A et Cas B)
- ❌ NE PAS modifier les autres invariants 1/2/3/5/6
- ❌ Pas de `git add/commit`
- ⚠️ Si grep #2 ou #3 révèle des dispatches Bus/dispatcher pour des events broadcast → noter dans le RUN report mais NE PAS étendre le check (out of scope V11 #1)
