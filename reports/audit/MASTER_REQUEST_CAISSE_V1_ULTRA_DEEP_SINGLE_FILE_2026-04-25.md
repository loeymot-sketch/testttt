# Master Ultra Deep Single File - Caisse V1 FoodKing

Date: 2026-04-25  
Objet: rapport unique consolide pour preparer la correction et la mise en production V1 du systeme caisse FoodKing.  
Sortie demandee: un seul fichier. Ce document remplace les brouillons precedents du meme sujet et doit etre traite comme le rapport de reference pour la suite.

---

## 0. Positionnement du rapport

Ce rapport n'est pas une implementation et ne ferme aucun gate. Il sert a transformer la demande initiale en chantier technique exploitable: audit profond, decomposition du systeme caisse, inventaire des fichiers directs et indirects, risques caches, plan de correction par checkpoints, criteres de validation et matrice de tests.

Le depot contient deja un cycle actif non lie a ce sujet dans `.cursor/ACTIVE_CYCLE.md`. Pour cette raison, aucune modification produit n'est incluse ici. Les changements futurs sur le code doivent passer par un cycle officiel `run-cycle <TASK_ID>` avec plan actif, mission, validation, audit Claude terminal et gate humain si necessaire.

Graphiti MCP n'etait pas disponible dans cette session. La memoire de secours a donc ete lue via le depot: `memory/INDEX.md`, `memory/episodes/*.jsonl`, les rapports d'audit existants, les documents d'orchestration, les routes, les services, les ressources, les composants Vue et les dossiers legacy.

---

## 1. Verdict executif

La caisse FoodKing n'est pas un simple ecran POS. C'est le noyau transactionnel qui relie:

- la vente comptoir POS;
- la borne kiosk;
- le paiement cash, carte, ticket restaurant et passerelles futures;
- le KDS cuisine;
- les stations bar / production;
- l'ecran de suivi commande;
- les tables et floorplans;
- les stocks / disponibilites;
- la fiscalite, les recus, Z/X reports et audit chain;
- l'outbox temps reel;
- les permissions, branches et machines kiosk;
- les workflows offline et reprise apres panne.

La base est solide sur plusieurs invariants importants:

- recalcul prix backend via `PricingService`;
- isolation branche via `BranchScope` et controles explicites dans plusieurs services;
- enums PHP pour `OrderStatus`, `OrderType`, `PaymentStatus`, `PaymentGateway`, `PosPaymentMethod`;
- evenements de domaine et outbox pour synchronisation;
- service separe POS vs frontend/kiosk;
- tests existants autour POS, KDS, fiscal, kiosk, idempotence et disponibilite.

Mais la V1 production reste bloquee tant que certains ecarts caches ne sont pas corriges ou formellement gates:

- la promotion kiosk est validee/preview cote frontend mais pas consommee de facon evidente dans la creation finale de commande;
- `paymentConfirm` frontend peut marquer une commande payee sans verifier assez fortement que le token est une machine kiosk et que la commande est bien une commande kiosk/carte/TR eligible;
- le catch d'idempotence POS apres violation SQL cherche par `idempotency_key` sans re-scoper par `branch_id`;
- KDS HTTP ne recoit pas un `expected_status` explicite du client et ne detecte pas proprement tous les conflits d'ecrans stale;
- des statuts numeriques hardcodes existent encore dans des composants kiosk hors couverture du lint status actuel;
- des dossiers legacy / indirects contiennent des exemples avec prix cote client, fallback branche et logique non authoritative qui peuvent polluer une future reprise;
- les flux bar / stations / bump / multi-ecran KDS n'ont pas encore un contrat durable de concurrence et de responsabilite station;
- les paiements multi-moyens et remboursements partiels restent limites par l'absence d'une table durable `order_payments`;
- la fiscalite est techniquement avancee mais ne doit pas etre presentee comme certification NF525 sans gate externe.

Conclusion: il faut lancer un chantier officiel en plusieurs cycles. Le premier cycle doit corriger les failles de contrat cachees avant de construire plus d'UX.

---

## 2. Invariants FoodKing a proteger

### 2.1 Prix

Invariant: le backend est la seule source de verite du prix.

Etat observe:

- `PricingService` recalcule les prix depuis la base, pas depuis les totaux envoyes par le client.
- `PosOrderRequest` accepte des champs financiers mais `OrderService::posOrderStore` les retire avant calcul.
- `FrontendOrderService::myOrderStore` passe par `PricingService::forKiosk`.
- `PricingPreviewService` existe pour afficher une estimation kiosk.

Risque cache:

- le preview kiosk applique `kiosk_promo_code`, mais la creation finale semble fonctionner surtout avec `coupon_id` / `loyalty_code`;
- le payload kiosk envoie `kiosk_promo_code`, mais `OrderRequest` ne valide pas ce champ dans le flux final;
- `PricingPreviewService::toObject()` reduit variations/extras a des objets `{ id }`, ce qui peut perdre les quantites dans les previews complexes;
- des fichiers legacy `kiosk_implementation/` contiennent du calcul taxe/total cote client et doivent etre consideres non-authoritatifs.

Decision cible:

- unifier preview et checkout final sur un meme contrat d'entree;
- ajouter tests de parite preview/final;
- interdire dans le code source actif tout calcul metier de prix cote frontend sauf affichage de valeurs renvoyees par backend.

### 2.2 OrderStatus

Invariant: enum unique, pas de chaines magiques ni entiers magiques disperses.

Etat observe:

- backend enum `App\Enums\OrderStatus`;
- frontend utilise `resources/js/common/enums/orderStatusEnum.js` sur plusieurs surfaces POS/KDS/OSS;
- lint status actuel passe sur son perimetre declare.

Risque cache:

- `KioskPaymentComponent.vue` et `KioskWaitingComponent.vue` postent encore `status: 16` pour annulation;
- le lint status actuel ne couvre pas clairement toute la surface kiosk;
- `OrderStatusRequest` valide `integer` mais pas explicitement `Rule::in(OrderStatus::values())`;
- le meme request est partage entre surfaces qui ne devraient pas toutes accepter les memes transitions.

Decision cible:

- etendre le lint status aux composants kiosk et a tout `resources/js/frontend/**`;
- remplacer les entiers directs par l'enum frontend;
- ajouter un request ou une policy de transition par surface: POS, KDS, kiosk, admin.

### 2.3 Branch isolation

Invariant: aucune fuite inter-branches.

Etat observe:

- `BranchScope` filtre les donnees pour les utilisateurs authentifies non admin;
- plusieurs services refont des guards explicites;
- canal Echo `branch.{branchId}` limite les kiosk tokens a leur machine et les staff a leur branche;
- les admin `branch_id=0` peuvent voir plus large selon les routes.

Risque cache:

- le catch idempotence POS apres violation SQL n'est pas branche-scope;
- les admin globaux sur KDS peuvent creer une vision omnisciente operationnelle si aucune limite UI/procedure n'est posee;
- les dossiers legacy montrent des fallback `branch_id ?? 1`;
- les routes QR table sont publiques/throttlees et doivent rester tres strictes sur la resolution de table/branche.

Decision cible:

- branch-scope systematique sur tous les fallbacks idempotence;
- tests anti-fuite inter-branches pour catch SQL, KDS sync, payment confirm, table QR;
- mention explicite dans runbook: admin global ne doit pas etre un mode production cuisine par defaut.

### 2.4 Dispatch apres commit

Invariant: jobs/events apres commit DB.

Etat observe:

- `OrderCreated`, `OrderStatusChanged`, `OrderTableChanged`, `ItemAvailabilityChanged` utilisent `DispatchableAfterCommit`;
- l'outbox cree `DomainEvent` puis declenche `DispatchDomainEventsJob` via `DB::afterCommit`;
- l'AppServiceProvider bloque certains modes dangereux en production (`QUEUE_CONNECTION=sync`, `BROADCAST_DRIVER=null`, cache non durable pour audit chain).

Risque cache:

- `AvailabilityService::toggle()` appelle `event(ItemAvailabilityChanged::forBranch(...))` dans une transaction. Certains listeners peuvent donc executer une partie de leur travail avant commit, meme si la diffusion outbox aval est protegee;
- des listeners de cache/menu snapshot doivent etre verifies pour rollback safety;
- l'outbox est robuste mais doit etre surveillee en prod: lag, retry, terminal failure, dead letters.

Decision cible:

- standardiser le dispatch availability sur la meme forme que les autres events;
- tester rollback: aucune invalidation/counter durable ne doit rester si transaction rollback;
- monitorer la queue `high` et les erreurs `DispatchDomainEventsJob`.

### 2.5 OrderService / FrontendOrderService symmetry

Invariant: parite explicite si l'un des deux est modifie.

Etat observe:

- POS et kiosk ont bien deux services separes;
- les deux recalculent via backend;
- les deux ont idempotence, queue/fiscal/order items, audit logs.

Risque cache:

- POS accepte coupon manuel/discount selon permissions alors que kiosk utilise coupon/promo/loyalty differemment;
- la finalisation paiement kiosk est separee de la creation;
- les signaux `OrderCreated` / `OrderStatusChanged` doivent rester coherents entre POS cash, kiosk cash, kiosk carte, ticket restaurant.

Decision cible:

- chaque cycle modifiant l'un des deux services doit inclure une `SYMMETRY_NOTE`;
- les tests doivent comparer les contrats: prix, status initial, payment_status, fiscal sequence, queue number, events emitted, refunds/stock release.

---

## 3. Carte ultra profonde des fichiers couverts

Cette section liste les zones visibles, indirectes et cachees a inclure dans le chantier. Elle n'est pas exhaustive a l'octet pres, mais elle couvre les chemins qui influencent la caisse directement ou indirectement.

### 3.1 Gouvernance, cycle, memoire, gates

| Zone | Fichiers | Role | Risque |
| --- | --- | --- | --- |
| Contrat agent | `AGENTS.md` | Invariants, workflow, roles | Modification produit interdite hors cycle |
| Cycle actif | `.cursor/ACTIVE_CYCLE.md` | Etat courant | Ne pas creer un chantier fantome |
| Routing | `.cursor/routing.md` | Role PLAN/EXECUTE/AUDIT | Ne pas modifier sans gate |
| Rules | `.cursor/rules/*.mdc` | Contraintes always-on | Invariants implicites |
| Cycle command | `.cursor/commands/run-cycle.md` | Procedure officielle | Toute correction doit passer ici |
| Memory | `memory/INDEX.md`, `memory/episodes/*.jsonl` | Decisions durables | Graphiti absent => fallback obligatoire |
| Missions | `missions/<TASK_ID>/` | Input plan/brief | Source de verite EXECUTE |
| Reports | `reports/audit/*`, `reports/review/*` | Audits precedents | Contient risques caches deja identifies |

### 3.2 Routes et surfaces API

| Route zone | Fichier | Surfaces critiques |
| --- | --- | --- |
| API principale | `routes/api.php` | POS, KDS, frontend order, kiosk event, fiscal, tables |
| Broadcast auth | `routes/channels.php` | `branch.{branchId}` auth staff/kiosk |
| POS admin | `admin/pos`, `admin/pos-order` | Creation POS, statut, paiement, suppression, export |
| KDS admin | `admin/kds-order`, `admin/kds-sync` | Liste cuisine, bump, sync fallback |
| Frontend/kiosk | `frontend/order`, `payment-confirm`, `pricing/preview`, `promo/validate` | Checkout kiosk |
| Floorplan/table | `admin/pos/floorplan`, `table/dining-order` | Tables, QR, dining |
| Fiscal | `admin/fiscal/z-report`, `x-report` | Z/X reports |
| Events kiosk | `frontend/kiosk-event` | Hardware/security telemetry |

### 3.3 Backend caisse et commandes

| Fichier | Responsabilite | Notes |
| --- | --- | --- |
| `app/Services/OrderService.php` | POS/admin order lifecycle | Coeur POS |
| `app/Services/FrontendOrderService.php` | Kiosk/frontend checkout | Coeur borne |
| `app/Services/PricingService.php` | Calcul prix SSOT | Noyau prix |
| `app/Services/PricingPreviewService.php` | Preview kiosk | Doit rester en parite |
| `app/Services/KioskPromoService.php` | Promo kiosk/coupon validation | Read-only aujourd'hui |
| `app/Services/KitchenDisplaySystemOrderService.php` | KDS list/change/items | Cuisine/bar |
| `app/Services/KdsSyncService.php` | Fallback polling sync | Temps reel degrade |
| `app/Services/PosParkedOrderService.php` | Commandes mises en attente | Revalidation avant recall |
| `app/Services/DiningTableService.php` | Tables/floorplan | Occupy/release/transfer |
| `app/Services/AvailabilityService.php` | 86 stock/disponibilite | Transactions + events |
| `app/Services/FiscalSequenceService.php` | Sequences fiscales | Audit/fiscal |
| `app/Services/PaymentService.php` | Transactions/cashback | Limites refund/multi-payment |
| `app/Services/EscPosPrinterService.php` | Ticket/open drawer | Hardware caisse |

### 3.4 Controllers critiques

| Controller | Role | Risque principal |
| --- | --- | --- |
| `Admin/PosController.php` | Creer commande POS | Entrypoint POS |
| `Admin/PosOrderController.php` | Gestion commande POS | Statut/paiement/delete/export |
| `Admin/KitchenDisplaySystemController.php` | KDS | Permissions et bump |
| `Admin/KdsSyncController.php` | Sync KDS | Branch override admin |
| `Frontend/OrderController.php` | Kiosk order/paymentConfirm | Durcissement paymentConfirm |
| `Frontend/PromoController.php` | Promo validation | Preview/final mismatch |
| `Frontend/KioskEventController.php` | Events kiosk hardware | PII/security whitelist |
| `Auth/KioskMachineLoginController.php` | Login machine | Token ability kiosk |
| `Admin/KioskMachineController.php` | Gestion machines | Admin settings |
| `Admin/Pos/ParkedOrderController.php` | Park/recall | Revalidation panier |
| `Admin/Pos/FloorplanController.php` | Tables POS | Branch/table sync |
| `Admin/Pos/CashDrawerController.php` | Tiroir caisse | Audit drawer |
| `Admin/PosReceiptPrintController.php` | Impression recu | Audit print |
| `Admin/PrinterController.php` | Printers | Station/branch |
| `Admin/OrderStatusScreenController.php` | OSS | Read-only display |

### 3.5 Requests, enums, resources

| Type | Fichiers | Point d'attention |
| --- | --- | --- |
| Requests POS | `PosOrderRequest`, `PaymentStatusRequest` | Valider enums/permissions |
| Requests frontend | `OrderRequest`, `OrderStatusRequest` | `kiosk_promo_code`, status strict |
| Enums | `app/Enums/OrderStatus.php`, `OrderType.php`, `PaymentStatus.php`, `PaymentGateway.php`, `PosPaymentMethod.php` | Source unique |
| Resources order | `OrderDetailsResource`, `OrderItemResource` | Totaux, snapshots, fiscal |
| Resources KDS | `KDSOrderDetailsResource`, `KDSOrderItemsResource` | Queue/items/stations |
| Models | `Order`, `OrderItem`, `KioskMachine`, `Printer`, `BranchScope` | Scopes, casts, fillable |

### 3.6 Events, listeners, jobs, outbox

| Fichier | Role |
| --- | --- |
| `app/Events/OrderCreated.php` | Signal creation |
| `app/Events/OrderStatusChanged.php` | Signal transition |
| `app/Events/OrderTableChanged.php` | Signal table/floorplan |
| `app/Events/ItemAvailabilityChanged.php` | Signal 86 |
| `app/Listeners/PersistOrderCreatedToOutbox.php` | Outbox order created |
| `app/Listeners/PersistOrderStatusChangedToOutbox.php` | Outbox status |
| `app/Listeners/PersistOrderTableChangedToOutbox.php` | Outbox table |
| `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` | Outbox availability |
| `app/Jobs/DispatchDomainEventsJob.php` | Broadcast durable |
| `app/Support/Realtime/EventContract.php` | Contrat event backend |
| `resources/js/common/realtime/eventContract.js` | Contrat event frontend |
| `app/Listeners/DecrementItemAvailabilityOnOrder.php` | Stock decrement |
| `app/Listeners/ReleaseAvailabilityOnOrderCanceled.php` | Stock release cancel |
| `app/Listeners/ReleaseAvailabilityOnRefundCreated.php` | Stock release refund |

### 3.7 Frontend POS, kiosk, KDS, OSS

| Zone | Fichiers | Note |
| --- | --- | --- |
| POS principal | `resources/js/components/admin/pos/PosComponent.vue` | Cart, KDS cash panel, realtime |
| Paiement POS | `resources/js/components/admin/pos/PaymentComponent.vue` | Cash/card visible |
| POS store | `resources/js/stores/posCart.js`, `posOrder.js` | Idempotence, localStorage branch/user |
| Kiosk cart | `resources/js/stores/kioskCart.js` | Payload sans branch_id, offline queue |
| Kiosk paiement | `KioskPaymentComponent.vue` | TPE, paymentConfirm, status 16 hardcode |
| Kiosk waiting | `KioskWaitingComponent.vue` | Cancel status 16 hardcode |
| KDS sync | `resources/js/services/KdsSyncService.js` | Polling fallback, version gate |
| Realtime | `resources/js/common/realtime/eventContract.js` | Dedupe per tab |
| OSS | order status screen components | Read-only kitchen/customer display |

### 3.8 Legacy, indirect, assets compiles

| Zone | Fichiers | Decision |
| --- | --- | --- |
| Kiosk Remix docs | `borne (Remix)/docs/design/KIOSK_HARDWARE_SPEC.md` | Spec hardware figiable |
| Kiosk hardware calls | `borne (Remix)/docs/design/KIOSK_HARDWARE_CALLS.md` | Interdire hardware direct |
| Flutter snippets | `kiosk_implementation/**` | Archival/non-authoritative |
| Public JS | `public/js/**` | Assets compiles, ne pas patcher comme source |
| Reports anciens | `reports/review/AUDIT_POS_110_*.md` | Risques caches deja catalogues |
| Docs business | `docs/BUSINESS_RULES.md`, `docs/ORDER_FLOW.md`, etc. | Verifier drift avec code |

---

## 4. Findings prioritaires

### P0-1 - Contrat de cycle avant correction produit

Tout changement produit doit passer par un vrai cycle:

1. `run-cycle CAISSE-V1-...`
2. plan officiel dans `plans/PLAN_<TASK_ID>_<date>.md`
3. mission dans `missions/<TASK_ID>/`
4. execution avec delegation tracee
5. validation tests
6. audit Claude terminal ou fallback documente
7. gate/close.

Risque si ignore: modifications hors scope, gates invisibles, frozen zones touchees, audit impossible.

Action: ouvrir un `TASK_ID` dedie avant toute correction.

### P0-2 - Kiosk promo preview/final mismatch

Observation:

- `PricingPreviewService` et `PromoController` prennent en compte `kiosk_promo_code`;
- `kioskCart.js` envoie `kiosk_promo_code`;
- `OrderRequest` ne semble pas valider ce champ;
- `FrontendOrderService::myOrderStore` travaille surtout avec `coupon_id` / `loyalty_code`;
- `PricingPreviewService::toObject()` peut perdre des quantites variations/extras.

Impact:

- le client peut voir une reduction en preview mais payer/creer une commande finale differente;
- risque comptable, support et perte de confiance;
- violation indirecte de la parite prix backend si la decision finale n'est pas la meme que la preview backend.

Correction cible:

- definir un contrat unique `pricing_context` pour preview et order create;
- accepter/normaliser `kiosk_promo_code` dans le request final ou le convertir explicitement en coupon/promotion interne;
- conserver quantites variations/extras dans preview;
- tests: meme panier + meme promo => meme totals preview/final.

### P0-3 - `paymentConfirm` frontend doit etre durci

Observation:

- `Frontend/OrderController::paymentConfirm` est sous `auth:sanctum`;
- il verifie que la commande appartient a l'utilisateur authentifie;
- il marque la commande `PAID` puis appelle `finalizePaidKioskOrder`;
- la verification forte `tokenCan('kiosk:order')` n'est pas visible dans la methode;
- la methode doit refuser explicitement les commandes non kiosk/non eligibles avant de muter `payment_status`.

Impact:

- un utilisateur web authentifie pourrait potentiellement marquer sa propre commande frontend comme payee avec un `transaction_id` arbitraire si aucune couche externe ne bloque;
- risque paiement, fraude, audit fiscal.

Correction cible:

- exiger token machine kiosk ou canal paiement backend confirme;
- refuser tout `order_type` non `KIOSK` selon la decision produit;
- refuser tout `payment_method` non carte/TR dans ce endpoint;
- verifier statut courant attendu;
- faire la validation avant toute mutation `payment_status`;
- tests d'abus: token client web, commande POS, commande cash, commande deja annulee.

### P0-4 - KDS/bar multi-ecran: concurrence incomplete

Observation:

- `KitchenDisplaySystemOrderService::changeStatus` compare le statut locke au statut observe par route binding;
- le client ne semble pas envoyer un `expected_status` explicite;
- le flux bump multi-ecrans peut produire des actions stale si l'ecran n'a pas le dernier etat;
- le systeme n'a pas encore de contrat clair station bar vs cuisine vs expo.

Impact:

- double bump;
- commande qui saute une etape;
- bar et cuisine non synchronises;
- UX "commande disparue" ou "deja traitee" non expliquee.

Correction cible:

- ajouter `expected_status` obligatoire sur mutation KDS;
- retourner `409 Conflict` avec etat courant si stale;
- definir stations: kitchen, bar, expo, all;
- separer item-level progress et order-level status si necessaire;
- tests concurrence HTTP et websocket/polling.

### P1-1 - POS idempotency catch non branch-scope

Observation:

- precheck idempotence POS est branche-scope;
- le catch `QueryException 23000` recherche `Order::where('idempotency_key', $idempotencyKey)->first()` sans `branch_id`.

Impact:

- en cas de collision rare mais possible, mauvaise commande retournee si cle identique entre branches.

Correction cible:

- ajouter `branch_id` au fallback;
- test collision cross-branch dans le catch SQL.

### P1-2 - `OrderStatusRequest` trop large pour toutes les surfaces

Observation:

- request partage;
- validation `integer`;
- permission differenciee mais pas de whitelist stricte par surface.

Impact:

- une surface peut tenter un statut qui ne lui appartient pas;
- la logique service refusera peut-etre, mais le contrat API reste flou.

Correction cible:

- `Rule::in(OrderStatus::values())`;
- request dedie KDS avec transitions autorisees KDS;
- request dedie kiosk cancel;
- request admin plus large mais auditee.

### P1-3 - KDS list vs orderItems mismatch

Observation:

- KDS list inclut `ACCEPT`, `PREPARING`, `PREPARED`;
- `orderItems` limite a `ACCEPT`, `PREPARING`.

Impact:

- une commande prepared peut etre visible dans une liste mais ses items refusent selon l'endpoint;
- incoherence station/expo.

Correction cible:

- decider si `PREPARED` doit etre detaille;
- aligner list/items;
- test d'API.

### P1-4 - Statuts hardcodes kiosk hors lint

Observation:

- composants kiosk postent `status: 16`;
- lint status actuel a passe mais ne couvre pas ces fichiers.

Impact:

- changement enum futur casse kiosk;
- violation invariant "pas de magic status".

Correction cible:

- importer enum frontend;
- etendre lint a `resources/js/components/frontend/**` et stores kiosk;
- test/lint obligatoire CI.

### P1-5 - Availability event avant commit potentiel

Observation:

- `AvailabilityService::toggle()` appelle `event(...)` dans une transaction;
- des listeners peuvent invalider cache/snapshot avant commit selon implementation.

Impact:

- rollback transaction mais cache/menu deja invalide ou bump inutile;
- incoherence temporaire kiosk/POS.

Correction cible:

- utiliser dispatch after commit de facon uniforme;
- tester rollback;
- auditer tous les listeners `ItemAvailabilityChanged`.

### P1-6 - DiningTable release incomplet

Observation:

- release libere la table;
- il faut verifier/renforcer le nettoyage `orders.dining_table_id`;
- emission `OrderTableChanged` sur release manuel a clarifier.

Impact:

- table libre mais commande encore liee;
- KDS/OSS/floorplan peuvent diverger.

Correction cible:

- release atomique table + commande;
- event table change;
- test floorplan -> order -> release -> OSS/KDS.

### P1-7 - Legacy kiosk contient logique non authoritative

Observation:

- `kiosk_implementation/` contient des snippets Flutter avec calcul taxe/total cote client, fallback branche, source POS.

Impact:

- futur developpeur peut copier ce code;
- confusion entre archive et source active.

Correction cible:

- ajouter bannieres `ARCHIVE_NON_AUTHORITATIVE`;
- exclure des builds;
- ajouter doc source officielle Vue/Laravel;
- scanner CI pour imports accidentels.

### P1-8 - POS UI paiement incomplet vs backend enum

Observation:

- backend supporte `TICKET_RESTAURANT`;
- `PaymentComponent.vue` visible semble surtout cash/card;
- tests backend peuvent couvrir ticket restaurant mais UX POS doit etre decidee.

Impact:

- fonctionnalite backend invisible;
- reporting fiscal/paiement incomplet si employee force autre chemin.

Correction cible:

- decider V1: ticket restaurant POS visible ou explicitement hors scope;
- si visible, ajouter UI, validation, receipt, reports;
- si hors scope, bloquer proprement cote request/permission.

### P1-9 - Multi-payment et remboursements partiels limites

Observation:

- `OrderDetailsResource` cree un `payments_breakdown` synthetique;
- rapports precedents indiquent absence de table durable `order_payments`;
- `PaymentService::cashBack` depend d'une transaction existante et cree un cashback, pas un ledger complet de tender lines.

Impact:

- split tender, partial refund, cashier reconciliation et audit fiscal limites;
- V1 peut etre acceptable si scope single-tender, mais doit etre declare.

Correction cible:

- V1: single tender explicite + controles;
- V2: table `order_payments` + refunds lines + reconciliation.

### P1-10 - Print/fiscal audit best effort a surveiller

Observation:

- receipt print et drawer sont separes;
- audit print peut etre best effort;
- `audit_emitted=false` doit etre une alerte, pas un detail ignore.

Impact:

- ecart ticket/fiscal/non-repudiation;
- diagnostic difficile en production.

Correction cible:

- metrics print success/failure;
- logs audit avec branch/order/printer/station;
- retry policy ou manual reprint trace.

### P2-1 - Dedupe realtime per-tab

Observation:

- dedupe frontend utilise `sessionStorage`;
- par definition, il est par onglet.

Impact:

- multi-device/multi-tab peuvent recevoir les memes events;
- acceptable si operations idempotentes, dangereux sinon.

Correction cible:

- verifier idempotence reducers frontend;
- ne pas compter sur dedupe UI pour integrite metier.

### P2-2 - Public assets compiles

Observation:

- `public/js/**` contient des bundles compiles;
- les sources sont dans `resources/js/**`.

Impact:

- patcher `public/js` directement cree drift build.

Correction cible:

- ne modifier que les sources;
- reconstruire assets selon pipeline;
- verifier que les bundles ne sont pas pris pour source.

### P2-3 - Audio/hardware direct a classifier

Observation:

- la spec kiosk demande `window.borne` pour hardware;
- certains composants OSS/waiting utilisent audio direct ou AudioContext.

Impact:

- selon surface, c'est acceptable ou violation spec;
- borne verrouillee doit eviter appels hardware directs.

Correction cible:

- classifier par surface: kiosk hardware vs OSS web display;
- mettre facade commune pour sons kiosk;
- tests browser sans permissions.

---

## 5. Breakdown des flux systeme

### 5.1 Flux POS cash

1. Caissier ajoute items dans POS.
2. Cart local branche/utilisateur via `posCart.js`.
3. Modal paiement `PaymentComponent.vue`.
4. Submit `posOrder.save` vers `admin/pos` avec `X-Idempotency-Key`.
5. `PosOrderRequest` valide structure.
6. `OrderService::posOrderStore`:
   - verifie branch ownership;
   - retire les champs financiers non fiables;
   - recalcul via `PricingService::forPos`;
   - cree commande status `ACCEPT`, payment_status `PAID`;
   - genere queue/fiscal sequence;
   - cree order items/snapshots/allergens;
   - applique coupon/discount autorise;
   - dispatch apres transaction.
7. Receipt print possible.
8. Cash drawer open via receipt printer.
9. KDS recoit `OrderCreated`.
10. Stock availability decremente.

Points critiques:

- idempotence cross-branch fallback;
- cash received/cash back audit;
- drawer open doit etre audite meme si print echoue;
- fiscal sequence doit rester monotone;
- prix uniquement backend.

### 5.2 Flux POS card

Similaire POS cash, avec `pos_payment_method=CARD`.

Points critiques:

- confirmation TPE POS selon scope;
- pas de marquage paye sans transaction si futur PSP;
- receipt doit indiquer card type/reference si fourni;
- pas de cash drawer automatique.

### 5.3 Flux POS ticket restaurant

Etat:

- enums backend existent;
- support UI POS a confirmer.

Decision V1:

- soit hors scope et bloque;
- soit complet avec UI, receipt, reports, refund rules et fiscal.

### 5.4 Flux kiosk cash

1. Machine kiosk login via `KioskMachineLoginController`.
2. Token Sanctum avec ability `kiosk:order`.
3. Cart kiosk sans `branch_id` client; branche resolue depuis machine.
4. Submit frontend order.
5. `FrontendOrderService::myOrderStore`:
   - idempotence branche machine;
   - recalcul prix backend;
   - cree commande;
   - cash peut etre `PAID` et auto-accepted selon logique observee.
6. POS/KDS voient commande kiosk cash.
7. Caissier peut collecter/traiter via panel POS/KDS.

Points critiques:

- ne jamais accepter branch_id client;
- status/payment_status initial doivent etre documentes;
- annulation kiosk doit utiliser enum;
- event order created/status doit etre coherent avec POS.

### 5.5 Flux kiosk card / ticket restaurant

1. Kiosk cree commande pending.
2. TPE/borne externe traite paiement.
3. Frontend appelle `paymentConfirm`.
4. Backend doit verifier token machine, order type, payment method, transaction reference, status attendu.
5. Backend marque paye et finalise via `finalizePaidKioskOrder`.
6. Commande passe `ACCEPT`, KDS recoit les signaux.

Point bloquant:

- `paymentConfirm` doit etre durci avant production.

### 5.6 Flux KDS cuisine

1. KDS liste commandes actives.
2. Realtime via outbox/Echo ou fallback `KdsSyncService`.
3. Chef bump status.
4. Backend lock commande et valide state machine.
5. Event `OrderStatusChanged`.
6. POS/OSS/KDS autres ecrans se mettent a jour.

Points critiques:

- expected status client;
- conflits 409;
- idempotence no-op;
- station ownership;
- alignement list/orderItems.

### 5.7 Flux bar

Le bar doit etre traite comme station de production, pas comme simple filtre visuel.

Questions a figer:

- quels items appartiennent au bar?
- statut item-level ou order-level?
- qui peut finaliser l'ordre si cuisine et bar ont deux tempos?
- l'expo valide-t-il la commande complete?
- comment afficher les items mixtes?

Plan cible:

- ajouter/clarifier `kds_station` dans item resource;
- definir `station_status` si necessaire;
- garder order status global pour client/OSS;
- eviter que bar marque toute la commande prepared si cuisine pas terminee.

### 5.8 Flux OSS / ecran client

1. OSS lit commandes en preparation/pretes.
2. Realtime ou polling.
3. Affiche queue/order number.
4. Son/flash selon transition.

Points critiques:

- readonly strict;
- branch scope;
- ne pas exposer donnees client inutiles;
- audio compatible kiosk/browser.

### 5.9 Flux table / floorplan

1. POS assigne table.
2. `DiningTableService` occupe table.
3. `OrderTableChanged` informe front.
4. Paiement/finalisation peut liberer table.
5. QR table routes publiques permettent commande table.

Points critiques:

- release doit nettoyer order relation;
- QR route doit etre throttlee et branch-safe;
- KDS doit voir table correcte;
- transfer table doit etre audite.

### 5.10 Flux disponibilite / 86

1. Admin/POS toggle availability.
2. Stock branch-scoped.
3. Event `ItemAvailabilityChanged`.
4. Menu kiosk/POS invalides.
5. Order create decremente.
6. Cancel/refund release.

Points critiques:

- dispatch after commit;
- cache rollback safe;
- locks autour stock;
- release qty idempotent.

### 5.11 Flux fiscal / Z report

1. Commande payee recoit sequence fiscale.
2. Audit chain/fingerprint stocke.
3. Receipt expose fiscal identity/sequence.
4. X report lecture, Z report cloture.
5. Deletion paid bloquee ou permissionnee + Z sealed check.

Points critiques:

- ne pas affirmer certification NF525;
- horodatage, chainage, inalterabilite;
- Z open max doit etre teste en charge;
- reprint et void doivent etre audites.

### 5.12 Flux offline kiosk

1. Kiosk conserve panier/idempotency.
2. Offline queue garde payload.
3. Rejoue quand reseau revient.
4. Backend idempotence doit eviter doublons.

Points critiques:

- ne pas recalculer prix offline comme verite;
- expiration panier/promo;
- revalidation availability/prix au replay;
- message utilisateur si total change.

---

## 6. Matrice de correction par cycles

### Cycle 0 - Ouverture chantier et gates

Objectif: cadrer officiellement le travail.

Taches:

- creer `TASK_ID` type `CAISSE-V1-CORE-AUDIT`;
- generer plan officiel depuis template;
- declarer sous-systemes touches;
- lister zones frozen et gates requis;
- extraire ce rapport dans `missions/<TASK_ID>/plan_excerpt.md`;
- creer `execute_brief.md` pour le premier lot seulement;
- definir test strategy.

Critere de sortie:

- plan valide humainement;
- aucune zone frozen modifiee sans gate;
- `SYMMETRY_NOTE` prevue si OrderService/FrontendOrderService touches.

### Cycle 1 - Contrats caches critiques

Objectif: fermer les P0/P1 qui peuvent casser paiement/prix/branche/statut.

Taches:

- durcir `paymentConfirm`;
- reparer parite `kiosk_promo_code` preview/final;
- corriger `PricingPreviewService::toObject()` pour quantites;
- branch-scope idempotency catch POS;
- strict enum validation dans requests;
- remplacer status `16` dans kiosk;
- etendre lint status kiosk;
- ajouter tests anti-abus.

Fichiers probables:

- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PricingPreviewService.php`
- `app/Services/OrderService.php`
- `app/Http/Requests/OrderStatusRequest.php`
- `resources/js/components/frontend/**`
- scripts lint status/pricing si necessaire
- tests feature/unit associes

Critere de sortie:

- preview/final totals identiques;
- non-kiosk token refuse;
- wrong order type/method refuse;
- cross-branch idempotency impossible;
- status lint couvre kiosk.

### Cycle 2 - KDS/bar concurrence et stations

Objectif: rendre KDS/bar fiable en multi-ecran.

Taches:

- ajouter `expected_status`;
- retourner `409` avec status courant;
- aligner `list` et `orderItems`;
- definir traitement `PREPARED`;
- documenter station bar/kitchen/expo;
- event reducer idempotent;
- tests concurrence.

Fichiers probables:

- `KitchenDisplaySystemOrderService.php`
- `KitchenDisplaySystemController.php`
- KDS requests/resources
- `resources/js/services/KdsSyncService.js`
- KDS components
- tests KDS.

Critere de sortie:

- double bump ne casse pas;
- stale UI recoit conflit clair;
- bar ne finalise pas cuisine par accident.

### Cycle 3 - Paiement POS, ticket, drawer, receipt

Objectif: rendre la caisse comptoir exploitable et auditable.

Taches:

- decision V1 sur ticket restaurant POS;
- UI POS pour tender autorises;
- drawer audit complet;
- receipt print success/failure metrics;
- cash variance et cash back visible;
- reconciliation daily cashier.

Fichiers probables:

- `PaymentComponent.vue`
- `PosComponent.vue`
- `OrderDetailsResource`
- `EscPosPrinterService`
- cash drawer controller/service
- fiscal reports/resources.

Critere de sortie:

- tender V1 explicite;
- print/drawer traces consultables;
- pas de tender backend invisible.

### Cycle 4 - Kiosk hardware/offline

Objectif: stabiliser borne production.

Taches:

- officialiser facade `window.borne` / `kioskHardware`;
- retirer hardware direct de la surface kiosk si present;
- offline replay avec revalidation prix/promo/availability;
- expirations panier/promo;
- TPE decline/cancel avec enum;
- login machine/token lifecycle tests.

Fichiers probables:

- kiosk Vue components;
- `kioskCart.js`;
- `KioskEventController`;
- hardware bridge docs;
- tests kiosk auth/offline.

Critere de sortie:

- borne ne fait pas de logique prix authoritative;
- machine token strict;
- offline replay previsible.

### Cycle 5 - Tables, floorplan, OSS

Objectif: eviter divergence table/KDS/client.

Taches:

- release table atomique;
- clear `orders.dining_table_id` si decision retenue;
- event table changed sur release;
- QR table branch-safe;
- OSS readonly et payload minimal.

Critere de sortie:

- table libre = aucune commande active attachee sauf exception explicite;
- OSS ne fuit pas infos client;
- floorplan/KDS synchrones.

### Cycle 6 - Outbox, observabilite, runbooks

Objectif: rendre la prod diagnosable.

Taches:

- dashboards queue/outbox lag;
- terminal failures `DispatchDomainEventsJob`;
- broadcast auth rejects;
- paymentConfirm denies;
- fiscal sequence anomalies;
- cache invalidation availability;
- runbook "KDS down", "printer down", "TPE down", "queue down".

Critere de sortie:

- chaque panne a signal + action;
- pas de panne silencieuse sur caisse.

### Cycle 7 - Legacy quarantine

Objectif: eviter confusion source/archive.

Taches:

- marquer `kiosk_implementation/**` comme archive non authoritative;
- marquer `borne (Remix)` docs comme spec/reference et non source active si c'est le cas;
- verifier aucun import/build actif depuis legacy;
- documenter source officielle frontend.

Critere de sortie:

- impossible de copier par erreur un exemple qui viole prix/branch_id.

### Cycle 8 - Validation production V1

Objectif: prouver que la V1 tient.

Taches:

- tests unitaires/feature critiques;
- Playwright flows POS cash/card, kiosk card/cash, KDS, OSS, table;
- tests multi-branch;
- tests offline/retry/idempotence;
- test load KDS sync et outbox;
- audit Claude terminal;
- gate humain.

Critere de sortie:

- `AUDIT_VERDICT: PASS`;
- gates signes;
- runbooks prets;
- aucun P0/P1 ouvert sans acceptation explicite.

---

## 7. Matrice de tests a creer ou renforcer

### 7.1 Prix et promo

Tests requis:

- preview kiosk avec variation quantity > 1 et extra quantity > 1;
- checkout final meme panier;
- totals identiques preview/final;
- promo kiosk valide appliquee final;
- promo expiree refusee final;
- coupon + kiosk promo priorite documentee;
- frontend ne peut pas envoyer total force;
- POS manual discount sans permission refuse.

### 7.2 Paiement

Tests requis:

- `paymentConfirm` refuse token non kiosk;
- refuse commande non kiosk;
- refuse payment method cash;
- refuse commande autre utilisateur;
- refuse commande annulee/rejected;
- idempotent si deja paid;
- transaction_id duplicate selon regle;
- POS cash requires received amount suffisant;
- POS card ne declenche pas drawer.

### 7.3 Branch isolation

Tests requis:

- idempotency key identique deux branches ne retourne jamais l'autre commande;
- KDS sync branch override admin seulement;
- kiosk token machine A ne subscribe pas branch B;
- printer branch isolation;
- floorplan table branch isolation;
- QR table ne permet pas branch spoof.

### 7.4 KDS/bar

Tests requis:

- `expected_status` stale => 409;
- double bump concurrent => un seul gagne;
- no-op same status ne dispatch pas event inutile;
- `orderItems` coherent avec list;
- item station bar filtre correctement;
- prepared order visible selon decision;
- polling fallback n'ecrase pas event plus recent.

### 7.5 Statuts

Tests requis:

- status request refuse valeur hors enum;
- kiosk cancel utilise enum frontend;
- lint status scanne kiosk;
- state machine refuse transitions interdites;
- RETURNED/CANCELED/REJECTED reasons selon policy.

### 7.6 Fiscal et audit

Tests requis:

- sequence fiscale monotone par branche;
- Z report sealed bloque suppression paid;
- receipt contient fiscal sequence;
- reprint audite;
- audit chain fingerprint present;
- X report lecture ne cloture pas;
- Z report double close refuse.

### 7.7 Tables / OSS

Tests requis:

- occupy table lie commande;
- transfer table change event;
- release table nettoie relation commande selon decision;
- POS paid release table;
- OSS readonly;
- OSS ne montre pas donnees sensibles.

### 7.8 Availability

Tests requis:

- order decrement branch stock;
- cancel release idempotent;
- refund release idempotent;
- toggle rollback ne laisse pas cache/snapshot incoherent;
- item unavailable refuse checkout;
- parked order recall revalide availability.

### 7.9 Legacy/build

Tests requis:

- aucun import actif depuis `kiosk_implementation/**`;
- aucun patch direct requis dans `public/js/**`;
- build regenere assets;
- scanner detecte prix client dans sources actives, pas archives marquees.

---

## 8. Validation deja executee pendant ce rapport

Commandes executees:

- `npm run pos:lint:pricing`
- `npm run pos:lint:status`

Resultats:

- pricing lint: PASS sur le perimetre scanne, avec avertissement connu `@pricing-allowed-block` dans `PosComponent.vue` valide jusqu'au 2026-05-10;
- status lint: PASS sur son perimetre actuel;
- limite importante: le lint status actuel ne capture pas tous les usages kiosk observes, notamment les `status: 16` dans les composants frontend kiosk.

Tests non executes:

- PHPUnit complet;
- Vitest complet;
- Playwright;
- build frontend complet.

Raison:

- ce rapport est une consolidation d'audit, pas un cycle d'implementation;
- lancer la validation complete doit etre fait dans le cycle officiel du chantier.

---

## 9. Audit des rapports precedents et signaux indirects

Les rapports anciens ne doivent pas etre ignores. Ils contiennent des signaux qui ne remontent pas toujours depuis le code actif.

### 9.1 `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`

Signaux a conserver:

- double commande si nouvelle idempotency key par ouverture de modal;
- admin KDS omniscient;
- docs business possiblement obsoletes;
- risques Z.open MAX;
- cache/Redis prod;
- absence durable `order_payments`.

### 9.2 `AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md`

Signaux a conserver:

- KDS/OSS/drawer doivent etre audites ensemble;
- pas seulement un flow POS;
- drawer et receipt sont des surfaces fiscales/operationnelles;
- bar/stations doivent etre modelises.

### 9.3 `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`

Signaux a conserver:

- remboursement partiel non complet;
- multi-payment non durable;
- cashback/transaction insuffisant pour reconciliation long terme.

### 9.4 `AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`

Signaux a conserver:

- readiness technique ne vaut pas certification;
- documents de preuve, audit chain, Z, reprint, void doivent etre traites comme domaine de conformite.

### 9.5 `AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`

Signaux a conserver:

- POS/KDS/OSS/kiosk doivent etre analyses comme un systeme synchronise;
- websocket seul ne suffit pas;
- polling fallback doit respecter version et branch.

### 9.6 Archives kiosk et specs hardware

Signaux a conserver:

- specs borne utiles pour hardware, PMR, 1080x1920, TPE, printer, scanner;
- snippets legacy dangereux si utilises comme source active;
- facade hardware obligatoire pour borne.

---

## 10. Definition de done V1 production

La V1 caisse peut etre consideree candidate production seulement si toutes les conditions suivantes sont vraies:

1. Prix backend uniquement, prouve par tests preview/final/POS/kiosk.
2. Aucun status magique dans sources actives scannees.
3. `paymentConfirm` durci contre token non kiosk, order type invalide, payment method invalide.
4. Idempotence POS/kiosk branche-scope dans precheck et catch SQL.
5. KDS multi-ecran gere les conflits stale avec `expected_status`.
6. Bar/station a un contrat explicite, meme minimal.
7. Kiosk promo/coupon/loyalty ont une regle unique preview/final.
8. Table/floorplan release ne laisse pas d'etat contradictoire.
9. Outbox/queue prod surveillees.
10. Receipt/drawer/fiscal audit traces.
11. Legacy non-authoritative marque et isole.
12. Tests critiques passent.
13. Audit Claude terminal donne `PASS`.
14. Gate humain signe les limites V1 restantes: NF525, split tender, refund partiel, ticket restaurant POS si non livre.

---

## 11. Backlog priorise

### Must fix before V1

- Durcir `paymentConfirm`.
- Corriger promo preview/final.
- Corriger quantites preview variations/extras.
- Branch-scope catch idempotence POS.
- Remplacer status `16` kiosk.
- Etendre lint status.
- Ajouter enum validation stricte.
- Ajouter KDS `expected_status`.
- Align KDS list/orderItems.
- Clarifier ticket restaurant POS.
- Ajouter tests P0/P1.

### Should fix before pilot restaurant

- Dining table release complet.
- Availability event dispatch standardise.
- Print/drawer audit metrics.
- Kiosk offline replay UX si prix change.
- Station bar minimale.
- Legacy quarantine.

### Can defer if gate explicite

- Table durable `order_payments`.
- Refund partiel complet.
- Certification NF525 externe.
- Admin KDS global restriction UX avancee.
- Dedupe cross-tab global.
- Load test 10k commandes si pilot limite.

---

## 12. Checklists d'execution pour l'equipe

### Checklist avant de coder

- Lire `AGENTS.md`.
- Lire `.cursor/ACTIVE_CYCLE.md`.
- Ouvrir `run-cycle <TASK_ID>`.
- Verifier `plans/PLAN_<TASK_ID>_<date>.md`.
- Verifier `missions/<TASK_ID>/execute_brief.md`.
- Identifier frozen zones.
- Ecrire `SYMMETRY_NOTE` si OrderService ou FrontendOrderService touches.
- Lister tests exacts.

### Checklist avant commit technique

- Pas de prix metier frontend.
- Pas de status magique.
- Pas de branch_id client trust.
- Pas de dispatch important avant commit.
- Pas de modification `public/js` comme source.
- Pas de legacy copie dans actif.
- Pas de migration/frozen sans gate.
- Tests locaux pertinents passent.

### Checklist avant audit

- Rapport execution rempli.
- `EXECUTE_DELEGATION` trace.
- Lints pricing/status executes.
- Tests feature executes.
- Screenshots/Playwright si plan demande.
- Risques restants listes.
- Audit Claude terminal lance ou fallback documente.

---

## 13. Plan de fichiers probables par chantier

### Contrat paiement/promo/prix

- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PricingPreviewService.php`
- `app/Services/KioskPromoService.php`
- `resources/js/stores/kioskCart.js`
- tests frontend order/pricing/promo.

### POS core

- `app/Services/OrderService.php`
- `app/Http/Requests/PosOrderRequest.php`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/stores/posOrder.js`
- `resources/js/stores/posCart.js`
- tests POS order/payment.

### KDS/bar/sync

- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Services/KdsSyncService.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Http/Controllers/Admin/KdsSyncController.php`
- `app/Http/Resources/KDSOrderDetailsResource.php`
- `app/Http/Resources/KDSOrderItemsResource.php`
- `resources/js/services/KdsSyncService.js`
- KDS components/tests.

### Tables/OSS

- `app/Services/DiningTableService.php`
- `app/Http/Controllers/Admin/Pos/FloorplanController.php`
- `app/Events/OrderTableChanged.php`
- OSS controllers/components/tests.

### Outbox/realtime

- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Support/Realtime/EventContract.php`
- `resources/js/common/realtime/eventContract.js`
- listeners `Persist*ToOutbox.php`
- tests event contract.

### Fiscal/printing

- `app/Services/FiscalSequenceService.php`
- `app/Services/EscPosPrinterService.php`
- `app/Http/Controllers/Admin/PosReceiptPrintController.php`
- fiscal report controllers/resources/tests.

### Legacy quarantine

- `kiosk_implementation/**`
- `borne (Remix)/docs/design/**`
- documentation source officielle.

---

## 14. Recommandation finale

Ne pas commencer par "refaire l'interface caisse". Le premier vrai chantier doit etre le noyau de contrats:

1. paiement confirme uniquement par acteur autorise;
2. prix preview/final identiques;
3. idempotence branche-safe;
4. statuts sans magie;
5. KDS conflict-safe.

Apres seulement, construire les ameliorations UX POS/KDS/bar devient beaucoup moins risque. Sinon l'UI peut masquer des incoherences metier qui deviendront tres couteuses en production: double commande, mauvais paiement, mauvaise branche, mauvaise promo, station cuisine/bar non synchronisee, ou ticket fiscal difficile a justifier.

Ce fichier est le rapport unique de depart pour le chantier caisse V1. Toute execution doit en extraire un plan borne, pas modifier tout le systeme en une seule passe.

---

## 15. Addendum ultra deep - fichiers caches et indirects ajoutes

Cet addendum renforce le rapport avec les zones qui ne sont pas naturellement visibles quand on regarde seulement `routes/api.php`, les services principaux ou les composants POS/KDS. Ces fichiers sont pourtant capables de casser la caisse en production: configuration runtime, scheduler, CI, lints, migrations historiques, seeders, middlewares, archives, docs de transfert et scripts d'orchestration.

### 15.1 Config runtime qui influence directement la caisse

| Fichier | Detail observe | Risque caisse | Action V1 |
| --- | --- | --- | --- |
| `config/kiosk.php` | Auto-login borne via `KIOSK_MACHINE_USERNAME` / `KIOSK_MACHINE_PASSWORD`; fallback local seulement; `max_item_qty`; `order_rate_limit`; `menu_pricing` ratios | Confusion entre config affichage et prix authoritative; credentials auto-login mal configures en prod | Gate env: pas de fallback prod, credentials secrets, `menu_pricing` non utilise comme calcul final |
| `config/pricing.php` | `PRICING_USE_SSOT=true` par defaut; fallback legacy possible si false | Un `.env` prod errone peut rebasculer sur ancien calcul | Preflight doit bloquer ou alerter `PRICING_USE_SSOT=false` en prod |
| `config/fiscal.php` | Secrets HMAC audit/Z report requis; sentinels dev refuses en prod; retention 6 ans | Fiscal chain forgeable si secret faible; archive non probante | Preflight obligatoire + rotation runbook |
| `config/sanctum.php` | Token expiration defaut 480 min | Kiosk/staff token trop long ou trop court selon exploitation | Definir duree prod explicite et rotation machine |
| `config/broadcasting.php` | `default => env('BROADCAST_DRIVER')` sans fallback | Si env absent, KDS/OSS realtime mort; AppServiceProvider fail-fast prod | Preflight prod + test health broadcast |
| `config/queue.php` | defaut local `sync`; prod doit etre redis/database | Jobs inline = latence POS/KDS, FCM peut bloquer | Preflight prod + supervisor/worker |
| `config/cache.php` | cache driver impacte locks audit chain et menu cache | `array/null` casse locks multi-workers | Preflight prod et cache round-trip |
| `config/logging.php` | canaux `security`, `observability`, `fiscal` | Alertes invisibles si logs non collectes | Runbook SIEM/log retention |
| `config/cors.php` | surface SPA/API | Mauvais CORS peut exposer ou bloquer borne/POS | Revue domaine prod/staging |

Constat important: la caisse depend autant de `.env` que du code. Une V1 "qui marche local" peut etre inutilisable en restaurant si `BROADCAST_DRIVER`, `QUEUE_CONNECTION`, `CACHE_DRIVER`, `FISCAL_*`, `SANCTUM_TOKEN_EXPIRATION`, `KIOSK_*` ou `PRICING_USE_SSOT` sont mal poses.

### 15.2 Middleware, providers et boot guards

| Fichier | Role cache | Risque | Action |
| --- | --- | --- | --- |
| `app/Providers/AppServiceProvider.php` | Fail-fast prod sur broadcast/queue/cache + bindings printer | Si contourne, prod silencieuse degradee | Ajouter au gate V1 une execution `app:preflight-production` |
| `app/Providers/RouteServiceProvider.php` | Rate limit kiosk orders depuis `config(kiosk.order_rate_limit)` | Throttle trop bas bloque borne; trop haut expose abus | Test par env + monitoring 429 |
| `app/Providers/EventServiceProvider.php` | Map OrderCreated/Status/Table/Availability vers listeners | Listener ajoute peut casser post-commit | Audit a chaque ajout listener |
| `app/Http/Middleware/ValidateKioskLocale.php` | Verifie locale demandee contre locales branche machine | Borne branch A peut demander locale non configuree; logs observability | Inclure dans tests multi-branch kiosk |
| `app/Http/Middleware/CorrelationIdMiddleware.php` | Injecte `X-Correlation-ID`, logs user/branch | Requetes POS/KDS doivent etre tracables bout en bout | Exiger correlation dans rapports incidents |
| `app/Http/Middleware/ApiKeyMiddleware.php` | Potentiel endpoint protege par cle | Mauvais usage peut bypass auth normale | Inclure dans inventaire routes |
| `app/Http/Middleware/VerifyCsrfToken.php` | Exclusions paiement | Gateway callback doit rester limite | Revue callbacks payment |

Risque indirect: si une requete caisse n'a pas de correlation id coherent, l'audit incident devient lent. Pour la V1, chaque mutation critique devrait pouvoir etre suivie par `correlation_id`: order create, payment confirm, KDS status change, drawer open, print, fiscal close, table release.

### 15.3 Scheduler, jobs et commandes console

| Fichier | Role | Risque cache | Action V1 |
| --- | --- | --- | --- |
| `app/Console/Kernel.php` | Planifie outbox rescue, stale kiosk cleanup, parked purge, SLO, fiscal archive | Si scheduler absent en prod, commandes stale/archives/outbox rescue ne tournent pas | Gate: cron/scheduler actif, `onOneServer` avec cache durable |
| `app/Jobs/CleanupStalePendingKioskOrders.php` | Rejette commandes kiosk pending > 15 min, dispatch status/cancel | Peut annuler commande encore en paiement lent; depend de source_surface/order_type | Tester seuil TPE, UX "paiement en cours" |
| `app/Console/Commands/PosPurgeParkedOrders.php` | Purge parked orders > 24h | Perte panier attendu si restaurant compte sur parked long | Configurer TTL et message UI |
| `app/Console/Commands/PreflightProductionCommand.php` | Gate env prod: APP_DEBUG, APP_KEY, timezone, cache, queue, broadcast, fiscal, DB/cache | Si non execute avant deploy, panne runtime | Ajouter commande au checklist de sortie |
| `app/Console/Commands/FiscalArchiveCommand.php` | Archive NF525 | Si rate/permission/secret KO, preuve fiscale incomplete | Monitorer sorties non-zero |
| `app/Console/Commands/EnsureKioskMachineCommand.php` | Provision machine kiosk | Risque credentials/machine mal liee | Runbook onboarding borne |
| `app/Console/Commands/SimulateKioskOrders.php` | Simulation | Ne doit jamais etre confondu avec flux prod | Marquer usage dev/test |

Point profond: le scheduler est une partie de la caisse. Sans lui, les commandes kiosk pending, la purge parked, l'outbox rescue, les SLO et les archives fiscales peuvent deriver alors que l'UI semble fonctionner.

### 15.4 Migrations historiques a ne pas oublier

| Migration | Pourquoi elle compte |
| --- | --- |
| `2026_03_25_002938_add_idempotency_key_to_orders_table.php` | Ancien index unique standalone sur `idempotency_key` |
| `2026_04_18_140003_scope_idempotency_key_to_branch.php` | Migration vers unique composite `branch_id + idempotency_key` |
| `2026_04_15_200000_create_domain_events_table.php` | Backbone outbox realtime |
| `2026_04_15_230000_create_order_status_transitions_table.php` | Audit lifecycle status |
| `2026_04_15_230200_v1_soft_deletes_and_deletion_log.php` | Soft delete + deletion audit |
| `2026_04_20_210000_create_printers_table.php` | Printers branch/station |
| `2026_04_20_210100_create_dining_table_audit_logs_table.php` | Audit floorplan/table |
| `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php` | Sequence fiscale par order |
| `2026_04_22_000002_create_audit_logs_table.php` | Chain audit fiscal |
| `2026_04_22_000003_create_z_reports_table.php` | Clotures Z |
| `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` | Anti-fork audit chain |
| `2026_04_18_120005_create_kiosk_promos_table.php` | Promo kiosk |
| `2026_03_26_075918_create_loyalty_transactions_table.php` | Ledger loyalty |
| `2026_04_23_100000_add_release_tracking_to_order_items.php` | Stock release idempotent |

Risque cache: l'etat final de la base compte plus que l'intention du code. Exemple concret: le code peut etre branch-safe, mais si une migration d'un environnement n'a pas remplace l'index standalone par le composite, la prod peut encore refuser des idempotency keys valides entre branches.

Action V1:

- executer `php artisan migrate:status` en prod/staging;
- verifier index reels DB, pas seulement fichiers migration;
- ajouter dans le rapport de gate une preuve `SHOW INDEX FROM orders` ou equivalent;
- interdire deploy si `orders_idempotency_key_unique` standalone existe encore sans composite.

### 15.5 CI, lints et leurs angles morts

| Fichier | Ce qu'il protege | Angle mort actuel |
| --- | --- | --- |
| `.github/workflows/phpunit.yml` | Invariants grep, MySQL 8, migrations, PHPUnit complet, surface filtering | Queue/cache en test volontairement differents de prod; Playwright separe |
| `.github/workflows/vitest.yml` | `pos:lint:status`, `pos:lint:pricing`, Vitest | status lint limite POS/KDS; kiosk status magic peut passer |
| `.github/workflows/playwright.yml` | E2E opt-in label/manual/main | Pas automatique sur toutes PR; historique CI ecran blanc |
| `tools/lint/pos_orderstatus_guard.mjs` | Interdit entiers status dans POS/KDS | Commentaire dit "Kiosk SFCs dedicated W1"; donc kiosk non couvert |
| `tools/lint/pos_pricing_guard.mjs` | Interdit calcul prix dans POS/KDS/kiosk SFC | Ne couvre pas helpers/stores JS hors SFC ni archives |
| `scripts/check-invariants.sh` | Greps invariants backend | Heuristique; doit etre completee par tests |
| `scripts/post-execute-guard.sh` | Trace execution | Ne remplace pas audit humain/Claude |

Action V1:

- etendre status guard a `resources/js/components/frontend/kiosk` et aux stores pertinents;
- etendre pricing guard aux stores/helpers kiosk si les calculs y vivent;
- ajouter mode "source active only" pour ignorer archives marquees et `public/js`;
- rendre Playwright obligatoire sur PR caisse via label/gate;
- ajouter test CI qui echoue si `PRICING_USE_SSOT=false` en prod-like.

### 15.6 Seeders, factories et comptes de test

| Zone | Fichiers | Risque |
| --- | --- | --- |
| Kiosk machine | `database/seeders/KioskMachineTableSeeder.php`, `database/factories/KioskMachineFactory.php` | Credentials connus locaux, ne pas reutiliser prod |
| Roles/permissions | `PermissionTableSeeder`, `RolePermissionTableSeeder`, tests role seeder | Permission manquante POS/KDS/fiscal provoque faux diagnostic UI |
| Payment gateways | `PaymentGateway*Seeder` | Gateway active/inactive influence checkout |
| KDS seed | `KdsOrderTableSeeder` | Donnees demo peuvent masquer bugs realistes |
| Order seed | `OrderTableSeeder*`, `OrderItemTableSeeder` | Donnees non conformes aux nouveaux invariants |
| Coupon seed | `CouponTableSeeder`, factories | Promo/coupon doivent couvrir branche et expiration |
| Menu seed | `CompleteFrenchMenuSeeder`, `GrillHouseMenuSeeder`, multi variation fixtures | Prix/menu config peuvent diverger du catalogue prod |

Action V1:

- distinguer "seed demo", "seed test", "seed prod minimal";
- aucun credential borne connu en prod;
- tests d'invariants sur seeders critiques: roles, permissions, payment gateways, kiosk machine, taxes.

### 15.7 Documents et archives qui peuvent mentir au developpeur

| Zone | Risque | Decision |
| --- | --- | --- |
| `_archive/ignored_legacy_web_orchestration/**` | Anciennes affirmations sur broadcast/queue peuvent etre obsoletes | Lire comme historique seulement |
| `docs/HANDOFF_NEW_CURSOR/**` | Bons rappels mais parfois anterieurs a corrections | Revalider contre code |
| `docs/REALTIME_SETUP.md` | Peut dire `QUEUE_CONNECTION=sync` suffisant pour certains events | Pour V1 caisse, preflight prod gagne |
| `docs/API_KIOSK.md` | Contrat kiosk utile mais doit suivre code actuel | Reconciliation docs/code dans cycle |
| `reports/audit-orchestration/**` | Audits anciens parfois deja fixes | Ne pas recopier sans verifier |
| `public/js/**` | Bundles compiles, pas source | Ne jamais patcher directement |
| `storage/debugbar/**` | Artefacts dev, peuvent exposer traces locales | Exclure audit produit |
| `node_modules/**` | Dependances vendor | Hors audit sauf CVE/deps |
| `borne (Remix)/**` | Spec/design ancienne ou parallele | Reference hardware, pas source active |
| `kiosk_implementation/**` | Snippets Flutter legacy avec prix client | Archive non-authoritative obligatoire |

Regle: le code actif gagne contre la doc; le schema DB reel gagne contre les migrations; le plan actif gagne contre les anciennes conversations; les rapports anciens servent a chercher des risques, pas a prouver l'etat courant.

---

## 16. Matrice "coin cache -> panne possible -> detection"

| Coin cache | Panne possible en restaurant | Detection recommandee |
| --- | --- | --- |
| `BROADCAST_DRIVER` absent | KDS/OSS/POS ne recoivent pas temps reel | Preflight prod + smoke websocket |
| `QUEUE_CONNECTION=sync` | Creation commande lente, notifications bloquent HTTP | Preflight + SLO latency order create |
| Scheduler absent | Pending kiosk jamais nettoye, fiscal archive non faite | Health cron + dernier run par job |
| Cache driver non durable | Locks audit chain non fiables, `onOneServer` inefficace | Preflight cache round-trip + lock test |
| `PRICING_USE_SSOT=false` | Retour calcul legacy | Preflight + test config prod-like |
| Token Sanctum trop long | Machine perdue garde acces | Rotation/revoke kiosk tokens |
| Token Sanctum trop court | Borne se deconnecte en service | Monitoring 401 kiosk |
| Kiosk locale middleware | Borne bloque commandes sur locale non allowlist | Logs `kiosk_locale_rejected` |
| Rate limit kiosk | Rush midi en 429 | Metrics 429 par route |
| Public bundle stale | Code source corrige mais UI ancienne | Hash mix-manifest + build artifact |
| Migration index incomplete | Idempotence cross-branch casse | DB index proof |
| Seed permission incomplet | Caissier ne voit pas POS/KDS | Test role permission |
| Outbox rescue non tourne | Events stuck | Domain events pending/failed dashboard |
| Cleanup pending trop agressif | Paiement TPE lent annule commande | Test TPE timeout > 15 min ou seuil ajuste |
| POS parked purge | Panier mis en attente disparu | UI TTL visible + audit purge |
| Fiscal archive fail | Preuve quotidienne absente | Log fiscal non-zero + alerte |
| Debugbar/storage artefacts | Confusion ou fuite locale | Exclusion prod + APP_DEBUG=false |
| Docs anciennes | Correction faite mais doc dit inverse | Reconciliation doc/code |
| Legacy Flutter | Prix client recopie | Banner archive + CI import scan |

---

## 17. Fichiers a ajouter a la prochaine mission officielle

Ce rapport recommande que le premier `execute_brief.md` du chantier caisse liste explicitement ces fichiers indirects, meme si le patch final n'en touche qu'une partie. Ils doivent etre lus au debut de la mission pour eviter les angles morts.

### 17.1 Lecture obligatoire cycle CAISSE-V1-CORE-CONTRACTS

- `AGENTS.md`
- `.cursor/ACTIVE_CYCLE.md`
- `plans/PLAN_<TASK_ID>_<date>.md`
- `missions/<TASK_ID>/graphiti_context.md` si disponible
- `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md`
- `routes/api.php`
- `routes/channels.php`
- `config/kiosk.php`
- `config/pricing.php`
- `config/fiscal.php`
- `config/sanctum.php`
- `config/broadcasting.php`
- `config/queue.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/RouteServiceProvider.php`
- `app/Providers/EventServiceProvider.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Http/Requests/OrderStatusRequest.php`
- `app/Http/Requests/PosOrderRequest.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/OrderService.php`
- `app/Services/PricingPreviewService.php`
- `app/Services/PricingService.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `tools/lint/pos_orderstatus_guard.mjs`
- `tools/lint/pos_pricing_guard.mjs`

### 17.2 Lecture obligatoire cycle CAISSE-V1-PROD-OPS

- `app/Console/Kernel.php`
- `app/Console/Commands/PreflightProductionCommand.php`
- `app/Jobs/CleanupStalePendingKioskOrders.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Console/Commands/FiscalArchiveCommand.php`
- `app/Console/Commands/PosPurgeParkedOrders.php`
- `docs/PRODUCTION_SETUP.md`
- `docs/QUEUE_WORKER_SETUP.md`
- `docs/FISCAL_SECRETS.md`
- `docs/KIOSK_DEPLOYMENT.md`
- `.github/workflows/phpunit.yml`
- `.github/workflows/vitest.yml`
- `.github/workflows/playwright.yml`

### 17.3 Lecture obligatoire cycle CAISSE-V1-LEGACY-QUARANTINE

- `borne (Remix)/docs/design/KIOSK_HARDWARE_SPEC.md`
- `borne (Remix)/docs/design/KIOSK_HARDWARE_CALLS.md`
- `kiosk_implementation/**`
- `_archive/ignored_legacy_web_orchestration/**` uniquement pour verifier risques historiques
- `public/mix-manifest.json`
- `webpack.mix.js`
- `resources/views/master.blade.php`
- `resources/views/admin-pos-v4.blade.php`

---

## 18. Nouvelles actions ajoutees au master plan

### Action A - Preflight prod comme gate caisse

Ajouter au cycle de sortie V1:

- `APP_ENV=production php artisan app:preflight-production --strict` sur staging/prod-like;
- preuve que `BROADCAST_DRIVER`, `QUEUE_CONNECTION`, `CACHE_DRIVER`, `SESSION_DRIVER`, `FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`, `APP_DEBUG`, `APP_KEY`, timezone sont corrects;
- preuve que DB/cache sont joignables.

### Action B - Verifier scheduler et workers

Avant pilot:

- scheduler Laravel actif chaque minute;
- workers high/default/notifications actifs;
- `foodking:outbox:rescue` tourne;
- `CleanupStalePendingKioskOrders` tourne;
- fiscal archive quotidienne tourne;
- `SloEvaluatorJob` tourne.

### Action C - Etendre les guards CI

Avant correction UI:

- status guard inclut kiosk SFC et stores;
- pricing guard inclut helpers/stores actifs;
- guard ignore seulement archives marquees;
- ajout check "no import from kiosk_implementation";
- ajout check "no direct edit public/js as source".

### Action D - Verifier migrations reelles

Avant production:

- index composite `orders(branch_id, idempotency_key)` existe;
- ancien unique standalone supprime;
- `domain_events`, `order_status_transitions`, `audit_logs`, `z_reports`, `printers`, `dining_table_audit_logs`, `item_branch_availability` existent;
- MySQL 8 utilise en CI pour JSON/surface filtering.

### Action E - Declarer les archives

Avant onboarding d'une equipe:

- bannieres dans `kiosk_implementation/**`;
- bannieres dans docs Remix si non source active;
- README "source officielle caisse";
- docs anciennes marquees "historique".

---

## 19. Checkpoint final renforce

Le rapport couvre maintenant trois couches:

1. Couche fonctionnelle: POS, kiosk, KDS, bar, OSS, fiscal, table, payment, promo, loyalty.
2. Couche technique cachee: configs, middleware, providers, jobs, scheduler, migrations, CI, lints, seeders.
3. Couche memoire/risque: rapports anciens, docs, archives, bundles compiles, dossiers legacy.

La prochaine etape ne doit pas etre une modification massive du code. La bonne suite est:

1. ouvrir un cycle officiel `CAISSE-V1-CORE-CONTRACTS`;
2. extraire uniquement les P0/P1 du rapport;
3. corriger par lots courts;
4. valider avec PHPUnit/Vitest/lints;
5. lancer audit Claude terminal;
6. seulement ensuite attaquer UI/stations/bar/ops.

Ce fichier reste la sortie unique consolidee pour la demande.
