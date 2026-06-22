# EXECUTE V8 #2 — P11_INVARIANT_DOC_REFRESH

TASK_ID: P11_INVARIANT_DOC_REFRESH
WAVE: V8 salve X (gouvernance, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V5 #2 + V7 #1 + V8 #1 — `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` §3 obsolète

---

## Goal

Mettre à jour `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` §3 pour refléter le hardening cumulatif :
- V5 #2 : pattern grep durci (FQN + short-name) + scope élargi
- V7 #1 : analyse Item/Category (NO_OP avec correction post-V7)
- V8 #1 : pattern `event(new ...)` ajouté

Aujourd'hui §3 contient un grep simpliste ligne 93 :
```bash
grep -rn "Event::dispatch\|::dispatch(" app/Services/OrderService.php app/Services/FrontendOrderService.php | grep -v "afterCommit\|shouldDispatchAfterCommit"
```
Il a au moins 3 défauts :
1. Scope limité à 2 fichiers (V5 #2 a élargi à 6 fichiers)
2. Ne capture pas le pattern `event(new ...)` (V8 #1 le corrige)
3. Manque la liste des events broadcast surveillés (capture tout `::dispatch(` sans distinction Order/Send)

La doc doit pointer vers `scripts/check-invariants.sh` comme source de vérité (single source of truth) plutôt que de dupliquer la logique.

---

## Scope

| Fichier | Action |
|---|---|
| `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` | EDIT — §3 (grep dispatch ligne 92-93) + référence vers script |

**SUBSYSTEMS_TOUCHED**: 1 fichier doc markdown.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif + scripts (ne pas modifier `check-invariants.sh` ici, c'est V8 #1).
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Étape 1 — Lire l'état actuel

Lire `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` lignes 80-103 (section "Grep de vérification à lancer avant chaque merge").

### Étape 2 — Modifier le bloc dispatch (ligne 92-93)

Remplacer :
```bash
# dispatch avant commit ?
grep -rn "Event::dispatch\|::dispatch(" app/Services/OrderService.php app/Services/FrontendOrderService.php | grep -v "afterCommit\|shouldDispatchAfterCommit"
```

Par :
```bash
# dispatch avant commit ? (SSOT: scripts/check-invariants.sh invariant 4/6)
# Couvre 3 patterns (FQN + short-name + event() helper) sur 6+ fichiers.
# Mis à jour V5 #2 (FQN + short-name), V8 #1 (event() helper).
bash scripts/check-invariants.sh -v 2>&1 | sed -n '/4\/6 App/,/^==>/p'
```

### Étape 3 — Ajouter une note au-dessus du bloc

Avant la ligne `## 3. Grep de vérification à lancer avant chaque merge`, ajouter :

```markdown
> **2026-04-20 — Migration progressive vers `scripts/check-invariants.sh`** : les invariants 1, 2, 3, 4, 5, 6 ci-dessous (et leur évolution) sont maintenus dans le script unique. Les `grep` listés dans cette §3 restent valides comme cheat-sheet rapide, mais en cas de divergence, **le script fait foi**. Mises à jour majeures : V5 #2 (durcissement 4/6), V7 #1 (analyse Item/Category), V8 #1 (pattern event() helper).
```

### Étape 4 — Mettre à jour la version en haut du fichier

Ligne 3 actuellement :
```markdown
**Version.** 2026-04-18
```

Remplacer par :
```markdown
**Version.** 2026-04-18 (rév. 2026-04-20 — V8 #2 alignement avec `scripts/check-invariants.sh` post V5 #2 + V8 #1)
```

---

## VALIDATE

1. `git diff tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` → 1 fichier modifié, ~10-15 lignes
2. Le bloc dispatch ligne 92-93 référence maintenant `scripts/check-invariants.sh`
3. Note ajoutée avant §3
4. Version en haut mise à jour
5. Aucun autre fichier modifié

---

## REPORT_FILE

`reports/execution/RUN_P11_INVARIANT_DOC_REFRESH_2026-04-20.md` — diff complet du fichier MD modifié.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier les autres invariants listés (pricing, branch_id, status, EventContract, audit log) — uniquement le bloc dispatch
- ❌ NE PAS modifier `scripts/check-invariants.sh` (c'est V8 #1)
- ❌ NE PAS modifier la structure §1, §2, §4 (uniquement §3)
- ❌ Pas de `git add/commit`
- ⚠️ Si la commande `sed -n '/4\/6 App/,/^==>/p'` ne marche pas en BSD sed (macOS), utiliser une variante GNU-compatible ou un grep -A 30
