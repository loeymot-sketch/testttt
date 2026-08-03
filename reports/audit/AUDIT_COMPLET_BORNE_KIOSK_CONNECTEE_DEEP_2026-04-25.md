# Audit complet borne kiosk connectée au système global - deep scan

Date: 2026-04-25  
Périmètre: module borne kiosk comme composant connecté au système global FoodKing, non comme système central.  
Sortie demandée: un seul fichier.  
Nature: audit statique approfondi du dépôt, incluant runtime, connexions indirectes, fichiers cachés par usage, docs, tests, prototypes, scripts et rapports historiques.  
Référence externe UX consultée: https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md

## 0. Résumé exécutif

La borne FoodKing n'est pas le centre métier. Elle est une interface client autonome qui collecte une intention de commande, déclenche un paiement, pousse de l'upsell et affiche l'état de commande. La source de vérité reste le POS/backend:

- prix et remises: backend via `PricingService`, jamais le frontend;
- statut de commande: enum `OrderStatus`, jamais des chaînes ou nombres magiques côté UI;
- branche: backend via `KioskMachine.branch_id`, jamais confiance dans une branche choisie par le client;
- dispatch POS/KDS: après commit DB;
- disponibilité produit: backend et événements branch-scopés.

Verdict global: la base d'architecture est bonne côté backend, mais la borne active mélange encore plusieurs générations de logique:

- nouveaux endpoints kiosk SSOT: `/frontend/menu`, `/frontend/pricing/preview`, `/frontend/promo/validate`, `/frontend/upsell`;
- anciens endpoints publics encore consommés par le store kiosk: `frontend/item-category`, `frontend/item`, `frontend/item/kiosk-upsell`;
- logique locale de prix encore présente dans `resources/js/helpers/kioskPricing.js`;
- offline queue robuste en apparence, mais un bug d'identifiant casse la détection offline réelle après soumission;
- analytics funnel partiellement aveugle;
- docs kiosk obsolètes pouvant induire de mauvaises décisions d'exploitation.

Les risques les plus critiques sont:

1. Catalogue et upsell peuvent ne pas respecter strictement la branche réelle de la borne si la SPA utilise les anciens endpoints.
2. La borne contient encore une logique locale de prix/add-ons qui peut diverger du backend.
3. Une commande offline peut être affichée comme commande online à cause d'un `orderId` UUID non préfixé `offline_`.
4. Plusieurs chemins UI annulent une commande avec le nombre magique `16` au lieu de `OrderStatus.CANCELED`.
5. Le funnel analytics ne capture pas correctement plusieurs événements v2 offline/conflict et peut perdre des événements via `sendBeacon`.
6. Les documents `docs/API_KIOSK.md` et une partie de `docs/KIOSK_DEPLOYMENT.md` décrivent un ancien modèle kiosk, pas l'état runtime actuel.

## 1. Carte des fichiers et zones couverts

### Runtime principal

- `resources/js/components/kiosk/*`
- `resources/js/stores/kioskCart.js`
- `resources/js/stores/kioskMenu.js`
- `resources/js/services/kioskOfflineQueue.js`
- `resources/js/services/kioskAnalytics.js`
- `resources/js/helpers/kioskPricing.js`
- `app/Http/Controllers/Api/Frontend/*`
- `app/Services/FrontendOrderService.php`
- `app/Services/Pricing/*`
- `app/Services/KioskMenuService.php`
- `app/Services/UpsellRuleService.php`
- `routes/api.php`
- `routes/channels.php`
- `config/kiosk.php`
- `resources/views/vendor/installer/layouts/master.blade.php`

### Connexions et effets indirects

- `app/Events/*`
- `app/Listeners/*Outbox*`
- `app/Listeners/Availability/*`
- `app/Jobs/CleanupStalePendingKioskOrders.php`
- `app/Console/Kernel.php`
- `app/Console/Commands/EnsureKioskMachineCommand.php`
- `app/Console/Commands/SimulateKioskOrders.php`
- `app/Models/KioskMachine.php`
- `app/Models/ItemBranchAvailability.php`
- `app/Models/UpsellRule.php`
- `app/Enums/OrderStatus.php`

### Tests, docs et archives

- `tests/Feature/KioskEndpointsTest.php`
- `tests/Feature/KioskPaymentStateMachineTest.php`
- `tests/js/kioskCartPromo.spec.js`
- `tests/js/kioskOfflineQueue.spec.js`
- `tests/e2e/03-kiosk-wizard.spec.js`
- `docs/API_KIOSK.md`
- `docs/KIOSK_DEPLOYMENT.md`
- `docs/known-issues/KI_002_BUNDLE_BLOAT_2026-04-20.md`
- `reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md`
- `borne (Remix)/`
- `kiosk_implementation/`

## 2. Position de la borne dans le système

### Rôle exact

La borne est une couche d'interaction client:

- entrée autonome de panier;
- navigation menu;
- proposition d'upsell;
- saisie loyalty/promo;
- choix de paiement;
- confirmation et suivi visuel;
- publication d'événements UX/analytics.

Elle ne doit pas décider:

- le prix final;
- la disponibilité réelle d'un produit;
- le statut métier durable;
- l'association branche-machine;
- la validité fiscale ou paiement;
- l'ordre de dispatch POS/KDS.

### Dépendance au POS/backend

La dépendance est forte et volontaire:

- login machine: `KioskMachineLoginController` crée un token Sanctum avec ability `kiosk:order`;
- branch isolation: `FrontendOrderService` résout `branch_id` depuis `KioskMachine`;
- pricing: `FrontendOrderService` nettoie les totaux client et recalcul via backend;
- menu: le bon endpoint `/frontend/menu` sait résoudre la branche via la machine;
- KDS/POS: consomment les événements de commande et disponibilité;
- broadcast: `routes/channels.php` autorise le canal branché selon la machine.

### Niveau d'autonomie réel

Autonomie forte côté UX, autonomie faible côté métier:

- peut collecter et mettre en cache une intention;
- peut afficher un total indicatif;
- peut faire une file offline locale;
- ne peut pas valider définitivement le prix ou le statut;
- ne peut pas garantir l'envoi cuisine sans backend;
- ne peut pas finaliser les paiements carte/TR sans confirmation backend.

Conclusion: l'architecture cible est saine, mais le frontend conserve trop de traces d'autonomie métier ancienne.

## 3. Flux système cible

### Flux nominal online

1. Borne charge config et s'authentifie comme machine.
2. Backend renvoie token `kiosk:order` et branche de la machine.
3. Borne charge le menu branché depuis `/frontend/menu`.
4. Client construit panier.
5. Borne demande preview backend via `/frontend/pricing/preview`.
6. Client valide paiement.
7. Borne crée commande via `frontend/order`.
8. Backend résout branche, recalcule prix, crée commande.
9. Selon paiement:
   - cash: commande acceptée/payée immédiatement dans l'état actuel;
   - card/TR: commande pending/unpaid jusqu'à confirmation paiement.
10. Après commit DB, événements vers POS/KDS/outbox.
11. Borne suit l'état via polling et/ou websocket.

### Flux offline actuel

1. Borne détecte erreur réseau.
2. `kioskOfflineQueue` sauvegarde un payload local.
3. UI affiche un état offline.
4. Reconnexion: la queue rejoue les payloads.
5. Backend recalcule et accepte ou rejette.

Problème critique: la soumission offline utilise parfois un UUID idempotency comme identifiant local. Les composants `KioskWaitingComponent` et `KioskPaymentComponent` attendent un préfixe `offline_`; donc une commande offline réelle peut être traitée visuellement comme online.

## 4. Points critiques classés

| ID | Gravité | Zone | Résumé | Impact conversion/revenu |
| --- | --- | --- | --- | --- |
| KIOSK-DEEP-001 | P0 | Menu/branche | La SPA charge encore le catalogue par anciens endpoints publics et choisit la première branche frontend | Produits indisponibles visibles, rupture cuisine, commandes rejetées |
| KIOSK-DEEP-002 | P0 | Prix | Logique locale d'addons/prix encore active côté borne | Écart affiché vs facturé, perte confiance, sous/sur-facturation perçue |
| KIOSK-DEEP-003 | P0 | Offline | Identifiant offline non stable: UUID non reconnu comme offline par waiting/payment | Commandes bloquées, paiement mal enchaîné, abandon |
| KIOSK-DEEP-004 | P0 | Statut | Annulation avec nombre magique `16` côté UI | Violation invariant OrderStatus, risque régression enum |
| KIOSK-DEEP-005 | P1 | Upsell | Upsell frontend utilise ancien endpoint non branch-scopé strict | Upsell non disponible proposé, conversion négative |
| KIOSK-DEEP-006 | P1 | Promo | UI lit `discount` alors que backend renvoie `discount_amount` | Promo affichée à 0, perte conversion |
| KIOSK-DEEP-007 | P1 | Analytics | Events v2 offline/conflict non allowlistés, `sendBeacon` probablement non authentifié | Funnel aveugle, revenue perdu invisible |
| KIOSK-DEEP-008 | P1 | UX | Cartes produits avec interactions imbriquées et fallback ajout simple | Friction, erreurs d'options, accessibilité faible |
| KIOSK-DEEP-009 | P1 | Paiement cash | Cash kiosk marqué payé/accepté immédiatement | Risque fiscal/opérationnel si cash non collecté |
| KIOSK-DEEP-010 | P1 | Docs | `docs/API_KIOSK.md` décrit un ancien modèle Flutter/API key/client totals | Mauvaise implémentation future, dette produit |
| KIOSK-DEEP-011 | P2 | Ops | Commandes/seeds avec credentials et branche par défaut | Mauvaise installation borne |
| KIOSK-DEEP-012 | P2 | Tests | Tests clés ne capturent pas les bugs réels frontend | Faux sentiment de sécurité |

## 5. Frontend UX

### Parcours client

Parcours observé:

- idle screen;
- menu catégories;
- détail produit ou ajout rapide;
- wizard menus/options;
- cart;
- loyalty/promo;
- upsell;
- paiement;
- waiting;
- confirmation.

Forces:

- écran idle distinct;
- prévention double `touchstart/click` dans `KioskIdleScreenComponent`;
- wizard riche pour menus;
- écran waiting avec polling;
- composant confirmation avec snapshot reçu/print/speech;
- clavier virtuel loyalty branché dans `KioskLoyaltyComponent`;
- haptics partiels sur succès.

Faiblesses:

- logique de fallback ajoute un item simple si le détail produit échoue;
- flux upsell auto-skip après 30 secondes;
- upsell montré une seule fois par panier;
- certaines erreurs réseau reviennent vers des états visuels ambigus;
- pas de garantie que tous les messages d'erreur soient compréhensibles pour un client non technique;
- interaction produit structurée avec `div role=button` et bouton imbriqué, ce qui est fragile accessibilité/tactile.

### Vitesse perçue

Points positifs:

- composants chunkés/lazy dans plusieurs zones;
- snapshots et cache menu existent;
- queue offline IndexedDB/localStorage.

Risques:

- `docs/known-issues/KI_002_BUNDLE_BLOAT_2026-04-20.md` indique un risque bundle et boot 6-10s sur kiosk bas de gamme;
- la borne charge des couches globales FoodKing non forcément nécessaires au kiosk;
- si le menu ancien endpoint charge trop large, la borne paie un coût réseau inutile;
- absence de service worker kiosk dédié: offline ne veut pas dire app shell disponible hors réseau après reload.

### Friction

Friction la plus coûteuse:

- différence possible entre total indicatif et total backend;
- promotion appliquée côté backend mais affichée à zéro côté cart;
- offline flow qui ressemble online puis échoue en polling;
- upsell non branché sur disponibilité réelle;
- paiement card/TR possible sur commande offline mal identifiée.

### Design émotionnel

Le design cherche un modèle borne restaurant classique: grandes cartes, upsell, waiting/confirmation, sons/speech/haptics. Le risque n'est pas l'absence d'émotion, mais l'émotion mal synchronisée:

- un haptic/success avant confirmation serveur durable peut créer une fausse certitude;
- un upsell indisponible crée une frustration supérieure au gain potentiel;
- un total qui change au paiement détruit la confiance;
- l'auto-skip upsell peut aider la vitesse mais réduit le panier moyen si trop court ou peu personnalisé.

## 6. Logique produit

### Upsell dynamique

État réel:

- frontend actif `kioskCart.fetchUpsellItems` appelle encore `frontend/item/kiosk-upsell`;
- backend legacy `ItemController::kioskUpsell` sélectionne des items actifs et `is_upsell`, avec peu de contexte branche/canal;
- nouveau backend `/frontend/upsell` existe via `UpsellController` + `UpsellRuleService`;
- `UpsellRuleService` utilise des règles par branche, mais doit encore vérifier strictement disponibilité et canal dans la requête finale.

Conséquence:

- l'architecture cible existe;
- la SPA n'en bénéficie pas complètement;
- l'upsell réel est probablement inférieur au potentiel parce qu'il est trop générique et parfois risqué.

### Recommandations

Critères manquants ou incomplets:

- historique panier courant;
- marge produit;
- stock/disponibilité branchée;
- heure/jour;
- type paiement;
- étape de friction;
- panier minimum promo;
- contraintes cuisine/KDS.

Une recommandation kiosk efficace doit être agressive mais naturelle:

- proposer au bon moment;
- rester en contexte du panier;
- ne jamais proposer indisponible;
- limiter les écrans bloquants;
- mesurer accept/refuse/timeout.

### Erreurs UX métier

Erreurs les plus probables:

- produit affiché disponible alors qu'il ne l'est pas pour cette branche;
- ajout simple d'un produit qui nécessitait des options;
- promo visuellement non déduite;
- total local différent du total backend;
- waiting d'une commande offline pollée comme online;
- annulation avec statut numérique codé en dur.

## 7. Performance et offline mode

### Temps de réponse

Le dépôt contient les briques pour une bonne vitesse:

- preview pricing;
- menu service optimisé;
- cache snapshots;
- polling fallback;
- cleanup job pour pending stale.

Mais le chemin actif n'est pas entièrement aligné:

- menu legacy en plusieurs appels plutôt qu'un endpoint menu branché consolidé;
- prix local recalculé en parallèle du backend;
- analytics beacon sans garantie;
- tests E2E trop peu profonds pour mesurer latence réelle.

### Offline mode

Offline actuel = file de commandes locale, pas autonomie complète:

- pas de service worker kiosk métier identifié;
- pas de garantie reload offline;
- la commande offline est une intention à rejouer, pas une commande backend confirmée;
- le backend reste arbitre au replay.

Bug majeur:

- `kioskCart.submitOrder` génère `idempotencyKey`;
- `saveOrder(payload, originalKey)` utilise `originalKey` comme clé locale;
- si `originalKey` est un UUID, l'id local ne commence pas par `offline_`;
- `KioskWaitingComponent` et `KioskPaymentComponent` détectent offline avec `startsWith('offline_')`;
- résultat: la commande offline peut partir dans le mauvais flow visuel/paiement.

## 8. Connexions système

### POS/caisse

Flux:

- commande créée par backend;
- statut et paiement gérés côté backend;
- événements post-commit;
- POS consomme les changements de commande;
- backend reste source de vérité.

Risques de désync:

- status hardcodé côté UI;
- cash marqué payé avant collecte réelle;
- commande offline rejouée plus tard avec menu/prix changé;
- frontend affichant un total non final.

Fallback:

- polling order;
- cleanup stale pending;
- queue offline;
- outbox/events.

Risque business:

- si la caisse voit une commande payée cash non collectée, le revenu comptable peut être faux;
- si la borne affiche une commande abandonnée comme en cours, client et staff perdent du temps.

### Backend API

Endpoints kiosk importants:

- login machine;
- `/frontend/menu`;
- `/frontend/pricing/preview`;
- `/frontend/promo/validate`;
- `/frontend/upsell`;
- `frontend/order`;
- `frontend/order/{id}`;
- `frontend/order/{id}/payment-confirm`;
- analytics kiosk.

Risques:

- coexistence endpoints anciens/nouveaux;
- request classes qui acceptent plus que nécessaire;
- docs obsolètes décrivant un autre contrat;
- certains endpoints loyalty plus publics que le reste.

Fallback:

- continuer à accepter les anciens endpoints peut protéger la prod;
- mais la SPA doit migrer vers les endpoints SSOT pour réduire le risque.

### Paiement

Cash:

- backend encode paiement immédiat et statut accepté;
- utile pour rapidité;
- risqué si la collecte cash n'est pas physiquement confirmée par staff/POS.

Card/TR:

- backend crée pending/unpaid;
- dispatch `OrderCreated` différé jusqu'à `payment-confirm`;
- meilleur modèle pour éviter cuisine avant paiement.

Risques:

- commande offline mal détectée peut atteindre un flow TPE incorrect;
- navigateur TPE stub/fallback doit être clairement borné;
- annulation paiement utilise un nombre magique.

Fallback:

- cleanup stale pending toutes les 5 minutes;
- confirmation backend;
- statut canceled sur refus/timeout, mais doit passer par enum.

### KDS

Flux:

- KDS reçoit commandes et statuts via événements;
- disponibilité décrémentée/libérée via listeners;
- événements branch-scopés.

Risques:

- si commande cash est dispatchée avant collecte réelle, cuisine peut préparer trop tôt;
- si menu borne n'est pas branché, KDS peut recevoir des items non disponibles;
- si disponibilité change pendant offline, replay peut échouer ou créer conflit.

Fallback:

- disponibilité backend;
- listeners after commit;
- polling KDS/POS possible.

### Websocket/broadcast

Forces:

- `routes/channels.php` restreint le canal kiosk à la branche de la machine;
- bonne direction pour isolation `branch_id`.

Risques:

- fallback polling doit rester fiable;
- events frontend v2 conflict/offline existent mais analytics ne les capture pas tous;
- si websocket absent, l'UX doit expliquer les délais.

## 9. Backend SSOT et invariants

### Prix

Forces:

- `FrontendOrderService` nettoie les totaux client;
- backend utilise `PricingService`;
- `PricingPreviewService` existe;
- tests backend couvrent plusieurs endpoints pricing/promo/menu.

Faiblesses:

- `kioskPricing.js` conserve des constantes et fallback de prix;
- wizard construit encore des extras locaux;
- commentaire frontend indique fallback local si preview serveur échoue;
- les tests JS ne bloquent pas l'ajout de nouvelle logique prix locale.

Conclusion:

- le backend respecte globalement l'invariant;
- le frontend n'est pas encore pur client d'affichage.

### OrderStatus

Forces:

- enum `OrderStatus` existe;
- plusieurs services backend l'utilisent correctement.

Violation:

- `KioskPaymentComponent` et `KioskWaitingComponent` utilisent `16` pour cancel;
- le frontend doit importer/consommer `orderStatusEnum.CANCELED` ou une constante partagée.

### branch_id

Forces:

- backend résout la branche depuis la machine;
- broadcast channel vérifie la branche;
- request order kiosk laisse `branch_id` nullable et documente résolution backend.

Violation indirecte:

- `KioskAppComponent` charge une liste de branches frontend et prend la première;
- `kioskMenu.js` passe ce `branch_id` à des anciens endpoints qui ne filtrent pas strictement;
- l'UX peut donc se construire sur une branche approximative même si la création de commande est corrigée ensuite.

### Dispatch après commit

Forces:

- listeners outbox utilisent une logique after-commit;
- architecture événementielle cohérente.

Risque:

- scripts de simulation et chemins dev peuvent bypasser cette discipline.

## 10. Analyse détaillée par zone

### 10.1 Auth machine

Fichier clé: `app/Http/Controllers/Api/Auth/KioskMachineLoginController.php`

Constats:

- authentification par `machine_id`, `username`, `password`;
- token Sanctum avec ability `kiosk:order`;
- token expiré/configurable;
- réponse renvoie machine et branch.

Risques:

- injection des credentials auto-login dans `window.foodkingConfig` si configurés;
- defaults locaux `kiosk-lecayenne/kiosk123` faciles à propager par erreur;
- documentation ancienne parle d'API key et token non expirant.

Action:

- rendre impossible en production l'exposition de credentials auto-login sauf flag explicite et journalisé;
- mettre à jour docs et checklist de provisioning.

### 10.2 Menu branché

Fichiers clés:

- `resources/js/stores/kioskMenu.js`
- `resources/js/components/kiosk/KioskAppComponent.vue`
- `app/Http/Controllers/Api/Frontend/MenuController.php`
- `app/Services/KioskMenuService.php`
- `app/Services/ItemService.php`
- `app/Services/ItemCategoryService.php`

Constats:

- le bon chemin `/frontend/menu` existe;
- `KioskMenuService` applique disponibilité branche et projette `is_available`;
- la SPA continue de charger catégories/items via anciens endpoints;
- `KioskAppComponent` choisit la première branche retournée par l'API frontend;
- les anciens services ne garantissent pas le filtrage branché attendu.

Impact:

- la borne peut afficher un catalogue incorrect;
- un item peut être commandable visuellement puis rejeté ou non préparé;
- l'upsell peut proposer des produits invisibles côté KDS/branche.

Action prioritaire:

- basculer le store kiosk vers `/frontend/menu` comme source unique;
- utiliser la branche renvoyée au login machine;
- supprimer la dépendance UX au premier `branch_id` frontend.

### 10.3 Pricing et preview

Fichiers clés:

- `resources/js/helpers/kioskPricing.js`
- `resources/js/helpers/kioskPricingPreview.js`
- `resources/js/components/kiosk/KioskWizardComponent.vue`
- `app/Services/Pricing/PricingPreviewService.php`
- `app/Services/FrontendOrderService.php`

Constats:

- preview serveur existe et est appelée;
- payload preview whitelist les champs non prix;
- backend ignore les totaux client;
- frontend garde des fallback prix locaux: menu, sauce, addon, running total.

Risque:

- si preview échoue, l'UI peut continuer avec un total non fiable;
- si addon local n'est pas représenté comme variation/extra backend, il peut ne pas être facturé pareil;
- le client voit une promesse de prix que la caisse ne partage pas.

Action prioritaire:

- interdire le fallback local de prix final;
- afficher "prix à confirmer" ou bloquer l'étape de paiement si preview backend indisponible;
- remplacer helpers prix par formatage/affichage uniquement.

### 10.4 Création commande

Fichiers clés:

- `resources/js/stores/kioskCart.js`
- `app/Http/Requests/OrderRequest.php`
- `app/Services/FrontendOrderService.php`

Constats:

- order request kiosk permet `branch_id` nullable;
- service résout branche via machine;
- service retire `subtotal`, `discount`, `total`;
- service applique pricing backend;
- idempotency key existe côté frontend.

Risques:

- payload cart peut contenir des choix que le backend ne mappe pas exactement;
- offline replay peut rejouer après changement de disponibilité/prix;
- tests ne prouvent pas chaque variante wizard.

Action:

- ajouter tests contrat payload wizard -> pricing preview -> order create;
- logguer mismatch preview/order pour détecter divergence.

### 10.5 Paiement

Fichiers clés:

- `resources/js/components/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/kiosk/KioskWaitingComponent.vue`
- `app/Http/Controllers/Api/Frontend/OrderController.php`
- `tests/Feature/KioskPaymentStateMachineTest.php`

Constats:

- card/TR: pending/unpaid jusqu'à confirmation, dispatch différé;
- cash: paid/accepted immédiat, testé comme comportement attendu;
- cleanup stale pending planifié;
- UI annule avec status `16`.

Risques:

- cash avant collecte réelle peut créer cuisine/revenu trop tôt;
- statut numérique casse la maintenabilité;
- offline mal identifié peut tenter un flow TPE.

Action:

- remplacer `16` par enum partagé;
- décider produit/fiscalement si cash kiosk doit être "pay at counter" ou "paid";
- bloquer card/TR en offline sauf mode explicitement certifié.

### 10.6 Promo

Fichiers clés:

- `app/Services/KioskPromoService.php`
- `resources/js/stores/kioskCart.js`
- `tests/js/kioskCartPromo.spec.js`

Constats:

- backend renvoie `discount_amount`;
- frontend lit `data.discount || 0`;
- le test JS success ne valide pas réellement la forme de réponse.

Impact:

- promo valide mais affichée sans réduction;
- abandon ou demande staff;
- baisse conversion.

Action:

- lire `discount_amount`;
- ajouter test qui mocke la réponse backend réelle;
- vérifier affichage cart et order final.

### 10.7 Upsell

Fichiers clés:

- `resources/js/stores/kioskCart.js`
- `app/Http/Controllers/Api/Frontend/UpsellController.php`
- `app/Services/UpsellRuleService.php`
- `app/Http/Controllers/Api/Frontend/ItemController.php`

Constats:

- ancien endpoint encore consommé;
- nouveau endpoint règles existe;
- branch_id présent dans règles;
- item query doit être resserrée sur disponibilité/canal.

Impact:

- upsell moins personnalisé;
- risque proposition indisponible;
- panier moyen inférieur au potentiel.

Action:

- brancher frontend sur `/frontend/upsell`;
- enrichir service avec disponibilité branche/canal;
- instrumenter accept/refuse/timeout par suggestion.

### 10.8 Loyalty

Fichiers clés:

- `resources/js/components/kiosk/KioskLoyaltyComponent.vue`
- `app/Http/Requests/Frontend/LoyaltyOptInRequest.php`
- routes loyalty dans `routes/api.php`

Constats:

- composant loyalty a clavier virtuel;
- consentement présent;
- certains endpoints sont plus publics que le reste.

Risques:

- public opt-in peut être voulu, mais doit être explicitement documenté;
- scanner/lookup doivent être ability-scopés si associés à une machine;
- consent analytics et consent loyalty ne doivent pas être confondus.

Action:

- documenter le modèle de consentement;
- vérifier auth/ability de chaque endpoint loyalty kiosk.

### 10.9 Analytics

Fichiers clés:

- `resources/js/services/kioskAnalytics.js`
- `resources/js/plugins/kioskAnalyticsPlugin.js`
- `app/Http/Controllers/Api/Frontend/KioskAnalyticsController.php`

Constats:

- allowlist d'events;
- events offline v2/conflict émis ailleurs mais non allowlistés;
- `sendBeacon` est tenté avant axios;
- endpoint backend demande auth;
- beacon ne porte pas forcément Authorization;
- `track` no-op sans consent.

Impact:

- funnel incomplet;
- pertes revenue non mesurées;
- offline/conflict invisibles;
- A/B upsell impossible à fiabiliser.

Action:

- séparer analytics opérationnel strictement nécessaire et analytics marketing consentis;
- utiliser axios authentifié pour events nécessitant auth ou prévoir endpoint beacon signé;
- ajouter events offline v2/conflict dans allowlist;
- mesurer étapes: menu view, item detail, add success/fail, preview fail, promo valid/fail, upsell view/accept/skip/timeout, payment start/success/fail, offline queued/replayed/conflict.

### 10.10 Websocket, availability et KDS

Fichiers clés:

- `routes/channels.php`
- `app/Services/AvailabilityService.php`
- `app/Listeners/Availability/*`
- `app/Events/*Availability*`
- `resources/js/components/kiosk/KioskAppComponent.vue`

Constats:

- channels branchés correctement;
- availability service gère décrément/libération;
- frontend écoute des changements et met à jour items;
- `UPDATE_ITEM` dans `kioskMenu.js` prend maintenant `is_available` en compte.

Risques:

- si menu initial vient de l'ancien endpoint, l'event corrige seulement après coup;
- conflit offline si disponibilité change entre ajout et replay;
- analytics conflict non capté.

Action:

- menu initial par `/frontend/menu`;
- modal conflict obligatoire au replay;
- événement analytics conflict autorisé.

## 11. Coins cachés et indirects

### 11.1 Docs obsolètes

`docs/API_KIOSK.md` décrit un modèle ancien:

- Flutter app;
- `X-API-KEY`;
- token sans expiration;
- request avec `branch_id`;
- `order_type: 5`;
- `subtotal`/`total` côté client.

Ce document contredit l'architecture actuelle:

- SPA Vue/Laravel;
- Sanctum token `kiosk:order`;
- token expirant;
- branch backend depuis machine;
- prix backend SSOT.

Risque: une future implémentation ou intégration externe peut reconstruire l'ancien modèle et réintroduire des failles.

Action: remplacer par un contrat API kiosk actuel, ou marquer explicitement `ARCHIVE/OBSOLETE`.

### 11.2 Déploiement kiosk

`docs/KIOSK_DEPLOYMENT.md` contient encore des références incertaines:

- contrôleur d'auth possiblement ancien;
- mention d'event/canal à venir;
- default password `kiosk123` présent dans le flux d'installation.

Le document a une bonne intention mais doit être réconcilié avec:

- `KioskMachineLoginController`;
- `config/kiosk.php`;
- `EnsureKioskMachineCommand`;
- routes actuelles.

### 11.3 Config auto-login

`config/kiosk.php` et `master.blade.php` exposent `window.foodkingConfig.kioskAutoLogin` pour `/kiosk*` si configuré.

Risque:

- utile en kiosk verrouillé;
- dangereux si activé en production web classique;
- credentials visibles côté navigateur;
- dépend fortement de TLS, lockdown device, rotation et environnement.

Action:

- ajouter garde production explicite;
- logguer activation;
- exiger credentials forts;
- refuser defaults en prod.

### 11.4 Commande provisioning

`EnsureKioskMachineCommand`:

- défaut username `kiosk-lecayenne`;
- défaut password `kiosk123`;
- défaut branch = première branche;
- confirmation production sauf `--force`.

Risque:

- installation rapide peut associer mauvaise branche;
- password faible si humain oublie override;
- première branche dangereuse dans un SaaS multi-branch.

Action:

- rendre `--branch-id` obligatoire hors local;
- rendre `--password` fort/obligatoire hors local;
- afficher la branch cible avant confirmation.

### 11.5 Seeder

`KioskMachineTableSeeder`:

- bloque production;
- seed credentials par défaut non-prod.

Risque faible en prod, mais:

- données de démo peuvent apparaître dans staging public;
- habitudes de test peuvent contaminer docs.

### 11.6 Simulation orders

`SimulateKioskOrders`:

- crée `Order` directement;
- force `branch_id = 1`;
- force status/payment numériques;
- dispatch events directs;
- bypass `FrontendOrderService`, pricing, outbox et branch isolation.

Risque:

- si utilisé comme preuve de test kiosk, il valide un faux système;
- peut masquer violations FoodKing;
- peut contaminer rapports ou démos.

Action:

- marquer dev-only;
- déplacer vers fixture contrôlée;
- ou réécrire pour appeler les services réels.

### 11.7 Prototypes et archives

`borne (Remix)/` et `kiosk_implementation/` semblent être des prototypes/designs/archives, pas le runtime Laravel/Vue actuel.

Risque:

- confusion sur la source de vérité;
- logique ancienne de prix/menu copiée dans le runtime;
- audit futur qui mélange prototype et production.

Action:

- ajouter README `ARCHIVE - not runtime`;
- extraire uniquement les enseignements UX encore utiles;
- éviter toute duplication métier.

### 11.8 Rapports historiques

`reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md` contient des constats utiles mais certains sont dépassés:

- clavier virtuel loyalty est maintenant branché;
- speech existe au moins payment/confirmation;
- `UPDATE_ITEM` gère maintenant `is_available`.

Risque:

- répéter des findings corrigés;
- créer bruit dans la priorisation.

Action:

- ouvrir un registre "resolved / still open";
- ne pas reprendre un ancien audit sans revalidation code.

## 12. Tests et couverture

### Tests solides

- `tests/Feature/KioskEndpointsTest.php` couvre plusieurs endpoints kiosk modernes;
- `tests/Feature/KioskPaymentStateMachineTest.php` couvre cash vs card/TR;
- tests backend confirment que card/TR ne dispatch pas avant paiement confirmé;
- tests menu/pricing/promo montrent que la couche backend cible existe.

### Trous de tests

1. Les tests backend ne prouvent pas que la SPA utilise les bons endpoints.
2. Le test promo JS ne capture pas le mismatch `discount_amount` vs `discount`.
3. Les tests offline utilisent des clés `offline_*`, mais le chemin réel submit peut utiliser un UUID.
4. Le E2E kiosk est surtout un smoke, pas un parcours complet commande/paiement/upsell/offline.
5. Pas de test contractuel "payload wizard complexe -> preview -> create order".
6. Pas de test de non-régression interdisant les prix locaux frontend.
7. Pas de test analytics auth/beacon/fallback.

### Validation recommandée

Minimum avant confiance production:

- test JS promo réel;
- test JS offline id réel;
- test store menu qui vérifie `/frontend/menu`;
- test backend upsell branch availability;
- E2E parcours kiosk online cash/card simulé;
- E2E offline queue/replay;
- test no hardcoded `16` dans composants kiosk.

## 13. Impact business

### Conversion

Risques de baisse conversion:

- prix/promo affichés de façon incohérente;
- produit indisponible visible;
- erreur options requises;
- offline flow bloquant;
- attente paiement sans feedback clair;
- analytics aveugle qui empêche d'identifier l'étape de perte.

### Panier moyen

Upsell réel:

- probablement présent mais sous-optimisé;
- endpoint legacy limite personnalisation;
- auto-skip réduit friction mais peut réduire prise d'upsell;
- absence de mesure complète empêche optimisation.

Upsell potentiel:

- branché sur règles par branche;
- contextuel selon panier;
- filtré disponibilité;
- testé par accept/refuse/timeout;
- combiné promo/palier panier.

### Revenu perdu

Sources probables:

- promo non visible = abandon ou non-utilisation;
- upsell indisponible/générique = taux accept faible;
- offline stuck = commande non finalisée;
- cash marqué payé = risque d'écart caisse;
- menu non branché = annulation staff/KDS;
- analytics perdu = impossibilité de prioriser précisément.

## 14. Plan d'amélioration

| Mission | Priorité | Objectif | Impact conversion | Difficulté | Fichiers principaux |
| --- | --- | --- | --- | --- | --- |
| T-KIOSK-001 | P0 | Faire de `/frontend/menu` l'unique source catalogue borne | Très élevé | Moyenne | `kioskMenu.js`, `KioskAppComponent.vue`, `MenuController.php` |
| T-KIOSK-002 | P0 | Supprimer les prix métier locaux du frontend | Très élevé | Moyenne | `kioskPricing.js`, `KioskWizardComponent.vue`, `kioskCart.js` |
| T-KIOSK-003 | P0 | Corriger l'identifiant offline pour toujours utiliser `offline_*` côté UI | Très élevé | Faible | `kioskCart.js`, `kioskOfflineQueue.js`, tests JS |
| T-KIOSK-004 | P0 | Remplacer `status: 16` par enum/constante partagée | Élevé | Faible | `KioskPaymentComponent.vue`, `KioskWaitingComponent.vue` |
| T-KIOSK-005 | P1 | Brancher upsell frontend sur `/frontend/upsell` et filtrer disponibilité/canal | Élevé | Moyenne | `kioskCart.js`, `UpsellRuleService.php` |
| T-KIOSK-006 | P1 | Corriger promo UI `discount_amount` et tests | Élevé | Faible | `kioskCart.js`, `tests/js/kioskCartPromo.spec.js` |
| T-KIOSK-007 | P1 | Fiabiliser analytics kiosk auth/events/funnel | Élevé | Moyenne | `kioskAnalytics.js`, analytics controller, plugin |
| T-KIOSK-008 | P1 | Revoir cash kiosk: payé immédiat vs paiement comptoir | Élevé | Haute | `FrontendOrderService.php`, POS flow, tests |
| T-KIOSK-009 | P1 | Renforcer E2E kiosk full flow | Élevé | Moyenne | `tests/e2e/03-kiosk-wizard.spec.js`, fixtures |
| T-KIOSK-010 | P2 | Nettoyer docs API/deploy kiosk | Moyen | Faible | `docs/API_KIOSK.md`, `docs/KIOSK_DEPLOYMENT.md` |
| T-KIOSK-011 | P2 | Sécuriser provisioning kiosk | Moyen | Faible | `EnsureKioskMachineCommand.php`, `config/kiosk.php` |
| T-KIOSK-012 | P2 | Marquer prototypes/archives comme non-runtime | Moyen | Faible | `borne (Remix)/README`, `kiosk_implementation/README` |

## 15. Priorité d'exécution recommandée

### Phase A - Sécurité métier immédiate

1. T-KIOSK-003 offline id.
2. T-KIOSK-004 enum statut.
3. T-KIOSK-006 promo discount.
4. T-KIOSK-001 menu unique branché.

Pourquoi: ce sont des corrections qui réduisent immédiatement les commandes perdues, les statuts fragiles et les incohérences branche.

### Phase B - Revenu et cohérence POS

1. T-KIOSK-002 prix frontend.
2. T-KIOSK-005 upsell moderne.
3. T-KIOSK-008 décision cash.
4. T-KIOSK-007 analytics.

Pourquoi: ces missions alignent conversion, panier moyen et vérité POS/backend.

### Phase C - Durabilité

1. T-KIOSK-009 E2E.
2. T-KIOSK-010 docs.
3. T-KIOSK-011 provisioning.
4. T-KIOSK-012 archives.

Pourquoi: elles empêchent la réintroduction des mêmes erreurs par docs ou tests insuffisants.

## 16. Alignement vision

Vision cible:

- UX fluide;
- upsell agressif mais naturel;
- zéro friction;
- POS/backend source de vérité;
- aucune logique métier dupliquée inutilement.

Traduction technique:

- la borne affiche, collecte, anticipe, mais ne décide pas;
- tout prix final vient du backend;
- tout item affiché vient du menu branché;
- toute suggestion upsell est disponible dans la branche;
- toute commande offline est explicitement "en attente de synchronisation";
- tout statut vient d'un enum partagé;
- toute erreur critique produit un événement analytics opérationnel.

## 17. Audit de l'audit

### Limites

- Audit statique: je n'ai pas exécuté un parcours navigateur complet dans cette passe.
- `npm run verify:boucle` avait échoué précédemment avec exit 1 après l'en-tête, sans détail exploitable dans le contexte.
- Graphiti MCP n'était pas chargé; secours utilisé via mémoire disque et lecture ciblée.
- Les lignes exactes peuvent bouger après modifications futures.
- Les dossiers prototypes ont été traités comme archives probables, pas comme runtime.

### Hypothèses

- Runtime production principal = Laravel/Vue sous `resources/js` + backend Laravel.
- POS/backend est la source de vérité selon invariants FoodKing.
- Les endpoints legacy encore appelés par la SPA sont actifs tant que le code les référence.
- Cash immédiat est intentionnel car couvert par test, mais reste un risque business/fiscal à valider.
- L'analytics opérationnel peut légalement être séparé du marketing consent si minimisé et documenté, mais cela demande validation produit/juridique.

### Angles morts restants

- Mesure réelle de latence sur hardware kiosk.
- Capture réseau réelle des endpoints appelés en navigateur.
- Vérification TPE réelle.
- Tests branch multi-tenant en base de données représentative.
- Vérification KDS/POS en environnement complet.
- Fiscalité locale exacte sur cash kiosk.

## 18. Conclusion

La borne FoodKing est déjà structurée comme un module connecté au système global, avec un backend qui porte la majorité des bonnes garanties. Le problème n'est pas l'absence de backend SSOT, mais l'inachèvement de la migration frontend vers ce modèle.

Le gain le plus rapide vient de quatre corrections: menu branché unique, suppression prix locaux, correction offline id, remplacement des statuts numériques. Ensuite, le revenu se travaille via upsell moderne, promo fiable et analytics exploitable. Enfin, les docs/prototypes/tests doivent être nettoyés pour éviter que les anciennes architectures kiosk reviennent par accident.

---

# R2 - Complément max effort avant orchestration de plan

Cette section ajoute les coins indirects non couverts assez fortement dans la première passe: endpoints qui ne sont pas visibles dans le parcours borne, dépendances fiscalité/POS/KDS, tests sentinelles manquants, risques de désynchronisation document/code et décisions humaines à trancher avant de transformer ce rapport en plan.

## 19. Priorité révisée après R2

La priorité réelle n'est plus seulement "corriger la migration frontend vers les endpoints SSOT". Deux sujets passent devant la conversion:

1. `payment-confirm` peut muter un ordre en `PAID` avant d'avoir prouvé que c'est bien une commande borne/TPE différée.
2. La décision fiscale kiosk est contradictoire entre gate "ticket borne non fiscal" et gate "ventes kiosk hors Z".

Ordre recommandé avant tout travail UX/revenu:

| Rang | Sujet | Pourquoi avant conversion |
|---|---|---|
| 1 | Sécurité et idempotence `payment-confirm` | Une conversion gagnée ne vaut rien si un client ou une borne peut créer un état `PAID` invalide. |
| 2 | Décision fiscal/Z kiosk | Le POS reste la source de vérité fiscale; la borne ne doit pas créer un rail de vente hors Z. |
| 3 | Offline id + replay | Une commande offline perdue ou non réouvrable est une vente perdue. |
| 4 | Menu/pricing/promo SSOT | Réduit friction, erreurs prix et abandons. |
| 5 | Upsell/analytics | Optimise le revenu une fois la base transactionnelle sûre. |

## 20. Findings R2 détaillés

### KIOSK-DEEP-013 - P0 - `payment-confirm` est trop large et peut créer un état `PAID` non kiosk

Fichiers:

- `routes/api.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/KioskPaymentStateMachineTest.php`

Preuves:

- La route `/api/frontend/order/{frontendOrder}/payment-confirm` est dans le groupe `auth:sanctum`, sans middleware route `abilities:kiosk:order`.
- Le contrôleur vérifie seulement que `frontendOrder.user_id === authenticatedUserId`.
- Le contrôleur fait ensuite, dans une transaction verrouillée: `payment_status = PAID`, `payment_method = request payment_method`, `transaction_id = request transaction_id`, `card_type = request card_type`.
- Seulement après cette mutation, il appelle `finalizePaidKioskOrder()`.
- `finalizePaidKioskOrder()` est une bonne défense pour empêcher le passage à `ACCEPT` si l'ordre n'est pas kiosk ou méthode différée; mais il ne compense pas la mutation `PAID` déjà faite par le contrôleur.
- Les tests couvrent le happy path card, le cash immédiat, un ordre kiosk déjà `PAID`, et l'appel direct service sur unpaid. Ils ne couvrent pas "ordre web/non-kiosk propriétaire" qui appelle `payment-confirm`.

Risque:

- Un utilisateur authentifié propriétaire d'une commande non kiosk peut potentiellement marquer sa commande `PAID` via cet endpoint.
- Le service peut refuser de promouvoir en `ACCEPT`, mais l'état paiement est déjà contaminé.
- Cela crée une désync paiement/order/KDS/Z: commande payée selon `orders.payment_status`, pas nécessairement encaissée par POS/TPE, pas nécessairement visible dans le flux attendu.

Impact business:

- Fraude ou erreur d'encaissement.
- Support client impossible à réconcilier: "commande payée" en DB sans preuve TPE fiable.
- Risque fiscal si des états `PAID` ne suivent pas le circuit POS/Z.

Correction attendue:

- Restreindre route et contrôleur à un vrai principal borne/TPE: token `kiosk:order`, `KioskMachine` lié, branche serveur, commande créée par cette machine.
- Refuser toute commande non `OrderType::KIOSK` ou `OrderType::TAKEAWAY` avec `source_surface='kiosk'`.
- Refuser toute méthode autre que `CARD` / `TICKET_RESTAURANT` pour `payment-confirm`.
- Refuser si `payment_status !== UNPAID` sauf idempotence strictement prouvée par même `transaction_id`.
- Ne muter `PAID` qu'après toutes les preuves métier.
- Ajouter contrainte/idempotence sur `transaction_id` ou clé dédiée par commande.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-013 | P0 | Très haut revenue integrity | M/L | Blinder `payment-confirm`, tests négatifs non-kiosk, tests idempotence/concurrence. |

### KIOSK-DEEP-014 - P0/P1 - Ambiguïté fiscalité kiosk: ticket non fiscal vs ventes kiosk hors Z

Fichiers:

- `docs/gates/GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md`
- `reports/execution/GATE_BRIEF_P_MEGA_13_TPE_IDEMPOTENCE_2026-04-20.md`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`

Clarification vocabulaire: plusieurs documents parlent encore de `frontend_orders`. Dans le code courant inspecté, `FrontendOrder` opère sur la table `orders`. Pour l'orchestration, il faut donc raisonner en termes de modèle `FrontendOrder` / table `orders`, et traiter `frontend_orders` comme une formulation historique quand elle apparaît dans des gates ou rapports.

Preuves:

- Le gate W9 acte que le ticket imprimé par la borne est une preuve commerciale, pas un ticket fiscal NF525. Cette décision est cohérente si le POS/backend porte bien la preuve fiscale consolidée.
- Le même gate indique que les commandes kiosk restent tracées dans `frontend_orders` et Z reports agrégés.
- Le gate P-MEGA-13 dit explicitement l'inverse opérationnel: les commandes kiosk ne réservent jamais `fiscal_sequence_no`, et `ZReportService` filtre `whereNotNull('fiscal_sequence_no')`; donc les ventes kiosk sont hors Z.
- `OrderService` POS réserve `fiscal_sequence_no` avant sauvegarde.
- `FrontendOrderService` et `paymentConfirm` ne référencent pas `FiscalSequenceService` ni `fiscal_sequence_no`.
- `ZReportService` a raison de filtrer sur `fiscal_sequence_no`; assouplir le filtre casserait l'invariant NF525. Si kiosk doit entrer en Z, la séquence doit être allouée au bon moment.

Risque:

- Si les ventes borne payées sont réelles mais hors Z signé, le système sous-déclare les ventes kiosk dans la piste fiscale.
- Si la décision produit est "borne non centrale, encaissement fiscal final au POS", alors il faut prouver quel événement POS réserve la séquence. Aujourd'hui, le cash kiosk semble déjà `PAID` côté commande et le card confirm met `PAID` côté frontend order; ce n'est pas un simple "pré-ticket".

Décision humaine obligatoire:

- Option A: toute commande kiosk payée reçoit une `fiscal_sequence_no` au moment de l'encaissement validé backend.
- Option B: la borne crée seulement une intention, et le POS finalise systématiquement l'encaissement fiscal via un flux POS explicite.
- Option C: différer l'activation prod kiosk payant autonome tant que la conformité n'est pas tranchée.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-014 | P0 si prod fiscal, sinon gate | Très haut compliance/revenue | L | Gate fiscal + tests "kiosk paid appears in Z" ou preuve explicite du flux POS final. |

### KIOSK-DEEP-015 - P0/P1 - Le garde route offline refuse le format réellement généré

Fichiers:

- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/helpers/kioskOfflineQueue.js`
- `tests/js/kioskWaitingAudioFallback.spec.js`

Preuves:

- Le garde `requireOrderRef()` accepte `^(offline_)?\d+$`.
- La queue offline génère `offline_${savedAt}_${random}` avec deux underscores et suffixe base36.
- Un test utilise `offline_w7b_test`, format également refusé par la regex.

Risque:

- Même après correction de la confusion `offline_123` vs id online, un vrai id offline généré par la queue peut être rejeté sur reload/deep-link.
- Le client revient à idle alors qu'une commande est en attente locale; c'est une perte de confiance et possiblement une vente abandonnée.

Correction attendue:

- Centraliser un helper `isOfflineOrderRef()` utilisé par router, waiting, confirmation, replay.
- Accepter le format généré réel, par exemple `^offline_[A-Za-z0-9_-]+$`, et séparer clairement les ids offline des ids backend numériques.
- Ajouter test direct navigation/reload avec une clé générée par `normalizeQueueEntry()`.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-015 | P1 proche P0 | Haut | S | Route guard offline aligné au format réel + tests reload. |

### KIOSK-DEEP-016 - P1 - Analytics offline v2 et `sendBeacon` créent un trou d'observabilité

Fichiers:

- `resources/js/helpers/kioskAnalytics.js`
- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `app/Http/Controllers/Frontend/KioskEventController.php`

Preuves:

- Frontend et backend whitelistent `offline.queued`, `offline.replayed`, `offline.abandoned`, `offline.recovered`.
- La queue offline émet aussi des événements `offline.queue.v2.*`: `migrated`, `quota_exceeded`, `stale_marked`, `backoff_skip`.
- `KioskAppComponent` émet `offline.queue.v2.conflict_modal_opened` et `offline.queue.v2.conflict_resolved`.
- Ces événements ne sont pas dans la whitelist de `kioskAnalytics.js`; ils peuvent donc être rejetés avant même le backend.
- Le transport privilégie `navigator.sendBeacon`, mais `sendBeacon` ne porte pas l'Authorization header Sanctum. Or `/api/frontend/kiosk-event` exige `auth:sanctum` + `abilities:kiosk:order`.
- Le fallback `fetch` utilise `/api/frontend/kiosk-event` sans header Authorization explicite. Axios est le chemin fiable si l'intercepteur token est chargé.

Risque:

- Les événements offline les plus utiles pour diagnostiquer les pertes de commande sont invisibles.
- Les tests peuvent passer si `sendBeacon` est mocké false, alors qu'un navigateur réel accepte le beacon et le serveur répond 401 sans retry observable.

Correction attendue:

- Décider si les événements v2 restent nommés `offline.queue.v2.*` ou sont mappés vers les quatre événements opérationnels actuels.
- Désactiver `sendBeacon` pour route Sanctum bearer-token, ou créer un endpoint beacon signé/cookie-compatible.
- Ajouter test où `sendBeacon()` renvoie `true` mais la route auth ne peut pas être satisfaite.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-016 | P1 | Moyen/haut, surtout anti-perte | M | Observabilité offline réelle + tests transport auth. |

### KIOSK-DEEP-017 - P2 - Kiosk events: bons garde-fous branche, limites PII et truncation

Fichiers:

- `app/Http/Controllers/Frontend/KioskEventController.php`
- `tests/Feature/KioskSecurity/KioskEventAbilityTest.php`
- `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php`

Points robustes:

- La route `/frontend/kiosk-event` et l'alias `/frontend/kiosk/event` exigent `auth:sanctum`, `abilities:kiosk:order`, throttle.
- Le contrôleur lit le `branch_id` depuis `KioskMachine`, pas depuis le payload.
- Le payload `branch_id` est seulement utilisé pour détecter/logguer un mismatch.
- Les tests existants couvrent l'ability et le spoofing de branche.

Limites:

- La protection PII est key-based: `phone`, `email`, `name`, etc. Une valeur sensible sous une clé neutre comme `notes` ne sera pas détectée.
- `ActionLog.details` est hard-cap à 500 caractères. Les métadonnées utiles de diagnostic peuvent être tronquées.
- `postKioskEvent()` côté hardware a un fallback fetch sans auth explicite, même risque de silence que l'analytics.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-017 | P2 | Moyen ops | S/M | Redaction plus robuste, trace structurée non tronquée ou hashée, transport auth homogène. |

### KIOSK-DEEP-018 - P1 - Cash kiosk: le POS collecte via KDS status, pas via un vrai ledger d'encaissement

Fichiers:

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `app/Services/OrderStatusScreenOrderService.php`
- `app/Services/FrontendOrderService.php`

Preuves:

- Le POS charge les commandes cash kiosk en récupérant `order_type=KIOSK` et `order_type=TAKEAWAY` avec `payment_method=CASH_ON_DELIVERY`.
- Le bouton "collect" appelle `admin/kds-order/change-status/{id}` avec `DELIVERED`.
- Le commentaire dit "collected + paid by cashier", mais l'action technique est un changement de statut KDS/livraison.
- La KDS sépare visuellement `TAKEAWAY` et `KIOSK` par `order_type`. Une commande créée depuis la borne mais en `TAKEAWAY` part dans la colonne takeaway, pas forcément dans une colonne "Borne".
- L'OSS inclut explicitement les takeaway kiosk via `queue_number`, ce qui montre que le cas hybride est connu.

Risque:

- Confusion entre "commande servie/livrée" et "cash réellement encaissé".
- Staff peut ne pas identifier une commande "à emporter depuis borne" dans le KDS si seul `order_type` pilote les colonnes.
- Si cash kiosk est déjà `PAID` dès création, la collecte POS ne prouve pas l'encaissement; elle prouve seulement une transition opérationnelle.

Décision humaine:

- Cash kiosk = payé immédiatement à la borne/caisse automatique, ou paiement au comptoir?
- Si paiement au comptoir, `payment_status` doit-il rester `UNPAID` jusqu'à action POS dédiée?
- Le KDS doit-il afficher badge `source_surface=kiosk` sur les commandes takeaway?

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-018 | P1 | Haut revenue integrity | M/L | Flux cash explicite: payment ledger ou action POS dédiée, badge source_surface KDS/POS. |

### KIOSK-DEEP-019 - P2/P1 - Admin PIN borne: frontend sans fallback, backend fallback `1234`

Fichiers:

- `app/Http/Resources/SettingResource.php`
- `resources/js/components/frontend/kiosk/KioskAdminComponent.vue`
- `resources/js/components/admin/settings/KioskSetup/KioskSetupComponent.vue`

Points robustes:

- Le composant kiosk ne hardcode plus `1234`; `DEFAULT_PIN=''`.
- Si aucun PIN n'est exposé, l'admin panel local est bloqué.
- Le PIN n'est retourné qu'à un token kiosk authentifié.

Risque caché:

- `SettingResource` retourne encore `kiosk_admin_pin ?? '1234'` aux tokens kiosk.
- Une borne provisionnée mais non configurée a donc un PIN admin dev connu.
- Le lockout 3 essais/30s est côté client; refresh/devtools/local state peuvent contourner l'effort-rate-limit.

Correction attendue:

- En prod, refuser ou masquer le panel admin tant que le PIN n'a pas été configuré explicitement.
- Supprimer le fallback backend `1234`, ou le limiter à environnement non prod.
- Journaliser unlock/failures côté serveur si cette fonction devient une vraie barrière staff.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-019 | P2, P1 si prod publique | Moyen ops/security | S | Suppression fallback `1234` prod + test resource. |

### KIOSK-DEEP-020 - P2 - KioskMachine admin: contraintes DB et side effects en transaction

Fichiers:

- `database/migrations/2025_02_21_110459_create_kiosk_machines_table.php`
- `app/Services/KioskMachineService.php`
- `app/Http/Requests/KioskMachineRequest.php`

Preuves:

- La table `kiosk_machines` n'a pas de contrainte DB unique sur `machine_id` ou `username`.
- Les règles Laravel peuvent refuser un doublon en requête normale, mais ne protègent pas contre course/import concurrent.
- `KioskMachineService::list()` applique `LIKE '%...%'` sur `user_id`, `branch_id`, `status`; pour des champs numériques, c'est fragile (`1` matche `10`, `11`).
- `destroy`, `changeStatus`, `logout` envoient des notifications FCM à l'intérieur d'une transaction DB. Ce n'est pas le chemin order dispatch critique, mais ça reste un side effect avant commit.

Risque:

- Deux machines peuvent partager un identifiant si la validation applicative est contournée.
- Admin/reporting peut filtrer trop large.
- Notification envoyée alors que la transaction rollback peut désynchroniser l'appareil.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-020 | P2 | Faible direct, moyen ops | M | Contraintes DB, filtres exacts numériques, FCM après commit si conservé. |

### KIOSK-DEEP-021 - P1 - Route `loyalty/scan`: middleware et commentaire divergent

Fichiers:

- `routes/api.php`
- `app/Http/Controllers/Frontend/LoyaltyController.php`

Preuves:

- Le commentaire route dit "Auth Sanctum + kiosk:order ability".
- Le middleware route est `auth:sanctum` + throttle, sans `abilities:kiosk:order`.
- Le contrôleur refait bien la vérification `$user->tokenCan('kiosk:order')`.

Risque:

- Le comportement actuel est défendu côté contrôleur, mais la route n'est pas fail-closed au même niveau que `kiosk-event`.
- Un futur refactor de contrôleur peut retirer la défense sans que la route compense.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-021 | P2/P1 sécurité simple | Faible direct | S | Ajouter `abilities:kiosk:order` à la route + test middleware. |

### KIOSK-DEEP-022 - P2 - Design System kiosk présent mais pas encore runtime canonique

Fichiers:

- `resources/js/bootstrap-kiosk.js`
- `resources/css/kiosk/tokens.css`
- `resources/css/kiosk/tokens-aaa.css`
- `resources/css/kiosk/tokens-pmr.css`
- `resources/js/components/frontend/kiosk/ds/*`

Preuves:

- `bootstrap-kiosk.js` importe les tokens et réexporte les atoms DS.
- Le commentaire du fichier dit explicitement que ce bootstrap n'est pas encore câblé à `resources/js/app.js`.
- La raison indiquée est un risque de restyle des écrans kiosk legacy via variables CSS existantes.

Risque:

- Un plan UX peut croire que les tokens/atoms DS sont déjà la couche runtime officielle, alors qu'une partie du kiosk reste pilotée par CSS/composants legacy.
- Les corrections d'accessibilité peuvent être faites dans les atoms sans toucher les écrans réellement montés.
- À l'inverse, patcher les composants legacy sans trajectoire DS peut aggraver la divergence.

Correction attendue:

- Ne pas lancer un "grand restyle" sans gate.
- Pour chaque écran kiosk touché, décider explicitement: legacy patch minimal ou migration DS atom par atom.
- Ajouter un contrôle de build/grep indiquant quels écrans consomment réellement `ds/*`.

Mission:

| ID | Priorité | Impact conversion/revenue | Difficulté | Livrable |
|---|---|---:|---:|---|
| T-KIOSK-022 | P2 | Moyen UX long terme | M | Cartographie runtime DS vs legacy + règle de migration écran par écran. |

## 21. Points robustes à conserver dans le plan

Ces éléments ne doivent pas être cassés par les missions futures:

- Le backend recalcul prix et totals via services serveur pour la création de commande kiosk; la borne ne doit pas devenir source de vérité prix.
- `KioskEventController` prend le `branch_id` depuis `KioskMachine`, pas depuis le payload.
- Les endpoints modernes menu/pricing/promo/upsell ont des contrôles côté contrôleur qui cherchent le principal kiosk.
- `finalizePaidKioskOrder()` a une défense de service contre promotion unpaid vers `ACCEPT`.
- Le POS cash panel récupère déjà les deux types `KIOSK` et `TAKEAWAY`.
- L'OSS inclut déjà les takeaway kiosk via `queue_number`.
- Le composant admin kiosk ne garde pas de fallback PIN local.
- Les tests de sécurité kiosk events existent et doivent rester dans le scope de validation.
- Le Design System kiosk existe, mais doit être considéré comme trajectoire/migration, pas comme vérité runtime déjà complète.

## 22. Matrice des décisions humaines avant plan

Ces décisions doivent être prises avant de découper un plan d'exécution. Sans ça, le plan risque de mélanger conformité, UX et backend critique.

| Décision | Option recommandée | Bloque quoi |
|---|---|---|
| `payment-confirm` réservé à qui? | Borne/TPE authentifié, token `kiosk:order`, commande liée à la machine. | T-KIOSK-013 |
| Les ventes kiosk payées entrent-elles dans le Z? | Oui si la borne encaisse réellement. Sinon POS doit finaliser l'encaissement fiscal. | T-KIOSK-014 |
| Cash kiosk = payé à création ou au comptoir? | Au comptoir si présence cashier; donc paiement séparé du statut cuisine. | T-KIOSK-018 |
| Kiosk TAKEAWAY visible où dans KDS? | Garder colonne takeaway mais badge source `Borne`, ou créer filtre/badge cross-column. | T-KIOSK-018 |
| Analytics opérationnel nécessite-t-il consent marketing? | Séparer observabilité opérationnelle minimale de marketing analytics. | T-KIOSK-016 |
| PIN admin par défaut accepté? | Non en prod; setup obligatoire. | T-KIOSK-019 |
| Offline order après reload doit-il être réouvrable? | Oui, avec bannière "en attente de synchro". | T-KIOSK-015 |

## 23. Handoff orchestration: lots recommandés

### Lot A - Sécurité paiement et idempotence

Missions:

- T-KIOSK-013 `payment-confirm`
- T-KIOSK-021 route ability loyalty scan si on veut faire un durcissement simple en même temps

Tests à exiger:

- Un ordre web/non-kiosk ne peut pas être marqué `PAID` via `payment-confirm`.
- Un ordre kiosk d'une autre machine/branche est refusé.
- Une méthode cash est refusée par `payment-confirm`.
- Deux confirmations concurrentes ne produisent qu'un seul état final idempotent.
- Même `transaction_id` sur deux ordres différents est refusé ou explicitement tracé comme conflit.

### Lot B - Fiscalité et cash POS

Missions:

- T-KIOSK-014 fiscal/Z
- T-KIOSK-018 cash collection

Tests à exiger:

- Selon décision: une commande kiosk payée a `fiscal_sequence_no` et apparaît dans l'agrégat Z.
- Ou: une commande kiosk n'est pas `PAID` fiscalement tant que le POS n'a pas fait l'action finale.
- Une commande cash kiosk ne passe pas silencieusement de "à encaisser" à "livrée" sans trace paiement.
- Le badge `source_surface=kiosk` reste visible pour `TAKEAWAY`.

### Lot C - Offline/replay/observabilité

Missions:

- T-KIOSK-003 première passe
- T-KIOSK-015 route offline id réel
- T-KIOSK-016 analytics offline v2 + auth transport
- T-KIOSK-017 redaction/transport events

Tests à exiger:

- Une clé `offline_<timestamp>_<suffix>` passe le guard waiting après reload.
- Un id offline ne déclenche jamais un GET order backend numérique.
- Les événements `offline.queue.v2.*` sont soit acceptés, soit mappés vers des noms whitelisted.
- `sendBeacon=true` ne fait pas disparaître silencieusement un événement auth.
- Payload PII sous clé interdite est refusé; payload long conserve le diagnostic essentiel.

### Lot D - Menu/pricing/promo/upsell conversion

Missions:

- T-KIOSK-001 menu unique `/frontend/menu`
- T-KIOSK-002 suppression prix frontend comme logique métier
- T-KIOSK-005 upsell moderne
- T-KIOSK-006 promo discount fiable
- T-KIOSK-007 analytics conversion

Tests à exiger:

- Le parcours SPA utilise le menu branché unifié, pas `item`, `item-category`, `featured-items` dispersés.
- Le prix affiché après option/promo vient de `/pricing/preview` ou réponse backend, pas d'un calcul durable frontend.
- Une promo montant fixe affiche la réduction réelle, pas seulement un message.
- Un item upsell indisponible en branche n'apparaît jamais.
- Les événements `upsell_shown`, `upsell_accepted`, `order_completed` partent avec consentement ou dans un canal opérationnel validé.

### Lot E - Ops/admin/docs

Missions:

- T-KIOSK-010 docs runtime
- T-KIOSK-011 provisioning/env
- T-KIOSK-012 archives
- T-KIOSK-019 PIN admin
- T-KIOSK-020 KioskMachine DB/admin
- T-KIOSK-022 cartographie DS runtime vs legacy

Tests/contrôles à exiger:

- Documentation "runtime source map" liste uniquement les fichiers actifs.
- Les prototypes/archives sont marqués non runtime.
- Une installation prod sans PIN admin explicite échoue ou masque le panel.
- `machine_id`/`username` ont une contrainte DB cohérente avec la validation Laravel.
- Les filtres numériques kiosk machine sont exacts, pas `LIKE`.
- Le plan UX sait quels écrans consomment réellement les atoms DS.

## 24. Tests red-team complets à demander au plan

Liste brute, prête à transformer en cases PHPUnit/Vitest/Playwright:

1. `payment-confirm` non-kiosk propriétaire: attendu 403/422 et `payment_status` inchangé.
2. `payment-confirm` kiosk mauvais `branch_id`/machine: attendu refus, pas de mutation.
3. `payment-confirm` sur cash order: attendu refus, pas de mutation.
4. `payment-confirm` sur ordre déjà `PAID` avec même transaction: idempotent.
5. `payment-confirm` sur ordre déjà `PAID` avec transaction différente: conflit.
6. `payment-confirm` concurrent x2: un seul succès logique, pas de double dispatch.
7. Kiosk paid card/TR: statut reste `PENDING` avant confirm; KDS ne voit pas l'ordre.
8. Kiosk card/TR confirmé: statut `ACCEPT`, dispatch post-commit, KDS voit l'ordre.
9. Kiosk paid fiscal: selon gate, `fiscal_sequence_no` présent et Z contient l'ordre, ou flux POS explicite.
10. Cash kiosk: pas de confusion entre "payé" et "livré".
11. TAKEAWAY kiosk: visible côté POS cash et KDS avec badge/source.
12. Offline generated key `offline_<timestamp>_<suffix>`: route waiting valide.
13. Offline key: aucun polling backend numérique tant que replay non confirmé.
14. Replay offline stale item: modal de conflit visible, résolution tracée.
15. Analytics offline v2: event non droppé par whitelist.
16. Analytics beacon path: event auth ne disparaît pas si `sendBeacon` retourne true.
17. PII event: clé interdite imbriquée refusée.
18. Long event payload: diagnostic minimal conservé après cap ou stocké structurément.
19. Menu: aucun appel legacy nécessaire au rendu principal si `/frontend/menu` disponible.
20. Pricing: mutation d'un prix frontend n'affecte jamais le total final backend.
21. Promo fixed amount: montant affiché = montant validé backend.
22. Upsell: item hors branche ou inactive jamais proposé.
23. Loyalty scan: route middleware + contrôleur refusent token non kiosk.
24. Admin PIN: absence de configuration prod ne donne jamais `1234`.
25. KioskMachine duplicate: DB refuse doublon concurrent.

## 25. Références locales utiles pour orchestration

À injecter dans les futurs briefs de mission selon lot:

- `tasks/audits/AUDIT_KIOSK_ORDER_CREATION_016.md`
- `tasks/audits/AUDIT_KIOSK_PAYMENT_CASH_017.md`
- `tasks/audits/AUDIT_KIOSK_PAYMENT_DEFERRED_CARD_TR_018.md`
- `tasks/audits/AUDIT_KIOSK_AUTH_TOKEN_ABILITY_019.md`
- `tasks/audits/AUDIT_KIOSK_WIZARD_UX_IDLE_020.md`
- `tasks/audits/AUDIT_KIOSK_HARDWARE_BRIDGE_021.md`
- `tasks/audits/AUDIT_KIOSK_BRANCH_ISOLATION_022.md`
- `tasks/audits/AUDIT_KIOSK_LOYALTY_UPSELL_023.md`
- `tasks/audits/AUDIT_KIOSK_REALTIME_ECHO_POLLING_024.md`
- `tasks/audits/AUDIT_KIOSK_CANCEL_REFUND_025.md`
- `docs/gates/GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md`
- `reports/execution/GATE_BRIEF_P_MEGA_13_TPE_IDEMPOTENCE_2026-04-20.md`
- `docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md`
- `docs/gates/GATE_P_MEGA_20_BRANCH_MISMATCH_2026-04-20.md`
- `reports/execution/RUN_T14B_OFFLINE_HARDENING_2026-04-20.md`
- `reports/execution/VERIFY_P_MEGA_W8_A_BRANCH_MISMATCH_2026-04-20.md`

## 26. UX/accessibilité R2

Source UX externe utilisée pour cette passe: Vercel Web Interface Guidelines, consultée le 2026-04-25: https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md

Points UX à ne pas perdre dans le plan:

- La borne doit fonctionner comme une interface tactile répétitive, pas comme une landing page: gros targets, focus visible, libellés actionnels, erreurs avec next step.
- Les états async doivent être annoncés (`aria-live`) pour paiement, offline replay, promo, fidélité.
- Les boutons icon-only doivent garder `aria-label`; les composants kiosk récents ont déjà des efforts dans ce sens.
- Le format monétaire doit rester `Intl.NumberFormat`, pas concaténation locale.
- Les textes longs de produits/addons doivent avoir `min-w-0`, truncation ou wrap contrôlé pour éviter les cassures sur écran vertical.
- Les animations idle/transition doivent respecter `prefers-reduced-motion`.
- La conversion vient d'abord de la confiance: prix stable, état paiement clair, numéro de queue visible, message offline honnête.

Risques UX indirects à intégrer:

- Si le paiement carte est en attente TPE, l'écran ne doit pas ressembler à une confirmation définitive.
- Si la commande offline est stockée, le client doit comprendre que la commande n'est pas encore acceptée par le POS/backend.
- Si cash = paiement comptoir, le CTA doit dire "Payer au comptoir" ou équivalent, pas "Commande payée".
- Si TAKEAWAY depuis borne apparaît en colonne takeaway KDS, le staff doit voir l'origine borne sans chercher dans les détails.

## 27. Audit de l'audit R2

Ce que cette R2 améliore:

- Elle ajoute les endpoints hors parcours visible (`payment-confirm`, loyalty scan, kiosk-event).
- Elle croise les gates fiscaux et le code Z report.
- Elle distingue conversion UX de revenue integrity.
- Elle prépare des lots planifiables plutôt qu'une liste plate de bugs.

Limites restantes:

- Je n'ai pas exécuté les suites PHPUnit/Vitest dans cette passe R2.
- Je n'ai pas lancé navigateur/Playwright sur un parcours kiosk réel.
- Je n'ai pas vérifié une base prod-like avec données multi-branches volumineuses.
- Je n'ai pas validé un terminal TPE/Electron réel.
- Les décisions fiscalité/NF525 doivent être prises par owner produit + conformité; ce rapport ne peut pas les auto-approuver.

Verdict R2:

- La borne reste bien un module connecté, pas le système central.
- Le POS/backend doit rester source de vérité.
- Avant d'optimiser l'upsell, il faut verrouiller paiement, fiscalité, offline et cash POS.
- Les missions T-KIOSK-013 et T-KIOSK-014 doivent devenir les premières entrées du plan si l'objectif est prod-ready.
