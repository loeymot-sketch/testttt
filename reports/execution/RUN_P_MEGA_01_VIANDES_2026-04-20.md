# RUN_P_MEGA_01 — Fix bug "Tacos M / Méga / Famille → 1 viande seulement"

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-01 (vague 1 — wizard logic)  
**Verdict** : **CLOSED — POSITIF**  
**Mode** : single-session, auto-remediation, 0 gate humain (hors zone critique)

## Symptôme rapporté (utilisateur)

> « un problem de logique je trouve lors de choisir un tacos 2 ou 3 ou 4 viandes j'ai le droit de choisir qu'une viande ! alors je peut choisir une ou miste et 3 de un et un de l'autre... »

## Cause racine confirmée par lecture code

`KioskWizardComponent.vue` exposait **trois fonctions** indépendantes pour piloter le step Viande :

| Fonction | Rôle | Regex employée |
|---|---|---|
| `detectViandeCount()` | nombre de viandes max | `\b\d+\s*viandes?\b` + `\bxxl\b`/`\bxl\b`/`\bl\b` |
| `shouldAskTacosTaille()` | afficher l'étape Taille ? | `'tacos m'`/`'tacos l'`/`'xl'`/`'xxl'`/`'1..4 viande'` |
| `inferTacosPresetMeta()` | label affiché | `xxl`/`xl`/`tacos l`/`tacos m` |

**Désalignement** : pour un libellé courant tel que `Tacos Méga`, `Tacos Famille`, `Tacos M` :

- `shouldAskTacosTaille` peut **matcher** (skip step Taille) parce que la sous-chaîne `tacos m` apparaît
- `detectViandeCount` **ne matche aucune** des regex digit / lettre → fallback `return 1`
- Conséquence côté `KioskStepViandeComponent` : `maxViandes = _tailleMeta?.viandeCount || 1` → bouton `+` désactivé après 1 sélection

C'est exactement le symptôme rapporté.

## Fix appliqué

### 1. Helper SSOT créé : `resources/js/helpers/kioskTacosSize.js`

Centralise la table de tailles et la dérivation `name → viandeCount` dans un seul module :

- `SIZE_TO_VIANDE_COUNT = { M:1, L:2, XL:3, XXL:4, MEGA:4, FAMILLE:4 }` (frozen)
- `detectTacosSize(name)` → renvoie `'M'|'L'|...|'FAMILLE'|null`
- `viandeCountFromName(name)` → renvoie `number|null` (jamais `1` silencieux)
- `hasPresetSizeInName(name)` → renvoie `boolean`
- `tacosSizeLabel(name)` → renvoie le label humain (`'Méga'`, `'XL'`, `'3 viandes'`, ...)

L'ordre des regex matche `XXL` avant `XL` pour éviter une fausse capture.

### 2. Composant refactoré : `KioskWizardComponent.vue`

`detectViandeCount()` adopte une cascade explicite à 4 niveaux :

1. `selections._tailleMeta?.viandeCount` (sélection utilisateur)
2. `item.viande_count` (futur champ serveur P-MEGA-23)
3. `viandeCountFromName(item.name)` (helper SSOT)
4. Fallback `1` **avec analytics** `wizard.viande_count_fallback` pour le rendre observable

`shouldAskTacosTaille()` n'utilise plus de sous-chaînes manuelles : délègue à `hasPresetSizeInName()` (cohérent avec `detectViandeCount`). Quand `item.viande_count` est exposé serveur, on ne demande pas la taille (info déjà disponible).

`inferTacosPresetMeta()` n'utilise plus de regex dupliquée : délègue à `detectTacosSize()` + `tacosSizeLabel()`. La meta retournée gagne un champ `size` pour l'affichage et le tri analytics.

### 3. Tests garde-fou

#### `tests/js/kioskTacosSize.spec.js` (nouveau, 46 tests)

- 14 cas `detectTacosSize` (M, L, XL, XXL, Méga, Mega, Famille, casse mixte, vide, null)
- 12 cas `viandeCountFromName` (incluant priorité digit > lettre, bug-cases reproduits)
- 9 cas `hasPresetSizeInName`
- 8 cas `tacosSizeLabel`
- 3 cas explicites pour le bug original (`Tacos M`, `Tacos Méga`, `Tacos Famille` ne retombent plus à 1 silencieux)

#### `tests/js/KioskWizard.spec.js` (étendu, +15 tests)

Tests **sur le vrai composant** (pas le mock local qui était désaligné) :

- 10 cas `detectViandeCount` matrix (Tacos M / L / XL / XXL / Méga / Famille / 2 viandes / 3 viandes / 4 viandes / digit prime sur lettre)
- 1 test `shouldAskTacosTaille = false` quand taille reconnue
- 1 test `shouldAskTacosTaille = true` sur libellé bordelin (sécurité : on demande Taille plutôt que de retomber à 1)
- 1 test `item.viande_count` serveur prime sur tout (anticipation P-MEGA-23)
- 1 test `selections._tailleMeta.viandeCount` prime sur l'heuristique
- 1 test `inferTacosPresetMeta` retourne `{ viandeCount: 4, label: 'Méga', size: 'MEGA' }`

## Métriques

| Mesure | Avant | Après | Delta |
|---|---:|---:|---:|
| Tests Vitest totaux | 413 | **495** | +82 |
| Tests dédiés au bug | 0 | **61** | +61 |
| Régressions | — | **0** | — |
| Régex dupliquées (taille) | 3 | **0** | −3 |
| Modules | — | +1 (`kioskTacosSize.js`) | — |
| LOC nouvelles | — | ~340 (helper + tests + glue) | — |

## Validation

```
Test Files  57 passed (57)
Tests  495 passed (495)
Duration  4.68s
```

## Risques / suite

- **Risque résiduel** : `wizard.viande_count_fallback` peut être tracé en prod si l'admin ajoute un libellé encore non-reconnu. C'est volontaire — c'est une **alerte douce** plutôt qu'une dégradation silencieuse. À surveiller dans P-MEGA-15 (a11y / observabilité).
- **Suite logique** : P-MEGA-02 (cousins du même bug — sauces, garnitures, suppléments, boissons) puis P-MEGA-23 (drift admin : exposer `viande_count` côté serveur pour éliminer définitivement l'heuristique).

## Conformité

- ✅ Aucune zone critique touchée (front kiosk uniquement, helper pur sans I/O)
- ✅ Test garde-fou ajouté avant le fix (TDD + reproduction du bug)
- ✅ Pas de régression sur 495 tests Vitest
- ✅ Commit atomique séparé du WIP P11/P12/P13 parallèle
- ✅ Cohérent avec auto-remediation.mdc (RUNNER_MODE single-session)
