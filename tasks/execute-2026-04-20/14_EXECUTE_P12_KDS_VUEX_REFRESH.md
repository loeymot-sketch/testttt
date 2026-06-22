# EXECUTE — P12_KDS_VUEX_REFRESH — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (front Vuex, 1 ligne ajoutée)
**VAGUE:** V4 salve 2 (P2 robustesse front — plan §1.3 ligne 70)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.3 ligne 70 (P12_KDS_VUEX_REFRESH)
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-04-03
- `reports/review/VERIFY_04_P4_KDS_CONCURRENCY_2026-04-20.md` §F-VERIFY-04-03 ligne 288 + §H4 ligne 244

## Constat factuel pré-cycle (vérifié read-only)

**Cible précise** : `resources/js/store/modules/kitchenDisplaySystemOrder.js:42-44`

```js
.catch((err) => {
    if (err.response && err.response.status === 409) {
        context.dispatch("lists", payload).catch(() => {});
    }
    reject(err);
});
```

**Bug** : sur 409 (conflit concurrent KDS), seul `state.lists` est rafraîchi via `dispatch("lists", payload)`. `state.orderItems` (board d'items agrégés) reste sur l'ancien snapshot jusqu'au prochain Echo `OrderStatusChanged` ou `_debouncedRefresh()` côté composant. UI peut afficher des items obsolètes pour la commande conflictée.

**Fix** : ajouter en parallèle `dispatch("orderItems")` (action déjà définie ligne 49, ne prend pas de payload, GET `admin/kds-order/items` puis commit `orderItems`).

**Existing action `orderItems` confirmée** :
```js
orderItems: function (context, payload) {
    return new Promise((resolve, reject) => {
        let url = 'admin/kds-order/items';
        axios.get(url).then((res) => {
            context.commit('orderItems', res.data.data);
            resolve(res);
        }).catch((err) => {
            reject(err);
        });
    });
},
```

**Pourquoi P2 et pas plus haut** : H4 partiellement vraie (cf. audit V4) — l'UI items board est rafraîchi ailleurs (Echo + debounce). Le risque d'affichage stale est court (~quelques secondes). Mais le fix est trivial et améliore la cohérence transactionnelle entre les 2 vues Vuex.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "isolated UI fixes, no schema, no auth, no pricing, no lifecycle")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/store/modules/kitchenDisplaySystemOrder.js` (1 ligne ajoutée dans le bloc catch 409)

### SCOPE_FILES (whitelist stricte — 2 fichiers)
- `resources/js/store/modules/kitchenDisplaySystemOrder.js` (1 ligne ajoutée)
- `reports/execution/RUN_P12_KDS_VUEX_REFRESH_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict)
- ❌ Tout autre fichier `resources/js/store/modules/*.js` (autres stores)
- ❌ Tout backend PHP (`app/`, `routes/`, `database/`)
- ❌ Composants Vue (`KitchenDisplaySystemComponent.vue`, etc.)
- ❌ Tests (`tests/**`, `tests/js/**`)
- ❌ Build (`webpack.mix.js`, `package.json`)
- ❌ Aucune création de nouvelle action/mutation/getter (juste 1 dispatch ajouté)
- ❌ Aucune création de fichier (sauf rapport)

## Invariants at Risk
- **Aucun invariant métier** — refresh d'un store front sur 409 (cas d'erreur déjà géré).
- Risque mineur : appel HTTP supplémentaire (`GET admin/kds-order/items`) sur chaque 409. Acceptable car 409 = cas exceptionnel.
- Pas de risque de loop (catch isolé, pas de retry chain).

## Dependencies
- Aucune

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `resources/js/store/modules/kitchenDisplaySystemOrder.js` (intégral, 70 lignes — déjà lu, contenu confirmé)

### Étape 2 — Modifier 3 lignes (1 ajoutée + 2 inchangées contexte)

**Patch unique via StrReplace** :

Avant :
```js
                if (err.response && err.response.status === 409) {
                    context.dispatch("lists", payload).catch(() => {});
                }
```

Après :
```js
                if (err.response && err.response.status === 409) {
                    context.dispatch("lists", payload).catch(() => {});
                    context.dispatch("orderItems").catch(() => {});
                }
```

**Précisions** :
- Préserver l'indentation (4 espaces × 4 = 16 espaces avant `context.dispatch("orderItems")`)
- Préserver le pattern `.catch(() => {})` pour cohérence (silencieux comme la première dispatch)
- **Ne PAS** passer de payload à `orderItems` (signature `function (context, payload)` mais `payload` non utilisé dans le corps — passer `payload` ne casse rien mais ne sert à rien)
- **Ne PAS** ajouter de commentaire explicatif
- **Ne PAS** modifier d'autres lignes

### Étape 3 — Validation
- `git diff --stat resources/js/store/modules/kitchenDisplaySystemOrder.js` → 1 fichier, +1/-0 (ligne ajoutée pure)
- `git status --short` (vérifier aucun fichier hors whitelist)
- `git diff resources/js/store/modules/kitchenDisplaySystemOrder.js` → DOIT montrer EXACTEMENT 1 ligne `+`, 0 ligne `-`
- Validation syntaxe JS (compilation light) :
  ```bash
  node -e "require('./resources/js/store/modules/kitchenDisplaySystemOrder.js')" 2>&1 | head -5
  ```
  → **NE FONCTIONNERA PAS** (modules ES6 + import axios, pas de bundle Node). Ignorer si erreur module/import — ne pas considérer comme failure.
- Alternative validation : vérifier les accolades équilibrées via comptage simple :
  ```bash
  awk '/{/{c++} /}/{c--} END{print c}' resources/js/store/modules/kitchenDisplaySystemOrder.js
  ```
  → DOIT renvoyer `0` (toutes accolades équilibrées)

### Étape 4 — Tests existants ne doivent PAS casser
**Pas obligatoire** : aucun test JS unit ne couvre directement cette action (recherche : `tests/js/kitchenDisplaySystem*` n'existe probablement pas). Documenter dans le rapport "no direct tests, behavior preserved by adding a parallel idempotent dispatch".

### Étape 5 — Rapport
`reports/execution/RUN_P12_KDS_VUEX_REFRESH_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] `kitchenDisplaySystemOrder.js` ligne dans le bloc catch 409 contient `context.dispatch("orderItems").catch(() => {});`
- [ ] `git diff` montre exactement 1 ligne `+`, 0 ligne `-`
- [ ] Accolades équilibrées (awk count = 0)
- [ ] **Aucun** fichier hors whitelist modifié
- [ ] Aucun autre dispatch / fonction ajouté

## Exit Criteria
- [ ] 1 fichier app touché exactement, +1/-0
- [ ] Syntaxe valide (accolades équilibrées)
- [ ] `reports/execution/RUN_P12_KDS_VUEX_REFRESH_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1+V3+V4)
**STOP IMMÉDIAT** si :
- Tentation de créer une action `refreshAll` qui fusionne `lists` + `orderItems` → ❌ refacto, scope creep
- Tentation de modifier `KitchenDisplaySystemComponent.vue` pour différencier UX 409 → ❌ c'est un autre cycle (P11_KDS_409_UX_DIFFERENTIATED, déjà clos ou backlog distinct)
- Tentation d'ajouter un payload à `orderItems` (ex. `branch_id`) → ❌ l'action ne le consomme pas
- Tentation d'ajouter un `Promise.all([...])` pour parallèle → ❌ refacto, le séquentiel ne pose aucun problème (axios fait déjà du parallel à HTTP level)
- Tentation de purger ou réorganiser les autres actions/mutations du store → ❌
- Tentation d'ajouter un toast d'erreur → ❌ scope strict store
- Tentation de modifier le getter `orderItems` ou la mutation → ❌
- **Anti-pattern V3 #4** : si le diff montre des lignes `-` autres que celle ciblée → STOP + escalade

## Remediation
- Attempt 1 KO (syntax broken) → re-fix indentation/accolades
- Attempt 2 KO → STOP + escalade
- Aucun retry sur scope creep — STOP immédiat

## Deliverables
- Diff `kitchenDisplaySystemOrder.js` (+1/-0)
- `reports/execution/RUN_P12_KDS_VUEX_REFRESH_2026-04-20.md`

## Communication
Subagent renvoie : verdict, `git status --short`, `git diff --stat`, `git diff resources/js/store/modules/kitchenDisplaySystemOrder.js`, output `awk` accolades count, confirmation aucun autre fichier modifié.
