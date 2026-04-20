# EXECUTE V8 #1 — P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN

TASK_ID: P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN
WAVE: V8 salve Y (3e durcissement check 4/6, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: pre-V8 audit orchestrateur (correction V7 #1) — 3e angle mort détecté

---

## Contexte / pourquoi ce cycle

V7 #1 a conclu (à tort) que les events Item/Category sont orphelins. L'audit orchestrateur pré-V8 a révélé qu'ils sont **vivants** mais dispatched via le pattern **`event(new XxxCreated($id))`**, pas `XxxCreated::dispatch($id)` ni `\App\Events\XxxCreated::dispatch($id)`.

Le check `scripts/check-invariants.sh` invariant 4/6 (durci V5 #2) capture les 2 premiers patterns mais **PAS le 3e** :
- ✅ `\App\Events\OrderCreated::dispatch(...)` (FQN, V5 #2)
- ✅ `OrderCreated::dispatch(...)` (short-name avec `use`, V5 #2)
- ❌ `event(new OrderCreated(...))` (helper Laravel, **angle mort**)

Ce 3e pattern existe au moins à 5 sites confirmés (`ItemService.php:182,306` + `ItemCategoryService.php:119,151,186`). Tous sont déjà wrappés `DB::afterCommit` (vérifié par `VERIFY_14_SYNC_CROSS_SURFACE` et orchestrateur), mais la sentinelle ne le sait pas — risque de régression silencieuse si quelqu'un retire un wrap.

---

## Goal

Étendre l'invariant 4/6 du `scripts/check-invariants.sh` pour capturer aussi le pattern `event(new <BroadcastEvent>(...))`. La regex doit :
- Détecter le pattern `event(new <ClassName>` ou `Event::dispatch(new <ClassName>`
- ClassName parmi la liste des events broadcast surveillés (BROADCAST_EVENTS_RE déjà définie en V5 #2)
- Continuer d'exclure : `afterCommit`, `shouldDispatchAfterCommit`, `// allow:`, `use App\\Events`

Les 5 sites Item/Category sont déjà wrappés — donc soit :
- (A) ils sont **dans** un `DB::afterCommit(function() { ... })` block et l'exclude doit le détecter
- (B) ou bien on doit les ajouter à la liste excluded explicitement par `// allow: pre-existing-afterCommit-wrapped`

L'option (A) est risquée car détecter un wrap multi-ligne avec grep est fragile. L'option (B) est pragmatique mais demande de toucher du code applicatif.

**Décision pragmatique** : retenir option (C) hybride :
- Étendre la regex pour matcher `event(new ` ET `Event::dispatch(new `
- Ajouter à l'exclude le pattern `DB::afterCommit` (couvre les cas où la ligne précédente ou suivante mentionne `afterCommit`)
- Si après extension la sortie 4/6 montre les 5 sites Item/Category comme nouveaux hits → ils sont effectivement dans un `DB::afterCommit` mais grep mono-ligne ne le voit pas → utiliser l'option (B) pour ces sites uniquement (commentaire `// allow: wrapped DB::afterCommit (V8 #1)`).

---

## Scope

| Fichier | Action |
|---|---|
| `scripts/check-invariants.sh` | EDIT — bloc invariant 4/6, regex + exclude étendus |
| (conditionnel) `app/Services/ItemService.php` | EDIT MICROSCOPIQUE — ajout `// allow:` SI besoin ET seulement après confirmation que c'est wrapped DB::afterCommit |
| (conditionnel) `app/Services/ItemCategoryService.php` | EDIT MICROSCOPIQUE — idem |

**SUBSYSTEMS_TOUCHED**: 1 script bash + (conditionnel) 2 services pour commentaires `// allow:` uniquement.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le reste (frozen zones, OrderService, FrontendOrderService — déjà sentinelles V5 #2 — ne pas re-toucher).
**INVARIANTS_AT_RISK**: aucun (le check est durci, jamais affaibli).

---

## Spécification

### Étape 1 — Lire le contexte exact des 5 sites pour confirmer wrap `DB::afterCommit`

Pour chacun des 5 sites, lire ±5 lignes de contexte :
- `app/Services/ItemService.php` lignes 175-190 (autour de 182)
- `app/Services/ItemService.php` lignes 300-315 (autour de 306)
- `app/Services/ItemCategoryService.php` lignes 113-125 (autour de 119)
- `app/Services/ItemCategoryService.php` lignes 145-160 (autour de 151)
- `app/Services/ItemCategoryService.php` lignes 180-195 (autour de 186)

Pour chacun, déterminer la **forme exacte** du wrap (s'il existe) :
- `DB::afterCommit(fn() => event(new ...))` (ligne unique)
- `DB::afterCommit(function() { event(new ...); })` (multi-ligne)
- ou autre (si pas de wrap → c'est un BUG_FOUND_INVARIANT_BROKEN)

Cette information dicte la stratégie d'exclude.

### Étape 2 — Étendre le pattern grep

Modifier le bloc 4/6 du `scripts/check-invariants.sh` pour ajouter une 2e variante de pattern :

```bash
# Pattern A (V5 #2, conservé) : XxxCreated::dispatch(...)
PATTERN_4_6_DISPATCH="(${BROADCAST_EVENTS_RE})::dispatch\("

# Pattern B (V8 #1, nouveau) : event(new XxxCreated(...)) ou Event::dispatch(new XxxCreated(...))
PATTERN_4_6_HELPER="(event\(new |Event::dispatch\(new )(${BROADCAST_EVENTS_RE})\b"

# Combiner via une union grep
PATTERN_4_6="(${PATTERN_4_6_DISPATCH})|(${PATTERN_4_6_HELPER})"
```

Si la regex devient ingérable pour BSD-grep, faire **2 `run_check` consécutifs** (4a et 4b) plutôt qu'une seule union.

### Étape 3 — Étendre l'exclude

Ajouter `DB::afterCommit` à la liste des exclusions :

```bash
EXCLUDE_4_6='afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events|DB::afterCommit'
```

Note : `afterCommit` seul couvre déjà `DB::afterCommit` car grep est sub-string-match. **Probablement la regex actuelle V5 #2 fait déjà le job pour les sites mono-ligne `DB::afterCommit(fn() => event(new ...))`**. À tester en pratique.

### Étape 4 — Re-run et adapter

`bash scripts/check-invariants.sh -v 2>&1 | grep -A 30 "4/6 App"` → analyser :

- **Cas A** : 8 hits (= V5 #2) → les 5 sites Item/Category sont déjà exclus correctement par `DB::afterCommit` ou `afterCommit` mono-ligne. **Aucune modif des services nécessaire**, juste le diff scripts est suffisant. **CLOSED — PASSED**.
- **Cas B** : 13 hits (= V5 #2 + 5 sites Item/Category) → les 5 sites sont en multi-ligne (block) ou pas grep-attrapables. Ajouter `// allow: wrapped DB::afterCommit (V8 #1)` à la fin de chaque ligne dispatch sur les 5 sites. **CLOSED — PASSED** après confirmation re-run = 8 hits.
- **Cas C** : >13 hits → nouveaux hits sur OrderService/FrontendOrderService ? Improbable mais à investiger.
- **Cas D** : <8 hits → régression de V5 #2 (un hit existant masqué) → STATUS REGRESSION, ne pas conclure, demander review orchestrateur.

### Étape 5 — MAJ KI-001

Ajouter une nouvelle section dans `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` :

```markdown
## V8 #1 — invariant 4/6 extended to event() helper pattern

**Audit** : 2026-04-20. The `scripts/check-invariants.sh` invariant 4/6 now also catches `event(new <BroadcastEvent>(...))` and `Event::dispatch(new <BroadcastEvent>(...))` patterns, in addition to the static `XxxCreated::dispatch(...)` patterns covered by V5 #2.

**Confirmed-wrapped sites** (allowed via `// allow: wrapped DB::afterCommit (V8 #1)` if needed) :
- `app/Services/ItemService.php:182,306`
- `app/Services/ItemCategoryService.php:119,151,186`

These sites are correctly inside `DB::afterCommit(...)` per `VERIFY_14_SYNC_CROSS_SURFACE` audit. The check now actively monitors that the wrap is preserved.
```

---

## VALIDATE

1. `bash scripts/check-invariants.sh -v 2>&1 | grep -A 30 "4/6 App"` → entre 8 hits (cas A) et 8 hits après allowlist (cas B). Jamais < 8.
2. Les autres invariants restent OK
3. KI-001 enrichi de la section V8 #1
4. Si modifications services → uniquement ajout de commentaire `// allow:` en fin de ligne, JAMAIS de logique modifiée

---

## REPORT_FILE

`reports/execution/RUN_P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN_2026-04-20.md` — diff script + diff KI-001 + (si applicable) diff services + sortie verbose check + analyse Cas A/B/C/D rencontré.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier les classes Event
- ❌ NE PAS modifier OrderService.php / FrontendOrderService.php (déjà couverts V5 #2, hors scope V8 #1)
- ❌ NE PAS modifier la logique des services Item/ItemCategory — uniquement ajouter `// allow:` en fin de ligne SI besoin ET après confirmation qu'ils sont déjà wrappés
- ❌ NE PAS désactiver le check 4/6
- ❌ NE PAS retirer le pattern V5 #2 (le nouveau s'AJOUTE)
- ❌ Pas de `git add/commit`
- ⚠️ Si la regex ne tient pas en BSD-grep → split en 2 `run_check` (4a et 4b), pas de fallback à un seul pattern
