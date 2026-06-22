# Plan – P_MEGA_W3_ALLERGENS_PLUS_P23_DRIFT_AUDIT_2026-04-20

## TASK_ID
P_MEGA_W3_ALLERGENS_PLUS_P23_DRIFT_AUDIT_2026-04-20

## Source
- Mega plan: `plans/PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` — Wave 3
- Tasks couvertes: **P-MEGA-08** (allergens propagation) + **P-MEGA-09** (filtre allergène persistant)
- Hors EXECUTE Wave 3 mais livré dans le même cycle (livrable orchestrateur séparé): **AUDIT P-MEGA-23** readonly → `reports/execution/AUDIT_P_MEGA_23_DRIFT_ROOT_CAUSE_2026-04-20.md`

## PRIMARY_MODEL

| Cycle EXECUTE | PRIMARY_MODEL | Subagent |
|---|---|---|
| W3.A — P-MEGA-08 | **Composer routine** | `foodking-routine-implementer` |
| W3.B — P-MEGA-09 | **Composer routine** | `foodking-routine-implementer` |

**Justification routing**: les deux cycles touchent exclusivement `resources/js/**` (helpers + components Vue + store Vuex local) et `tests/js/**` (Vitest). Aucune logique pricing, aucune logique `OrderService`/`FrontendOrderService`, aucun touch à la DB schema, aucun `branch_id` filter, aucun dispatch backend. C'est du wiring front + helper pur → routinière selon `.cursor/routing.md` (CRUD/UI/scaffold). GPT-5.4 complex serait surdimensionné.

**Exceptions levées dans ESCALATION ci-dessous** (snapshot allergènes côté backend) : ces extensions **ne sont pas dans le scope EXECUTE W3** et nécessiteront un cycle dédié (gate + GPT-5.4) si la décision business est de fermer la boucle bout-en-bout.

## Découpage cycles

**Décision**: 2 cycles EXECUTE séquentiels, **pas de fusion**.

Justification quantitative:
- P-MEGA-08 ≈ 90 LOC impl + 70 LOC tests ≈ 160 LOC total
- P-MEGA-09 ≈ 130 LOC impl + 100 LOC tests ≈ 230 LOC total
- **Total impl seul ≈ 220 LOC** > seuil 200 LOC → split obligatoire
- Surfaces de test orthogonales (allergènes vs filter persistance) → pas de mutualisation

Ordre obligatoire: **P-MEGA-08 d'abord** (helper `mergeAllergens` est consommé par le badge dans le wizard, donc requis avant le filtre P-MEGA-09 qui exclut sur allergens calculés au niveau variation).

---

## SUBSYSTEMS_TOUCHED

### Cycle W3.A — P-MEGA-08 (allergens propagation)

| Subsystem | Path(s) | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| Helper allergens kiosk | `resources/js/helpers/kioskFilters.js` | **Write** (extend) | No | No |
| Composant badge | `resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue` | **Write** (props extension) | No | No |
| Wizard kiosk (consume) | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | **Write** (récap + cart line) | No | No |
| Cart kiosk (consume) | `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (si présent) | **Write** (badge call) | No | No |
| Resources backend (mapping audit) | `app/Http/Resources/OrderItemResource.php`, `app/Http/Resources/NormalItemResource.php`, `app/Http/Resources/ItemVariationResource.php`, `app/Http/Resources/ItemExtraResource.php` | **Read-only** | No | No |
| Service backend (mapping audit) | `app/Services/Orders/OrderItemAllergenSnapshot.php`, `app/Services/FrontendOrderService.php::hydrateAllergenSnapshots` | **Read-only** | No | No |
| Tests | `tests/js/kioskAllergenMerge.spec.js` (NEW), extension `tests/js/kioskAllergenBadge.spec.js` si présent | **Write** | No | No |

### Cycle W3.B — P-MEGA-09 (filtre allergène persistant)

| Subsystem | Path(s) | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| Store Vuex filtres | `resources/js/store/modules/kioskFilter.js` (NEW) | **Write** (create) | No | No |
| Store index | `resources/js/store/index.js` | **Write** (registration only) | No | No |
| Catalogue kiosk | `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | **Write** (greyed-out + bandeau + persistance) | No | No |
| Wizard kiosk (variation grey-out) | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`, `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`, `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue`, `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` | **Write** (filter pass-through + disabled state) | No | No |
| Helper | `resources/js/helpers/kioskFilters.js` | **Write** (extend `isVariationAllowedByFilters`) | No | No |
| Tests | `tests/js/kioskFilterPersist.spec.js` (NEW) | **Write** | No | No |

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php` — invariant 5 (symmetry) — pas touché
- `app/Services/FrontendOrderService.php` — invariant 5 + invariant 4 (dispatch) — read-only mapping uniquement
- `database/migrations/**` — schema invariant — pas touché
- `app/Http/Middleware/Auth*` — pas touché
- Tout `app/Http/Controllers/**` — pas touché
- `resources/js/services/*` (axios, websocket) — pas touché
- Pricing: aucune logique de prix touchée (invariant 1 SSOT respecté — le filtre n'altère que l'affichage, pas le total)

## INVARIANTS_AT_RISK

| # | Invariant | Statut | Justification |
|---|---|---|---|
| 1 | Backend pricing SSOT | **OK** | Filtre n'altère pas les prix; helper allergens additif côté display |
| 2 | OrderStatus enum | N/A | Pas de logique status dans le scope |
| 3 | branch_id isolation | **OK** | Pas de query/mutation dans le scope |
| 4 | Dispatch after commit | N/A | Pas de dispatch dans le scope |
| 5 | OrderService/FrontendOrderService symmetry | **AT RISK BUT DEFERRED** | Voir ESCALATION : un fix back complet (snapshot enrichi par variations+extras) toucherait `FrontendOrderService::hydrateAllergenSnapshots` ET `OrderItemAllergenSnapshot::hydrate` symétriquement. **Out of scope W3** — gate dédié si confirmé. Le test parité rouge sera produit ici comme sentinelle. |
| 6 | Frozen zones | **OK** | Aucun fichier frozen touché (front kiosk + helper + Vuex module + tests) |

## GATE_CONDITIONS

- **Aucune gate humaine anticipée pour les deux cycles EXECUTE W3** (front + tests, zone safe).
- **Gate potentielle ouverte indirectement** par le test parité allergens (cycle W3.A): si le test échoue côté backend (snapshot omet variations/extras), il devient un **finding** à logguer dans le report. Le finding lui-même n'ouvre pas de gate (pas de fix back tenté). Une issue de remédiation backend pourra être ouverte hors-cycle si la direction le décide.

## SYMMETRY_NOTE

`OrderService::posOrderStore` et `FrontendOrderService::storeOrderItems` partagent tous deux le pattern `hydrateAllergenSnapshots()` qui ne résout les allergènes que depuis `Item.allergens` (pivot). **Aucun des deux ne consulte les variations sélectionnées ni les extras commandés.** Tout fix réel (W3 hors scope) devrait modifier symétriquement les deux services + leurs deux helpers (`OrderItemAllergenSnapshot::resolveSnapshot` et `FrontendOrderService::resolveAllergenSnapshot`). **Status W3**: documenté comme finding sentinel; pas de write back.

---

## Stratégie de test

| Cycle | Stratégie | Vocabulaire actif |
|---|---|---|
| W3.A | `local-validation` | Vitest 8 cas + 1 PHPUnit sentinel rouge documentant le gap snapshot back |
| W3.B | `local-validation` | Vitest 6 cas (persistance + greyout + bandeau + clavier) |

Pas de Playwright sur ce cycle — surfaces UI déjà couvertes par specs admin/POS existants; le gain marginal d'un E2E ne justifie pas le coût de stabilisation pour W3.

---

## P-MEGA-08 — Détail cycle W3.A

### Contexte
Aujourd'hui `KsAllergenBadge` reçoit `:allergens="item.allergens"` figé au mount. Si l'utilisateur ajoute l'extra "fromage" (lait) à un Tacos boeuf (sans allergène), le badge ne se met PAS à jour, et le snapshot backend `allergens_snapshot` n'inclut PAS le fromage non plus (vérifié dans `OrderItemAllergenSnapshot::resolveSnapshot` — ne lit que `item->allergens`).

### Audit (déjà fait par cet appel — résumé)
- `KsAllergenBadge.vue:81-83` accepte un prop `allergens: Array` simple — pas de notion de selections.
- `kioskFilters.js::extractAllergenCodes` opère sur un `item` unique, pas sur un cart line composé.
- Backend: `FrontendOrderService::resolveAllergenSnapshot` (l.754) et `OrderItemAllergenSnapshot::resolveSnapshot` (l.64) ne consultent que `Item.allergens` — ignorent variations + extras.
- `ItemVariationResource` et `ItemExtraResource` n'exposent **pas** d'allergens (drift confirmé par l'audit P-MEGA-23 livré en parallèle).

### Fix front (in-scope)

1. **Helper `mergeAllergens(item, selectedVariations, selectedExtras, opts)`** dans `resources/js/helpers/kioskFilters.js`:
   - Inputs: `item` (avec `allergens[]`), `selectedVariations` (array d'objets variation), `selectedExtras` (array d'objets extra avec qty)
   - Output: array dédupliqué de codes allergènes FR (UE 1169/2011 — codes canoniques `gluten`, `lait`, `oeufs`, ...)
   - Tolérant aux objets sans `allergens` (variations/extras qui n'exposent pas encore le champ → traités comme {} → no-op)
   - Utilise `extractAllergenCodes` existant en interne pour chaque source
2. **Étendre `KsAllergenBadge.vue`** avec un prop optionnel `:selections="{...}"` et computed `effectiveAllergens` qui appelle `mergeAllergens(item, selections.variations, selections.extras)` si `selections` fourni, sinon fallback comportement actuel (rétrocompat).
3. **Brancher dans `KioskWizardComponent.vue`** récap + dans `KioskCartComponent.vue` (cart line):
   - Récap wizard: `:item="resolvedItem" :selections="selections"` au lieu de `:allergens="resolvedItem.allergens"`
   - Cart line: idem, mais en s'appuyant sur le snapshot `cart.line.item_variations` + `cart.line.item_extras` déjà persistés en local

### Tests Vitest (8 cas — local-validation)

Fichier `tests/js/kioskAllergenMerge.spec.js`:
1. Item sans allergènes + 0 extras → []
2. Item `[gluten]` + 0 extras → `[gluten]`
3. Item `[]` + extra fromage `[lait]` → `[lait]`
4. Item `[gluten]` + extra fromage `[lait]` → `[gluten, lait]` (dédupliqué + ordre canonique)
5. Item `[gluten]` + extra fromage `[lait]` qty=2 → `[gluten, lait]` (qty n'ajoute pas de doublon)
6. Item `[gluten]` + variation pain `[gluten]` → `[gluten]` (dédup)
7. Variations/extras sans champ `allergens` (drift back) → silencieux, pas de crash
8. `selections.variations` undefined → fallback rétrocompat = `item.allergens` seul

### Test sentinel back (PHPUnit, peut rester rouge)

Fichier `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php` (NEW):
- Crée un order avec 1 item base sans allergens + 1 extra avec allergens `[lait]`
- Asserte `$orderItem->allergens_snapshot === ['lait']`
- **Si rouge**: documente le gap dans le report sous `FINDING — backend snapshot does not merge variations/extras allergens`. Cycle W3.A reste **CLOSED PASSED** (8 Vitest verts) avec la mention `FINDING_BACK_DEFERRED`.

> Décision documentée: ce test sentinel ne bloque **pas** le verdict W3.A — sa raison d'être est de matérialiser la dette backend pour qu'un futur cycle (gate + GPT-5.4 + symmetry note résolue) la close. Le verdict W3.A repose uniquement sur les Vitest.

### Acceptance W3.A
- [ ] 8 Vitest verts dans `kioskAllergenMerge.spec.js`
- [ ] `KsAllergenBadge.vue` rétrocompatible: appelé sans `:selections` → comportement actuel inchangé (regression check via test snapshot existant si présent)
- [ ] Récap wizard et cart line affichent `lait` quand on ajoute fromage à Tacos boeuf
- [ ] Test sentinel back créé (rouge ou vert, peu importe) + finding documenté
- [ ] 0 régression sur la suite Vitest globale (521/521 doivent rester verts → 529/529)

### Risques W3.A
- **Faible**. Helper additif + extension de prop optionnelle. Le seul point d'attention: ne pas casser le contrat actuel `:allergens="item.allergens"` consommé ailleurs (audit grep avant fix par le subagent).

### Estimation LOC W3.A
- Impl: ~90 LOC (helper +30, badge +20, wizard +20, cart +20)
- Tests: ~70 LOC (Vitest +50, PHPUnit sentinel +20)

---

## P-MEGA-09 — Détail cycle W3.B

### Contexte
Aujourd'hui dans `KioskCategoriesComponent.vue:355` `activeFilters: []` est une **propriété locale du composant** — elle est perdue dès qu'on quitte la vue catégories (vers wizard, cart, payment). Au retour, l'utilisateur doit re-cliquer son filtre. Pire: dans le wizard, les variations contenant l'allergène filtré restent cliquables.

### Audit (déjà fait par cet appel — résumé)
- `applyKioskFilters` opère sur la liste d'items, OK.
- `KIOSK_FILTERS` inclut `gluten_free`, `vegetarian`, `halal`, `pork_free`, `spicy`, `under_10`. Note: les flags `is_*` côté `Item` existent en BD (`app/Models/Item.php:32-36`) mais **NE SONT PAS exposés par `NormalItemResource`** (drift documenté dans audit P-MEGA-23, livré en parallèle). Conséquence: cycle W3.B met en place le **rail UI** correctement, mais le filtre restera cosmétique tant que la resource n'expose pas les flags. C'est un **finding séparé**, pas un blocker W3.B (le test mock injectera les flags).
- Aucun composant ne lit un store global pour les filtres → store à créer.

### Fix front (in-scope)

1. **Store Vuex `kioskFilter`** (`resources/js/store/modules/kioskFilter.js`):
   - State: `{ activeFilters: [], customerAllergens: [], hydrated: false }`
   - Mutations: `SET_FILTERS`, `TOGGLE_FILTER`, `RESET`, `SET_CUSTOMER_ALLERGENS`
   - Actions: `init` (lecture localStorage `kiosk:filters` + `kiosk:customer_allergens`), `toggle`, `reset`, `setCustomerAllergens`
   - Plugin de persistance: souscrit aux mutations et écrit dans localStorage (try/catch silencieux pour mode privé)
   - Garde-fou: `KIOSK_FILTERS.includes()` validate avant set
2. **Enregistrement** dans `resources/js/store/index.js` (1 ligne).
3. **`KioskCategoriesComponent.vue`** : 
   - Remplace `data.activeFilters` par `mapState('kioskFilter', ['activeFilters'])` + `mapMutations`.
   - Au mount, `dispatch('kioskFilter/init')`.
   - Bandeau permanent `<KsActiveFilterBanner>` (créé inline ou helper component) en haut, visible si `activeFilters.length > 0`.
   - **Greyed-out au lieu de masquage**: la liste produits ne `applyKioskFilters` plus pour cacher; à la place, `mapDisabled = applyKioskFilters(items, filters)` retourne items autorisés, et le composant carte applique `:class="{ disabled: !allowedSet.has(product.id) }"` + `:aria-disabled="true"` + `tabindex="-1"` + tooltip `:title="$t('kiosk.catalog.filtered_out_reason', {filter: ...})"`. Le `@click` no-op si disabled.
4. **Wizard `KioskWizardComponent.vue` + 4 steps** (`KioskStepViandeComponent`, `KioskStepSauceComponent`, `KioskStepGarnituresComponent`, `KioskStepSupplementsComponent`):
   - Helper additionnel `isVariationAllowedByFilters(variation, filters)` dans `kioskFilters.js`.
   - Chaque step boucle sur ses variations: si `!isVariationAllowedByFilters(v, activeFilters)` → render avec `:class="{ 'kiosk-disabled': true }"` + bouton `+`/click désactivé + tooltip i18n + sortable focus.
   - Pas de scroll-jump: pas de `v-if` qui supprime le DOM, on garde l'item visible mais grisé (cohérent W3.B).

### Tests Vitest (6 cas — local-validation)

Fichier `tests/js/kioskFilterPersist.spec.js`:
1. `init()` lit localStorage existant et hydrate state
2. `toggle('gluten_free')` ajoute, second `toggle` retire, persisté
3. `reset()` vide state + localStorage
4. localStorage corrompu → fallback à `[]` sans crash
5. Mode privé (localStorage throw) → no-op silencieux
6. Filtre dans grid: produit sans flag `is_gluten_free` reçoit `class.disabled` mais reste **présent dans le DOM** (pas de v-if), tooltip présent

> Tests greyout de variations dans wizard: 1 case minimum (variation pain blanc filtrée par `gluten_free` → disabled + tooltip + click no-op). Comptés dans les 6.

### Acceptance W3.B
- [ ] 6 Vitest verts dans `kioskFilterPersist.spec.js`
- [ ] Filtre actif persiste après navigation `Catalog → Wizard → Cart → Catalog` (validé via store mocked + reload simulé)
- [ ] Bandeau visible permanent en haut quand `activeFilters.length > 0`, avec bouton "Tout effacer"
- [ ] 0 produit `display:none` ni `v-if` masqué (garde clavier + a11y)
- [ ] 0 régression Vitest globale → 535/535 (529 + 6)

### Risques W3.B
- **Faible-moyen**. La couche store + persistance est nouvelle mais isolée. Le risque principal est régression visuelle sur le grid (carte disabled style). Mitigation: classe CSS dédiée additive, pas de modification du `kiosk-product-card` baseline.
- **Finding doublé**: le filtre devient cosmétique tant que `NormalItemResource` n'expose pas les `is_*` flags (drift P-MEGA-23). À documenter dans le report comme `FINDING_RESOURCE_FLAGS_DEFERRED`.

### Estimation LOC W3.B
- Impl: ~130 LOC (store +60, wizard/steps +40, catégories +20, helper +10)
- Tests: ~100 LOC

---

## Execution Steps (résumé orchestrateur)

### Cycle W3.A — `RUN_P_MEGA_W3_A_ALLERGEN_PROPAGATION_2026-04-20`
1. Orchestrateur ouvre TASK_ID = `P_MEGA_W3_A_ALLERGEN_PROPAGATION_2026-04-20`, mode single-session.
2. Délégation `foodking-routine-implementer` (Composer) avec ce plan + détail W3.A.
3. Subagent: implémente `mergeAllergens` + extend `KsAllergenBadge` + branche wizard/cart + écrit Vitest + crée test sentinel PHPUnit.
4. Validate Composer: `npm test -- kioskAllergenMerge` + suite globale Vitest.
5. Audit Claude: vérifier rétrocompat, noter finding back si test sentinel rouge.
6. Close PASSED ou loop remediation per `auto-remediation.mdc`.

### Cycle W3.B — `RUN_P_MEGA_W3_B_FILTER_PERSIST_2026-04-20`
1. Orchestrateur ouvre TASK_ID = `P_MEGA_W3_B_FILTER_PERSIST_2026-04-20`.
2. Délégation `foodking-routine-implementer` (Composer).
3. Subagent: crée store kioskFilter + persiste + bandeau + greyout grid + greyout variations + Vitest.
4. Validate + Audit + Close.

### Synthèse W3
Après les 2 cycles closed, l'orchestrateur produit `reports/execution/SYNTHESE_P_MEGA_W3_2026-04-20.md` (modèle SYNTHESE existant) listant les findings W3.A + W3.B et leur impact sur les vagues suivantes.

---

## ESCALATION

Pré-déclarée (à honorer par le subagent EXECUTE):

1. **Snapshot backend allergens (variations + extras)** — `FrontendOrderService::resolveAllergenSnapshot` + `OrderItemAllergenSnapshot::resolveSnapshot`. Hors scope W3 car invariant 5 symmetry + dispatch invariant si réécriture du chemin order item insert. À ouvrir comme cycle dédié `P_MEGA_W3_C_BACKEND_SNAPSHOT_ENRICHMENT` avec PRIMARY_MODEL = GPT-5.4 complex + SYMMETRY_NOTE complète si la direction confirme.

2. **Resources back exposant les flags `is_*` filterables** — `NormalItemResource` n'expose pas `is_vegetarian/is_halal/is_pork_free/is_gluten_free/is_spicy`. Hors scope W3.B. Couvert nativement par P-MEGA-23 (admin/kiosk drift) et son cycle EXECUTE futur.

3. **Resources back exposant `allergens` sur `ItemVariation` et `ItemExtra`** — drift identique. Hors scope W3.A. Couvert nativement par P-MEGA-08 cycle backend (#1 ci-dessus) ou P-MEGA-23.

Tout autre besoin scope > scope W3 = **STOP + ESCALATION** standard.

## SCOPE_PRESSURE
[À remplir mid-cycle si le subagent rencontre une pression scope.]

## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_[TASK_ID]_[DATE].md`
