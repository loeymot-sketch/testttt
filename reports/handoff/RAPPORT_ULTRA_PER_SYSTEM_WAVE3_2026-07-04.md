# ULTRA A→Z — Wave 3 : AUDIT PAR SYSTÈME ULTRA-PROFOND — 2026-07-04
**Goal** (Stop-hook : « chaque système vraiment ultra profond, A→Z ») : 6 systèmes backend live non encore
audités ultra-profond ce campaign (stock · delivery · cash/Z · Uber · OSS · split-payment/terminals),
adversaire + raisonnement + refute-by-default. Workflow 30 agents, 0 erreur, 3.08M tokens. HEAD `3184e5768`.

## 1. RÉSULTAT — 18 confirmés, 6 réfutés. **2 HEALÉS live (dont le déblocage split cash+card), 16 documentés.**
Santé par système (résumés adversaires) : stock **cœur physique SAIN** (décrément inline lockForUpdate,
idempotency_key UNIQUE), delivery **globalement sain** (fee server-authoritative, flux livreur verrouillé),
cash/Z **chaîne HMAC INTACTE** (23 Z, 0 erreur, gap-free), Uber **architecturalement correct mais INERTE**
(pas go-live-ready), OSS **lecture bien durcie**. Les défauts sont dans les sous-chemins.

## 2. HEALÉS (live, non-frozen, TDD, frozen 0, commités)
| Commit | Sév | Système | Le bug + le fix |
|---|---|---|---|
| `3184e5768` | **P2** | split-payment | **AUCUN split cash+card n'aboutissait à la caisse.** Le frontend frozen envoie `pos_payment_method`=mode DOMINANT + `pos_received_amount`=tendered PARTIEL cash + `terminal_id` PAR TRANCHE → 3 gardes single-tender se déclenchaient sur le champ dominant : cash-dominant → garde cash `OrderService:1071` (partiel 15 < total 25) ; card-dominant → note 4-chiffres `PosOrderRequest:129` + `terminal_id required_if :141`. Les 3 gatées sur l'absence de `payment_breakdown`. `SplitPaymentService::validateBreakdown` garantit déjà somme≥total. **100% serveur — PaymentComponent.vue frozen intouché.** Les tests existants masquaient le bug (envoyaient le total complet) → 2 tests au payload frontend RÉEL. |
| `7e032d5a2` | doc | stock (visuel) | URL §6 `/admin/stock-rupture-dashboard` → 404 (SPA catch-all) ; vraie route `/admin/stock/rupture`. Doc corrigé (menait chaque session au 404). Dashboard « Gestion Produits & Stock » sain (toggle dispo temps-réel). |

## 3. DOCUMENTÉS — FROZEN (ZReportService §7/§8 → owner-gate + LOCK, propose-sans-appliquer)
| Sév | Le problème | Fix proposé |
|---|---|---|
| **P2** | **Ventes PAID hors de tout Z signé** (`ZReportService:231`). La fenêtre Z est bornée par `opened_at`, PAS chaînée au `closed_at` du Z précédent → l'intervalle (close, next-open] n'est couvert par aucun Z (+ orphelins de règlement : créée avant close, scellée après). **Déjà owner-triagé « P0 #1 detect-only »** (VerifyZMembershipCommand) + atténué par crons close/open (~2 min/nuit) ; magnitude 2505 = **artefact dev** (pas de scheduler dev → trous multi-jours). HMAC INTACTE (fuite de couverture, pas de falsification). | Chaîner `from = prev.closed_at` (temporel) + fenêtrer par instant de scellé fiscal (règlement). Change les agrégats SIGNÉS → LOCK + gate + triple-vert. |
| **P2** | **`total_by_method` (ventilation SIGNÉE) fausse en split** (`ZReportService:666`). Le total intégral va dans le bucket `pos_payment_method` unique, ignore les tranches `order_payments` → X-carte/X-espèces faux pour un split (1 seule commande split en base). `total_ttc` juste. **Déjà LOCK owner-approuvé M6-002 (LOCK_ZREPORT_SPLIT_BUCKETING), NON appliqué sur cette branche.** Vérité par-tranche préservée+signée dans `order_payments`+`audit_logs`. | Dériver byMethod en sommant `order_payments` par mode ; fallback `pos_payment_method` sans tranche. Appliquer le LOCK M6-002 existant. |

## 4. DOCUMENTÉS — DÉCISION OWNER (non-frozen, fix prêt)
| Sév | Le problème | Fix |
|---|---|---|
| P3 | **Livraison offerte ≥30€ appliquée web/kiosk mais PAS au POS** (`OrderService::posOrderStore:1053`) → même panier livraison gratuit sur le site, facturé 4€ au comptoir/téléphone. (= confirmé aussi en Wave 2.) NB adversaire : `OrderService:568 myOrderStore` = DEAD code ; le vrai web passe par `FrontendOrderService` (correct). | Décision : promo ≥30€ tous-canaux (répliquer le waiver au POS via un `DeliveryFeeService::applyFreeThreshold` partagé) OU web-only (documenter l'exclusion). |
| P3 | **Réconciliation caisse livreur SANS garde-fou de variance** (`DeliveryBoyCashSessionService:234`) — scelle RECONCILED inconditionnel, là où le POS (`CashDrawerService`) exige motif+approbation manager >seuil 2€. Adversaire : atteignable **admin-only** (pas livreur), variance déjà auditée dans la chaîne signée → dette = **docblocks faussement affirmatifs + catch mort** qui prétendent un contrôle inexistant. | Porter la garde `CashDrawerService::reconcileSession` (le catch existe déjà, devient vivant) OU corriger les docblocks trompeurs + retirer le catch mort. |
| P3 | **Fee livraison dérivé de coordonnées CLIENT non vérifiées** (`AddressService:103` : validation de plage seule, 0 géocodage serveur) → spoof lat/lng ≈ branche → distance≈0 → fee plancher. Design client-trust documenté ; exploit faible en V1 (free_km=5 aplatit déjà ≤5km). | Géocoder serveur (Nominatim+cache) au devis OU plafond de zone + log écart texte↔coords ; OU documenter risque accepté. |

## 5. DOCUMENTÉS — WORKSTREAM UBER GO-LIVE (dormant : secret vide → webhook fail-closed 401 ; à faire ENSEMBLE avant activation)
Uber est **inerte aujourd'hui** (0 impact) mais **PAS go-live-ready**. 6 durcissements non-frozen à traiter+tester en bloc à l'activation :
- **Article non mappé → commande PAYÉE perdue** (`UberWebhookController:150` : `item_id`=NULL viole NOT NULL → rollback → 200 give-up). `config/uber_menu_map.php` VIDE. → dégradation gracieuse vers item placeholder + brancher l'alerte `webhook_events.status=failed` + pré-remplir la carte.
- **Pas de UNIQUE sur `orders.transaction_id`** → double commande concurrente (dédup SELECT-puis-INSERT non atomique). → **fix cheap : peupler `business_date` + `is_advance_order` au forceFill active l'index UNIQUE existant `(branch_id,business_date,queue_number)`** (queue_number déterministe 'U'+display_id) ; sinon `Cache::lock('uber:'+id)`.
- **`is_advance_order` non initialisé** → DB default `YES` → KDS affiche `delivery_date=DEMAIN`. → `is_advance_order = Ask::NO` au forceFill.
- **Token OAuth jamais invalidé sur 401** (`UberClient:99`, TTL défaut 30j) → intégration bloquée. → `Cache::forget`+refetch sur 401, cap TTL 3600s.
- **Filtre event trop large** (`:73` `str_contains('order')`) → `orders.cancel` déclenche `createFromUber`+`acceptOrder` → cuisine prépare une commande annulée. → router par type d'event exact (cancel → CANCELED interne).

## 6. DOCUMENTÉS — LATENT / défense-en-profondeur (non-frozen, 0 instance live)
- **Stock : 86 d'un extra/variation = porte à sens unique** (`AvailabilityService:541` — réactivation laisse `on_hand=0` lu comme rupture par `ChoiceAvailabilityResolver`). 0 ligne extra/variation aujourd'hui, mais dashboard dit « dispo » pendant que la commande est rejetée 422. Fix : supprimer la ligne flag-only à la réactivation (+ flip du test qui fige l'état buggé).
- **Stock : quota journalier `max_daily_qty` non vérifié au gate** (`AvailabilityService:264`, flip post-commit → TOCTOU survente). 0 ligne `max_daily_qty`. Fix : vérifier/réserver le compteur sous le `lockForUpdate` existant.
- **OSS n'applique pas le filtre board-release paiement** (`OrderStatusScreenOrderService:63`) → un UNPAID forcé PREPARING par admin s'afficherait au mur client mais absent du KDS. 0 instance live. Fix : `KitchenReleaseRule::applyBoardReleaseFilter` dans `list()`+`listForBranch()` (comme mon fix Wave 1 KdsSync).
- **Enrichissement cash du Z jamais câblé** (`ZReportCashEnrichmentService:235` — colonnes `cash_*` ni peuplées ni lues, code mort décoratif). Fix : câbler via event `ZReportClosed` additif OU supprimer les colonnes vestigiales.

## 7. DOCUMENTÉS — OSS minuit + .vue cowork
- **P3 bascule minuit** (`OrderStatusScreenOrderService:92` + KDS `:128`) : filtre jour-CIVIL masque les commandes actives à cheval sur minuit (Le Cayenne opère après minuit, DB-prouvé). Mitigé (tickets ESC/POS imprimés, client présent). **Nécessite une décision de cutoff « jour d'exploitation »** + mise à jour du sentinel `KdsTodayWindowTzSentinelTest` qui fige la prémisse actuelle. Fix : fenêtre glissante `now-stale_hours` comme filtre primaire non-advance.
- **P3 double carillon OSS** (`PreparingAndReadyComponent.vue:407`, course poll↔Echo) → **.vue = territoire cowork**, ne pas toucher (conflit). Fix : `_markNewReady` idempotent par id.

## 8. RÉFUTÉS (6 — verify-before-report)
stock released_qty (registre lu-jamais-écrit mais impact sécurité inatteignable) · uber webhook non-atomique (le Handler global catch le 500) · uber total≠Σlignes (`uber.fiscalize` jamais lu → inatteignable) · OSS is_advance strict-equality (non atteignable V1) · OSS public fail-open (multi-tenant hors-scope V1) · split cash_back négatif (gardes présentes dans chaque consommateur).

## 9. GATES
- **Split 8/8** (2 nouveaux au payload frontend réel) + **POS regression 22/22** (cash-trail, tax, loyalty, delivery-guard, no-client-totals) · **frozen 0** (PaymentComponent.vue/pos-wizard/Fiscal/Pricing intouchés) · **NF525 CHAIN OK** (4 branches, inchangée).
- Visuel : 6 surfaces analysées (borne/caisse/kds/oss/items/stock), 0 raw label ; 1 URL doc corrigée.

## 10. CONVERGENCE
Les **bugs LIVE non-frozen sont désormais tous healés** (split-payment = le dernier gros). Le résidu = **frozen
(Z, 2 items dont 1 LOCK déjà approuvé) + dormant (Uber, 6 items) + latent (stock/OSS, 0 instance) + décisions
owner (pricing/variance) + 1 .vue cowork**. Aucun P0 ; aucun P1 non traité (le seul P1 confirmé, la couverture Z,
est un résidu owner-triagé connu à HMAC intacte). Backend V1 LOCAL **validé** ; reste = owner-gates + go-live Uber.
