# EXECUTE V9 #1 — P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK

TASK_ID: P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK
WAVE: V9 salve Z1 (4e durcissement check 4/6, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V8 #1 lessons learned — dette `// allow:` à éliminer

---

## Contexte / pourquoi ce cycle

V8 #1 a étendu la regex 4/6 mais a dû ajouter **5 commentaires `// allow: wrapped DB::afterCommit (V8 #1)`** sur les sites Item/Category car le wrap `DB::afterCommit(function () use ($id): void { ... event(new X($id)); })` est multi-ligne et grep mono-ligne ne le voit pas.

Cette dette d'allowlist a 3 défauts :
1. **Risque masquage** : si un dev retire le wrap `DB::afterCommit` mais oublie de retirer le `// allow:`, le bug passe inaperçu
2. **Bruit code** : 5 commentaires de gouvernance dans du code applicatif
3. **Pas extensible** : tout futur dispatch wrappé devra recevoir un `// allow:`

V9 #1 résout en **détectant le wrap multi-ligne via awk** : pour chaque hit grep, on inspecte les 5 lignes au-dessus dans le fichier source. Si on y trouve `DB::afterCommit(`, c'est un wrap → on ignore le hit. Sinon → vrai violation.

---

## Goal

Remplacer la stratégie `// allow:` par une détection awk multi-ligne intégrée dans `scripts/check-invariants.sh` invariant 4/6 :
- **Préserver** la baseline 8 hits (= V5 #2 + V8 #1, vrais bugs OrderService + FrontendOrderService)
- **Faire disparaître** les 5 `// allow:` ajoutés en V8 #1
- **Détecter** automatiquement les sites wrappés `DB::afterCommit(...)` (5 sites Item/Category aujourd'hui, N futurs)

---

## Scope

| Fichier | Action |
|---|---|
| `scripts/check-invariants.sh` | EDIT — bloc invariant 4/6 + nouvelle fonction `filter_aftercommit_wrapped` en awk |
| `app/Services/ItemService.php` | EDIT MICROSCOPIQUE — retirer les 2 commentaires `// allow: wrapped DB::afterCommit (V8 #1)` |
| `app/Services/ItemCategoryService.php` | EDIT MICROSCOPIQUE — retirer les 3 commentaires |
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | EDIT — ajouter section V9 #1 documentant le passage `// allow:` → awk |

**SUBSYSTEMS_TOUCHED**: 1 script + 2 services (suppressions de commentaires) + 1 doc.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le reste (frozen zones, OrderService, FrontendOrderService, autres invariants 1/2/3/5/6).
**INVARIANTS_AT_RISK**: aucun. Le check est durci, jamais affaibli. **Le compte de hits doit rester à 8 strictement.**

---

## Spécification

### Étape 1 — Lire le contexte

1. Lire `scripts/check-invariants.sh` lignes 109-129 (bloc invariant 4/6 actuel post V5+V8)
2. Lire `app/Services/ItemService.php` lignes 178-186 et 302-310 (sites avec `// allow:`)
3. Lire `app/Services/ItemCategoryService.php` lignes 115-122, 148-153, 183-189

### Étape 2 — Concevoir la fonction awk de filtrage

Ajouter une fonction bash après `run_check` (vers ligne 72) :

```bash
# filter_aftercommit_wrapped <hits>
#
# For each hit line "path:line:content", inspect the 5 lines preceding
# `line` in `path`. If any of those lines contains `DB::afterCommit(`,
# the hit is considered properly wrapped and is REMOVED from the output.
# Otherwise the hit is kept (genuine invariant violation).
#
# Used by invariant 4/6 to detect manual after-commit wrapping without
# needing per-site `// allow:` comments.
filter_aftercommit_wrapped() {
    local hits="$1"
    [[ -z "$hits" ]] && return 0
    local kept=""
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        local file="${line%%:*}"
        local rest="${line#*:}"
        local lineno="${rest%%:*}"
        # Guard: file must exist and lineno must be a positive int.
        if [[ ! -f "$file" ]] || ! [[ "$lineno" =~ ^[0-9]+$ ]] || (( lineno < 1 )); then
            kept+="${line}"$'\n'
            continue
        fi
        local start=$(( lineno - 5 ))
        (( start < 1 )) && start=1
        # Use awk to inspect the window [start, lineno-1].
        local has_wrap
        has_wrap=$(awk -v s="$start" -v e="$((lineno - 1))" \
            'NR>=s && NR<=e && /DB::afterCommit\(/ { found=1 } END { print (found ? "1" : "0") }' \
            "$file")
        if [[ "$has_wrap" != "1" ]]; then
            kept+="${line}"$'\n'
        fi
    done <<< "$hits"
    # Strip trailing newline.
    printf '%s' "${kept%$'\n'}"
}
```

### Étape 3 — Adapter le bloc 4/6

Modifier `run_check` pour qu'il accepte un **post-filter optionnel**, OU créer un appel manuel pour 4/6 uniquement :

**Option A (recommandée, moins invasive)** : ne pas toucher `run_check`, refaire le 4/6 manuellement :

```bash
# 4. Event broadcast dispatched without afterCommit — V9 #1 awk multi-line filter.
echo -n "  [4/6 App\\Events\\* dispatch afterCommit] ... "
BROADCAST_EVENTS_4_6='OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted'
PATTERN_4_6="(${BROADCAST_EVENTS_4_6})::dispatch\\(|(event\\(new |Event::dispatch\\(new )(${BROADCAST_EVENTS_4_6})\\b"
EXCLUDE_4_6='afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events|DB::afterCommit'
SCOPE_4_6=( app/Services/OrderService.php
            app/Services/FrontendOrderService.php
            app/Services/Menu/AvailabilityService.php
            app/Services/ItemService.php
            app/Services/ItemCategoryService.php
            app/Http/Controllers/Admin/AvailabilityController.php )

raw_hits_4_6=$(grep -rEn --include='*.php' -e "$PATTERN_4_6" "${SCOPE_4_6[@]}" 2>/dev/null || true)
raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -vE "$EXCLUDE_4_6" || true)
raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -v '^$' || true)
filtered_hits_4_6=$(filter_aftercommit_wrapped "$raw_hits_4_6")
count_4_6=0
[[ -n "$filtered_hits_4_6" ]] && count_4_6=$(echo "$filtered_hits_4_6" | wc -l | tr -d ' ')

if [[ $count_4_6 -eq 0 ]]; then
    echo "${GREEN}OK${NC}"
else
    echo "${RED}FAIL (${count_4_6} hit(s))${NC}"
    FAILED=$((FAILED + 1))
    TOTAL_HITS=$((TOTAL_HITS + count_4_6))
    if [[ $VERBOSE -eq 1 ]]; then
        echo "$filtered_hits_4_6" | head -20 | sed 's/^/      /'
    fi
fi
```

(Conserver l'ancien `run_check` pour 1/6, 2/6, 3/6, 5/6, 6/6 inchangé.)

### Étape 4 — Retirer les 5 commentaires `// allow:`

Sur :
- `app/Services/ItemService.php` lignes 182, 306
- `app/Services/ItemCategoryService.php` lignes 119, 151, 186

Retirer **uniquement le suffixe** `// allow: wrapped DB::afterCommit (V8 #1)` (laisser `event(new X($id));` intact, ne PAS toucher l'indentation, ne PAS toucher le wrap `DB::afterCommit` lui-même, ne PAS toucher les autres lignes).

### Étape 5 — Re-run et valider

```bash
bash scripts/check-invariants.sh -v 2>&1 | sed -n '/4\/6 App/,/^==>/p'
```

Doit afficher **8 hits** (= V5 #2 + V8 #1 baseline).
- Si **8 hits** = SUCCESS, awk filtre les 5 sites Item/Category malgré la suppression des `// allow:`
- Si **13 hits** = FAIL : awk ne filtre pas correctement, restaurer les `// allow:` et debug
- Si **<8 hits** ou **>13 hits** = REGRESSION : STOP, demander review orchestrateur

### Étape 6 — Vérifier les autres invariants intacts

```bash
bash scripts/check-invariants.sh
```

Doit afficher exactement la même structure 1/6 OK, 2/6 OK, 3/6 OK, 4/6 FAIL (8 hits), 5/6 OK, 6/6 OK.

### Étape 7 — MAJ KI-001

Ajouter une section :

```markdown
## V9 #1 — invariant 4/6 multi-line `DB::afterCommit` detection

**Audit** : 2026-04-20. The `scripts/check-invariants.sh` invariant 4/6 now uses an `awk`-based post-filter that inspects the 5 lines preceding each grep hit. If `DB::afterCommit(` is found in that window, the hit is considered properly wrapped and is removed.

**Consequence** : the 5 `// allow: wrapped DB::afterCommit (V8 #1)` comments added to `ItemService.php` / `ItemCategoryService.php` in V8 #1 have been removed. The detection is now structural rather than convention-based.

**Trade-offs** :
- ✅ No more allowlist drift risk
- ✅ Future dispatch sites wrapped in `DB::afterCommit` are auto-detected (no manual annotation needed)
- ✅ Removing the wrap correctly raises a new invariant 4/6 hit
- ⚠️ Window size = 5 lines. Wraps spanning >5 lines (multiline `use (...)` lists) would not be detected. Increase the window if needed in future cycles.
- ⚠️ The check is per-line, not per-AST: a `DB::afterCommit(` appearing in a comment 3 lines above would create a false negative. Acceptable trade-off given current codebase patterns.

**Baseline preserved** : 8 hits (3 × `OrderCreated::dispatch` + 3 × `OrderStatusChanged::dispatch` in `OrderService.php` + 1 × `OrderCreated::dispatch` + 1 × `OrderStatusChanged::dispatch` in `FrontendOrderService.php`). To be resolved by V5 #1 remediation (gate C9).
```

---

## VALIDATE

1. `bash scripts/check-invariants.sh -v 2>&1 | grep "4/6"` → exactement `FAIL (8 hit(s))`
2. `bash scripts/check-invariants.sh` exit code = 1, autres invariants OK
3. `grep -n "// allow: wrapped DB::afterCommit (V8 #1)" app/Services/ItemService.php app/Services/ItemCategoryService.php` → **aucun match**
4. `grep -c "DB::afterCommit" app/Services/ItemService.php app/Services/ItemCategoryService.php` → inchangé vs avant cycle (les wraps eux-mêmes ne bougent PAS)
5. Diff `scripts/check-invariants.sh` : ajout de `filter_aftercommit_wrapped` + remplacement du bloc 4/6 par version inline avec post-filter
6. KI-001 enrichi section V9 #1

---

## REPORT_FILE

`reports/execution/RUN_P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK_2026-04-20.md` — diff complet + sortie verbose 4/6 avant/après + comptage validation.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier les wraps `DB::afterCommit(...)` eux-mêmes
- ❌ NE PAS modifier la **logique** des services (uniquement suppression du commentaire en fin de ligne)
- ❌ NE PAS modifier `run_check` (pour ne pas impacter les autres invariants 1/2/3/5/6)
- ❌ NE PAS toucher les autres invariants 1/2/3/5/6
- ❌ NE PAS retirer le pattern V5 #2 ou V8 #1 (le post-filter S'AJOUTE)
- ❌ NE PAS modifier OrderService / FrontendOrderService
- ❌ Pas de `git add/commit`
- ⚠️ Si la nouvelle fonction `filter_aftercommit_wrapped` ne marche pas en BSD bash 3.2 (macOS), tester avec `/bin/bash --version` et adapter la syntaxe. Pas de fallback à zsh.
- ⚠️ Si après suppression des `// allow:` le compte est ≠ 8 → restaurer immédiatement les `// allow:` et investiguer pourquoi awk ne filtre pas
