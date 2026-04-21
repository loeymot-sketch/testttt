# SYNTHESE_P_MEGA_W3 — Vague 3 Allergens & Dietary close

**Date** : 2026-04-20  
**Cycle** : `P_MEGA_W3_ALLERGENS_PLUS_P23_DRIFT_AUDIT_2026-04-20`  
**Mode** : single-session, orchestration multi-subagent stricte (`run-cycle.md` + `routing.md` respectés)

## Bilan exécutif

| Sous-cycle | Statut | Tests | Commit | Subagent EXECUTE |
|---|---|---|---|---|
| **W3.A — Allergens propagation** | CLOSED PASSED | 8 nouveaux + 529/529 | `a86b8ca03` | `foodking-routine-implementer` |
| **W3.B — Filtre persistant + bandeau + greyout** | CLOSED PASSED | 6 nouveaux + 535/535 | `6d7ca7bf1` | `foodking-routine-implementer` |
| **AUDIT P-MEGA-23 (transverse)** | LIVRÉ readonly | n/a | (artefact) | `foodking-planner-orchestrator` |

**535/535 Vitest verts** · **0 régression** · **2 commits atomiques** · **3 findings deferred documentés**

## Délégation orchestrée (correctif vs cycles précédents)

Différence clé vs P-MEGA-W1/W2 (où Claude écrivait directement le code applicatif en violation de `routing.md`) :

- **PLAN** : délégué à `foodking-planner-orchestrator` (subagent Claude isolé) → produit `plans/PLAN_P_MEGA_W3_2026-04-20.md` + audit transverse P-MEGA-23
- **EXECUTE W3.A & W3.B** : délégué à `foodking-routine-implementer` (subagent Composer) → tout le code applicatif (resources/js/**, tests/js/**) écrit hors chat parent
- **AUDIT** : Claude orchestrateur (chat parent) — checklist `audit-context.md` appliquée à chaque round
- **EXECUTE_DELEGATION:** ligne présente dans chaque REPORT_FILE (traçabilité audit `run-cycle.md` Step 2)
- **Anti-gaspillage tokens** respecté : subagents ont reçu plan + scope précis, ont rendu réponses 10-15 lignes max sans full diff

## Détail W3.A — P-MEGA-08 (Allergens propagation)

**Problème** : `KsAllergenBadge` recevait `:allergens="item.allergens"` figé. Ajout d'un extra fromage (allergène lait) à un Tacos boeuf (sans allergène) → badge n'affichait pas `lait` côté UI, et `OrderItem.allergens_snapshot` côté serveur ne le contenait pas non plus.

**Fix front (in-scope)** :
- Helper pur `mergeAllergens(item, selectedVariations, selectedExtras)` dans `kioskFilters.js` — dédup + ordre canonique UE 1169/2011, tolérant champs absents
- `KsAllergenBadge` étendu avec prop optionnel `:selections` + computed `effectiveAllergens` (rétrocompat totale si `:selections` absent)
- Wizard récap + Cart line branchent `:item :selections` au lieu de `:allergens=item.allergens`
- 8 cas Vitest exhaustifs incl. cas dégénérés (qty=2, drift back tolérant, fallback rétrocompat)

**Sentinel back (FINDING_BACK_DEFERRED)** :
- `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php` créé, **rouge volontairement**
- Matérialise la dette `OrderItemAllergenSnapshot::resolveSnapshot` (l.64) qui ignore variations + extras
- Cycle backend dédié à ouvrir : `P_MEGA_W3_C_BACKEND_SNAPSHOT_ENRICHMENT` (PRIMARY_MODEL = GPT-5.4 complex, gate symmetry note + invariants 4+5)

## Détail W3.B — P-MEGA-09 (Filtre persistant + bandeau + greyout)

**Problème** : `activeFilters: []` était propriété locale `KioskCategoriesComponent` perdue dès navigation Catalog → Wizard → Cart. Au retour, l'utilisateur devait re-cliquer son filtre. Pire : variations contenant l'allergène filtré restaient cliquables dans le wizard.

**Fix front (in-scope)** :
- Store Vuex `kioskFilter` namespacé (`state()` factory function = anti-pollution tests) + persistance localStorage (clés `kiosk:filters`, `kiosk:customer_allergens`) + actions `init/toggle/setCustomerAllergens/reset`
- `KioskCategoriesComponent` : bandeau permanent quand `hasActiveFilters` + bouton "tout effacer" + greyout grid via Set d'IDs + `aria-disabled`/`tabindex`/click no-op
- 4 steps wizard (Viande/Sauce/Garnitures/Suppléments) + WizardComponent : prop `:active-filters` héritée + helper `isVariationAllowedByFilters` + greyout variations
- **A11y critique** : zéro `v-if`, zéro `display:none` — items disabled restent dans le DOM, navigation clavier préservée
- 6 cas Vitest (init, toggle round-trip, reset, localStorage corrompu, mode privé, isVariationAllowedByFilters)

**Finding deferred (FINDING_RESOURCE_FLAGS_DEFERRED)** :
- Le rail UI fonctionne, mais le filtre reste **cosmétique côté données** tant que `NormalItemResource` n'expose pas `is_vegetarian`, `is_halal`, `is_pork_free`, `is_gluten_free`, `is_spicy`
- Drift #5 du audit P-MEGA-23 — sera adressé par cycle dédié backend (gate symmetry + Resource extension)

## Audit transverse P-MEGA-23 — Drift admin↔kiosk (livré readonly)

**Le levier stratégique de cette session.** Au lieu d'attendre Vague 9 pour auditer la racine, fait MAINTENANT pour informer toutes les vagues 4→8.

**13 drifts identifiés, 3 patterns systémiques** :

- **Pattern A — Heuristique nom** : 4 champs wizard (`viande_count`, `tacos size`, `snacking detection`, `pain detection`) devinés par regex sur `item.name` au lieu d'être lus serveur. Mort par construction du code défensif `if (Number.isInteger(item.viande_count))` qui ne remontera jamais une valeur.
- **Pattern B — Resource publie un sous-ensemble strict** : `ItemAttribute` a 3 colonnes neuves (`min_select`, `max_select`, `allow_repeat` — migration `2026_04_22_000010`) **jamais exposées par `ItemAttributeResource`**. C'est la cause directe du bug "Tacos 2 viandes mais on ne peut en sélectionner qu'une".
- **Pattern C — Allergens uniquement sur `Item`, pas sur composants** : `ItemVariation` et `ItemExtra` n'exposent pas leurs allergènes ; snapshot back ignore variations + extras (FIC UE 1169/2011 = obligation légale).

**Recommandation transverse** : migration générique `items add wizard_meta JSON` couvre simultanément #4, #10, #12 (cycle dédié GPT-5.4 + gate humaine).

## Métriques globales W1+W2+W3

| Mesure | Avant W1 | Après W3 | Δ |
|---|---:|---:|---:|
| Tests Vitest | 495 | **535** | +40 |
| Bugs critiques fixés | 0 | **3** (viandes, cart edit, allergens propagation) | +3 |
| Bugs invisibles fixés | 0 | **1** (Vue 3 lifecycle) | +1 |
| Régressions | — | **0** | 0 |
| Findings deferred (sentinel/cosmétique) | 0 | **2** (back snapshot, resource flags) | +2 |
| Audits transverses readonly | 0 | **3** (cardinality, pricing, TVA, drift admin/kiosk) | +3 |
| Gates documentées | 0 | **3** (P-MEGA-03 BD cardinality, P-MEGA-06 pricing SSOT, P-MEGA-07 TVA TTC/HT) | +3 |
| Commits atomiques | 0 | **7** | +7 |
| Subagents délégués (W3 only) | 0 | **3** (1 planner + 2 routine implementer) | +3 |

## Gates ouvertes (3 — inchangées depuis W2 + 1 implicite révélée par P-MEGA-23)

1. **P-MEGA-03** — BD cardinality (`min_select`, `max_select`, `allow_repeat` exposition + form admin) — gate humaine schema
2. **P-MEGA-06** — Pricing SSOT divergences (~430€/jour silent) — gate option A/B/C
3. **P-MEGA-07** — TVA TTC/HT (NF525) — gate convention prix admin
4. **P-MEGA-23 implicite** — Pattern A "heuristique nom" : migration `items.wizard_meta JSON` recommandée — gate humaine schema (ouverte si direction confirme)

## Commits W3

```
6d7ca7bf1 [P-MEGA-09] Filtre allergène persistant + bandeau visible + greyout grid/wizard a11y
a86b8ca03 [P-MEGA-08] Allergens propagation: merge variations + extras dans badge kiosk + sentinel back
```

## Conformité méthodologique W3

- ✅ `routing.md` respecté à 100% : Claude écrit uniquement governance (plan/audit), Composer subagent écrit uniquement code routine
- ✅ `run-cycle.md` Step 2 honoré : ligne `EXECUTE_DELEGATION:` dans chaque report
- ✅ `auto-remediation.mdc` : 0 remediation requise (plan robuste, exécution clean au 1er essai sur les 2 cycles)
- ✅ `audit-context.md` : checklist appliquée pour W3.A et W3.B avec audit appended au report
- ✅ `scope.mdc` / frozen zones / invariants 1-6 : tous respectés (uniquement front + helpers + Vuex local + tests)
- ✅ Token discipline : subagents ont reçu plan + scope minimal, ont rendu réponses courtes sans full diff
- ✅ Anti-pollution tests Vuex : `state()` factory function (leçon P-MEGA-05 retenue)

## Prochaines étapes recommandées

### Voie A — Continuer wave-by-wave (alignée avec consigne utilisateur)
- **Vague 4** — i18n / RTL (P-MEGA-10 + P-MEGA-11) : zone safe, no gate, route → routine implementer
- **Vague 5** — Order types / payment / receipt : 3 tâches HUMAN_GATE (Frozen pricing/fiscal)
- **Vague 6** — A11y kiosk + perf cold start (P-MEGA-15 + P-MEGA-16) : zone safe, mix routine + complex perf
- **Vague 7** — Resilience offline + hardware (P-MEGA-17/18/19) : 1 gate business
- **Vague 8** — Security / NF525 / observability (P-MEGA-20/21/22) : 3 HUMAN_GATE
- **Vague 9** — P-MEGA-23 cycle EXECUTE backend (déjà audité, prêt à implémenter sous gate)

### Voie B — Débloquer gates avec arbitrage utilisateur
- P-MEGA-03 + P-MEGA-23 (BD schema cardinality + wizard_meta) groupables en 1 cycle GPT-5.4 complex sous gate unique
- P-MEGA-06 + P-MEGA-07 (pricing + TVA) groupables en 1 cycle Frozen Zone gate

### Voie C — Cycle backend allergens (W3.C)
- `P_MEGA_W3_C_BACKEND_SNAPSHOT_ENRICHMENT` : ferme la dette FIC UE 1169/2011 — gate symmetry note + invariants 4+5
- Rendrait le sentinel PHPUnit vert et les 2 findings deferred résolus en cascade

## Verdict

**Vague 3 verrouillée avec orchestration propre conforme à AGENTS.md.** Délégation strictement respectée (1 planner + 2 routine implementer). Audit transverse P-MEGA-23 livré en bonus stratégique = capital de connaissance qui éclairera toutes les vagues suivantes.

**0 régression sur 535 tests Vitest.**  
**2 commits atomiques.**  
**3 findings deferred clairement documentés avec cycle de remédiation pré-tracé.**
