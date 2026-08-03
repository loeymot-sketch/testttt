# Kiosk Wizard User Remarks Rework — 2026-04-27

TASK_ID: KIOSK-WIZARD-REWORK-ULTRA-AUDIT-2026-04-27  
EXECUTE_DELEGATION: codex-extension  
AUDIT_SCOPE: active Vue kiosk wizard, user remarks from live browser session  
AUDIT_VERDICT: PASS_WITH_EXTERNAL_SAFETY_BLOCKER

## 1. Objectif

Corriger les remarques utilisateur non couvertes par le premier passage sur le wizard borne :

- Quantités répétées de suppléments.
- Total live qui doit évoluer dès la sélection d’options.
- Récapitulatif clair, avec quantités et boisson réelle.
- Bouton `AJOUTER AU PANIER` plus lisible et mieux priorisé.
- Compatibilité thème clair / thème sombre.
- Conservation des invariants : pricing backend SSOT, pas de backend modifié, payload panier compatible.

## 2. Audit Avant Correction

Constats dans le navigateur in-app sur `http://127.0.0.1:8000/kiosk/categories?cat=1` :

| Point audité | État avant REWORK | Risque |
| --- | --- | --- |
| Suppléments | Sélection binaire seulement, pas de `+` répété pour un même supplément | UX incomplète pour clients qui veulent deux fois fromage / œuf / autre supplément |
| Total live | Les deltas sauce/menu avaient été corrigés, mais pas la quantité multiple de suppléments | Sous-affichage si quantité répétée |
| Récap | Supplément affiché une seule fois, sans `×N` | Récap ambigu |
| Boisson centrale | Fonctionnel sur l’étape menu, mais le récap pouvait retomber sur un id si la boisson venait du catalogue central | Perte de lisibilité client |
| CTA panier | CTA présent mais pas assez priorisé sur l’étape récap | Action finale trop proche des boutons de navigation |
| Thème sombre | Tokens disponibles, à vérifier réellement dans le navigateur | Risque de contraste incomplet |

## 3. Implémentation

### 3.1 Suppléments avec quantité

Fichier : `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`

- Remplacement du choix binaire par un contrôle `- / quantité / +`.
- Normalisation rétrocompatible : `true` vaut `1`, les nombres positifs valent leur quantité.
- Limite défensive : maximum 9 unités par supplément.
- Boutons avec `aria-label` localisé.
- Suppression du parent interactif `role="checkbox"` avec boutons imbriqués ; la carte devient `role="group"`.

### 3.2 Total live et payload panier

Fichiers :

- `resources/js/helpers/kioskPricing.js`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`

Changements :

- Ajout `normalizeKioskSelectionCount()`.
- `calculateKioskRunningTotal()` multiplie désormais le prix du supplément par la quantité.
- `buildCartItem()` pousse une entrée `item_extras` par unité, comme le flux viande payante existant.
- `item_extra_total` multiplie bien par la quantité.

Invariant pricing : pas de nouvelle logique métier de prix inventée côté frontend. Le frontend applique uniquement le prix catalogue déjà présent dans `item.extras`, comme avant, mais respecte maintenant la quantité.

### 3.3 Récapitulatif

Fichier : `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`

- Section suppléments affiche maintenant le total d’unités.
- Ligne supplément affiche `×N` et le prix de ligne.
- La boisson issue du catalogue central utilise `selections._boissonMeta.boissonName`, donc le récap affiche `Capri-Sun` au lieu d’un id.

### 3.4 CTA et accessibilité

Fichier : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`

- Ajout d’un état `kiosk-nav--recap`.
- Bouton final `AJOUTER AU PANIER` plus large, plus lisible, avec `aria-label`.
- Remplacement de `transition: all` par transitions explicites.

## 4. Audit Après Correction — Navigateur In-App

Flow vérifié :

1. Ouverture borne.
2. `Sur place`.
3. `Tacos M (1 Viande)`.
4. Viande `Merguez`.
5. Sauces `Ketchup` + `Mayonnaise`.
6. Supplément `Jambon de dinde` ajouté deux fois.
7. Formule `Boisson seule`.
8. Boisson réelle `CAPRI-SUN`.
9. Récapitulatif.
10. Passage thème sombre via le bouton existant.

Résultats observés :

| Vérification | Résultat |
| --- | --- |
| Après deux sauces | `Total €7,00` |
| Après supplément `Jambon de dinde ×2` | `Total €9,00` |
| Après `Boisson seule` | `Total €10,20` |
| Liste boissons | boissons réelles visibles : `CAPRI-SUN`, `EAU PLATE 50CL`, `ORANGINA 33CL`, etc. |
| Récap supplément | `Jambon de dinde ×2`, prix ligne `+€2,00` |
| Récap boisson centrale | `Boisson : Capri-Sun` |
| CTA final | bouton unique accessible `AJOUTER AU PANIER`, plus priorisé visuellement |
| Thème sombre | wizard récap sombre lisible, contraste texte/prix/CTA OK |

## 5. Validations Automatisées

Commandes exécutées :

```bash
npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskDrinkAddons.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskViandeCatalog.spec.js
```

Résultat : `4 passed`, `117 passed`.

```bash
npm run production
```

Résultat : `Compiled Successfully`.

```bash
npx vitest run tests/js/kiosk*.spec.js tests/js/Kiosk*.spec.js
```

Résultat : `64 passed`, `557 passed`.

```bash
git diff --check -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue resources/js/helpers/kioskPricing.js resources/js/languages/fr.json resources/js/languages/en.json tests/js/KioskWizard.spec.js public/js/kiosk-wizard.js public/js/kiosk-wizard-step.js public/mix-manifest.json
```

Résultat : PASS.

```bash
rg -n "transition:\s*all|outline:\s*none|role=\"checkbox\"|@click=\"toggleSupplement" resources/js/components/frontend/kiosk/KioskWizardComponent.vue resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue
```

Résultat : aucun match.

## 6. Blocage Externe Non Corrigé Ici

```bash
bash .cursor/hooks/safety-check.sh
```

Résultat : HALT.

Cause :

```text
[HALT] Frozen zone staged: app/Services/OrderService.php — gate clearance required. See docs/gates/
```

Ce fichier n’appartient pas au scope wizard UI et n’a pas été modifié dans ce REWORK. Il reste un blocage de gouvernance/worktree préexistant.

## 7. Fichiers Touchés

- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`
- `resources/js/helpers/kioskPricing.js`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `tests/js/KioskWizard.spec.js`
- `public/js/kiosk-wizard.js`
- `public/js/kiosk-wizard-step.js`
- `public/mix-manifest.json`

## 8. Verdict

SELF_AUDIT_VERDICT: PASS_WITH_EXTERNAL_SAFETY_BLOCKER

Les remarques fonctionnelles critiques du wizard sont maintenant couvertes :

- Suppléments répétables.
- Total live cohérent.
- Récap lisible.
- Boisson réelle du catalogue central.
- CTA final amélioré.
- Thème sombre vérifié en live.

Reste hors mission : blocage `safety-check` sur un fichier staged frozen préexistant.
