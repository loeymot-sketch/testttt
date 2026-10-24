# GOAL — ONB-02 CATALOGUE DE ZÉRO
## FoodKing — Onboarding commerçant · catégories, articles, images, taxes, attributs, import/export, canaux, station cuisine : recopier sa carte en une soirée, sans développeur

- **Slug** : `ONB02_CATALOGUE_DE_ZERO_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « catalogue » (`admin/items/**` sauf `composer/**`, `settings/{ItemCategory,ItemAttribute,Tax}/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB02_CATALOGUE_DE_ZERO.md`
- **Port de session** : **8802** · **Persona** : Karim, tacos-burger à Marseille, 45 produits, veut recopier sa carte plastifiée en une soirée.

> **En cinq lignes.** Le problème : cinq entrées pour un concept (Catalogue, Articles, Produits & Stock, Réglages/Catégories, Attributs), Catégories/Taxes/
> Attributs cachés, une **taxe par défaut à 0 %** sur tout nouvel article alors que la config dit 10 %, une station cuisine validée par une **erreur SQL brute**,
> un canal inconnu **accepté**, une virgule décimale refusée en anglais, un « wizard de création » qui est un squelette, un import Excel jamais éprouvé.
> Preuve : mesures Z1 du 2026-08-26 (`recon/Z1_catalogue_wizard.md`, 2 passages, 50 captures, `tmp/recon/Z1/*.json`). FINI = Karim crée catégorie, article
> complet (images, taxe 10 %, station, canaux, allergènes), importe 45 lignes, corrige, supprime, et la borne + la caisse + la cuisine le reflètent (C1..C7).
> Ce GOAL ne touche pas au composer/wizard (→ ONB-03), ni au stock (→ ONB-08), ni à la visibilité du menu (→ ONB-05). Premier geste : W0 puis rejouer `z1_a_create_full.js` sur :8802.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb02-catalogue`, branche `goal/onb02-catalogue-2026-08-26`, créé **depuis HEAD** (jamais `origin/main`, 2 485 commits de retard).
- Pré-vol : `.env` copié avec `APP_URL=http://127.0.0.1:8802` ; `.env.testing` copié ; `vendor/` + `node_modules/` en **liens durs** (jamais symlink) ; vérification
  `ReflectionClass(App\Models\Item::class)->getFileName()` → worktree ; `php artisan serve --port=8802` ; `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8802`.
- Base partagée `foodking_e2e` : préfixe **`GOAL-ONB02`** sur toute catégorie / article / attribut / taxe créés ; nettoyage **définitif** en fin de vague (un article
  soft-supprimé garde son slug unique et fausse le test suivant — leçon du 2026-08-26) ; ⛔ jamais `migrate:fresh` ; tests via
  `bash ~/.claude/skills/brain/scripts/safe-test.sh --phpunit "Catalog|Items|Menu|Tax|Category"`.
- ⚠️ `menu:reset-le-cayenne` (1 250 lignes) est bloqué par une garde de dérive (GOAL CAISSE PARFAITE S3) : **ne jamais l'exécuter** ici.
- Filet : `git branch backup/pre-onb02-2026-08-26` + `mysqldump foodking_e2e items item_categories item_variations item_extras item_addons taxes item_attributes`.
- Git : fichiers nommés, jamais `git add .`, jamais push, un commit par vague.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Un seul lieu (Studio = hub) | `resources/js/components/admin/items/{CatalogStudioComponent,CatalogHubComponent,CatalogConceptHelpComponent,ItemComponent,ItemListComponent}.vue`, `resources/js/router/modules/itemRoutes.js` (routes catalogue) |
| S2 Fiche produit sûre | `admin/items/{ItemCreateComponent,ItemShowComponent,ItemPreviewComponent,ItemPhotoUpload,AvailabilityToggleComponent(lecture)}.vue`, `admin/items/{variation,extra,addon}/**`, `admin/items/wizard/ProductCreateWizardComponent.vue`, `app/Http/Controllers/Admin/{ItemController,ItemVariationController,ItemExtraController,ItemAddonController,ItemPhotoController}.php`, `app/Http/Requests/{ItemRequest,ItemVariationRequest,ItemExtraRequest,ItemAddonRequest,ItemPhotoUploadRequest,ItemOptionPhotoRequest,ChangeImageRequest}.php`, `app/Services/{ItemService,ItemVariationService,ItemExtraService,ItemAddonService}.php`, `app/Models/{Item,ItemVariation,ItemExtra,ItemAddon}.php`, `config/menu_images.php` |
| S3 Catégories, taxes, attributs | `settings/{ItemCategory,ItemAttribute,Tax}/**`, `app/Http/Controllers/Admin/{ItemCategoryController,ItemAttributeController,TaxController}.php`, `app/Http/Requests/{ItemCategoryRequest,ItemCategoryImportRequest,ItemAttributeRequest,TaxRequest}.php`, `app/Services/{ItemCategoryService,ItemCategoryHierarchyService,ItemAttributeService}.php`, `database/seeders/TaxTableSeeder.php` |
| S4 Import / export | `admin/items/ItemUploadComponent.vue`, `settings/ItemCategory/CategoryUploadComponent.vue`, `app/Imports/ItemImport.php` (+ catégories), `app/Exports/{ItemExport,ItemCategoryExport}.php`, `public/file/itemImportSample.xlsx`, `ItemController::export/import` |

| HORS (déclaré) | Porté par |
|---|---|
| Composer / wizard / règles de prix, `app/Services/Composer/**`, `admin/items/composer/**` | **ONB-03** |
| Stock, ruptures, `max_daily_qty`, ingrédients, achats | ONB-08 |
| Visibilité des entrées Catégories / Attributs / Taxes dans le menu (`v1-hidden-modules.js`, `MenuComponent.vue`, `BackendMenuComponent.vue`) | **ONB-05** (fiche de renvoi) |
| Extraction de menu par IA | ONB-04 (consomme les API de ce GOAL) |
| Seeders Le Cayenne, `menu:*` | ONB-12 |
| `PricingService.php` (gelé), `KioskWizardComponent.vue` (gelé), `pos-wizard.js` (strict) | jamais |

Zones à coordonner : `routes/api.php` (aucune route nouvelle attendue), `resources/js/languages/fr.json` (bloc `label.item_*`, `label.category_*`), `database/seeders/DatabaseSeeder.php` (si `TaxTableSeeder` corrigé).

## §0.3 — Drapeaux d'expansion
SCOPE-1 fichier gelé · SCOPE-2 3 boucles · SCOPE-3 migration non prévue (aucune prévue : les colonnes existent) · SCOPE-4 NF525 hors ajout (les taxes touchent la TVA : **toute modification de taux d'une taxe existante = escalade**, les commandes passées portent leur snapshot) · SCOPE-5 fichier d'un autre GOAL → fiche de renvoi.

## §0.4 — Pipeline par tâche
`ultra-audit-profond` par `T-x.y.z` ; `test-e2e` par page ; `verify-before-report` ; TDD rouge d'abord ; `systematic-debugging` avant correctif. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets de l'Axe 6 (étiquette brute, casse de mise en page 1366/1024/768, erreur console, diff gelé, P0, test rouge non documenté, acceptation sans chemin, « presque », NF525, deux cycles différents).
**Convergence = deux cycles consécutifs P0+P1 = 0 aux constats identiques.**

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Un article neuf naît avec la TVA restauration | `POST item` sans `tax_id` → `tax_id` = taxe par défaut réglée (10 %) | **VRAI** |
| C2 | Aucun message d'erreur brut | balayage des 422/500 sur 20 payloads invalides (station, canal, prix, nom, image) → aucun `SQLSTATE`, aucun anglais | **0** |
| C3 | Import Excel fiable | fichier 45 lignes (3 invalides) → 42 créées, 3 refusées avec ligne + motif ; ré-import identique → 0 doublon | **VRAI** |
| C4 | Effet borne / caisse / cuisine | article créé → présent dans `GET /api/frontend/menu` (borne), `GET /api/admin/item?branch_id=1` (caisse), visible KDS avec sa station | **3/3** en < 10 s |
| C5 | Parcours « de zéro à vendable » | clics mesurés catégorie + article complet (image, taxe, station, canaux) | **≤ 12** clics, 0 changement d'écran |
| C6 | Table `taxes` saine | lignes actives = taxes réelles ; parasites archivées/supprimées après G-TAX | **6** actives |
| C7 | Zéro « Cayenne » dans les valeurs par défaut du catalogue | `config/menu_images.php` repli générique, aperçu sans filiales de démonstration | **0** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · NF525 ajout seul · `tests/Feature/Catalog/` = **16** fichiers (`ItemDuplicationTest`, `ItemDeletionWithOrderHistoryTest`, `OptionPhotoTest`,
`ProductPhotoAuthzTest`, `ChannelsNullWarningTest`, `CategoryRenameSyncTest`, `ItemUpdateInvalidatesKioskCacheSentinelTest`, `PhotoEndToEndKioskInvalidationTest`, `CatalogWarningServiceExtraCodesTest`…) ·
`tests/Feature/Items/` = 3 · `tests/Feature/Menu/` = 32 · `tests/js/catalogStudio*.spec.js` = 5 · `items` actifs = **59**, `item_categories` = 64 lignes (9 actives réelles), `taxes` = **53 lignes dont 6 légitimes**.

## §0.7 — Contradictions détectées et tranchées
- **C-CONST** (index) : paramétrer ≠ multi-tenant ; gate G0.
- **C-TAX** — `config/menu.php:80` `default_tax_id = 3` (10 %) mais la création rapide du Studio envoie `tax_id = this.defaultTaxId` (`CatalogStudioComponent.vue:490`) et l'article
  mesuré naît avec `tax_id = 1` « No-VAT 0 % ». Tranché : **la taxe par défaut est un réglage** (via ONB-05 ou `settings` du catalogue), le Studio et l'API la respectent ; W1 établit d'où vient le 1.
- **C-CHANNEL** — `ItemRequest.php:69` valide `channels.* in kiosk,pos,web` mais un canal inconnu est accepté à la création (mesuré 201). Tranché : c'est un défaut (forme du payload), pas une tolérance.
- **C-CREATE** — `itemRoutes.js:62-80` documente `?create=1` ; mesuré inopérant (deux passages), `/admin/items/create` fonctionne. Tranché : un seul chemin, l'autre supprimé.
- **C-WIZARD** — `ProductCreateWizardComponent.vue:1-19` « SKELETON — TODO » : terminer (recommandé, il orchestre 9 endpoints existants) ou retirer. Gate G-GUIDE.

## §0.8 — Le commerçant-type et ses questions
Karim, 33 ans, 45 produits, 6 catégories, sauces/viandes/suppléments partout, ferme à 23 h. 1. « Je mets ma carte où ? Une seule page ? » 2. « Pourquoi mon burger est à 0 % de TVA ? »
3. « Je tape 8,50 et ça refuse ? » 4. « J'ai un fichier Excel de ma carte : je l'importe ? et si une ligne est fausse ? » 5. « Si je supprime un produit, mes ventes d'hier disparaissent ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés — sortie brute)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Hub | **ÉCLATÉE** (5 entrées) | `CatalogStudioComponent.vue:4-24,39-51,490` · `CatalogHubComponent.vue:8-9,23-66` · `itemRoutes.js:41-50,62-80,94-107` · `BackendMenuComponent.vue:94-99,109,120-127` | `tests/js/catalogStudio{AddCategoryUx,CategoryQuery,CategoryWizardEntry,QuickCreateUniversal,Routing}.spec.js` |
| S2 Fiche | **FONCTIONNELLE, bords cassants** | `ItemRequest.php:42-89` (`:69` channels, `:78` barcode, `:79` kds_station max:32, `:100-178` withValidator) · `ItemController.php:32,200-221` · `ItemShowComponent.vue:12-48` (7 onglets) · `ItemCreateComponent.vue:14-90` · `wizard/ProductCreateWizardComponent.vue:1-19` (squelette) · `app/Models/Item.php:20-77,88-115,133-138` · migration `2026_04_20_230000_add_kds_station_to_items.php:16-22` (enum) · `config/catalog_v15.php:160` (protection suppression) | `tests/Feature/Catalog/` 16 · `tests/Feature/Items/` 3 |
| S3 Catégories/taxes/attributs | **CACHÉES, table taxes polluée** | `ItemCategoryController` (`routes/api.php:513-523`) · `ItemCategoryService.php:172-191` (garde suppression non vide) · `settings/ItemCategory/ItemCateogryListComponent.vue` (faute dans le nom, `settingRoutes.js:8`) · `TaxController` (`:505-511`) · `ItemAttributeController` (`:525-531`) · `app/Models/ItemAttribute.php` (+ `2026_04_22_000010` min/max/repeat) · `database/seeders/TaxTableSeeder.php` | `tests/Feature/Menu/` 32 (partiel) |
| S4 Import/export | **NON ÉPROUVÉ** | `ItemController::export` `:200-207`, `::import` `:218-226`, `ItemImportRequest`, `app/Imports/ItemImport.php`, `app/Exports/ItemExport.php`, `public/file/itemImportSample.xlsx` ; catégories `:515-517` | aucun test d'import → À CRÉER |

**Sortie d'ancrage brute** : `ls tests/Feature/Catalog | wc -l` → 16 · `ls tests/Feature/Items` → 3 · `ls tests/Feature/Menu` → 32 · `ls tests/js | grep -ci catalogStudio` → 5 ·
`grep -n "default_tax_id" config/menu.php` → `:80 => 3` · `grep -n "tax_id" CatalogStudioComponent.vue` → `:490 fd.append("tax_id", this.defaultTaxId …)` ·
`SELECT id,name,code,tax_rate,status FROM taxes` → 53 lignes : 6 légitimes (`No-VAT 0`, `VAT 5`, `VAT 10`, `GST 5`, `GST 10`, `VAT 5.5`), 21 « TVA nn% » incohérentes (`status 1`), 26 `AUDIT-KIOSK-MULTI TVA 0` (`status 10`) ·
`ls app/Services | grep Item` → `ItemService, ItemVariationService, ItemExtraService, ItemAddonService, ItemAttributeService, ItemCategoryService, ItemCategoryHierarchyService` · `SELECT COUNT(*) FROM items WHERE deleted_at IS NULL AND status=5` → 59.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (extrait de `recon/Z1_catalogue_wizard.md`)
**Marche** : création catégorie + article en 9-10 clics ; doublons, nom 300, catégorie inexistante, prix négatif → 422 FR ; course de doublon → 1 × 201 + 1 × 422 ; 7 onglets ; onglet
Composition « Final : PricingService backend » ; suppression de catégorie non vide bloquée ; modale Attribut avec aide ; `.svg` et 27 Mo refusés.
**Constats** : [P1] `kds_station` inconnue → « SQLSTATE[01000] Data truncated » · [P1] canal inconnu → 201 · [P2] virgule décimale → « This price must be a number. » ·
[P2] prix 0 → « negative amount not allow » · [P2] `?create=1` inopérant · [P2] toasts anglais · [P2] `.svg` → « La photo du produit est obligatoire », 27 Mo → 413 HTML brut ·
[P2] composer par article / wizard avancé = écrans de repli silencieux · [P3] boutons wizard du Studio (superposition 7 s). **Angles morts** : concepts non expliqués, Catégories/Attributs/Taxes
cachés, TVA 0 % par défaut, pas de sémantique gratuit/inclus (→ 03), images de repli Cayenne. **Cayenne** : filiales de démonstration dans l'aperçu, templates, menu de base.
**Non mesuré (à rejouer W1)** : composer par catégorie de bout en bout, import Excel (fichiers `ok.xlsx`, `broken.xlsx`, `missing_col.xlsx` prêts dans `tmp/recon/Z1/`), suppression d'article.

# §3 — SOUS-SYSTÈME 1 : UN SEUL LIEU — LE STUDIO COMME HUB

### Contrat
Un commerçant qui cherche « ma carte » trouve UNE entrée ; catégories, taxes, attributs y sont à portée de clic ; « Articles », « Catalogue », « Produits & Stock » ne se concurrencent plus.

## Sub 1.1 — Inventaire des entrées et décision de forme
**Ancrages** : `BackendMenuComponent.vue:94-99,109,120-127` (Catalogue, Attribut d'articles, Produits & Stock), `itemRoutes.js:41-50,94-107`, `MenuComponent.vue:95,99,103` (Catégories, Attributs, Taxes cachées).
**Tâches**
- **T-1.1.1** — Cartographier les 5 entrées (libellé, route, composant, permission, ce qu'on y fait) et mesurer le parcours de Karim (clics, changements d'écran) — table MISSION §8.
  • test : (À CRÉER à `tests/js/catalogEntryPointsInventory.spec.js` — sentinelle : toute nouvelle entrée « catalogue » doit être déclarée)
- **T-1.1.2** — Proposer la forme cible (G-HUB) : Studio = hub avec onglets **Carte · Catégories · Taxes · Attributs · Import**, « Produits & Stock » reste le hub stock (ONB-08).
  Les dé-cachages / renommages de menu sont une **fiche de renvoi ONB-05**.
- **T-1.1.3** — Implémenter les onglets du hub en réutilisant les composants existants (`settings/ItemCategory/*`, `settings/Tax/*`, `settings/ItemAttribute/*`) sans les dupliquer ; corriger la faute `ItemCateogryListComponent.vue` (renommage + import `settingRoutes.js:8`).
  • test : (À CRÉER à `tests/js/catalogStudioHubTabs.spec.js`) · visuel : `http://127.0.0.1:8802/admin/items/studio` à 1366/1024/768
  • au-delà : rechargement sur un onglet profond conserve l'onglet ; retour arrière navigateur ; double clic sur un onglet.
- **T-1.1.4** — Aide contextuelle des concepts (« Variante = choix qui change le prix, Extra = supplément payant, Supplément = produit ajouté… ») via `CatalogConceptHelpComponent.vue` visible dès la fiche.
  • test : `tests/js/catalogStudioQuickCreateUniversal.spec.js` (existant, étendre)
**Acceptation** : C5 ≤ 12 clics mesurés · 2 tests VERTS · captures lues · G-HUB tranché.

# §4 — SOUS-SYSTÈME 2 : FICHE PRODUIT SÛRE

## Sub 2.1 — Validation sans erreur brute
**Ancrages** : `ItemRequest.php:42-89,100-178`, migration `2026_04_20_230000` (enum station), `ItemService.php`.
**Tâches**
- **T-2.1.1** — ROUGE d'abord : 20 payloads invalides (station inconnue, canal inconnu, prix « 8,50 », prix 0, nom 191, code-barres dupliqué, catégorie inactive, taxe inactive, allergène inconnu, image .svg/.html/27 Mo) → aujourd'hui : SQL brut, 201 indu, anglais.
  • test : (À CRÉER à `tests/Feature/Catalog/ItemRequestEdgeCasesTest.php`)
- **T-2.1.2** — Règles : `kds_station` `in:` **source unique** (`App\Enums\KdsStation` À CRÉER, consommée par `ItemRequest`, le KDS et les imprimantes — vocabulaire aujourd'hui divergent `cuisine_chaude` vs `kitchen_hot`, mesuré Z7) ; `channels` effectif (forme tableau) avec défaut = tous ; prix : virgule normalisée côté client ET serveur, 0 autorisé, négatif refusé ; messages FR (`lang/fr/validation.php`).
  • test : le même, VERT · au-delà : deux onglets modifiant le même article → dernier gagne, prouvé ; rechargement pendant l'enregistrement.
- **T-2.1.3** — Taxe par défaut : réglage `catalog.default_tax_id` (lecture `config/menu.php:80` en repli) appliqué par `ItemService` quand `tax_id` absent, et par le Studio (`:490`) — établir en W1 d'où vient le `1`.
  • test : (À CRÉER à `tests/Feature/Catalog/ItemDefaultTaxTest.php`) · C1
- **T-2.1.4** — Images : validation par contenu (finfo), dimensions minimales, message avant envoi « JPG/PNG ≤ 2 Mo », 413 intercepté côté client ; repli d'image générique (`config/menu_images.php` : clé `default` neutre au lieu des slugs Cayenne).
  • test : `tests/Feature/Catalog/{OptionPhotoTest,ProductPhotoAuthzTest,PhotoEndToEndKioskInvalidationTest}.php` (existants) + (À CRÉER à `tests/Feature/Catalog/ItemPhotoValidationTest.php`) · C7
**Acceptation** : C2 = 0 · 3 tests VERTS · captures des 4 messages d'erreur lues.

## Sub 2.2 — Création guidée et fiche complète
**Ancrages** : `wizard/ProductCreateWizardComponent.vue:1-19`, `ItemCreateComponent.vue:14-90`, `ItemShowComponent.vue:12-48`, `itemRoutes.js:62-80`.
**Tâches**
- **T-2.2.1** — Décision G-GUIDE : terminer le wizard guidé (nom/prix/catégorie → image → variantes/extras/suppléments → station/canaux/allergènes → aperçu) ou le retirer. Recommandation : **terminer**, en 4 étapes, sans nouvelle API.
- **T-2.2.2** — Implémenter ; champs obligatoires pour un article vendable : catégorie, prix, taxe, **station ≠ none** si canal cuisine (avertissement bloquant expliqué), au moins un canal.
  • test : (À CRÉER à `tests/js/productCreateWizardFlow.spec.js`) · visuel : `/admin/items/create`
  • au-delà : annulation à l'étape 3 → rien en base ; rechargement → brouillon conservé (localStorage) ; double soumission → un seul article.
- **T-2.2.3** — Un seul chemin de création (`/admin/items/create`), `?create=1` retiré ou réparé (`itemRoutes.js:62-80`) ; duplication d'article prouvée (`ItemDuplicationTest.php` existant, étendre aux images/options).
**Acceptation** : question 1 et 3 de Karim = OUI · 2 tests VERTS · G-GUIDE tranché.

# §5 — SOUS-SYSTÈME 3 : CATÉGORIES, TAXES, ATTRIBUTS

## Sub 3.1 — Taxes saines et lisibles
**Ancrages** : `TaxController` (`routes/api.php:505-511`), `TaxRequest.php`, `settings/Tax/*`, `TaxTableSeeder.php`, table `taxes` (53 lignes).
**Tâches**
- **T-3.1.1** — Inventaire des 53 lignes : 6 légitimes, 21 « TVA nn% » (taux incohérents, `status 1`), 26 `AUDIT-KIOSK-MULTI` (`status 10`) ; vérifier qu'aucun article ni commande ne les référence (`items.tax_id`, snapshots).
  • preuve : requêtes consignées MISSION §8 · gate **G-TAX** avant tout archivage (fiscal-adjacent : jamais de suppression d'une taxe référencée).
- **T-3.1.2** — Taxes FR par défaut pour une installation neuve : 5,5 % / 10 % / 20 % / 0 %, libellés FR, `TaxTableSeeder` corrigé (coordination ONB-12 pour le socle).
  • test : (À CRÉER à `tests/Feature/Catalog/TaxSeederFrenchDefaultsTest.php`)
- **T-3.1.3** — Modifier le taux d'une taxe référencée par des commandes : interdit (créer une nouvelle taxe) — règle + message ; snapshot des commandes intact.
  • test : (À CRÉER à `tests/Feature/Catalog/TaxRateImmutableWhenReferencedTest.php`) · au-delà : désactiver une taxe utilisée par 12 articles → avertissement listant les articles.
**Acceptation** : C6 = 6 actives · 2 tests VERTS · G-TAX tranché.

## Sub 3.2 — Catégories et attributs
**Ancrages** : `ItemCategoryService.php:172-191`, `ItemCategoryHierarchyService.php` (profondeur 2), `ItemCategoryRequest`, `ItemAttributeRequest`, `app/Models/ItemAttribute.php` (min/max/allow_repeat), `settings/ItemCategory/CategoryUploadComponent.vue`.
**Tâches**
- **T-3.2.1** — Catégorie : tri (`sort`, `kiosk_sort`, `pos_sort`), canaux, image, sous-catégorie (profondeur 2) : parcours prouvé + effet borne/caisse (ordre d'affichage).
  • test : `tests/Feature/Catalog/CategoryRenameSyncTest.php` (existant) + (À CRÉER à `tests/Feature/Catalog/CategoryOrderingAcrossSurfacesTest.php`)
- **T-3.2.2** — Attributs (groupes de choix) : création depuis la fiche produit sans passer par Réglages ; libellés et aide (déjà bons, mesuré) ; suppression d'un attribut utilisé → refus expliqué.
  • test : (À CRÉER à `tests/Feature/Catalog/ItemAttributeDeleteGuardTest.php`)
- **T-3.2.3** — Suppression d'article référencé par des commandes : `catalog_v15.item_deletion.protect_force_delete_when_referenced` (`config/catalog_v15.php:160`) prouvé + message FR « archivé, ventes conservées » (question 5 de Karim).
  • test : `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` (existant, étendre au message)
**Acceptation** : 3 tests VERTS · captures lues.

# §6 — SOUS-SYSTÈME 4 : IMPORT / EXPORT FIABLE

**Ancrages** : `ItemController.php:200-226`, `app/Imports/ItemImport.php`, `app/Exports/ItemExport.php`, `ItemImportRequest`, `public/file/itemImportSample.xlsx`, `ItemUploadComponent.vue`, catégories `routes/api.php:515-517`.
**Tâches**
- **T-4.1.1** — ROUGE d'abord : importer `ok.xlsx`, `broken.xlsx` (prix négatif, doublon), `missing_col.xlsx` (`tmp/recon/Z1/`) → consigner le comportement réel (tout-ou-rien ? lignes partielles ? message ?).
  • test : (À CRÉER à `tests/Feature/Catalog/ItemImportEdgeCasesTest.php`)
- **T-4.1.2** — Prévisualisation avant import (tableau lignes / statut / motif), import transactionnel par lot, **idempotence** (clé = nom normalisé + catégorie : ré-import = mise à jour), catégories/taxes inconnues → ligne refusée (pas de création silencieuse), rapport téléchargeable.
  • test : le même + (À CRÉER à `tests/Feature/Catalog/ItemImportIdempotencyTest.php`) · C3
  • au-delà : fichier de 5 000 lignes (temps, mémoire) ; encodage accentué ; colonnes réordonnées ; import interrompu à la ligne 30.
- **T-4.1.3** — Export réimportable : `ItemExport` produit exactement les colonnes attendues par `ItemImport` (round-trip test) ; catégories idem.
  • test : (À CRÉER à `tests/Feature/Catalog/ItemExportImportRoundTripTest.php`)
- **T-4.1.4** — Gabarit `itemImportSample.xlsx` en français, avec exemples génériques (pas de produit Cayenne), colonnes documentées (station, canaux, allergènes, code-barres).
**Acceptation** : C3 VRAI · 3 tests VERTS · question 4 de Karim = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES

| Fonction \ scénario | annulation | rechargement pendant l'enregistrement | double soumission | deux onglets | rôle inférieur (API) | données vides | volume | réseau/worker coupé | effet borne / caisse / KDS / rapports | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Création article | `productCreateWizardFlow.spec.js` | idem (brouillon) | `ItemRequestEdgeCasesTest` (course mesurée 201/422) | `ItemRequestEdgeCasesTest` (version ?) | `ProductPhotoAuthzTest`, `pos@` 403 sur `POST item` (mesuré Z3 : à confirmer) | prix vide → 422 FR | 200 articles (pagination Studio) | menu borne servi depuis cache (`ItemUpdateInvalidatesKioskCacheSentinelTest`) | C4 3/3 | supprimer → `ItemDeletionWithOrderHistoryTest` | prix 0 / négatif / 99999 ; nom 190/191 ; emoji ; `<script>` |
| Catégorie | `catalogStudioAddCategoryUx.spec.js` | idem | doublon 422 (mesuré) | idem | 403 | image absente → repli générique | 64 catégories, profondeur 2 | — | ordre borne/caisse (`CategoryOrderingAcrossSurfacesTest`) | supprimer non vide → refus (mesuré) | nom 190, sous-sous-catégorie refusée |
| Taxe | N/A | `TaxRateImmutableWhenReferencedTest` | idem | idem | 403 | taux vide → 422 | 53 lignes → 6 | — | ticket TVA (`tests/Feature/PosReceiptTaxLinesTest.php` existant) | réactiver une taxe archivée | 0 %, 100 %, 20,5 %, négatif |
| Import | annulation à la prévisualisation → 0 écriture | `ItemImportEdgeCasesTest` (transaction) | même fichier 2× → `ItemImportIdempotencyTest` | N/A motivé | 403 (`items_create`, `ItemController.php:32`) | fichier vide / en-têtes seules | 5 000 lignes | interruption ligne 30 → rollback | C4 après import | export → ré-import identique | colonnes manquantes, encodage, prix « 8,50 » |
| Image | fermer le sélecteur | — | — | — | `ProductPhotoAuthzTest` | pas d'image → repli | 27 Mo → message FR | — | borne invalidation (`PhotoEndToEndKioskInvalidationTest`) | supprimer l'image → repli | .svg, .html, 0 octet, 20 000 px |

# §A — ARMÉE D'AGENTS
Architecte (frontière Studio/Réglages, enum station partagé avec KDS/imprimantes) · Sécurité (upload, import Excel = surface d'injection de formules, IDOR sur `item/{id}`) · UX/A11y (hub, 3 gabarits, clavier dans les tiroirs) ·
**Psychologie commerçant** (vocabulaire Variante/Extra/Supplément, peur de « casser la carte », confiance dans l'aperçu) · DBA (`taxes` pollution, index `items.slug`, `deleted_at` vs slug unique) · SRE (cache menu borne, invalidation) ·
Implémenteur unique · ROUGE (rejoue `z1_a_create_full.js` + fichiers Excel après chaque correctif) · QA visuel + ROUGE visuel (contestation indépendante des captures) · **Jalonneur** (6 points, refuse au premier « non »).
Discipline : 5 lecture seule en un message ; ROUGE avant tout « fini » ; disque `reports/test-e2e/ONB02_CATALOGUE_DE_ZERO/<round>/wave-<W>-<rôle>.json` ; contrat `[P0..P3] file:line — titre / reproduction / preuve / recommandation` ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, gates statués | séquentiel | — |
| **W1** | Reconnaissance ciblée : rejouer Z1 (création, bords, composer catégorie en lecture, import des 3 fichiers, suppression) ; origine du `tax_id = 1` ; inventaire `taxes` | fan-out lecture seule | — |
| **W2** | S2 — validation, taxe par défaut, images (T-2.1.*) | séquentiel | — |
| **W3** | S3 — taxes saines, catégories, attributs (T-3.*) | séquentiel | **G-TAX** pour l'archivage |
| **W4** | S4 — import/export (T-4.*) | séquentiel | — |
| **W5** | S1 + S2.2 — hub Studio, création guidée (T-1.*, T-2.2.*) | séquentiel | G-HUB, G-GUIDE |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Catalog|Items|Menu"`, Vitest, Playwright `tests/e2e/onb02-*.spec.js` (À CRÉER) sur :8802, diff gelé 0, BRAIN | séquentiel | — |

Ordre volontaire : bords et fiscalité (W2-W3) avant ergonomie (W5) — un beau hub qui crée des articles à 0 % de TVA est pire que l'état actuel.
**§X.8** point de contrôle 6 points (Jalonneur) · **§X.9** échec de convergence : STOP, `STUCK_*.md`, 4 options, attendre · **§X.10** interruption : `wip(<vague>)`, `INTERRUPT_*.md`, BRAIN §2.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-TAX** | Archiver/supprimer les 47 taxes parasites ; taxes FR par défaut ; taux d'une taxe référencée immuable | Propriétaire | accord ligne par ligne (inventaire T-3.1.1) | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque T-3.1.1 (archivage), pas l'inventaire |
| **G-DEFAULT-TAX** | Taxe par défaut des nouveaux articles = 10 % (restauration sur place/à emporter) | Propriétaire | confirmation | MISSION §6 | EN ATTENTE — bloque T-2.1.3 |
| **G-HUB** | Forme du hub Studio (onglets Carte/Catégories/Taxes/Attributs/Import) + renommages de menu (via ONB-05/11) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-1.1.3 |
| **G-GUIDE** | Terminer ou retirer `ProductCreateWizardComponent.vue` | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-2.2.2 |
| **G-CACHE** | Dé-cacher Catégories / Attributs / Taxes (exécuté par ONB-05) | Propriétaire | tableau | `MISSION_ONB05` §6 | EN ATTENTE — hors de ce GOAL |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `superpowers:test-driven-development` · `CONSTITUTION.md` · `SYSTEM_MAP.md §5` · `CLAUDE.md §3bis (SSOT : jamais inventer un produit), §3ter, §7` ·
`plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-02) · `recon/Z1_catalogue_wizard.md` · `recon/Z0_modele_catalogue_wizard_reglages.md §A-B` · `recon/Z0_carte_dashboard.md §1, §4, §9` ·
`tmp/recon/Z1/{z1_a_create_full.js,lib.js,ok.xlsx,broken.xlsx,missing_col.xlsx}` · `plans/GOAL_CAISSE_PARFAITE_2026-08-22.md` (S3 garde `menu:reset`) · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (NAV1-01 catalog-hub).

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes (§X.8) ; 2. C1..C7 VRAIS, chiffres dans MISSION §8 ; 3. PHPUnit ≥ 5 194 + ≥ 12 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé = 0 ;
5. NF525 ajout seul, **aucune taxe référencée modifiée** ; 6. gates tranchés ou différés par écrit ; 7. BRAIN §2/§3/§4 + MISSION §8 vrais ; 8. deux cycles identiques ; 9. fiches de renvoi écrites (ONB-05 dé-cachage/renommage, ONB-03 composer, ONB-08 stock, ONB-12 seeders).
**Interdit** : exécuter `menu:reset-le-cayenne` · inventer un produit (SSOT = `items`) · supprimer une taxe/catégorie/article référencé · déclarer vert sans capture lue · approuver un gate.
> Le sens : Karim recopie sa carte un soir, à 10 % de TVA, sans un seul message en anglais ni un seul appel — et la borne la vend le lendemain matin.
