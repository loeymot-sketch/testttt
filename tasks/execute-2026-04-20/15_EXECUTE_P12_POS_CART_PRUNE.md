# EXECUTE — P12_POS_CART_PRUNE — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (front Vuex + 1 callsite Vue, parity pattern kiosk)
**VAGUE:** V4 salve 2 (P2 UX POS — plan §1.3 ligne 68)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.3 ligne 68 (P12_POS_CART_PRUNE)
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-01-02
- `reports/review/VERIFY_01_P1_AVAILABILITY_2026-04-20.md` §3.3 ligne 98 (POS ne prune pas) + §F-VERIFY-01-02 ligne 176

## Constat factuel pré-cycle (vérifié read-only)

**Bug** : Le POS reçoit l'event `ItemAvailabilityChanged` (`PosComponent.vue:1086`) et grise la tuile catalogue (`_onItemAvailabilityChanged:1098-1122`), mais **ne purge pas le panier en cours**. Si une ligne déjà ajoutée bascule unavailable, la garde serveur la rejette à submit (422 `OrderService::posOrderStore`).

**Pattern de référence** : kiosk (`KioskAppComponent.vue:388` → `kioskCart/pruneUnavailableLines` → `kioskCart.js:337-352`).

**Architecture POS confirmée (read-only)** :
- Store : `resources/js/store/modules/posCart.js` (387 lignes)
- State principal : `state.lists[]` — chaque ligne a `item_id` (number) + `pos_line_addons[]` (chaque addon a son `item_id`)
- Pattern de splice existant : `quantity` mutation ligne 337-338 (`state.lists.splice(payload.id, 1)`) — modèle à reproduire

**Décision de scope strict (parity minimale)** :
- ✅ Prune par **item_id principal** uniquement (`line.item_id === itemId`)
- ❌ Pas de prune par addon `pos_line_addons[].item_id` (edge case bundle menu — laisse au serveur la garde finale, évite scope creep)
- ❌ Pas de toast / message UX (cycle séparé pour i18n+UX si besoin)
- ❌ Pas de modification de la 422 serveur ni du parsing front (cycle séparé : `P14_AvailabilityException_StructuredPayload` backlog)

**Comportement attendu** : quand admin met item indisponible, l'event `ItemAvailabilityChanged` arrive sur le canal POS. Si `payload.is_available === false`, on dispatche `posCart/pruneUnavailable` qui retire silencieusement les lignes correspondantes. Le caissier voit son panier rétrécir mais pas de modal/toast (UX silencieuse — cf. plan).

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "bounded UI changes, parity with existing pattern")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
1. `resources/js/store/modules/posCart.js` — ajouter action `pruneUnavailable` (~6 lignes) + mutation `pruneUnavailable` (~7 lignes)
2. `resources/js/components/admin/pos/PosComponent.vue` — ajouter dispatch dans `_onItemAvailabilityChanged` (~3 lignes)

### SCOPE_FILES (whitelist stricte — 3 fichiers)
- `resources/js/store/modules/posCart.js` (+~13 lignes)
- `resources/js/components/admin/pos/PosComponent.vue` (+~3 lignes)
- `reports/execution/RUN_P12_POS_CART_PRUNE_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict)
- ❌ Backend PHP (`app/`, `routes/`, controllers, services)
- ❌ Tests (`tests/**`, `tests/js/**`)
- ❌ Autres stores (`kioskCart.js`, `kitchenDisplaySystemOrder.js`, etc.)
- ❌ Autres composants POS (`PaymentComponent.vue`, `ItemComponent.vue`, etc.)
- ❌ Build (`webpack.mix.js`, `package.json`)
- ❌ i18n (`resources/js/languages/*.json`) — cycle séparé si toast/i18n nécessaire
- ❌ Reset / discount / subtotal mutations existantes (juste **ajout** de prune, pas refacto)
- ❌ `pos_line_addons[].item_id` (out of scope, edge case bundle)
- ❌ Aucune nouvelle clé localStorage
- ❌ Aucune dépendance npm ajoutée
- ❌ Persistence localStorage **OUI** : la prune doit appeler `saveCartToStorage(state)` comme les autres mutations qui modifient `state.lists` (cohérence avec pattern existant ligne 309, 317, 331, 346, 352, 356)

## Invariants at Risk
- **Aucun invariant métier** — la garde serveur reste SSOT (`OrderService::posOrderStore` ligne 612/659 garde inchangée).
- Risque local : si la prune retire la dernière ligne, `state.subtotal` doit être recalculé. Mitigation : la mutation prune doit appeler `subtotal` mutation à la fin (pattern existant des mutations qui touchent `state.lists`).
- Risque cross-cycle : `posCart.js` n'est PAS dans `git status` initial (pas modifié par autre cycle/dev). Diff propre attendu.
- Risque persistance : si on ne sauvegarde pas dans localStorage, après reload le panier "ressuscite" l'item indisponible. Mitigation : appeler `saveCartToStorage(state)` dans la mutation.

## Dependencies
- Aucune (l'event POS `ItemAvailabilityChanged` est déjà subscribed depuis P1, cf. `PosComponent.vue:1086`)

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `resources/js/store/modules/posCart.js` (intégral, 387 lignes — déjà lu)
- `resources/js/components/admin/pos/PosComponent.vue:1086-1126` (subscribe + handler `_onItemAvailabilityChanged` — déjà lu)
- Optionnel : confirmer `kioskCart.js:337-352` pour vérifier le pattern de référence (read-only)

### Étape 2 — Modifier `posCart.js`

**Patch 1 — Ajouter action `pruneUnavailable`** dans le bloc `actions` (entre `replaceCartLine` ligne 209-212 et `setScope` ligne 221, en gardant l'ordre alphabétique relâché — ajouter à la fin avant `setScope`).

Insérer **après** la ligne `replaceCartLine: function (context, payload) { ... }` (ligne ~212) et **avant** `/**` de `setScope` :

```js
        /**
         * [P12_POS_CART_PRUNE / F-VERIFY-01-02] Remove cart lines whose item_id
         * matches an item flagged unavailable by the ItemAvailabilityChanged
         * broadcast. Mirrors the kiosk pattern (kioskCart/pruneUnavailableLines).
         * Bundle add-ons (pos_line_addons[].item_id) are out of scope: the
         * server guard remains the SSOT and rejects the order at submit time.
         */
        pruneUnavailable: function (context, itemId) {
            context.commit('pruneUnavailable', itemId);
            context.commit('subtotal');
        },
```

**Patch 2 — Ajouter mutation `pruneUnavailable`** dans le bloc `mutations` (insérer après `deleteCartItem` ligne 348-353, avant `discount` ligne 354, en respectant l'ordre du fichier).

Insérer **après** `deleteCartItem` mutation (ligne ~353) :

```js
        pruneUnavailable: function (state, itemId) {
            const id = parseInt(itemId, 10);
            if (!id) return;
            const before = state.lists.length;
            state.lists = state.lists.filter(line => parseInt(line.item_id, 10) !== id);
            if (state.lists.length !== before) {
                state.discount = 0;
                saveCartToStorage(state);
            }
        },
```

**Précisions** :
- `parseInt(..., 10)` pour cohérence avec `_onItemAvailabilityChanged` qui parse déjà l'`item_id`
- `state.discount = 0` : aligné avec le pattern `quantity` (ligne 339) et `deleteCartItem` (les autres mutations qui retirent une ligne réinitialisent le discount manuel saisi)
- `saveCartToStorage(state)` : appelé seulement si quelque chose a changé (optimisation mineure, évite I/O inutile)
- Ne PAS toucher aux autres lignes du store

### Étape 3 — Modifier `PosComponent.vue`

**Patch 3 — Ajouter dispatch dans `_onItemAvailabilityChanged`** (lignes 1098-1122). Insérer juste **après** la mise à jour `list[idx]` (ligne ~1114) et **avant** le bloc `if (payload.type === 'full')` (ligne 1119).

Avant (lignes 1106-1119) :
```js
            if (list) {
                const idx = list.findIndex(i => parseInt(i.id, 10) === itemId);
                if (idx !== -1) {
                    const isAvailable = payload.is_available === true || payload.is_available === 1 || payload.is_available === '1';
                    list[idx] = Object.assign({}, list[idx], {
                        is_available: isAvailable,
                        availability_reason: payload.reason || null,
                    });
                }
            }

            // If the broadcast signals a structural change (price / variation /
            // category move), reload the catalogue in the background.
            if (payload.type === 'full') {
```

Après :
```js
            if (list) {
                const idx = list.findIndex(i => parseInt(i.id, 10) === itemId);
                if (idx !== -1) {
                    const isAvailable = payload.is_available === true || payload.is_available === 1 || payload.is_available === '1';
                    list[idx] = Object.assign({}, list[idx], {
                        is_available: isAvailable,
                        availability_reason: payload.reason || null,
                    });
                    // [P12_POS_CART_PRUNE / F-VERIFY-01-02] Mirror kiosk parity:
                    // remove cart lines for this item_id when it becomes unavailable.
                    if (!isAvailable) {
                        try { this.$store.dispatch('posCart/pruneUnavailable', itemId); } catch (e) { /* defensive */ }
                    }
                }
            }

            // If the broadcast signals a structural change (price / variation /
            // category move), reload the catalogue in the background.
            if (payload.type === 'full') {
```

**Précisions** :
- Le dispatch est wrappé en `try/catch` defensive (cohérent avec `try { this.itemList(); } catch (e) { /* defensive */ }` ligne 1120)
- Indentation : 20 espaces (4 niveaux × 4) pour la ligne `if (!isAvailable)` — vérifier visuellement après édition
- Le commentaire `// [P12_POS_CART_PRUNE / F-VERIFY-01-02]` est traçable en git blame

### Étape 4 — Validation
- `git diff --stat` → 2 fichiers app modifiés (+~16 lignes total au max)
- `git status --short` → vérifier aucun fichier hors whitelist (ATTENTION : `PosComponent.vue` n'est PAS dans le `git status` initial → donc une modif locale propre)
- `git diff resources/js/store/modules/posCart.js` → DOIT montrer +13 lignes (action + mutation), 0 ligne `-`
- `git diff resources/js/components/admin/pos/PosComponent.vue` → DOIT montrer +6 lignes (3 logique + 1 commentaire 2 lignes + try/catch wrap), 0 ligne `-`
- Accolades équilibrées :
  ```bash
  awk '/{/{c++} /}/{c--} END{print c}' resources/js/store/modules/posCart.js
  awk '/{/{c++} /}/{c--} END{print c}' resources/js/components/admin/pos/PosComponent.vue
  ```
  → DOIT renvoyer `0` pour chacun
- **Anti-régression cross-cycle** : `git diff` ne doit PAS supprimer d'autre ligne (anti-pattern V3 #4)

### Étape 5 — Tests existants
**Pas de test ajouté** (scope strict V4 salve 2). Le pattern kiosk équivalent est testé indirectement par `tests/js/kioskMenuStore.spec.js` mais aucun test JS direct sur `posCart.js`. Documenter dans le rapport.

### Étape 6 — Rapport
`reports/execution/RUN_P12_POS_CART_PRUNE_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] `posCart.js` contient une action `pruneUnavailable` (cherche `pruneUnavailable: function`)
- [ ] `posCart.js` contient une mutation `pruneUnavailable`
- [ ] `posCart.js` `git diff` ne supprime AUCUNE ligne existante
- [ ] `PosComponent.vue` contient `posCart/pruneUnavailable` dans `_onItemAvailabilityChanged`
- [ ] `PosComponent.vue` `git diff` ne supprime AUCUNE ligne existante
- [ ] Accolades équilibrées (awk count = 0 pour les 2 fichiers)
- [ ] **Aucun** fichier hors whitelist modifié
- [ ] Aucune i18n / JSON modifié
- [ ] Aucun test ajouté

## Exit Criteria
- [ ] 2 fichiers app touchés exactement (posCart.js + PosComponent.vue)
- [ ] Pattern parité kiosk respecté (pruneUnavailable méthode store + dispatch handler)
- [ ] Syntaxe valide
- [ ] `reports/execution/RUN_P12_POS_CART_PRUNE_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1+V3+V4)
**STOP IMMÉDIAT** si :
- Tentation d'ajouter prune par `pos_line_addons[].item_id` → ❌ scope = item_id principal seulement
- Tentation d'ajouter toast UX (`alertService.info(...)`) → ❌ cycle UX i18n séparé
- Tentation de modifier `OrderService.php` ou `PosController.php` pour structurer la 422 → ❌ cycle backlog `P14_AvailabilityException_StructuredPayload`
- Tentation d'ajouter un test (`posCart.spec.js`) → ❌ scope V4 salve 2 strict
- Tentation de toucher `kioskCart.js` pour DRY / partager une util → ❌ duplication assumée (parity pattern, pas refacto cross-store)
- Tentation de modifier `is_available` → `isAvailable` dans tout le composant → ❌ scope strict
- Tentation de réorganiser l'ordre des actions/mutations du store → ❌ insertion seulement
- Tentation de modifier la mutation `deleteCartItem` ou `quantity` pour réutiliser logic → ❌ duplication assumée
- Tentation d'ajouter un getter `unavailableLines` → ❌ pas demandé
- **Anti-pattern V3 #4** : si `git diff` montre des lignes `-` → STOP + escalade

## Remediation
- Attempt 1 KO (syntax / accolades) → re-fix
- Attempt 2 KO → STOP + escalade
- Aucun retry sur scope creep — STOP immédiat

## Deliverables
- Diff `posCart.js` (+13/-0)
- Diff `PosComponent.vue` (+6/-0)
- `reports/execution/RUN_P12_POS_CART_PRUNE_2026-04-20.md`

## Communication
Subagent renvoie : verdict, `git status --short`, `git diff --stat`, full `git diff` des 2 fichiers, output `awk` accolades count, confirmation aucun autre fichier modifié, confirmation aucun toast/i18n/test ajouté.
