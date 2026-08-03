# EXECUTE — P13_LOG_HYGIENE — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (presentation only — 8 lignes JS commentées)
**VAGUE:** V4 salve 1 (P3 hygiène — plan §1.4 ligne 88)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.4 ligne 88 (P13_LOG_HYGIENE)
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-18-05
- `reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md` §5.2 ligne 142 + §R6 ligne 204

## Constat factuel pré-cycle (vérifié read-only)

**8 occurrences `console.log` exactes confirmées** :

| Fichier | Lignes | Contexte |
|---|---|---|
| `resources/js/services/appService.js` | 263 | itération formdata `pair[0] + " : " + pair[1]` |
| `resources/js/services/WebSocketService.js` | 56 | trace transition Pusher state `[WS] previous → current` |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | 580, 591 | Echo subscribe/unsubscribe `[KDS]` branch |
| `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` | 152, 162 | Echo subscribe/unsubscribe `[OSS]` branch |
| `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | 229, 240 | Echo subscribe/unsubscribe `[KioskWaiting]` branch |

**Risque identifié** : `KioskWaitingComponent.vue` est dans le git status initial comme `M` (modifié par un autre cycle/dev). Les 2 lignes ciblées (229, 240) doivent être vérifiées dans la **version courante working tree** (pas HEAD), pour s'assurer qu'elles existent encore après les modifs parallèles.

**Stratégie** : commenter (pas supprimer) chaque ligne avec préfixe `// [P13_LOG_HYGIENE]` — préserve la trace pour debug futur tout en silenciant la sortie production.

**Anti-pattern à éviter** : ne **PAS** introduire de wrapper logger global (étend le scope, change l'archi). Pure suppression / commentaire.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "isolated UI fixes, no schema, no auth, no pricing")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- 5 fichiers JS/Vue (8 lignes commentées exactement)

### SCOPE_FILES (whitelist stricte — 6 fichiers)
- `resources/js/services/appService.js` (1 ligne)
- `resources/js/services/WebSocketService.js` (1 ligne)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (2 lignes)
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` (2 lignes)
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` (2 lignes — VÉRIFIER positions actuelles)
- `reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict)
- ❌ Tout autre fichier `resources/js/**/*.{vue,js}` (autres composants)
- ❌ Tout backend PHP (`app/`, `routes/`, `database/`, `config/`)
- ❌ Tests (`tests/**`)
- ❌ Build (`webpack.mix.js`, `package.json`, lockfiles)
- ❌ i18n (`resources/js/languages/*.json`, `lang/**/*.php`)
- ❌ Docs (`docs/`, `README.md`)
- ❌ Aucune création de wrapper logger / utility nouveau fichier

## Invariants at Risk
- **Aucun** — supprime uniquement de la trace stdout dev. Comportement runtime identique.
- Risque mineur : si un test E2E Playwright observait le `console.log` du navigateur (peu probable). Mitigation : aucun test ne semble dépendre de ces traces (à confirmer par grep si suspect).

## Dependencies
- Aucune

## Plan bref

### Étape 1 — Lire (vérité terrain working tree, PAS HEAD)
Pour chaque fichier, lire les lignes ±3 autour du numéro indiqué pour confirmer que `console.log` est bien là dans le working tree actuel :
- `appService.js:260-266`
- `WebSocketService.js:53-59`
- `KitchenDisplaySystemComponent.vue:577-594`
- `PreparingAndReadyComponent.vue:149-165`
- `KioskWaitingComponent.vue:226-243` ⚠️ (modifié parallèle — relire absolument)

**Si une ligne est introuvable** (ex. parallel dev a déjà supprimé) → STOP + déclarer dans le rapport, NE PAS chercher d'autres `console.log` à commenter (scope strict).

### Étape 2 — Modifier les 8 lignes

Pour chaque ligne `console.log(...)`, **commenter** :

```js
// [P13_LOG_HYGIENE] console.log(...);
```

Exemple :

Avant :
```js
console.log(`[KDS] Echo subscribed to branch.${branchId}`);
```

Après :
```js
// [P13_LOG_HYGIENE] console.log(`[KDS] Echo subscribed to branch.${branchId}`);
```

**Ne PAS modifier** :
- L'indentation
- Les espaces autour
- Le contenu de la ligne (préserver caractères exacts)

### Étape 3 — Validation
- `git diff --stat` (preuve scope respect — 5 fichiers app modifiés)
- `git status --short` (vérifier aucun fichier hors whitelist)
- `git diff` filtré pour confirmer **uniquement des `+` lignes commentaires + `-` lignes console.log** (pas d'autre changement)
- `grep -rn "console\.log" resources/js/services/appService.js resources/js/services/WebSocketService.js resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` → DOIT renvoyer **0 console.log non commentée** (ou seulement celles dans `// [P13_LOG_HYGIENE] console.log(...)`)

### Étape 4 — Rapport
`reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] 5 fichiers modifiés (exactement)
- [ ] 8 lignes `console.log` commentées avec préfixe `// [P13_LOG_HYGIENE]`
- [ ] `grep -rn "^[^/]*console\.log" <fichiers cibles>` → 0 résultat (aucun console.log non commenté restant)
- [ ] **Aucun** fichier hors whitelist modifié
- [ ] Aucune création de fichier (sauf rapport)

## Exit Criteria
- [ ] 5 fichiers app touchés exactement
- [ ] 8 lignes commentées
- [ ] `reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1+V3)
**STOP IMMÉDIAT** si :
- Tentation de créer un wrapper logger (ex. `resources/js/utils/logger.js`) → ❌ scope creep
- Tentation de remplacer par `if (process.env.NODE_ENV !== 'production')` ou autre conditionnel → ❌ étend scope (touche build config)
- Tentation de purger d'autres `console.log` du repo (le grep trouve probablement d'autres dans `resources/js/`) → ❌ scope strict, seulement les 8 listés
- Tentation de modifier le contenu de la trace (ex. retirer `${branchId}` pour "anonymiser") → ❌ scope = silence, pas refacto
- Tentation de supprimer (vs commenter) → ❌ on commente pour préserver intention/debug
- Tentation de modifier `KioskWaitingComponent.vue` au-delà des 2 lignes (parallel dev) → ❌ scope strict
- Tentation de toucher `package.json` ou ajouter ESLint rule no-console → ❌
- **Anti-pattern V3 #4** : si une ligne semble manquante, NE PAS aligner sur HEAD ou autre version → STOP + escalade parent

## Remediation
- Attempt 1 KO (ligne déplacée par parallel dev) → relire fichier, ajuster numéro de ligne, re-tenter
- Attempt 2 KO → simplifier (commenter seulement les lignes trouvables, documenter manquantes)
- Attempt 3 même `bug_signature` → STOP + escalade parent

## Deliverables
- Diff 5 fichiers (8 lignes commentées)
- `reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md`

## Communication
Subagent renvoie : verdict, `git status --short` filtré, `git diff --stat`, output `grep -rn` post-modif (preuve 0 console.log restant), notes sur lignes éventuellement non trouvées (parallel dev), confirmation aucune autre modif.
