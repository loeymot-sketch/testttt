# RECONNAISSANCE — Voie CENTRAL (back-office de gestion)

**GOAL** : `goal-max-confort-2026-08-15` · **HEAD** : `e2d2ca3b4` · **Branche** : `pos/category-first-caisse-2026-06-23`
**Nature** : lecture seule. Aucun fichier modifié.
**Angle** : « le patron doit pouvoir TOUT piloter confortablement, sans développeur ».

## Prior art lu avant de commencer (pour ne PAS re-signaler du résolu)

| Document | Ce qu'il prouve | Conséquence sur ce rapport |
|---|---|---|
| `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` | 72/73 pages admin **atteignables** + 2 bugs corrigés | Je ne re-teste pas l'atteignabilité. « Atteignable par URL » ≠ « pilotable par le patron » — c'est l'angle neuf. |
| `plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` | RBAC navigable prouvé ; `BranchScope` no-op sur `User` documenté | Non re-signalé. |
| `reports/goal-ops-swap-2026-08-12/w1/CONSTATS_W1.md` + `CORRECTIONS.md` | **C1 EXPORT-BLOB-MUET corrigé** (`resources/js/shared/blob-error.js`), **C2 permission-url corrigé**, **`config/report.php` créé**, **`lang/fr/validation.php` créé** (vérifié : `lang/fr/validation.php:63` = « Le champ :attribute doit être une adresse e-mail valide. ») | Ces 4 points sont **RÉSOLUS**, non re-signalés. En revanche **les réglages orphelins §4 et les 23 entrées masquées §6.4 sont RE-VÉRIFIÉS OUVERTS sur `e2d2ca3b4`** (preuves ci-dessous). |

---

# BLOC 1 — INVENTAIRE des surfaces de gestion

Classement **BASE** (indispensable au quotidien) / **SECONDAIRE**.
`ctrl` = fichier:ligne réellement lu. `front` = composant Vue existant (vérifié par `ls`).

## 1.1 Pilotage quotidien — BASE

```
[BASE] Tableau de bord (KPI + graphes + accès rapides) — route:/admin/dashboard — ctrl:app/Http/Controllers/Admin/DashboardController.php:63 — front:resources/js/components/admin/dashboard/DashboardComponent.vue
[BASE] Catalogue produits (liste + créer + éditer prix) — route:/admin/items — ctrl:app/Http/Controllers/Admin/ItemController.php:48 — front:resources/js/components/admin/items/ItemListComponent.vue
[BASE] Catalog Studio (catégories + produits sur une page) — route:/admin/items/studio — ctrl:app/Http/Controllers/Admin/ItemController.php:48 — front:resources/js/components/admin/items/CatalogStudioComponent.vue
[BASE] Catalog Hub (onglets Catalogue+Stock) — route:/admin/catalog-hub — ctrl:app/Http/Controllers/Admin/ItemController.php:48 — front:resources/js/components/admin/items/CatalogHubComponent.vue
[BASE] Composer produit (tacos/sandwich à étapes) — route:/admin/items/:id/composer — ctrl:app/Http/Controllers/Admin/ComposerProfileController.php — front:resources/js/components/admin/items/composer/
[BASE] Produits & Stock / rupture (86 binaire) — route:/admin/stock/rupture — ctrl:app/Http/Controllers/Admin/StockRuptureDashboardController.php:50 + AvailabilityController.php:52 — front:resources/js/components/admin/stock/StockRuptureDashboardComponent.vue
[BASE] Vue stock unifiée (matières+boissons+« à acheter ») — route:/admin/stock/unified — ctrl:app/Http/Controllers/Admin/UnifiedStockViewController.php:31 — front:resources/js/components/admin/stock/UnifiedStockViewComponent.vue
[BASE] Ajustement inventaire (casse/vol/pesée) — route:/admin/stock/raw-material-adjust — ctrl:app/Http/Controllers/Admin/RawMaterialAdjustController.php:113 — front:resources/js/components/admin/stock/RawMaterialAdjustComponent.vue
[BASE] Scan facture → entrée stock — route:/admin/purchasing/scan — ctrl:app/Http/Controllers/Admin/PurchasingScanController.php:46 — front:resources/js/components/admin/purchasing/
[BASE] Ingrédients (dispo/86 par ingrédient) — route:/admin/ingredients — ctrl:app/Http/Controllers/Admin/IngredientController.php:21 — front:resources/js/components/admin/ingredients/IngredientListComponent.vue
[BASE] Historique commandes unifié — route:/admin/historique — ctrl:app/Http/Controllers/Admin/OrderHistoryController.php:45 — front:resources/js/components/admin/orderHistory/HistoriqueListComponent.vue
[BASE] Rapport des ventes (filtre date + export) — route:/admin/sales-report — ctrl:app/Http/Controllers/Admin/SalesReportController.php:46 — front:resources/js/components/admin/salesReport/SalesReportListComponent.vue
[BASE] Rapport produits — route:/admin/items-report — ctrl:app/Http/Controllers/Admin/ItemsReportController.php:34 — front:resources/js/components/admin/itemsReport/
[BASE] Transactions — route:/admin/transactions — ctrl:app/Http/Controllers/Admin/TransactionController.php:24 — front:resources/js/components/admin/transactions/TransactionListComponent.vue
[BASE] Rapports Z (clôture fiscale, lecture) — route:/admin/settings/z-reports — ctrl:app/Http/Controllers/Admin/Fiscal/ZReportController.php:22 — front:resources/js/components/admin/settings/Fiscal/ZReportListComponent.vue
[BASE] Rapport X (point de contrôle) — ctrl:app/Http/Controllers/Admin/Fiscal/XReportController.php:22 — front:resources/js/components/admin/settings/Fiscal/
[BASE] Utilisateurs — Administrateurs — route:/admin/administrators — ctrl:app/Http/Controllers/Admin/AdministratorController.php:38 — front:resources/js/components/admin/administrators/
[BASE] Utilisateurs — Employés — route:/admin/employees — ctrl:app/Http/Controllers/Admin/EmployeeController.php:35 — front:resources/js/components/admin/employees/
[BASE] Utilisateurs — Cuisiniers — route:/admin/chefs — ctrl:app/Http/Controllers/Admin/ChefController.php:40 — front:resources/js/components/admin/chefs/
[BASE] Réglages > Entreprise — route:/admin/settings/company — ctrl:app/Http/Controllers/Admin/CompanyController.php:22 — front:resources/js/components/admin/settings/Company/CompanyComponent.vue
[BASE] Réglages > Succursale (adresse/tel/statut) — route:/admin/settings/branches — ctrl:app/Http/Controllers/Admin/BranchController.php:26 — front:resources/js/components/admin/settings/Branch/BranchComponent.vue
[BASE] Réglages > Commande (temps prépa, créneau) — route:/admin/settings/order-setup — ctrl:app/Http/Controllers/Admin/OrderSetupController.php:22 — front:resources/js/components/admin/settings/OrderSetup/OrderSetupComponent.vue
[BASE] Réglages > Fidélité (barème points) — route:/admin/settings/loyalty-setup — ctrl:app/Http/Controllers/Admin/LoyaltySetupController.php:22 — front:resources/js/components/admin/settings/LoyaltySetup/
[BASE] Réglages > Imprimantes — route:/admin/settings/printers — ctrl:app/Http/Controllers/Admin/PrinterController.php:23 — front:resources/js/components/admin/settings/Printers/PrintersComponent.vue
[BASE] Réglages > TVA (taxes) — route:/admin/settings/taxes/list — ctrl:app/Http/Controllers/Admin/TaxController.php:25 — front:resources/js/components/admin/settings/Tax/TaxListComponent.vue  ⚠️ MASQUÉ DU MENU (voir C-01)
[BASE] Réglages > Catégories produits — route:/admin/settings/item-categories/list — ctrl:app/Http/Controllers/Admin/ItemCategoryController.php:40 — front:resources/js/components/admin/settings/ItemCategory/  ⚠️ MASQUÉ DU MENU
[BASE] Réglages > Rôles / Permissions — route:/admin/settings/role/list — ctrl:app/Http/Controllers/Admin/RoleController.php:25 + PermissionController.php:36 — front:resources/js/components/admin/settings/Role/  ⚠️ MASQUÉ DU MENU
```

## 1.2 SECONDAIRE

```
[SECONDAIRE] État du système + INTERRUPTEURS métier — route:/admin/observability/system — ctrl:app/Http/Controllers/Admin/Observability/SyncOverviewController.php:69 + Pilotage/InterrupteurController.php:30 — front:resources/js/components/admin/observability/SystemHealthComponent.vue
[SECONDAIRE] File outbox (synchro) — route:/admin/observability/outbox — ctrl:app/Http/Controllers/Admin/Observability/SyncOverviewController.php:314 — front:resources/js/components/admin/observability/OutboxOverviewComponent.vue
[SECONDAIRE] Coupons — route:/admin/coupons — ctrl:app/Http/Controllers/Admin/CouponController.php:32 — front:resources/js/components/admin/coupons/  ⚠️ MASQUÉ DU MENU + INERTE (voir C-02)
[SECONDAIRE] Offres — route:/admin/offers — ctrl:app/Http/Controllers/Admin/OfferController.php:46 — front:resources/js/components/admin/offers/  ⚠️ MASQUÉ DU MENU
[SECONDAIRE] Clients (fidélité) — route:/admin/customers — ctrl:app/Http/Controllers/Admin/CustomerController.php:42 — front:resources/js/components/admin/customers/  ⚠️ MASQUÉ DU MENU
[SECONDAIRE] Commandes en ligne — route:/admin/online-orders — ctrl:app/Http/Controllers/Admin/OnlineOrderController.php:46 — front:resources/js/components/admin/onlineOrders/  ⚠️ MASQUÉ DU MENU
[SECONDAIRE] Notifications push — route:/admin/push-notifications — ctrl:app/Http/Controllers/Admin/PushNotificationController.php:28 — front:resources/js/components/admin/pushNotification/
[SECONDAIRE] Messages — route:/admin/messages — ctrl:app/Http/Controllers/Admin/MessageController.php:28 — front:resources/js/components/admin/messages/MessageListComponent.vue
[SECONDAIRE] Abonnés — route:/admin/subscribers — ctrl:app/Http/Controllers/Admin/SubscriberController.php:29 — front:resources/js/components/admin/subscribers/SubscriberListComponent.vue
[SECONDAIRE] Tables / plan de salle — route:/admin/dining-tables — ctrl:app/Http/Controllers/Admin/DiningTableController.php:30 — front:resources/js/components/admin/diningTable/  ⚠️ MASQUÉ DU MENU
[SECONDAIRE] Rapport solde crédit — route:/admin/credit-balance-report — ctrl:app/Http/Controllers/Admin/CreditBalanceReportController.php:44 — front:resources/js/components/admin/creditBalanceReport/  ⚠️ MASQUÉ DU MENU
[SECONDAIRE] Livreurs / Serveurs / Clients (CRUD) — routes:/admin/delivery-boys, /admin/waiters, /admin/customers — ctrl:DeliveryBoyController.php:36, WaiterController.php:45, CustomerController.php:42  ⚠️ MASQUÉS DU MENU
[SECONDAIRE] Réglages > Bornes (machines) — route:/admin/settings/kiosk-machines/list — ctrl:app/Http/Controllers/Admin/KioskMachineController.php:30
[SECONDAIRE] Réglages > Borne (textes accueil, PIN) — route:/admin/settings/kiosk-setup — ctrl:app/Http/Controllers/Admin/KioskSetupController.php:23
[SECONDAIRE] Réglages > Terminaux de paiement — route:/admin/settings/payment-terminals — ctrl:app/Http/Controllers/Admin/PaymentTerminalController.php:44
[SECONDAIRE] Réglages > Site / Devises — routes:/admin/settings/site, /admin/settings/currencies/list — ctrl:SiteController.php:22, CurrencyController.php:24
[SECONDAIRE] Réglages MASQUÉS (nav) : mail(25) · otp(22) · notification(25) · notification-alert(21) · social-media(22) · cookies(21) · analytics(29) · theme(22) · time-slots(23) · sliders(30) · pages(29) · languages(30) · sms-gateway(29) · payment-gateway(29) · license(25) · item-attributes(25) — ctrl:app/Http/Controllers/Admin/<X>Controller.php:<ligne>
[SECONDAIRE] Profil / mot de passe / appareils — routes:/admin/profile/* — front:resources/js/components/admin/profile/
[SECONDAIRE] Démo lanceur assistant — route:/admin/demo/wizard-launcher — front:resources/js/components/admin/demo/WizardAdvancedLauncherComponent.vue
```

---

# BLOC 2 — FRICTIONS DE CONFORT

## §A — LA LISTE : réglages métier qui exigent AUJOURD'HUI un développeur

C'est la donnée n°1. Chaque ligne = un réglage que le patron **ne peut pas** changer depuis un écran.
Trois familles : **(E)** `.env` + accès SSH · **(F)** édition d'un fichier source + recompilation/déploiement · **(D)** colonne en base sans formulaire.

```
[P1] app/Services/Pilotage/InterrupteurService.php:43-56 — SEULS 2 interrupteurs sur ~40 sont pilotables
  friction: la liste blanche `CATALOGUE` ne contient que `split_payment.enabled` et `wheel.enabled`.
            Tout le reste de la table ci-dessous exige un développeur.
  evidence: `public const CATALOGUE = [ 'split_payment' => [...], 'wheel' => [...] ];` — 2 entrées, fin.
            Le docblock l.13-18 dit lui-même que les bascules « vivaient dans des fichiers de
            configuration. Les changer exigeait un déploiement ».
  fix-suggéré: étendre `CATALOGUE` (liste blanche, pas de filtre) aux clés marquées ✅ ci-dessous.
```

### Tableau exhaustif (chaque clé vérifiée par lecture du fichier de config)

| # | Réglage métier | Où il vit | Type | Défaut | Ce que ça coûte au patron | Éligible `CATALOGUE` |
|---|---|---|---|---|---|---|
| 1 | **Heures de service** des commandes programmées | `config/kds.php:99-100` `KDS_SCHEDULED_WINDOW_OPEN/CLOSE` | E | `18:00` / `00:30` | Ouvrir le midi = ticket développeur. Le commentaire dit « Le Cayenne sert 18h → minuit et demie » — c'est un horaire d'établissement en dur. | ✅ (champ heure) |
| 2 | **Remise manuelle en caisse** autorisée | `config/pos.php:196-200` `POS_MANUAL_DISCOUNT_ENABLED` | E | `false` | Un geste commercial au comptoir est impossible ; aucun écran ne l'ouvre. | ✅ |
| 3 | **Codes promo utilisables** | `config/pos.php:271-276` `POS_COUPON_CODES_ENABLED` | E | `false` | Voir C-02 : coupons créés en admin mais refusés à l'encaissement. | ✅ |
| 4 | **Redeem fidélité** (dépense de points) | `config/pos.php:233-238` `POS_LOYALTY_ENABLED` | E | `true` | Couper la fidélité un soir = déploiement. | ✅ |
| 5 | **Tolérance d'écart de caisse** (€) | `config/cash.php:31` `CASH_VARIANCE_THRESHOLD_EUR` | E | `2.00` | Le seuil au-delà duquel la caisse exige une justification. Réglage 100 % métier. | ✅ (numérique) |
| 6 | **Double validation manager à la clôture** | `config/cash.php:84-88` `CASH_MANAGER_GATE_ROUTINE_CLOSE` | E | `false` | Passer à 2 caissiers = déploiement. | ✅ |
| 7 | **Ancienneté « caisse oubliée »** (h) | `config/pos.php:319` `POS_CASH_SESSION_STALE_HOURS` | E | `24` | Le commentaire mesure « deux sessions ouvertes depuis 49 et 36 jours, sans que rien ne le signale ». | ✅ |
| 8 | **Barème frais de livraison** (base / €-km / km offerts / minimum) | colonnes `branches.delivery_fee_base/_per_km/_free_km/_minimum` — `app/Services/Delivery/DeliveryFeeService.php:34-36` | D | 3 € + 2 €/km, 3 km, min 4 € | **Aucun formulaire** : `app/Http/Requests/BranchRequest.php:31-46` ne contient aucun de ces champs. Le dernier changement a été fait **par une migration** (`database/migrations/2026_07_27_091000_update_delivery_fee_bareme_owner.php:22-25`) = un développeur a écrit du SQL pour changer un tarif. | ✅ (formulaire branche) |
| 9 | **Montant minimum de commande en livraison** | colonne `branches.delivery_minimum_order` (`database/migrations/2026_05_18_110000_add_delivery_minimum_order_to_branches.php:18`) | D | — | Idem : absent de `BranchRequest`. | ✅ |
| 10 | **SIRET / TVA intracommunautaire / n° de caisse / mention légale** imprimés sur le ticket | colonnes `branches.siret, vat_intra, register_id, legal_footer` (`app/Models/Branch.php:18`) — imprimés en `app/Services/Hardware/OrderReceiptEscPosRenderer.php:88-89,208-209` | D | — | Ce sont des **mentions fiscales sur le ticket client**. Absents de `BranchRequest`. Un changement de SIRET = intervention en base. | ✅ |
| 11 | **Seuil d'alerte stock bas par produit** | colonnes `stock_levels.threshold_low` / `raw_materials.threshold_low` | D | `0` | **Affiché sur 3 écrans, réglable nulle part** (voir C-03). | ✅ |
| 12 | **86 automatique quand le stock tombe à 0** | `config/catalog_v15.php:136-142` `FK_CATALOG_AUTO_86_CRON_ENABLED` | E | `false` | La mise en rupture reste 100 % manuelle. | ✅ |
| 13 | **Alerte stock bas (mail + toast)** activée | `config/catalog_v15.php:165-171` `FK_CATALOG_STOCK_LOW_ALERT_ENABLED` | E | `false` | Idem #11 : l'alerte est éteinte par défaut. | ✅ |
| 14 | **Ouverture du tiroir-caisse avec le ticket** | `config/printing.php:234-239` `PRINTING_DRAWER_OPEN_WITH_RECEIPT` | E | `true` | Le commentaire dit « Mettre à false coupe l'ouverture automatique sans déploiement » — vrai pour `.env`, faux pour le patron. | ✅ |
| 15 | **Impression automatique du reçu client** | `config/printing.php:71` `POS_AUTO_PRINT_CLIENT_RECEIPT` | E | `false` | Décision purement commerciale. | ✅ |
| 16 | **Largeur du ticket** (caisse / borne) + page de code € | `config/printing.php:93,98,103` | E | `0`/`0`/`0` | Changer d'imprimante = ticket illisible jusqu'à intervention. | ⚠️ (technique, mais bloquant terrain) |
| 17 | **Site web imprimé sur le ticket** | `config/printing.php:83` `RECEIPT_WEBSITE` | E | `lecayenne.fr` | — | ✅ |
| 18 | **Afficheur client** (activé, port COM, textes d'accueil) | `config/printing.php:180-187` | E | off / `COM3` / « LE CAYENNE » | Textes vus par le client, en dur. | ✅ |
| 19 | **Vitrine publique ouverte/fermée** (`staff_only_mode`) | `config/features.php:50` `STAFF_ONLY_MODE` | E | `true` | Ouvrir/fermer la commande en ligne = déploiement. Lu par `resources/views/master.blade.php:252`. | ✅ |
| 20 | **Module « Offres » activé** | `config/features.php:27` `FEATURE_OFFERS_ENABLED` | E | `false` | — | ✅ |
| 21 | **Paiement en ligne (Mollie) activé + clé** | `config/payment.php:114-116` `MOLLIE_ENABLED`/`MOLLIE_API_KEY` — verrou `app/Http/PaymentGateways/Gateways/Mollie.php:73-76` | E | `false` / `''` | L'écran « Passerelle de paiement » écrit `gateway_options` (Stripe/PayPal). **La passerelle réellement branchée est Mollie, qui ne lit que `.env`.** Couper le paiement en ligne depuis l'admin n'a aucun effet. (constat W1 §4 — **RE-VÉRIFIÉ OUVERT**) | ✅ (au moins le on/off) |
| 22 | **Toutes les commandes borne encaissées au comptoir** | `config/kiosk.php:54` `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER` | E | `true` | Décision d'exploitation (plan B TPE). | ✅ |
| 23 | **Walk-in caisse routé vers l'encaissement différé** | `config/pos.php:296-306` `POS_WALKIN_ROUTE_TO_COUNTER` | E | `false` | — | ⚠️ (gate owner assumé) |
| 24 | **Numéro de file de départ de la borne** | `config/kiosk.php:98` `KIOSK_QUEUE_START_NUMBER` | E | `32` | — | ✅ |
| 25 | **Retour auto de l'écran de confirmation borne** (s) | `config/kiosk.php:199` `KIOSK_CONFIRMATION_AUTO_RETURN_SECONDS` | E | `30` | Rythme de service. | ✅ |
| 26 | **Quantité max d'un article en borne** | `config/kiosk.php:192` `KIOSK_MAX_ITEM_QTY` | E | `20` | — | ✅ |
| 27 | **Catégories « frites incluses » de la borne** | `config/kiosk.php:125` `KIOSK_FRITES_INCLUDED_CATS` (`'309,310,311,314'`) | E | IDs en dur | Une nouvelle catégorie menu = ticket développeur, avec des **ID numériques** dans un `.env`. | ✅ (sélecteur de catégories) |
| 28 | **Catégories mises en avant en caisse** | `config/pos.php:113-124` `POS_FEATURED_CATEGORY_SLUGS` / `_IDS` | E | vide | Mettre une promo en avant sur l'écran caisse. | ✅ |
| 29 | **Fenêtre « commande fraîche » du mur client (OSS)** | `config/oss.php:29,40` `OSS_STALE_WINDOW_HOURS` / `_ADVANCE_` | E | `8` / `48` | Explique le « mur client vide » déjà vu en mémoire. | ✅ |
| 30 | **Avance d'affichage d'une commande programmée en cuisine** | `config/kds.php:78` `KDS_SCHEDULED_LEAD_MINUTES` | E | `20` | Mandat owner « avant 20 minutes » figé dans `.env`. | ✅ |
| 31 | **Délai de grâce d'une programmée no-show** | `config/kds.php:90` `KDS_SCHEDULED_GRACE_HOURS` | E | `2` | — | ✅ |
| 32 | **Fenêtre d'impression auto du ticket cuisine** | `config/kds.php:114` `KDS_BRIDGE_PRINT_WINDOW_MINUTES` | E | `30` | — | ⚠️ (garde-fou, cf. commentaire) |
| 33 | **Nombre max de tranches d'un paiement fractionné** | `config/split_payment.php:20` `SPLIT_PAYMENT_MAX_TRANCHES` | E | `12` | L'interrupteur on/off est pilotable, **pas le nombre**. | ✅ |
| 34 | **Durée de validité d'un devis caisse/borne** (s) | `config/quote.php:20` `QUOTE_TTL_SECONDS` | E | `300` | — | ⚠️ |
| 35 | **Code PIN du carnet de bord** | `config/daily_book.php:13` `DAILY_BOOK_PIN` | E | `''` | **Changer un code d'accès après un départ de salarié exige un développeur.** | ✅ (P1 sécurité d'usage) |
| 36 | **Durée de session du carnet de bord** (min) | `config/daily_book.php:16` | E | `240` | — | ✅ |
| 37 | **Code PIN du stock mobile** | `config/mobile_stock.php:22` `MOBILE_STOCK_PIN` | E | `''` | Idem #35. | ✅ |
| 38 | **Durée de session du stock mobile** (min) | `config/mobile_stock.php:25` | E | `720` | — | ✅ |
| 39 | **Code PIN du comptoir roue** + durée | `config/wheel.php:304-305` `WHEEL_PIN` / `WHEEL_SESSION_MINUTES` | E | `''` / `240` | Idem #35. | ✅ |
| 40 | **Plafond de lignes d'un export PDF** | `config/report.php:36` `REPORT_PDF_MAX_ROWS` | E | `2000` | Fichier créé le 2026-08-12 → clé désormais lisible, mais toujours `.env`. | ⚠️ |
| 41 | **Nombre max d'appareils connectés par compte** | `config/auth.php` `max_devices_per_user` (cf. `app/Services/Auth/DeviceTokenService.php:37` `DEFAULT_MAX_DEVICES = 10`) | E/F | `10` | — | ⚠️ |
| 42 | **Taux de TVA appliqué aux frais de livraison** | `config/menu.php:73` `'tax_rate' => 10.00` — lu par `app/Services/Fiscal/ZReportService.php:864` | **F (littéral, aucun `env`)** | `10.00` | Aucune variable, aucun écran : **édition de fichier source obligatoire**. | ⚠️ (NF525-adjacent, gate) |
| 43 | **Plafond d'attente annoncé au client** (`order_setup_wait_cap`) | `app/Services/WaitEstimateService.php:66` — lu dans le groupe `order_setup` | **D (lu, jamais écrit)** | `30` min | Le commentaire l.62-64 l'assume : « surchargeable owner via le repository settings existant » = via tinker/SQL. Aucun champ dans `OrderSetupRequest`. (constat W1 §4 miroir — **RE-VÉRIFIÉ OUVERT**) | ✅ |
| 44 | **Langues de la borne + langue par défaut** (`kiosk_languages_enabled`, `kiosk_default_language`) | `app/Http/Resources/SettingResource.php:110-111` — lus | **D (lus, jamais écrits)** | `fr` | Aucun `KioskSetupRequest` ne les valide. (W1 §4 miroir — **RE-VÉRIFIÉ OUVERT**) | ✅ |
| 45 | **Cadence des sondages** (POS/OSS/KDS, ms) | `config/catalog_v15.php:59-96` (8 clés `FK_CATALOG_*_MS`) | E | 250 ms → 60 s | Technique, mais c'est le levier direct contre les 429 déjà vécus en service. | ⚠️ |

### §A bis — Réglages **écrits par l'admin mais lus par personne** (le patron croit régler quelque chose)
Constat W1 §4 du 2026-08-12 — **re-vérifié ligne par ligne sur `e2d2ca3b4`, TOUJOURS OUVERT** :

```
[P1] app/Http/Resources/SettingResource.php:116 — le PIN admin de la borne est écrasé par `null`
  friction: l'écran Réglages>Borne propose un champ « code à 4 chiffres » ; le patron le saisit,
           il est enregistré, et AUCUN écran ne le demandera jamais.
  evidence: champ présent `resources/js/components/admin/settings/KioskSetup/KioskSetupComponent.vue:76-83`
           + validé `app/Http/Requests/KioskSetupRequest.php:22` (`regex:/^\d{4}$/`)
           MAIS `SettingResource.php:116` : `'kiosk_admin_pin' => null,` (littéral, commentaire
           l.113-115 « the kiosk never receives a PIN »).
  fix-suggéré: soit retirer le champ de l'écran, soit exposer la vraie valeur — pas de troisième voie.

[P2] app/Http/Requests/CompanyRequest.php:37-39 — 3 champs OBLIGATOIRES que personne ne lit
  friction: on refuse d'enregistrer la fiche entreprise tant que ville/région/code postal ne sont
           pas saisis, alors qu'aucun code ne les relit (0 occurrence hors Request/Resource/Vue).
  evidence: `'company_city' => ['required', ...]`, `'company_state' => ['required', ...]`,
           `'company_zip_code' => ['required', ...]` ; grep `company_city` → uniquement
           CompanyResource.php, CompanyRequest.php, CompanyComponent.vue. Idem `company_website`.
  fix-suggéré: composer l'adresse d'établissement à partir de ces champs (ticket/mentions), ou les passer `nullable`.

[P3] app/Http/Requests/SiteRequest.php — `site_email_verification` / `site_auto_update` sans lecteur
  friction: deux verrous affichés qui ne verrouillent rien.
  evidence: grep des deux clés → uniquement SiteResource.php + SiteRequest.php (+ SiteComponent.vue
           pour la première). Zéro lecteur métier.
  fix-suggéré: retirer les deux champs de l'écran.
```

## §B — Frictions de navigation et de parcours

```
[P1] resources/js/config/v1-hidden-modules.js:12-54 — 23 entrées de menu masquées, dont la TVA et les RÔLES
  friction: le patron ne voit PAS dans son menu : TVA (l.39), catégories produits (l.35),
           rôles (l.38) et permissions (l.37), créneaux horaires (l.49), langues (l.43),
           coupons (l.13), offres (l.14), clients (l.12), commandes en ligne (l.19),
           passerelle de paiement (l.53), pages/CMS, sliders, mail, OTP, thème, analytics…
           Les écrans EXISTENT et répondent (audit 2026-08-13, 72/73). Ils sont simplement
           invisibles. Pour changer un taux de TVA il faut connaître l'URL par cœur.
  evidence: `export const V1_HIDDEN_MENU_MODULES = Object.freeze([ 'customers', 'coupons', 'offers',
           ..., 'settings.tax', ... ])` ; appliqué par
           `resources/js/components/admin/settings/MenuComponent.vue:38-42` (table
           HIDDEN_KEY_TO_LOCAL_SETTING) + `resources/js/components/layouts/backend/BackendMenuComponent.vue:58`.
           Le docstring du fichier l.9 dit lui-même : « Pour réafficher : retirer la clé de cette liste »
           — c.-à-d. éditer un fichier source et recompiler. Signalé « à trancher avec l'owner »
           le 2026-08-12 (CONSTATS_W1 §6.4) et jamais tranché.
  fix-suggéré: réafficher au minimum tax, item-categories, role, permission, time-slots, coupons
           (les 6 réglages métier d'un restaurateur) ; ou piloter la liste par un réglage en base.

[P1] app/Services/FrontendOrderService.php:1069-1078 — un coupon créé en admin est REFUSÉ à l'encaissement
  friction: le patron crée un code promo dans /admin/coupons (écran complet, dates, plafond,
           surfaces). À l'usage, le client se voit répondre « Les remises (coupon) sont
           désactivées en V1. » Rien, sur l'écran de création, ne l'avertit.
  evidence: `if (config('pos.coupon_codes_enabled') === true) { return; }` puis
           `if (config('pos.manual_discount_enabled') !== true) { throw ... 'Les remises (coupon)
           sont désactivées en V1.' }` — les deux flags valent `false` par défaut
           (`config/pos.php:271-276` et `config/pos.php:196-200`). Même garde côté caisse :
           `app/Services/OrderService.php:3426,3449`. Grep `coupon_codes_enabled` dans
           `resources/js/components/admin/coupons/` → 0 occurrence : aucun bandeau d'avertissement.
  fix-suggéré: bandeau « les codes promo sont actuellement désactivés » sur l'écran Coupons, alimenté
           par `PromoFlyerController.php:83-84` qui expose déjà exactement ce booléen.

[P2] resources/js/components/admin/observability/SystemHealthComponent.vue:106-135 — les 2 seuls interrupteurs métier sont rangés dans un écran technique
  friction: pour couper le paiement en plusieurs fois un soir de panne TPE, il faut aller dans
           « État du système », entre le battement du planificateur et la fraîcheur des sauvegardes.
  evidence: bloc `data-testid="system-interrupteurs"` dans SystemHealthComponent ; routes
           `routes/api.php:1632-1633` sous le préfixe `observability`. Entrée de menu créée par
           `database/migrations/2026_08_09_180000_ajoute_menu_etat_du_systeme.php:33-36`
           (`'name' => 'System Health'`, sous le parent `setup`).
  fix-suggéré: dupliquer le bloc interrupteurs dans un onglet « Réglages > Exploitation ».
```

## §C — Données affichées sans contexte / retours trompeurs

```
[P1] app/Services/DashboardService.php:19-29 + :374-398 — les 4 tuiles du tableau de bord sont des cumuls DEPUIS TOUJOURS
  friction: la première chose que voit le patron en se connectant est « Total ventes 39 945,13 € »
           — le cumul de toute la vie du restaurant, sans période, sans comparaison, sans libellé
           de date. Il ne peut pas répondre à « combien j'ai fait aujourd'hui ? » depuis l'accueil.
  evidence: `private function orderQuery() { $query = Order::query(); ... return $query; }`
           — AUCUN filtre de date. `totalSales()` fait `->realizedRevenue()->sum('total')` dessus.
           Libellé : `resources/js/components/admin/dashboard/OverviewComponent.vue:12`
           `{{ $t('label.total_sales') }}` → `resources/js/languages/fr.json:1398` = « Total ventes ».
           Contraste : les graphiques SOUS les tuiles ont, eux, un sélecteur de période
           (`OrderStatisticsComponent.vue:5-8`) et une valeur par défaut « mois en cours »
           (`DashboardService.php:232-233`).
  fix-suggéré: ajouter un sélecteur de période aux 4 tuiles (défaut « aujourd'hui ») + un
           libellé de période sous le chiffre.

[P1] resources/js/components/admin/dashboard/StockLowAlertsWidget.vue:93-95 — une panne d'alertes stock s'affiche comme « aucune alerte »
  friction: si l'appel échoue (403, 500, réseau), le widget affiche « aucune alerte de stock bas ».
           Le patron lit « tout va bien » alors que le système n'a rien pu vérifier.
  evidence: `} catch (_e) { this.alerts = []; }` — l'erreur est avalée sans trace ; le template
           l.12-14 rend alors `$t('label.no_low_alerts')`.
  fix-suggéré: état d'erreur distinct de l'état vide (« impossible de vérifier le stock »).

[P1] app/Listeners/NotifyStockLowOnStockLevelChanged.php:20-23 — le seuil d'alerte stock n'a AUCUN écran (C-03)
  friction: `threshold_low` est AFFICHÉ trois fois (dashboard, vue unifiée, ajustement matières)
           mais réglable nulle part → il vaut 0 partout → l'alerte ne se déclenche jamais.
  evidence: commentaire du code lui-même : « all stock_levels rows have threshold_low=0 and this
           listener short-circuits at the threshold check (no log emitted). Kept active so
           V1.0.2 admin UI for `threshold_low > 0` lights up observability » — l'écran d'admin
           est explicitement reporté. Affichages :
           `resources/js/components/admin/dashboard/StockLowAlertsWidget.vue:33`,
           `resources/js/components/admin/stock/UnifiedStockViewComponent.vue:119-120,156`,
           `resources/js/components/admin/stock/RawMaterialAdjustComponent.vue:79-80`.
           Aucun `Request`/`v-model`/endpoint n'écrit `threshold_low` (grep exhaustif : 0 résultat).
  fix-suggéré: champ « seuil d'alerte » éditable dans l'écran Ajustement matières + rupture.
```

## §D — Messages techniques bruts et textes anglais

```
[P2] app/Libraries/QueryExceptionLibrary.php:26 — la trace technique remonte jusqu'au patron
  friction: pour toute exception qui n'est pas une QueryException, le message brut de l'exception
           PHP est renvoyé au navigateur et affiché en toast.
  evidence: `} else { return $e->getMessage(); }` — pas de traduction, pas de filtre.
           Consommé tel quel par ~30 contrôleurs admin sous la forme
           `return response(['status' => false, 'message' => $exception->getMessage()], 422);`
           (ex. `app/Http/Controllers/Admin/DashboardController.php:68`,
           `LoyaltySetupController.php:26,36`, `KioskSetupController.php:28,37`).
  fix-suggéré: message générique FR + identifiant de corrélation ; détail en log seulement.

[P2] 167 occurrences / 71 fichiers admin — `|| e.message` affiche l'erreur JavaScript brute
  friction: sur un écran fiscal, le patron peut lire « Network Error » ou « Cannot read properties
           of undefined ».
  evidence: `resources/js/components/admin/settings/Fiscal/ZReportListComponent.vue:113,143`
           `alertService.error(e.response?.data?.message || e.message)` ;
           `settings/Printers/PrintersComponent.vue:226,268,281` ; `items/ItemListComponent.vue:638`.
  fix-suggéré: un helper unique `messageUtilisateur(err)` (même patron que le correctif blob de W1).

[P2] resources/js/components/admin/dashboard/DashboardComponent.vue:245-257 — `window.alert()` natif avec le texte serveur
  friction: fenêtre système grise, bloquante, hors charte, contenant un message serveur brut.
  evidence: parse du blob puis `window.alert(msg)`.
  fix-suggéré: passer par `alertService`.

[P2] resources/js/languages/fr.json:1744-1770 — 6 messages de succès en franglais cassé
  friction: à chaque enregistrement réussi, le patron lit une phrase mal traduite.
  evidence: `"coupon_add": "Coupon Ajouter Successfully."` (l.1744) ·
           `"coupon_delete": "Coupon Supprimer Successfully."` (l.1745) ·
           `"delivery_boy_add": "Livreur Ajoutered Successfully!"` (l.1746) ·
           `"image_update": "Image Mettre à jourd Successfully."` (l.1756, utilisé par
           `offers/OfferShowComponent.vue:180` et `items/ItemShowComponent.vue:312`) ·
           `"photo_update": "Photo Mettre à jourd Successfully."` (l.1763, utilisé par
           `customers/CustomerShowComponent.vue:374`, `waiters/WaiterShowComponent.vue:357`,
           `deliveryBoys/DeliveryBoyShowComponent.vue:349`) ·
           `"zone_update_successfully": "Zone Mettre à jour Successfully."` (l.1770, utilisé par
           `settings/Branch/BranchShowComponent.vue:290`).
  fix-suggéré: réécrire les 6 valeurs. ADR-007 (locale FR) est immuable.

[P2] 8 clés de fil d'Ariane absentes de fr.json — le patron voit la clé brute ou de l'anglais
  friction: en haut de page, à la place du nom de l'écran.
  evidence: rendu par `resources/js/components/admin/components/BreadcrumbComponent.vue:6,12,15`
           (`$t('menu.'+…breadcrumb)`). Absentes de fr ET en (→ clé brute affichée) :
           `menu.composer` (`router/modules/itemRoutes.js:117,150`), `menu.create` (l.75),
           `menu.wizard_advanced_launcher` (l.132), `menu.observability_system`
           (`observabilityRoutes.js:33`), `menu.observability_outbox` (l.44),
           `menu.delivery_cash_view` (`deliveryBoyCashSessionRoutes.js:37`).
           Absentes de fr mais présentes en en (→ anglais affiché) : `menu.order_details`
           (6 modules de routes : administrator/chef/customer/deliveryBoy/employee/waiter, l.52-53),
           `menu.delivered_order_details` (`deliveryBoyRoutes.js:64`).
  fix-suggéré: ajouter les 8 clés dans `fr.json` groupe `menu`.

[P2] resources/js/components/admin/items/CatalogStudioComponent.vue:521,541,570,596 — « Something Wrong. » en anglais
  friction: l'erreur générique du Studio catalogue s'affiche en anglais.
  evidence: `$t("error.something_wrong")` ; le groupe `error` de fr.json ne contient pas cette clé,
           `i18n.js:124` a `fallbackLocale: [DEFAULT_LOCALE, 'en']` → `en.json:1720` « Something Wrong. ».
  fix-suggéré: ajouter `error.something_wrong` en FR.

[P3] resources/js/components/admin/components/MapComponent.vue:7,78,82,136,140 — anglais en dur sur la carte
  evidence: `placeholder="Enter a location"`, `alert('The Geolocation service failed.')`,
           `alert("Your browser doesn't support geolocation.")`. Doublons dans
           `settings/Branch/BranchShowComponent.vue:179,183`.

[P3] resources/js/components/admin/items/composer/StepEditorComponent.vue:3-8,28,34-39 — l'éditeur d'étapes parle en clés techniques
  friction: le patron qui compose un tacos voit `step_key`, `source_ref`, `item_attribute`,
           `extra_group`, `addon`, `No addon role`, `menu_component`, `upsell`.
  evidence: placeholders et `<option>` en dur, non traduits.

[P3] 38 fichiers de liste — `alt="Not Found"` en anglais sur les vignettes cassées
  evidence: ex. `items/ItemListComponent.vue:287`, `customers/CustomerListComponent.vue:137`.

[P3] resources/js/components/admin/ingredients/IngredientListComponent.vue:7 — bandeau « FoodKing V1 »
  friction: nom de l'éditeur affiché au patron sur un écran métier (marque non-Cayenne).
```

## §E — Listes, filtres, gestes

```
[P2] resources/js/components/admin/ingredients/IngredientListComponent.vue — liste sans recherche ni pagination
  friction: la liste des ingrédients (attributs + extras + addons, potentiellement des centaines)
           s'affiche d'un bloc, sans champ de recherche ni pagination : seulement 3 onglets de type.
  evidence: 0 occurrence de `PaginationComponent`/`paginate` et 0 occurrence de `search` dans les
           240 lignes du fichier ; le seul filtre est `selectTab(tab.value)` (l.39).
  fix-suggéré: champ de recherche + pagination, comme `TransactionListComponent.vue`.

[P3] resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue:74 — `window.prompt()` pour saisir le motif de rupture
  friction: fenêtre système grise pour un geste quotidien ; inutilisable sur certaines tablettes.
  evidence: `nextReason = window.prompt(this.$t('label.ingredient.toggle_prompt_reason'), ...)`.
  fix-suggéré: petite modale du design system.
```

---

# BLOC 3 — TROU DE PREUVE EN TEST RÉEL (fonctions BASE)

Tous les chemins ci-dessous ont été vérifiés présents sur disque (`ls`) avant citation.

```
1.  Tableau de bord KPI          — TEST-UNIT tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php
                                   TEST-E2E  tests/e2e/dashboard-visual-integrity.spec.js
2.  Produits CRUD + prix         — TEST-UNIT tests/Feature/AdminCrudComprehensiveTest.php:112-133
                                   TEST-E2E  tests/e2e/central-management-dashboard-crud.spec.js:565
3.  Catégories produits          — TEST-UNIT tests/Feature/AdminCrudComprehensiveTest.php:175-204  (⚠ create+delete seulement, AUCUN test d'update)
                                   TEST-E2E  tests/e2e/central-management-dashboard-crud.spec.js:591
4.  Rupture / 86                 — TEST-UNIT tests/Feature/Admin/AvailabilityControllerTest.php:37-90
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:1479
5.  Vue stock unifiée            — TEST-UNIT tests/Feature/Stock/UnifiedStockViewServiceTest.php  (⚠ appelle le SERVICE, jamais la route /api/admin/stock/unified-overview)
                                   TEST-VACUOUS tests/e2e/p3d-unified-stock-capture.spec.js:71 — n'assertit que `toBe(200)` + « pas d'état d'erreur » ; aucune valeur de stock vérifiée
6.  Ajustement matières          — TEST-UNIT tests/Feature/RawMaterials/RawMaterialAdjustEndpointTest.php
                                   TEST-E2E  tests/e2e/verif-globale-raw-material-adjust-2026-08-14.spec.js:301
7.  Scan facture → stock         — TEST-UNIT tests/Feature/Purchasing/PurchasingScanFlowTest.php:119
                                   TEST-E2E  tests/e2e/p3c-purchase-scan-capture-2026-07-24.spec.js (capture seule)
8.  Ingrédients (dispo)          — TEST-UNIT tests/Feature/Ingredients/IngredientControllerToggleTest.php:58-84
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:1413
9.  Historique commandes         — TEST-UNIT tests/Feature/OrderHistoryUnifiedTest.php:74-109
                                   (pas de spec e2e dédiée)
10. Commandes en ligne           — TEST-UNIT tests/Feature/WebAcceptIsAtomicTest.php:93
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:613,714,819,890
11. Transactions                 — TEST-VACUOUS tests/Feature/Sentinels/TransactionBranchExactnessSentinelTest.php:58-60 —
                                   le SEUL test PHP est `getJson('/api/admin/transaction?branch_id=1')->assertStatus(403)` :
                                   une assertion de REFUS, rien n'assertit jamais le contenu ni les montants de la liste.
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:1131 (aller-retour de filtre, toujours aucun montant asserté)
12. Rapport des ventes           — TEST-UNIT tests/Feature/Reports/SalesReportListMirrorParitySentinelTest.php:115-160
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:259
13. Rapport produits             — TEST-UNIT tests/Feature/Report/ReportPdfNoTruncationTest.php:136-160
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:314
14. Rapports Z / X               — TEST-UNIT tests/Feature/Fiscal/ZReportControllerTest.php + tests/Feature/Fiscal/XReportTest.php:35
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:1294
15. Utilisateurs (6 types)       — TEST-E2E  tests/e2e/admin-users-crud-functional.spec.js:27,64,118,156,193,230 (créer → apparaît → supprimer → disparaît)
                                   ⚠ AUCUN test PHPUnit n'appelle /api/admin/employee|chef|waiter|customer.
                                     Seul angle sécurité : tests/Feature/Sentinels/AdministratorBranchZeroMintBypassSentinelTest.php:132
16. Rôles (CRUD)                 — AUCUNE PREUVE RÉELLE — zéro test source touche RoleController ou /api/admin/setting/role
                                   (les seuls résultats du grep sont des artefacts de capture .console.json).
                                   Seule couverture : le balayage de rendu tests/e2e/admin-full-breadth-sweep.spec.js:154
16b Permissions                  — TEST-VACUOUS tests/Feature/Admin/PermissionControllerIndexAuthzTest.php:32-34 —
                                   `new PermissionController(...)` + `$controller->getMiddleware()` par réflexion, AUCUNE requête HTTP.
                                   TEST-VACUOUS tests/Feature/Security/AdminApiEnforcementDirectCallTest.php:253-271 —
                                   envoie `['permissions' => []]` et n'assertit qu'un code 200/201 : jamais que le rôle a changé.
17. Coupons                      — TEST-UNIT tests/Feature/Coupon/CouponCrudTest.php
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:168
17b Offres                       — TEST-UNIT tests/Feature/OffersDisabledV1SentinelTest.php:44-55 — prouve que le module est
                                   VOLONTAIREMENT 403 en V1 ; il n'existe donc aucune preuve que le CRUD Offres fonctionne (par construction).
18. Réglages — prouvés réels     — TEST-E2E tests/e2e/admin-settings-persist-functional.spec.js:43,75,103,136,183,217,269,385,425,547
                                   (Entreprise, Réseaux sociaux, Fidélité, Cookies, Commande, OTP, Borne, Mail, Site, Alertes de notification :
                                    éditer → enregistrer → recharger → persisté → restauré)
                                   + TEST-UNIT TimeSlot (tests/Feature/Settings/TimeSlotOverlapGuardTest.php),
                                     OrderSetup (tests/Feature/OrderSetupRequestNegativeValuesTest.php),
                                     Mail (tests/Feature/Settings/MailLicenseEnvInjectionGuardTest.php),
                                     Imprimantes (tests/Feature/PrinterControllerTest.php),
                                     Terminaux (tests/Feature/Admin/PaymentTerminalControllerTest.php)
18b Réglages — TVA, Devises,     — TEST-VACUOUS tests/e2e/admin-full-breadth-sweep.spec.js:228 (+ garde l.240-243
    Langues, Pages, Sliders,       `expect(failures, ...).toEqual([])`) : l'unique condition de succès est la chaîne
    Thème, Licence                 « working page (routed content rendered, no error, no i18n leak) ».
                                   La page s'affiche, pas de 5xx, pas de clé i18n brute — ZÉRO mutation, ZÉRO persistance assertée.
                                   ⚠ La TVA est un réglage fiscal : c'est le trou de preuve le plus lourd de la liste.
19. Interrupteurs (Pilotage)     — TEST-UNIT tests/Feature/Pilotage/InterrupteurTest.php:57-95
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:1072
20. Observabilité / synchro      — TEST-UNIT tests/Feature/Observability/SyncOverviewControllerTest.php
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:986
21. Push / Messages / Abonnés    — Push : TEST-UNIT tests/Feature/Admin/PushNotificationBranchIdSpoofTest.php:51 · TEST-E2E …:933
                                   Messages : TEST-E2E …:1260 SEULEMENT — aucun test PHPUnit ne touche /api/admin/message
                                   Abonnés : TEST-UNIT tests/Feature/Admin/SubscriberMailSubjectTest.php:45 · TEST-E2E …:102
22. Tables de salle              — TEST-UNIT tests/Feature/AdminCrudComprehensiveTest.php:355-399
                                   TEST-E2E  tests/e2e/admin-operations-crud-functional.spec.js:40
```

**Maillons les plus faibles, classés** : ① Rôles CRUD (rien du tout) · ② Permissions (réflexion + code de statut seulement) · ③ Transactions (403 seulement) · ④ Réglages TVA / Devises / Langues / Pages / Sliders / Thème / Licence (balayage de rendu) · ⑤ Vue stock unifiée (aucun test au niveau de la route) · ⑥ Employés/Cuisiniers/Serveurs/Livreurs/Clients (aucun test PHPUnit d'API).

---

# BLOC 4 — CASSÉ / INACHEVÉ CONNU

```
[MORT] resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue:18
  « Status : SKELETON — implementation TODO Codex. » + `TODO Codex (plan task 2.9)` l.145.
  Vérifié NON IMPORTÉ : grep `ProductCreateWizard` dans tout `resources/js/` → seulement des
  auto-références (l.3, l.119, l.183). Aucune route, aucun parent. Code mort qui décrit pourtant
  une friction réelle (« le flux morcelé … l'atlas des 9 étapes ») — le Catalog Studio + le
  Composer l'ont partiellement absorbée.

[ORPHELIN] app/Http/Resources/SettingResource.php:116 — `kiosk_admin_pin` figé à `null` (cf. §A bis).
[ORPHELIN] app/Http/Requests/SiteRequest.php — `site_email_verification`, `site_auto_update` (cf. §A bis).
[ORPHELIN] app/Http/Requests/CompanyRequest.php:37-39 — `company_city/state/zip_code` requis, jamais lus (cf. §A bis).
[ORPHELIN inverse] app/Services/WaitEstimateService.php:66 — `order_setup_wait_cap` lu, jamais écrit par un écran.
[ORPHELIN inverse] app/Http/Resources/SettingResource.php:110-111 — `kiosk_languages_enabled`, `kiosk_default_language` lus, jamais écrits.
[INERTE] app/Http/Controllers/Admin/PaymentGatewayController.php:29 — l'écran pilote `gateway_options`
  (Stripe/PayPal) alors que la passerelle vivante est Mollie, câblée sur `.env`
  (`config/payment.php:114-116`, verrou `app/Http/PaymentGateways/Gateways/Mollie.php:73-76`).
[DETTE assumée] app/Services/RawMaterials/FoodCostService.php:34, RawMaterialConsumptionService.php:72,
  Stock/UnifiedStockViewService.php:40, DailyBook/DailyBookService.php:15 — `BRANCH_ID = 1` en dur.
  Cohérent avec l'enveloppe V1 mono-branche (CONSTITUTION), à rouvrir avant tout multi-succursale.
[DETTE] app/Services/WaitEstimateService.php:33-37 — `STEP_MINUTES=5`, `ORDERS_PER_STEP=3`,
  `DEFAULT_CAP_MINUTES=30` en dur : seule la base (`order_setup_food_preparation_time`) est pilotable.
```

---

## Notes de méthode

- Tous les `file:line` ci-dessus proviennent d'un `Read`/`grep` réel effectué pendant cette session sur `e2d2ca3b4`.
- Aucun constat n'est repris d'un rapport antérieur sans re-vérification : les 4 correctifs de W1 (blob d'export, permission-url, `config/report.php`, validation FR) ont été confirmés **livrés** et sont donc **absents** de ce rapport ; les réglages orphelins et les entrées de menu masquées ont été confirmés **toujours ouverts**.
- Hors périmètre volontairement : `Admin/Pos/**`, KDS/OSS, roue, ticket promo, photo Uber (voies CAISSE/KDS, déjà auditées).
