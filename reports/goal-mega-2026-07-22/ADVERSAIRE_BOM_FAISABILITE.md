# ADVERSAIRE — Faisabilité plan BOM/Stock intelligent (2026-07-23)

Cible : `plans/ARCH_STOCK_INTELLIGENT_BOM_2026-07-23.md`. Méthode : code réel + DB `foodking_e2e` (3 279 orders, 3 343 order_items, 2026-05-28→07-22). Read-only.

## Verdicts par claim

### C1 « composition_snapshot = source de consommation parfaite » — TIENT AVEC RÉSERVES (3 trous prouvés)
POUR : builder unique `app/Services/Pricing/CompositionSnapshotBuilder.php:19` (`SCHEMA_VERSION=1`), écrit à la création (`PricingService.php:266-291`), immuable. Snapshots réels = ids + noms (`variation_id/attribute_id/extra_id/addon_id/addon_item_id`). Tailles = items séparés (`items` : Tacos M=26, Tacos L=97, Big Tacos=27, Petite/Grande Frites=33/34) → recette par item, pas de problème M/L. Crudités MIEUX que le plan ne le dit : extras structurés à 0€ (Salade=77, Tomate=78, Oignon=79). Frites de menu : addon `role=menu_frites` explicite.
CONTRE :
1. **Suppléments viande/sauce = extras GÉNÉRIQUES** : snapshot 5435 = `extra 394 "Viande supplémentaire" ×2` — QUELLE viande (75 g steak vs 120 g poulet) vit uniquement en texte libre (`order_items.instruction` 5484 : « Sauce : Algérienne, Andalouse »). La viande = ingrédient le plus cher, exemple phare owner → approximation forcée ou parsing texte fragile.
2. **Boisson de menu NON identifiée** : commande 5671 = 1 ligne Suprême + addon `menu_boisson` (addon_item_id=1 = le menu composite), AUCUNE ligne canette, instruction vide. 0 « Coca » dans les 3 305 snapshots ; 42 instructions seulement nomment un parfum. Conso par parfum impossible pour les menus (agrégat « 1 canette » OK).
3. **Doses** : sauces/viandes qty=1 sans grammage — les poids viennent des recettes (prévu), mais « Mixte » vendu = 1 variation, la décomposition 1 steak+120 g poulet est recette-side : OK.

### C2 « SSOT catalogue accrochable » — TIENT AVEC RÉSERVES
Ids stables présents dans les snapshots ✅. MAIS la doctrine ingrédient existante dédup par NOM : `IngredientService::listAll` (app/Services/Ingredients/IngredientService.php:52-64) groupe 535 ItemExtra rows en ~43 noms — une recette par `extra_id` doit couvrir N rows/nom logique → table de mapping many obligatoire (le plan l'ignore). `stock_levels` polymorphe réutilisable en théorie mais : quasi vide (2 rows `App\Models\Item`), `on_hand` unsigned + `CHECK on_hand>=0` (migration 2026_04_27_143120:27-29) interdit le stock théorique NÉGATIF (inévitable avant inventaire correcteur), `delta` int entier, `reason` ENUM fermé 6 valeurs (pas d'invoice_in/inventory). → tables `ingredient_*` dédiées (signed decimals) en mirror du pattern `StockService::decrementForOrder` (app/Services/Stock/StockService.php:44, idempotent + triggers append-only 2026_05_18) : petit build, pas refonte.

### C3 « Rejouable rétroactivement » — TIENT AVEC RÉSERVES
98,3 % des order_items en schema v1 depuis le PREMIER jour (3 286/3 343 ; 38 NULL, 19 sans version, 51 « Boisson Seule » sans parfum). MAIS piège non dit : la DB active (.env) = `foodking_e2e`, mêlant commandes réelles et **commandes de tests e2e/chaos sans flag `is_test`** → food cost historique pollué sauf replay sur la DB VPS production uniquement.

### C4 « Écrans réutilisables » — TIENT AVEC RÉSERVES
`IngredientController` (app/Http/Controllers/Admin/IngredientController.php) = projection VIRTUELLE availability-only (globalId `type:id`, toggle 86 cascade par nom) — zéro quantité. `/m` (routes/web.php:84) = 2 endpoints (`catalog`/`toggle`, MobileStockController) — inventaire, photo facture, « à acheter » backend = à créer. Dashboard rupture admin existe. Bases d'écrans OK, la quasi-totalité du quantitatif est neuve.

### C5 Charges/compta — FAUX comme « existant »
Aucun modèle/migration Expense/Supplier/Invoice/Purchase (greps vides). Seul précédent : `DailyBookEntry` (carnet PIN hors NF525, type expense/advance/note, label+amount plat) — pas de fournisseur, lignes, TVA, lien ingrédient, prix moyen. B4/B5 = **domaine neuf complet**.

### C6 Onboarding IA via `menu:*` — TIENT AVEC RÉSERVES FORTES
Write-path PROUVÉ scriptable : `MenuCommand` config-driven (config/menu.php, create/reset/verify), `menu:reset-le-cayenne` (hardcodé), patchs `menu:ensure-kids-menu-steps`, `menu:ensure-sauce-supplement-extras`, `menu:assign-*-vat`, seeders wizard profiles (AlignFritesWizardProfilesSeeder…). MAIS tous sont des one-shots Le Cayenne ; `generate-menu-from-api.mjs` INTROUVABLE dans ce repo ; **aucun importeur générique** JSON→catalogue+profils. B6 = construire ce pipeline + images + recettes réelles = produit à part.

## Chantiers cachés sous-estimés
1. Identité suppléments viande/sauce : capture structurée = toucher l'intake — **borne (KioskWizardComponent.vue) ET caisse (pos-wizard.js) sont FROZEN** → LOCK owner, ou approximation « mix pondéré » assumée. Builder non-frozen : `schema_version=2` additif possible.
2. Boisson de menu : idem (approx « canette générique » ou intake).
3. Pollution test-data du replay (choisir la DB de replay).
4. Tables ingredient_stock signées/décimales (pas de réutilisation forcée de stock_levels).
5. Mapping recette↔N ids par nom logique.
6. Domaine achats/fournisseurs entier (B4/B5).
7. Importeur générique B6.

## Décision adversaire
**OUI — exécutable par phases sans refonte**, SOUS CONDITIONS : (a) P1/P2 avec approximations EXPLICITES dans la fiche owner (supplément viande = mix moyen, canette de menu = générique, doses sauces) ; (b) nouvelles tables ingrédient (mirror StockService, pas stock_levels) ; (c) replay = DB production, pas e2e ; (d) toute précision « quelle viande » exige LOCK frozen (seul point qui flirte avec la refonte) ; (e) B4/B5 chiffrés comme domaine neuf, B6 comme produit séparé. La claim « ADDITIVE » tient ; « source parfaite » et « commandes menu:* existantes » sont sur-vendues.
