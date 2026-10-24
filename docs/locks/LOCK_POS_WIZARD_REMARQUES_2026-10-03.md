# LOCK — Assistant de composition de la caisse : 5 remarques du propriétaire (2026-10-03)

**ID :** `LOCK_POS_WIZARD_REMARQUES_2026-10-03.md`
**Date :** 2026-10-03
**Statut :** **BROUILLON — EN ATTENTE DU CONTRESEING DU PROPRIÉTAIRE (§10).** Rien n'est modifié dans
les fichiers gelés tant que ce document n'est pas contresigné.
**Portée :** 2 fichiers gelés (CLAUDE.md §7), modifications ciblées, une par remarque, chacune
désactivable séparément.

## Fichiers gelés concernés

| Fichier | SHA-256 actuel (2026-10-03) |
|---|---|
| `public/js/pos-wizard.js` | `3bf2f9c90c9320bf61d7e8619c535cee3e82acfa6d7f1314273a89ec6258995f` |
| `public/css/pos-wizard.css` | `96e06df60076e3d02346f5d1ab4cc7db79fbc5e01c42c175fbd3e840b811e33d` |

Aucune autre zone gelée n'est touchée : ni `PricingService` (les prix restent calculés par le serveur
à partir des seuls identifiants), ni `OrderStateMachine`, ni le fiscal.

## Pourquoi un LOCK

Ces cinq remarques ne peuvent être traitées QUE dans l'assistant de composition de la caisse
(`renderSinglePage()`), écrit à la main et gelé (« design parfait selon le propriétaire »). Aucun
contournement hors zone gelée n'existe : l'affichage et la validation des choix vivent dans ce fichier.

## Les cinq changements proposés

Chaque point se contresigne séparément (cocher OUI / NON).

### W-1 · R-020 — Suppléments et options frites plus grands

- **Propriétaire :** choix plus grands, occupant tout leur cadre.
- **Constat :** les suppléments et options frites sont des `micro-opt`. Police 10-11 px, hauteur
  30 px (`pos-wizard.css:524-536`). La grille des suppléments est plafonnée à 200 px de haut
  (`:1817-1819`).
- **Changement (CSS seul) :**
  - `micro-opt` passe à 14 px de police et 44 px de hauteur minimale (cible tactile WCAG) ;
  - le plafond de 200 px est levé (défilement interne seulement au-delà de 3 rangées).
- **Preuve :** capture avant/après sur 3 produits (Cayenne, Tacos M, Menu), sans débordement à
  1366 × 768.
- [ ] OUI  [ ] NON

### W-2 · R-023 — « Sans sauce » exclusif

- **Constat :** « Sans sauce » peut être coché avec une autre sauce. Il compte alors comme 2ᵉ sauce,
  facturée +0,50 € (`pos-wizard.js:6287-6303`, `:4228-4230`). La borne est déjà corrigée
  (`fd6faec72`).
- **Changement :**
  - cocher « Sans sauce » décoche les autres sauces ;
  - cocher une sauce décoche « Sans sauce » ;
  - « Sans sauce » ne compte jamais comme sauce facturée.
- **Preuve :** banc Vitest (à créer)
  `tests/js/posWizardSansSauceExclusif.spec.js`.
- [ ] OUI  [ ] NON

### W-3 · R-025 — Pas de faux choix « Pain ou Galette » à la modification

- **Constat :** un produit au gabarit `sandwich` sans attribut pain reçoit un faux choix
  [Pain, Galette] (`pos-wizard.js:907-920`). Quand on le modifie depuis le panier, la ligne arrive
  avec `pain: null`, et l'ajout est refusé avec « Sélectionnez Pain ou Galette » (`:5688-5692`).
- **Changement :** le repli « Pain / Galette » n'est créé que si le produit en a réellement besoin
  (catégorie sandwich avec pain). Sinon, aucune étape pain.
- **Preuve :** banc Vitest (à créer) `tests/js/posWizardPasDePainFantome.spec.js` (cheeseburger,
  tacos, modification depuis le panier).
- [ ] OUI  [ ] NON

### W-4 · R-033 — Message juste quand la viande incluse manque

- **Constat :** le message dit « Sélectionnez 1 viande supplémentaire » alors que c'est la viande
  INCLUSE qui manque (`pos-wizard.js:5651`).
- **Changement :** le message devient « Choisissez la viande » (ou « les N viandes ») quand l'inclus
  n'est pas atteint. « supplémentaire » est réservé aux viandes payantes.
- **Preuve :** banc Vitest (à créer) `tests/js/posWizardMessageViande.spec.js`.
- [ ] OUI  [ ] NON

### W-5 · R-035 — Étape « Extra sauce fromagère (+1 €) » sur le tacos

- **Constat :** aucune étape de ce type. Un simple extra ne suffirait pas : l'assistant écarte tout
  extra dont le nom contient « sauce » (`pos-wizard.js:3165`).
- **Changement :** sur un tacos, une ligne oui / non « Extra sauce fromagère ». « Oui » ajoute
  l'identifiant d'un extra « Sauce fromagère supplémentaire », créé en données à 1,00 € et facturé
  par le serveur.
- **Données :** création de l'extra sur les tacos (commande artisan dédiée, idempotente, à créer).
- **Preuve :**
  - test serveur `tests/Feature/Pos/TacosExtraSauceFromagereTest.php` (à créer) : facturé 1 €,
    sans prix client ;
  - banc Vitest de l'étape.
- [ ] OUI  [ ] NON

### W-6 · Options frites fantômes après un changement de formule (affiché ≠ facturé)

- **Origine :** revue de convergence 3, le 2026-10-03.
- **Constat :** on choisit « Grande Portion » ou « Cheddar Fondu », ou des sauces frites, puis « Sans
  formule » ou « Boisson Seule ». Les choix de frites restent dans `selections`, car les boutons de formule
  (`pos-wizard.js:6344-6354`) ne les remettent jamais à zéro. Ils sont ensuite comptés dans le total
  (`:1564`) et imprimés dans l'instruction (`:4077`, « ↳ Grande Portion » sur un produit sans frites).
  Exemples mesurés :

  | Formule finale | Panier | Facturé |
  |---|---|---|
  | Sans formule | 7,90 | 6,90 |
  | Boisson Seule | 10,30 | 9,30 |

  Le serveur ne facture rien de tout cela (aucune ligne formule frites).
- **Déjà corrigé hors zone gelée** (`ItemComponent.vue`, revue de convergence 3) : la sauce frites n'est
  plus facturée sur une formule sans frites. Seul le **total affiché** et la **note cuisine** viennent du
  wizard gelé.
- **Changement :** quand la formule choisie ne contient pas de frites, remettre à zéro `fritesGrande`,
  `fritesCheddar` et `sauceFritesOrder`. C'est la même règle que l'affichage des sections frites :
  « frite » ou « menu » dans le nom.
- **Preuve :**
  - banc Vitest (à créer) `tests/js/posWizardOptionsFritesReinitialisees.spec.js` : panier = facturé dans
    les 2 cas ci-dessus ;
  - aucun « ↳ Grande Portion » dans l'instruction.
- [ ] OUI  [ ] NON

## Invariants

- **Prix :** le client n'envoie que des identifiants. `PricingService` reste l'unique autorité.
- **Empreinte :** la baseline SHA-256 (`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`)
  n'est mise à jour que dans le commit du correctif, après ce contreseing, selon la règle du hook
  zone gelée.
- **Suites :** les suites complètes (PHPUnit, Vitest) restent vertes ; capture caisse avant/après relue.

## Rollback

Un commit par point W-n. Un `git revert` de ce commit rétablit le fichier et son empreinte.

## Contreseing propriétaire (§10)

- [ ] Contresigné — date : ________  points acceptés : W-1 / W-2 / W-3 / W-4 / W-5 / W-6
