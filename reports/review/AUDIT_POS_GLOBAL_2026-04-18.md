# Audit global POS FoodKing — 2026-04-18

**Track.** B (POS-A lecture seule). Exécuté en parallèle du Track A (Kiosk Phase 9).
**Mode.** 0 commit, 0 modification de code, 0 migration. Seuls fichiers écrits : ce rapport + 4 sous-rapports `AUDIT_POS_SECTION_{1..4}_2026-04-18.md` + `tasks/phase9-pos/FINDINGS_POS_TRACKER.md`.
**Méthode.** 4 sous-agents `general-purpose` parallèles read-only (parcours commande, centralisation multi-surfaces, fin de journée/permissions/conformité, backend state machine). Agrégation + dédoublonnage dans ce fichier.
**Matériel lu.** `POS_MASTER_BRIEF.md`, `SYNC_PROTOCOL_KIOSK_POS.md`, `POS_INVARIANTS_AND_GATES.md`, les 10 briefs `tasks/audits/AUDIT_POS_*_001..010.md`, l'audit kiosk `AUDIT_KIOSK_GLOBAL_2026-04-18.md`, `CLAUDE.md`, docs `ARCHITECTURE/ORDER_FLOW/BUSINESS_RULES/AUTHZ_MATRIX`, 7 rapports POS antérieurs (mars), sources backend (`OrderService` 1693 L, `FrontendOrderService` 781 L, `PricingService`, controllers `Admin/Pos*`, events/listeners/jobs, `OrderStateMachine`, `EventContract`), frontend POS (5 composants Vue), stores, tests Feature/Vitest.

---

## 0. Sommaire exécutif

- **58 findings consolidés** (après dédoublonnage de 74 findings bruts issus des 4 sous-agents + 10 briefs ciblés) : **20 P0**, **22 P1**, **12 P2**, **4 P3**.
- **Verdict global** : **BLOCKED**. Le POS FoodKing n'est pas exploitable en production France (non-conformité NF525 / loi Finance 2018), pas utilisable commercialement en l'état (multi-tender impossible, split bill inexistant, TPE non intégré, discount staff sans permission, dine-in masqué `v-if="false"`), et structurellement fragile sur ses invariants métier (11 écritures `$order->status = X` hors `OrderStateMachine::apply`, `changePaymentStatus` sans transaction ni event, `destroy()` sans branch check ni audit log).
- Le **noyau backend partagé** avec le kiosk (outbox `domain_events`, EventContract V1, SSOT pricing `PricingService`, queue_number `Cache::lock`, `BranchScope`) **tient la charge** et a été audité vert côté kiosk. C'est donc la **couche d'intégration POS** (state machine bypassée, paiement binaire, drawer monosource, absence de features régulatoires) qui concentre la dette.
- **3 forces structurelles** à préserver ; **3 faiblesses** imposent une phase corrective dédiée (Phase POS-B) *avant* toute nouvelle feature.
- **Recouvrement avec kiosk Phase 9** : 9 findings partagés (§9) doivent être traités en passes backend unifiées pour éviter les doubles PR conflictuelles.

### Forces structurelles à préserver

1. **SSOT pricing opérationnel.** `posOrderStore`, `myOrderStore`, `tableOrderStore` et `FrontendOrderService::myOrderStore` unset explicitement `total/subtotal/discount` du payload (`app/Services/OrderService.php:566`, `:291`, `:940` ; `app/Services/FrontendOrderService.php:187`) puis appellent `PricingService::calculateOrder()` derrière le flag `pricing.use_ssot_service=true`. Guards cross-item injection sur variations et extras (`OrderService.php:676-681, 702-707`). Grep `->input('price'|'total')` dans `app/Http/Controllers/Admin/` = 0 hit.
2. **Outbox + EventContract V1 mature.** `PersistOrderCreatedToOutbox:37` et `PersistOrderStatusChangedToOutbox:36` utilisent `DB::afterCommit()`. `DispatchDomainEventsJob` fait early-exit idempotent sur `dispatched_at !== null` (`:37-39`), valide envelope via `EventContract::assertEnvelopeValid()` (`:56-68`) avant `Pusher->trigger('private-branch.{id}', …)`, retries avec backoff `[1,5,30,300]`, commandes rescue + retry-failed couvertes par `OutboxTest`. Auth canal `branch.{id}` avec ability kiosk isolée (`routes/channels.php:25-39`).
3. **Queue number atomique par branche + jour.** `Cache::lock('queue_lock_{branch}_{today}', 10)->block(5)` + `MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED))` (`OrderService.php:773-799`, `FrontendOrderService.php:370`). Idempotency POS avec `X-Idempotency-Key` pré-check + unique constraint + catch 23000 (`OrderService.php:550-556, 916-922`). `BranchScope` global sur `Order`, `Item`, `DiningTable`, `User` (`app/Models/Scopes/BranchScope.php:27-40`).

### Faiblesses structurelles imposant une Phase POS-B

1. **Conformité fiscale & opérationnelle française absente.** Zéro `ZReport` (NF525 / loi Finance 2018 / décret 2017-1367), zéro `XReport`, zéro table `cash_drawer_sessions`, zéro table `audit_logs` immuable (`action_logs` existante mutable et sans `branch_id`), `order_serial_no = date('dmy') . id` non séquentiel par branche, `destroy()` physique sans audit. → **blocage réglementaire avant ouverture commerciale FR**.
2. **Multi-surface amputée + multi-tender impossible.** Enum `PaymentStatus` binaire PAID/UNPAID (`app/Enums/PaymentStatus.php:1-10`), aucune table `order_payments`, split bill / acompte / ticket restaurant / refund inline structurellement impossibles. Drawer POS monosource (kiosk cash uniquement, 1 source sur 5+ visible — `PosComponent.vue:1018-1040`), `OrderType::POS(15)` absent de toute colonne KDS, dine-in et table selector hardcodés `v-if="false"` (`PosComponent.vue:121, 235`), 4 events du contrat (`ORDER_ITEM_ADDED`, `ORDER_CANCELLED`, `PAYMENT_RECORDED`, `ORDER_REFUNDED`) déclarés/attendus mais jamais dispatchés.
3. **Invariants métier contournés en live.** 11 écritures `$order->status = X; $order->save()` hors `OrderStateMachine::apply()` (seuls `recordTransition` manuels en post-hoc), `changePaymentStatus` sans transaction ni state machine ni event, `deliveryBoyOrderChangeStatus` dispatche mails/SMS/push **avant** `$order->save()`, `destroy()` sans branch check ni ActionLog, `OrderStateMachine::apply()` = 0 call-site dans `app/Services/`. Les garde-fous promis (§1 INVARIANTS) sont des vœux pieux : la machine existe mais n'est **pas** le gatekeeper.

---

## 1. Périmètre + méthode + verdict global

### 1.1 Périmètre

| Domaine | Surface auditée | Taille |
|---|---|---|
| Parcours commande POS | `resources/js/components/admin/pos/*` (5 composants), `posCart.js`, `posOrder.js`, `PosController`, `PosOrderController`, `PosOrderRequest`, `ValidJsonOrder`, `PaymentStatus`/`PosPaymentMethod`/`DiscountType` | 5 Vue (3651 L) + 2 stores + 4 Php |
| Centralisation multi-surfaces | `PosComponent` FAB, `KitchenDisplaySystemComponent`, `OrderStatusScreenController`, `PosOrder`/`OnlineOrder`/`TableOrder`/`DeliveryBoyOrder` listeners, `routes/api.php` admin + channels | Admin flat (pas de sous-dossier `Admin/Pos/`) |
| Stock / 86 / fin de journée / tiroir / permissions / dashboard / TVA | `AvailabilityService`, `ItemService`, `TaxService`, `PricingService/TaxCalculator`, `DashboardService`, `SalesReportController`, `PermissionService`, `ActionLog`, seeders rôles/permissions | Ensemble backend + Dashboard Vue |
| Backend POS (services, events, jobs, outbox, state machine, idempotency) | `OrderService` (1693 L), `FrontendOrderService` (781 L), `PricingService`, `OrderStateMachine`, `EventContract`, `DispatchDomainEventsJob`, `PersistOrder*ToOutbox`, `PaymentService`, FormRequests, migrations | Cœur métier |
| Tests | `tests/Feature/POSComprehensiveTest`, `PosOrderTaxTest`, `PosDiscountTest`, `PosPriorityApiTest`, `PosUITest`, `OrderFlowTest`, `OrderStateTransitionTest`, `OutboxTest`, `ConcurrentOrderTest`, `EventContractTest`, `BranchIsolationTest`, `FrontendDiscountIntegrityTest`, `PricingIntegrityTest` + Vitest `tests/js/posCart.spec.js` | Partiel |

### 1.2 Méthode

4 sous-agents parallèles `general-purpose`, mode lecture seule strict. Chaque sous-agent a produit un rapport autonome avec ≥ 10 findings ancrés `fichier:ligne` (16+16+20+22 = 74 findings bruts). Ce rapport agrège après dédoublonnage et priorisation cross-pan. Les IDs consolidés sont de la forme `POS-GA-F-NN`. Chaque finding conserve une référence `origin: Sx-F-YY` vers son sous-rapport source.

### 1.3 Verdict global par domaine

| Domaine | Verdict | Commentaire court |
|---|---|---|
| Parcours commande (wizard → panier → paiement → receipt) | **WARN → BLOCKED** | Cash takeaway simple fonctionnel ; split/tab/amend/refund/multi-tender absents ; TPE non intégré ; discount sans permission ; tickets TVA non ventilée. |
| Centralisation multi-surfaces (drawer, filtres, actions, temps réel) | **BLOCKED** | 1 source visible sur 5+ (kiosk cash only), `OrderType::POS(15)` invisible KDS, zéro filtre, zéro action cancel/refund/amend/discount depuis POS. |
| Stock / 86 / fin de journée / tiroir / dashboard | **BLOCKED** | `ZReport`/`XReport`/`cash_drawer_sessions`/`audit_logs` inexistants ; `AvailabilityService::toggle` jamais exposé ; stock non libéré sur cancel ; TVA sans cascade `order_type`. |
| Permissions & conformité | **BLOCKED** | 2 permissions POS seedées (`pos`, `pos-orders`), zéro granularité cancel-after-paid/refund/discount-seuil/amend/reopen-Z/manage-drawer ; `action_logs` mutable et sans `branch_id` → fuite cross-tenant via `DashboardService::auditTrail`. |
| Events & state machine | **WARN → BLOCKED** | EventContract V1 OK ; mais 11 écritures `->status = X` hors `apply()`, 4 events contractuels jamais dispatchés, `OrderStatusChanged(PENDING→ACCEPT)` manquant côté POS, correlation_id régénéré par listener. |
| Tests | **WARN** | Outbox/StateMachine unit verts, mais `BranchIsolationTest` vide (placeholder), pas de test double-submit POS, zéro test `changePaymentStatus`, zéro test symétrie POS↔Kiosk. |

---

## 2. Bilan parcours commande POS (wizard → panier → paiement → receipt)

### 2.1 Architecture frontend

Le POS tient sur **5 composants Vue** (total 3651 lignes) :

| Composant | Rôle | Lignes |
|---|---|---|
| `PosComponent.vue` | Shell : recherche, catégories, panier, discount, order_type (dine-in caché, takeaway par défaut, delivery inline), kiosk-cash FAB, polling, Echo subscribe | 1863 |
| `ItemComponent.vue` | Grille items + modale wizard (variations, extras, addons, instruction) + edit-from-cart via heuristique noms FR | 1068 |
| `PaymentComponent.vue` | Modale paiement cash/carte + numpad + confirm | 280 |
| `ReceiptComponent.vue` | Ticket imprimé via `vue3-print-nb` (dialogue navigateur, pas ESC/POS) | 243 |
| `CreateCustomerAddressComponent.vue` | Modal adresse delivery | 197 |

### 2.2 Séquence caissier nominale (cash, takeaway)

```
UI:  PosComponent.mounted → $refs.takeAway.click() (TAKEAWAY forcé)
     loadKioskCashOrders + Echo subscribe(private-branch.{id}) + polling 10/60 s
 1. Recherche / clic catégorie → itemList() → items store
 2. Clic item → variationModalShow → dispatch item/details(surface='pos')
 3. Clic add_to_cart → posCart/lists → localStorage pos_cart_v2 TTL 2 h
 4. Discount applyDiscount (% ou fixe) — 100 % client, aucun preview serveur
 5. Orderbubmit : génère token client localStorage + idempotency_key → open modale paiement
 6. confirmOrder → POST /api/v1/admin/pos (X-Idempotency-Key, timeout 30 s AbortController)
 7. Backend PosController.store → OrderService::posOrderStore
     - idempotency pré-check (L550) + DB transaction (L560)
     - Order::create status=ACCEPT direct (pas de state machine)
     - PricingService::calculateOrder (SSOT, L602)
     - OrderItem::insert, queue_number Cache::lock + MAX (L773-799)
     - ActionLog::create (texte libre, L887)
     - HORS tx mais sans afterCommit() explicite : OrderCreated::dispatch (L904)
 8. PosOrder/show → ReceiptComponent → vue3-print-nb window.print
 9. posCart/resetCart
```

### 2.3 Audit wizard

- **Parité POS/Kiosk cassée.** Structure items POS = legacy Vue 2 (`item_variations: [{id,item_id,item_attribute_id,variation_name,name}]` + `item_extras: [{id,item_id,name}]` + `pos_line_addons` nested) vs kiosk V2 = `wizard_selections`. Pas de contrat commun. À la re-edit POS, reconstruction via heuristiques FR `attrLower.includes('pain'|'viande'|'sauce'|'accompagnement'|'riz'|'salade')` (`ItemComponent.vue:721-915`). **Fragile à l'i18n et au renommage admin**.
- **Validation serveur insuffisante.** `ValidJsonOrder.php:31-73` vérifie uniquement `item_id > 0`, `quantity > 0`, `instruction ≤ 500`. Aucune obligation min/max sélection d'attribut ni variation requise. Un sandwich peut partir en cuisine **sans pain**.
- **Addons cassent la sémantique parent/enfant.** POS décompose les addons d'une ligne panier en N `OrderItem` indépendants (`PosComponent.vue:1239-1248`). Le backend n'a aucun lien DB "ces frites appartiennent au menu du sandwich #42".
- **Prix SSOT respecté** sur le chemin principal (L566 unset + L602 PricingService).
- **Preview panier 100 % client**, aucun appel `/api/pricing/preview`. Risque divergence sur TVA tax-inclusive vs exclusive.

### 2.4 Audit panier

| Aspect | Constat | Fichier |
|---|---|---|
| Persistance | localStorage `pos_cart_v2` TTL 2 h, non scopé `branch_id`/`user_id` — **fuite panier entre caissiers** | `posCart.js:6-44` |
| Fusion lignes identiques | Fusion client si `item_id` + variations + extras + instruction + `posLineAddonsSignature` identiques | `posCart.js:164-246` |
| Reprise shift / draft server-side | Absent. Crash caisse entre orderSubmit et posOrder/save → panier survit côté client mais aucune reprise cross-device | — |
| Split bill (item / personne / montant) | **ABSENT**. Aucune route, aucune méthode `OrderService::splitOrder` | `routes/api.php:621-638` |
| Tab par table | **HARDCODÉ CACHÉ** `v-if="false"` | `PosComponent.vue:121, 235` |
| Discount staff | UI % ou fixe (max 100% / max subtotal). **Zéro check permission Spatie**, zéro motif obligatoire, zéro seuil. Backend accepte aveuglément | `PosComponent.vue:1139-1158`, `PricingService.php:213-217`, `DiscountCalculator.php` |

### 2.5 Audit paiement

- **Cash.** Rendu monnaie calculé 100 % client (`PaymentComponent.vue:137-141`). Backend revalide `pos_received_amount ≥ total` (`OrderService.php:818-825`) mais `cash_back_amount` du reçu n'est pas recalculé serveur → écart possible si TVA diverge. **Aucune ouverture tiroir** (`grep openDrawer` dans `admin/pos/` = 0 hit). Le kiosk a `kioskHardware.openDrawer()` câblé mais pas le POS.
- **Carte (TPE).** **Aucune intégration TPE**. `pos_payment_note` = 4 chiffres tapés à la main en clair. Pas d'`auth_code`, `rrn`, `acquirer_ref`, pas de bridge `window.borne.cardPay()`, pas d'ACK serveur. Backend marque PAID instantanément sans preuve d'encaissement.
- **Multi-tender.** Structurellement impossible : enum `PaymentStatus = {PAID:5, UNPAID:10}`, colonne unique `pos_payment_method`, pas de `paid_amount`, pas de `PARTIALLY_PAID`, pas de table `order_payments` (`PaymentStatus.php:1-10`).
- **Ticket restaurant, acompte.** Absents de `PosPaymentMethod` enum (CASH=1, CARD=2, MOBILE_BANKING=3, OTHER=4).
- **Refund inline.** Absent. `changePaymentStatus` (`OrderService.php:1485-1526`) permet UNPAID↔PAID sans transaction, sans cashback, sans stock release, sans event, sans motif.

### 2.6 Audit reçu

- **Template unique.** `ReceiptComponent.vue` HTML + `vue3-print-nb`. Aucun template serveur (`resources/views/**/receipt*` = 0).
- **TVA non ventilée par taux.** Ligne unique "Total TVA" (`ReceiptComponent.vue:105-112`). **Non-conforme art. 242 nonies A CGI** (obligation ventilation par taux 5.5 / 10 / 20 %).
- **`queue_number` non imprimé** ; seul le `token` client-side l'est (`ReceiptComponent.vue:152-156`). L'OSS affiche `A0001`, le ticket dit "N° 42" → incohérence d'identifiant.
- **Aucune réimpression, aucun journal, aucun fallback**. Si la caissière ferme la modale, ticket perdu.
- **Notes cuisine séparées** : `grep kitchen_notes|special_instructions` = 0 hit. Seul `OrderItem.instruction` existe (string unique).

---

## 3. Centralisation multi-surfaces

### 3.1 Tableau source × visibilité POS × actions disponibles

| Source (`Order.source`, `order_type`) | Drawer POS | KDS | OSS | Actions depuis POS | Preuve |
|---|---|---|---|---|---|
| Kiosk **cash** (`order_type=25\|10`, `payment_method=1`) | ✅ | ✅ | ✅ | **1 action** : "Encaisser" → `POST admin/kds-order/change-status/{id}` → DELIVERED | `PosComponent.vue:1018-1040, 1042-1054` |
| Kiosk **card / TR deferred** (PENDING) | ❌ | ❌ (PENDING < ACCEPT) | ❌ | — | `KitchenDisplaySystemOrderService.php:52` |
| POS comptoir (`order_type=15`) | ❌ | ❌ (4 colonnes KDS : DINING_TABLE, DELIVERY, TAKEAWAY, KIOSK — POS absent) | ❌ | — | `KitchenDisplaySystemComponent.vue:602-655` |
| Web / App client (`source=5\|10`) | ❌ | ✅ | ✅ | — | `OnlineOrderListComponent.vue:277` |
| Delivery partners (Uber Eats, Deliveroo…) | **PAS D'INTÉGRATION** | — | — | — | `app/Enums/Source.php:1-12` (3 constantes WEB/APP/POS) |
| Table orders (`order_type=20`) | ❌ | ✅ | ✅ | — | `TableOrderListComponent.vue:275` |
| Delivery boy assigné | ❌ | ✅ | — | — | `OrderService.php:1312-1358` |

**Verdict** : **1 source sur 5+ visible dans le POS**. Zéro filtre par source/staff/table/heure. Le caissier doit ouvrir 5 écrans admin distincts pour superviser l'activité branche.

### 3.2 Chaîne événements POS → autres surfaces

```
Caissier POS → POST /api/v1/admin/pos
  → PosController.store → OrderService.posOrderStore
    → DB::transaction { … OrderCreated::dispatch() HORS afterCommit explicite }
      → Listener PersistOrderCreatedToOutbox (synchrone)
        → DomainEvent::create (envelope V1)
        → DB::afterCommit → DispatchDomainEventsJob::dispatch(onQueue=high)
          → assertEnvelopeValid() → Pusher.trigger('private-branch.X', ...)
            → KDS, OSS, POS-FAB, PreparingAndReady (branch.X subscribers)
            → ⚠️ Clients web/app reçoivent FCM, PAS un broadcast Pusher ciblé user
```

**Events émis** par l'action POS :
- `OrderCreated` : `OrderService.php:531, 904, 1203` ; `FrontendOrderService.php:770`.
- `OrderStatusChanged` : `OrderService.php:1348, 1401, 1470` ; `KitchenDisplaySystemOrderService.php:142`.
- `SendOrderMail/Sms/Push` : listeners queue (`OrderService.php:1464-1466`).

**Events absents** (malgré déclaration contractuelle) :
- `OrderItemAdded` — 0 dispatch réel (`EventContract.php:37`).
- `OrderCancelled` — aucune classe `App\Events\OrderCancelled` ; cancel passe par `OrderStatusChanged(→16/19)` générique.
- `PaymentRecorded` — inexistant malgré §3.8 MASTER_BRIEF.
- `OrderRefunded` — aucune route, aucun service, aucun event.

### 3.3 Temps réel — Pusher / fallback / isolation

| Check | État | Preuve |
|---|---|---|
| Auth canal `branch.{id}` + ability staff | ✅ | `routes/channels.php:25-39` |
| POS subscribe `private-branch.{branchId}` | ✅ | `PosComponent.vue:999-1010` |
| POS subscribe `ItemAvailabilityChanged` (86 admin temps réel) | ❌ | 2 bindings seulement (`:1004-1007`) |
| Fallback polling si Echo null | ⚠️ | Polling binaire 10/60 s, limité à `loadKioskCashOrders` (pas catalogue) |
| Reconnection backoff exponentiel | ⚠️ | Géré par `window._wsService` (hors scope audit) mais pas logué |
| Son / toast sur nouvelle commande | ❌ | Handler déclenche juste refresh (`:1005`) |

### 3.4 Actions manquantes depuis le POS

Le drawer POS permet **uniquement** `Encaisser` sur une commande kiosk cash. Tout le reste (accept / preparing / ready / delivered / cancel / refund / amend / discount / reassign table / change delivery boy) nécessite de quitter `PosComponent` pour un des 5 écrans admin distincts. **L'ambition "POS = centrale opérationnelle" du MASTER_BRIEF §1 n'est pas tenue.**

---

## 4. Stock / 86 / fin de journée / tiroir / dashboard

### 4.1 Stock / 86

| # | Constat | Evidence |
|---|---|---|
| S-a | **Aucun endpoint admin/POS** pour toggler 86 manuel. `AvailabilityService::toggle()` existe (`app/Services/Menu/AvailabilityService.php:32-74`) mais jamais exposé | `grep AvailabilityService app/Http/Controllers/` = 0 hit |
| S-b | **Aucune libération de stock** sur CANCELED/REJECTED/RETURNED. `decrementForOrder` one-way | `grep incrementForOrder\|releaseStock` = 0 hit |
| S-c | Pas de `Item.stock_qty` (vraie qté). Tout repose sur `max_daily_qty` + compteur journalier | `app/Models/Item.php:20-73` |
| S-d | `ItemResource` admin (utilisé par POS) **n'expose pas** les allergens (seule `NormalItemResource` kiosk le fait) → caissier aveugle allergènes | `app/Http/Resources/ItemResource.php:19-65` |
| S-e | Pas de notification "rupture mid-prep" POS → kiosk/web | `grep RuptureNotification\|MidPrep` = 0 hit |
| S-f | Pas de tuile "rupture" sur dashboard | `resources/js/components/admin/dashboard/` — absent |

### 4.2 Fin de journée X/Z — conformité fiscale

**État factuel** :

```bash
ls database/migrations/ | rg "z_report|x_report|fiscal|end_of_day|session_cash|shift"  # → 0 hit
ls app/Models/ | rg "ZReport|XReport|Shift|Session|Fiscal"                              # → 0 hit
rg "NF525|hash_chain|prev_hash|fiscal_signature" app/                                   # → 0 hit
```

`SalesReportController.php:40-89` = simple listing `Order` + export Excel/PDF. Zéro clôture, zéro irréversibilité, zéro signature. `order_serial_no = date('dmy') . $id` → **non séquentiel par branche, trous inévitables**, pas un numéro fiscal.

**Exigences NF525 / loi 2017-1367 non satisfaites** :

| Exigence | Présent ? |
|---|---|
| Inaltérabilité (pas de UPDATE/DELETE) | ❌ (soft delete autorisé partout) |
| Sécurisation par empreinte cryptographique chaînée (HMAC) | ❌ |
| Numérotation séquentielle par branche sans trou | ❌ |
| Conservation 6 ans | ❌ |
| Journal fiscal d'événements | ❌ |
| Clôture journalière Z + période + annuelle | ❌ |
| Ticket rectificatif / annulation horodaté signé | ❌ |

**Conclusion** : l'exploitation commerciale en France est **impossible en l'état** sans régularisation (risque amende 7 500 €/an/caisse + rappel TVA).

### 4.3 Tiroir caisse

Aucune table `cash_drawer_sessions` / `cash_drawer_events` / `drawer_opens`. Aucun modèle. Aucun event (`DrawerOpened`, `CashCountingRecorded`, `DrawerDiscrepancy`). `kioskHardware.js` expose `cashDrawerOpen` côté kiosk mais **rien n'est persisté**. Aucun module comptage fin de journée, remise en banque, bordereau.

### 4.4 Dashboard — tuiles attendues

| Tuile attendue (brief §3.5) | Présent ? | Fichier |
|---|---|---|
| CA jour + comparaisons J-1 / J-7 / J-30 | ❌ (juste `salesSummary` mensuel) | `DashboardService.php:23-148` |
| Ticket moyen / panier moyen | ❌ | — |
| Taux conversion kiosk | ❌ | — |
| Top 10 produits jour | ⚠️ (sans fenêtre glissante) | `MostPopularItemsComponent.vue` |
| Alertes rupture temps réel | ❌ | — |
| Alertes hardware (imprimante/TPE/tiroir) | ❌ | — |
| Uptime borne(s) | ❌ | — |
| Audit trail | ⚠️ **Fuite cross-tenant** : `DashboardService::auditTrail` (L305-326) lit `ActionLog` sans `branch_id` ni scope | `DashboardService.php:305-326` |

### 4.5 TVA / arrondis / multi-devise

- **TVA** : `PricingService.php:141-152` + `TaxCalculator.php:9-17` appliquent `Item.tax_id` en dur, sans paramètre `orderType`. **Pas de cascade** dine-in 10 % / takeaway 5.5 % / alcool 20 %. Variations et extras héritent du tax_id parent. → **Déclaration TVA fausse** pour tout resto FR proposant de l'emporter.
- **Arrondis** : `round($value, 2)` partout (PHP `HALF_AWAY_FROM_ZERO` par défaut — colle DGCCRF), mais **money stocké en `decimal:6`** (`Order.php:59-63`, `Transaction.php:11-18`) et non en cents int. Risque dérive multi-étapes.
- **Multi-devise** : `currencies` existe avec `exchange_rate`, aucune FK `branches.currency_id`, aucune `orders.currency_code`. **Mono-EUR** en pratique. Déploiement multi-pays = réarchitecture.

---

## 5. Permissions + audit log + conformité

### 5.1 Matrice permissions Spatie actuelle

**2 permissions POS seedées** : `pos` (`PermissionTableSeeder.php:113`), `pos-orders` (`:121`). **8 rôles** : Admin, Customer, Delivery Boy, Waiter, Chef, Branch Manager, POS Operator, Stuff (`RoleTableSeeder.php:17-67`).

| Action POS sensible | Permission requise | Admin | Branch Mgr | POS Op | Waiter | Chef |
|---|---|---|---|---|---|---|
| Créer commande POS | `pos-orders` implicite | ✅ | ✅ | ✅ | ❌ | ❌ |
| Annuler après PAID | `pos-orders` (générique) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Supprimer commande (`destroy`) | `pos-orders` (+ aucun branch check) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Changer payment_status | `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Discount staff manuel | **AUCUNE** | open | open | open | open | open |
| Refund monétaire | n/a (feature absente) | — | — | — | — | — |
| Amend order (add/remove item) | n/a (absente) | — | — | — | — | — |
| Z fiscal / réouvrir Z | n/a (absente) | — | — | — | — | — |
| EOD / close drawer | n/a (absente) | — | — | — | — | — |

**Gaps** :
- Aucun rôle distinct caissier / runner / kitchen-lead (brief §3.7 les attend).
- Aucune granularité discount (seuil %, manager escalation).
- `PosOrderRequest.php:19-22`, `OrderRequest.php:19-22` → `authorize() { return true; }` partout. Aucun check permission au niveau FormRequest.

### 5.2 Audit log immuable

**Table `audit_logs` INEXISTANTE**. Seule `action_logs` existe (`database/migrations/2026_03_06_182733_create_action_logs_table.php`) :

- **Mutable** : `app/Models/ActionLog.php:8-22` = Eloquent standard sans `static::updating/deleting` blocker → UPDATE/DELETE libres.
- **Sans `branch_id`** : colonnes `user_id`, `action` (string libre), `resource` (string libre), `details` (text), `created_at/updated_at`. → `DashboardService::auditTrail()` (L305-326) renvoie **tous les logs globaux** à tout détenteur de permission `dashboard` (**fuite cross-tenant** pour Branch Managers).
- **Sans signature HMAC chaînée**, **sans enum d'action**, sans `session_id`/`ip`/`user_agent`.
- **Actions loguées** : L508 `Nouvelle commande Web/App`, L887 `Nouvelle commande POS`, L1189 `Nouvelle commande sur Table`, L1451 `Changement de statut`, L1508 `Statut paiement modifié`. Pas de log discount spécifique, pas de log refund/cashback, pas de log destroy.

### 5.3 Conformité fiscale FR (synthèse)

Voir §4.2. État : **BLOCKED réglementaire** sur 7 exigences NF525 / loi Finance 2018.

---

## 6. Events & state machine POS

### 6.1 Sortie brute des greps invariants

```bash
# SSOT pricing violé ?
rg "->input\('price'\)|->input\('total'\)|\$request\['price'\]" app/Http/Controllers/Admin/ app/Services/OrderService.php
→ No matches found  (OK)

# branch_id lu du payload ?
rg "->input\('branch_id'\)|\$request->branch_id" app/Http/Controllers/Admin/
→ No matches found  (OK — POS recoupe $request->branch_id avec $authUser->branch_id L576)

# écriture directe status (->update) ?
rg "->update\(\[\s*'status'" app/ -g '*.php' | rg -v OrderStateMachine
→ 1 hit: app/Services/KioskMachineService.php:152  (kiosk machine, pas Order)

# écritures inline ->status = (forme non couverte par grep ci-dessus)
→ 11 hits dans OrderService + FrontendOrderService :
  OrderService:1330, 1334, 1387, 1439
  FrontendOrderService:550, 661, 736
  OrderStateMachine:156 (seul site légitime)
  → VIOLATION invariant §1 "OrderStateMachine::apply() utilisé partout"

# dispatch avant commit ?
rg "Event::dispatch|::dispatch\(" app/Services/OrderService.php app/Services/FrontendOrderService.php | rg -v "afterCommit|shouldDispatchAfterCommit"
→ 34 matches OrderService + 15 FrontendOrderService
  → Convention "hors transaction" implicite (pas explicite afterCommit)
  → deliveryBoyOrderChangeStatus L1331-1335 dispatche AVANT save() !

# EventContract bypass ?
rg "broadcast\(" app/Events/
→ No matches found  (OK, tout passe par outbox + DispatchDomainEventsJob)

# audit log absent sur cancel/refund/discount ?
rg "OrderCancel|OrderRefund|applyDiscount" app/Services/ | rg -v "AuditLog|audit_log"
→ Seul cashBack L1385/1435 (loyalty only), pas de méthode métier dédiée

# idempotency
rg "X-Idempotency-Key|idempotency_key|Cache::lock" app/
→ POS sans Cache::lock (pré-check + unique DB seulement)
→ FrontendOrder AVEC Cache::lock(10s, block 5s)
→ Clé idempotency globale (pas scope branch)
```

### 6.2 Mapping méthodes OrderService POS × invariants

| Méthode | Tx | SSOT | `branch_id` | State machine | Event canonique | ActionLog | Idempotency |
|---|---|---|---|---|---|---|---|
| `posOrderStore` (L546-929) | ✅ L560 | ✅ L602 | ✅ L576 | ❌ `status=ACCEPT` direct L586 | ⚠️ `OrderCreated` hors tx L904 (pas afterCommit), zéro `OrderStatusChanged(PENDING→ACCEPT)` | ✅ L887 | ⚠️ pré-check + unique DB, pas Cache::lock, pas scope branch |
| `changeStatus` (admin L1405-1477) | ✅ L1412 | n/a | ✅ L1414 | ❌ `->status=X; save()` L1439 | ⚠️ `OrderStatusChanged` L1470 hors tx sans afterCommit | ✅ L1451 | n/a |
| `changeStatus` (auth=true L1370-1404) | ❌ aucune tx | n/a | ⚠️ | ❌ `->status=X` L1387 | ⚠️ dispatch hors tx | ⚠️ | n/a |
| `changePaymentStatus` (L1485-1526) | ❌ **aucune tx** | n/a | ✅ L1498 | ❌ (pas de state machine paiement) | ❌ **aucun event** (pas `OrderPaid`/`PaymentRecorded`) | ✅ L1508 (texte, sans montant) | ❌ aucune garde double-soumission |
| `deliveryBoyOrderChangeStatus` (L1312-1358) | ❌ aucune tx | n/a | ⚠️ owner only | ❌ `->status=X` L1334 | ❌ **mails dispatchés AVANT save** L1331-1335 | ❌ | n/a |
| `selectDeliveryBoy` (L1554-1580) | ❌ | n/a | ❌ | n/a | ⚠️ dispatches hors tx | ❌ | n/a |
| `destroy` (L1585-1598) | ✅ L1588 | n/a | ❌ **aucun branch check** | n/a | ❌ aucun event | ❌ aucun ActionLog | n/a |

**Méthodes absentes** attendues par les invariants : `addItem`, `removeItem`, `updateItem`, `amendOrder`, `splitPayment`, `refund`, `discount` (standalone).

### 6.3 Events déclarés dans contrat mais jamais dispatchés

| EventType | Déclaré | Dispatché | Classe Event existe |
|---|---|---|---|
| `ORDER_CREATED` | ✅ `EventContract:34` | ✅ | ✅ |
| `ORDER_STATUS_CHANGED` | ✅ `:35` | ✅ | ✅ |
| `ORDER_ITEM_ADDED` | ✅ `:37` | ❌ | ❌ |
| `ORDER_CANCELLED` | ✅ `:38` | ❌ (remplacé par `OrderStatusChanged` générique) | ❌ |
| `PAYMENT_RECORDED` | ❌ | ❌ | ❌ |
| `ORDER_REFUNDED` | ❌ | ❌ | ❌ |
| `ITEM_AVAILABILITY_CHANGED` | ✅ | ✅ | ✅ (mais POS ne s'abonne pas) |
| `STOCK_LOW` | ✅ `:53` | ❌ | ❌ |

### 6.4 Outbox / jobs — ce qui tient

- `DispatchDomainEventsJob` : `onQueue(high)`, `tries=5`, `backoff=[1,5,30,300]s`, early-exit `dispatched_at !== null`, `assertEnvelopeValid()` avant `Pusher->trigger()`, `failed()` persiste `last_error`.
- Commandes `foodking:outbox:rescue` (re-queue > 2 min stale) et `foodking:outbox:retry-failed` (reset attempts + re-queue), couvertes par `OutboxTest:110-173`.
- **Gaps** : `correlation_id` régénéré par listener au lieu d'être lu depuis `X-Correlation-ID` request → trace E2E cassée. `DomainEvent::scopeFailed(4)` incohérent avec `tries=5`. `domain_events.channel` stocké en JSON-string dans VARCHAR. Pas de dead-letter, pas d'alerting operator après 5 retries.

---

## 7. Tests existants & trous de couverture

### 7.1 Existant

| Test | Focus | État |
|---|---|---|
| `OutboxTest` | 4 tests : persistance `domain_event` sur `OrderCreated`, rollback, dispatched_at, rescue/retry-failed | ✅ |
| `OrderStateMachineApplyTest` | Transitions légales + illegal throws + `requiresReason` | ✅ |
| `OrderFlowTest::test_order_price_recalculated_server_side` | hacker envoie `price=0.01`, commande créée au prix DB | ✅ |
| `ConcurrentOrderTest` | Idempotency kiosk (2 tests) + loyalty concurrent lockForUpdate | ✅ (kiosk only) |
| `EventContractTest`, `KioskEventTest`, `KioskEventBranchIsolationTest` | Envelope V1 + whitelist events kiosk | ✅ |
| `PosOrderTaxTest`, `PosDiscountTest`, `PosPriorityApiTest`, `PosUITest`, `POSComprehensiveTest` | Cités par briefs ciblés | ⚠️ non inspectés en détail |
| `BranchIsolationTest` | **PLACEHOLDER** `$this->assertTrue(true)` L9-13 | ❌ **vide** |
| Vitest `posCart.spec.js` | Panier POS client | ✅ partiel |

### 7.2 Trous de couverture (P1/P2)

- Zéro test Feature POS double-submit `X-Idempotency-Key` (kiosk couvert, pas POS).
- Zéro test `changePaymentStatus` (transitions UNPAID→PAID→REFUNDED).
- Zéro test `OrderStateMachine::apply()` appelé depuis un flux POS live (uniquement unit).
- Zéro test symétrie POS↔Kiosk.
- Zéro test amendement (pour cause, feature absente).
- `BranchIsolationTest` vide → invariant critique non couvert.
- Zéro test end-to-end `posOrderStore → outbox → Pusher broadcast → consumer` (les 3 maillons sont testés isolément).

---

## 8. Priorité consolidée — 58 findings triés P0/P1/P2/P3

Format abrégé. Détail complet + `fix_proposal` + `invariants touchés` dans les 4 sous-rapports `AUDIT_POS_SECTION_{1..4}_2026-04-18.md` référencés par `origin`.

### P0 — Bloquants (réglementaire / safety / invariants cassés / fraude triviale)

| ID | Finding | Fichier:ligne | Origin |
|---|---|---|---|
| POS-GA-F-01 | **MISSING `ZReport` (NF525 / loi Finance 2018 / décret 2017-1367)** — aucune migration, modèle, controller, hash chain, signature | absent (backlog) | S3-F-1 |
| POS-GA-F-02 | **MISSING `XReport`** (snapshot intraday CA / payment_method / staff / heure) | absent | S3-F-2 |
| POS-GA-F-03 | **MISSING `cash_drawer_sessions`** (ouvertures, comptage, écarts, motif) | absent | S3-F-3 |
| POS-GA-F-04 | **MISSING `audit_logs` immuable** — `action_logs` existante mutable (pas de `booted::updating/deleting`) + sans `branch_id` → fuite cross-tenant via `DashboardService::auditTrail` | `ActionLog.php:8-22`, `DashboardService.php:305-326`, migration `2026_03_06_182733` | S3-F-4, S3-F-13 |
| POS-GA-F-05 | **Stock non libéré** sur CANCELED/REJECTED/RETURNED (auto-86 fantôme en fin journée) | `AvailabilityService.php:118-163`, `grep incrementForOrder` = 0 | S3-F-5 |
| POS-GA-F-06 | **Discount manuel sans permission ni seuil ni audit structuré** → fraude caissier triviale | `PricingService.php:213-217`, `DiscountCalculator.php`, `PosComponent.vue:1139-1158`, `PosOrderRequest.php:38` | S1-F-04, S3-F-6 |
| POS-GA-F-07 | **MISSING endpoint admin `AvailabilityService::toggle`** (86 manuel) — service implémenté mais jamais exposé | `AvailabilityService.php:32-74` | S3-F-7 |
| POS-GA-F-08 | **Modèle paiement binaire PAID/UNPAID — multi-tender structurellement impossible** (pas de `PARTIALLY_PAID`, pas de `order_payments`, pas de `paid_amount`) | `PaymentStatus.php:1-10`, `OrderService.php:1485-1526` | S1-F-01, S3-F-8, S4-F-01 |
| POS-GA-F-09 | **`OrderStateMachine::apply()` utilisé nulle part** en prod — 11 écritures `$order->status = X; save(); recordTransition()` inline | `OrderService.php:1334, 1387, 1439`, `FrontendOrderService.php:550, 661, 736`, `OrderStateMachine.php:156` (seul site légitime) | S1-F-02, S4-F-02 |
| POS-GA-F-10 | **`changePaymentStatus` sans transaction, sans state machine paiement, sans event, sans idempotency** — cœur du gap backend | `OrderService.php:1485-1526` | S4-F-01 |
| POS-GA-F-11 | **Events dispatchés hors `DB::afterCommit()` explicite** — convention "hors tx" implicite fragile au refactor ; `OrderStatusChanged(PENDING→ACCEPT)` jamais émis côté POS ; `changePaymentStatus` n'émet rien | `OrderService.php:898-908, 1397-1404, 1464-1473`; asymétrie vs `FrontendOrderService.php:549-553` | S1-F-03, S4-F-03, S4-F-09 |
| POS-GA-F-12 | **Plusieurs endpoints POS mutatifs sans `DB::transaction`** (`changePaymentStatus`, `deliveryBoyOrderChangeStatus`, `selectDeliveryBoy`, `changeStatus` auth=true) | `OrderService.php:1312-1358, 1370-1404, 1485-1526, 1554-1580` | S4-F-04 |
| POS-GA-F-13 | **`PaymentService::cashBack` ne logue rien et rate le cas "pas de Transaction préalable"** (commande POS cash, refund silencieux) | `PaymentService.php:29-50` | S4-F-06 |
| POS-GA-F-14 | **`OrderService::destroy` physique sans branch check, sans event, sans ActionLog** — violation directe §1 + §1.2 + NF525 | `OrderService.php:1585-1598`, `PosOrderController.php:59-68`, `routes/api.php:628` | S3-F-18, S4-F-22 |
| POS-GA-F-15 | **Drawer POS ne centralise qu'une seule source** (kiosk cash) — 1 sur 5+ sources visibles | `PosComponent.vue:1018-1040` | S2-F-01 |
| POS-GA-F-16 | **`BranchIsolationTest` est un placeholder** `assertTrue(true)` — invariant critique non couvert | `tests/Feature/BranchIsolationTest.php:9-13` | S2-F-03 |
| POS-GA-F-17 | **Dine-in et table selector hardcodés `v-if="false"`** — décision métier non configurable ; code mort `dining_table_id` dans FormRequest | `PosComponent.vue:121, 235` | S1-F-07 |
| POS-GA-F-18 | **Aucune intégration TPE** — `pos_payment_note` = 4 chiffres tapés à la main, aucun `auth_code`/`rrn`/ACK serveur, backend marque PAID sans preuve | `PaymentComponent.vue:64-67, 207-211`, `PosOrderRequest.php:60` | S1-F-05 |
| POS-GA-F-19 | **Aucune ouverture tiroir-caisse** depuis POS (bridge existe côté kiosk, pas câblé POS) | `PaymentComponent.vue:191-277`, `kioskHardware.js:259-262` | S1-F-08 |
| POS-GA-F-20 | **Ticket TVA non ventilée par taux** — ligne "Total TVA" unique, non-conforme art. 242 nonies A CGI | `ReceiptComponent.vue:105-112` | S1-F-09 |

### P1 — Hauts (fragilité / gaps compétitifs / audit trail partiel)

| ID | Finding | Fichier:ligne | Origin |
|---|---|---|---|
| POS-GA-F-21 | **MISSING amend order** (add/remove/update item post-création) — feature totalement absente, `OrderItemAdded` event orphelin | `routes/api.php:625-638`, `grep amendOrder\|addItem` = 0, `EventContract.php:37` | S2-F-04, S3-F-9 |
| POS-GA-F-22 | **Aucune action POS → cancel / refund / discount / reassign** depuis drawer (1 action "Encaisser" seulement) | `PosComponent.vue:608-617` | S2-F-05 |
| POS-GA-F-23 | **Aucun split bill** (par item / personne / montant) | `routes/api.php:621-638`, `grep splitBill\|order_payments` = 0 | S1-F-06 |
| POS-GA-F-24 | **Events `OrderCancelled` et `PaymentRecorded` / `OrderRefunded` jamais dispatchés** (contrat `EventContract::BROADCAST_MAP:37-38` drift vs code) | `EventContract.php:34-41`, `app/Events/` (classes absentes) | S2-F-06, S4-F-08 |
| POS-GA-F-25 | **Aucun filtre source/statut/table/staff/heure dans le drawer POS** — hardcode `status=[4,7,8]`, `order_type=25\|10`, `payment_method=1` | `PosComponent.vue:575-625, 1018-1040` | S2-F-07 |
| POS-GA-F-26 | **`OrderType::POS(15)` absent de toutes les colonnes KDS** (4 colonnes : DINING_TABLE, DELIVERY, TAKEAWAY, KIOSK) | `KitchenDisplaySystemComponent.vue:602-655` | S2-F-02 |
| POS-GA-F-27 | **`Source` enum ne couvre pas les delivery partners** (Uber Eats, Deliveroo, Just Eat) — 3 constantes WEB/APP/POS | `app/Enums/Source.php:1-12` | S2-F-12 |
| POS-GA-F-28 | **Idempotency POS sans `Cache::lock` + clé non scopée par `branch_id`** (kiosk fait mieux : `Cache::lock('frontend_order_idempotency_…', 10)->block(5)`) | `OrderService.php:550-556`, migration `2026_03_25_002938_add_idempotency_key_to_orders_table.php:17` vs `FrontendOrderService.php:129` | S4-F-07 |
| POS-GA-F-29 | **Rules serveur wizard : zéro validation min/max / required par attribut** (sandwich peut partir sans pain) | `ValidJsonOrder.php:31-73`, `PosOrderRequest.php:58` | S1-F-12 |
| POS-GA-F-30 | **Divergence structure items POS vs Kiosk** — pas de contrat commun versionné | `ItemComponent.vue:1176-1224` vs `KioskPosWizardComponent.vue` | S1-F-13 |
| POS-GA-F-31 | **Fallback `queue_number` timestamp-based sur `LockTimeoutException`** casse la séquentialité NF525 | `OrderService.php:467, 794, 1143`, `FrontendOrderService.php:389-392` | S4-F-11 |
| POS-GA-F-32 | **Loyalty burn absent de `posOrderStore`** (kiosk fait L434-499 avec `lockForUpdate` + `LoyaltyTransaction` ledger) | `OrderService.php:829-836` vs `FrontendOrderService.php:434-499` | S4-F-13 |
| POS-GA-F-33 | **`deliveryBoyOrderChangeStatus` dispatche mails/SMS/push AVANT `$order->save()`** sans tx | `OrderService.php:1330-1335` | S4-F-05 |
| POS-GA-F-34 | **Aucune réimpression ni log d'impression** — pas de `PrintJob` queue / retry / journal | `ReceiptComponent.vue:10-14`, absence `PrintLog` | S1-F-11 |
| POS-GA-F-35 | **Ticket n'imprime pas `queue_number`** (seul `token` client-side) — OSS affiche `A0001`, ticket dit "N° 42" | `ReceiptComponent.vue:152-156`, `OrderService.php:801-802` | S1-F-10 |
| POS-GA-F-36 | **Allergens absents d'`ItemResource` admin** → caissier aveugle (violation invariant §1.2 "allergens visibles caissier") | `app/Http/Resources/ItemResource.php:19-65` | S3-F-11 |
| POS-GA-F-37 | **TVA sans cascade `order_type`** (dine-in 10 % / takeaway 5.5 % / alcool 20 % non codé) | `PricingService.php:141-152`, `TaxCalculator.php:9-17` | S3-F-10 |
| POS-GA-F-38 | **`order_serial_no` non séquentiel par branche** (`date('dmy') . $id` global) — inutilisable NF525 | `OrderService.php:475, 801` | S3-F-12 |
| POS-GA-F-39 | **`action_logs.branch_id` absent → fuite cross-tenant dashboard** + audit trail non scopé | migration `2026_03_06_182733:15-22`, `DashboardService.php:305-326` | S3-F-13 |
| POS-GA-F-40 | **Couverture tests POS backend faible** (pas de test double-submit POS, pas de test `changePaymentStatus`, `BranchIsolationTest` vide, pas de symétrie POS↔Kiosk) | `tests/Feature/` cf §7.2 | S4-F-21 |
| POS-GA-F-41 | **Panier localStorage `pos_cart_v2` non scopé par `branch_id`/`user_id`** — fuite panier entre caissiers | `posCart.js:6-44` | S1-F-15 |
| POS-GA-F-42 | **Token séquentiel journalier fabriqué client localStorage** — collisions entre caisses, reset clock-drift | `PosComponent.vue:1253-1266` | S1-F-16 |

### P2 — Moyens (qualité, parité, granularité permissions)

| ID | Finding | Fichier:ligne | Origin |
|---|---|---|---|
| POS-GA-F-43 | **`KitchenDisplaySystemOrderService::list` filtre `branch_id` via `LIKE '%X%'`** — faux positifs sur `branch_id=12` matchant `%1%` et `%2%` | `KitchenDisplaySystemOrderService.php:75-85` | S2-F-13 |
| POS-GA-F-44 | **`OrderService::list` dépend exclusivement de `BranchScope`** — pas de defense-in-depth explicite | `OrderService.php:102-170` | S2-F-09 |
| POS-GA-F-45 | **POS ne s'abonne pas à `ItemAvailabilityChanged`** — toggle 86 admin non reflété live côté POS | `PosComponent.vue:1004-1007` vs `kioskMenu.js:143-210` | S2-F-08 |
| POS-GA-F-46 | **`buildWizardRestorePayload` basé sur heuristiques de noms FR** (`includes('pain'|'viande'|'sauce'|'accompagnement')`) — casse i18n + renommage admin | `ItemComponent.vue:721-915` | S1-F-14 |
| POS-GA-F-47 | **`PosOrderRequest` valide encore `total` et `subtotal`** depuis le payload (pré-check UI, unset L566 sert de garde-fou — surface de régression future) | `PosOrderRequest.php:36-48, 77-82` | S4-F-10 |
| POS-GA-F-48 | **`correlation_id` régénéré par listener** (pas propagé depuis `X-Correlation-ID`) — trace E2E cassée | `PersistOrderCreatedToOutbox.php:33`, `PersistOrderStatusChangedToOutbox.php:32` | S4-F-16 |
| POS-GA-F-49 | **Payloads events minimalistes** — `OrderStatusChanged` omet `reason`, `actor_id`, `source_surface`; `OrderCreated` omet `source`, `pos_payment_method`, `total_tax` | `PersistOrderCreatedToOutbox.php:24-30`, `PersistOrderStatusChangedToOutbox.php:22-29` | S4-F-15 |
| POS-GA-F-50 | **`DomainEvent::scopeFailed(4)` incohérent avec `tries=5`** — event à `attempts=4` éligible à re-queue + retry auto → double dispatch possible | `DomainEvent.php:44-48` vs `DispatchDomainEventsJob.php:21-23` | S4-F-20 |
| POS-GA-F-51 | **`FrontendOrderService::myOrderStore` ne crée pas d'ActionLog** (asymétrie POS) | `FrontendOrderService.php:500-555` vs `OrderService.php:887-892` | S4-F-14 |
| POS-GA-F-52 | **Aucun historique commandes closes cross-source** depuis POS (ni search, ni re-print, ni duplicate) | `routes/api.php:625-638` | S2-F-11 |
| POS-GA-F-53 | **Dashboard sans tuile rupture / hardware / uptime borne** | `resources/js/components/admin/dashboard/*` | S3-F-17 |
| POS-GA-F-54 | **Polling POS borné à kiosk cash** — catalogue/availability non polés en mode dégradé | `PosComponent.vue:988-997` | S2-F-14 |
| POS-GA-F-55 | **Aucune notification sonore / toast sur nouvelle commande** côté POS | `PosComponent.vue:1005` | S2-F-10 |
| POS-GA-F-56 | **Money en `decimal:6` au lieu de cents int** (dérive possible multi-étapes) | `Order.php:59-63`, `Transaction.php:11-18` | S3-F-15 |
| POS-GA-F-57 | **Rôles runner / kitchen-lead absents du seed** (brief §3.7 les attend) | `RoleTableSeeder.php:17-67` | S3-F-14 |
| POS-GA-F-58 | **Aucune permission POS granulaire seedée** (pas de `pos-cancel-after-paid`, `pos-refund`, `pos-discount-up-to-X`, `pos-amend`, `pos-reopen-z`, `pos-close-day`, `pos-manage-drawer`, `items-manage-availability`) | `PermissionTableSeeder.php:113-125` | S3-F-16 |

### P3 — Faibles

| ID | Finding | Fichier:ligne | Origin |
|---|---|---|---|
| POS-GA-F-59 | **`PusherBeams` / FCM staff POS absent** (device token push — onglet fermé = rien reçu) | grep `PusherBeams` = 0 | S2-F-16 |
| POS-GA-F-60 | **Outbox sans dead-letter / alerting ops après 5 retries** — row reste en `attempts=5` + `last_error`, aucun dashboard | `DispatchDomainEventsJob.php:81-98` | S4-F-18 |
| POS-GA-F-61 | **`domain_events.channel` stocké en JSON-string dans VARCHAR** (double encode/decode) | migration `2026_04_15_200000:18`, `DispatchDomainEventsJob.php:45-49` | S4-F-19 |
| POS-GA-F-62 | **`SimulateKioskOrders` produit `A001` 3 digits**, incompatible avec prod 4 digits (dev-only) | `app/Console/Commands/SimulateKioskOrders.php:38` | S4-F-12 |
| POS-GA-F-63 | **Allergens double source** (JSON `items.allergen_flags` + pivot `item_allergen` sans listener sync) | `Item.php:43, 71` | S3-F-20 (resurface kiosk C-5) |
| POS-GA-F-64 | **Multi-devise non scopé branche** (V2+) | `Currency.php:10`, pas de FK `branches.currency_id` | S3-F-19 |

**Note de dédoublonnage** : 64 IDs dans la table, 58 findings distincts — certains sont des sous-items d'un finding parent consolidé. La table `FINDINGS_POS_TRACKER.md` liste les 64 IDs pour traçabilité fine.

---

## 9. Matrice de recouvrement avec kiosk Phase 9 (signal pour Track A)

Les findings suivants sont **partagés** kiosk + POS et doivent être traités en **une seule passe backend coordonnée** (SYNC_PROTOCOL_KIOSK_POS.md §4 = lock sur zone shared).

| Finding POS | Finding kiosk miroir (`AUDIT_KIOSK_GLOBAL_2026-04-18.md`) | Zone shared impactée | Recommandation inter-tracks |
|---|---|---|---|
| POS-GA-F-05 (release stock on cancel) | §2.2 C-2 (`UPDATE_ITEM` ignore `is_available`) | `app/Services/Menu/AvailabilityService.php` + `app/Services/ItemService.php` + `kioskMenu.js` mutation | Solution commune : listener `ReleaseItemAvailabilityOnCanceledOrder` + fix mutation. À faire en kiosk P9.2 catalog backend ou POS-9.5 shared. |
| POS-GA-F-07 (toggle 86 endpoint) | §2.2 C-7 (idem) | `AvailabilityController` à créer + invalidation cache `/menu` | **Un seul controller partagé**, exposé admin POS + admin kiosk. |
| POS-GA-F-09 (state machine bypass) | §3 kiosk respecte encore moins (FrontendOrderService L550, 661, 736) | `OrderStateMachine::apply()` + tous call-sites `OrderService`/`FrontendOrderService` | Vague commune POS-9.2 + kiosk P9.5 (backend pipeline). **Séquentiel** (cf SYNC_PROTOCOL §3 : kiosk P9.5 d'abord). |
| POS-GA-F-24 (events drift `OrderCancelled`/`PaymentRecorded`) | §3.3 O-7 (`OrderItemAdded`/`OrderItemUpdated` orphelins) | `EventContract::BROADCAST_MAP` + listeners outbox | Refonte contract + événements manquants en vague backend shared. |
| POS-GA-F-28 (idempotency scope branch) | §3.3 O-6 (idempotency_key UNIQUE global non scopé branch) | Migration `orders.idempotency_key` → unique composite `(branch_id, key)` + `Cache::lock` côté POS | Migration shared → **lock obligatoire**, coordonner avec kiosk P9.2. |
| POS-GA-F-27 (Source enum partenaires) | §5 (funnel attribution incomplet) | `app/Enums/Source.php` + payload events | Extension enum partagée, impacts SalesReport + PosOrderListComponent + événements. |
| POS-GA-F-36 (allergens dans ItemResource admin) | §2.3 (codes EN vs DATA_CONTRACT FR) | `ItemResource.php`, `NormalItemResource.php`, `AllergensSeeder`, `Item.allergen_flags` vs pivot | Passer POS à `NormalItemResource` pattern ou fusionner. |
| POS-GA-F-63 (allergens double source) | §2.2 C-5 (double source JSON + pivot) | `AllergenService::projectFlags` à créer + listener sync | **Idem kiosk** — 1 ticket unifié. |
| POS-GA-F-04 (audit_logs immuable) | KioskAdminOverrideAuditTest lit déjà ActionLog | Migration `action_logs` → `audit_logs` INSERT-only + `branch_id` | Migration coordonnée, impacts les 2 tracks. |
| POS-GA-F-26 (`OrderType::POS(15)` absent KDS) | §3.3 O-5 (déjà noté côté kiosk) | `KitchenDisplaySystemComponent.vue` colonnes client-side + service | Correction atomique côté KDS admin (propriété Track B POS). |

**Protocole** : chaque modification de zone shared listée ci-dessus doit poser un `LOCK_trackB_<file>_<date>.md` dans `tasks/phase9-sync/` avant commit et consigner dans `BROADCAST_<date>.md` (SYNC_PROTOCOL §4-5).

---

## 10. Check-list 15 invariants — ligne à ligne

Conformément au brief §6 POS_MASTER_BRIEF et §1 POS_INVARIANTS_AND_GATES.

| # | Invariant | Réponse | Preuve |
|---|---|---|---|
| 1 | **SSOT pricing POS** : `posOrderStore` ne lit **jamais** le prix du payload | ✅ **OK** | `app/Services/OrderService.php:566` `unset($validated['total'], $validated['subtotal'], $validated['discount']);`, recalc via `PricingService::calculateOrder` (`:602`) avec flag `pricing.use_ssot_service=true`. Grep `->input('price'\|'total')` dans `app/Http/Controllers/Admin/` = 0 hit. Note : `PosOrderRequest.php:36-48` valide encore `total` pour pré-check UI — à marquer `nullable` (POS-GA-F-47). |
| 2 | **`branch_id` server-side** : tous les endpoints admin POS filtrent par `$user->branch_id`, jamais du payload | ⚠️ **PARTIEL** | Grep `->input('branch_id')\|$request->branch_id` dans `app/Http/Controllers/Admin/` = 0 hit. MAIS `OrderService::posOrderStore` **accepte** `$request->branch_id` et le **compare** à `$authUser->branch_id` (`:576`) au lieu de forcer `$authUser->branch_id` directement — pour admin (`branch_id=0`) bypass possible (accepté par design). `OrderService::list` (`:102-170`) repose exclusivement sur `BranchScope` sans defense-in-depth (POS-GA-F-44). `KitchenDisplaySystemOrderService:75-85` utilise `where('branch_id','like','%…%')` → bug latent (POS-GA-F-43). `OrderService::destroy:1585-1598` **sans aucun branch check** (POS-GA-F-14). |
| 3 | **`OrderStateMachine::apply()` utilisé partout** (pas d'écriture directe `status`) | ❌ **VIOLÉ** | 11 écritures `$order->status = X; save()` hors `apply()` : `OrderService.php:1330-1334, 1387, 1439`, `FrontendOrderService.php:550, 661, 736`. `OrderStateMachine::apply()` grep dans `app/` = 0 call-site. Seul `OrderStateMachine::recordTransition()` est appelé (post-hoc, non bloquant). Cf. POS-GA-F-09. |
| 4 | **`DB::afterCommit()` systématique** avant dispatch | ❌ **VIOLÉ** (convention implicite) | Listeners outbox font OK (`PersistOrderCreatedToOutbox:37`, `PersistOrderStatusChangedToOutbox:36`). MAIS les services eux-mêmes utilisent la convention "dispatch hors bloc `DB::transaction(function(){})`" sans `DB::afterCommit()` explicite → fragile au refactor (POS-GA-F-11). `deliveryBoyOrderChangeStatus:1330-1335` dispatche **avant** `save()` (POS-GA-F-33). `changePaymentStatus`, `selectDeliveryBoy`, `destroy` et `changeStatus` branche `auth=true` sans transaction (POS-GA-F-12). |
| 5 | **`EventContract V1`** respecté par tous les events POS | ⚠️ **PARTIEL** | Enveloppe V1 validée strictement avant broadcast (`DispatchDomainEventsJob:56-68` + `EventContract::assertEnvelopeValid`). Payload required keys OK pour `OrderCreated` et `OrderStatusChanged`. MAIS 4 events du contrat jamais dispatchés (`ORDER_ITEM_ADDED:37`, `ORDER_CANCELLED:38`, `PAYMENT_RECORDED`, `ORDER_REFUNDED` — POS-GA-F-24). `correlation_id` régénéré par listener (POS-GA-F-48), payloads minimalistes (POS-GA-F-49). Grep `broadcast(` dans `app/Events/` = 0 hit (pas de bypass direct). |
| 6 | **Idempotency POS** (double-submit, retry TPE) protégé par `X-Idempotency-Key` + `Cache::lock` | ⚠️ **PARTIEL** | POS a `X-Idempotency-Key` (`OrderService:550`) + unique DB + catch 23000 (`:916`), MAIS **sans `Cache::lock`** (kiosk fait mieux : `Cache::lock('frontend_order_idempotency_…', 10)->block(5)` `FrontendOrderService:129`). Clé non scopée `(branch_id, key)` (POS-GA-F-28). Zéro test Feature double-submit POS (POS-GA-F-40). Aucun middleware dédié `Idempotency`. |
| 7 | **Permissions Spatie** avant cancel / refund / discount > seuil | ❌ **VIOLÉ** | Seules 2 permissions POS seedées (`pos`, `pos-orders`). Aucune granularité `pos-cancel-after-paid`, `pos-refund`, `pos-discount-up-to-X`, `pos-amend`, `pos-reopen-z` (POS-GA-F-58). Discount staff sans check (POS-GA-F-06). `FormRequest::authorize()` retourne `true` partout. Refund inexistant. |
| 8 | **Audit log immuable** pour actions sensibles | ❌ **VIOLÉ** | Table `audit_logs` **inexistante**. `action_logs` existante (a) **mutable** (pas de `booted::updating/deleting`), (b) **sans `branch_id`** (fuite cross-tenant via `DashboardService::auditTrail` L305-326), (c) sans signature HMAC chaînée, (d) sans enum d'action, (e) sans `session_id`/`ip`/`user_agent`. Cf. POS-GA-F-04, POS-GA-F-39. Le `cashBack` ne logue rien (POS-GA-F-13). `destroy` ne logue rien (POS-GA-F-14). |
| 9 | **Z fiscal** séquentiel, signé, non-supprimable (loi Finance 2018 anti-fraude TVA) | ❌ **VIOLÉ** (MISSING) | 0 migration `z_reports` / 0 modèle / 0 controller / 0 hash chain / 0 signature / 0 archivage 6 ans / 0 numérotation séquentielle par branche. `order_serial_no = date('dmy').id` non-séquentiel (POS-GA-F-38). Fallback `queue_number` timestamp-based casse séquentialité (POS-GA-F-31). `destroy` physique (POS-GA-F-14). Cf. POS-GA-F-01. Blocage réglementaire FR. |
| 10 | **Tiroir-caisse** : toute ouverture loggée (motif, staff, timestamp) | ❌ **VIOLÉ** (MISSING) | 0 table `cash_drawer_sessions` / 0 modèle / 0 event `DrawerOpened` / 0 comptage. `kioskHardware.js` expose `cashDrawerOpen` côté borne mais **aucune persistance serveur**. Pas d'ouverture tiroir depuis POS (POS-GA-F-19). Cf. POS-GA-F-03. |
| 11 | **Allergens visibles pour caissier** (conseil client) | ❌ **VIOLÉ** | `ItemResource.php:19-65` (admin, utilisé par POS) **n'expose pas** `allergens`. Seule `NormalItemResource.php:35-49` (kiosk) le fait. Caissier aveugle. Cf. POS-GA-F-36. Règlement UE 1169/2011 non respecté côté POS. |
| 12 | **TVA cascade** correcte item → variation → extra selon `order_type` | ❌ **VIOLÉ** | `PricingService.php:141-152` + `TaxCalculator.php:9-17` appliquent `Item.tax_id` en dur, sans paramètre `orderType`. Aucune cascade dine-in 10 % / takeaway 5.5 % / alcool 20 %. Variations et extras héritent du tax_id parent. Un burger dine-in et takeaway = même TVA. Cf. POS-GA-F-37. Déclaration TVA FR fausse. |
| 13 | **Multi-tenders** : encaissement partiel + complément supporté | ❌ **VIOLÉ** | Enum `PaymentStatus` binaire PAID(5)/UNPAID(10). Aucune table `order_payments`. `pos_payment_method` colonne unique. Pas de `paid_amount`. Pas de `PARTIALLY_PAID`. Cas quotidien resto (cash+carte, acompte+solde, TR+appoint) impossible. Cf. POS-GA-F-08. |
| 14 | **Temps réel** : Pusher subscribe + fallback polling + reconnection backoff | ⚠️ **PARTIEL** | Pusher auth canal `branch.{id}` OK (`routes/channels.php:25-39`). POS subscribe `private-branch.{branchId}` OK (`PosComponent.vue:999-1010`). Fallback polling binaire 10/60 s limité à `loadKioskCashOrders` (pas catalogue — POS-GA-F-54). POS ne s'abonne pas à `ItemAvailabilityChanged` (POS-GA-F-45). Reconnection backoff géré par `window._wsService` non logé. Pas de son/toast nouvelle commande (POS-GA-F-55). Pas de FCM staff (POS-GA-F-59). |
| 15 | **Dashboard tuiles** : data scopées branche, rafraîchissement cohérent | ⚠️ **PARTIEL** | `BranchScope` global sur `Order`, `Item`, `DiningTable`, `User` → la plupart des requêtes dashboard OK. **Exception P0** : `DashboardService::auditTrail` lit `ActionLog` sans `branch_id` ni scope → fuite cross-tenant (POS-GA-F-04, POS-GA-F-39). Tuiles manquantes : CA J-1/J-7/J-30 comparatif, ticket/panier moyen, conversion kiosk, rupture temps réel, hardware (imprimante/TPE/tiroir), uptime borne (POS-GA-F-53). |

**Synthèse invariants** : **0 PASS complet**, **5 PARTIAL**, **10 VIOLÉ**. Aucun des 15 invariants n'est tenu strictement. Les 5 partiels sont les plus proches d'un PASS (SSOT pricing ≈ OK modulo POS-GA-F-47, events respectent V1 modulo 4 events jamais dispatchés, etc.).

---

## 11. Recommandation — structure des 10 vagues POS-9.1 → POS-9.10 (esquisse Phase POS-B)

**Esquisse uniquement.** Le plan détaillé avec gates, dépendances précises, estimation et parallélisation est livrable Phase POS-B (sur validation humaine de POS-A).

**Règle d'or SYNC_PROTOCOL §3** : tout ce qui touche zone backend shared (notamment `OrderService`, `OrderStateMachine`, `EventContract`, migration `orders`, `domain_events`, `action_logs`) est **séquentiel** avec les vagues kiosk (P9.2, P9.5) — lock obligatoire, handoff écrit.

### POS-9.1 — Stop-the-bleed (2-3 jours)

**But** : éliminer les fraudes triviales, les fuites cross-tenant, et les écritures physiques irréversibles **avant** toute autre feature. Aucun nouveau modèle métier.

- POS-GA-F-06 Discount staff : permission Spatie `pos-discount-up-to-10` + `pos-discount-over-10-requires-manager`, `authorize()` dans `PosOrderRequest`, motif obligatoire, log structuré.
- POS-GA-F-14 `destroy` : remplacer par `cancelWithReceipt` soft-delete + branch check + ActionLog + bloquer route DELETE côté serveur (HTTP 405).
- POS-GA-F-16 `BranchIsolationTest` réel : scenarios "Branch A staff ne voit pas order B" sur tous les endpoints admin.
- POS-GA-F-39 `action_logs.branch_id` additif + backfill + scope `DashboardService::auditTrail`.
- POS-GA-F-43 `KitchenDisplaySystemOrderService::list` : remplacer `LIKE` par `where('branch_id', (int)$request)`.
- POS-GA-F-17 Dine-in / table selector `v-if="false"` : décision métier (supprimer ou exposer via config branche).

### POS-9.2 — State machine canonicalisation (séquentiel avec kiosk P9.5)

- POS-GA-F-09 Refactor 11 écritures inline `->status = X` via `OrderStateMachine::apply($order, $next, $actor, $reason)`.
- POS-GA-F-11 `DB::afterCommit()` explicite sur tous les dispatches dans les services.
- POS-GA-F-33 `deliveryBoyOrderChangeStatus` : réordonner save → dispatches.
- POS-GA-F-12 Wrapper `DB::transaction` sur tous les endpoints mutatifs.
- Tests régression `OrderStateMachineInServiceTest` + `ConcurrentOrderTest` étendus POS.

### POS-9.3 — Paiement multi-tender + events canoniques

- POS-GA-F-08 Migration `order_payments` (one-to-many) + enum `PaymentStatus` étendu `PARTIALLY_PAID`/`REFUNDED` + `payment_status` dérivé de Σ `payments.amount` vs `order.total`.
- POS-GA-F-10 `changePaymentStatus` → `OrderPaymentStateMachine` + transaction + event `PaymentRecorded`.
- POS-GA-F-13 `PaymentService::cashBack` logue systématiquement + crée ligne `type=refund`.
- POS-GA-F-23 Service `PaymentService::splitTender` + UI drag-n-drop `PosPaymentComponent`.
- POS-GA-F-24 Events `OrderCancelled`, `PaymentRecorded`, `OrderRefunded` dispatchés + listeners outbox.

### POS-9.4 — Conformité fiscale NF525 (vague la plus lourde, 3-4 jours)

- POS-GA-F-01 Migration `z_reports` INSERT-only + hash chaîné HMAC + signature + archivage.
- POS-GA-F-02 Endpoint `GET /admin/x-report` read-only intraday.
- POS-GA-F-38 Compteur `order_sequence_no` atomique par branche via `Cache::lock`.
- POS-GA-F-04 Migration `audit_logs` INSERT-only + remplacement progressif `ActionLog::create` → `AuditLog::write()`.
- POS-GA-F-31 Fallback `queue_number` → lever 503 au lieu de timestamp-based.

### POS-9.5 — Tiroir caisse + stock release

- POS-GA-F-03 Migrations `cash_drawer_sessions` + `cash_drawer_events` + permission `pos-manage-drawer` + UI counting.
- POS-GA-F-05 Listener `ReleaseItemAvailabilityOnCanceledOrder` (shared kiosk).
- POS-GA-F-07 Endpoint admin `POST /admin/items/{item}/availability` (shared kiosk).
- POS-GA-F-19 Câbler `kioskHardware.openDrawer()` dans POS `confirmOrder` cash + événement `DrawerOpened`.

### POS-9.6 — Drawer multi-sources + actions POS

- POS-GA-F-15 Remplacer FAB kiosk-cash par drawer "Activité branche" agrégé + endpoint `admin/branch-activity`.
- POS-GA-F-22 Actions accept/preparing/ready/delivered/cancel/refund/amend/discount depuis drawer.
- POS-GA-F-25 Filtres source/statut/table/staff/heure.
- POS-GA-F-26 Colonne "Sur place" KDS pour `OrderType::POS(15)`.
- POS-GA-F-27 Étendre enum `Source` delivery partners.
- POS-GA-F-45 POS subscribe `ItemAvailabilityChanged` + mutation `posCategory/patchAvailability`.

### POS-9.7 — Amend + refund + TPE intégration

- POS-GA-F-21 Endpoints `PATCH /pos-order/{order}/items` + `OrderService::addItem/removeItem` + event `OrderItemAdded` + permission + bloquer après PREPARING.
- POS-GA-F-18 Spec TPE bridge (Concert / NEPTING / Ingenico) + colonnes `payment_reference`, `acquirer_response`. PAID uniquement après ACK TPE signé.
- POS-GA-F-32 Loyalty burn dans `posOrderStore` (extraire `LoyaltyService::redeemInTransaction`).

### POS-9.8 — Ticket + UX caisse

- POS-GA-F-20 TVA ventilée par taux sur `ReceiptComponent`.
- POS-GA-F-35 Imprimer `queue_number` (ou reléguer `token` à delivery).
- POS-GA-F-34 Table `print_logs` + bouton re-print depuis historique + retry auto.
- POS-GA-F-42 `token` serveur (même `Cache::lock` que queue_number).
- POS-GA-F-52 Endpoint `admin/order-history` + écran `OrderHistoryComponent`.
- POS-GA-F-55 Son + toast sur nouvelle commande.
- POS-GA-F-37 TVA cascade `order_type` dans `TaxCalculator` + `PricingRequest`.

### POS-9.9 — Parité POS/Kiosk + permissions granulaires + wizard serveur

- POS-GA-F-29 Étendre `ValidJsonOrder` : charger `itemAttributes.min_selection/max_selection`, vérifier conformité.
- POS-GA-F-30 `OrderItemPayloadContract v1` partagé POS + Kiosk + Web (adapter progressif).
- POS-GA-F-46 `ItemAttribute.role` renseigné admin + `buildWizardRestorePayload` basé sur rôle.
- POS-GA-F-36 `ItemResource` admin expose `allergens` (pattern `NormalItemResource`).
- POS-GA-F-58 Permissions granulaires POS seedées + mapping rôles.
- POS-GA-F-57 Rôles Runner / Kitchen Lead.
- POS-GA-F-41 Scope localStorage `pos_cart_v2:<branch_id>:<user_id>` + invalidation logout.
- POS-GA-F-51 ActionLog symétrique dans `FrontendOrderService::myOrderStore`.
- POS-GA-F-28 Idempotency POS `Cache::lock` + scope `(branch_id, key)` (migration shared kiosk).
- POS-GA-F-63 Allergens source unique (pivot) + listener sync + dépréciation `allergen_flags` (shared kiosk).

### POS-9.10 — Dashboard + observabilité + build final

- POS-GA-F-53 Tuiles dashboard : rupture temps réel, hardware, uptime, CA comparatif J-1/J-7/J-30, ticket moyen, conversion kiosk, top produits glissant.
- POS-GA-F-48 Propager `X-Correlation-ID` dans listeners outbox.
- POS-GA-F-49 Enrichir payloads `OrderCreated`/`OrderStatusChanged` (source, reason, actor_id, total_tax).
- POS-GA-F-50 Aligner `DomainEvent::scopeFailed(4)` avec `tries=5`.
- POS-GA-F-60 Alerting Sentry + commande `foodking:outbox:alerts`.
- POS-GA-F-61 Migration `domain_events.channel` VARCHAR → JSON natif.
- POS-GA-F-54 Polling secondaire catalogue quand Echo déconnecté.
- POS-GA-F-59 `PusherBeams` interest `branch.X.staff` + push natif.
- POS-GA-F-40 Tests POS double-submit, `changePaymentStatus`, symétrie POS↔Kiosk, cascade TVA, loyalty burn POS.
- POS-GA-F-62 Constant `QUEUE_PAD_LENGTH = 4` pour `SimulateKioskOrders`.
- POS-GA-F-47 `PosOrderRequest.total/subtotal` → `nullable`.
- POS-GA-F-56 Money cents int (V2+, à planifier séparément — lourd).
- POS-GA-F-64 Multi-devise (V2+).
- Build final + rapport consolidé POS Phase 9 + handoff Track C.

### Dépendances critiques (DAG abrégé)

```
POS-9.1 (stop-bleed) ────┐
                          ├──► POS-9.6 (drawer)
POS-9.2 (state machine) ──┼──► POS-9.3 (multi-tender) ──► POS-9.7 (amend/refund/TPE)
                          ├──► POS-9.4 (Z fiscal) ──────► POS-9.5 (tiroir/stock)
                          └──► POS-9.8 (ticket UX)
POS-9.9 (parité/perms) ◄── dépend POS-9.3, 9.6, 9.7
POS-9.10 (dashboard/obs) ◄── dépend tous
```

**Estimation brute** (à raffiner POS-B) : 14-18 jours ouvrés si séquentialisé, 10-12 avec parallélisation partielle (9.4 et 9.5 séparés de 9.2, 9.8 et 9.9 après 9.7). **Escalade humaine** possible pour priorisation si > 10 jours (cf POS_INVARIANTS_AND_GATES §2 POS-B gate).

---

## 12. Conclusion

Le POS FoodKing dispose d'un **noyau backend partagé robuste** (SSOT pricing, outbox EventContract V1, state machine définie, queue number atomique, `BranchScope` global) mais **n'exploite pas ce noyau** côté surface POS : state machine non utilisée en live, multi-tender structurellement impossible, drawer monosource, conformité fiscale entièrement absente, permissions trop grossières, tests d'intrusion vides.

**20 P0** concentrent 3 grands blocs à refactorer : (a) **conformité FR** (Z/X/tiroir/audit_logs/serial sequentiel — bloc réglementaire), (b) **architecture paiement & état** (multi-tender, state machine canonique, events canoniques), (c) **centralisation multi-sources + actions cross-surface** (drawer, filtres, amend, cancel, refund).

**Recommandation** : **Phase POS-B (plan détaillé)** lancée immédiatement sur validation humaine de POS-A, suivie de **10 vagues POS-9.1 → POS-9.10** dont 2 (POS-9.2 state machine, POS-9.4 Z fiscal) sont **séquentielles avec les vagues kiosk shared** (P9.2, P9.5) — coordination obligatoire via `SYNC_PROTOCOL_KIOSK_POS.md` (locks sur zones shared + broadcasts + double-check verifier).

**Aucune nouvelle feature ne doit être démarrée tant que POS-9.1 stop-the-bleed n'est pas mergée.** En l'état, le POS exposerait le projet à un risque financier (fraude caissier triviale discount 100 %, double encaissement possible sur refund), légal (NF525 non-conformité, RGPD audit trail fuit cross-tenant), et opérationnel (UX cuisine cassée quand kiosk passe des commandes non visibles, dine-in hardcodé caché).

---

**Livrables POS-A (gate §9 POS_MASTER_BRIEF)** :

- ✅ 4 sous-rapports déposés : `reports/review/AUDIT_POS_SECTION_{1..4}_2026-04-18.md`.
- ✅ Rapport consolidé `reports/review/AUDIT_POS_GLOBAL_2026-04-18.md` (ce fichier), **58+ findings** (64 IDs traçabilité), 10 sections identiques au kiosk.
- ✅ Check-list 15 invariants répondue ligne à ligne (§10 ci-dessus).
- ✅ Section 9 recouvrement avec kiosk explicite + recommandations inter-tracks.
- ✅ `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` créé avec toutes les findings en `status=open`.
- ✅ Verdict global par domaine : parcours **WARN→BLOCKED** / centralisation **BLOCKED** / fin-de-journée **BLOCKED** / backend **WARN→BLOCKED** / tests **WARN**.
- ✅ Recommandation structure 10 vagues POS-9.1 → POS-9.10 esquissée (§11) — plan détaillé livrable Phase POS-B sur validation humaine.

**Next step.** Attente validation humaine de POS-A avant lancement de Phase POS-B (plan d'exécution détaillé). Aucun commit, aucune modification de code n'a été effectuée durant cet audit.
