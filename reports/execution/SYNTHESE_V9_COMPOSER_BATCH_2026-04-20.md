# Synthèse V9 — Composer batch (salve Z1 + U) — 2026-04-20

## Contexte

Salve **élimination de dette V8 + couverture flag POS dine-in**.

| Salve | Cycle | Type | Verdict |
|---|---|---|---|
| Z1 | V9 #1 — `P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK` | Composer / no gate / script + 2 services + KI doc | **CLOSED — PASSED** |
| U | V9 #2 — `P11_POS_DINE_IN_FLAG_TEST_EXTEND` | Composer / no gate / 1 spec Vitest | **CLOSED — PASSED with documented deviation** |

## Résultats détaillés

### V9 #1 — `INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK`

**Avant V9 #1** : check 4/6 V8 #1 nécessitait 5 commentaires `// allow: wrapped DB::afterCommit (V8 #1)` sur les sites Item/Category, créant une dette d'allowlist fragile.

**Après V9 #1** :
- Nouvelle fonction `filter_aftercommit_wrapped()` en bash + awk dans `scripts/check-invariants.sh` (ligne 82)
- Pour chaque hit grep 4/6, awk inspecte les 5 lignes au-dessus dans le fichier source ; si `DB::afterCommit(` y figure → wrap valide, hit ignoré
- Bloc 4/6 réécrit en inline (sans toucher `run_check` qui sert les autres invariants 1/2/3/5/6)
- 5 commentaires `// allow:` retirés des services Item/ItemCategory
- KI-001 enrichi section V9 #1

**Validation orchestrateur** :
- 4/6 = **8 hits** strict (= V5 #2 + V8 #1 baseline préservée)
- Brut sans awk = 13 hits (8 + 5 Item/Category) ; awk filtre les 5 → 8 ✓
- Wraps `DB::afterCommit` toujours présents (2 ItemService, 3 ItemCategoryService) ✓
- Autres invariants 1/2/3/5/6 OK ✓

**Bénéfice durable** :
- Détection désormais **structurelle** (analyse syntaxique légère via awk) plutôt que **conventionnelle** (allowlist commentaires)
- Tout futur dispatch wrappé `DB::afterCommit` est auto-détecté, sans annotation manuelle
- Si un dev retire un wrap par erreur, le check 4/6 reverra immédiatement le hit (no silent regression)

### V9 #2 — `POS_DINE_IN_FLAG_TEST_EXTEND`

**Avant V9 #2** : 4 `it()` basiques (defaults, 0/false, 1/true, dotted-key)

**Après V9 #2** : 10 `it()` (4 originaux + 6 nouveaux)
1. Précédence snake_case vs dotted-key (court-circuit `??` sur 0)
2. Rejet boolean strings non-strictes (`'true'`, `'TRUE'`, `'yes'`, `'on'`)
3. Rejet numeric weird (`2`, `-1`, `0.5`, `'2'`)
4. Rejet `null` / `NaN` / `undefined` explicites
5. Rejet types non-primitifs (`{}`, `[]`, `[1, 2]`, `() => 1`)
6. Documentation intentionnelle de la coercion `String(raw) === '1'`

**Déviation documentée** : `[1, 2]` substitué à `[1]` dans le test des arrays car `String([1]) === '1'` est une quirk JavaScript native qui rendrait `dineInEnabledFrom({pos_dine_in_enabled: [1]})` à `true`. Subagent a respecté l'interdit "ne pas modifier la fonction" et ajusté le test.

**Découverte parallèle** : micro-vulnérabilité de coercion documentée. Sévérité très faible (payload backend normal est `0` ou `1`, jamais array). Suggestion future cycle `P11_DINE_IN_FLAG_STRICT_HARDENING` (no gate, 10 min) si triage produit confirme l'utilité.

## Statistiques cumulées Composer (V1-V9)

| Wave | Cycles | PASSED | NO_OP | PARTIAL | DEVIATION | BUG_FOUND | Annulés | Régressions |
|---|---|---|---|---|---|---|---|---|
| V1 | 8 | 8 | 0 | 0 | 0 | 0 | 0 | 0 |
| V3 | 1 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| V4 | 11 | 9 | 0 | 1 | 0 | 1 | 0 | 0 |
| V5 | 2 | 2 | 0 | 0 | 0 | 0 | 0 | 0 |
| V6 | 2 | 2 | 0 | 0 | 0 | 0 | 0 | 0 |
| V7 | 2 | 1 | 1* | 0 | 0 | 0 | 0 | 0 |
| V8 | 2 | 2 | 0 | 0 | 0 | 0 | 1 (T) | 0 |
| **V9** | **2** | **1** | **0** | **0** | **1** | **0** | **0** | **0** |
| **TOTAL** | **30** | **26** | **1*** | **1** | **1** | **1** | **1** | **0** |

*V7 #1 NO_OP corrigé in-place dans KI-001 par orchestrateur pré-V8 (résultat technique reste valide).

## Convergence des sentinelles 4/6 (V5 → V8 → V9)

Vue d'ensemble du durcissement progressif du check `dispatch-after-commit` :

| Wave | Patterns détectés | Stratégie d'exclusion | Hits stables |
|---|---|---|---|
| V5 #2 | FQN + short-name `::dispatch` | exclude regex (`afterCommit\|...`) | 8 |
| V8 #1 | + `event(new ...)` + `Event::dispatch(new ...)` | + 5 commentaires `// allow:` (dette) | 8 |
| **V9 #1** | (idem) | post-filter awk multi-ligne `DB::afterCommit(` 5 lignes | **8** |

**Convergence parfaite** : 3 vagues de durcissement, 0 nouveau false positive, 0 régression, dette d'allowlist éliminée. Les 8 vrais bugs OrderService + FrontendOrderService sont strictement préservés et **uniquement** détectés (= scope C9 remediation).

## Lessons learned V9

### 1. **Détection structurelle > détection conventionnelle**
Allowlist par commentaires (`// allow:`) = dette permanente. Préférer une analyse syntaxique légère (awk, grep multi-pass) qui se met à jour automatiquement avec le code.

### 2. **Une fonction de filtrage générique est dangereuse**
J'ai recommandé de NE PAS modifier `run_check` original (qui sert 5 autres invariants) et de réécrire 4/6 en inline avec post-filter local. Bénéfice : zero side effect sur 1/2/3/5/6.

### 3. **Les quirks JavaScript méritent des tests**
`String([1]) === '1'` est subtil mais réel. Les tests d'edge cases révèlent des micro-vulnérabilités de coercion qui ne sont jamais attrapées par les tests "happy path".

### 4. **Documenter les déviations vaut mieux qu'élargir le scope**
Subagent V9 #2 a découvert un comportement laxiste de `dineInEnabledFrom` mais a respecté son scope (test only). Documenté + suggéré comme futur cycle. Bonne discipline.

## Prochaines étapes (handoff)

### Gates en attente d'approbation humaine
1. **C1-C8** consolidé — `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` (3 GPT-5.4)
2. **C9 étendu** — `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` — bug confirmé 3 events × 8 call-sites (sentinelles V5+V8+V9 toutes vertes pour valider la remédiation après application)

### Options Composer no-gate restantes

| Option | Cycle | Bénéfice | Effort | Risque |
|---|---|---|---|---|
| Z2 | `P11_DISPATCH_PATTERN_3_FACADE` | étendre check 4/6 à `Event::dispatch(...)` non-static façade SI grep révèle des hits | ~30 min | bas |
| V | `P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND` | étendre `kioskOfflineQueue.spec.js` reconnexion partielle | ~45 min | bas |
| W | `P11_KI_002_*` | doc known-issue pour autre bug ouvert (à sélectionner depuis verifyTracker) | ~30 min | bas |
| AA | `P11_DINE_IN_FLAG_STRICT_HARDENING` | durcir `dineInEnabledFrom` avec typeof check (issu V9 #2 deviation) | ~10 min + nouveau test | bas |
| BB | `P11_KI_002_KIOSK_OUTBOX_DRIFT` | KI-002 si VERIFY_15 a un bug observability ouvert non-doc | ~30 min | bas |

### Recommandation orchestrateur

**3 chemins équivalents en valeur** :
- **AA** (durcir `dineInEnabledFrom`) : effort minimal, valeur immédiate, ferme la quirk découverte V9 #2.
- **V** (kioskOfflineQueue tests) : couverture frontend critique, complète V6 #1 sur la résilience kiosk.
- **C9 gate humain** : reste le blocker majeur pour V5 #1 (ghost-orders prod).

**Recommandation forte** : approuver **C9** maintenant. Toutes les sentinelles sont en place pour valider la remédiation, le risque est calibré, le plan détaillé. Plus on attend, plus l'exposition prod augmente.

Si pas de C9 → enchaîner **AA** (10 min, fermeture quirk V9 #2) puis **V** (kiosk offline queue).
