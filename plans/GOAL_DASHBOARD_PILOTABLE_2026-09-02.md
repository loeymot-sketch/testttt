# GOAL — Le Dashboard pilote vraiment le restaurant (catalogue, pages de wizard, contrôle)

Date : 2026-09-02 · Branche : `pos/category-first-caisse-2026-06-23` · HEAD de départ : `ef0e41d01`
Propriétaire : « en tant qu'admin, passer en revue tout, fonctionnalité par fonctionnalité : est-ce que ça se
contrôle, se gère, se modifie ? Les catégories, produits, sous-catégories, wizards, pages de wizard —
création, modification, ajout d'une page. Si demain j'ajoute une catégorie, je dois pouvoir lui donner
N pages, en reprenant celles qui existent (pain, crudités…) et en personnalisant les autres. Une bonne
structure pour le futur. Boucler jusqu'à ce que tout soit fonctionnel, validé, meilleur — interface,
expérience et tout. »

Instrument : `tests/Playwright/dashboard-catalogue-captures-2026-09-02.spec.js` (captures + console +
HTTP ≥ 400 + libellés i18n bruts), lancé avec Node 22 (`~/.nvm/versions/node/v22.23.2/bin`) contre
`http://127.0.0.1:8766` (proxy → `:8000`, arbre principal, base `foodking_e2e`).

---

## 1. Constat mesuré (preuves)

### F1 — P0 · La gestion des wizards n'a AUCUN effet en caisse ni sur la borne
- Les surfaces ne lisent que les profils **par produit** : `app/Http/Resources/ItemResource.php:156`
  et `NormalItemResource.php:186` (`where('item_id', …)`) ; `PricingService::assertComposerStepConstraints`
  (`:564`, zone gelée) idem. Un wizard **de catégorie** n'agit qu'à travers ses clones produit créés à la
  publication (`ComposerProfileService::fanOutCategoryPublish`).
- Base : les profils catégorie P34 (Sandwichs), P35, P36 (Galette), P37 (Burgers), P38 (Tacos), P39 (Bols)
  sont `is_published=1`, mais **aucun produit** de ces catégories n'a de clone (items 26/97/234, 22/103/104/
  105/163, 38…102 : zéro profil). Total profils = 34, id max 45.
- Conséquence : l'admin voit « Publié », la caisse et la borne tournent sur l'heuristique legacy
  (`buildSteps` / `detectTemplateFromName`). Le Dashboard ment.

### F2 — P1 · L'admin voit un brouillon périmé, pas ce qui est en caisse
- `ComposerProfileService::pickAdminProfile` préfère n'importe quel brouillon (même plus ancien) au profil
  publié : Tacos affiche P32 (brouillon v3, 7 pages) alors que P38 (publié v4, 8 pages) est « en caisse ».
  Deux à trois profils par catégorie (P22+P28+P34…).

### F3 — P1 · Faux blocage « Profil Composeur manquant » sur chaque produit
- `CatalogWarningService::forItem` (`:76`) ne regarde que `item_id`. Tacos M affiche un blocker rouge avec
  un bouton « Créer le profil composeur » qui envoie sur `/admin/items/show/26/composer` — route inexistante
  (`ComposerProfileWarningBadge.vue:127`), et la route produit est de toute façon fermée (demo flag).

### F4 — P0 (demande propriétaire) · Une page n'a ni choix ni prix : impossible d'ajouter une catégorie
- Une page (`item_wizard_steps`) ne pointe que par NOM vers des attributs/extras qui doivent exister sur
  CHAQUE produit (`item_variations`, `item_extras`, `item_addons`). Créer « Wraps » avec pain + crudités +
  sauces = ressaisir des dizaines de lignes par produit dans les onglets Variante/Extra.
- Dérive mesurée : 130 lignes « crudite » sur 17 produits, 188 « supplement » sur 20 ; Poulet crispy sur
  5/11 produits, Cornichon 5/17, Spicy 5/25. Pages « pain » et « taille » inactives faute de source
  (`P34 pain act=0 source ''` ; « Type de Pain » n'existe que sur 5 produits).

### F5 — P2 · Défauts d'interface constatés à l'écran (captures lues)
- Icônes absentes de `public/themes/default/fonts/lab/lab.css` : `lab-cog`, `lab-view-line`,
  `lab-delete-line`, `lab-export`, `lab-arrow-left`, `lab-close-circle`, `lab-refresh`, `lab-add-line`,
  `lab-setting-line`, `lab-toggle-on`, `lab-eye`, `lab-stock` → les trois actions de chaque carte produit du
  studio et le bouton supprimer des catégories sont des pastilles vides.
- Libellés sans accents dans `fr.json` (« Publie », « Depublier », « Apercu live », « Portee branche »,
  « Rafraichi apres modification »…) ; « = Obligatoire, le client doit choisir exactement articles. »
  (`{n}` perdu : `$t` interpole avant le `.replace`).
- Sous-catégories : `parent_id` existe (modèle, requête, borne qui rend l'arbre `KioskMenuService:246`)
  mais **aucun champ** dans le formulaire catégorie, liste plate (« Tacos Signature » ressemble à un rayon vide).
- Fiche produit : « Mis en avant » / « Suggestion caisse » vides ; drapeau `/storage/1/english.png` 404 ;
  composeur : « No Image Found! » en en-tête, bouton retour sur 3 lignes, curseurs 0–10 pour min/max.
- Pollution E2E visible : attributs lorem (7) + `E2E_PLAYWRIGHT_ATTRIBUTE_TOGGLE`, produit
  `E2E_PLAYWRIGHT_STUDIO_ITEM` dans la liste produits.

---

## 2. Cible — « Pages de wizard réutilisables »

**Principe** : une catégorie = une suite ordonnée de pages ; une page = une liste de choix **avec prix**.
Les pages vivent dans une **bibliothèque** partagée (pain, viandes, sauces, crudités, suppléments,
boisson, formule…). Une catégorie prend une page « telle quelle » (liée : suit la bibliothèque) ou la
**personnalise** (copie privée à la catégorie, modifiable librement). À la publication, le système
**matérialise** les choix sur chaque produit de la catégorie (lignes `item_variations` / `item_extras` /
`item_addons`, idempotent, jamais de suppression dure) puis clone le profil sur chaque produit — le
contrat lu par la caisse, la borne et `PricingService` reste **strictement inchangé** (zones gelées
intactes). Un produit créé ensuite dans la catégorie est couvert automatiquement.

### Données
- `wizard_pages` : id, key (unique), label, kind ∈ {pain, taille, viande, sauce, garnitures, supplements,
  menu, generic}, source_type ∈ {item_attribute, extra_group, addon}, item_attribute_id (nullable, créé/lié
  automatiquement pour les pages attribut), extra_group_label, addon_role, min_select, max_select,
  allow_repeat, visible_on (json), stockable_choices, is_active, owner_category_id (null = bibliothèque),
  description, sort, timestamps, soft deletes.
- `wizard_page_choices` : id, wizard_page_id, name, price decimal(19,6), addon_item_id (nullable), sort,
  status, visible_on (json), timestamps, soft deletes.
- `item_wizard_steps.wizard_page_id` (nullable).
- Le `step_key` d'une étape liée = `page.key` : pour les kinds connus (pain/viande/sauce/garnitures/
  supplements/menu/taille) la caisse et la borne gardent leurs écrans dédiés ; tout autre key → écran
  générique (`generic_choices`) déjà supporté par les deux surfaces.

### Backend
- Modèles `WizardPage`, `WizardPageChoice` ; `WizardPageService` (CRUD, copie privée, usages) ;
  `WizardPageMaterializer` (par catégorie / par produit, rapport créés/mis à jour/désactivés) ;
  commande `composer:materialize {--category=} {--all} {--dry-run}` ; commande `wizard-pages:bootstrap`
  (bibliothèque Le Cayenne depuis les données existantes + liaison des étapes existantes) exécutée par
  migration data-only.
- `ComposerProfileService::publish` (catégorie) : matérialiser → contrôles → clones (existant).
  Écouteur `ItemCreated` : produit ajouté à une catégorie publiée → matérialisé + cloné.
- `pickAdminProfile` : brouillon uniquement s'il est plus récent que le publié ; endpoint
  `GET admin/composer/categories/{category}/runtime` (version publiée, couverture produits, écarts).
- `CatalogWarningService` : résolution catégorie (fin du faux blocage).
- API `admin/wizard-pages` (index/show/store/update/destroy, choix imbriqués, `duplicate-for-category`),
  `POST admin/composer/categories/{category}/materialize`.

### Frontend (admin)
- Écran **Pages de wizard** (`/admin/wizard-pages`) : liste (type, nb choix, utilisée par N catégories),
  éditeur (libellé, type de choix, règles min/max/répétition, canaux, choix : nom + prix + actif + ordre).
- Composeur catégorie : « Ajouter une page » → bibliothèque (utiliser telle quelle / personnaliser / page
  vide privée) ; panneau d'étape : choix affichés (liés en lecture + « Modifier dans la bibliothèque »,
  privés éditables), entrées numériques min/max ; en-tête : « Publié vN · Brouillon » + couverture
  produits + « Synchroniser les produits ».
- Catégories : champ « Catégorie parente », hiérarchie visible dans la liste et le studio.
- Corrections F5 : alias CSS des icônes manquantes, accents, `{n}`, valeurs vides fiche produit, CTA du
  badge, photo d'en-tête, bouton retour, masque pollution E2E côté liste produits/attributs.

### Hors périmètre (volontaire)
- Zones gelées (`pos-wizard.js`, `KioskWizardComponent.vue`, `PricingService`…) : lecture seule.
- Suppression physique de la pollution E2E en base (masque en lecture seulement, décision antérieure).
- Cockpit/observabilité et KPI dashboard : déjà audités (Codex 2026-09-02), traités en vague 3 uniquement
  pour les écrans qui cassent à l'usage.

---

## 3. Vagues

| Vague | Contenu | Preuve |
|---|---|---|
| W0 | Instrument de capture ; base de tests composeur (compte) | spec + `php artisan test --filter=Composer` |
| W1 | Backend bibliothèque + matérialisation + clones + runtime + warnings + API | PHPUnit ciblés verts, `composer:materialize --all --dry-run` = 0 écart après bootstrap |
| W2 | Frontend bibliothèque + composeur + catégories (parent) + F5 | Vitest verts, captures relues, Mix rebuild |
| W3 | Parcours Dashboard complet fonctionnalité par fonctionnalité (37 entrées + réglages) : chargement, erreurs, libellés bruts, CRUD de fumée | spec Playwright + rapport |
| W4 | Vérification : suites complètes, diff zones gelées = 0, chaîne NF525, RED adversaire, BRAIN, commit | rapport final |

## 4. Critères d'acceptation
- AC1 : créer une catégorie neuve + 1 produit, lui attacher 4 pages de la bibliothèque + 1 personnalisée,
  publier → `admin/item/show/{id}` ET le flux borne (`NormalItemResource`) exposent 5 étapes avec leurs
  choix et prix ; aucune saisie dans les onglets Variante/Extra.
- AC2 : les 6 catégories existantes ont 100 % de leurs produits actifs couverts par un clone publié égal
  à la version catégorie ; `composer:materialize --all --dry-run` = 0 écart.
- AC3 : plus de faux « Profil Composeur manquant » ; CTA valide.
- AC4 : l'admin voit la version publiée et un seul brouillon, jamais un brouillon plus vieux.
- AC5 : sous-catégorie créable et visible en hiérarchie.
- AC6 : chaque entrée du menu admin répond 200 sans erreur JS de page ni libellé brut (soketi exclu).
- AC7 : chaque bouton d'action du catalogue porte une icône rendue.
- AC8 : PHPUnit (Composer/Catalog/WizardPage), Vitest complet, Playwright nouveaux verts ; zones gelées
  0 ligne (hors lot stagé préexistant `goal-caisse-vision`, jamais commité ici) ; `fiscal:verify-chain` inchangé.

## 5. STOP (6 questions)
1. Le problème est-il mesuré ? Oui (§1, preuves file:line + base + captures).
2. Le plus petit changement ? Non trivial : la demande est structurelle ; le contrat runtime est préservé.
3. Périmètre tenu ? Catalogue/wizard + revue Dashboard ; pas de cloud, pas de gelées.
4. Rollback ? Migrations réversibles (nouvelles tables + colonne nullable) ; data bootstrap idempotent.
5. Preuve visuelle ? Captures relues à chaque vague.
6. Qui valide ? Propriétaire sur le résultat ; RED adversaire avant fin.

## 6. Registre (mis à jour au fil de l'eau)
| Id | Défaut | État | Preuve |
|---|---|---|---|
| F1 | Wizards sans effet (clones absents) | **corrigé** | `composer:materialize --all` : 28 des 30 produits n'avaient AUCUN clone publié (`reports/goal-dashboard-pilotable-2026-09-02/materialize-dry-run-avant.txt`) → 30/30 à jour, second passage « 0 changement ». Test : `NouvelleCategorieDepuisLaBibliothequeTest` |
| F2 | Brouillon périmé préféré | **corrigé** | `pickAdminProfile` ne préfère un brouillon que s'il est plus récent ; en-tête « En caisse : version N » + couverture produits (`GET .../categories/{id}/runtime`). Test : `le tableau de bord dit la vérité sur ce que lit la caisse`, `un vieux brouillon ne masque plus le wizard en caisse` |
| F3 | Faux blocage composeur | **corrigé** | `CatalogWarningService` résout le profil de CATÉGORIE ; capture `05-item-show-tacos-m` sans blocage |
| F4 | Pages sans choix/prix | **corrigé** | `wizard_pages` + `wizard_page_choices` + `WizardPageMaterializer` ; écran « Pages de wizard » (12 pages, choix + prix éditables) ; « Ajouter une page » = bibliothèque. Tests : `WizardPageMaterializerTest` (4), `NouvelleCategorieDepuisLaBibliothequeTest` (6) |
| F5 | UI (icônes, accents, {n}, sous-cat, fiche, CTA) | **corrigé** | 10 alias d'icônes vérifiés glyphe par glyphe contre `lab.css` ; 5 accents (`Dépublier`, `Aperçu live`, `Portée branche`, `Publié`, `Rafraîchi après modification`) ; pluriel « 1 article » ; prix « +0,90 € » ; champ « Catégorie parente » + hiérarchie en liste ; drapeau 404 (`DrapeauLangueSansFichierTest`, prouvé rouge sans le correctif) |

### Défauts trouvés par l'audit adverse sur MON travail (corrigés, bancs rouges avant)
| Id | Défaut | Banc |
|---|---|---|
| A1 | `WizardPageService::update()` non partiel : corriger un prix mettait `item_attribute_id` à `null` → attribut recréé, variations orphelines (2 attributs fantômes réellement laissés en base, nettoyés) | `ModifierUnePageNeCasseRienTest` |
| A2 | Page « formule » sans produit relié : tous les addons du rôle supprimés, aucun recréé | `MaterialisationNeDetruitPasEnSilenceTest` |
| A3 | Matérialiser puis republier non atomique : un 422 laissait les écritures en base | idem (chemins API + Artisan) |
| A4 | « Synchroniser les produits » écrasait prix et options sans aperçu | simulation + confirmation avant application |
| A5 | Faux vert « 0 changement » quand une étape active n'a aucune page reliée | `MaterialisationNeDetruitPasEnSilenceTest` |
| A6 | Page éteinte proposée sans le dire et ajoutée « active » ; doublon via « Personnaliser » ; erreur réseau affichée « aucune page » ; bandeau caisse disparaissant en silence ; brouillon jeté sans avertir ; a11y du tableau des choix ; modale sans dialogue ni Échap ; compteur d'usage jamais rendu ; attribut fantôme après suppression | Vitest + captures |

### Signalés par l'audit, NON corrigés (décision propriétaire)
- `catalog.publish` seul permet désormais de réécrire le catalogue (publier = écriture de masse).
- Coût : ~50 requêtes par produit et par passage, dans une seule transaction.
- `bindSteps` retouche un profil publié sans monter de version (visible avec `--no-clone`).
- L'écouteur « produit créé » échoue en silence (journal seulement).

### Restes connus (non traités, assumés)
- **Pages éteintes** : `pain` (5 catégories), `garnitures`/`supplements` (Tacos), `viande` (Bols) sont
  reliées à une page mais `is_active = 0` avec une source vide. Le composeur le DIT maintenant
  (bandeau « Page éteinte : elle n'apparaît ni en caisse ni sur la borne ») et l'interrupteur les
  rallume — mais les rallumer change la carte servie au client : c'est une décision du propriétaire,
  pas de la session.
- **Étape « taille »** (Tacos) : écran natif de la caisse et de la borne (produits frères M/L/XL),
  sans page de bibliothèque — normal, à ne pas « réparer ».
- **Exception par produit** : le modèle est « une page = la catégorie entière ». Retirer un choix
  pour UN seul produit de la catégorie n'existe pas ; à rouvrir si le besoin apparaît.
