# Kiosk Wizard Design/UX Fix - 2026-04-27

TASK_ID: KIOSK-WIZARD-DESIGN-UX-2026-04-27  
EXECUTE_DELEGATION: codex-extension  
MODE: FRONTEND_UI_EXECUTE  
AUDIT_VERDICT: PASS_WITH_RESERVE

## Objectif

Corriger le wizard borne actif Vue, en portant la direction dark/red déjà validée sur les étapes internes sans remplacer les flows existants quote, panier, paiement, offline et attente.

## Audit Avant

Constats reproduits ou vérifiés :

- Le wizard actif utilisait encore beaucoup de surfaces blanches internes alors que le shell borne avait déjà le thème dark/light.
- Le footer total pouvait rester faux ou revenir en arrière après un retour `/pricing/preview`, notamment après une deuxième sauce ou un choix de formule.
- Le choix viande bloquait après le quota gratuit, donc les viandes payantes ne pouvaient pas être ajoutées comme supplément.
- L'option "Boisson seule" était masquée sur tacos/sandwich/burger alors qu'elle doit être une formule explicite.
- Une ligne générique "Boisson seule" / "+ Boisson" pouvait être traitée comme une boisson réelle.
- Si l'article n'expose pas de boissons via ses addons, le wizard n'affichait pas la liste centrale des boissons disponibles.
- Les premiers choix de sauce affichaient un prix `0,00 €`, moins clair qu'un état "Gratuite".
- Le safety-check est bloqué par un état préexistant hors mission : `app/Services/OrderService.php` staged en zone frozen.

## Implémentation

Fichiers produit touchés :

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/helpers/kioskDrinkAddons.js`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`

Fichiers tests touchés :

- `tests/js/KioskWizard.spec.js`
- `tests/js/kioskDrinkAddons.spec.js`

Build assets générés :

- `public/js/kiosk-shell.js`
- `public/js/kiosk-wizard.js`
- `public/js/kiosk-wizard-step.js`
- `public/mix-manifest.json`

Changements principaux :

1. Total live
   - `serverPreviewTotal` est vidé immédiatement à chaque changement de sélection.
   - Le total affiché ne peut plus descendre sous le total local des options explicites du wizard.
   - Corrige le cas où la deuxième sauce affichait +0,50 € puis revenait visuellement au prix de base.

2. Viandes
   - Séparation logique entre quota inclus et viandes payantes.
   - Le quota gratuit reste obligatoire pour avancer.
   - Les viandes `source='extra'` restent ajoutables après le quota gratuit et sont comptées dans le total.

3. Sauces
   - Première sauce affichée comme gratuite.
   - Sauces supplémentaires gardent le libellé prix clair.
   - Même logique appliquée aux sauces frites de la formule.

4. Menu / boissons
   - "Boisson seule" est disponible pour les articles menu-capables.
   - Les labels génériques "Boisson seule" / "+ Boisson" sont exclus de la vraie liste de boissons.
   - Fallback vers le catalogue central borne (`kioskMenu/allItems` + catégories "Boissons") quand les addons de l'article n'exposent pas de boisson réelle.
   - Les boissons indisponibles (`is_available=false`, `status` 0/2/10) sont exclues.
   - Si des boissons réelles existent via ce fallback, "Suivant" reste bloqué tant qu'une boisson n'est pas choisie.

5. Design
   - Wizard, étapes viande/sauce/menu/suppléments et footer alignés sur les tokens `--kiosk-*`.
   - Compatibilité dark/light préservée via le thème existant de `KioskAppComponent`.
   - Boutons, badges, cards, états selected/disabled et nav footer harmonisés avec le shell borne.

## Validation

Static :

```text
git diff --check -- <wizard files + tests> -> PASS
```

Safety :

```text
bash .cursor/hooks/safety-check.sh -> HALT
Reason: pre-existing staged frozen zone app/Services/OrderService.php.
This task did not edit that file.
```

Vitest targeted :

```text
npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskDrinkAddons.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskViandeCatalog.spec.js
Result: 4 files PASS, 115 tests PASS.
```

Vitest kiosk pattern :

```text
npx vitest run tests/js/kiosk*.spec.js tests/js/Kiosk*.spec.js
Result: 64 files PASS, 555 tests PASS.
```

Build :

```text
npm run production
Result: PASS, Laravel Mix compiled successfully.
```

Playwright local browser, dark theme, 1080x1920 :

- `reports/ux/screenshots/kiosk-wizard-ux-categories-after-order-type-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-wizard-ux-active-after-order-type-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-wizard-ux-tacos-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-wizard-ux-tacos-menu-drinks-after-fix-dark-2026-04-27.png`

Playwright trace final on `Tacos M (1 Viande)` :

```json
{
  "open": "Total €6,50",
  "after_included_meat": "Total €6,50",
  "after_second_sauce": "Total €7,00",
  "menu_step": "Total €7,00",
  "after_boisson_only_before_drink": "Total €8,20",
  "after_drink_choice": "Total €8,20",
  "boissonOnlyVisible": true,
  "drinkNames": [
    "CAPRI-SUN",
    "EAU PLATE 50CL",
    "ORANGINA 33CL",
    "OASIS TROPICAL 33CL",
    "SPRITE 33CL",
    "FANTA ORANGE 33CL",
    "COCA-COLA ZERO 33CL",
    "COCA-COLA 33CL"
  ],
  "canNextBeforeDrink": false,
  "canNextAfterDrink": true
}
```

## Invariants

- Backend pricing SSOT respecté : aucun prix n'est envoyé au serveur depuis le wizard; les prix affichés restent issus du catalogue/preview existant.
- Branch isolation : non touché.
- Order status enum : non touché.
- Dispatch after commit : non touché.
- Backend, routes, config, database : non touchés.
- Quote, payment, offline queue : flows non remplacés; les tests kiosk restent verts.

## Réserves

1. Le safety-check reste bloqué par `app/Services/OrderService.php` staged en zone frozen, état préexistant à cette mission.
2. `resources/js/languages/fr.json` et `resources/js/languages/en.json` avaient déjà des modifications hors mission. Cette intervention ne revendique que le libellé wizard "Boisson seule" / "Drink only".
3. Les boissons de formule utilisent maintenant la liste centrale disponible côté borne. La tarification reste la tarification formule existante (`kioskMenuPricing` / addon Menu), pas une addition séparée du prix article boisson.

FINAL_STATUS: PASS_WITH_RESERVE
