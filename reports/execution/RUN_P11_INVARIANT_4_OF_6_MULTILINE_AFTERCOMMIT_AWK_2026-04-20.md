# RUN — P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK (V9 #1)

**Date** : 2026-04-20  
**Statut** : **SUCCESS**  
**Compte 4/6 final** : **8 hits** (baseline préservée)

---

## 1. Snippet wrap réel (ItemService ~182)

Structure confirmée : `DB::afterCommit(` apparaît dans les 5 lignes au-dessus de `event(new ItemCreated(...))`.

```php
                $createdItemId = (int) $this->item->id;
                DB::afterCommit(function () use ($createdItemId): void {
                    event(new ItemCreated($createdItemId));
                });
```

---

## 2. Diff complet `scripts/check-invariants.sh`

Résumé fonctionnel :

- Après `run_check` : fonction `filter_aftercommit_wrapped` (fenêtre 5 lignes au-dessus, recherche `DB::afterCommit(`).
- Bloc 4/6 : `grep` + `EXCLUDE_4_6`, puis `filtered_hits_4_6=$(filter_aftercommit_wrapped "$raw_hits_4_6")` avant comptage / verbose.
- `run_check` **non modifié** pour 1/6, 2/6, 3/6, 5/6, 6/6.

Diff `git` (workspace vs `HEAD` au moment du run) :

```diff
diff --git a/scripts/check-invariants.sh b/scripts/check-invariants.sh
index 010f2a1bd..ca65ebfda 100755
--- a/scripts/check-invariants.sh
+++ b/scripts/check-invariants.sh
@@ -70,6 +70,44 @@ run_check() {
     fi
 }
 
+# filter_aftercommit_wrapped <hits>
+#
+# For each hit line "path:line:content", inspect the 5 lines preceding
+# `line` in `path`. If any of those lines contains `DB::afterCommit(`,
+# the hit is considered properly wrapped and is REMOVED from the output.
+# Otherwise the hit is kept (genuine invariant violation).
+#
+# Used by invariant 4/6 to detect manual after-commit wrapping without
+# needing per-site `// allow:` comments.
+filter_aftercommit_wrapped() {
+    local hits="$1"
+    [[ -z "$hits" ]] && return 0
+    local kept=""
+    while IFS= read -r line; do
+        [[ -z "$line" ]] && continue
+        local file="${line%%:*}"
+        local rest="${line#*:}"
+        local lineno="${rest%%:*}"
+        # Guard: file must exist and lineno must be a positive int.
+        if [[ ! -f "$file" ]] || ! [[ "$lineno" =~ ^[0-9]+$ ]] || (( lineno < 1 )); then
+            kept+="${line}"$'\n'
+            continue
+        fi
+        local start=$(( lineno - 5 ))
+        (( start < 1 )) && start=1
+        # Use awk to inspect the window [start, lineno-1].
+        local has_wrap
+        has_wrap=$(awk -v s="$start" -v e="$((lineno - 1))" \
+            'NR>=s && NR<=e && /DB::afterCommit\(/ { found=1 } END { print (found ? "1" : "0") }' \
+            "$file")
+        if [[ "$has_wrap" != "1" ]]; then
+            kept+="${line}"$'\n'
+        fi
+    done <<< "$hits"
+    # Strip trailing newline.
+    printf '%s' "${kept%$'\n'}"
+}
+
 echo "== POS invariants CI guard (${YELLOW}POS_INVARIANTS_AND_GATES.md §3${NC}) =="
 
 # 1. SSOT pricing — price/total/subtotal must never come from payload in POS layer.
@@ -106,13 +144,44 @@ run_check "3/6 status via OrderStateMachine" \
     app/Http/Controllers/Admin/PosController.php \
     app/Http/Controllers/Admin/PosOrderController.php
 
-# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* only
-#    (jobs are already queued/async; this rule targets broadcast events).
-run_check "4/6 App\\Events\\* dispatch afterCommit" \
-    'App\\\\Events\\\\[A-Za-z]+::dispatch\(' \
-    'afterCommit|shouldDispatchAfterCommit|// allow:' \
-    app/Services/OrderService.php \
-    app/Services/FrontendOrderService.php
+# 4. Event broadcast dispatched without afterCommit — scope to App\Events\* broadcast events.
+#    V5 #2: FQN (\App\Events\X::dispatch) AND short-name (X::dispatch with `use`).
+#    V8 #1: Laravel helpers event(new X(...)) and Event::dispatch(new X(...)).
+#    V9 #1: awk post-filter — if DB::afterCommit( appears in the 5 lines above a hit, skip.
+#    NOTE 2026-04-20: this check WILL fail until P11_DISPATCH_AFTER_COMMIT_REMEDIATION
+#    (V5 #1) implements ShouldDispatchAfterCommit on event classes. Pre-existing
+#    violations in OrderService.php / FrontendOrderService.php are tracked and
+#    will resolve automatically once events implement the contract.
+#    Item/Category catalog events use manual DB::afterCommit wrapping (multi-line);
+#    invariant 4/6 detects the wrap structurally (no per-site // allow:).
+BROADCAST_EVENTS_4_6='OrderCreated|OrderStatusChanged|ItemAvailabilityChanged|ItemCreated|ItemUpdated|ItemDeleted|CategoryCreated|CategoryUpdated|CategoryDeleted'
+PATTERN_4_6="(${BROADCAST_EVENTS_4_6})::dispatch\\(|(event\\(new |Event::dispatch\\(new )(${BROADCAST_EVENTS_4_6})\\b"
+EXCLUDE_4_6='afterCommit|shouldDispatchAfterCommit|// allow:|use App\\\\Events|DB::afterCommit'
+SCOPE_4_6=( app/Services/OrderService.php
+            app/Services/FrontendOrderService.php
+            app/Services/Menu/AvailabilityService.php
+            app/Services/ItemService.php
+            app/Services/ItemCategoryService.php
+            app/Http/Controllers/Admin/AvailabilityController.php )
+
+echo -n "  [4/6 App\\Events\\* dispatch afterCommit] ... "
+raw_hits_4_6=$(grep -rEn --include='*.php' -e "$PATTERN_4_6" "${SCOPE_4_6[@]}" 2>/dev/null || true)
+raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -vE "$EXCLUDE_4_6" || true)
+raw_hits_4_6=$(echo "$raw_hits_4_6" | grep -v '^$' || true)
+filtered_hits_4_6=$(filter_aftercommit_wrapped "$raw_hits_4_6")
+count_4_6=0
+[[ -n "$filtered_hits_4_6" ]] && count_4_6=$(echo "$filtered_hits_4_6" | wc -l | tr -d ' ')
+
+if [[ $count_4_6 -eq 0 ]]; then
+    echo "${GREEN}OK${NC}"
+else
+    echo "${RED}FAIL (${count_4_6} hit(s))${NC}"
+    FAILED=$((FAILED + 1))
+    TOTAL_HITS=$((TOTAL_HITS + count_4_6))
+    if [[ $VERBOSE -eq 1 ]]; then
+        echo "$filtered_hits_4_6" | head -20 | sed 's/^/      /'
+    fi
+fi
 
 # 5. EventContract bypass — broadcast() must build & assert envelope.
 run_check "5/6 EventContract envelope" \
```

---

## 3. Diff `app/Services/ItemService.php` + `ItemCategoryService.php`

Suppression **uniquement** du suffixe `// allow: wrapped DB::afterCommit (V8 #1)` (lignes concernées par le plan).

**ItemService.php** (2 sites) :

```diff
-                    event(new ItemCreated($createdItemId)); // allow: wrapped DB::afterCommit (V8 #1)
+                    event(new ItemCreated($createdItemId));

-                    event(new ItemDeleted($itemId)); // allow: wrapped DB::afterCommit (V8 #1)
+                    event(new ItemDeleted($itemId));
```

**ItemCategoryService.php** (3 sites) :

```diff
-                    event(new CategoryCreated($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
+                    event(new CategoryCreated($categoryId));

-                    event(new CategoryUpdated($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
+                    event(new CategoryUpdated($categoryId));

-                    event(new CategoryDeleted($categoryId)); // allow: wrapped DB::afterCommit (V8 #1)
+                    event(new CategoryDeleted($categoryId));
```

*(Si le dépôt local avait déjà ces lignes sans commentaire, le diff git peut être vide ; la sémantique attendue est ci-dessus.)*

---

## 4. Diff KI-001 — section V9 #1

Ajout après la section V8 #1 + ajustement mineur du libellé « Confirmed-wrapped sites » (plus de dépendance aux commentaires `// allow:`).

Fichier : `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md`

---

## 5. Sortie verbose 4/6 — avant / après

### Avant (logique équivalente : hits grep après exclude, **sans** post-filter awk)

Sur la même portée et le même `PATTERN_4_6` / `EXCLUDE_4_6`, le **brut** après `grep -vE` compte **13** lignes (8 OrderService / FrontendOrderService + 5 Item / ItemCategory), par exemple :

```
app/Services/OrderService.php:541:...
...
app/Services/FrontendOrderService.php:848:...
app/Services/ItemService.php:182:...
app/Services/ItemService.php:306:...
app/Services/ItemCategoryService.php:119:...
app/Services/ItemCategoryService.php:151:...
app/Services/ItemCategoryService.php:186:...
```

### Après (`bash scripts/check-invariants.sh -v`, filtre awk actif)

```
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
  ...
==> 1 invariant(s) violated (8 total hit(s)).
```

**Validation** : 13 → 5 filtrés (wrap `DB::afterCommit(`) → **8** hits conservés.

---

## 6. Comptage validation

| Mesure | Valeur |
|--------|--------|
| Raw hits (sans awk) | 13 |
| Après `filter_aftercommit_wrapped` | **8** |
| `grep "// allow: wrapped DB::afterCommit (V8 #1)"` Item + ItemCategory | **0** |
| `grep -c DB::afterCommit` ItemService / ItemCategoryService | 2 / 3 (inchangé, wraps intacts) |

---

## 7. `bash scripts/check-invariants.sh` (sans `-v`)

- Exit code : **1**
- 1/6 OK, 2/6 OK, 3/6 OK, **4/6 FAIL (8 hit(s))**, 5/6 OK, 6/6 OK
- Ligne : `[4/6 App\Events\* dispatch afterCommit] ... FAIL (8 hit(s))`

---

## 8. Risque résiduel / suivi

- Fenêtre **5 lignes** : documentée dans KI-001 V9 #1 ; à élargir si des wraps dépassent cette distance.
- Faux négatif théorique si `DB::afterCommit(` apparaît dans un commentaire au-dessus du hit (trade-off accepté).

**Path rapport** : `reports/execution/RUN_P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK_2026-04-20.md`

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run `bash scripts/check-invariants.sh -v 2>&1 \| sed -n '/4\/6 App/,/^==>/p'` | **8 hits FAIL** (= V5 #2 + V8 #1 baseline strictement préservée) |
| 2 | `grep "// allow: wrapped DB::afterCommit (V8 #1)" app/Services/ItemService.php app/Services/ItemCategoryService.php` | **0 match** (dette V8 #1 totalement éliminée) |
| 3 | `grep -c "DB::afterCommit" app/Services/Item*.php` | **2 + 3** (wraps intacts, jamais touchés) |
| 4 | Autres invariants (1/2/3/5/6) | tous **OK** (aucun side effect du nouveau bloc 4/6) |
| 5 | `grep -n "filter_aftercommit_wrapped" scripts/check-invariants.sh` | défini ligne 82, appelé ligne 171 (wired correctement) |
| 6 | `bash scripts/check-invariants.sh` exit code | **1** (uniquement 4/6 FAIL, comportement attendu) |

**Test de robustesse implicite** : la fonction awk a correctement filtré les 5 sites Item/Category malgré la disparition des `// allow:` → preuve que la détection structurelle fonctionne. Les 8 hits restants sont tous des sites OrderService/FrontendOrderService **sans** wrap `DB::afterCommit` à 5 lignes au-dessus → vrais bugs (= scope C9 remediation).

**Lesson learned V9 #1** : la gouvernance de sentinelles doit toujours préférer une détection **structurelle** (analyse syntaxique légère) à une détection **conventionnelle** (allowlist de commentaires). Coût : ~30 lignes awk. Bénéfice : élimination définitive de la dette d'allowlist + détection automatique de tout futur site wrappé.

**Note git** : `git diff app/Services/Item*.php` peut apparaître vide vs HEAD car V8 #1 n'avait pas été commit ; V9 #1 ramène simplement le fichier à son état HEAD original (diff net = 0). Comportement attendu et cohérent avec le workflow no-commit.
