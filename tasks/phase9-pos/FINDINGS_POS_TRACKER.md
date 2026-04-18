# FINDINGS_POS_TRACKER — Phase POS-A (audit global)

**Date audit** : 2026-04-18
**Rapport source** : `reports/review/AUDIT_POS_GLOBAL_2026-04-18.md`
**Sous-rapports** : `reports/review/AUDIT_POS_SECTION_{1..4}_2026-04-18.md`
**Registre** : chaque finding consolidée est listée ci-dessous avec `status=open` initial. Les corrections relèvent de **Phase POS-B → vagues POS-9.1 … POS-9.10**. Toute mise à jour passe par PR avec bump `status` + ligne changelog en bas de fichier.

## Légende

- **status** : `open` / `planned` / `in_progress` / `blocked` / `resolved` / `deferred-v2`
- **wave** : vague cible POS-9.X si déjà arbitrée en §11 du rapport global (sinon vide → arbitrage POS-B).
- **sync_with_kiosk** : finding partagée avec Track A (cf §9 matrice recouvrement) — nécessite lock shared avant PR.

## Table principale

| ID | P | Title | File:line | Wave | Sync kiosk | Status |
|---|---|---|---|---|---|---|
| POS-GA-F-01 | P0 | MISSING `ZReport` (NF525 / loi Finance 2018) | backlog | POS-9.4 | — | open |
| POS-GA-F-02 | P0 | MISSING `XReport` (snapshot intraday) | backlog | POS-9.4 | — | open |
| POS-GA-F-03 | P0 | MISSING `cash_drawer_sessions` + comptage + écarts | backlog | POS-9.5 | — | open |
| POS-GA-F-04 | P0 | `action_logs` mutable + sans `branch_id` → fuite cross-tenant | `app/Models/ActionLog.php:8-22`, `app/Services/DashboardService.php:305-326`, migration `2026_03_06_182733` | POS-9.4 | sync (audit infra) | partial (POS-9.1.4 adds branch_id scoping; immutability = POS-9.4) |
| POS-GA-F-05 | P0 | Stock non libéré sur CANCELED/REJECTED/RETURNED | `app/Services/Menu/AvailabilityService.php:118-163` | POS-9.5 | sync (listener shared) | open |
| POS-GA-F-06 | P0 | Discount manuel sans permission / seuil / audit structuré | `app/Services/Pricing/PricingService.php:213-217`, `app/Services/Pricing/DiscountCalculator.php`, `resources/js/components/admin/pos/PosComponent.vue:1139-1158`, `app/Http/Requests/PosOrderRequest.php:38` | POS-9.1 | — | resolved (POS-9.1.1) |
| POS-GA-F-07 | P0 | MISSING endpoint admin toggle 86 (`AvailabilityService::toggle` non exposé) | `app/Services/Menu/AvailabilityService.php:32-74` | POS-9.5 | sync (controller shared) | open |
| POS-GA-F-08 | P0 | Modèle paiement binaire PAID/UNPAID — multi-tender impossible | `app/Enums/PaymentStatus.php:1-10`, `app/Services/OrderService.php:1485-1526` | POS-9.3 | — | open |
| POS-GA-F-09 | P0 | `OrderStateMachine::apply()` jamais utilisé — 11 écritures inline `->status = X` | `app/Services/OrderService.php:1334, 1387, 1439`, `app/Services/FrontendOrderService.php:550, 661, 736`, `app/Domain/Order/OrderStateMachine.php:156` | POS-9.2 | sync (séquentiel avec kiosk P9.5) | open |
| POS-GA-F-10 | P0 | `changePaymentStatus` sans tx, sans state machine paiement, sans event | `app/Services/OrderService.php:1485-1526` | POS-9.3 | — | open |
| POS-GA-F-11 | P0 | Events dispatchés hors `DB::afterCommit()` explicite + `OrderStatusChanged(PENDING→ACCEPT)` jamais émis POS | `app/Services/OrderService.php:898-908, 1397-1404, 1464-1473` | POS-9.2 | sync | open |
| POS-GA-F-12 | P0 | Endpoints POS mutatifs sans `DB::transaction` (`changePaymentStatus`, `deliveryBoyOrderChangeStatus`, `selectDeliveryBoy`, `changeStatus auth=true`) | `app/Services/OrderService.php:1312-1358, 1370-1404, 1485-1526, 1554-1580` | POS-9.2 | — | open |
| POS-GA-F-13 | P0 | `PaymentService::cashBack` ne logue pas / rate cas "pas de Transaction préalable" | `app/Services/PaymentService.php:29-50` | POS-9.3 | — | open |
| POS-GA-F-14 | P0 | `OrderService::destroy` physique sans branch check, sans event, sans ActionLog | `app/Services/OrderService.php:1585-1598`, `app/Http/Controllers/Admin/PosOrderController.php:59-68`, `routes/api.php:628` | POS-9.1 | — | resolved (POS-9.1.2) |
| POS-GA-F-15 | P0 | Drawer POS ne centralise que kiosk cash (1 source sur 5+) | `resources/js/components/admin/pos/PosComponent.vue:1018-1040` | POS-9.6 | — | open |
| POS-GA-F-16 | P0 | `BranchIsolationTest` est un placeholder (`assertTrue(true)`) | `tests/Feature/BranchIsolationTest.php:9-13` | POS-9.1 | sync | resolved (POS-9.1.3) |
| POS-GA-F-17 | P0 | Dine-in et table selector hardcodés `v-if="false"` | `resources/js/components/admin/pos/PosComponent.vue:121, 235` | POS-9.1 | — | resolved (POS-9.1.6 — flag pos_dine_in_enabled) |
| POS-GA-F-18 | P0 | Aucune intégration TPE — PAID marqué sans preuve d'encaissement | `resources/js/components/admin/pos/PaymentComponent.vue:64-67, 207-211`, `app/Http/Requests/PosOrderRequest.php:60` | POS-9.7 | — | open |
| POS-GA-F-19 | P0 | Aucune ouverture tiroir depuis POS | `resources/js/components/admin/pos/PaymentComponent.vue:191-277`, `resources/js/services/kioskHardware.js:259-262` | POS-9.1 | — | resolved (POS-9.1.12 — openDrawer() on CASH success) |
| POS-GA-F-20 | P0 | Ticket TVA non ventilée par taux (art. 242 nonies A CGI) | `resources/js/components/admin/pos/ReceiptComponent.vue:105-112` | POS-9.8 | — | open |
| POS-GA-F-21 | P1 | MISSING amend order (add/remove/update item post-création) | `routes/api.php:625-638`, `app/Domain/Events/EventContract.php:37` | POS-9.7 | — | open |
| POS-GA-F-22 | P1 | Aucune action POS → cancel/refund/discount/reassign depuis drawer | `resources/js/components/admin/pos/PosComponent.vue:608-617` | POS-9.6 | — | open |
| POS-GA-F-23 | P1 | Aucun split bill (par item / personne / montant) | `routes/api.php:621-638` | POS-9.3 | — | open |
| POS-GA-F-24 | P1 | Events `OrderCancelled` + `PaymentRecorded` / `OrderRefunded` jamais dispatchés | `app/Domain/Events/EventContract.php:34-41`, `app/Events/` | POS-9.3 | sync | open |
| POS-GA-F-25 | P1 | Aucun filtre source/statut/table/staff/heure dans drawer POS | `resources/js/components/admin/pos/PosComponent.vue:575-625, 1018-1040` | POS-9.6 | — | open |
| POS-GA-F-26 | P1 | `OrderType::POS(15)` absent des colonnes KDS (4 colonnes) | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:602-655` | POS-9.6 | — | open |
| POS-GA-F-27 | P1 | `Source` enum ne couvre pas les delivery partners (3 constantes) | `app/Enums/Source.php:1-12` | POS-9.6 | sync | open |
| POS-GA-F-28 | P1 | Idempotency POS sans `Cache::lock` + clé non scopée branche | `app/Services/OrderService.php:550-556`, migration `2026_03_25_002938_add_idempotency_key_to_orders_table.php:17` | POS-9.9 | sync (migration shared) | open |
| POS-GA-F-29 | P1 | `ValidJsonOrder` : zéro validation min/max/required par attribut | `app/Rules/ValidJsonOrder.php:31-73`, `app/Http/Requests/PosOrderRequest.php:58` | POS-9.9 | — | open |
| POS-GA-F-30 | P1 | Divergence structure items POS vs Kiosk — pas de contrat commun versionné | `resources/js/components/admin/pos/ItemComponent.vue:1176-1224` vs kiosk wizard | POS-9.9 | sync | open |
| POS-GA-F-31 | P1 | Fallback `queue_number` timestamp-based sur LockTimeoutException → séquentialité cassée | `app/Services/OrderService.php:467, 794, 1143`, `app/Services/FrontendOrderService.php:389-392` | POS-9.4 | — | open |
| POS-GA-F-32 | P1 | Loyalty burn absent de `posOrderStore` (kiosk OK L434-499) | `app/Services/OrderService.php:829-836` vs `app/Services/FrontendOrderService.php:434-499` | POS-9.7 | — | open |
| POS-GA-F-33 | P1 | `deliveryBoyOrderChangeStatus` dispatche mails/SMS/push AVANT `$order->save()` sans tx | `app/Services/OrderService.php:1330-1335` | POS-9.1 | — | resolved (POS-9.1.7 — DB::transaction + save→dispatch) |
| POS-GA-F-34 | P1 | Aucune réimpression ni journal d'impression | `resources/js/components/admin/pos/ReceiptComponent.vue:10-14` | POS-9.8 | — | open |
| POS-GA-F-35 | P1 | Ticket n'imprime pas `queue_number` (seul `token` client) | `resources/js/components/admin/pos/ReceiptComponent.vue:152-156`, `app/Services/OrderService.php:801-802` | POS-9.8 | — | open |
| POS-GA-F-36 | P1 | Allergens absents d'`ItemResource` admin (POS) → caissier aveugle | `app/Http/Resources/ItemResource.php:19-65` | POS-9.9 | sync | open |
| POS-GA-F-37 | P1 | TVA sans cascade `order_type` (dine-in 10 % / takeaway 5.5 % / alcool 20 %) | `app/Services/Pricing/PricingService.php:141-152`, `app/Services/Pricing/TaxCalculator.php:9-17` | POS-9.8 | — | open |
| POS-GA-F-38 | P1 | `order_serial_no` non séquentiel par branche (`date('dmy').id`) | `app/Services/OrderService.php:475, 801` | POS-9.4 | — | open |
| POS-GA-F-39 | P1 | `action_logs.branch_id` absent → fuite cross-tenant dashboard | migration `2026_03_06_182733:15-22`, `app/Services/DashboardService.php:305-326` | POS-9.1 | sync | resolved (POS-9.1.4) |
| POS-GA-F-40 | P1 | Couverture tests POS backend faible (pas double-submit POS, pas `changePaymentStatus`, pas symétrie) | `tests/Feature/` | POS-9.10 | sync | open |
| POS-GA-F-41 | P1 | Panier localStorage `pos_cart_v2` non scopé par `branch_id`/`user_id` — fuite entre caissiers | `resources/js/store/pos/posCart.js:6-44` | POS-9.1 | — | resolved (POS-9.1.9 — clé pos_cart_v3:b<branch>:u<user>) |
| POS-GA-F-42 | P1 | Token séquentiel journalier fabriqué client localStorage — collisions entre caisses | `resources/js/components/admin/pos/PosComponent.vue:1253-1266` | POS-9.8 | — | open |
| POS-GA-F-43 | P2 | `KitchenDisplaySystemOrderService::list` filtre `branch_id` via `LIKE '%X%'` → faux positifs | `app/Services/KitchenDisplaySystemOrderService.php:75-85` | POS-9.1 | — | resolved (POS-9.1.5) |
| POS-GA-F-44 | P2 | `OrderService::list` dépend exclusivement de `BranchScope` (pas de defense-in-depth) | `app/Services/OrderService.php:102-170` | POS-9.10 | — | open |
| POS-GA-F-45 | P2 | POS ne s'abonne pas à `ItemAvailabilityChanged` (86 admin non reflété live) | `resources/js/components/admin/pos/PosComponent.vue:1004-1007` | POS-9.1 | — | resolved (POS-9.1.10 — onEvents subscribe + handler) |
| POS-GA-F-46 | P2 | `buildWizardRestorePayload` basé sur heuristiques FR (`includes('pain'\|'viande'\|...')`) | `resources/js/components/admin/pos/ItemComponent.vue:721-915` | POS-9.9 | — | open |
| POS-GA-F-47 | P2 | `PosOrderRequest` valide encore `total`/`subtotal` depuis payload | `app/Http/Requests/PosOrderRequest.php:36-48, 77-82` | POS-9.1 | — | resolved (POS-9.1.8 — total nullable, server recomputes) |
| POS-GA-F-48 | P2 | `correlation_id` régénéré par listener au lieu d'être propagé depuis `X-Correlation-ID` | `app/Listeners/PersistOrderCreatedToOutbox.php:33`, `app/Listeners/PersistOrderStatusChangedToOutbox.php:32` | POS-9.10 | sync | open |
| POS-GA-F-49 | P2 | Payloads events minimalistes (`reason`, `actor_id`, `source`, `total_tax` manquants) | `app/Listeners/PersistOrderCreatedToOutbox.php:24-30`, `app/Listeners/PersistOrderStatusChangedToOutbox.php:22-29` | POS-9.10 | sync | open |
| POS-GA-F-50 | P2 | `DomainEvent::scopeFailed(4)` incohérent avec `tries=5` → double dispatch possible | `app/Models/DomainEvent.php:44-48`, `app/Jobs/DispatchDomainEventsJob.php:21-23` | POS-9.10 | sync | open |
| POS-GA-F-51 | P2 | `FrontendOrderService::myOrderStore` ne crée pas d'ActionLog (asymétrie POS) | `app/Services/FrontendOrderService.php:500-555` vs `app/Services/OrderService.php:887-892` | POS-9.9 | sync | open |
| POS-GA-F-52 | P2 | Aucun historique commandes closes cross-source depuis POS | `routes/api.php:625-638` | POS-9.8 | — | open |
| POS-GA-F-53 | P2 | Dashboard sans tuile rupture / hardware / uptime borne / ticket moyen / conversion | `resources/js/components/admin/dashboard/` | POS-9.10 | — | open |
| POS-GA-F-54 | P2 | Polling POS borné à kiosk cash — catalogue non polé en mode dégradé | `resources/js/components/admin/pos/PosComponent.vue:988-997` | POS-9.10 | — | open |
| POS-GA-F-55 | P2 | Aucune notification sonore / toast sur nouvelle commande POS | `resources/js/components/admin/pos/PosComponent.vue:1005` | POS-9.1 | — | resolved (POS-9.1.11 — toast + WebAudio beep) |
| POS-GA-F-56 | P2 | Money en `decimal:6` au lieu de cents int (dérive possible) | `app/Models/Order.php:59-63`, `app/Models/Transaction.php:11-18` | deferred-v2 | — | open |
| POS-GA-F-57 | P2 | Rôles Runner / Kitchen Lead absents du seed | `database/seeders/RoleTableSeeder.php:17-67` | POS-9.9 | — | open |
| POS-GA-F-58 | P2 | Aucune permission POS granulaire (cancel-after-paid, refund, discount-X, amend, manage-drawer, reopen-z) | `database/seeders/PermissionTableSeeder.php:113-125` | POS-9.9 | — | open |
| POS-GA-F-59 | P3 | PusherBeams / FCM staff POS absent (onglet fermé = rien reçu) | grep `PusherBeams` = 0 | POS-9.10 | — | open |
| POS-GA-F-60 | P3 | Outbox sans dead-letter / alerting ops après 5 retries | `app/Jobs/DispatchDomainEventsJob.php:81-98` | POS-9.10 | sync | open |
| POS-GA-F-61 | P3 | `domain_events.channel` stocké en JSON-string dans VARCHAR | migration `2026_04_15_200000:18`, `app/Jobs/DispatchDomainEventsJob.php:45-49` | POS-9.10 | sync | open |
| POS-GA-F-62 | P3 | `SimulateKioskOrders` produit `A001` 3 digits, incompatible prod 4 digits | `app/Console/Commands/SimulateKioskOrders.php:38` | POS-9.10 | — | open |
| POS-GA-F-63 | P3 | Allergens double source (JSON `items.allergen_flags` + pivot `item_allergen` sans sync) | `app/Models/Item.php:43, 71` | POS-9.9 | sync (kiosk C-5) | open |
| POS-GA-F-64 | P3 | Multi-devise non scopé branche (V2+) | `app/Models/Currency.php:10`, pas de FK `branches.currency_id` | deferred-v2 | — | open |

## Totaux

- **Total findings** : 64 IDs (58 findings distincts après dédoublonnage dans le rapport global).
- **P0** : 20 · **P1** : 22 · **P2** : 16 · **P3** : 6.
- **Sync kiosk (shared zones)** : 14 findings nécessitent coordination Track A/B.
- **Wave la plus chargée** : POS-9.10 (13 findings — build final + observabilité) puis POS-9.9 (10 findings — parité + perms).

## Changelog

- 2026-04-18 — Création du tracker. 64 findings enregistrées, toutes en `status=open`. Rapport source `AUDIT_POS_GLOBAL_2026-04-18.md`.
