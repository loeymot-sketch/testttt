# Cartographie — Cœur commandes + pricing + fiscal (NF525)

Vague 01-structure — lecteur read-only. Tout file:line cité a été lu dans cette session (Read/grep).
Date : 2026-07-02. Branche : `pos/category-first-caisse-2026-06-23`, HEAD `594eb92f5`.

## 1. Architecture générale

Deux pipelines de création de commande, un moteur de prix unique, un noyau fiscal NF525 en 3 chaînes :

- **POS (caisse)** : `app/Services/OrderService.php` (3095 l.) — `posOrderStore` (l.657), `tableOrderStore` (l.1336), `changeStatus` (l.2014), `changePaymentStatus` (l.2292), `collectKioskCash` (l.2511), `destroy` (l.2623).
- **Borne/Web/Mobile** : `app/Services/FrontendOrderService.php` (1470 l.) — `myOrderStore` (l.132), `changeStatus` (l.744), `finalizePaidKioskOrder` (l.1160).
- **Pricing SSOT** : `app/Services/Pricing/PricingService.php::calculateOrder` (l.36-370) derrière le flag `config('pricing.use_ssot_service', true)` ; les deux services gardent un chemin legacy dupliqué (FrontendOrderService l.321-484, OrderService l.856-996) exécuté seulement si le flag est false.
- **Fiscal NF525** : `app/Services/Fiscal/` — séquence gap-free (`FiscalSequenceService`), chaîne audit HMAC (`AuditLogService`), Z quotidien signé (`ZReportService` + `FiscalSealingService`), validation (`FiscalChainValidator`), X (`XReportService`), enrichissement cash/TPE (`ZReportCashEnrichmentService`).
- **State machines** : `app/Domain/Order/OrderStateMachine.php` (statuts) + `app/Domain/Order/PaymentStateMachine.php` (payment_status) + `AutoPrepareOnPaidPolicy` (auto ACCEPT→PREPARING).

## 2. Enums / constantes (vérifiés)

- `app/Enums/OrderStatus.php` : PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22.
- `app/Enums/PaymentStatus.php` : PAID=5, UNPAID=10, **PENDING_COUNTER=15**, REFUNDED=20.
- `app/Enums/PosPaymentMethod.php` : CASH=1, CARD=2, MOBILE_BANKING=3, OTHER=4, TICKET_RESTAURANT=5, **COUNTER_DEFERRED=6** (borne : « à encaisser au comptoir »).

## 3. Table des transitions OrderStateMachine (`app/Domain/Order/OrderStateMachine.php:30-91`)

| from \ to | autorisé sans permission | avec permission |
|---|---|---|
| PENDING | ACCEPT, CANCELED, REJECTED | — |
| ACCEPT | PREPARING, CANCELED | DELIVERED (perm `pos`, l.41) ; RETURNED (perm `pos-refund`, LOCK-OSM-PREZ-REFUND l.48) |
| PREPARING | PREPARED, CANCELED | DELIVERED (`pos`, l.55) ; RETURNED (`pos-refund`, l.59) |
| PREPARED | OUT_FOR_DELIVERY, DELIVERED | RETURNED (`pos-refund`, l.67) |
| OUT_FOR_DELIVERY | DELIVERED | — |
| DELIVERED | RETURNED | — |
| CANCELED/REJECTED/RETURNED | rien | tout si rôle Admin (l.82) |

- `from === to` toujours vrai (l.32). Reason obligatoire pour CANCELED/REJECTED/RETURNED (`requiresReason` l.276).
- `apply()` (l.195-270) = chemin atomique moderne : `DB::transaction` + `lockForUpdate` + early-return idempotent + `recordTransition` (audit `order_status_transitions`, best-effort l.148-175). Les call-sites historiques OrderService/FrontendOrderService gardent le pattern `$order->status = X; save(); recordTransition()` (règle frozen-zone documentée l.21-23).
- Kitchen release : `isReleasedToKitchen` (l.127-143) = status ≥ ACCEPT ET (PAID OU (POS+CASH)).
- `PaymentStateMachine` (`app/Domain/Order/PaymentStateMachine.php:9-19`) : UNPAID→PAID ; PENDING_COUNTER→{PAID, REFUNDED} ; PAID et REFUNDED terminaux.

## 4. Flux critiques (chaînes file:line vues)

### F1 — Commande borne cash « Plan B » (COUNTER_DEFERRED)
`POST /api/frontend/order` → `FrontendOrderService::myOrderStore` (l.132) : résolution branche pour lock idempotence (KioskMachine → user.branch_id → request.branch_id validé, l.145-165) → `Cache::lock` + recovery `(branch,key)` (l.168-184) → `DB::transaction` : `FrontendOrder::create` avec `payment_status=PENDING_COUNTER(15)` + `pos_payment_method=COUNTER_DEFERRED(6)` si kiosk+CASH_ON_DELIVERY (l.290-291) → `PricingService::calculateOrder` (l.302) → `OrderItem::insert` (snapshot inclus) → gate anti-remise V1 `assertDiscretionaryDiscountAllowed` (l.526) → `sealForCommit` quote kiosk (l.551) → décrément stock (l.559) → `source_surface='kiosk'|'web'` selon KioskMachine (l.573-575) → auto-accept PENDING→ACCEPT (l.629-633) → `OrderCreated::dispatch` DANS la transaction (afterCommit, l.645-647) → post-commit `recordTransition` + mail/SMS/push (l.650-675). **Aucun fiscal_sequence_no ici.**

### F2 — Encaissement comptoir (borne OU walk-in caisse déféré)
File caisse : `routes/api.php:807-853` `GET /counter-collect/pending` = PENDING_COUNTER, exclut CANCELED (l.822), 3 clauses (kiosk+type, pos+COUNTER_DEFERRED, filet source_surface NULL l.830-836), FIFO cap 200. Confirmation : `routes/api.php:854-895` → `PaymentService::confirmCounterPayment` (`app/Services/PaymentService.php:193-441`) : lock row → replay même caissier = no-op 200, autre caissier = 409 `PaymentAlreadyCollectedException` (l.278-310) → `PaymentStateMachine::assertCanTransition` (l.313) → refus statuts terminaux (l.323) → **allocation `fiscal_sequence_no` si NULL (l.335-337)** → PAID + mode + `pos_received_amount` (CASH seulement, l.339-344) → auto-prepare ACCEPT→PREPARING sauf CASH (l.365-374) → `Transaction::firstOrCreate` (l.390) → audit HMAC `order.counter_payment_confirmed` (l.403-415) → post-tx `OrderPaidAtCounter::dispatch` + `OrderStatusChanged` (l.422-439). `collectKioskCash` (OrderService:2511) délègue ici avec mode CASH.

### F3 — Commande borne carte/TR (TPE)
`myOrderStore` crée PENDING+UNPAID (gate dispatch l.250 : signals différés) → callback TPE → `FrontendOrderService::finalizePaidKioskOrder` (l.1160) : guard type+pm (l.1174), lock, whitelist PENDING seul (l.1201), **guard PAID obligatoire (l.1209)** → **allocation fiscale à la finalisation** si `fiscal.kiosk_auto_allocate_sequence` (défaut true, l.1232-1238) ; échec → flag `fiscal_alloc_error_at` écrit HORS transaction en raw update (l.1365-1390), commande reste PENDING, cron `foodking:fiscal:retry-alloc` (Kernel.php:266 ; `RetryFiscalAllocCommand.php:39-65`) rattrape → ACCEPT (l.1291) → auto-prepare PREPARING si policy (l.1318-1336) → `OrderCreated::dispatch($locked)` in-tx (l.1362) → post-tx recordTransition + double broadcast ACCEPT puis ACCEPT→PREPARING (l.1405-1437).

### F4 — Vente POS directe
`posOrderStore` (OrderService:657) : idempotence `(branch, customer, key)` Cache::lock + recovery 23000 (l.671-695, 1306-1323) → guard branche caissier (l.732) → si `pos.walkin_route_to_counter` ou `defer_to_counter` : markers déférés identiques kiosk Plan B (l.721-726, payment_status PENDING_COUNTER l.783) → sinon `payment_status=PAID` à la création → statut initial via `AutoPrepareOnPaidPolicy` (l.758-764) → `PricingService::calculateOrder` forPos (l.819) → gates remise (l.836-848) → allergen snapshot (l.852) → **allocation fiscale à la création SEULEMENT si non-déféré (l.1114-1119)**, dans le closure `saveOrderWithQueueNumber` → validation cash reçu ≥ total serveur (l.1071-1078) → `source_surface='pos'` (l.1090) → audit HMAC `order.created.pos` in-tx (l.1206-1227) + audit remise si >0 (l.1175-1194) → split payment optionnel `SplitPaymentService::persistTranches` (l.1238-1243) → `cash_movement` NF525 strict si CASH (l.1260-1267) → `OrderCreated::dispatch` in-tx (l.1277).

### F5 — QUAND la séquence fiscale est allouée (synthèse)
- POS inline payé : **à la création** (OrderService:1117).
- POS/borne déféré comptoir : **à l'encaissement** (PaymentService:335-337) — jamais à la création (commentaire NF525 anti-gap OrderService:1106-1113).
- Borne carte/TR : **à finalizePaidKioskOrder après PAID TPE** (FrontendOrderService:1236-1238), retry cron si échec.
- `FiscalSequenceService::next` (`app/Services/Fiscal/FiscalSequenceService.php:57-104`) : `Cache::lock fiscal_seq_b{branch}` 5s/block 3s + transaction + `lockForUpdate` + `withTrashed()` + `withoutGlobalScope(BranchScope)` sur `MAX(fiscal_sequence_no)+1` (l.97-103). Unique DB `orders_branch_fiscal_seq_unique` (commentaire l.22).

### F6 — Clôture Z
`ZReportService::close` (l.180-286) : lock `z_report_b{n}` → `verifyChain` AVANT close (l.201) → tx : `warnOnOrphanedPaidOrders` (l.229) → `aggregate` (l.297-459) : fenêtre demi-ouverte `(from, to]` (l.343-347), seuls orders `fiscal_sequence_no NOT NULL` + `payment_status != UNPAID` (l.340-341), `withTrashed` (l.338), exclusion statuts terminaux (l.349-357), miroirs refund contre-écriture nettés (l.381-387), ajustements post-Z négatifs par `updated_at` (l.404-419), `total_by_tax_rate` = SSOT TVA, `total_ht = ttc - tva` (l.435-444) → `prev_hash` = signature du Z précédent (l.233-237) → `sign` via `FiscalSealingService::signZReport` (HMAC SHA-256, `fiscal.z_report_secret`, sentinelles prod refusées, FiscalSealingService.php:11-38, 92-115).

### F7 — Chaîne audit HMAC
`AuditLogService::write` (l.70-132) : seul écrivain autorisé ; `branch_id` explicite requis (l.93-98) ; `Cache::lock audit_chain_b{n}` + tx + retry unique 1× sur UNIQUE(branch_id, prev_hash) (l.179-191) ; `current_hash = HMAC(prev_hash | canonical(action,payload sorted))` (l.237-243) ; secret prod ≥32 chars, sentinelles dev rejetées (l.303-327). `verifyChain` (l.199-231) re-marche toute la chaîne. Immutabilité DB : triggers `BEFORE DELETE SIGNAL 45000` sur z_reports (migration 2026_05_09_160000 l.51-55), audit trail cash (2026_05_10_010000 l.109-125) ; `BEFORE UPDATE` sur `order_items.composition_snapshot` (2026_05_24_040211 l.86-126) + guard applicatif `OrderItem::boot` updating (OrderItem.php:51-54).

## 5. Pricing (frozen, lu intégralement)

`PricingService::calculateOrder` (l.36-370) : disponibilité items/addons par branche (l.47-55, 100-107) → chargement bulk items/variations/extras/addons (l.57-98) → `assertOptionsOrderable` (statut ACTIVE + visibilité par surface pos/kiosk/web + stock, l.452-555) → `assertComposerStepConstraints` (profil wizard publié : min/max/allow_repeat/appartenance/dispo, l.557-657) → par ligne : prix DB uniquement, guards cross-item (l.152-156, 182-186, 207-211), quantités multi (l.158, 188), ratio menu `menuRoleAdjustedAddonPrice` (roles menu_full/frites/boisson × `config('kiosk.menu_pricing')`, l.793-813) → **TVA mode TTC** : `config('pricing.tax_inclusive_prices')` → `TaxCalculator::lineTaxAmountFromTTC` extrait la TVA du TTC (HT = TTC/(1+taux/100), TaxCalculator.php:32-48) ; total = subtotal + delivery − discount (sans re-additionner la TVA, l.350-354) → `CompositionSnapshotBuilder::build` par ligne (l.270-276) : snapshot immuable SCHEMA_VERSION=1 (CompositionSnapshotBuilder.php:19), json_encode manuel car mass-insert bypasse le cast (commentaire l.266-269). `DiscountCalculator` : coupon ou remise manuelle contexts pos/table seulement (l.331-344). `PricingRequest::forPos/forKiosk` fabriques.

Remises V1 : DÉSACTIVÉES par gate code (`assertDiscretionaryDiscountAllowed`, appelé FrontendOrderService:526 + OrderService:848) tant que le défaut F1 (TVA sur base pré-remise) n'est pas corrigé — la Z compense via netting `LOCK_ZREPORT_F1_DISCOUNT_NETTING` (ZReportService:425-434).

## 6. Fichiers clés annexes

- `app/Services/Order/OrderQuoteService.php` : quote signé TTL 300s (l.38), surfaces pos/kiosk, `sealForCommit` (l.111) consommé à la création (FrontendOrderService:551, OrderService:1060).
- `app/Models/Order.php` : `restoring()` throw = soft-delete one-way (l.139) ; auto `source_surface='delivery'` pour DELIVERY (l.157-158) ; scopes statut (l.211-267).
- `app/Models/OrderPayment.php` : tranches split payment, BranchScope global (l.67), `terminal_id` TPE nullable (l.36), somme ≥ total validée par SplitPaymentService (docblock l.13-16).
- `app/Models/OrderItem.php` : cast `composition_snapshot => array` (l.102), guard updating NF525 (l.51-54).
- Commandes artisan (app/Console/Commands, vérifié ls) : `fiscal:verify-chain` (FiscalVerifyChainCommand:65), `foodking:fiscal:retry-alloc` (RetryFiscalAllocCommand:41), `foodking:fiscal:archive`, `fiscal:verify-z-membership` (schedulé Kernel.php:91), FiscalOpen/CloseAllActiveBranches, FiscalAssertChainClean.
- Route encaissement : `routes/api.php:807-915` (pending / confirm / cancel), toutes gated `can('pos')` + throttle + idempotency middleware sur les POST.

## 7. Invariants observés (file:line)

1. Prix 100% backend : champs financiers client unset avant create (FrontendOrderService:271, OrderService:705) ; items rejetés si absents DB (PricingService:128-133).
2. composition_snapshot écrit à la création dans la même tx, immuable (trigger BEFORE UPDATE + guard modèle).
3. Séquence fiscale : monotone gap-free par branche, triple défense (cache lock + tx + lockForUpdate + unique DB), jamais brûlée pour une commande non payée (defer), échec → flag + retry cron, jamais de crash côté borne.
4. Z : chaque commande fiscalisée dans exactement un Z (fenêtre demi-ouverte + withTrashed) ; chaîne Z vérifiée avant chaque close.
5. Audit chain append-only : UNIQUE(branch_id, prev_hash) anti-fork + triggers DELETE + secrets prod forts obligatoires.
6. Events : `OrderCreated`/`OrderStatusChanged` dispatchés DANS les transactions via DispatchableAfterCommit (drop sur rollback — anti ghost KDS), mail/SMS/push post-commit.
7. Idempotence commandes : Cache::lock + UNIQUE DB (branch, user, key) + recovery 23000, POS et kiosk symétriques.
8. Encaissement concurrent : lockForUpdate + réponse discriminée même-caissier(200)/autre(409).

## 8. Risques préliminaires (à vérifier vagues suivantes — PAS certifiés)

- R1 : `PosOrderRequest.php:117-118` compare `request('pos_payment_method')` (string HTTP) à des constantes int avec `===` — seul TICKET_RESTAURANT est casté string ; les règles conditionnelles note/received peuvent ne jamais s'activer en multipart (connu, « laissé exprès » selon mémoire projet ; le total serveur reste validé côté service OrderService:1071).
- R2 : double chemin de pricing legacy (flag `pricing.use_ssot_service=false`) toujours présent et divergent (pas de `assertVariationConstraints` ni composer checks dans le legacy FrontendOrderService:369-477) — dead code dangereux si flag bascule.
- R3 : `finalizePaidKioskOrder` recordTransition post-tx PENDING→ACCEPT même quand la policy a poussé à PREPARING dans la tx (l.1405-1412 puis broadcast séparé) — trail cohérent mais ordre des lignes d'audit à valider.
- R4 : `counter-collect/pending` filet NULL source_surface repose sur order_type kiosk/emporter — une commande WEB TAKEAWAY PENDING_COUNTER avec source_surface NULL apparaîtrait en file borne (données héritées seulement).
- R5 : `confirmCounterPayment` autorise `received=null` pour CASH → défaut = total (l.341-343) ; la validation `received < total` ne joue que si fourni (l.329).
- R6 : `AuditLogService::resolveBranchId` fallback sur user.branch_id — un admin branch_id=0 écrit sur la chaîne système 0 ; OK V1 mono-branche, à surveiller.

## 9. Couverture de tests (ls vérifié)

`tests/Feature/Fiscal/` (57 fichiers) : FiscalSequenceTest, AuditLogHashChainTest/Concurrency/Immutability, ZReportCloseTest/BoundaryTest/AggregateFilterTest/TaxBreakdownTest/DiscountNettingTest, RefundPreZ/PostZ/CounterEntryNettedInZ, NF525ComplianceE2ETest, FiscalAllocOrphanRetryTest, FiscalAllocErrorFlagOutsideTxSentinelTest, FiscalCashAtCounterLifecycleTest, ManualDiscountDisabledV1SentinelTest, Vat10ZReconciliationTest, SealedOrderMutationGuardTest, CompositionSnapshotImmutabilityTriggerSentinel…
`tests/Feature/Order/` : AutoPrepareOnPaidTest, ChangeStatusRaceGuardTest, PriceChangeSnapshotTest, OrderServiceFrontendOrderServiceSymmetryTest, OrderStateMachinePreZRefundLockSentinelTest.
`tests/Feature/Orders/` : CrossItemGuardTest, IdempotencyBranchScopedTest, KioskIdsOnlyPayloadTest, OrderAllergenSnapshot*.
`tests/Feature/Pricing/TaxInclusivePricesTest.php`. `tests/Feature/Kiosk/` : FinalizePromotionGuardTest, KioskPaymentConfirmAmountTest, PaymentReconcileTest.

## 10. Questions ouvertes

- Le flag `pricing.use_ssot_service` est-il verrouillé à true en prod (boot guard ?) — sinon le chemin legacy sans contraintes composer est atteignable.
- `ZReportCashEnrichmentService` (587 l.) non lu en détail — ventilation TPE/terminal_id à cartographier en W2.
- `changePaymentStatus` (OrderService:2292) et `destroy` (l.2623, garde `fiscal_sequence_no !== null` l.2651) lus partiellement — chemins refund/suppression à couvrir en W2.
- Interaction `sealForCommit` quote kiosk vs recalcul PricingService : lequel prime en cas d'écart (tolérance ?) — non lu.
