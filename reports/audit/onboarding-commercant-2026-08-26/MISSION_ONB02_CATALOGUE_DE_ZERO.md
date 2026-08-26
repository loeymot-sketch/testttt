# MISSION ONB-02 — CATALOGUE DE ZÉRO · Rapport de mission
- GOAL : `plans/GOAL_ONB02_CATALOGUE_DE_ZERO_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, arbre principal `:8766`, base locale `foodking_e2e`)
- Port : **8802** · Voie : CENTRAL, sous-voie « catalogue » · Parallèle possible avec : ONB-01, 05, 06, 07, 08, 09, 10 (vague A). **ONB-03 et ONB-04 attendent la fin (ou la stabilisation) de ce GOAL.**

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-02 (catalogue de zéro). Lis dans l'ordre : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md,
PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB02_CATALOGUE_DE_ZERO.md, plans/GOAL_ONB02_CATALOGUE_DE_ZERO_2026-08-26.md, puis recon/Z1_catalogue_wizard.md et
recon/Z0_modele_catalogue_wizard_reglages.md (§A-B). Pré-vol §0.1 : worktree .claude/worktrees/onb02-catalogue depuis HEAD, .env avec
APP_URL=http://127.0.0.1:8802, .env.testing, vendor/node_modules en liens durs, serveur 8802, PLAYWRIGHT_BASE_URL, filet backup/pre-onb02 + dump
des tables catalogue. ⛔ Jamais `menu:reset-le-cayenne`, jamais migrate:fresh, jamais un produit inventé (SSOT = table items). Puis « lance le GOAL » :
W0 → W1 (rejoue /Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z1/z1_a_create_full.js et importe ok.xlsx / broken.xlsx / missing_col.xlsx sur 8802 ;
si le dossier tmp a disparu, reconstruis depuis recon/Z1 §3) → W2..W6. Pipeline ultra-audit-profond, 5 spécialistes lecture seule en un message,
implémenteur unique, ROUGE avant tout « fini », Jalonneur §X.8, matrice §S, convergence = deux cycles identiques. Fichiers possédés = §0.2 ; composer
→ ONB-03, stock → ONB-08, menu → ONB-05 : fiches de renvoi dans ce rapport §8. Préfixe GOAL-ONB02, nettoyage DÉFINITIF (pas soft) en fin de vague,
tests via safe-test.sh. Jamais de push. Gates §G : proposer, ne pas trancher. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Le catalogue est la deuxième heure d'un nouveau commerçant et la matière première de tout le reste (wizard ONB-03, extraction IA ONB-04, stock ONB-08, borne, caisse,
cuisine). Aujourd'hui il « marche » (création en 9 clics, doublons refusés, suppression protégée) mais **ment aux bords** : TVA 0 % par défaut, erreur SQL brute,
canal inconnu accepté, anglais, import jamais éprouvé, cinq entrées de menu pour un concept. Persona Karim (45 produits, Excel de sa carte).

## 2. ÉTAT MESURÉ LE 2026-08-26 (`recon/Z1_catalogue_wizard.md`, `tmp/recon/Z1/{a1_result.json,z1_a_result.json}`, 50 captures `recon/screens/Z1/`)
**2.1 Périmètre** : Studio, catalog-hub, items (+`?create=1`, `/create`), fiche 7 onglets, composer catégorie, composer article + wizard avancé (drapeau false), Réglages
Catégories/Attributs/Taxes par URL ; API création/bords ; lignes de base menu borne (`kiosk_menu_baseline.json`, 299 Ko) et articles POS.
**2.2 Ce qui marche** : catégorie + article en 9-10 clics, article immédiatement vendable (`status 5`, `is_available true`) ; 422 FR pour doublon, nom > 190, catégorie
inexistante, prix négatif ; course de doublon 201/422 ; onglet Composition « Final : PricingService backend » ; suppression de catégorie non vide bloquée
(`ItemCategoryService.php:172-191`) ; modale Attribut avec aide ; `.svg` 422, 27 Mo 413.
**2.3 Constats**
| Sév. | Constat | Preuve |
|---|---|---|
| P1 | `kds_station` inconnue → 422 avec « SQLSTATE[01000] Data truncated for column 'kds_station' » | `z1_a_result.json edges.kdsInconnu` ; `ItemRequest.php:79` (max:32, pas d'`in:`) |
| P1 | Canal inconnu accepté (201, article 241) malgré `channels.* in kiosk,pos,web` (`ItemRequest.php:69`) | `edges.chanInconnu` |
| P1 (fiscal) | Nouvel article créé avec `tax_id = 1` « No-VAT 0 % » ; `config/menu.php:80 default_tax_id = 3` (10 %) ; Studio envoie `this.defaultTaxId` (`CatalogStudioComponent.vue:490`) | `z1_a_result.json item`, `item_show_tax` |
| P1 (données) | Table `taxes` : 53 lignes, 6 légitimes, 21 « TVA nn% » incohérentes (`status 1`), 26 `AUDIT-KIOSK-MULTI TVA 0` (`status 10`) jamais nettoyées | `SELECT … FROM taxes` (chef de projet) |
| P2 | Prix « 8,50 » → « This price must be a number. » ; prix 0 → « This price negative amount not allow. » | `product_comma_toasts`, `edges.price0` ; capture `03-studio-produit-prix-virgule-refuse.png` |
| P2 | `?create=1` inopérant ; `/admin/items/create` fonctionne | `a1_result.json` pages |
| P2 | Toasts anglais (« Catégories Deleted Successfully. », « Articles Deleted Successfully. ») | `category_toasts` |
| P2 | `.svg` → « La photo du produit est obligatoire » ; 27 Mo → 413 HTML PHP brut (`post_max_size` 8 Mo) | `photo_edges` |
| P2 | Composer par article et Wizard avancé : écrans de repli silencieux (`wizard_per_item_demo=false`) | captures `a1-06`, `a1-07` → ONB-03 |
| P3 | Boutons « wizard » du Studio : superposition ~7 s puis pleine page (produit) / rien (catégorie) | `a1b-40..43` |
**2.4 Angles morts** : concepts non expliqués (hors modale Attribut), Catégories/Attributs/Taxes cachés, TVA 0 %, aucune sémantique gratuit/inclus (→ 03), repli d'images Cayenne.
**2.5 Cayenne** : filiales de démonstration dans l'aperçu, templates `tacos/sandwich…`, menu de base.
**2.6 Non mesuré** (à rejouer W1) : composer catégorie bout-en-bout, import Excel (fichiers prêts), suppression d'article, deux onglets.

## 3. CE QUI A DÉJÀ ÉTÉ FAIT (ne pas refaire)
- 2026-06-03 Wave C « Catalogue/Stock read-side » convergée (403/0 fail) ; 2026-07-21 `catalog-hub` ; 2026-08-13 NAV1-01 (hub) planifié non exécuté.
- Tests existants `tests/Feature/Catalog/` (16) : duplication, suppression avec historique, photos d'option, authz photos, canaux null, renommage catégorie, invalidation cache borne,
  photo bout-en-bout borne, avertissements catalogue ; `tests/Feature/Menu/` (32, dont `MenuResetDriftGuardTest`) ; `tests/js/catalogStudio*.spec.js` (5).
- Décisions en vigueur : SSOT produits = table `items` (CLAUDE.md §3bis) ; `menu:reset-le-cayenne` bloqué par garde (GOAL CAISSE PARFAITE S3) ; suppression protégée
  (`catalog_v15.item_deletion.protect_force_delete_when_referenced = true`).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Studio | `admin/items/CatalogStudioComponent.vue` | `:4-24` en-tête, `:39-51` wizard catégorie, `:490` `tax_id` | hub cible |
| Hub | `admin/items/CatalogHubComponent.vue` | `:8-9,23-66` onglets | Catalogue / Stock |
| Routes | `resources/js/router/modules/itemRoutes.js` | `:41-50` studio, `:62-80` `?create=1`, `:94-107` hub, `:13-25,108-153` composer (→ 03) | |
| Requête article | `app/Http/Requests/ItemRequest.php` | `:42-89` règles, `:69` channels, `:78` barcode, `:79` kds_station, `:100-178` withValidator | P1 ×2 |
| Contrôleur | `app/Http/Controllers/Admin/ItemController.php` | `:32` gate `items_create`, `:200-207` export, `:209` sample, `:218-226` import | |
| Modèle | `app/Models/Item.php` | `:20-47` fillable, `:49-77` casts, `:88-115` repli image par slug, `:133-138` conversions | `config/menu_images.php` |
| Station | `database/migrations/2026_04_20_230000_add_kds_station_to_items.php` | `:16-22` enum bar/cuisine_chaude/cuisine_froide/none | vocabulaire imprimantes divergent (Z7) |
| Fiche | `admin/items/ItemShowComponent.vue` `:12-48` · `ItemCreateComponent.vue` `:14-90` · `wizard/ProductCreateWizardComponent.vue` `:1-19` | 7 onglets ; squelette | |
| Catégories | `ItemCategoryController` (`routes/api.php:513-523`) · `app/Services/ItemCategoryService.php:172-191` · `ItemCategoryHierarchyService.php` · `settings/ItemCategory/ItemCateogryListComponent.vue` (faute, `settingRoutes.js:8`) | | cachées (`v1-hidden-modules.js`) |
| Taxes | `TaxController` (`:505-511`) · `TaxRequest.php` · `settings/Tax/*` · `database/seeders/TaxTableSeeder.php` · `config/menu.php:73,80` | | 53 lignes |
| Attributs | `ItemAttributeController` (`:525-531`) · `app/Models/ItemAttribute.php` · migration `2026_04_22_000010` | min/max/allow_repeat | réinjecté dans le menu principal |
| Import/export | `app/Imports/ItemImport.php` · `app/Exports/ItemExport.php` · `ItemImportRequest` · `public/file/itemImportSample.xlsx` · `admin/items/ItemUploadComponent.vue` | | aucun test |
| Services | `app/Services/{ItemService,ItemVariationService,ItemExtraService,ItemAddonService,ItemAttributeService}.php` | | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Catalog|Items|Menu|Tax"` → figer en W0 · `npx vitest run tests/js/catalogStudio*.spec.js` (5 fichiers) · `items` actifs 59 · `item_categories` 64 lignes ·
`taxes` 53 (6 légitimes) · `/api/frontend/menu` : 731 ms / 799 requêtes SQL (mesuré Z2 : à signaler, pas à corriger ici sauf régression).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Options | Recommandation | Si non tranché |
|---|---|---|---|---|
| G-TAX | Archiver les 47 taxes parasites ? Taxes FR par défaut 5,5/10/20/0 ? | oui / non | oui, après preuve « non référencées » | inventaire seul |
| G-DEFAULT-TAX | Taxe par défaut = 10 % ? | 10 % / 5,5 % / 0 % | **10 %** | articles à 0 % continuent |
| G-HUB | Studio = hub à onglets (Carte/Catégories/Taxes/Attributs/Import) ? | oui / garder 5 entrées | oui | W5 bloquée |
| G-GUIDE | Terminer le wizard guidé ? | terminer / retirer | terminer (4 étapes) | squelette reste |
| G-CACHE | Dé-cacher Catégories/Attributs/Taxes | via ONB-05 | oui | pages par URL seulement |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- Un article ou une catégorie **soft-supprimé garde son slug unique** : recréer le même nom → 422 « déjà utilisé » = faux positif. Nettoyer en `forceDelete` (script chef de projet : `cleanup_leftovers2.php`).
- Le sélecteur de tiroir (`.drawer form button[type=submit]`) a fait avorter le script Z1 (`99-a-fatal.png`) : préférer l'API pour les bords, le navigateur pour l'UX.
- Cache menu borne : `ItemUpdateInvalidatesKioskCacheSentinelTest` — vérifier après chaque création via `GET /api/frontend/menu`.
- Le serveur de dev est mono-requête ; `/api/frontend/menu` pèse 799 requêtes SQL : ne pas conclure « lent = cassé ».
- `menu:reset-le-cayenne` : garde de dérive, sortie 2 — interdit.
- `:8000` = autre worktree ; ta session = **:8802**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Constats nouveaux : — · Décisions : — · Fichiers touchés : — · Fiches de renvoi : ONB-05 (dé-cacher Catégories/Attributs/Taxes, renommer « Articles »/« Catalogue »), ONB-03 (composer, drapeau par article), ONB-08 (stock, `kds_station=none`), ONB-10 (vocabulaire des stations partagé avec imprimantes), ONB-12 (`TaxTableSeeder`, `menu_images` génériques), ONB-11 (toasts anglais) · État final : —
