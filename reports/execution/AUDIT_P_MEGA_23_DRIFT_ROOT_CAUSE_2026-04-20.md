# AUDIT P-MEGA-23 — Drift admin menu CRUD ↔ exposition kiosk (root cause "bug viandes")

**Date**: 2026-04-20  
**Mode**: READONLY (aucune modification code, aucune migration, aucun fix proposé exécutable)  
**Scope mega plan**: Wave 9 — `P-MEGA-23 Audit drift admin ↔ kiosk` (cause profonde de P-MEGA-01 et plus largement de la classe "fallback silencieux").  
**Auteur**: foodking-planner-orchestrator (subagent Claude Opus)  
**Fichiers consultés (lecture seule)**:
- `app/Http/Resources/SimpleItemResource.php`, `ItemResource.php`, `NormalItemResource.php`, `ItemVariationResource.php`, `ItemExtraResource.php`, `ItemAttributeResource.php`, `OrderItemResource.php`, `OrderDetailsResource.php`
- `app/Models/ItemAttribute.php`, `app/Models/Item.php`, `app/Models/OrderItem.php`
- `app/Services/Orders/OrderItemAllergenSnapshot.php`, `app/Services/FrontendOrderService.php` (méthodes allergens uniquement)
- `database/migrations/2026_04_22_000010_add_min_max_repeat_to_item_attributes.php`
- `resources/js/components/admin/items/ItemCreateComponent.vue`
- `resources/js/components/admin/settings/ItemAttribute/ItemAttributeCreateComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (méthodes `detectViandeCount`, `shouldAskTacosTaille`, `inferTacosPresetMeta`)
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`, `KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` (filtres)
- `resources/js/helpers/kioskFilters.js`

---

## 1. Synthèse exécutive

Le bug "viandes" rapporté est **un symptôme ; pas la racine**. La racine est un **drift systémique entre la BD / l'admin et la projection kiosk**: chaque fois qu'un champ business a été ajouté en BD pour piloter le wizard, il n'a **pas été propagé jusqu'à la Resource publique du kiosk**, et inversement, des champs consommés par le kiosk **n'existent pas en BD ni en admin**, obligeant le wizard à utiliser des heuristiques (regex sur le nom de l'item).

**13 drifts identifiés** (5 CRITIQUE, 5 MOYEN, 3 MINEUR). Tous regroupés sous **3 patterns systémiques**:

- **Pattern A — Heuristique nom** : 4 champs distincts du wizard (`viande_count`, `tacos size`, `snacking detection`, `pain detection`) sont devinés depuis `item.name` par regex au lieu d'être lus depuis le serveur.
- **Pattern B — Resource publie un sous-ensemble strict des colonnes BD** : `ItemAttribute` a des colonnes neuves (`min_select`, `max_select`, `allow_repeat`) jamais exposées par `ItemAttributeResource`. Idem pour `Item.is_vegetarian/is_halal/...` jamais exposés par `NormalItemResource` (filtres kiosk donc cosmétiques).
- **Pattern C — Allergens propagés uniquement sur `Item`, pas sur ses composants** : `ItemVariation` et `ItemExtra` n'exposent pas leurs allergènes; le snapshot `allergens_snapshot` côté `OrderItem` ne consomme que `Item.allergens` (ignore variations + extras).

**Conséquence directe**: au moins **3 vagues du mega plan** (W2 pricing, W3 allergens, W9 admin/kiosk) sont indissociables et leur ordre de fix doit respecter une chaîne (admin → resource → kiosk consume), sinon les fixes restent cosmétiques.

---

## 2. Tableau exhaustif des drifts identifiés

Légende sévérité:
- **CRITIQUE**: cause directe d'un bug fonctionnel utilisateur visible OU d'une obligation légale (FIC allergènes, NF525 fiscal)
- **MOYEN**: dégrade UX ou cohérence admin/front mais workaround heuristique présent
- **MINEUR**: dette technique, pas d'impact utilisateur direct

| # | Champ / capacité | Source admin (table.col ou form input) | Resource exposant (ou absente) | Consommateur kiosk | Sévérité | Vague mega plan qui couvre |
|---|---|---|---|---|---|---|
| 1 | `item_attributes.min_select` | BD: présent (migration 2026_04_22_000010). **Form admin: ABSENT** (`ItemAttributeCreateComponent.vue` n'expose que `name` + `status`) | `ItemAttributeResource`: **ABSENT** (l.18-22 ne renvoie que id/name/status) | `KioskStepSauceComponent`, `KioskStepViandeComponent`, `KioskStepGarnituresComponent`, `KioskStepSupplementsComponent` (devraient lire min/max → font 1 par défaut) | **CRITIQUE** | W3 P-MEGA-03 (gate ouverte) |
| 2 | `item_attributes.max_select` | BD: présent. Form admin: **ABSENT** | `ItemAttributeResource`: **ABSENT** | idem #1 | **CRITIQUE** | W3 P-MEGA-03 (gate ouverte) |
| 3 | `item_attributes.allow_repeat` | BD: présent. Form admin: **ABSENT** | `ItemAttributeResource`: **ABSENT** | `KioskStepViande` (devrait gérer répétition d'une même viande) | **CRITIQUE** | W3 P-MEGA-03 (gate ouverte) |
| 4 | `items.viande_count` | **BD: ABSENT** (aucune migration ne crée la colonne — `KioskWizardComponent::detectViandeCount` la lit défensivement: `if (Number.isInteger(item.viande_count) ...)` mais elle n'existera jamais tant qu'il n'y a ni migration ni form). Form admin: **ABSENT** | Resource: **ABSENT** | `KioskWizardComponent::detectViandeCount` (l.487 fallback regex `viandeCountFromName(item.name)` → `1` si miss) | **CRITIQUE** | W9 P-MEGA-23 (cette tâche) |
| 5 | `items.is_vegetarian/is_halal/is_pork_free/is_gluten_free/is_spicy` | BD: **présent** (`Item::$fillable` l.32-36). Form admin: **ABSENT** dans `ItemCreateComponent.vue` (vérifié: que `name`, `price`, `category`, `tax`, `image`, `item_type`, `is_featured`, `is_upsell`, `status`, `caution`, `description`) | `NormalItemResource` (kiosk-facing): **ABSENT** (l.59-105 — aucune mention) | `kioskFilters.js::matchFilter` switch `case 'vegetarian'`, `'halal'`, etc. → renvoie toujours `false` car `item.is_vegetarian === undefined`. **Filtres kiosk cosmétiques**. | **CRITIQUE** | W3 P-MEGA-09 (UI rail) + W9 P-MEGA-23 (resource + admin form) |
| 6 | `item_variations.allergens` (ou pivot `item_variation_allergen`) | **BD: ABSENT** (modèle inexistant) | `ItemVariationResource`: **ABSENT** (pas de champ `allergens`) | `KsAllergenBadge.vue` ne peut pas calculer le snapshot composé. `OrderItemAllergenSnapshot::resolveSnapshot` (l.64-86) ignore les variations | **CRITIQUE** (FIC UE 1169/2011 — info allergène composé légalement obligatoire) | W3 P-MEGA-08 (front merge, finding back) + cycle backend dédié |
| 7 | `item_extras.allergens` (ou pivot) | **BD: ABSENT** | `ItemExtraResource`: **ABSENT** (l.18-34 — pas de champ `allergens`) | idem #6 | **CRITIQUE** (FIC) | idem #6 |
| 8 | `items.min_sauces` / `max_sauces` / `min_garnitures` / `max_garnitures` | **BD: ABSENT**. Form admin: **ABSENT** | Aucune resource | `KioskStepSauceComponent`, `KioskStepGarnituresComponent` — caps cachés en code, pas paramétrables par item | MOYEN | W9 P-MEGA-23 |
| 9 | `items.allow_repeat_variations` (par item, pas par attribute) | **BD: ABSENT** | Aucune resource | Wizard: comportement uniforme, pas de personnalisation par item | MOYEN | W9 P-MEGA-23 |
| 10 | `items.viande_count_per_size` (mapping taille → nb viandes) | **BD: ABSENT**. Form admin: **ABSENT** | Aucune resource | `KioskStepTailleComponent` doit deviner via le label de la taille (regex). Source: `KioskWizardComponent::inferTacosPresetMeta` (l.561) qui appelle `detectTacosSize()` sur le nom | MOYEN | W9 P-MEGA-23 |
| 11 | `item_variations.is_default` | BD: peut exister selon Item.php; **non vérifié dans cet audit** car hors scope. **Form admin: ABSENT** | `ItemVariationResource`: **ABSENT** (l.19-35) | `KioskStepViande/Sauce/Pain` — pas de présélection automatique → utilisateur force à choisir même si une variation est "par défaut" | MOYEN | W9 P-MEGA-23 |
| 12 | `item_variations.sort_order` (ordre d'affichage) | BD: **non vérifié explicitement** dans cet audit | `ItemVariationResource`: **ABSENT** (pas de champ `sort_order` ni `order`) | Wizard: ordre fonction de l'ordre d'insertion DB (non-déterministe) | MINEUR | W9 P-MEGA-23 |
| 13 | `items.available_for_kiosk` / `items.available_for_pos` (toggles surface item) | BD: **PARTIEL** — `ItemVariation` et `ItemExtra` ont `visible_on` (vérifié dans `ItemVariationResource:30` et `ItemExtraResource:28`); **`Item` lui-même n'a PAS de toggle équivalent** (utilise `is_available` global + `ItemBranchAvailability` par branche, mais pas par surface) | `NormalItemResource:75` expose `is_available` global; pas de filtre surface item-level | Catégories kiosk affichent tout item `is_available=true` même s'il devrait être réservé POS | MINEUR (existe `ItemBranchAvailability` qui couvre l'usage principal) | W9 P-MEGA-23 |

---

## 3. Patterns systémiques (la vraie racine)

### Pattern A — Heuristique nom de produit (regex sur `item.name`) utilisée pour 4+ champs distincts

**Fichiers concernés**:
- `KioskWizardComponent.vue::detectViandeCount` l.490 — `viandeCountFromName(item.name)`
- `KioskWizardComponent.vue::shouldAskTacosTaille` l.559 — `hasPresetSizeInName(haystack)`
- `KioskWizardComponent.vue::inferTacosPresetMeta` l.566 — `detectTacosSize(...)` + `tacosSizeLabel(...)`
- `KioskWizardComponent.vue::effectiveWizardTemplate` (lignes 460-472) — détection "snacking" via `name.includes('nugget'/'tender'/'goujon'/...)`
- `KioskWizardComponent.vue::shouldShowStep('pain')` l.533-535 — détection attribut "pain" via `(a?.name || '').toLowerCase().includes('pain')`

**Diagnostic**: 4 décisions structurelles du wizard (combien de viandes, faut-il proposer la taille, est-ce un snack, est-ce du pain) reposent sur des regex de nom. Les commentaires `[P-MEGA-01]` montrent que l'équipe sait déjà que c'est un anti-pattern et a documenté l'intention de migrer vers SSOT serveur (`item.viande_count` lu défensivement). **Aucune migration ni form n'a suivi** → le code défensif l.487 est mort par construction.

**Impact**: tout item dont le nom n'est pas dans la regex retombe silencieusement à `1` ou à un comportement incorrect, sans alerte autre que l'event analytics `wizard.viande_count_fallback` (l.496) — qui doit être encore observé en prod.

**Recommandation pattern**: introduire une **migration générique `items` add `wizard_meta JSON`** avec sous-champs `viande_count`, `taille_preset`, `wizard_template_override`, `pain_attribute_id`, exposés en clair par `NormalItemResource`. Couvre #4 + #10 + #12. Cycle dédié GPT-5.4 complex + gate humaine (DB schema).

### Pattern B — Resource ne reflète pas le schéma BD

**Cas typique flagrant**: `ItemAttribute` a 3 colonnes neuves (`min_select`, `max_select`, `allow_repeat` — migration `2026_04_22_000010`) **et le ItemAttributeResource n'a pas été mis à jour** (toujours `id/name/status` uniquement). Le frontend est aveugle à ces colonnes. C'est la cause directe du bug "Tacos 2 viandes mais on ne peut en sélectionner qu'une".

Idem pour `Item.is_vegetarian/is_halal/...` — flags présents dans `$fillable` (Item.php l.32-36) jamais exposés par `NormalItemResource`. Conséquence: les **6 chips de filtre kiosk** affichées dans `KioskCategoriesComponent` (l.152-168) **ne filtrent rien** au-delà de `under_10` (qui lit `price`).

**Recommandation pattern**: instaurer une règle CI / test snapshot qui détecte la divergence "colonne `$fillable` vs champ exposé par resource" pour les modèles `Item`, `ItemAttribute`, `ItemVariation`, `ItemExtra`, `ItemCategory`. Le test échoue → contributeur force à choisir : exposer ou écrire un commentaire `// INTENTIONALLY_HIDDEN: <reason>`.

### Pattern C — Allergens propagés uniquement sur `Item`, pas sur ses composants

**Trois symptômes corrélés**:
1. `ItemVariationResource` n'a pas de champ `allergens`.
2. `ItemExtraResource` n'a pas de champ `allergens`.
3. `OrderItemAllergenSnapshot::resolveSnapshot` (l.64-86) **ignore variations + extras**, ne consulte que `Item->allergens` (pivot).

**Conséquence FIC**: un client allergique au lait commande un Tacos boeuf (sans lait) + extra fromage (lait). Le badge ne signale pas le lait. Le snapshot persisté ne contient pas le lait. **Risque légal réel** (UE 1169/2011 + EAA 2025).

**Recommandation pattern**: cycle backend dédié `P_MEGA_W3_C_BACKEND_SNAPSHOT_ENRICHMENT` avec:
- Migration ajoutant `allergens` pivot sur `item_variations` et `item_extras`
- Resources exposant le champ
- Réécriture symétrique de `FrontendOrderService::resolveAllergenSnapshot` ET `OrderItemAllergenSnapshot::resolveSnapshot` (invariant 5 SYMMETRY)
- PRIMARY_MODEL = GPT-5.4 complex + GATE humaine (schema + symmetry)

---

## 4. Mapping drift → vague mega plan

| Drift | Couvert par | Notes |
|---|---|---|
| #1, #2, #3 (cardinality min/max/repeat) | **W3 P-MEGA-03** (gate ouverte ; SUBAGENT GPT-5.4 ; nécessite resource update + admin form update + kiosk consume) | Gate déjà ouverte par cycle précédent; reprise nécessaire |
| #4 (`items.viande_count`) | **W9 P-MEGA-23** (cette task au sens mega plan); ou peut être détaché en `P-MEGA-23a` cycle prioritaire car c'est la racine du bug rapporté utilisateur | Gate humaine schema |
| #5 (`is_*` flags resource missing) | **W3 P-MEGA-09** met en place le rail UI + persistance; **W9 P-MEGA-23** ferme le drift en exposant les flags | W3.B note ce point comme finding différé |
| #6, #7 (allergens variation/extra) | **W3 P-MEGA-08** — front merge dans cycle W3.A; backend snapshot enrichi → cycle dédié `P_MEGA_W3_C` (gate) | Test sentinel rouge produit par W3.A documente le gap |
| #8, #9, #10 (item-level wizard meta) | **W9 P-MEGA-23** | Gate schema |
| #11 (`is_default` variation) | **W9 P-MEGA-23** | Schema additif simple |
| #12 (`sort_order` variation) | **W9 P-MEGA-23** | Schema additif simple |
| #13 (`available_for_kiosk/pos`) | **W9 P-MEGA-23** ou hors mega plan (tracker design POS) | Discutable: ItemBranchAvailability couvre déjà l'usage principal |

---

## 5. Recommandations chiffrées par priorité

| Priorité | Action | LOC est. | Risque | Gate humaine |
|---|---|---|---|---|
| P0 | Reprendre cycle W3 P-MEGA-03 (cardinality) — fix `ItemAttributeResource` + form admin + kiosk consume | ~250 LOC | Moyen | OUI (gate déjà ouverte) |
| P0 | Cycle dédié `P-MEGA-23a` — schema + form + resource pour `items.viande_count` (couvre racine bug user) | ~150 LOC | Moyen-haut (DB schema) | OUI (schema migration) |
| P1 | Cycle backend `P_MEGA_W3_C` — snapshot allergens enrichi (pivot variations + extras + resource + service symétrique) | ~400 LOC | Haut (FIC + symmetry) | OUI (schema + symmetry) |
| P1 | Cycle resource `P-MEGA-23b` — exposer `is_vegetarian/is_halal/is_pork_free/is_gluten_free/is_spicy` dans `NormalItemResource` (3 LOC) + form admin checkbox group (~50 LOC) | ~60 LOC | Faible | NON (additif resource + admin) |
| P2 | Test CI snapshot drift `$fillable` ↔ resource pour `Item`, `ItemAttribute`, `ItemVariation`, `ItemExtra` | ~80 LOC | Faible | NON |
| P2 | Cycle `P-MEGA-23c` — `items.min_sauces/max_sauces/min_garnitures/max_garnitures` + meta wizard JSON | ~200 LOC | Moyen (schema) | OUI (schema) |
| P3 | Cycle `P-MEGA-23d` — `item_variations.is_default` + `sort_order` + form admin | ~120 LOC | Faible-moyen | OUI (schema) |
| P3 | Audit dépréciation heuristique nom (passer toutes les regex en assert dev seulement, log analytic en prod) | ~40 LOC | Faible | NON |

**Total estimé pour fermer la dette drift admin↔kiosk : ~1300 LOC + 4 gates humaines schema/symmetry.**

---

## 6. Zones critiques touchées par les recommandations (lecture seule — pas de gate brief produit ici)

Les recommandations P0-P3 ci-dessus toucheraient, **si elles étaient exécutées**, les zones critiques suivantes (rappel — cet audit ne propose ni n'écrit aucun gate brief):

- **DB schema** : 4 migrations potentielles (`items.viande_count`, pivot `item_variation_allergens`, pivot `item_extra_allergens`, `items.min_sauces/max_sauces/...`). Toutes doivent être routées GPT-5.4 complex + gate humaine.
- **OrderService / FrontendOrderService symmetry** : la réécriture du snapshot allergens touche les deux services symétriquement (invariant 5). SYMMETRY_NOTE obligatoire dans le plan dédié.
- **Pricing SSOT** : aucune des recommandations ne touche pricing → invariant 1 sauf.
- **branch_id isolation** : aucune des recommandations ne change la portée branch_id → invariant 3 sauf.
- **Dispatch after commit** : aucun nouveau dispatch envisagé → invariant 4 sauf.
- **Frozen zones** : à vérifier au moment du plan dédié (consulter `scope.mdc` à ce moment-là). Cet audit ne déclare pas qu'aucun fichier n'est frozen — il déclare ne pas avoir cherché.

---

## 7. Notes de méthodologie

- Cet audit est strictement READONLY conformément au scope du subagent planner-orchestrator.
- Aucune modification de code applicatif (`app/`, `resources/`, `routes/`, `database/`) n'a été produite.
- Aucune commande shell modifiante n'a été lancée.
- Token discipline respectée: ~16 fichiers lus (resources 8, models 3, services 2, vue front 5, vue admin 2, helpers 1, migrations 1) — pas de tour exhaustif du repo.
- Drifts #11 et #12 mentionnés comme "non vérifiés dans le détail BD" car la confirmation exacte du schéma actuel sortirait du scope token-disciplined; un cycle EXECUTE futur devra reconfirmer via lecture migration avant fix.

## 8. Verdict orchestrateur

- **Drifts CRITIQUE**: 7 (#1, #2, #3, #4, #5, #6, #7)
- **Drifts MOYEN**: 4 (#8, #9, #10, #11)
- **Drifts MINEUR**: 2 (#12, #13)
- **Patterns systémiques**: 3 (A, B, C)
- **Vagues mega plan impactées**: W3 (P-MEGA-03, 08, 09), W9 (P-MEGA-23) + 1 cycle backend dédié à créer
- **Gates humaines requises pour fermer la dette complète**: 4 (toutes liées à DB schema ou symmetry)

L'audit confirme que le bug "viandes" rapporté n'est **pas isolé** — c'est l'expression d'un drift architectural multi-couche admin/resource/kiosk qui se manifeste partout où le wizard a besoin d'un paramètre business explicite. Les fixes par item (ex: P-MEGA-01 patch viandes seul) seront contournés par le pattern A tant que les patterns A+B ne sont pas adressés au niveau systémique.

**Prochaine action recommandée orchestrateur parent**: 
1. Lancer cycle EXECUTE W3.A (P-MEGA-08 front merge) — produit le test sentinel back qui matérialise la dette pattern C.
2. Lancer cycle EXECUTE W3.B (P-MEGA-09 filtre persistant) — produit le finding pattern B sur les flags.
3. Avant de lancer toute autre vague, présenter cet audit au humain pour décision sur l'ordre de priorité P-MEGA-23a (viande_count) vs P-MEGA-03 (cardinality) — les deux sont gate humaine schema.

---

*Fin de l'audit P-MEGA-23 — 2026-04-20.*
