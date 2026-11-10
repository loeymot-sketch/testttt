# GOAL — Livraison & tickets · Admin contrôle total · Vitrine/catalogue distants · App des stores (2026-11-10)

Demande du propriétaire (2026-11-10, verbatim résumé) : « audit profond de notre système de livraison, des accès,
de l'impression du ticket de livraison et des tickets de caisse — il devrait y avoir le téléphone, l'adresse et le
type d'encaissement choisi par le client sur le site, tout ça ne fonctionne pas complètement ; finir l'application
mobile au maximum de qualité ; contrôle total depuis l'admin : stock, personnalisation des produits, création
d'offres, accès admin et livreurs ; une mise à jour de produit, d'offre, de photo, de prix, de code promo ou un
design d'un jour pendant les fêtes ne doit jamais demander aux clients de mettre l'application à jour. »

---

## §0 Préambule

### 0.1 Arbre de travail
- Dépôt `testttt`, branche `pos/category-first-caisse-2026-06-23`, HEAD `98fab4f5f` (docs). **En ligne** : backend
  `4c48695d2` (déployé 2026-11-08, vérifié), site `b61d61f` (Vercel). Arbre PARTAGÉ avec la session `testttt-59`
  (195 fichiers suivis modifiés par d'autres sessions, tous `reports/` et `tests/captures/`) → **commit par pathspec
  uniquement**, jamais `git add .`, jamais de `git stash`.
- Site + app : worktree `/Users/1millnonstop/Downloads/lecayenne-web-deploy/site-deploy-2026-11-03`, branche
  `app/catalogue-distant-2026-11-05` (= en ligne). `app/node_modules` est un lien : après `cap sync`,
  `git restore -- app/ios/App/CapApp-SPM/Package.swift app/android/capacitor.settings.gradle`.
- Production **en lecture seule** pendant l'audit ; abus et reproductions en local (`:8766`) ou sur un serveur privé.

### 0.2 Périmètre et hors-périmètre
- IN : 4 systèmes ci-dessous. OUT : borne (gelée), paiement en ligne Mollie, Uber Direct (bloqué côté Uber), prototype
  `mobile/` (mort, cf. CLAUDE.md §3bis).

### 0.3 Pipeline par tâche
Chaque tâche s'exécute avec `ultra-audit-profond` (5 spécialistes lecture seule → synthèse → TDD → RED → tests →
visuel → RED visuel). Ce document ne redécrit pas le pipeline. Zones gelées : la liste canonique est
CLAUDE.md §7 (13 fichiers/dossiers) ; toute touche = skill `lock-plan` + porte propriétaire (§G).

### 0.4 Critères de convergence
Une vague est close quand : P0+P1 = 0 sur deux cycles consécutifs aux constats identiques ; diff zones gelées = 0 ;
`php artisan fiscal:verify-chain --all` CHAIN OK ; captures lues ; BRAIN §2/§3 à jour. Règles de rejet : Axe 6 du skill.

### 0.5 Ce que l'ancrage a déjà montré (hypothèses à PROUVER en W1, pas des conclusions)
1. Le ticket thermique (client ET cuisine) ne lit le téléphone que dans `orders.pos_customer_phone`
   (`app/Services/Hardware/OrderReceiptEscPosRenderer.php:166` et `:383`). Une commande du site n'a pas ce champ.
   L'écran caisse (`OrderDetailsResource.php:165,170`) ne replie sur le compte que si `source_surface === 'web'` ;
   or toute LIVRAISON du site porte `source_surface='delivery'` (forcé par `FrontendOrder::creating`,
   `FrontendOrderService.php:1201`) : **ticket ET écran étaient aveugles sur le cas du propriétaire**.
   → **PROUVÉ puis CORRIGÉ le 2026-11-10** (`ClientTicketLivraisonCoordonneesTest` 8/8, rouge 6/7 avant) :
   SSOT `app/Support/CoordonneesClientTicket.php`, renderer + resource alignés sur `web/online/delivery`.
2. **Aucun ticket n'imprime l'adresse du client** : le renderer ne lit que l'adresse de la branche (`:81`) ; l'adresse
   de livraison existe pourtant en base (`order_addresses` : address, apartment, city, postal_code, créée dans
   `app/Services/FrontendOrderService.php:859-869`). → corrigé (bloc `LIVRER A :`, en-tête cuisine `Livrer : …`).
3. Une livraison à régler à la porte imprime `** A REGLER EN CAISSE **` (`:233`) ; le mode choisi sur le site
   (`payment_method` 1 espèces / 4 carte en ligne / 5 titre-restaurant, et `orders.door_payment_mode='card'` posé par
   `FrontendOrderService.php:381`) n'est jamais lu par le renderer. Déjà listé P3 dans
   `reports/test-e2e/audit-abusif-2026-11-06/CONVERGENCE_FINAL.md` (« ticket livraison À RÉGLER EN CAISSE »).
   → corrigé (`** A REGLER A LA LIVRAISON : ESPÈCES | CARTE AU LIVREUR | TITRE-RESTAURANT **`).
4. Le reçu HTML de la caisse (`resources/js/components/admin/pos/ReceiptComponent.vue:144-145`) n'affiche que
   l'adresse/téléphone de la branche, jamais ceux du client ni le mode de règlement à la porte.
5. Le module **Offres** est volontairement désactivé en V1 (`tests/Feature/OffersDisabledV1SentinelTest.php` :
   l'admin affichait une remise que `PricingService` — gelé — n'appliquait pas). « Créer des offres » passe donc
   aujourd'hui par : coupons, promos borne, ticket promo, bandeaux de vitrine à `coupon_code`. Décision propriétaire
   requise (porte G1).
6. La vitrine distante planifie les **bandeaux** (`starts_at`/`ends_at`, `next_change_at`) mais pas le **thème** :
   un « design d'un jour » demande aujourd'hui deux gestes manuels (publier le matin, republier le soir).
7. Rôle `Delivery Boy` : `landing_url = 'delivery-boys'` (`database/seeders/LeCayenneRoleLandingUrlSeeder.php:31`),
   c'est la LISTE ADMIN des livreurs, pas l'écran `/livreur` ; `RolePermissionTableSeeder.php:48-52` donne les
   permissions `delivery-boys*` à l'ADMIN, et aucun bloc n'existe pour `EnumRole::DELIVERY_BOY` (rôles traités :
   Admin, Branch Manager, POS Operator, Chef, Stuff) → mini-découverte T-1.4.1. Un changement de seeder en prod =
   `db:seed --class=…` = déploiement (porte G3).
8. Relecture indépendante du plan (agent Plan, 2026-11-10) : 12 corrections appliquées ; une réfutée — la route
   `GET /pos/orders/{order}/escpos` (`routes/api.php:1662`) n'a pas de middleware `permission`, mais le contrôleur
   garde lui-même (`PosTicketBytesController.php:38-41`, `can('pos')` ou `kitchen-display-system` pour le ticket
   cuisine) ; T-1.3.3 ajoute néanmoins la sentinelle Chef/livreur → 403 dans `BorneEscposEndpointTest`.

---

## §1 Carte principale (backend testttt)

| # | Système | Maturité | Ancre vérifiée (2026-11-10) | Tests existants |
|---|---|---|---|---|
| 1 | Livraison & tickets | Livraison mûre (docs/LIVRAISON.md §1-9, 43 fichiers `tests/Feature/Delivery/`) ; **tickets livraison incomplets** (§0.5-1..4, corrigés le 2026-11-10, reste 1.2/1.3/1.4) | `OrderReceiptEscPosRenderer.php`, `ReceiptDataService.php`, `PosTicketBytesController.php`, `DeliveryRunController.php`, `DeliveryHandoverController.php` | `tests/Feature/Hardware/` (34), `tests/Feature/Delivery/` (43), `tests/Playwright/livraison-*` |
| 2 | Admin contrôle total | Stock mûr (26 fichiers de tests) ; produits mûrs (studio/composer) ; **offres désactivées** ; accès : 52 FormRequests `return true` restants | `Admin/UnifiedStockViewController.php`, `ItemController.php`, `WizardPageController.php`, `OfferController.php`, `CouponController.php`, `EmployeeController.php`, `RoleController.php` | `tests/Feature/Stock/`, `tests/Feature/Item*`, `tests/Feature/Admin/Employee*`, `tests/Feature/Coupon/` |
| 3 | Vitrine & catalogue distants | Livrés 2026-11-03/05 (47 tests storefront), thème non planifiable | `Frontend/StorefrontController.php`, `Admin/StorefrontAdminController.php`, `Services/Storefront/StorefrontService.php`, `config/storefront.php`, `Frontend/AppConfigController.php` | `tests/Feature/Storefront/` (4 fichiers, 47 tests) |
| 4 | Application des stores | Code prêt (PUBLICATION.md §2), jamais compilée ici (Xcode absent), Android compilable | voir §2 | site `tests-e2e/vitrine-*.spec.js`, `tools/verify-app-device.py`, `tools/verify-app-behaviour.mjs` |

## §2 Carte séparée (site + app, dépôt Site-lecayenne)

| Pièce | Fichier | Rôle |
|---|---|---|
| Tunnel de commande | `funnel.jsx` (3141-3160 : lcfReglementLivraison/lcfCarteReglement), `api.js:1606-1621` (payload `payment_method`, `address_id`, `delivery_slot_at`) | envoie le choix de règlement ; la vérité « carte au livreur » = `door_payment_mode` |
| Vitrine distante | `vitrine.js` (GET `/api/frontend/storefront`, ETag, relecture 5 min + `next_change_at`) | couleurs + bandeaux sans nouvelle version |
| Catalogue distant | `data/catalogue-distant.js` (GET `/api/frontend/item`), `data/catalogue-reference.js` (généré 2026-11-05) | produits/photos/prix/ruptures/catégories sans nouvelle version |
| App Capacitor | `app/capacitor.config.json` (`fr.lecayenne.app`), `app/www` (285 fichiers), `app/PUBLICATION.md` | `npm run check:www` → `build:www` → `cap sync` |

---

## §3 Système 1 — Livraison & tickets

### Contrat
Une commande du site (retrait ou livraison) doit sortir, sur le ticket client thermique, sur le ticket cuisine et sur
le reçu HTML de la caisse, avec : nom, **téléphone joignable**, **adresse complète de livraison** (si livraison),
créneau, et **le règlement tel que le client l'a choisi** (payé en ligne / espèces à la porte / carte au livreur /
titre-restaurant), jamais « à régler en caisse » pour une livraison. Le livreur et l'admin n'accèdent qu'à ce que leur
rôle autorise.

### Zones gelées concernées
`PaymentComponent.vue` (impression du reçu après encaissement caisse), `pos-wizard.js`, `PricingService.php`,
`OrderStateMachine.php`. Les tickets (renderer, ReceiptComponent, resources) ne sont PAS gelés.

### Ancres (vérifiées par `find app -iname "*Deliver*"`, `grep -n phone|address|payment OrderReceiptEscPosRenderer.php`)
`app/Services/Hardware/OrderReceiptEscPosRenderer.php` (client :45-260, cuisine :290-400, nom :634-670, paiements
:872-935) · `app/Support/CoordonneesClientTicket.php` (SSOT téléphone / adresse / règlement à la porte, créé 2026-11-10) · `app/Services/Receipt/ReceiptDataService.php` · `app/Http/Controllers/Admin/Pos/PosTicketBytesController.php:26-69`
· `app/Listeners/PrintFiscalReceiptAndOpenDrawerOnCounterPaid.php` · `app/Listeners/AutoPrintKitchenTicketOnKitchenEntry.php`
· `app/Models/OrderAddress.php` · `app/Http/Resources/OrderDetailsResource.php:86-170` ·
`resources/js/components/admin/pos/ReceiptComponent.vue` · `resources/views/livreur.blade.php`, `livreur-remise.blade.php` ·
`app/Http/Controllers/Frontend/DeliveryRunController.php` · `docs/TICKET_CONTRACT.md`, `docs/LIVRAISON.md`.

### Sub 1.1 — Coordonnées et règlement sur les tickets thermiques (client + cuisine)
**Tâches**
- T-1.1.1 ✅ (6/7 rouges le 2026-11-10 avant correctif, 8/8 verts après) Prouver le défaut : commande web livraison (espèces, carte au livreur, carte en ligne, titre-restaurant) ×
  retrait web × caisse téléphone → octets ESC/POS des deux tickets ; relever téléphone/adresse/règlement absents.
  • anchor : `OrderReceiptEscPosRenderer.php:163-170, :215-235, :381-386` • test : (TO BE CREATED
  `tests/Feature/Hardware/ClientTicketLivraisonCoordonneesTest.php`, rouge d'abord).
- T-1.1.2 ✅ Téléphone : résolution unique « saisi en caisse, sinon compte du client des surfaces web/online/delivery »,
  partagée avec `OrderDetailsResource:165,170` (helper `app/Support/CoordonneesClientTicket.php`, un seul SSOT ;
  `PhoneDisplay::safe` ne masque que la sentinelle `PENDING_`, le numéro complet est imprimé : c'est la demande du
  propriétaire du 2026-11-10, le livreur doit pouvoir appeler). • anchor : renderer `:634-670` • tests :
  `tests/Feature/Hardware/TicketNomClientTest.php` (existant, inchangé) + ClientTicketLivraisonCoordonneesTest (8).
- T-1.1.3 ✅ (ticket) / ouvert (longueur cuisine, porte G8) Adresse de livraison sur le ticket client (bloc « LIVRER À » : adresse, complément, CP ville, créneau) et en
  en-tête cuisine (une ligne tronquée, width-safe), seulement `order_type = DELIVERY` ET surface web/caisse-livraison,
  jamais Uber. • anchor : `OrderAddress` (`order_addresses.address/apartment/city/postal_code`), renderer `$headerLine`
  `:360-370` • tests : (TO BE CREATED `tests/Feature/Hardware/KitchenTicketLivraisonAdresseTest.php`) +
  `tests/Feature/Hardware/TicketWidthSafeTest.php` (existant).
- T-1.1.4 ✅ Règlement choisi : remplacer `** A REGLER EN CAISSE **` par « À RÉGLER À LA LIVRAISON : ESPÈCES / CARTE AU
  LIVREUR / TITRE-RESTAURANT » selon `payment_method` + `door_payment_mode` ; « PAYÉ EN LIGNE » si `payment_method=4`
  payé ; caisse/borne inchangées. Même table de libellés que le site (`funnel.jsx:3141`) et que
  `DeliveryHandoverService.php:587`. • anchor : renderer `:215-235`, `:872-910` • tests : (TO BE CREATED
  `tests/Feature/Hardware/ClientTicketReglementPorteTest.php`) + `tests/Feature/PosTicketRestaurantPaymentTest.php`,
  `tests/Feature/Delivery/DeliveryCardAtDoorTest.php` (existants).
- T-1.1.5 Réimpression à la remise : le ticket réimprimé APRÈS encaissement à la porte montre le moyen réellement
  encaissé (ligne `order_payments`), pas « à régler ». • anchor : `payments()` `:872-910`, `DeliveryHandoverService` •
  test : `tests/Feature/Delivery/DeliveryDoorSplitTest.php` (existant) + cas ajouté dans ClientTicketReglementPorteTest.
**Acceptance** : 3 nouveaux fichiers verts + `phpunit --testsuite Feature --filter 'Hardware|Ticket'` vert (33 fichiers) +
`docs/TICKET_CONTRACT.md §4` mis à jour + capture d'un ticket rendu (outil `tools/` de prévisualisation ESC/POS → texte)
lue et analysée.

### Sub 1.2 — Reçu HTML et écran caisse
- T-1.2.1 ✅ (2026-11-10 : `clientLines`/`doorPaymentLabel`, libellés `resources/js/languages/{fr,en}.json`, Vitest 6/6,
  capture Playwright lue — `tests/Playwright/livraison-tickets-2026-11-10/recu-caisse-livraison.spec.js`) `ReceiptComponent.vue` : bloc client (nom, téléphone, adresse de livraison, règlement choisi) à partir de
  `OrderDetailsResource` (`order_address`, `door_payment_mode`, `pos_customer_phone`), 0 libellé brut, FR.
  • anchor : `ReceiptComponent.vue:140-150, :260-310` ; dépend de T-1.1.2 (`OrderDetailsResource.php` déjà aligné)
  • test : (TO BE CREATED `tests/js/receiptComponentLivraison.spec.js`) • visuel : `http://127.0.0.1:8766/admin/pos` →
  reçu d'une livraison web.
- T-1.2.2 ✅ 2026-11-10 (planche : `phone` + adresse complète via le SSOT, `DeliveryBoardAndCatchUpTest`, `posTourneesTicketClient.spec.js`, capture lue) Planche Livraisons / file d'encaissement : vérifier que téléphone, adresse, mode à la porte sont visibles et
  identiques au ticket (même SSOT). • anchor : `PosDeliveryBoard.vue`, `PosDeliveryPage.vue`, `OrderDetailsResource:86-135`
  • test : `tests/Feature/Delivery/DeliveryBoardAndCatchUpTest.php` (existant) + cas « surface delivery » dans
  (TO BE CREATED `tests/Feature/Receipt/ReceiptParityLivraisonTest.php`) • visuel : `/admin/pos` onglet livraisons.
  ⚠️ `PosDeliveryBoard.vue` est aussi touché par T-1.3.3 : un seul Implementer, 1.2 PUIS 1.3.
- T-1.2.3 ✅ 2026-11-10 (`ReceiptParityLivraisonTest` : total, téléphone, adresse, règlement identiques ticket / ressource caisse / planche / livreur ; a révélé et corrigé la commune imprimée deux fois sur une adresse saisie à la main — PHP et jumeau JS) Parité numérique ticket thermique = reçu HTML = écran = site (total, frais, règlement) sur une même commande.
  • test : (TO BE CREATED `tests/Feature/Receipt/ReceiptParityLivraisonTest.php`) + banc
  `tests/Playwright/livraison-parcours-complet.spec.js` (existant, à étendre d'une assertion sur le reçu).
**Acceptance** : Vitest ciblé vert, PHPUnit Receipt vert, captures 1280/1920 lues, 0 `Label.X`.

### Sub 1.3 — Déclenchement de l'impression du ticket de livraison
- T-1.3.1 ✅ 2026-11-10 (`docs/TICKET_CONTRACT.md §2bis` ; confirmé : une livraison à la porte n'a AUCUN ticket client sans clic, le serveur n'imprime jamais, tout passe par les ponts des postes) Cartographier QUI imprime le ticket client d'une livraison web et QUAND : seul chemin automatique trouvé =
  encaissement au comptoir (`PrintFiscalReceiptAndOpenDrawerOnCounterPaid`) ; le ticket cuisine est automatique à
  l'acceptation (`AutoPrintKitchenTicketOnKitchenEntry`). Une livraison à encaisser à la porte n'a donc peut-être AUCUN
  ticket client imprimé sans clic. • anchor : les deux listeners, `PosTicketBytesController`, `PosOrdersTrackerComponent.vue`
  • livrable : tableau surface × événement × ticket dans `docs/TICKET_CONTRACT.md §2`.
- T-1.3.2 Règle à proposer au propriétaire puis coder : ticket client (avec QR de remise) imprimé automatiquement au
  passage PRÊTE d'une livraison de nos tournées (ou au départ de tournée), une seule fois, best-effort, journalisé.
  • anchor : `DeliveryWaveService.php` (départ), `OrderStateMachine` (GELÉ : on s'abonne à l'événement, on ne le modifie
  pas) • test : (TO BE CREATED `tests/Feature/Delivery/DeliveryTicketAutoPrintTest.php`) +
  `tests/Feature/Hardware/ClientTicketDeliveryQrTest.php`, `tests/Feature/Delivery/DeliveryHandoverQrPrintFlagTest.php`.
- T-1.3.3 ✅ 2026-11-10 (bouton « Ticket » sur chaque arrêt non remis de la planche, même chemin que le Suivi ; pas de « duplicata » : aucune première impression n'existe pour une livraison à la porte ; livreur → 403 client ET cuisine dans `DeliveryBoyAccessMatrixTest`, Chef déjà couvert par `KdsKitchenTicketAuthzTest`) Réimpression depuis la planche Livraisons (bouton, duplicata marqué) + sentinelle : Chef et livreur → 403 sur
  `GET /pos/orders/{order}/escpos?ticket=client` (garde en contrôleur, pas en route — §0.5-8). • anchor :
  `PosTicketBytesController:26-69` (`duplicata`, `abort_unless can('pos')`) • test : `tests/Feature/Hardware/BorneEscposEndpointTest.php`
  (existant, étendre) • visuel : planche.
- T-1.3.4 ✅ 2026-11-10 (§2bis écrit) `docs/TICKET_CONTRACT.md` : §4 déjà mis à jour (2026-11-10) ; §2 (surface × événement × ticket) écrit EN FIN de
  W1 par le dernier Implementer (fichier partagé par 1.1 et 1.3).
**Acceptance** : tests verts ; une livraison web de bout en bout (banc Playwright existant) produit exactement 1 ticket
cuisine + 1 ticket client, prouvé par le journal d'impression (`Printer`/logs) ; 0 double ticket.

### Sub 1.4 — Accès livreur et admin
- T-1.4.1 ✅ 2026-11-10 (relevé dev + prod lecture seule : rôle sans AUCUNE permission, prod = 0 compte livreur, dev = 12 ; sonde dynamique `DeliveryBoyAccessMatrixTest` : 74 routes admin sans middleware de permission → 6 lectures de configuration sans client ni argent, 0 commande, 0 mutation) MINI-DÉCOUVERTE : matrice réelle des droits du rôle `Delivery Boy` (permissions Spatie en base de dev, `landing_url`,
  routes `/api/frontend/delivery-run*`, `/api/admin/delivery-boy*`, `/livreur`, `/livreur/remise`) ; vérifier ce qu'un
  compte livreur peut lire dans l'admin SPA. • anchor : `LeCayenneRoleLandingUrlSeeder.php:31`, `routes/api.php:985-1030,
  2501-2520`, `DeliveryRunController.php:38,193,201` • test : (TO BE CREATED `tests/Feature/Authz/DeliveryBoyAccessMatrixTest.php`).
- T-1.4.2 ✅ 2026-11-10 (landing `livreur` : seeder + migration de données `2026_11_10_100000` + route SPA `admin.livreur` → sortie dure `/livreur` ; `AntiGravityLoginRedirectionTest`, `livreurAtterritSurSonEcran.spec.js`) Atterrissage : un livreur qui se connecte arrive sur `/livreur`, pas sur la liste admin des livreurs ; un
  caissier n'arrive jamais sur `/livreur`. • anchor : `LoginController.php:197`, seeder landing • test : matrice T-1.4.1 +
  `tests/Feature/Auth/MultiDeviceLoginTest.php` (existant, non régressé).
- T-1.4.3 ✅ 2026-11-10 (arrêts/photo/départ d'un collègue → 403 sans adresse ni téléphone, dans la matrice ; caisse livreur déjà couverte par `DeliveryRunCashTest`) Isolation : un livreur ne voit que SES arrêts, ses photos, sa caisse ; un autre livreur / un client → 403/404
  sans fuite (adresse, nom, téléphone). • anchor : `DeliveryRunController:38-84, 189-201`, `DeliveryHandoverController`
  • tests : `tests/Feature/Delivery/DeliveryHandoverTokenTest.php`, `DeliveryRunCashTest.php` (existants) + cas adverses
  dans la matrice.
- T-1.4.4 Admin : créer/suspendre un livreur, réinitialiser son mot de passe, voir sa caisse et ses remises depuis
  `resources/js/components/admin/deliveryBoys/*` et `deliveryBoyCashSession/*` ; sessions révoquées au changement
  d'identifiants. • tests : `tests/Feature/Admin/DeliveryBoyCashSessionControllerTest.php`,
  `tests/Feature/Admin/StaffCredentialChangeRevokesSessionsTest.php`, `tests/Feature/Delivery/DeliveryBoyAddressPermissionSplitTest.php`
  (existants) • visuel : `/admin/delivery-boys`.
- T-1.4.5 (téléphone ✅ 2026-11-10 : `DeliveryRunController` lit `CoordonneesClientTicket::telephone` puis le compte ;
  mode à la porte ✅ prouvé identique ticket ↔ livreur sur les 3 modes dans `ReceiptParityLivraisonTest` ; reste : adresse
  complète sur `/livreur` = champs séparés, à confronter au SSOT si l'écran change) Écran du LIVREUR `/livreur` et `/livreur/remise` : téléphone
  cliquable, adresse (+ Waze), mode de règlement choisi = même SSOT que le ticket (`DeliveryRunController:280-305`).
  • tests : `tests/Feature/Delivery/DeliveryRunTest.php`, `DeliveryHandoverConfirmTest.php` (existants, étendre d'une
  assertion de parité) • visuel : `/livreur` sur viewport téléphone.
**Acceptance** : matrice verte (chaque case = une assertion), `phpunit --filter 'Delivery|Authz'` vert, captures lues.

---

## §4 Système 2 — Admin : contrôle total

### Contrat
Depuis l'admin, le propriétaire gère seul : le stock (niveaux, ruptures, pertes, matières), la personnalisation de
chaque produit (options, suppléments, tailles, photos, canaux, allergènes), les offres et codes promo, et les accès
(employés, rôles, livreurs). Chaque geste est visible sur caisse, borne, cuisine, site ET application sans republication.

### Zones gelées concernées
`PricingService.php` (prix et remises : toute offre « qui change le prix » passe par lui → porte G1), `BranchScope.php`.

### Ancres (vérifiées par `ls app/Http/Controllers/Admin`, `ls resources/js/components/admin`)
`UnifiedStockViewController.php`, `StockRuptureDashboardController.php`, `PosStockOutflowController.php`,
`resources/js/components/admin/stock/{UnifiedStockViewComponent,StockRuptureDashboardComponent,RawMaterial*}.vue` ·
`ItemController.php`, `ItemAddon/Extra/Variation/Attribute/PhotoController.php`, `WizardPageController.php`,
`resources/js/components/admin/items/{CatalogHubComponent,CatalogStudioComponent,ItemPhotoUpload,composer,wizard}` ·
`OfferController.php`, `CouponController.php`, `PromoFlyerController.php`, `KioskPromo.php`, `UpsellRule.php` ·
`EmployeeController.php`, `RoleController.php`, `PermissionController.php`, `DeliveryBoyController.php`.

### Sub 2.1 — Stock
- T-2.1.1 ✅ 2026-11-10 (suite Stock 27 fichiers / 93 verts + `StockRuptureDashboardEndpointsTest` ; capture `/admin/stock/rupture` lue) Parcours admin : rupture manuelle, réassort, perte déclarée (bornée au stock réel depuis `05448beb3`), matière
  première ; effet immédiat sur caisse/borne/site (`/api/frontend/item` `is_available`). • tests :
  `tests/Feature/Stock/StockCrossSurfaceSyncTest.php`, `StockRuptureAvailabilitySyncTest.php`,
  `ManualEightySixStickyThroughRestockTest.php`, `tests/Feature/Admin/StockRuptureDashboardEndpointsTest.php` (existants)
  • visuel : `/admin/stock/rupture`, vue unifiée.
- T-2.1.2 ✅ relevé 2026-11-10 : DÉJÀ construit — widget `StockLowAlertsWidget.vue` (tableau de bord + caisse) sur `GET admin/stock/low-alerts` (3 tests existants verts : `StockRuptureDashboardLowAlertsN1Test`, `…RawMaterialAlertsTest`, `UnSeuilDAlerteStockPeutEnfinEtreSaisiTest`) ; e-mail/journal derrière `FK_CATALOG_STOCK_LOW_ALERT_ENABLED` = **true en production** (lu via `config()`, lecture seule). Pas de test à créer. Alerte stock bas réellement visible (seuil, pastille, e-mail) — backlog MASTER-GAP-046 (BRAIN §5). • anchor :
  `app/Listeners/NotifyStockLowOnStockLevelChanged*`, `tests/Feature/Stock/NotifyStockLowOnStockLevelChangedTest.php` •
  test : (TO BE CREATED `tests/Feature/Stock/StockLowAlertVisibleInAdminTest.php`).
- T-2.1.3 ✅ 2026-11-10 (`WizardOptionStockSyncTest` vert dans la suite Stock ; site `catalogue-distant-2026-11-05.unit.mjs` 55/55 ; `verify-catalogue-distant.mjs --prod` : 40 produits, 9 catégories, 13 hors carte à dessein, 0 erreur JS) Rupture d'une boisson de menu / d'un supplément → refusée sur TOUTES les surfaces (acquis `05448beb3` pour
  caisse/borne ; vérifier site/app via catalogue distant). • tests : `tests/Feature/Stock/WizardOptionStockSyncTest.php`
  (existant) + site `tests-e2e/catalogue-distant-2026-11-05.unit.mjs` (existant).
**Acceptance** : suite `tests/Feature/Stock` verte (26 fichiers), banc cross-surface vert, captures lues.
⚠️ Fichier partagé avec Sub 2.2 : `app/Http/Controllers/Frontend/ItemController.php` / `ItemResource` (T-2.1.3 et
T-2.2.3/2.2.4) et le banc site `catalogue-distant-2026-11-05.unit.mjs` → un seul Implementer y touche, 2.1 PUIS 2.2.

### Sub 2.2 — Personnalisation des produits
- T-2.2.1 ✅ 2026-11-10 (suites Items/Catalog/Requests/Composer 59 fichiers 229 verts + Item 4 fichiers 32 verts ; captures `/admin/items`, `/admin/stock/rupture`, tableau de bord lues, 0 clé brute, 0 erreur console) Fiche produit complète depuis le studio : nom, prix, catégorie, canaux (caisse/borne/web), photo, allergènes,
  options (viande, sauce, taille), suppléments, formule → visibles en caisse, borne, site, app. • anchor :
  `CatalogStudioComponent.vue`, `ItemController.php`, `ItemPhotoUpload.vue` • tests : `tests/Feature/Item/`,
  `tests/Feature/Items/`, `tests/Feature/Catalog/`, `ItemExtraManagementTest.php`, `ItemModifierPriceValidationTest.php`
  (existants) • visuel : `/admin/items`.
- T-2.2.2 ✅ 2026-11-10 (message persistant `item-save-error` près d'« Enregistrer » en plus du toast + défilement de septembre ; `tests/js/itemFormErreurVisible.spec.js` 3 verts ; banc réel `fiche-produit-erreur-visible.spec.js` : nom vidé → 422 → message lu sur capture) Formulaire admin « échec muet » (mémoire `formulaire-admin-echec-muet`) : l'erreur doit être visible près du
  bouton Enregistrer ; 0 fiche non enregistrable sur le catalogue de PROD (fixtures
  `tests/Playwright/site-livraison-2026-10-23/fixtures-prod/items.json`). • test : (TO BE CREATED
  `tests/js/itemFormErreurVisible.spec.js`).
- T-2.2.3 ✅ vérifié 2026-11-10 (règle « photo du serveur ≠ référence » couverte par le banc site 55/55 ; lecture prod OK) Photo changée en admin → site + app en < 5 min sans republication (règle « photo du serveur ≠ référence »).
  • anchor : `data/catalogue-distant.js` règles photo, `ItemPhotoController.php` • test : site
  `tests-e2e/catalogue-distant-2026-11-05.unit.mjs` (existant) + `tools/verify-catalogue-distant.mjs --prod` (lecture).
- T-2.2.4 ✅ vérifié 2026-11-10 (même banc site + `FrontendDiscountIntegrityTest` rejoué) Produit « caisse seule » (`channels=['pos']`) : masqué du site/app ET refusé au serveur (acquis `05448beb3`) ;
  produit nouveau → carte construite depuis le serveur, options déductibles sinon non affiché + diagnostic. • test :
  idem T-2.2.3 + `tests/Feature/FrontendDiscountIntegrityTest.php` (existant).
**Acceptance** : suites Item/Catalog vertes, banc catalogue distant vert, capture studio lue.

### Sub 2.3 — Offres et codes promo (après porte G1)
- T-2.3.1 État des lieux honnête à présenter au propriétaire : Offres = 403 en V1 (sentinelle), coupons (admin +
  `/api/frontend/coupon/coupon-checking`), promos borne (`/api/frontend/promo/validate`), ticket promo nominatif, bandeau
  de vitrine à `coupon_code`. • tests : `tests/Feature/OffersDisabledV1SentinelTest.php`, `tests/Feature/Coupon/*`,
  `tests/Feature/Promo/*` (existants).
- T-2.3.2 Option A (sans zone gelée) : « Offre » = coupon planifié (dates, cumul, plafond, produits ciblés) + bandeau
  vitrine lié automatiquement ; un seul écran admin « Offres & codes » qui compose les deux. • anchor :
  `CouponController.php`, `StorefrontAdminController.php:67-95`, `resources/js/components/admin/{coupons,storefront}` •
  test : (TO BE CREATED `tests/Feature/Coupon/OffreComposeeCouponBandeauTest.php`) • visuel : écran admin.
- T-2.3.3 Option B (si le propriétaire veut des remises PAR PRODUIT appliquées au prix) : LOCK sur `PricingService.php`
  (skill `lock-plan`), câblage Offers → `calculateOrder`, levée de la sentinelle V1. • porte G1 + G6 obligatoires
  • tests : `tests/Feature/OffersDisabledV1SentinelTest.php` (réécrit en « offres câblées ») + (TO BE CREATED
  `tests/Feature/Pricing/OfferAppliedByPricingServiceTest.php`).
- T-2.3.4 Site/app : code promo tapé → même réponse que la caisse/borne (montant, 422 lisible, plafond), bandeau « offre
  flash » cliquable qui pré-remplit le code. • anchor : `api.js:1929`, `vitrine.js` bandeaux • tests : site
  `tests-e2e/vitrine-distante-2026-11.spec.js` (existant) + `tests/Feature/CouponSecurityTest.php`, `tests/Feature/Coupon/CouponValidityTest.php`.
**Acceptance** : option retenue codée, tests verts, capture admin + capture app lues.

### Sub 2.4 — Accès (employés, rôles, livreurs)
- T-2.4.1 Matrice rôle × écran × action pour Admin, Branch Manager, POS Operator, Chef, Delivery Boy (seeder
  `RoleTableSeeder.php:19-62`) : ce que chacun voit et peut muter ; écarts avec `docs/AUTHZ_MATRIX.md`. • tests :
  `tests/Feature/Authz/*`, `tests/Feature/Admin/{EmployeeRoleGrantEntitlementTest,EmployeePeerManagementGuardTest,
  PermissionControllerIndexAuthzTest}.php` (existants) + T-1.4.1.
- T-2.4.2 ✅ 2026-11-10 (52 → 46 : adresse livreur, adresse employé, attribut, import catégories, import produits, photo produit ; `FormRequestAuthorizeWaveLivraisonTest` ; coupons/items/catégories déjà durcies avant, `CouponCheckRequest` = route publique du site, laissée) Cliquet FormRequest `RETURN_TRUE_BASELINE` (52) : traiter les FormRequests des contrôleurs de ce GOAL
  (livreurs, stock, items, coupons, storefront) et resserrer la constante. • anchor :
  `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:83` • tests : un test `authorize()` par FormRequest
  resserrée (TO BE CREATED `tests/Feature/Authz/FormRequestAuthorizeWaveLivraisonTest.php`) + la sentinelle.
- T-2.4.3 Gestion des accès depuis l'admin : créer un employé/livreur, changer rôle, suspendre, réinitialiser mot de
  passe, révocation des sessions ; 0 prise de compte d'un pair. • tests : `StaffCredentialChangeRevokesSessionsTest.php`,
  `EmployeeAbusGuardTest.php` (existants) • visuel : `/admin/employees`, `/admin/roles`.
**Acceptance** : matrice verte, sentinelle resserrée (nombre cité relu dans le code), captures lues.

---

## §5 Système 3 — Vitrine et catalogue distants (mise à jour sans republication)

### Contrat
Tout ce que le restaurant change en admin — couleurs, bandeaux, message de livraison, produits, photos, prix, ruptures,
codes promo — est visible dans le site et l'application installée en moins de 5 minutes et au retour au premier plan,
sans nouvelle version. Un « design d'un jour » se programme à l'avance et se défait seul. Règle Apple 2.5.2 : des
RÉGLAGES, jamais du code.

### Ancres
`StorefrontService.php` (`next_change_at` :169, planification bandeaux :126-159), migrations `*storefront*` (`theme` json,
`starts_at/ends_at` sur bandeaux, `snapshot`, `version`), `StorefrontComponent.vue` (601 lignes), `vitrine.js`,
`data/catalogue-distant.js`, `AppConfigController.php` (`online_payment_enabled`), `routes/api.php:2300-2312`.

### Sub 3.1 — Vitrine : thème planifiable
- T-3.1.1 ✅ 2026-11-10 (`storefront_settings.scheduled_themes` JSON — migration `2026_11_10_110000` ; `StorefrontService::normalizeScheduledThemes / scheduledThemeErrors / activeScheduledTheme`, payload `theme` remplacé + `theme_name`, validé à l'enregistrement ET à la publication, restauré au retour arrière ; `StorefrontThemePlanifieTest` 9 verts ; pas de visuel d'accueil : couleurs + bandeaux existants suffisent, règle Apple 2.5.2) Modèle : « thème saisonnier » (jeu de couleurs + bandeau + éventuel visuel d'accueil) avec `starts_at/ends_at`,
  priorité, aperçu avant publication ; le thème de base reprend seul à `ends_at`. • anchor : `StorefrontSetting.theme`,
  `StorefrontService::publish`, contraste `config/storefront.php` (`min_contrast` 4.5, `fixed_contrast`) • test :
  (TO BE CREATED `tests/Feature/Storefront/StorefrontThemePlanifieTest.php`) + `StorefrontThemeContrastTest.php` (existant).
- T-3.1.2 ✅ 2026-11-10 (`next_change_at` = début du prochain thème / fin du thème en cours, prouvé serveur ; site : banc `vitrine-robustesse-2026-11.spec.js` étendu — 15/15 : couleurs posées puis retirées à la relecture, `vitrine.js` inchangé) `next_change_at` inclut les bornes du thème ; le client relit à l'heure dite (déjà fait pour les bandeaux).
  • anchor : `StorefrontService.php:169-177`, `vitrine.js` minuterie • test : site `tests-e2e/vitrine-robustesse-2026-11.spec.js`
  (existant, étendre).
- T-3.1.3 ✅ 2026-11-10 (section « Thèmes programmés » de `StorefrontComponent.vue` : liste avec état En cours/Programmé/Terminé/Désactivé, éditeur nom/dates/priorité/couleurs + contraste, aperçu téléphone aux couleurs du thème en cours ; `storefrontThemePlanifie.spec.js` 3 verts ; banc réel `vitrine-theme-planifie.spec.js` : thème couvrant l'heure présente → « En cours » + aperçu, capture lue ; publier / revenir / historique existaient) Admin : écran Vitrine avec calendrier des thèmes/bandeaux, « publier », « revenir à la version N », historique
  15. • anchor : `StorefrontComponent.vue`, `StorefrontAdminController::publish/rollback` • tests :
  `StorefrontAdminTest.php` (15, existant) • visuel : `/admin/storefront`.
**Acceptance** : 47 + nouveaux tests verts, démonstration locale « thème fêtes de 00h00 à 23h59 » vue dans l'app
(émulateur) sans redémarrage, captures lues.

### Sub 3.2 — Catalogue distant (NON-RÉGRESSION : livré par `GOAL_APP_HYBRIDE_FINITION_2026-11-03.md` T-S4.x ; on rejoue, on ne reconstruit pas)
- T-3.2.1 ✅ 2026-11-10 (banc 55/55 ; `--prod` : 40 produits, 9 catégories, 13 hors carte à dessein, 0 ignoré, 0 erreur JS) Rejouer les 7 règles du module (`data/catalogue-distant.js` en-tête) contre le catalogue de PROD en lecture :
  nouveau produit, produit retiré, photo changée, prix changé, rupture, nouvelle catégorie, ordre. • test :
  `tests-e2e/catalogue-distant-2026-11-05.unit.mjs` (existant) + `tools/verify-catalogue-distant.mjs --prod`.
- T-3.2.2 ✅ 2026-11-10 (règle déjà écrite dans `app/PUBLICATION.md §11` : « à chaque nouvelle version, `catalogue-reference.mjs --base` (diff) puis `--ajouter` » ; preuve du jour : « Référence du 2026-11-05 · 70 produits · serveur : 70 — ✓ référence identique au serveur » et `check:www` « app/www est à jour (285 fichiers) ») Référence périmée : `catalogue-reference.js` date du 2026-11-05 ; définir QUAND la régénérer (à chaque build
  store) et le prouver par `npm run check:www`. • anchor : `tools/catalogue-reference.mjs`, `app/PUBLICATION.md`
  • livrable = preuve capturée : sortie de `node tools/catalogue-reference.mjs` + `check:www` dans le rapport, règle écrite
  dans `app/PUBLICATION.md §4`.
- T-3.2.3 ✅ acceptation atteinte 2026-11-10 (0 produit de prod ignoré : la ligne « NON AFFICHÉ » du diagnostic est vide) ; l'écran admin de compte-rendu reste NON construit (différé : rien à montrer aujourd'hui, l'outil CLI `--prod` dit tout) Produits dont les options ne sont pas déductibles → compte-rendu lisible dans l'admin (pas seulement dans un
  outil CLI). • anchor : `etat().ignores` • test : (TO BE CREATED site `tests-e2e/catalogue-distant-ignores-admin.spec.js`).
**Acceptance** : 0 produit de prod ignoré sans raison affichée ; banc vert ; capture app lue.

### Sub 3.3 — Codes promo et offres dans l'app
- T-3.3.1 ✅ rejoué 2026-11-10 (`CouponCheckNegativeTotalTest`, `CouponPasEncoreActifMessageTest`, banc site 15/15 dont « Utiliser le code » pré-rempli) Bandeau `coupon_code` → code pré-rempli dans le tunnel → validé par le serveur → même remise que la caisse.
  • anchor : `vitrine.js`, `api.js:1929`, `FrontendCouponController` • tests : `tests-e2e/vitrine-distante-2026-11.spec.js`
  + `tests/Feature/CouponCheckNegativeTotalTest.php`, `tests/Feature/Coupon/CouponPasEncoreActifMessageTest.php` (existants).
- T-3.3.2 ✅ 2026-11-10 (`CouponExpireALaMinuteTest` 2 verts : fin INCLUSE à la seconde des deux côtés — paiement `now > end`, vitrine `end < now` — code encore promis et accepté à 18:00:00, retiré et refusé 422 FR à 18:00:01, relecture programmée à la fin ; ⚠️ un coupon créé depuis l'admin a toujours une fin à 23:59:59 (`CouponService` endOfDay) : l'heure fine vient du bandeau, pas du coupon) Fin d'offre à l'heure : bandeau disparu, code refusé (422 FR lisible), aucun prix figé côté client. • test :
  (TO BE CREATED `tests/Feature/Coupon/CouponExpireALaMinuteTest.php`).
**Acceptance** : tests verts ; parcours réel émulateur avec code promo (banc `tools/verify-app-device.py`) capturé et lu.

### Sub 3.4 — Robustesse (hors ligne, cache, charge) — NON-RÉGRESSION (≈ T-S1.3.1 du GOAL hybride : rejouer, 0 nouvelle tâche sauf rouge)
- T-3.4.1 ✅ 2026-11-10 (mesuré sur :8766 : `/storefront` 304 ; `/app/config` répondait 200 sans ETag → ETag + 304 ajoutés dans `AppConfigController`, test dans `AppConfigMinVersionTest` ; `/item` : catalogue distant lu par `api.js` avec son propre cache, non modifié) ETag/304 sur `/storefront`, `/app/config`, `/item` ; plafonds par IP non bloquants pour une IP partagée
  (mémoire `site-option-caisse-seule-et-limites-par-ip`). • anchor : `routes/api.php:2305-2312` throttle 300/min •
  tests : `StorefrontApiTest.php` (existant) + `tests/Feature/Delivery/DeliveryPublicThrottleIsolationTest.php`.
- T-3.4.2 ✅ rejoué 2026-11-10 (banc site 15/15 : 429 + Retry-After respecté, dernière vitrine conservée, pas de rafale) ; P3 « la carte » toujours ouvert ; P3 neuf : hors ligne, un thème programmé reste affiché après sa fin jusqu'à la prochaine lecture réussie (le client ne connaît pas le thème de base) Hors ligne : dernière vitrine/catalogue valides affichés, bandeau « hors connexion » correct (P3 ouvert : parle
  de « la carte »). • test : site `tests-e2e/vitrine-robustesse-2026-11.spec.js` (existant).
**Acceptance** : tests verts ; réponse 304 mesurée ; capture hors ligne lue.

---

## §6 Système 4 — Application des stores : finition

### Contrat
L'application = le site `app/catalogue-distant-2026-11-05` empaqueté (Capacitor 8, `fr.lecayenne.app`). Finition « qualité
maximale » = parité stricte avec le site en ligne, zéro écran blanc, natif propre (clavier, barre d'état, retour de paiement,
notifications, lien profond), parcours complet prouvé sur émulateur, dossiers stores prêts. La compilation iOS et les envois
restent au propriétaire (§G4).

### Ancres
`app/capacitor.config.json`, `app/www` (285 fichiers, `check:www` à jour le 2026-11-08), `app/PUBLICATION.md §1bis-§6`,
`tools/verify-app-device.py`, `tools/verify-app-behaviour.mjs`, `app-native.js`, `onboarding.jsx`, `screens.jsx`.
Branche des stores : **UNIQUEMENT `app/catalogue-distant-2026-11-05`** (les autres branches affichent encore « € dépensés »,
interdit par le propriétaire).

### Sub 4.1 — Parité www ↔ site
- T-4.1.1 `npm run check:www` → `build:www` → `cap sync` → `git restore` des deux fichiers natifs ; preuve par empreintes
  (285 fichiers identiques). • test : `check:www` (script existant) + `tools/verify-app-behaviour.mjs`.
- T-4.1.2 Aucune chaîne « dépensé », aucune URL locale, API = production, jetons `?v=` cohérents (mémoire
  `site-cache-buster`). • test : (TO BE CREATED site `tests-e2e/app-www-parite-interdits.unit.mjs`).
**Acceptance** : empreintes égales, grep interdits vide, rapport dans `app/PUBLICATION.md`.

### Sub 4.2 — Qualité native
- T-4.2.1 Clavier (`resize: native`), barre d'état, encoches, retour arrière Android, écran blanc impossible (garde
  « onglet périmé » morte au build, mémoire `garde-par-nom-de-fichier-meurt-au-build`). • anchor : `capacitor.config.json`,
  `app-native.js` • test : `tools/verify-app-device.py` (émulateur Android, DNS explicites).
- T-4.2.2 Retour de paiement `fr.lecayenne.app://paiement/retour` et `online_payment_enabled` relu avant chaque paiement ;
  comptoir seul si désactivé. • anchor : `api.js:67-73`, `AppConfigController.php:20` • test : site
  `tests-e2e/abandon-paiement.spec.js` (existant).
- T-4.2.3 Pont natif réel (`registerPlugin` absent du site, mémoire `pont-capacitor-sans-registerplugin`) : notifications
  locales, Preferences, SocialLogin Apple — chacun prouvé sur émulateur. • test : `tools/verify-app-device.py` (portes
  natives existantes) + `tools/verify-app-behaviour.mjs` ; livrable = captures émulateur lues (T-4.2.2 ≈ S3 du GOAL
  hybride : non-régression).
**Acceptance** : 9/9 portes de `tools/verify-app-device.py` vertes, captures émulateur lues.

### Sub 4.3 — Parcours client complet (émulateur)
- T-4.3.1 Premier lancement → onboarding → compte (e-mail OTP) → commande retrait « payer sur place » → suivi → points
  crédités → code promo → livraison avec adresse → ticket côté caisse portant téléphone/adresse/règlement (lien avec §3).
  • bancs : `tests/Playwright/fidelite-2026-11-06/`, `tests/Playwright/site-livraison-paiement-2026-10-24/` (existants),
  backend privé (jamais la prod).
- T-4.3.2 Vitrine : changer les couleurs en admin local → l'app les prend au retour au premier plan ; bandeau programmé
  apparaît/disparaît à l'heure. • bancs : `tests-e2e/vitrine-distante-2026-11.spec.js` + capture émulateur.
**Acceptance** : parcours 100 % vert, 0 erreur console, captures lues, rapport `reports/test-e2e/livraison-tickets-admin-app-2026-11-10/`.

### Sub 4.4 — Dossiers stores (préparation, sans envoi) — NON-RÉGRESSION (≈ T-S5.x du GOAL hybride : relire, pas refaire)
- T-4.4.1 Textes, captures, visuels, déclarations (PUBLICATION.md §6) relus contre le produit réel (pas de promesse de
  livraison si la livraison est suspendue, pas de « € dépensés »). • livrable : diff de `app/PUBLICATION.md`.
- T-4.4.2 Android : APK de débogage compilé ici (JDK 21, SDK 36) et installé sur émulateur ; AAB signé = propriétaire
  (jamais avec la clé étiquettes `lecayenne-etiquettes.jks`). iOS : Xcode 26 = propriétaire. • test :
  `tools/verify-app-device.py` contre l'APK installé ; livrable = journal de build + empreinte de l'APK dans le rapport.
**Acceptance** : APK installé et parcours T-4.3.1 rejoué dessus ; liste G4 à jour.

---

## §A Armée d'agents

Rôles, outils et gabarits : Axe 4 du skill (`~/.claude/skills/superpower-gstack/agents/*.md`). Matrice de tir par type de
tâche : Axe 4 (frontend visuel = 9 rôles ; backend/livraison = Architect, Security, DBA, Implementer, RED ; vitrine/app =
+ QA Visual + RED Visual). Discipline : 5 spécialistes lecture seule en UN message ; un seul Implementer à la fois ; RED
après chaque commit ; rapports sur disque `reports/test-e2e/livraison-tickets-admin-app-2026-11-10/round-<N>/wave-<W>-<role>.json`
(schéma Axe 4, ≤ 1500 mots). Vérification obligatoire (CLAUDE.md §3ter) : tout P0/P1 cite `file:line` + reproduction, sinon rejeté.

---

## §X Vagues de convergence

| W | Portée | Parallélisme | Checkpoint (Axe 3, 6 points) | Reprise |
|---|---|---|---|---|
| W0 | Pré-vol : sauvegarde DB dev, branche `backup/pre-livraison-tickets-2026-11-10`, baselines (`phpunit` complet, `audit_logs` count + hash, `check:www`), reproduction T-1.1.1 rouge | lecture seule ∥ | baselines écrites dans BRAIN §2 | — |
| W1 | **Tickets (Sub 1.1 ✅, 1.2 ✅, 1.3 ✅ hors T-1.3.2 qui attend G2 — 2026-11-10)** — le défaut signalé | séquentiel 1.1 → 1.2 → 1.3 (`PosDeliveryBoard.vue` et `TICKET_CONTRACT.md` partagés) | 3 tests créés verts, Hardware 33 verts, Vitest reçu vert, captures lues, TICKET_CONTRACT.md à jour | manifeste `INTERRUPT_W1_*.md` |
| W2 | Accès livreur/admin (Sub 1.4 ✅ 2026-11-10 ; Sub 2.4 ✅ : 2.4.1 via tests existants + matrice livreur, 2.4.2 cliquet 52 → 46, 2.4.3 tests existants + captures) | séquentiel (seeders/permissions partagés) | matrices vertes, cliquet FormRequest resserré | idem |
| W3 | Admin stock + produits (Sub 2.1 ✅, 2.2 ✅ — 2026-11-10 ; l'alerte stock bas existait déjà, flag e-mail actif en prod) | séquentiel 2.1 → 2.2 (`Frontend/ItemController` + banc catalogue distant partagés) | Stock 26 + Item/Catalog verts, bancs catalogue distant verts | idem |
| W4 | Offres (Sub 2.3) — **bloquée par G1** | séquentiel | option codée, tests verts, capture admin + app | idem |
| W5 | Vitrine & catalogue distants (Sub 3.1 ✅ thème programmé, 3.2 ✅ rejoué, 3.3 ✅, 3.4 ✅ + ETag app/config — 2026-11-10 ; reste : démonstration émulateur = W6) | 3.1 ∥ 3.2 ; 3.3/3.4 après | Storefront 47+ verts, 304 mesuré, démonstration thème planifié | idem |
| W6 | App finition (Sub 4.1-4.4) | 4.1→4.2→4.3 ; 4.4 ∥ 4.3 | 9/9 portes émulateur, parcours complet vert, APK installé | idem |
| W7 | Convergence : PHPUnit complet, Vitest complet, Playwright livraison + fidélité + vitrine, diff gelé 0, CHAIN OK, 2 cycles RED identiques ; puis déploiement (G3) backend → site → `cap sync` | fan-out RED ∥ | CONVERGENCE_FINAL.md, tag `v1.0.x-livraison-tickets-admin-app` | — |

Règles : W4 ne bloque pas W5/W6 (domaines disjoints) ; W7 ne démarre qu'avec W1-W3, W5, W6 closes (W4 close OU
explicitement différée par le propriétaire). Protocole d'échec après 3 cycles : Axe 3 (`STUCK_<W>_*.md`, 4 options, pas de
choix automatique). Interruption : commit `wip(W<n>): …` par pathspec + manifeste + BRAIN §2.

---

## §G Portes propriétaire

| Porte | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G1 | « Offres » : A = coupons planifiés + bandeaux (sans zone gelée) ; B = remises par produit câblées dans `PricingService` (LOCK, levée de la sentinelle V1) | Propriétaire | réponse A ou B | BRAIN §6 DECISIONS LOG + ce fichier | PENDING — bloque W4 seulement |
| G2 | Règle d'impression automatique du ticket client des livraisons (à PRÊTE / au départ / au clic) et activation `DELIVERY_HANDOVER_QR_PRINT=true` en prod après test papier iPhone + Android (docs/LIVRAISON.md §8) | Propriétaire (imprimante réelle) | décision + photo du ticket scanné | `docs/LIVRAISON.md §8`, BRAIN §2 | PENDING — bloque T-1.3.2 et le QR en prod, pas W1 |
| G3 | Déploiements (backend `tools/deploy-lecayenne.sh <sha>`, site `vercel --prod --yes --archive=tgz`) : le classificateur les refuse à l'agent → `! bash tools/deploy-lecayenne.sh <sha>` par le propriétaire, un seul déployeur, annoncé | Propriétaire | journal de déploiement, HEAD prod vérifié en lecture | BRAIN §2, CONVERGENCE_FINAL.md | PENDING — bloque W7 fin |
| G4 | Comptes Apple Developer / Google Play, Xcode 26 sur un Mac, keystore Android (jamais `lecayenne-etiquettes.jks`), identifiants §5 PUBLICATION.md | Propriétaire | identifiants collés aux emplacements §5 | `app/PUBLICATION.md §3, §5` | PENDING — bloque T-4.4.2 (envoi), pas la finition |
| G5 | Test papier : ticket livraison imprimé sur la SAGA réelle (adresse longue, QR, titre-restaurant) | Propriétaire (machine du restaurant) | photo lisible du ticket | `reports/test-e2e/livraison-tickets-admin-app-2026-11-10/` | PENDING — valide W1 en réel |
| G6 | Toute touche de zone gelée (option B de G1 ; `PaymentComponent.vue` si l'impression du reçu caisse doit changer) | Propriétaire | LOCK contresigné (skill `lock-plan`) | `plans/LOCK_*.md §10` + commit du LOCK d'abord | PENDING — aucun besoin tant que A |
| G7 | Données personnelles sur papier : téléphone complet + adresse imprimés sur le ticket client qui reste dans le sac / chez le client | Propriétaire | confirmation (la demande du 2026-11-10 le réclame explicitement ; à défaut, masquer le téléphone sur la copie client et le garder entier sur la copie livreur) | BRAIN §6 | DÉCIDÉ PAR LA DEMANDE — à confirmer |
| G8 | Contenu du ticket CUISINE : garder « Livrer : adresse » en en-tête (une ligne tronquée) ou le réserver au ticket client (mémoire `note-client-en-cuisine-format` : une info par ligne, bruit minimal) | Propriétaire | choix | `docs/TICKET_CONTRACT.md §3` | PENDING — codé « une ligne tronquée » en attendant |

Pendant qu'une porte est ouverte, l'orchestrateur exécute toutes les vagues qui n'en dépendent pas (W1-W3, W5, W6).

---

## §R Références
- Skills : `ultra-audit-profond`, `superpower-gstack`, `test-e2e`, `lock-plan`, `superpowers:dispatching-parallel-agents`.
- Docs : `docs/TICKET_CONTRACT.md`, `docs/LIVRAISON.md` (§3, §3sexies, §8, §9), `docs/AUTHZ_MATRIX.md`, `docs/BUSINESS_RULES.md`,
  `config/storefront.php`, `app/PUBLICATION.md`, `reports/test-e2e/app-hybride-2026-11/W2/W2a-vitrine-serveur.md` (contrat vitrine).
- Plans précédents à ne pas refaire : `plans/GOAL_APP_HYBRIDE_FINITION_2026-11-03.md`, `GOAL_STORES_FINITION_APP_2026-09-30.md`,
  `GOAL_APP_STORES_PRETE_A_SOUMETTRE_2026-10-30.md`.
- Rapports : `reports/test-e2e/audit-abusif-2026-11-06/CONVERGENCE_FINAL.md` (P3 ticket livraison), `reports/test-e2e/livraison-geo-2026-10-21/`.
- Mémoires : `note-client-en-cuisine-format`, `sauces-sans-nom-espace-pas-virgule`, `valider-contre-le-catalogue-de-production`,
  `formulaire-admin-echec-muet`, `cap-sync-reecrit-chemins-node-modules-lie`, `deux-deployeurs-simultanes`, `app-stores-est-le-site-capacitor`.

## §F Règle finale
DONE = une commande passée sur le site ou l'application, retrait ou livraison, quel que soit le règlement choisi, sort en
cuisine et à la caisse avec nom, téléphone, adresse et règlement exacts, sur papier et à l'écran, prouvé par un test rouge
puis vert ET par une photo du ticket réel ; le livreur n'accède qu'à sa tournée ; le propriétaire change produits, photos,
prix, stock, offres, couleurs et accès depuis l'admin et le voit dans l'application installée sans republication ; l'app
est prête à être signée par le propriétaire. Pas de « presque » : ce qui n'est pas prouvé est ouvert, et écrit comme tel.
