# Kiosk Wizard — Live Composition UX Fix — 2026-05-01

## Verdict

`ISSUE_VERDICT: CONFIRMED`

`FIX_VERDICT: PASS_LOCAL`

`SCOPE: Kiosk wizard header + meat step UX`

## Probleme Corrige

Deux problemes UX etaient visibles sur la borne:

- l'etape viande repetait `Inclus` sur chaque carte, ce qui donnait au client une lecture confuse: "toutes les viandes sont incluses" au lieu de "je dois choisir ma viande comprise dans le produit";
- le haut du wizard ne montrait que les etapes, sans recapitulatif dynamique des choix deja faits. Le client devait revenir en arriere pour verifier sa composition.

## Correction

### 1. Composition en direct sous le stepper

Fichier:

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`

Ajout d'une barre compacte, non interactive, sous le stepper:

- choix reels du client uniquement;
- produit masque, car le titre du wizard affiche deja le produit;
- taille masquee si elle est seulement deduite du nom du produit, ex. `Tacos M (1 viande)` ne cree pas un doublon `Taille M`;
- pain;
- viande(s);
- sauce(s);
- garnitures;
- supplements;
- menu / boisson / sauce frites;
- choix composer generiques.

La barre lit uniquement l'etat `selections` deja existant, mais n'affiche une famille de choix que lorsque le client est arrive a l'etape correspondante. Cela evite d'afficher des valeurs par defaut, comme les crudites cochees automatiquement, avant que le client ne les ait vues.

Elle ne touche pas:

- `buildCartItem()`;
- le pricing;
- le submit;
- les state machines;
- la logique de quota viande.

### 2. Etape viande repensee

Fichier:

- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`

Changements:

- suppression du badge `Inclus` repete sur chaque viande gratuite;
- conservation du compteur global `0 / 1`, `1 / 1`, etc.;
- message global plus clair: "Votre tacos comprend 1 viande : touchez celle que vous voulez.";
- carte selectionnee affiche `Choisi`;
- les viandes payantes affichent toujours `Supplement {price}`;
- cartes viande rendues plus horizontales, plus lisibles, avec image a gauche et action tactile a droite;
- targets tactiles conservees a 44px+.

## Review Adversariale

Un sous-agent adversaire a audite le plan avant validation:

- risque principal: ne pas toucher `buildCartItem()` ni le pricing backend;
- risque quota: les viandes payantes ne doivent jamais compter comme viande incluse;
- risque layout: le header est deja dense, donc le recap doit rester compact;
- risque accessibilite: le recap doit rester non interactif si les targets tactiles ne sont pas necessaires.

Ces contraintes ont ete respectees.

## Tests

```bash
npx vitest run tests/js/KioskWizard.spec.js
```

Resultat: `97 passed`.

```bash
npx vitest run tests/js/kioskA11yTouchTargets.spec.js tests/js/kioskA11yAxe.spec.js
```

Resultat: `12 passed`.

```bash
npm run development
```

Resultat: `Compiled Successfully`.

```bash
node -e "for (const f of ['fr','en','de','bn','ar']) JSON.parse(require('fs').readFileSync(`resources/js/languages/${f}.json`, 'utf8'))"
```

Resultat: JSON i18n valide pour `fr`, `en`, `de`, `bn`, `ar`.

## Validation Runtime Locale

Scenario Playwright reel:

1. ouvrir `http://127.0.0.1:8000/kiosk/idle`;
2. choisir `Sur place`;
3. ouvrir categorie `Tacos`;
4. ouvrir `TACOS M (1 VIANDE)`;
5. verifier l'etape viande;
6. choisir `Merguez`;
7. verifier le recap haut.

Avant choix:

```json
{
  "liveComposition": 1,
  "includesText": false,
  "meatCards": 9,
  "meatMetaTexts": [],
  "firstCardText": "MERGUEZ\nCHOISIR"
}
```

Apres premiere passe, un audit visuel a revele une repetition:

- titre: `TACOS M (1 VIANDE)`;
- chip: `PRODUIT Tacos M (1 Viande)`;
- chip: `TAILLE M`;
- chip: `GARNITURES Salade, Tomate +1` avant meme que le client arrive a l'etape crudites.

Deuxieme correction appliquee:

- suppression du chip produit;
- suppression du chip taille quand l'etape taille n'est pas une etape client visible;
- suppression des chips d'etapes futures tant que le client n'a pas atteint l'etape;
- conservation du chip viande immediatement apres choix.

Validation runtime finale avant choix:

```json
{
  "compositionText": "VOTRE COMPOSITION\nVos choix s'ajoutent ici au fur et a mesure.",
  "empty": 1,
  "productChip": 0,
  "tailleChip": 0,
  "garnituresChip": 0,
  "meatChip": 0,
  "includesText": false
}
```

Validation runtime finale apres choix viande:

```json
{
  "compositionText": "VOTRE COMPOSITION\nVIANDES\nMerguez",
  "empty": 0,
  "productChip": 0,
  "tailleChip": 0,
  "garnituresChip": 0,
  "meatChipText": "VIANDES\nMerguez"
}
```

Ancienne preuve apres choix de la premiere passe:

```json
{
  "compositionText": "VOTRE COMPOSITION\nPRODUIT\nTacos M (1 Viande)\nTAILLE\nM\nVIANDES\nMerguez\nGARNITURES\nSalade, Tomate +1",
  "meatMetaTexts": ["Choisi"],
  "selectedCardText": "MERGUEZ\nCHOISI\n−\n1\n+"
}
```

Captures:

- `reports/screenshots/kiosk-wizard-live-composition-2026-05-01.png`
- `reports/screenshots/kiosk-wizard-after-meat-choice-2026-05-01.png`
- `reports/screenshots/kiosk-wizard-composition-empty-no-dup-2026-05-01.png`
- `reports/screenshots/kiosk-wizard-composition-after-meat-no-dup-2026-05-01.png`

## Fichiers Touches

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/de.json`
- `resources/js/languages/bn.json`
- `resources/js/languages/ar.json`
- `tests/js/KioskWizard.spec.js`
- `public/js/kiosk-wizard.js`
- `public/js/kiosk-wizard-step.js`
- `public/mix-manifest.json`

## Conclusion

Le wizard est plus lisible pour un client borne:

- il voit sa composition progresser sans revenir en arriere;
- l'etape viande ne repete plus `Inclus` partout;
- les supplements payants restent clairement marques;
- la logique pricing/order reste intacte.
