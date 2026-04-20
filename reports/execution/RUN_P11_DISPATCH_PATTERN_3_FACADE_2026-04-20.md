# RUN — P11_DISPATCH_PATTERN_3_FACADE (V11 #1)

**Date** : 2026-04-20  
**Plan** : `tasks/execute-2026-04-20/V11_01_P11_DISPATCH_PATTERN_3_FACADE.md`  
**Statut** : **SUCCESS (Cas A NO_OP)**

---

## Cas final

**Cas A** — `grep` #1 = **0 hit** → documentation dans `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` ; `scripts/check-invariants.sh` **non modifié**.

---

## Étape 1 — Sortie complète des 3 greps

Les commandes ont été exécutées depuis la racine du dépôt (`cwd` = racine projet).

### Gre #1 — façade `Event::dispatch(` sans `new`

```bash
grep -rn "Event::dispatch(" app/ --include='*.php' | grep -v "::dispatch(new "
```

**Sortie** : *(vide)*  
**Code de sortie** : `1` (aucune ligne correspondante)

---

### Gre #2 — `dispatch_now` / `dispatch_sync` / `Bus::dispatchNow`

```bash
grep -rn "dispatch_now\|dispatch_sync\|Bus::dispatchNow" app/ --include='*.php'
```

**Sortie** : *(vide)*  
**Code de sortie** : `1` (aucune ligne correspondante)

**Note (auto-remediation / hors scope)** : aucun hit. Rien à signaler pour des dispatches Bus/dispatcher vers des events broadcast ; pas d’extension du check V11 #1.

---

### Gre #3 — `events` / `app('events')` / `$dispatcher->dispatch`

```bash
grep -rn "events.*->dispatch\|app('events')\->dispatch\|\$dispatcher->dispatch" app/ --include='*.php' | head -20
```

**Sortie** : *(vide)*  
**Code de sortie** : `1` (aucune ligne correspondante)

**Note** : comme pour le gre #2 — hors scope pour extension du check ; ici aussi 0 hit.

---

## Décision Cas A vs B

| Gre #1 hits | Décision |
|-------------|----------|
| 0 | **Cas A** — NO_OP doc uniquement |

---

## Diff — `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md`

Fichier **non suivi** par git (`??`) au moment du RUN ; le diff ciblé ci-dessous correspond à la **section insérée après la section « V9 #1 »**, avant « Confirmed call-sites », conformément au plan.

```diff
+## V11 #1 — façade pattern `Event::dispatch(string|class-string)` audit
+
+**Audit** : 2026-04-20. Exhaustive grep across `app/` for the façade pattern `Event::dispatch(...)` (event passed by name/class-string, without `new`):
+
+```bash
+grep -rn "Event::dispatch(" app/ --include='*.php' | grep -v "::dispatch(new "
+```
+
+**Result** : **0 matches**. The façade pattern is not used in this codebase. The 3 patterns currently surveilled by `scripts/check-invariants.sh` invariant 4/6 (FQN static, short-name static, helper `event(new ...)`/`Event::dispatch(new ...)`) cover the entire dispatch surface for broadcast events.
+
+**Verdict** : **CLOSED — NO_OP**. No change to `scripts/check-invariants.sh`.
+
+**Lesson** : V7 #1 audited "are events dispatched at all" but missed the helper pattern. V11 #1 audits "is there a 4th pattern we don't surveil" — answered : no. The 3-pattern coverage is complete for this codebase as of 2026-04-20. Re-audit recommended after any major upgrade (Laravel version bump, EventBus refactor).
```

---

## Diff — `scripts/check-invariants.sh`

**N/A (Cas A)** — aucune modification.

---

## Justification

- L’audit profond (3 greps) confirme **0** occurrence du pattern façade `Event::dispatch(...)` sans `new` sous `app/`.
- Aligné avec le plan : **Cas A**, texte **Étape 3A** intégré tel quel en fin de KI-001.
- Invariants 1/2/3/5/6 et code applicatif inchangés ; pas de `git add/commit`.

---

**Path RUN report** : `reports/execution/RUN_P11_DISPATCH_PATTERN_3_FACADE_2026-04-20.md`
