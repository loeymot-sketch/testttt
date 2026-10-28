# FICHES DE CADRAGE DES 14 GOAL — décisions du chef de projet (2026-08-26)

> Chaque rédacteur lit SA fiche + `_CONSIGNE_REDACTEUR.md` + les gabarits + l'index. La fiche fixe le PROBLÈME, la PERSONA,
> les SOUS-SYSTÈMES imposés, les DÉCISIONS DE CONCEPTION déjà prises par le chef de projet (à ne pas rouvrir sans motif écrit),
> les fichiers possédés/interdits, les gates et les lectures. Le rédacteur ancre, détaille, chiffre — il n'invente pas.
> Règle commune : tout ce qui touche la visibilité du menu (`v1-hidden-modules.js`, `settings/MenuComponent.vue`,
> `BackendMenuComponent.vue`) est demandé à ONB-05 ; tout ce qui touche `PricingService`/fiscal est sous LOCK (ONB-03 seul).

---

## ONB-01 — IDENTITE_ETABLISSEMENT
**Problème prouvé** : l'identité d'un établissement est éclatée entre Entreprise (`.env` via Smartisan, nom/adresse/contact),
Filiale (SIRET, TVA intra, `legal_footer`, barème livraison), Site (`.env` : formats, devise, langue) et Thème (3 logos, caché) ;
**aucun éditeur d'horaires d'ouverture** ; **aucune couleur** réglable ; Thème/Langues/Pages/Créneaux/Bannières cachés.
**Persona** : Nadia, kebab-burger à Lyon, 1 caisse, 1 borne, 2 cuisiniers, aucun service informatique. Ses questions : « Où je mets
mon nom, mon logo, mon SIRET ? Qu'est-ce qui s'imprime sur le ticket ? Comment je dis que je suis fermée le lundi ? »
**Sous-systèmes imposés** : (1) **Source unique d'identité** — modèle cible : Entreprise = identité légale + marque ; Filiale = point
de vente ; en mono-établissement, un seul écran « Mon établissement » regroupe les deux sans dupliquer les données (décision : la
**filiale** porte SIRET/TVA/mentions ; l'Entreprise porte nom commercial/logo/contact ; l'écran unique lit/écrit les deux) ;
preuve « ce qui s'imprime sur le ticket / s'affiche sur la borne » ancrée dans les vues d'impression (grep `legal_footer`,
`company_name`, `siret`). (2) **Horaires & calendrier** — nouveau : horaires hebdomadaires + fermetures exceptionnelles, éditables ;
effet borne (écran « fermé »), site/app (hors périmètre code, mais projection API), OSS ; migration ⇒ gate G-DATA ; ne PAS toucher
la date métier NF525 (cron Z). (3) **Marque & apparence** — logos, favicon, **couleurs** (variables CSS runtime pour admin/borne/OSS,
défaut = palette Cayenne `#F4501E/#FFB800/#1A1A1A`, sans recompilation), dé-cachage Thème via ONB-05. (4) **Localisation & sécurité
des écritures `.env`** — devise, formats, fuseau ; FR verrouillé (ADR-007) ; décider si Entreprise/Site continuent d'écrire le `.env`
(risque : injection, redémarrage, perte en worktree) ou migrent vers la table `settings` — proposer, gate.
**Possède** : `settings/{Company,Site,Branch,Theme,Currency,Language,Page,TimeSlot,Slider}/**`, contrôleurs/requêtes homonymes,
`SiteService`, `ThemeSetting`, modèle `Branch` (champs identité), nouveaux fichiers horaires. **Interdit** : catalogue, rôles, bornes/
imprimantes, fichiers de visibilité du menu (→ 05). **Port** 8801. **Recon** : `recon/Z2_*.md` (+ Z8 pour l'UX). **Lectures** :
`plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (SET-T01, T05, T06, N05-N07, N11-N12), `CompanyRequest.php`, `SiteRequest.php`,
`BranchRequest.php`, `ThemeRequest.php`, `app/Console/Kernel.php:495-549` (Z auto). **Gates** : G0, G-DATA (horaires), G-ENV (écriture `.env`).

## ONB-02 — CATALOGUE_DE_ZERO
**Problème prouvé** : cinq entrées pour un concept (Studio, Articles, Produits & Stock, Réglages/Catégories, Attributs), Catégories/
Taxes/Attributs **cachés**, `ProductCreateWizardComponent.vue` = **squelette**, import Excel jamais éprouvé aux bords, `kds_station`
par défaut `none` = invisible en cuisine, concepts Variation/Extra/Addon/Attribut/Ingrédient non expliqués.
**Persona** : Karim, tacos-burger à Marseille, 45 produits, veut recopier sa carte en une soirée.
**Sous-systèmes imposés** : (1) **Un seul lieu** — Studio devient le hub : catégories (tri, canaux, image), taxes, attributs accessibles
depuis Studio ; un article = une fiche complète (les 7 onglets) ; retirer l'ambiguïté des libellés (« Articles » vs « Catalogue » vs
« Produits & Stock ») — proposition, dé-cachage via 05. (2) **Fiche produit sûre** — création guidée : terminer OU retirer le squelette
(décision : terminer, en réutilisant les 9 endpoints existants) ; champs obligatoires (catégorie, prix, taxe, **station KDS ≠ none**
pour tout article vendable en cuisine), images (taille/type, repli par slug `config/menu_images.php` = Cayenne → générique), canaux,
allergènes, code-barres, duplication, suppression protégée (`catalog_v15.item_deletion.protect_force_delete_when_referenced`).
(3) **Import/export fiable** — prévisualisation avant import, erreurs par ligne, idempotence (ré-import = mise à jour, pas doublon),
catégories/taxes auto-créées ou refusées (décision : refusées avec message), export = réimportable. (4) **Bords et effets** —
double clic, deux onglets (version ?), rechargement, prix 0/négatif, 255+ caractères, emoji, article dans 2 canaux ; effet borne/POS/
KDS mesuré via les API menu.
**Possède** : `admin/items/**` SAUF `composer/**`, `settings/{ItemCategory,ItemAttribute,Tax}/**`, `Item*Controller`, `ItemCategoryController`,
`ItemAttributeController`, `TaxController`, `Item*Request`, `ItemCategory*Request`, `TaxRequest`, `app/Imports/**`, `app/Exports/Item*`,
`config/menu_images.php`. **Interdit** : `composer/**` et `app/Services/Composer/**` (→ 03), stock (→ 08), visibilité menu (→ 05).
**Port** 8802. **Recon** : `recon/Z1_*.md`. **Lectures** : `Z0_modele_*.md §A-B`, `tests/Feature/Catalog/` (compter), `tests/Feature/Items/`,
`tests/Feature/Menu/`, `plans/GOAL_CAISSE_PARFAITE_2026-08-22.md` (S3 : garde `menu:reset-le-cayenne`), mémoire SSOT (`CLAUDE.md §3bis`).
**Gates** : G0, G-CACHE (via 05), G-DATA (si colonne ajoutée).

## ONB-03 — WIZARD_REGLES_DE_PRIX
**Problème prouvé** : les étapes du wizard portent `min_select`/`max_select`/`position`/`visible_on` mais **aucune sémantique de prix**
(`price` interdit dans `ComposerProfileRequest`/`ComposerStepRequest`) ; « N viandes incluses », « sauce incluse », « frites/boisson au ratio
0,76 » vivent dans `config/menu.php` et `config/kiosk.php` ; l'édition par article est derrière `FEATURE_WIZARD_PER_ITEM_DEMO=false` ;
`source_type='fixed'` est une valeur d'enum morte ; les templates sont ceux de Le Cayenne.
**Persona** : Karim veut « Sauce : 1 incluse, la 2e à 0,50 € ; Viande : 1 obligatoire ; Pain : choix unique gratuit ; Boisson : formule
+2 € ; Suppléments : payants ; jusqu'à 10 catégories de personnalisation par produit ».
**Décisions de conception (fermes)** : règle par ÉTAPE, pas par choix : `pricing_mode enum('free','included','paid')` +
`included_count` (uint, ≥ 0, « les N premiers choix sont offerts ») + `unit_price_override` (decimal nullable ; sinon prix de la
variation/extra/addon) ; « choix unique » = `max_select=1` ; « obligatoire » = `min_select≥1` (déjà) ; les prix restent calculés
**backend uniquement** (`PricingService::calculateOrder`) et figés dans `composition_snapshot` avec la règle appliquée ; les surfaces
(borne, caisse, web) n'affichent que ce que la projection expose (`ComposerProfileProjection`). Aucun env flag de bypass.
**Sous-systèmes imposés** : (1) **Modèle de règles** — migration `item_wizard_steps` (+3 colonnes, CHECKs), requêtes, modèle, versionnage
(`item_wizard_step_versions` snapshot inclut la règle), projection ; retirer/assumer `fixed`. (2) **Éditeur** — dans
`ProductComposerEditorComponent` : phrase lisible générée (« 1 sauce incluse, puis 0,50 € »), 10 étapes max triables, visibilité par
surface, aperçu prix en direct (calculé backend via devis), templates génériques (pas « tacos Cayenne ») ; décision sur le drapeau
par article : **le lever** (gate) ou le remplacer par une permission. (3) **Tarification & NF525** — `PricingService` (GELÉ) doit appliquer
`included_count` : **LOCK G-PRIX obligatoire**, tests de caractérisation AVANT (prix actuels inchangés pour tous les profils publiés),
parité borne/caisse/web (`tests/Feature/Pricing*`, `tests/Feature/Catalog`), idempotence, snapshot. (4) **Migration des inclusions en dur**
— inventaire de `config/menu.php` (`viandes`, `has_sauce`, `has_crudites`, `supplement_sauce_price`) et `config/kiosk.php`
(`frites_included_category_ids`, `menu_pricing`), plan de bascule vers les règles d'étape SANS changer un seul prix Le Cayenne
(preuve : devis avant/après identiques sur les 59 articles) — gate G-DATA.
**Possède** : `admin/items/composer/**`, `Composer*Controller`, `Composer*Request`, `app/Services/Composer/**`, modèles `ItemWizard*`,
nouvelles migrations `item_wizard_*`, `config/catalog_v15.php` (drapeaux wizard). **Sous LOCK seulement** : `app/Services/Pricing/PricingService.php`.
**Interdit** : fiche produit hors composer (→ 02), kiosk Vue gelé (lecture), `pos-wizard.js` (strict). **Port** 8803. **Dépend** : 02.
**Recon** : `recon/Z1_*.md` (scénario b). **Lectures** : `Z0_modele_*.md §A.2-A.3`, `ComposerProfileProjection.php`, `ComposerProfileService.php`,
`config/menu.php`, `config/kiosk.php:150-170,325-340`, `tests/Feature/Catalog/` (26), `tests/Feature/Multitenant/WizardProfileBranchScopeTest.php`,
`plans/GOAL_VIANDE_NOMMEE_BORNE_PAIEMENT_UNIQUE_2026-08-03.md`, `plans/GOAL_PARITE_SYNC_MULTISAUCE_2026-07-18.md`, `memory/04_pricing_ssot.jsonl`.
**Gates** : G-PRIX (LOCK PricingService), G-DATA (migration), G-FLAG (lever `wizard_per_item_demo`).

## ONB-04 — EXTRACTION_MENU_IA_ET_ASSISTANT
**Problème prouvé** : aucune extraction de menu, aucun assistant ; l'IA vision OpenAI existe pour factures (`Purchasing/Vision`) et tickets
Uber (`Uber/Vision`) avec un motif **contrat + implémentation OpenAI + mock fixture** réutilisable ; clé partagée, `OPENAI_VISION_ENABLED=false`.
**Persona** : Karim photographie sa carte plastifiée ; il veut voir apparaître catégories, produits, prix et options, corriger, puis « créer ».
Puis : « ajoute la sauce algérienne à tous les tacos », « passe les desserts en station froide ».
**Décisions de conception (fermes)** : **mock-first** (le GOAL converge sans clé) ; jamais d'écriture directe par l'IA — l'IA **propose**,
l'humain **valide**, le système **applique via les API admin existantes** (ItemCategory/Item/Variation/Extra/Addon/Composer) dans une
transaction idempotente traçable ; aucune action fiscale, aucun prix hors backend ; permissions `catalog.compose` (+ `catalog.publish` pour publier) ;
journal des actions IA ; budget/plafond par établissement ; fournisseur derrière un contrat (OpenAI d'abord, motif existant).
**Sous-systèmes imposés** : (1) **Pipeline d'extraction** — `app/Services/MenuExtraction/{MenuExtractionContract, OpenAiMenuExtractionService,
MockMenuExtractionService, MenuDraft (schéma JSON versionné : catégories → produits → prix → options → règles gratuit/inclus/payant)}`,
entrée photo/PDF/texte, limites (taille, pages), erreurs lisibles. (2) **Écran de validation** — `admin/assistant/MenuImportReviewComponent.vue` :
diff avec l'existant (doublons par nom normalisé), corrections inline, mapping vers attributs/extras/étapes ONB-03, « appliquer » par lot avec
rapport ligne à ligne, annulation = rien d'écrit. (3) **Application idempotente** — service `MenuDraftApplier` : préfixe de lot, reprise après
coupure, aucune suppression, effet borne/POS vérifié. (4) **Assistant de missions locales** — chat → intention → **plan d'actions** affiché
(quoi/combien/où) → confirmation explicite → exécution via API → journal ; catalogue de missions autorisées (catégorie, article, option, étape,
station, disponibilité, prix via devis) ; **refus** de tout ce qui touche caisse/fiscal/utilisateurs.
**Possède** : nouveaux `app/Services/MenuExtraction/**`, `app/Http/Controllers/Admin/Assistant/**`, `app/Http/Requests/Assistant/**`,
`resources/js/components/admin/assistant/**`, `config/assistant.php`, migrations `menu_drafts`/`assistant_actions` ; registre : routes/menu (déclarer).
**Interdit** : modifier les API de 02/03 (les consommer) ; `PricingService`. **Port** 8804. **Dépend** : 02, 03 (schéma de règles).
**Recon** : `recon/Z1_*.md`, `Z0_carte_dashboard.md §9`. **Lectures** : `app/Services/Purchasing/Vision/*`, `app/Providers/PurchasingServiceProvider.php`,
`config/services.php:71-90`, `PurchasingScanController.php`, `admin/purchasing/PurchaseScanComponent.vue`, `app/Services/Uber/Vision/*`,
`plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md` (B5 « pilotable par IA »). **Gates** : G-IA (fournisseur/clé/plafond), G-DATA.

## ONB-05 — REGLAGES_SANS_DEVELOPPEUR
**Problème prouvé** : 6 interrupteurs booléens seulement (`InterrupteurService::CATALOGUE`, booléen « par conception » `:56-65`) ; 22/31 sous-pages
Réglages cachées ; « 45 réglages exigent un développeur » (15/08) ; barème livraison sans écran ; tolérance d'écart de caisse, seuil stock,
mention légale, heures de service, temps de préparation dispersés entre `config/*.php`, `.env` et `settings`.
**Persona** : Nadia veut changer la tolérance d'écart de caisse à 5 €, un seuil de stock bas, et décider si la remise manuelle est autorisée —
sans appeler personne.
**Décisions de conception (fermes)** : **réglages typés déclaratifs** — étendre le catalogue à `type: bool|int|decimal|string|time_range|select`
avec validation, libellé FR, aide, groupe, valeur par défaut fichier, effet immédiat (`Config::set` + événement `SettingsUpdated`), **journal**
(qui/quand/avant/après — coordonné avec ONB-13) ; `idempotency.enabled` et tout NF525 restent **hors catalogue** ; une page « Réglages métier »
reliée au menu (Configuration) remplace l'écran orphelin ; ONB-05 est le **propriétaire unique** de `v1-hidden-modules.js`,
`settings/MenuComponent.vue`, `BackendMenuComponent.vue` (visibilité) : il exécute les dé-cachages demandés par 01/02/06/09/10 après G-CACHE.
**Sous-systèmes imposés** : (1) **Mécanisme typé** — service, contrôleur, requête, page, tests de caractère (valeur hors borne refusée, effet
mesuré sur la caisse/borne). (2) **Les 22 pages cachées** — tableau garder/cacher/retirer avec motif (SaaS-era : Licence, Passerelles, OTP,
Cookies, Analytique, Réseaux sociaux, Bannières, Pages ; nécessaires : Taxes, Catégories, Attributs, Rôles, Thème, Créneaux, Langues ?) →
G-CACHE ; exécution des dé-cachages ; cohérence (Attributs caché ET réinjecté). (3) **Réglages métier prioritaires** — tolérance caisse
(`cash.reconcile` 2 €), barème livraison (écran sur les colonnes `branches`), seuil stock bas, mention légale ticket, heures de service, temps de
préparation, `pos.manual_discount_enabled`, `pos.coupon_codes_enabled`, `kiosk.promo_enabled`, `kiosk.queue_start_number` — pour chacun :
où il vit, type, effet, test. (4) **Propagation & caches** — Spatie/permissions, settings cache, `.env` (SiteService) : effet immédiat prouvé.
**Possède** : `app/Services/Pilotage/**`, `Pilotage/InterrupteurController`, nouvelle page `admin/settings/Business/**` (ou nom retenu),
`settings/{OrderSetup,KioskSetup,Mail,Otp,Notification,NotificationAlert,SocialMedia,Cookies,analytics,SmsGateway,PaymentGateway,License}/**`,
`v1-hidden-modules.js`, `settings/MenuComponent.vue`, `BackendMenuComponent.vue` (visibilité uniquement), `config/{pos,kiosk,dashboard,features}.php`
(clés de réglage). **Interdit** : identité (→ 01), rôles (→ 06), `config/idempotency.php`, fiscal. **Port** 8805. **Recon** : `recon/Z2_*.md`, `Z7`.
**Lectures** : `Z0_modele_*.md §C-D`, `InterrupteurService.php` intégral, `PROJECT_BRAIN.md §4` (« 45 réglages »), mémoire `pilotage_sans_developpeur_2026-08-09`,
`plans/GOAL_CONFORT_MAX_ET_BASE_PROUVEE_2026-08-15.md` (V5). **Gates** : G-CACHE, G-DATA (table de réglages/journal), G0.

## ONB-06 — EQUIPE_ROLES_ACCES
**Problème prouvé** : page Rôles & Autorisations **cachée** ; repli **permissif** côté client (`router/index.js:106-110`, `DashboardComponent.vue:143-145`) ;
libellés techniques (« Stuff », `pos-discount-over-10-requires-manager`) ; enforcement API direct jamais prouvé pour tous les endpoints ;
`User` non isolé par filiale (documenté, V2) ; politique de mot de passe annoncée min:12 non prouvée.
**Persona** : Nadia embauche Sami (caissier) et Léa (cuisine) ; elle veut que Sami ne puisse pas annuler une vente payée ni voir les rapports.
**Sous-systèmes imposés** : (1) **Créer son équipe** — un parcours « Équipe » cohérent (employés/chefs/admins : mêmes formulaires, mêmes règles),
mot de passe fort imposé, PIN borne/carnet, activation/désactivation avec effet immédiat sur les jetons. (2) **Rôles métier lisibles** — dé-cacher
Rôles (via 05), libellés FR des 80+ permissions avec aide, rôles préconfigurés (Gérant, Caissier, Cuisine, Livreur) en seeder « socle »,
matrice `docs/AUTHZ_MATRIX.md` **générée par un test** (jamais à la main). (3) **Enforcement réel** — sentinelle qui parcourt `routes/api.php`
admin et appelle chaque endpoint mutateur avec chaque rôle : 403 attendu sauf autorisé ; repli permissif → **fail-closed** côté client ;
cliquet `RETURN_TRUE_BASELINE` resserré. (4) **Sessions & appareils** — révocation par appareil, plafond 10, auto-suppression/auto-rétrogradation
interdites, dernier admin protégé, désactivation ⇒ 401 immédiat.
**Possède** : `admin/{administrators,employees,chefs,waiters,customers,deliveryBoys,profile}/**`, `settings/Role/**`, contrôleurs/requêtes
utilisateurs, `RoleController`, `PermissionController`, `DeviceSessionController`, `Auth/ProfileController`, `permission-match.js`,
`router/index.js` (fonction d'accès uniquement), `database/seeders/{PermissionTableSeeder,RolePermissionTableSeeder,RoleTableSeeder}.php`,
`docs/AUTHZ_MATRIX.md`, `tests/Feature/Security/**`, `tests/Feature/Sentinels/RouteCoverage_*`, `FormRequestAuthzDriftSentinelTest`.
**Interdit** : `BranchScope.php` (gelé), `DeviceTokenService` révocation par appareil (ne pas « réparer », CLAUDE.md §9), visibilité menu (→ 05).
**Port** 8806. **Recon** : `recon/Z3_*.md`. **Lectures** : `plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` (S1), `plans/GOAL_ADMIN_NAV_BREADTH_*` (Wave 3),
`CLAUDE.md §9`, `tests/Feature/Auth/MultiDeviceLoginTest.php`. **Gates** : G-CACHE (Rôles), G-DATA (seeder socle), G0.

## ONB-07 — TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS
**Problème prouvé** : parité écran/export jamais re-vérifiée (REP-03/04 de juin), 2 widgets orphelins (`CustomerStats`, `TopCustomers`), rapport X
sans page, tuiles « depuis toujours » corrigées le 15/08 mais non re-prouvées, fenêtre SLA 24 h récente, 12 widgets sans aide.
**Persona** : Nadia ferme à 22 h et veut savoir en 30 s ce qu'elle a vendu, par canal, et si le tiroir est juste ; elle exporte pour son comptable.
**Sous-systèmes imposés** : (1) **Chiffres vrais** — pour chaque widget et rapport : définition écrite (période, `business_date` vs `created_at`,
fuseau Europe/Paris, statuts inclus/exclus, TTC/HT) + test « widget = rapport = SUM(DB) ». (2) **Exports = écran** — Excel/PDF/CSV : mêmes
filtres, mêmes totaux, mêmes libellés FR ; tests de parité. (3) **Rapports manquants ou orphelins** — page Rapport X (lecture NF525), décision
sur les 2 widgets clients (monter ou supprimer), rapport crédit caché (retirer si non pertinent). (4) **Lisibilité** — libellés, unités,
devise, aide contextuelle, états vides honnêtes, temps de chargement mesurés (< 2 s par widget), périodes prédéfinies.
**Possède** : `admin/{dashboard,salesReport,itemsReport,transactions,orderHistory,creditBalanceReport}/**`, `DashboardController`, `DashboardService`,
`SalesReportController`, `ItemsReportController`, `TransactionController`, `OrderHistoryController`, `CreditBalanceReportController`,
`Fiscal/XReportController` (lecture) + page Vue X, `config/dashboard.php`, `app/Exports/{SalesReport,ItemsReport,Transaction}*`, `tests/Feature/Dashboard/**`,
`tests/Feature/Report*/**`. **Interdit** : `ZReportService` (gelé), caisse (→ CAISSE), historique côté POS. **Port** 8807. **Recon** : `recon/Z4_*.md`.
**Lectures** : `plans/GOAL_ADMIN_NAV_BREADTH_*` (Wave 5 Reports), `PROJECT_BRAIN.md §2` (SLA 344 → 0, tuiles `period=today`), `tests/Feature/Report`
et `tests/Feature/Reports` (deux dossiers : anomalie à trancher). **Gates** : G0, G-DATA (si vue SQL).

## ONB-08 — STOCK_INGREDIENTS_DISPONIBILITE
**Problème prouvé** : 4 écrans (Produits & Stock, Conso & Stock, Ajustement, Ingrédients) + Scan facture pour un concept ; `RawMaterialAdjustController`
et `PurchasingScanController` **sans FormRequest** ; 11 articles vendables en `kds_station=none` ; widget stock-bas ; `Ingredient` = façade virtuelle
sur 3 tables ; addons en lecture seule.
**Persona** : Karim tombe en rupture de pain à 20 h ; il veut que la borne cesse de vendre les burgers en 10 s, et savoir le lendemain quoi racheter.
**Sous-systèmes imposés** : (1) **Un concept, un parcours** — carte mentale à trois questions (« est-ce vendable ? » = disponibilité/86 ; « combien il
m'en reste ? » = stock matières ; « qu'est-ce que je rachète ? » = achats) reflétée dans le menu et les titres ; proposition de fusion d'écrans
(dé-cachage/renommage via 05). (2) **Mouvements validés** — FormRequests pour ajustement et scan (bornes, motifs, idempotence, journal), effet
`StockLevel` vérifié, CHECKs DB. (3) **Rupture de bout en bout** — article/extra/ingrédient indisponible → borne, caisse, web, KDS en < N s
(mesuré), remise en disponibilité, `max_daily_qty`, reset quotidien. (4) **Seuils, alertes, stations** — seuils réglables (via 05), alerte dashboard,
`kds_station=none` rendu visible et corrigeable en lot, cohérence boissons (`bar`).
**Possède** : `admin/{stock,ingredients,purchasing}/**`, `items/AvailabilityToggleComponent.vue`, `ingredients/IngredientAvailabilityToggleComponent.vue`,
`StockRuptureDashboardController`, `UnifiedStockViewController`, `RawMaterialAdjustController`, `IngredientController`, `PurchasingScanController`,
`AvailabilityController`, requêtes associées, `app/Services/{Stock,Ingredients,Purchasing,RawMaterials}/**`, `tests/Feature/{Stock,Availability,Ingredients}/**`.
**Interdit** : `PosStockOutflow*` (voie CAISSE), fiche produit (→ 02), IA vision (contrat seulement, → 04 pour l'extraction menu). **Port** 8808.
**Recon** : `recon/Z5_*.md`. **Lectures** : `PROJECT_BRAIN.md §2` (11 articles `none`), `plans/GOAL_CUISSON_ET_STOCK_VIANDE_2026-08-06.md`,
`plans/GOAL_RUPTURE_CARNET_AUDIT_2026-07-15.md`, `SYNC_CONTRACT.md` (propagation de disponibilité). **Gates** : G0, G-DATA.

## ONB-09 — ANIMATION_COMMERCIALE
**Problème prouvé** : Coupons/Offres **cachés** ; `pos.coupon_codes_enabled=false`, `kiosk.promo_enabled=false`, `features.offers_enabled=false` ;
coupon accepté au devis puis refusé au commit (15/08, différé : SSOT prix) ; push → file `notifications` orpheline (1 490 jobs) ; roue liée au
site public Le Cayenne ; ticket promo nominatif = flux caisse.
**Persona** : Nadia veut « -10 % sur les menus le mardi », un code `BIENVENUE` pour la borne, des points fidélité, et prévenir ses abonnés.
**Sous-systèmes imposés** : (1) **Promotions & coupons** — dé-cacher (via 05), activer par réglage typé (via 05), parcours création → application
devis → commit sur borne/caisse/web ; le défaut « devis oui / commit non » est adjacent au pricing : **caractériser** puis proposer sous LOCK
(gate) — pas de correctif sauvage. (2) **Fidélité** — règles (points/€, plafonds, expiration), effets caisse/borne, lisibilité. (3) **Communication**
— push : décider le sort de la file orpheline (worker ou purge), aucune notification envoyée à des commandes vieilles ; messages, abonnés,
consentement. (4) **Ticket promo & roue** — transférabilité à un autre établissement (réglages, textes, dépendance au site public), désactivation propre.
**Possède** : `admin/{pushNotification,messages,subscribers,coupons,offers,promo}/**`, `settings/LoyaltySetup/**`, contrôleurs Push/Message/Subscriber/
Coupon/Offer/OfferItem/PromoFlyer/LoyaltySetup/`Wheel/**`, requêtes homonymes, `config/wheel.php`, `config/loyalty*.php`, `app/Services/Loyalty/**`,
`app/Services/Wheel/**`, `tests/Feature/{Coupon,Offer,Loyalty,Wheel,Notification}*/**`. **Interdit** : `PricingService`/`DiscountCalculator` (LOCK),
`PosLoyaltyController` (CAISSE), visibilité menu (→ 05), worker/queue config (→ 10). **Port** 8809. **Dépend** : 05. **Recon** : `recon/Z6_*.md`.
**Lectures** : `PROJECT_BRAIN.md §2` (file notifications), `plans/GOAL_ROUE_UX_IDENTITE_2026-08-13.md`, `plans/PLAN_GOAL-WHEEL-EXPERIENCE-*`, mémoire
`ticket_promo_plateformes_2026-08-07`, `roue_*`, `fidelite_*`. **Gates** : G-CACHE, G-PRIX (coupon au commit), G-NOTIF (purge/worker), G0.

## ONB-10 — EQUIPEMENT_ET_OPERATIONS
**Problème prouvé** : écrans orphelins (État du système, Outbox, Interrupteurs sans page), garde `SafeRemoteHost` vs pont local `127.0.0.1:9100`
(allowlist `.env` vide, oracle de scan de port si host-seul), file `notifications` orpheline, postes de cuisine réglables article par article
seulement, TPE simulé non expliqué à l'écran, parcours d'amorçage borne exigeant `.env`/commandes.
**Persona** : Nadia reçoit sa borne et son imprimante ; elle veut les brancher depuis le Dashboard, savoir si « ça marche », et qui appeler sinon.
**Sous-systèmes imposés** : (1) **Bornes** — parcours Dashboard → clé → pont → login → accueil sans développeur ; docs `KIOSK_DEPLOYMENT.md` reliées ;
révocation propre. (2) **Imprimantes & TPE** — allowlist **host + port** (option b du 13/08) sous réglage typé (via 05) ; destinations cuisine/comptoir
explicites ; test d'impression lisible ; TPE : écran qui dit « simulé » et pourquoi (CONSTITUTION §2). (3) **Cuisine & écrans** — stations
(bar/chaud/froid) éditables en lot, carillon, disposition, délai d'alerte, personnalisation OSS (logo/message) — KDS/OSS sont une AUTRE voie :
lecture + demandes coordonnées. (4) **Santé & pilotage reliés** — entrées de menu pour État du système / Outbox / Interrupteurs (via 05), sondes
honnêtes, décision sur la file orpheline (avec 09), runbook worker relié, alerte « worker absent » visible du commerçant.
**Possède** : `settings/{KioskMachine,Printers,PaymentTerminals}/**`, `admin/observability/**`, `observabilityRoutes.js`, `KioskMachineController`,
`PrinterController`, `PaymentTerminalController`, `HealthController`, `HealthzController`, `app/Rules/SafeRemoteHost.php`, `app/Services/Printing/**`
(transport), `config/printing.php`, `config/queue.php`, `docs/KIOSK_DEPLOYMENT.md`, `docs/RUNBOOK_WORKER_CAISSE.md`, `tests/Feature/{Printer,Kiosk,Health}*`.
**Interdit** : `KitchenDisplaySystemComponent.vue`/OSS (voie KDS : lecture + demande), `KioskAppComponent.vue` (gelé), `pos-*` (CAISSE), visibilité menu (→ 05).
**Port** 8810. **Recon** : `recon/Z7_*.md`. **Lectures** : `plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` (S1.3 imprimantes, S2 santé),
`plans/GOAL_CONSOLIDATION_V1_PRODUCTION_2026-08-25.md` (Sub 3.3 worker, S5), `SYNC_CONTRACT.md`, `docs/PLAYWRIGHT_MCP_OPS.md §7`. **Gates** : G-LAN (allowlist), G-NOTIF, G0.

## ONB-11 — EXPERIENCE_COMMERCANT_TRANSVERSE
**Problème prouvé** : motifs hétérogènes (drawer / page / modale / Blade externe), anglais résiduel, « Filiales » pour un mono-restaurant, « Stuff »,
aucune aide contextuelle, aucune checklist, repli permissif, 16 entrées + 9 sous-pages visibles sans hiérarchie de fréquence d'usage.
**Persona** : Nadia, première heure devant le Dashboard, seule, un mardi matin.
**Mode** : **audit lecture seule en parallèle** de tout le programme ; **corrections** : uniquement dans les fichiers possédés ; tout le reste est
**renvoyé** (fiche de renvoi par constat : GOAL propriétaire, file:line, correctif proposé) — c'est le GOAL « conscience UX » des autres.
**Sous-systèmes imposés** : (1) **Charte des motifs** — inventaire réel (quel écran utilise quoi), charte courte (quand drawer, quand page, quand modale),
composants partagés à corriger (`admin/components/**` = §6 partagé → sérialisé). (2) **Vocabulaire commerçant** — audit `fr.json` (anglais, accents,
`label.x` bruts, jargon), glossaire, aide contextuelle (composant « ? » réutilisable), renommages proposés (« Filiales » → « Mon établissement »,
« Stuff » → « Équipe »). (3) **Accessibilité & tablette** — axe-core sur les 25 pages visibles, clavier, focus, contraste, cibles tactiles, 1024×768 et
768×1024, temps de chargement. (4) **Psychologie** — premier écran (que voit-on, que craint-on), ordre et regroupement du menu par fréquence
d'usage, confirmations/annulation/retour arrière (peur de casser), confiance dans les chiffres (définitions visibles), checklist « Premier démarrage »
(spécifiée ici, construite par 12).
**Possède** : `layouts/backend/**` (hors visibilité menu → 05), `admin/components/**` (sérialisé), `resources/css/app.css`, `fr.json` (bloc `label`/`menu`
hors clés d'autres GOAL), `docs/UX_CHARTE_BACKOFFICE.md` (À CRÉER), `tests/js/a11y*`, `tests/e2e/admin-a11y-*`. **Interdit** : tout composant de page d'un autre
GOAL (renvoi). **Port** 8811. **Recon** : `recon/Z8_*.md` + tous les Z. **Lectures** : `resources/js/languages/fr.json`, mémoire « 92 % du FR = anglais littéral »
(`backoffice_export_blob_permission_inerte_2026-08-12`), `plans/GOAL_UX_MOBILE_CAISSE_WEB_2026-08-06.md`, `plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md`,
CLAUDE.md §3bis (palette). **Gates** : G-VOCAB (renommages), G0.

## ONB-12 — PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE
**Problème prouvé** : `DatabaseSeeder` installe **le menu, les rôles-landing et les bornes de Le Cayenne** (`MenuSeeder`, `LeCayenneRoleLandingUrlSeeder`,
`KioskMachineTableSeeder`) ; **147 fichiers** (`app/ config/ resources/js/ database/ routes/`) + **11** (`resources/views`, `lang`) citent « cayenne » ;
12 commandes artisan `Menu*/`*Cayenne*` ; installeur Blade `/install` hors Dashboard ; aucun parcours guidé ; images de repli par slug Cayenne.
**Persona** : l'intégrateur qui installe le logiciel chez Nadia un dimanche, puis Nadia qui l'ouvre le lundi.
**Décisions de conception (fermes)** : séparer **socle** (permissions, rôles génériques, réglages par défaut, devise, langue FR, une filiale « Mon
établissement », un admin) et **jeu de données Le Cayenne** (menu, wizards, bornes, textes) en seeders distincts ; commande `foodking:installer
--etablissement="…"` idempotente ; la marque devient **donnée** (réglages ONB-01/05) ; les commandes `Menu*Cayenne*` sont **archivées** (déplacées sous
`Commands/LeCayenne/`, gate) et non appelées par l'installation ; aucune suppression de données Le Cayenne existantes.
**Sous-systèmes imposés** : (1) **Installation vierge reproductible** — base vide → `migrate` → seeders socle → premier admin → Dashboard vide et propre
(zéro « Cayenne » visible) ; preuve sur une base dédiée `foodking_onb12` (gate G-DATA). (2) **Checklist « Premier démarrage »** — composant Dashboard
(7 étapes : identité → catalogue → personnalisation → équipe → équipement → commande test → publication ; état persisté, dismiss, reprise) reliée aux
écrans de 01/02/03/06/10, spécifiée par 11. (3) **Dé-cayennisation** — classement des 158 fichiers (marque-dans-code / donnée / test / doc), migration
vers réglages, renvoi des libellés aux GOAL propriétaires, sentinelle « zéro cayenne dans `app/ config/ resources/js/` hors dossier archive ».
(4) **Preuve** — le parcours ONB-14 tourne sur l'installation vierge.
**Possède** : `database/seeders/**` (sauf permissions/rôles → 06), `app/Console/Commands/{Menu*,*Cayenne*,FreshOrderSeed}.php`, `Installer/**`,
nouveaux `admin/onboarding/**`, `app/Http/Controllers/Admin/OnboardingController.php`, `config/menu.php`, `config/menu_images.php`,
`tests/Feature/Onboarding/**`, `tests/Feature/Sentinels/NoBrandInCodeSentinelTest.php` (À CRÉER). **Interdit** : fichiers gelés, composants de page d'autres GOAL.
**Port** 8812. **Dépend** : 01, 02, 05, G0. **Recon** : `Z0_*`, `recon/Z2_*.md`, `Z8`. **Lectures** : `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md`
(B5 intégral), `database/seeders/DatabaseSeeder.php`, `MenuSeeder.php`, `MenuResetLeCayenneCommand.php`, `docs/DEPLOYMENT_GUIDE_V1.md`,
`docs/GO_LIVE_RUNBOOK_LECAYENNE.md`. **Gates** : G0, G-DATA, G-ARCHIVE (commandes).

## ONB-13 — SECURITE_INTEGRITE_BACKOFFICE
**Problème prouvé** : 8 contrôleurs admin sans FormRequest (`RawMaterialAdjust`, `PurchasingScan`, `UberPhotoCapture`, `PromoFlyer`, `StockRuptureDashboard`,
`Wheel/*`, `NotificationAlert`), secrets potentiellement renvoyés par des index de réglages (passerelles), écritures `.env` (Entreprise/Site),
uploads (images, Excel, photos), IDOR à re-prouver par ressource, idempotence limitée à certaines routes (`config/idempotency.php`), aucun journal
« qui a changé quel réglage ».
**Persona** : le comptable de Nadia, et l'inspecteur qui demande « qui a changé la TVA le 3 mars ».
**Mode** : **audit lecture seule en parallèle** ; corrections sérialisées **par voie** (chaque FormRequest créée est déclarée au GOAL propriétaire du contrôleur).
**Sous-systèmes imposés** : (1) **Validation partout** — FormRequest pour chaque mutateur admin + sentinelle « tout POST/PUT/PATCH/DELETE admin a une
FormRequest » (cliquet). (2) **Secrets & exposition** — index de réglages sans secrets, masquage, logs, `.env` non lisible, headers. (3) **Intégrité des
mutations** — idempotence sur les mutations admin sensibles (ajout seul dans `config/idempotency.php`), IDOR par ressource (tests), limites de débit,
gardes d'upload (type/taille/contenu), CSRF/CORS. (4) **Journal des changements de réglages** — table `settings_audit` (ou réutilisation d'un journal
existant non gelé : `action_logs`), qui/quand/avant/après, lisible par le commerçant (page via 05) — jamais `AuditLogService` (gelé).
**Possède** : `app/Http/Requests/**` (nouvelles requêtes, déclarées), `app/Http/Middleware/**` hors gelés, `config/idempotency.php` (ajout seul),
`app/Services/Audit/SettingsAudit*` (À CRÉER), `tests/Feature/Security/**`, `tests/Feature/Sentinels/*FormRequest*`, `docs/SECURITY_BACKOFFICE.md` (À CRÉER).
**Interdit** : `IdempotencyKeyMiddleware.php`, `BranchScope.php`, `AuditLogService.php`, `PricingService.php` (gelés) ; pages d'autres GOAL (renvoi).
**Port** 8813. **Recon** : tous les `recon/Z*.md` (§ constats sécurité). **Lectures** : `plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md`,
`plans/GOAL_ADMIN_NAV_BREADTH_*` (SET-T02, T05, T12, N04), `CLAUDE.md §9`, `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php`,
`config/idempotency.php`, `app/Rules/SafeRemoteHost.php`. **Gates** : G-DATA (journal), G-IDEMP (routes ajoutées), G0.

## ONB-14 — CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT
**Problème prouvé** : rien ne prouve le parcours complet d'un établissement **autre que Le Cayenne** : identité → catalogue → wizard à règles →
équipe → équipement → commande borne → cuisine → encaissement → Z → rapports.
**Persona** : Nadia, jour 1 d'ouverture, avec son intégrateur au téléphone.
**Mode** : **exécuté en dernier** ; **aucun code produit** : chaque échec est renvoyé au GOAL propriétaire avec une fiche ; base **dédiée**
`foodking_onb14` créée par l'installation vierge de 12 (gate G-DATA) ; port 8814 ; jamais la base partagée.
**Sous-systèmes imposés** : (1) **Scénario « journée » scripté** — Playwright (navigateur réel, captures lues) + jumeau PHP (`tests/Feature/Onboarding/
JourneeNouveauCommercantTest.php`) ; données « Chez Nadia » 100 % différentes de Le Cayenne (noms, prix, règles) ; commande borne réelle → KDS → encaissement
comptoir → Z → rapports = DB. (2) **Boucle de convergence** — deux cycles consécutifs aux constats identiques ; chaque constat → fiche de renvoi
→ correction par le GOAL propriétaire → rejeu. (3) **Registre des renvois** — table constat / GOAL / statut / preuve de clôture. (4) **Clôture du
programme** — rapport final, `PROJECT_BRAIN.md §2/§3/§4/§6/§7`, `CONSTITUTION.md` (si G0), `SYSTEM_MAP.md` (sous-voies), étiquette (G-PUSH).
**Possède** : `tests/e2e/onboarding-journee-*.spec.js`, `tests/Feature/Onboarding/JourneeNouveauCommercantTest.php`, `reports/audit/onboarding-commercant-2026-08-26/CONVERGENCE_*.md`,
`reports/test-e2e/ONB14_*/**`. **Interdit** : tout fichier produit. **Dépend** : 01-13. **Recon** : tous. **Lectures** : `tests/e2e/boucle-quotidienne.spec.js`,
`tests/Feature/BoucleQuotidienneTest.php`, `docs/PLAYWRIGHT_MCP_OPS.md`, `tests/Playwright/global-setup.js` (garde d'identité), `tests/e2e/helpers/*`.
**Gates** : G-DATA (base dédiée), G-PUSH, G0.
