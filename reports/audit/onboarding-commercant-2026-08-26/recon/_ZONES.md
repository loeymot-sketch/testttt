# BRIEFS DE ZONE Z1..Z8 — reconnaissance web réelle (2026-08-26)

> Chaque auditeur lit `_BRIEF_COMMUN.md` PUIS sa zone ci-dessous. Tout chemin de code est relatif à l'arbre principal
> `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` (lecture par chemin absolu, jamais `cd`).
> Cartes déjà vérifiées à lire d'abord : `recon/Z0_carte_dashboard.md`, `recon/Z0_modele_catalogue_wizard_reglages.md`.

## RÉSILIENCE (une coupure de session a déjà tué un premier passage — ces règles sont obligatoires)
1. Dès la fin de la phase de largeur (≤ 15 min), ÉCRIS le livrable `recon/Z<n>_<slug>.md` avec les sections 1-2 et les constats
   déjà obtenus ; puis RÉÉCRIS-le à chaque étape majeure. Un rapport partiel sur disque vaut plus qu'un rapport parfait en mémoire.
2. Tiens `/Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z<n>/state.json` : ids créés, valeurs d'origine AVANT toute écriture.
3. Nettoie **au plus tôt** : dès qu'un scénario est fini, supprime ses entités (pas « à la fin »).
4. Des scripts d'un premier passage existent peut-être déjà dans ton dossier tmp (`*.js`, `lib.js`, `*_baseline.json`) : réutilise-les.
5. Budget **20-25 min** de travail effectif. Sois terse dans tes raisonnements ; la valeur est dans le fichier.
6. Le premier passage a été nettoyé par le chef de projet ; s'il reste une entité `AUDIT-ONB-*` que tu n'as pas créée, signale-la, ne la touche pas.

---

## Z1 — CATALOGUE & PERSONNALISATION PRODUIT (livrable `Z1_catalogue_wizard.md`, captures `screens/Z1/`, tmp `recon/Z1/`)
PAGES : `/admin/items/studio`, `/admin/catalog-hub`, `/admin/items` (+ `?create=1`), fiche `/admin/items/show/:id` (7 onglets), composer catégorie
`/admin/categories/:id/composer`, composer article `/admin/items/:id/composer` (drapeau `FEATURE_WIZARD_PER_ITEM_DEMO=false` : constater l'effet),
`/admin/demo/wizard-launcher`, pages cachées par URL : `/admin/settings/item-categories/list`, `/admin/settings/item-attributes/list`, `/admin/settings/taxes/list` ;
import/export Excel (`GET /api/admin/item/download-sample`, `POST /api/admin/item/import/file`), duplication, photos, bascule 86, tri, canaux, station KDS, allergènes.
CODE : `resources/js/components/admin/items/**`, `settings/{ItemCategory,ItemAttribute,Tax}/**`, `app/Http/Controllers/Admin/{Item*,ItemCategory,ItemAttribute,Tax,Composer*}Controller.php`,
`app/Services/Composer/**`, `app/Http/Requests/{Item*,Composer*,Tax,ItemCategory}Request.php`, `config/menu.php`, `config/kiosk.php`, `config/catalog_v15.php`.
Fait établi : les étapes du wizard n'ont AUCUNE sémantique de prix (`price` interdit) ; inclusions en dur dans `config/menu.php` + `config/kiosk.php`.
SCÉNARIOS (jusqu'au bout) : (a) catégorie `AUDIT-ONB-Z1 Cat` + article `AUDIT-ONB-Z1 Burger` avec 2 variations, 3 extras payants, 1 addon boisson, station KDS,
canaux kiosk+pos → persistance après rechargement → présence dans le menu borne (API frontend menu) et POS (`/api/admin/pos/...`) → effet station ;
(b) composer par catégorie : profil, étapes (attribut / groupe d'extras / addon), min/max, template, aperçu, publier, diff, dé-publier ; tenter `max<min`,
étape obligatoire sans choix, publish sans étape ; constater l'absence de « gratuit / inclus / payant » ; (c) import Excel : sample tel quel puis fichier cassé
(colonne manquante, prix négatif, doublon, catégorie inexistante) ; nettoyer ; (d) suppression article (soft ?) et catégorie non vide ; (e) « nouveau commerçant » :
clics/écrans de zéro à un produit vendable sauce+viande+boisson ; où se perd-il ; concepts expliqués ? ; (f) « Cayenne » en dur à l'écran (templates, images de repli
`config/menu_images.php`) ; (g) bords : doublon de nom, prix 0/négatif, 255+ car., image > 2 Mo / .svg, double clic, annulation d'un drawer, deux onglets, rechargement.
HORS : stock/ruptures (Z5), réglages hors catalogue (Z2), utilisateurs (Z3).

## Z2 — PROFIL ÉTABLISSEMENT & RÉGLAGES (livrable `Z2_profil_reglages.md`, `screens/Z2/`, tmp `recon/Z2/`)
PAGES : `/admin/settings/company` (LECTURE), `/admin/settings/site` (LECTURE), `/admin/settings/branches/list` + `show/:id` (CRUD sur `AUDIT-ONB-Z2 Resto` puis suppression),
`/admin/settings/order-setup`, `/admin/settings/kiosk-setup`, `/admin/settings/loyalty-setup` (lecture), `/admin/settings/currencies/list`, cachées par URL : `theme`, `languages/list`
(+ éditeur, lecture), `pages/list`, `time-slots`, `sliders/list`, `mail`, `otp`, `notification`, `notification-alert`, `social-media`, `cookies`, `analytics/list`, `sms-gateway`,
`payment-gateway` (⚠️ secrets renvoyés à `pos@` ? lecture), `license` ; `GET /api/admin/observability/interrupteurs` (lecture) ; `/admin/observability/system`.
CODE : `settings/{Company,Site,Branch,OrderSetup,KioskSetup,Currency,Theme,Language,Page,TimeSlot,Slider,Mail,Otp,Notification,NotificationAlert,SocialMedia,Cookies,analytics,SmsGateway,PaymentGateway,License}/**`,
`settings/MenuComponent.vue`, `resources/js/config/v1-hidden-modules.js`, contrôleurs/requêtes homonymes, `app/Services/Pilotage/InterrupteurService.php`, `config/{menu,kiosk,pos,dashboard,features}.php`.
Faits établis : SIRET/TVA sur la FILIALE ; aucun éditeur d'horaires ; barème livraison = colonnes `branches` sans écran ; Thème = 3 logos ; 22/31 pages cachées.
SCÉNARIOS : (a) « nouveau restaurant » : nom, logo, favicon, SIRET, TVA, adresse, téléphone, e-mail, horaires, jours fermés, devise, langue, fuseau, couleurs, mention ticket
→ écran trouvé (URL + capture) ou ABSENT ; ce qui s'imprime réellement sur le ticket / la borne (grep `legal_footer`, `company_name`, `siret` dans les vues d'impression) ;
Entreprise vs Filiale : laquelle gagne ; (b) filiale test : SIRET 13 chiffres, TVA mal formée, `delivery_fee_per_km` négatif, `legal_footer` 2000 car. → validations ; valides →
persistance → suppression (filiale avec données ? filiale 1 ?) ; ⛔ ne touche pas aux 6 filiales existantes ; (c) chaque page cachée : s'ouvre ? fonctionnelle ? pertinente
pour un commerçant local FR (garder / cacher / retirer) ; (d) effet réel d'un réglage : temps de préparation (order-setup) → propagé (API frontend) → RESTAURER ; titre d'accueil
borne (kiosk-setup) → restaurer ; cache ? ; (e) Entreprise/Site : soumettre UNIQUEMENT une valeur invalide attendue refusée (nom avec saut de ligne) — acceptée = P0 ; lire
`CompanyRequest.php`/`SiteRequest.php` ; (f) créneaux : chevauchement, vide, fin < début ; (g) interrupteurs : lecture seule ; (h) « Cayenne » en dur.
HORS : catalogue/taxes/catégories/attributs (Z1), utilisateurs (Z3), bornes/imprimantes/TPE (Z7), fidélité/promos (Z6).

## Z3 — UTILISATEURS, RÔLES, CONTRÔLE D'ACCÈS (livrable `Z3_utilisateurs_rbac.md`, `screens/Z3/`, tmp `recon/Z3/`)
PAGES : `/admin/administrators`, `/admin/employees`, `/admin/chefs`, cachées : `/admin/waiters`, `/admin/customers`, `/admin/delivery-boys` ; `/admin/settings/roles/list` + `show/:id`
(LECTURE des rôles existants ; créer `AUDIT-ONB-Z3 Manager`) ; `/admin/profile/edit-profile`, `change-password` (compte test seulement), `/admin/profile/devices` ; PIN borne (lecture) ;
`/carnet`, `/m` (PIN, lecture).
CODE : `admin/{administrators,employees,chefs,waiters,customers,deliveryBoys,profile}/**`, `settings/Role/**`, `resources/js/shared/permission-match.js`, `router/index.js:106-133`
(repli PERMISSIF), `BackendMenuComponent.vue` (`MENU_URL_TO_PERMISSION_URL`), contrôleurs Administrator/Employee/Chef/Waiter/Customer/DeliveryBoy/Role/Permission,
`Auth/{DeviceSessionController,ProfileController}.php`, requêtes homonymes, `app/Services/Concerns/EnforcesOwnBranchScope.php`, `app/Services/Auth/DeviceTokenService.php`,
`database/seeders/{PermissionTableSeeder,RolePermissionTableSeeder}.php`, `docs/AUTHZ_MATRIX.md`, `tests/Feature/Sentinels/{RouteCoverage_AdminPermissionGateSentinelTest,FormRequestAuthzDriftSentinelTest}.php`.
⛔ Ne jamais modifier les permissions d'un rôle existant, ni un vrai compte, ni son mot de passe.
SCÉNARIOS : (a) employé `AUDIT-ONB-Z3 Caissier` (POS Operator, mot de passe fort) → connexion navigateur ET API → MENU visible vs pages par URL vs API ; appels DIRECTS avec son jeton :
`GET /api/admin/setting/company`, `GET /api/admin/setting/role`, `POST /api/admin/administrator` (payload test ; si 201 → SUPPRIME immédiatement, P0), `POST /api/admin/item` (attendu 403),
la route Z-reports réelle (lire `routes/api.php`), `PUT /api/admin/observability/interrupteurs/<nom>` (si ça passe → RESTAURE), `GET /api/admin/setting/payment-gateway` (secrets ?) ;
2xx là où le menu cache = P0 ; (b) repli permissif : entrée de menu/route dont la `permissionUrl` n'a AUCUNE ligne dans `permissions` → visible par `chef@` ? ; (c) mot de passe :
`123456`, `password`, 8 car. → refusés ? ancien mot de passe exigé ? autres appareils révoqués ? ; (d) rôle test avec `items` + `sales-report` → assigner → reconnexion → menu + API ;
retirer une permission → effet immédiat ou après reconnexion (cache Spatie) ? supprimer le rôle : ses utilisateurs ? ; (e) garde-fous : supprimer le seul Admin / soi-même (UNIQUEMENT sur
`AUDIT-ONB-Z3 Admin` créé par toi), se rétrograder, e-mail existant, désactiver un employé connecté → session tombe ? ; (f) filiale de l'employé créé (`EnforcesOwnBranchScope`) ;
(g) appareils : liste, révocation, plafond 10 ; (h) « nouveau commerçant » : rôles métier sans développeur ? libellés (« Stuff », « Filiales », permissions techniques) compréhensibles ?
HORS : réglages hors rôles/profil (Z2), catalogue (Z1), bornes/imprimantes (Z7).

## Z4 — TABLEAU DE BORD, RAPPORTS, HISTORIQUE, TRANSACTIONS (livrable `Z4_dashboard_rapports.md`, `screens/Z4/`, tmp `recon/Z4/`)
PAGES : `/admin/dashboard` (12 widgets, accès rapide, bouton PDF clôture EOD : POST autorisé, vérifier le contenu du PDF), `/admin/sales-report` (+ export Excel + PDF), `/admin/items-report`
(+ export), `/admin/transactions` (+ export), `/admin/historique`, `/admin/pos-orders` (lecture), `/admin/pos-orders-tracker` (lecture), `/admin/cash-overview` (lecture),
`/admin/cash-sessions-report` (lecture), `/admin/credit-balance-report` (caché), `/admin/encaissement` (LECTURE STRICTE : ne rien confirmer), `/admin/settings/z-reports` (lecture,
PDF d'un Z existant OK), rapport X (`GET`, lecture : trouver la route dans `routes/api.php:1700-1712`).
CODE : `admin/{dashboard,salesReport,itemsReport,transactions,orderHistory,creditBalanceReport}/**`, `DashboardController.php`, `app/Services/DashboardService.php`, `SalesReportController`,
`ItemsReportController`, `TransactionController`, `OrderHistoryController`, `Fiscal/{ZReport,XReport}Controller`, `config/dashboard.php`, `app/Exports/**`, `tests/Feature/Dashboard/`, `tests/Feature/Report*/`.
SCÉNARIOS : (a) cohérence chiffrée : total ventes du jour (widget) vs sales-report vs `SUM` en base sur `business_date` (statuts inclus ?) ; export Excel vs écran (bugs REP-03/04 de juin :
re-vérifier) ; (b) filtres période (aujourd'hui / hier / 7 j / mois / plage) : valeurs, fuseau Europe/Paris, `business_date` vs `created_at` ; (c) états vides et gros volumes (pagination,
temps de réponse mesurés) ; (d) permissions : `chef@` voit-il des chiffres ? `pos@` ? ; (e) « nouveau commerçant » : comprend-il chaque widget ? libellés, unités, devise, définitions ;
(f) alertes SLA (fenêtre 24 h) ; (g) les 2 composants orphelins `CustomerStatsComponent`/`TopCustomersComponent` : API vivante ? ; (h) PDF EOD : contenu, mentions légales, « Cayenne » en dur.
HORS : caisse/encaissement en écriture (jamais), Z en écriture (jamais).

## Z5 — STOCK, INGRÉDIENTS, ACHATS, DISPONIBILITÉ (livrable `Z5_stock_ingredients.md`, `screens/Z5/`, tmp `recon/Z5/`)
PAGES : `/admin/catalog-hub?tab=stock`, `/admin/stock/rupture`, `/admin/stock/unified`, `/admin/stock/raw-material-adjust` (⚠️ pas de FormRequest : validation avec valeurs invalides SANS
enregistrer ; un ajustement réel uniquement sur une matière test créée par toi, puis compensé exactement), `/admin/ingredients` (+ `/attribute`, `/extra`, `/addon`, usage),
`/admin/purchasing/scan` (vision MOCK car OpenAI désactivé : scanner une image test, voir les cibles, NE PAS appliquer sauf sur matière test), bascules 86 sur un article test
`AUDIT-ONB-Z5`, `max_daily_qty`, seuils stock bas (widget dashboard), station KDS `none` (re-mesurer : `SELECT name FROM items WHERE deleted_at IS NULL AND status=5 AND kds_station='none'`).
CODE : `admin/{stock,ingredients,purchasing}/**`, `items/AvailabilityToggleComponent.vue`, `StockRuptureDashboardController`, `UnifiedStockViewController`, `RawMaterialAdjustController`,
`IngredientController`, `PurchasingScanController`, `AvailabilityController`, `app/Services/{Stock,Ingredients,Purchasing,RawMaterials}/**`, `tests/Feature/{Stock,Availability,Ingredients}/`.
SCÉNARIOS : (a) rupture d'un article test → menu borne/POS (API) le montrent indisponible ? délai mesuré ; remise en dispo ; (b) seuil bas → alerte dashboard ; (c) extra en rupture →
tous les articles qui l'utilisent ; (d) scan facture mock → lignes → cibles → validation (matière test) ; (e) « nouveau commerçant » : différence Produits & Stock / Conso & Stock /
Ajustement / Ingrédients — 4 écrans pour un concept ; (f) articles en `kds_station=none` : liste exacte ; (g) bords : quantité négative, texte, énorme, double soumission, deux onglets.
HORS : fiche produit (Z1), sorties de stock POS (modale caisse : lecture seule), IA (contrat seulement).

## Z6 — COMMUNICATION, PROMOS, FIDÉLITÉ, ROUE (livrable `Z6_animation_commerciale.md`, `screens/Z6/`, tmp `recon/Z6/`)
PAGES : `/admin/push-notifications` (créer `AUDIT-ONB-Z6` SANS envoyer si un bouton d'envoi existe — vérifier ce que « créer » déclenche : file `notifications` orpheline ~1 490 jobs :
`SELECT queue, COUNT(*) FROM jobs GROUP BY queue` avant/après), `/admin/messages` (lecture), `/admin/subscribers` (abonné test créé/supprimé), cachés : `/admin/coupons`, `/admin/offers`
(coupon test → vérifier via l'API de devis frontend/POS qu'il s'applique, SANS créer de commande ; supprimer), `/admin/promo-flyer` + `/settings` (lecture), `/admin/settings/loyalty-setup`
(lecture + validation sans enregistrer), roue : `/admin/roue` (Blade, lecture), `/admin/roue-reglages` (lecture), `/admin/roue-historique`.
CODE : `admin/{pushNotification,messages,subscribers,coupons,offers,promo}/**`, `settings/LoyaltySetup/**`, contrôleurs Push/Message/Subscriber/Coupon/Offer/PromoFlyer/LoyaltySetup/`Wheel/**`,
`config/wheel.php`, `config/pos.php` (`coupon_codes_enabled`, `loyalty_enabled`), `config/kiosk.php` (`promo_enabled`), `config/features.php` (`offers_enabled`).
SCÉNARIOS : (a) « nouveau commerçant » : promo -10 % sur une catégorie, code promo, offre du jour, programme fidélité, sans développeur ? (coupons/offres cachés ; drapeaux à false) ;
(b) push : que se passe-t-il réellement à la création (job en file ? worker ?) ; (c) fidélité : règles (points/€), plafonds, effet POS/borne (lecture des services) ; (d) roue :
dépendance au site public Le Cayenne, réglages transférables ? ; (e) bords : coupon expiré, montant négatif, code dupliqué, deux onglets.
HORS : caisse (jamais de commande), `PricingService`.

## Z7 — OPÉRATIONS & ÉQUIPEMENT (livrable `Z7_equipement_ops.md`, `screens/Z7/`, tmp `recon/Z7/`)
PAGES : `/admin/settings/kiosk-machines/list` (borne test `AUDIT-ONB-Z7 Borne` → clé/`machine_id` → `/kiosk/login` avec elle → supprimer : que devient son jeton ?), `/admin/settings/printers`
(imprimante test à `127.0.0.1:9100` : garde `SafeRemoteHost` ? aussi `192.168.1.50:9100`, `10.0.0.1:22`, un nom DNS ; `test-print` ; supprimer), `/admin/settings/payment-terminals`
(TPE test : frais, modifier, supprimer), `/admin/settings/kiosk-setup` (PIN admin, vidéo : lecture), `/admin/kitchen-display-system` (LECTURE : stations, disposition V2, son, filtres),
`/admin/order-status-screen` (lecture : personnalisation ?), orphelins : `/admin/observability/system`, `/admin/observability/outbox` ; `GET /api/healthz`, `GET /api/health/full`,
`GET /api/admin/observability/interrupteurs` ; ponts `/dl/borne`, `/dl/caisse-bridge`, `/dl/kitchen-bridge` (`routes/web.php:124-137`).
CODE : `settings/{KioskMachine,Printers,PaymentTerminals,KioskSetup}/**`, `admin/observability/**`, `observabilityRoutes.js`, contrôleurs KioskMachine/Printer/PaymentTerminal/KioskSetup,
`Pilotage/InterrupteurController.php`, `InterrupteurService.php`, `{HealthController,HealthzController}.php`, `Auth/KioskMachineLoginController.php`, `app/Rules/SafeRemoteHost.php`,
`app/Services/Printing/**` (`TcpPrinterTransport`), `config/{printing,queue,kiosk,pos}.php`, `KitchenDisplaySystemComponent.vue` (lecture), `docs/RUNBOOK_WORKER_CAISSE.md`, `docs/KIOSK_DEPLOYMENT.md`.
Faits établis : file `notifications` ~1 490 jobs jamais tentés ; sondes de santé rendues honnêtes le 25/08 ; `SafeRemoteHost` interdit `127.0.0.0/8` par défaut ; Interrupteurs sans lien de menu.
SCÉNARIOS : (a) « installer sa borne » : Dashboard → borne → clé → pont (`/dl/borne`) → `/kiosk/login` → accueil ; chaque endroit où il faut un développeur ; (b) imprimantes : garde LAN
(4 adresses) + test-print + destinations cuisine/comptoir ; (c) TPE simulé (CONSTITUTION §2) : l'écran l'explique ? ; (d) KDS/OSS : postes de cuisine réglables autrement qu'article par
article ? carillon, disposition, alerte ; `chef@` : que voit-il ? ; (e) santé : `/api/healthz`, `/api/health/full` (sans auth ?), `queue_pending` vs `jobs` par file, `failed_jobs` ;
page État du système lisible ? interrupteur `impression_ticket_client_auto` : PUT puis RESTAURE ; `pos@` → 403 ? ; (f) Outbox ; (g) « Cayenne » en dur (`lecayenne-worker`, `kiosk-lecayenne`).
HORS : réglages généraux (Z2), catalogue (Z1), rôles (Z3).

## Z8 — EXPÉRIENCE COMMERÇANT TRANSVERSE (livrable `Z8_experience_commercant.md`, `screens/Z8/`, tmp `recon/Z8/`)
Pas une zone de pages : une traversée. SCÉNARIO PRINCIPAL : chronométrer un nouveau commerçant qui ouvre le Dashboard pour la première fois (que voit-il : widgets Le Cayenne,
chiffres d'un autre restaurant ?), dans quel ordre ferait-il ses réglages, existe-t-il une checklist ? Parcourir les 16 entrées du menu + les 9 sous-pages Réglages visibles en
1366×768, 1024×768 et 768×1024 : cohérence des motifs (drawer / page / modale / Blade), libellés FR (anglais résiduel, accents, `label.x` bruts, « Filiales » pour un mono-restaurant,
« Stuff »), messages d'erreur, états vides, boutons sans retour, double clic, focus clavier, contraste, cibles tactiles, temps de chargement (réseau) ; barre latérale : ordre /
regroupement / charge cognitive ; recherche globale ? aide contextuelle ? icônes. `@axe-core/playwright` est installé dans l'arbre principal : l'utiliser sur 6 pages.
CODE (lecture) : `layouts/backend/**`, `admin/dashboard/**`, `resources/js/languages/fr.json`, `resources/css/app.css`, `admin/components/**`.
LIVRABLE ADDITIONNEL : proposition de checklist « Premier démarrage » (7 étapes ancrées sur les écrans existants) + top 10 des frictions classées par impact commerçant.
HORS : aucune écriture en base (lecture seule totale).
