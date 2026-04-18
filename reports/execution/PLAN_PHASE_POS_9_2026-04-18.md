# Plan Phase POS-9 — FoodKing POS — 2026-04-18

**Source.** `reports/review/AUDIT_POS_GLOBAL_2026-04-18.md` (4 audits parallèles → 64 findings, 20 P0 / 22 P1 / 16 P2 / 6 P3) + `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` + `tasks/phase9-pos/POS_MASTER_BRIEF.md` §4-5 + `tasks/phase9-pos/SYNC_PROTOCOL_KIOSK_POS.md` §3-4 + `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` §1-3.
**Modèle de structuration.** `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md` (10 vagues, gates, DAG, séquencement).

---

## Invariants non-négociables (référence POS_INVARIANTS §1)

Hérités CLAUDE.md :

- Backend SSOT pricing strict (aucun prix client dans `POST /api/v1/admin/pos`, `/admin/order/*`).
- `branch_id` serveur uniquement, jamais lu du payload, défense en profondeur (FormRequest + Service + `BranchScope`).
- `OrderStateMachine::apply()` seul gatekeeper de toute transition `status` — zéro `->update(['status'=>…])` ni `->status = X; save()`.
- `DB::afterCommit()` systématique avant tout dispatch d'event. Event toujours **après** `$order->save()`.
- EventContract V1 strict (`version, type, aggregate_id, aggregate_type, branch_id, correlation_id, occurred_at, payload`) validé `assertEnvelopeValid()` avant broadcast.
- RGPD : opt-in explicite marketing, legitimate interest ops documenté.

Spécifiques POS (POS_INVARIANTS §1.2) :

- Permissions Spatie granulaires avant cancel/refund/discount>seuil/amend/reopen-Z/manage-drawer.
- Audit log **immuable** (INSERT-only, `branch_id`, hash chaîné HMAC, enum d'action).
- Z fiscal NF525 / loi Finance 2018 : séquentiel sans trou, HMAC chaîné, archivable 6 ans, non-supprimable.
- Tiroir-caisse : chaque ouverture loggée (staff, motif, solde théorique, timestamp).
- Multi-tenders : `Order` ↔ N `OrderPayment`, Σ `payments.amount` = `order.total`.
- Idempotency POS : `X-Idempotency-Key` + `Cache::lock` + clé scopée `(branch_id, key)`.
- Refund / amend : permission + motif + audit log + event canonique + stock policy tranchée.
- Allergens visibles caissier dans `ItemResource` admin.
- TVA cascade par `order_type` (dine-in 10 % / takeaway 5.5 % / alcool 20 %).
- Arrondis DGCCRF `HALF_AWAY_FROM_ZERO`, money en cents int (V2+ pour la migration complète).

**Couverture par test.** Chaque invariant §1 est couvert par **au moins un test** dans les vagues listées au §Couverture invariants en fin de plan.

---

## Gate commun par vague (identique kiosk + invariants POS §3)

Chaque vague POS-9.X passe **6 gates** AVANT merge :

1. **Vitest complet vert** + nouveaux tests Vitest dédiés verts.
2. **PHPUnit complet vert** + nouveaux tests Feature dédiés verts.
3. **Playwright POS vert** (suite `tests/e2e/pos-*.spec.ts`) + nouveaux scénarios vague.
4. `npm run production` compile **< 27 s**, bundle POS stable ±5 %.
5. **Greps invariants tous vides** (cf. POS_INVARIANTS §3) :
   ```bash
   rg "->input\('price'\)|->input\('total'\)" app/Http/Controllers/Admin/ app/Services/OrderService.php
   rg "->input\('branch_id'\)|\$request->branch_id" app/Http/Controllers/Admin/
   rg "->update\(\[\s*'status'" app/ -g '*.php' | rg -v OrderStateMachine
   rg "->status\s*=\s*\"" app/Services/ | rg -v OrderStateMachine
   rg "Event::dispatch|::dispatch\(" app/Services/OrderService.php | rg -v "afterCommit|shouldDispatchAfterCommit"
   rg "broadcast\(" app/Events/ | rg -v "buildEnvelope|assertEnvelopeValid"
   ```
6. **Verifier indépendant 100 % RESOLVED** — sous-agent `general-purpose` read-only relit le HEAD et note chaque finding de la vague `RESOLVED` / `PARTIAL` / `STILL_BROKEN` dans `reports/review/VERIFY_POS_9_X_<DATE>.md`. Zéro merge si non 100 %.

**Lock shared.** Toute modification d'un fichier SYNC_PROTOCOL §2 "zone partagée" requiert un `tasks/phase9-sync/LOCK_trackB_<file>_<date>.md` **avant** commit et un `BROADCAST_<date>.md` après release (SYNC_PROTOCOL §4).

**Commit style.** Un commit atomique par sous-phase. Message : `feat(pos/phase-9.X.Y): <résumé>` ou `fix(pos/phase-9.X.Y): <résumé>`. Branche : `feat/pos-phase-9-X`.

---

## Vague POS-9.1 — Stop-the-bleed POS (P0 safety / fraude / fuite cross-tenant / invariants UI)

**Objectif.** Corriger les 14 P0 à effort court qui (a) bloquent la fraude caissier triviale, (b) colmatent les fuites cross-tenant dashboard, (c) câblent les garde-fous UI manquants. **Aucun changement de schéma lourd** (pas encore de `order_payments`, pas encore de `z_reports` — ceux-là sont en POS-9.3 et POS-9.4). **Bloque toutes les vagues suivantes**.

**Durée estimée.** 6-10 h de travail (1 jour ouvré), 1 PR atomique multi-commits.

**Marker synchro Track A.** `[parallel OK]` — POS-9.1 évite délibérément `OrderService.php` et `OrderStateMachine.php` (zones shared lourdement touchées par kiosk P9.5). Seuls `action_logs` migration additive et `KitchenDisplaySystemOrderService.php` sont shared mais sans conflit attendu (kiosk ne touche pas KDS admin).

| #       | ID finding    | Item                                                                                                                                                                     | Fichiers (zone)                                                                                                                                            | Effort | Tests ajoutés (PHPUnit / Vitest)                                                                                                                                                                                                     | Dép. Track A                                                                                                                                      |
| ------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| 9.1.1   | POS-GA-F-06   | **Discount permission gate**. `PosOrderRequest::authorize()` vérifie `pos-discount-up-to-10` si `discount/subtotal ≤ 0.10`, sinon `pos-discount-over-10`. Motif obligatoire dès 0.01 €. Seed 2 permissions + rattache Admin/BranchMgr/POS-Op. | `app/Http/Requests/PosOrderRequest.php:19-22, 38`, `database/seeders/PermissionTableSeeder.php:113-125`, `resources/js/components/admin/pos/PosComponent.vue:1139-1158` (UI motif input) | 45 min | PHPUnit `PosDiscountPermissionTest::test_cashier_without_over_10_permission_is_denied_at_15_percent` + `test_motif_required_when_discount_gt_zero`. Vitest `PosComponent.spec.js::discount_modal_requires_reason` | parallèle                                                                                                                                         |
| 9.1.2   | POS-GA-F-14   | **`destroy()` sécurisé**. `OrderService::destroy` → branch check `$order->branch_id === $authUser->branch_id` (bypass admin=0), soft-delete (pas de `forceDelete`), ActionLog `order.destroyed` avec motif, bloquer si `payment_status=PAID` sans permission `pos-destroy-paid`. Route `DELETE /admin/pos-order/{order}` garde. | `app/Services/OrderService.php:1585-1598`, `app/Http/Controllers/Admin/PosOrderController.php:59-68`, `routes/api.php:628`, `app/Http/Requests/PosOrderDestroyRequest.php` (nouveau) | 1 h    | PHPUnit `PosOrderDestroyTest::test_cannot_destroy_other_branch_order_even_as_admin_branch_scoped`, `test_destroy_paid_order_requires_permission`, `test_destroy_creates_action_log`                                                  | **lock shared** `LOCK_trackB_OrderService_2026-04-18.md` (ligne 1585-1598 uniquement — intention narrow, pas de refactor)                          |
| 9.1.3   | POS-GA-F-16   | **`BranchIsolationTest` réel**. Remplacer placeholder par 6 scénarios : (a) staff A ne voit pas orders B en `OrderService::list`, (b) ne peut pas `changeStatus` order B, (c) ne peut pas `changePaymentStatus` order B, (d) ne peut pas `destroy` order B, (e) `PosOrderController::show` retourne 404, (f) `KitchenDisplaySystemOrderService::list` scopé. | `tests/Feature/BranchIsolationTest.php:1-80`                                                                                                               | 1h30   | 6 tests PHPUnit ci-dessus                                                                                                                                                                                                            | parallèle                                                                                                                                         |
| 9.1.4   | POS-GA-F-39   | **`action_logs.branch_id` additif**. Migration additive (nullable d'abord) + backfill `UPDATE action_logs SET branch_id = (SELECT branch_id FROM users WHERE users.id = action_logs.user_id)`, index composite `(branch_id, created_at)`, modifier `ActionLog::create` sites pour injecter `branch_id = $authUser->branch_id`. Scope `DashboardService::auditTrail($branchId)` forcé. | nouvelle migration `2026_04_19_000000_add_branch_id_to_action_logs.php`, `app/Models/ActionLog.php`, `app/Services/DashboardService.php:305-326`, 5 call-sites `ActionLog::create` | 1 h    | PHPUnit `ActionLogBranchIsolationTest::test_audit_trail_returns_only_current_branch_logs`, `test_dashboard_does_not_leak_cross_tenant_logs`                                                                                          | **lock shared** `LOCK_trackB_action_logs_migration_2026-04-18.md` — migration additive, rollback sûr. Pas de conflit attendu avec kiosk (P9.5 ne touche pas `action_logs`). |
| 9.1.5   | POS-GA-F-43   | **`KitchenDisplaySystemOrderService::list` : remplacer `LIKE '%X%'` par `where('branch_id', (int) $branchId)`**. Bug latent `branch_id=12` matche `%1%` et `%2%`. | `app/Services/KitchenDisplaySystemOrderService.php:75-85`                                                                                                 | 15 min | PHPUnit `KdsBranchFilterTest::test_branch_12_does_not_match_branch_1_via_like`                                                                                                                                                       | parallèle (non shared — admin KDS)                                                                                                                |
| 9.1.6   | POS-GA-F-17   | **Dine-in / table selector `v-if="false"` — décision tranchée**. Feature flag branche `pos.dine_in_enabled` dans `kioskSettings` (boolean). Si false (défaut v1) → retirer le code mort + FormRequest valide `dining_table_id` bloquant. Si true (future) → exposer. Ticket back Q4 product. | `resources/js/components/admin/pos/PosComponent.vue:121, 235`, `app/Http/Requests/PosOrderRequest.php:49` (exclure `dining_table_id` quand flag off)      | 30 min | Vitest `PosComponent.spec.js::hides_dine_in_when_flag_false`, `shows_dine_in_when_flag_true`                                                                                                                                         | parallèle                                                                                                                                         |
| 9.1.7   | POS-GA-F-33   | **`deliveryBoyOrderChangeStatus` : ré-ordonner save → dispatches + `DB::transaction`**. Écriture critique avant tout dispatch mail/SMS/push. | `app/Services/OrderService.php:1312-1358`                                                                                                                   | 20 min | PHPUnit `DeliveryBoyStatusChangeOrderTest::test_save_before_dispatch_mail_sms_push`                                                                                                                                                  | **lock shared** même fichier que 9.1.2, narrow ligne 1312-1358 uniquement                                                                         |
| 9.1.8   | POS-GA-F-47   | **`PosOrderRequest::total`/`subtotal` → `nullable`**. Ces champs ne servent qu'à un pré-check UI informatif ; unset L566 reste le garde-fou SSOT. Passer `nullable` supprime la surface d'incident futur. | `app/Http/Requests/PosOrderRequest.php:36-48, 77-82`                                                                                                       | 10 min | PHPUnit `PosOrderRequestNullableTotalTest::test_order_succeeds_without_total_in_payload` + assert même pricing serveur                                                                                                              | parallèle                                                                                                                                         |
| 9.1.9   | POS-GA-F-41   | **Panier localStorage scoped**. Clé `pos_cart_v2:<branch_id>:<user_id>` au lieu de `pos_cart_v2`. Invalidation automatique au logout (event Vuex `auth/logout` → `posCart/clearAll`). | `resources/js/store/pos/posCart.js:6-44`                                                                                                                   | 30 min | Vitest `posCart.spec.js::cart_scoped_by_branch_user`, `clears_cart_on_logout`                                                                                                                                                        | parallèle                                                                                                                                         |
| 9.1.10  | POS-GA-F-45   | **POS subscribe `ItemAvailabilityChanged`**. 2 lignes supp. dans `PosComponent.mounted` Echo handler + mutation `posItems/patchAvailability` déjà existante adaptée. | `resources/js/components/admin/pos/PosComponent.vue:999-1010, 1004-1007`                                                                                   | 20 min | Vitest `PosComponent.spec.js::updates_item_availability_on_echo_event`                                                                                                                                                               | parallèle                                                                                                                                         |
| 9.1.11  | POS-GA-F-55   | **Son + toast sur nouvelle commande POS**. Reuse `soundNewOrder` kiosk asset + `vue3-toastify` (déjà dans projet) déclenchés par handler Echo `OrderCreated` quand `order.source ∈ {kiosk,web,app}`. | `resources/js/components/admin/pos/PosComponent.vue:1004-1010`, `resources/audio/new-order.mp3` (asset)                                                    | 30 min | Vitest `PosComponent.spec.js::plays_sound_and_toast_on_new_kiosk_order`                                                                                                                                                              | parallèle                                                                                                                                         |
| 9.1.12  | POS-GA-F-19   | **Câbler ouverture tiroir POS cash**. Appel `kioskHardware.openCashDrawer()` dans `PaymentComponent.confirm()` quand `pos_payment_method === PosPaymentMethod::CASH`. Best-effort (pas de persistance serveur en P9.1 ; event persisté en POS-9.5 avec table `cash_drawer_events`). | `resources/js/components/admin/pos/PaymentComponent.vue:191-277`, `resources/js/services/kioskHardware.js:259-262`                                         | 30 min | Vitest `PaymentComponent.spec.js::opens_cash_drawer_on_cash_confirm`                                                                                                                                                                 | parallèle                                                                                                                                         |
| 9.1.13  | POS-GA-F-20   | **Ticket TVA ventilée par taux**. Remplacer ligne unique "Total TVA" par agrégation `tax_lines` reçues du serveur (déjà calculées dans `PricingService::calculateOrder::taxBreakdown` mais non sérialisées jusqu'au reçu — étendre `OrderResource` + `pos_order.tax_lines` JSON). | `app/Services/Pricing/PricingService.php:141-152` (expose tax_lines), `app/Http/Resources/OrderResource.php`, `resources/js/components/admin/pos/ReceiptComponent.vue:105-112` | 1 h    | PHPUnit `OrderResourceTaxLinesTest::test_exposes_tax_lines_by_rate`. Vitest `ReceiptComponent.spec.js::renders_tva_ventilee_par_taux`                                                                                                | **lock shared** `PricingService.php` narrow (ajout de `taxBreakdown()` lecture seule, pas de refactor calc)                                        |
| 9.1.14  | POS-GA-F-36   | **Allergens dans `ItemResource` admin (POS)**. Ajouter `'allergens' => $this->allergens->pluck('code')` (pattern `NormalItemResource`). Affichage badge dans `ItemComponent.vue` grille et modale wizard. | `app/Http/Resources/ItemResource.php:19-65`, `resources/js/components/admin/pos/ItemComponent.vue`                                                         | 45 min | PHPUnit `ItemResourceAdminAllergensTest::test_exposes_allergen_codes`. Vitest `ItemComponent.spec.js::renders_allergen_badges`                                                                                                      | **lock shared** `ItemResource.php` (sync kiosk POS-GA-F-36 ↔ kiosk). Coordination Track A si P9.2 a déjà modifié le même resource.                |

**Total : 14 items · ~9 h.**

**Locks shared à poser (SYNC_PROTOCOL §4)** — 4 fichiers :
- `LOCK_trackB_OrderService_destroy+deliveryboy_2026-04-18.md` (lignes 1312-1358 et 1585-1598 uniquement).
- `LOCK_trackB_action_logs_migration_2026-04-18.md` (migration additive).
- `LOCK_trackB_PricingService_taxBreakdown_2026-04-18.md` (ajout méthode lecture seule).
- `LOCK_trackB_ItemResource_allergens_2026-04-18.md` (ajout 1 clé, sync avec kiosk).

**Gate POS-9.1.** Tous les 14 P0 couverts. CI complète verte. Greps invariants vides (`->input('price')`, `->input('branch_id')`, écriture `->status =` hors OrderStateMachine). Build prod < 27 s. Verifier indépendant 100 % RESOLVED. Rapport `reports/execution/RUN_POS_9_1_<DATE>.md` avec diff + evidence. `BROADCAST_2026-04-18.md` envoyé à Track A listant les 4 fichiers shared touchés.

---

## Vague POS-9.2 — State machine canonicalisation + transactions backend

**Objectif.** Imposer `OrderStateMachine::apply()` comme **unique** gatekeeper des transitions `status` côté POS. Éliminer les 11 écritures inline `->status = X`. Wrapper `DB::transaction` sur tous les endpoints mutatifs. Déplacer les dispatches d'events en `DB::afterCommit()` explicite. Émettre `OrderStatusChanged(PENDING→ACCEPT)` manquant côté `posOrderStore`.

**Durée estimée.** 1,5 jour.

**Marker synchro Track A.** **`[SEQUENTIAL WAIT P9.5]`** — kiosk P9.5 (order pipeline hardening) touche `OrderService.php` et `OrderStateMachine.php` lourdement. POS-9.2 doit attendre merge kiosk P9.5 sur main puis rebase. Rationale : POS-9.2 refactore les mêmes écritures que kiosk P9.5 "allergens_snapshot" dans le même fichier.

| #      | ID finding  | Item                                                                                                                                                                                                          | Fichiers (shared)                                                                                          | Effort | Tests                                                                                                                      |
| ------ | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------------------- |
| 9.2.1  | POS-GA-F-09 | **Audit 11 call-sites écrivant `$order->status = X; save()`**. Établir tableau `from → to → actor → reason` exhaustif pour chaque site (changeStatus auth=true/false, deliveryBoyOrderChangeStatus, destroy cascade…). Doc `tasks/phase9-pos/STATE_MACHINE_CALLSITES_INVENTORY.md`. | lecture seule                                                                                              | 45 min | — (inventaire)                                                                                                             |
| 9.2.2  | POS-GA-F-09 | **Refacto `OrderService::changeStatus(auth=false)`** → `OrderStateMachine::apply($order, $next, $actor, $reason)`. Exception `InvalidTransitionException` remonte en 422. | `app/Services/OrderService.php:1405-1477`                                                                  | 45 min | PHPUnit `ChangeStatusViaStateMachineTest::test_illegal_transition_throws_422`, `test_legal_transition_persists_and_dispatches_event` |
| 9.2.3  | POS-GA-F-09 | **Refacto `OrderService::changeStatus(auth=true)`** (endpoint customer) → state machine + transaction + afterCommit. | `app/Services/OrderService.php:1370-1404`                                                                  | 30 min | `CustomerOrderStatusChangeTest::test_uses_state_machine`                                                                   |
| 9.2.4  | POS-GA-F-09 | **Refacto `FrontendOrderService`** sites L550, L661, L736 → `OrderStateMachine::apply`. | `app/Services/FrontendOrderService.php:550, 661, 736`                                                      | 45 min | `FrontendOrderStateMachineTest::test_kiosk_flow_uses_state_machine`                                                        |
| 9.2.5  | POS-GA-F-12 | **Wrapper `DB::transaction` sur endpoints mutatifs**. `changePaymentStatus`, `selectDeliveryBoy`, `changeStatus(auth=true)` reçoivent `DB::transaction(function() use (...) { … })`. | `app/Services/OrderService.php:1485-1526, 1554-1580, 1370-1404`                                            | 45 min | `OrderServiceTransactionsTest::test_change_payment_status_rollback_on_exception`                                           |
| 9.2.6  | POS-GA-F-11 | **`DB::afterCommit()` explicite sur tous les dispatches**. Remplacer la convention "dispatch hors bloc" par `DB::afterCommit(fn() => Event::dispatch(...))` dans `posOrderStore`, `changeStatus`, `changePaymentStatus`, `destroy`. | `app/Services/OrderService.php:898-908, 1397-1404, 1464-1473`                                              | 45 min | `EventsAfterCommitTest::test_rollback_does_not_leak_event`                                                                 |
| 9.2.7  | POS-GA-F-11 | **Émettre `OrderStatusChanged(PENDING→ACCEPT)` dans `posOrderStore`**. Actuellement `OrderCreated` dispatché + `status=ACCEPT` direct sans transition event → KDS/OSS ne reçoivent pas le signal attendu. | `app/Services/OrderService.php:586-595, 898-908`                                                           | 30 min | `PosOrderInitialStatusEventTest::test_dispatches_pending_to_accept_on_pos_creation`                                        |
| 9.2.8  | POS-GA-F-31 | **Fallback `queue_number` timestamp-based → lever 503**. Si `Cache::lock('queue_lock_{branch}_{today}', 10)->block(5)` échoue → `throw QueueNumberLockTimeoutException` qui map sur HTTP 503 retry-after 2 s, au lieu de fabriquer un `queue_number` non séquentiel. | `app/Services/OrderService.php:467, 794, 1143`, `app/Services/FrontendOrderService.php:389-392`, nouveau `app/Exceptions/QueueNumberLockTimeoutException.php` | 30 min | `QueueNumberFallbackTest::test_lock_timeout_returns_503_not_timestamp`                                                     |
| 9.2.9  | invariant §3 | **Grep invariants en CI**. Ajouter step GitHub Actions exécutant les 6 greps POS_INVARIANTS §3 → failure si hit. | `.github/workflows/ci.yml`, `scripts/check-invariants.sh` (nouveau) | 20 min | — (check CI)                                                                                                               |
| 9.2.10 | POS-GA-F-22 | **Endpoints manquants admin POS : cancel, accept, preparing, ready, delivered**. Wiring simple routes + controller (utilise `OrderStateMachine::apply`) qui seront consommés par le drawer POS-9.6. | `routes/api.php:625-638`, `app/Http/Controllers/Admin/OrderController.php` (5 actions)                     | 1 h    | `AdminOrderActionsTest::test_5_actions_via_state_machine`                                                                  |

**Total : 10 items · ~6 h.**

**Locks shared à poser** : `LOCK_trackB_OrderService_state_machine_refacto_2026-04-18.md` (lignes 1312-1580 quasi-totales), `LOCK_trackB_FrontendOrderService_state_machine_2026-04-18.md` (3 sites L550/661/736), `LOCK_trackB_routes_api_admin_orders_2026-04-18.md`.

**Gate POS-9.2.** Grep `rg "\$order->status\s*=\s*" app/Services/ | rg -v OrderStateMachine` retourne **0 hit**. Grep `::dispatch\(` hors `afterCommit` retourne **0 hit** dans `OrderService`. `OrderStateMachine::apply()` appelé ≥ 8 fois en live. Tous tests state machine verts. CI invariants script vert. Rapport `RUN_POS_9_2_<DATE>.md`.

---

## Vague POS-9.3 — Paiement multi-tender + events canoniques manquants

**Objectif.** Débloquer le modèle paiement (enum binaire PAID/UNPAID → multi-tender). Migration `order_payments` (1-to-N), enum `PaymentStatus` étendu `PARTIALLY_PAID`/`REFUNDED`, `payment_status` dérivé. Classes d'events `OrderCancelled`, `PaymentRecorded`, `OrderRefunded` créées + dispatchées + listeners outbox. `changePaymentStatus` passé sous state machine dédiée.

**Durée estimée.** 1,5 jour.

**Marker synchro Track A.** **`[SEQUENTIAL WAIT P9.5 + POS-9.2]`** — migration shared `orders` (changement `payment_status` enum + nouvelle table `order_payments`) + modification `EventContract::BROADCAST_MAP`. Requires POS-9.2 mergée (state machine canonique) avant pouvoir ajouter `OrderPaymentStateMachine`.

| #      | ID finding  | Item                                                                                                                                                                                                                           | Fichiers (shared)                                                                                                                          | Effort | Tests                                                                                                    |
| ------ | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ------ | -------------------------------------------------------------------------------------------------------- |
| 9.3.1  | POS-GA-F-08 | **Migration `order_payments`** `(id, order_id FK, branch_id, method enum PosPaymentMethod, amount decimal:10,2, transaction_id nullable, auth_code nullable, rrn nullable, recorded_by user_id, recorded_at, refund_of_id nullable self-ref)`. | nouvelle migration `2026_04_20_000000_create_order_payments_table.php`                                                                     | 45 min | `OrderPaymentsSchemaTest::test_unique_constraints_and_fk`                                                |
| 9.3.2  | POS-GA-F-08 | **Enum `PaymentStatus` étendu** `PARTIALLY_PAID(6)`, `REFUNDED(7)`, `PARTIALLY_REFUNDED(8)` + migration `orders.payment_status` enum élargi. | `app/Enums/PaymentStatus.php:1-10`, nouvelle migration `2026_04_20_010000_extend_orders_payment_status.php`                                  | 20 min | `PaymentStatusEnumTest::test_all_cases`                                                                  |
| 9.3.3  | POS-GA-F-08 | **`OrderPaymentStateMachine`** `(UNPAID → PARTIALLY_PAID | PAID) → (REFUNDED | PARTIALLY_REFUNDED)`. Transitions légales + `apply($order, $payment, $actor, $reason)`. | nouveau `app/Domain/Order/OrderPaymentStateMachine.php`                                                                                    | 1 h    | `OrderPaymentStateMachineTest::test_legal_transitions`, `test_illegal_transition_throws`                 |
| 9.3.4  | POS-GA-F-10 | **Refacto `changePaymentStatus`** via `OrderPaymentStateMachine::apply` + `DB::transaction` + `DB::afterCommit` + event `PaymentRecorded` / `OrderRefunded` + permission check. | `app/Services/OrderService.php:1485-1526`                                                                                                   | 1 h    | `ChangePaymentStatusTest::test_tx_rollback`, `test_dispatches_payment_recorded`, `test_requires_permission_for_refund` |
| 9.3.5  | POS-GA-F-13 | **`PaymentService::cashBack` log + idempotent**. Crée ligne `OrderPayment` `type=refund`, `amount<0`, avec motif, log AuditLog. Gère cas "pas de Transaction préalable" (POS cash pur). | `app/Services/PaymentService.php:29-50`                                                                                                     | 45 min | `CashBackAuditTest::test_creates_refund_payment_and_audit_log`                                           |
| 9.3.6  | POS-GA-F-24 | **Events canoniques créés**. `app/Events/OrderCancelled.php`, `app/Events/PaymentRecorded.php`, `app/Events/OrderRefunded.php` + listeners outbox `PersistOrderCancelledToOutbox`, `PersistPaymentRecordedToOutbox`, `PersistOrderRefundedToOutbox` + binding `EventServiceProvider`. | 3 classes Event + 3 listeners + `app/Providers/EventServiceProvider.php`, `app/Http/EventContract.php:37-38` (alignement `BROADCAST_MAP`) | 1 h    | `OrderCancelledEventTest::test_envelope_v1_valid`, idem Payment/Refunded                                 |
| 9.3.7  | POS-GA-F-24 | **Dispatch `OrderCancelled` sur cancel** (remplace `OrderStatusChanged(→16/19)` générique) + `OrderRefunded` sur cashBack. | `app/Services/OrderService.php:1405-1477`, `app/Services/PaymentService.php:29-50`                                                          | 30 min | `CancelDispatchesOrderCancelledTest`, `RefundDispatchesOrderRefundedTest`                                |
| 9.3.8  | POS-GA-F-49 | **Payloads events enrichis**. `OrderCreated.payload` ajoute `source`, `pos_payment_method`, `total_tax`. `OrderStatusChanged.payload` ajoute `reason`, `actor_id`, `source_surface`. | `app/Listeners/PersistOrderCreatedToOutbox.php:24-30`, `PersistOrderStatusChangedToOutbox.php:22-29`                                        | 30 min | `EventPayloadCompletenessTest`                                                                           |
| 9.3.9  | POS-GA-F-48 | **Propager `X-Correlation-ID`** depuis request header jusqu'aux listeners outbox au lieu de régénérer. Middleware `CorrelationIdMiddleware` assigne à `request->attributes->set('correlation_id', …)` puis `PersistOrder*ToOutbox` le lit. | nouveau `app/Http/Middleware/CorrelationIdMiddleware.php`, `app/Listeners/PersistOrderCreatedToOutbox.php:33`, idem statusChanged | 45 min | `CorrelationIdPropagationTest::test_header_propagates_to_event_envelope`                                 |
| 9.3.10 | POS-GA-F-23 | **Endpoint `POST /admin/pos-order/{order}/split-payment`** — accepte N tenders (`[{method, amount}, …]`), Σ == `order.total`, atomique dans transaction. UI `PosPaymentComponent` multi-ligne (dépôt minimal — UX complète en POS-9.7). | `app/Services/PaymentService::recordPayments`, `app/Http/Controllers/Admin/PosOrderController@recordPayments`, `routes/api.php`            | 1 h    | `SplitPaymentTest::test_sum_equals_total`, `test_partial_payment_leaves_partially_paid`                  |

**Total : 10 items · ~8 h.**

**Locks shared à poser** : `LOCK_trackB_OrderService_changePaymentStatus_2026-04-20.md`, `LOCK_trackB_EventContract_broadcast_map_2026-04-20.md`, `LOCK_trackB_PaymentService_2026-04-20.md`, `LOCK_trackB_migration_orders_payment_status_enum_2026-04-20.md`.

**Gate POS-9.3.** Enum `PaymentStatus` 6 cases. Table `order_payments` créée + seedée sur orders existantes (1 payment par ordre PAID, 0 par UNPAID). `OrderCancelled`, `PaymentRecorded`, `OrderRefunded` dispatched + listeners outbox verts + broadcast valide envelope V1. `correlation_id` propagé. Rapport `RUN_POS_9_3_<DATE>.md`.

---

## Vague POS-9.4 — Conformité fiscale NF525 / loi Finance 2018 (Z/X/audit immuable)

**Objectif.** Débloquer la mise en prod France. Introduire `Z reports` (NF525 / décret 2017-1367), `X reports` intraday, `audit_logs` INSERT-only avec hash chaîné HMAC, `order_sequence_no` atomique par branche. **La vague la plus lourde de Phase POS-9** — 2 jours.

**Durée estimée.** 2 jours.

**Marker synchro Track A.** **`[SEQUENTIAL WAIT POS-9.3]`** — nécessite `order_payments` pour agréger Z par méthode. **`[parallel OK kiosk]`** — aucune zone shared kiosk : tout est nouveau code dans `app/Services/Fiscal/`, nouvelles migrations, nouvelles routes `/admin/fiscal/*`.

| #      | ID finding  | Item                                                                                                                                                                                                                                            | Fichiers                                                                                                                                                            | Effort | Tests                                                                              |
| ------ | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------- |
| 9.4.1  | POS-GA-F-38 | **Migration `order_sequence_no`** — colonne `orders.fiscal_sequence_no UNSIGNED BIGINT NULL` + unique composite `(branch_id, fiscal_sequence_no)`. | nouvelle migration                                                                                                                                                  | 20 min | `OrderFiscalSequenceSchemaTest`                                                    |
| 9.4.2  | POS-GA-F-38 | **`FiscalSequenceService::next($branchId)`** — `Cache::lock('fiscal_seq_{branch}', 5)->block(3)` → `SELECT MAX(fiscal_sequence_no) FROM orders WHERE branch_id = ?` + 1 → persist. Appelé depuis `posOrderStore` et `FrontendOrderService::myOrderStore` **en dernier, avant `save()`**. | nouveau `app/Services/Fiscal/FiscalSequenceService.php`                                                                                                             | 1 h    | `FiscalSequenceTest::test_atomic_per_branch_no_gaps`                               |
| 9.4.3  | POS-GA-F-04 | **Migration `audit_logs` INSERT-only** `(id, branch_id, user_id, action enum, resource, resource_id, payload JSON, prev_hash CHAR(64), current_hash CHAR(64), created_at, ip, user_agent, session_id)`. Trigger DB `BEFORE UPDATE/DELETE → SIGNAL SQLSTATE '45000'` (MySQL / MariaDB). | nouvelle migration `2026_04_22_000000_create_audit_logs_table.php`                                                                                                  | 45 min | `AuditLogImmutabilityTest::test_update_blocked`, `test_delete_blocked`             |
| 9.4.4  | POS-GA-F-04 | **`AuditLogService::write(branchId, action, resource, payload)`** — calcule `current_hash = hmac_sha256(prev_hash||payload, SECRET)` où `SECRET = config('fiscal.audit_secret_branch_{id}')` (rotation-able). Append-only. | nouveau `app/Services/Fiscal/AuditLogService.php`                                                                                                                   | 1 h    | `AuditLogHashChainTest::test_chain_verified`, `test_tampering_detected`            |
| 9.4.5  | POS-GA-F-04 | **Adapter call-sites sensibles** à `AuditLogService::write` : cancel, destroy, discount>0, refund, payment_status change, Z open/close, drawer open. Conserver `ActionLog` pour actions non sensibles. | `app/Services/OrderService.php`, `PaymentService.php`, `Pricing/DiscountCalculator.php`                                                                             | 1 h    | `AuditLogSensitiveActionsTest`                                                     |
| 9.4.6  | POS-GA-F-01 | **Migration `z_reports`** `(id, branch_id, sequence_no, opened_at, closed_at, opened_by, closed_by, total_ht, total_ttc, total_by_method JSON, total_by_tax_rate JSON, order_count, cancel_count, refund_count, prev_hash, signature CHAR(64), archived_at)` + unique `(branch_id, sequence_no)`. | nouvelle migration                                                                                                                                                  | 30 min | `ZReportSchemaTest`                                                                |
| 9.4.7  | POS-GA-F-01 | **`ZReportService::open($branchId, $user)`** + **`close($branchId, $user)`**. `close` = calcule agrégats sur `orders WHERE branch_id AND created_at >= last_z.closed_at AND payment_status != UNPAID`, signe HMAC chaîné, verrouille. | nouveau `app/Services/Fiscal/ZReportService.php`                                                                                                                    | 2 h    | `ZReportCloseTest::test_aggregates_correct_totals`, `test_second_close_blocked`     |
| 9.4.8  | POS-GA-F-02 | **`XReportService::snapshot($branchId)`** read-only intraday. Retourne tuiles CA/méthode/staff/heure sans écriture. | nouveau `app/Services/Fiscal/XReportService.php`                                                                                                                    | 45 min | `XReportTest::test_snapshot_matches_orders`                                        |
| 9.4.9  | POS-GA-F-01 | **Endpoints admin `/admin/fiscal/z-report/open`, `/close`, `/list`, `/{id}/pdf`, `/admin/fiscal/x-report`**. Permission `pos-manage-fiscal`. PDF signé (mpdf ou dompdf, libre de droits). | nouveau `app/Http/Controllers/Admin/Fiscal/ZReportController.php`, `XReportController.php`, 5 routes                                                                | 1h30   | `ZReportControllerTest::test_permission_required`, `test_pdf_signed`               |
| 9.4.10 | POS-GA-F-14 | **`OrderService::destroy` : bloquer après Z fermé** — si `order.fiscal_sequence_no IS NOT NULL AND order.created_at < current_open_z.opened_at` → 409 Conflict. | `app/Services/OrderService.php:1585-1598`                                                                                                                            | 20 min | `DestroyBlockedAfterZTest`                                                         |
| 9.4.11 | POS-GA-F-01 | **Export archivable** : commande artisan `foodking:fiscal:archive {branch_id} --from=YYYY-MM-DD --to=YYYY-MM-DD` → zip chiffré (gpg) déposé dans `storage/fiscal/{branch}/{period}.gpg`. Rétention 6 ans. | nouveau `app/Console/Commands/FiscalArchiveCommand.php`                                                                                                              | 45 min | `FiscalArchiveTest::test_zip_contains_all_z_reports_and_orders_and_audit_logs`     |
| 9.4.12 | POS-GA-F-38 | **Permission granulaire** `pos-reopen-z` (interdit sans manager). Permission `pos-manage-fiscal` rattachée Admin + BranchMgr uniquement (pas POS-Op). | `database/seeders/PermissionTableSeeder.php:113-125`, `RoleTableSeeder.php`                                                                                         | 15 min | `FiscalPermissionTest`                                                             |

**Total : 12 items · ~11 h.**

**Locks shared à poser** : `LOCK_trackB_OrderService_fiscal_sequence_2026-04-22.md` (call-site `posOrderStore` + `destroy`). Tout le reste = zones POS-exclusives (new `app/Services/Fiscal/*`, migrations, routes admin fiscal).

**Gate POS-9.4.** `audit_logs` UPDATE/DELETE bloqués DB. Hash chain vérifié. `ZReport::close` atomique. `order.fiscal_sequence_no` sans trou sur une branche. Endpoint X intraday répond agrégats cohérents avec orders DB. Tests fiscal verts. Rapport `RUN_POS_9_4_<DATE>.md` + DPO review note (Q1 humain).

---

## Vague POS-9.5 — Tiroir-caisse + release stock on cancel (shared avec Track A)

**Objectif.** Compléter la brique opérationnelle : tiroir-caisse auditable (sessions, comptage, écarts), listener de libération de stock sur cancel/reject/return (shared kiosk), endpoint admin `AvailabilityService::toggle` (shared kiosk).

**Durée estimée.** 1 jour.

**Marker synchro Track A.** **`[SEQUENTIAL WAIT P9.2 kiosk (catalog backend)]`** pour l'endpoint availability + listener release, car kiosk P9.2.7 crée déjà `AvailabilityController::toggle`. Track B **réutilise** le controller kiosk (don't duplicate).

| #      | ID finding  | Item                                                                                                                                                                                                                                     | Fichiers                                                                                                                                                                    | Effort | Tests                                                                        |
| ------ | ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------- |
| 9.5.1  | POS-GA-F-03 | **Migration `cash_drawer_sessions`** `(id, branch_id, opened_at, opened_by, opening_balance, closing_balance_expected, closing_balance_counted, discrepancy, closed_at, closed_by, notes)`. | nouvelle migration                                                                                                                                                          | 20 min | `CashDrawerSchemaTest`                                                       |
| 9.5.2  | POS-GA-F-03 | **Migration `cash_drawer_events`** `(id, session_id FK, branch_id, type enum[opening,close,cash_in,cash_out,auto_open_on_cash_order], amount nullable, reason nullable, actor_id, occurred_at)`. | nouvelle migration                                                                                                                                                          | 20 min | —                                                                            |
| 9.5.3  | POS-GA-F-03 | **`CashDrawerService`** : `openSession`, `closeSession`, `logAutoOpen` (appelé depuis `posOrderStore` cash), `logManualOpen` (motif obligatoire). Émet event `DrawerEvent` contrat V1. Hooks AuditLog POS-9.4. | nouveau `app/Services/Fiscal/CashDrawerService.php`                                                                                                                         | 1 h 30 | `CashDrawerServiceTest::test_open_close_counting`, `test_discrepancy_recorded` |
| 9.5.4  | POS-GA-F-03 | **UI POS drawer counting**. Modale `CashDrawerCountingComponent.vue` ouverture/fermeture avec numpad + affichage écart calculé. Permission `pos-manage-drawer`. | nouveau `resources/js/components/admin/pos/CashDrawerCountingComponent.vue`                                                                                                  | 1 h    | Vitest `CashDrawerCounting.spec.js`                                          |
| 9.5.5  | POS-GA-F-19 | **Persister `cash_drawer_event.auto_open`** quand `posOrderStore` cash confirmé. Récupère `kioskHardware.openCashDrawer()` ACK success/fail. | `app/Services/OrderService.php:898-908` (after commit), `CashDrawerService::logAutoOpen`                                                                                    | 30 min | `PosCashDrawerAutoOpenTest::test_cash_order_creates_drawer_event`            |
| 9.5.6  | POS-GA-F-05 | **Listener `ReleaseItemAvailabilityOnCanceledOrder`** — sur `OrderCancelled` ou `OrderStatusChanged(→REJECTED|RETURNED)` → `AvailabilityService::incrementForOrder($order)`. | nouveau `app/Listeners/ReleaseItemAvailabilityOnCanceledOrder.php`, `EventServiceProvider`, extension `AvailabilityService::incrementForOrder` (symétrie `decrementForOrder`) | 45 min | `ReleaseStockOnCancelTest::test_stock_returns_to_initial_after_cancel`       |
| 9.5.7  | POS-GA-F-07 | **Expose `AvailabilityController::toggle` dans l'UI POS** (réutilise endpoint créé kiosk P9.2.7). Bouton 86 dans drawer POS item + modale motif. Permission `items-manage-availability`. | `resources/js/components/admin/pos/ItemComponent.vue`, `resources/js/store/pos/posItems.js`                                                                                  | 30 min | Vitest `PosItemAvailabilityToggle.spec.js`                                   |
| 9.5.8  | POS-GA-F-57 | **Seed rôles Runner + Kitchen Lead** + rattache permissions (runner : `pos, orders-view` ; kitchen-lead : `orders-view, items-manage-availability`). | `database/seeders/RoleTableSeeder.php:17-67`                                                                                                                                | 15 min | `RoleSeederTest::test_runner_and_kitchen_lead_exist`                         |
| 9.5.9  | POS-GA-F-58 | **Permissions granulaires seedées** : `pos-cancel-after-paid`, `pos-refund`, `pos-discount-up-to-10`, `pos-discount-over-10`, `pos-amend`, `pos-manage-drawer`, `pos-manage-fiscal`, `items-manage-availability`, `pos-reopen-z`. | `database/seeders/PermissionTableSeeder.php:113-125`                                                                                                                         | 30 min | `PosPermissionsSeederTest::test_9_permissions_seeded_and_assigned_by_role`   |

**Total : 9 items · ~6 h.**

**Locks shared** : `LOCK_trackB_AvailabilityService_incrementForOrder_2026-04-23.md` (sync avec kiosk P9.2.7 qui a créé `toggle`). `LOCK_trackB_OrderService_cash_drawer_hook_2026-04-23.md` (L898-908 dispatch après commit + hook drawer).

**Gate POS-9.5.** Tiroir auditable : ouverture session + comptage + écart calculé + AuditLog. Stock revient bien à l'initial après cancel de 3 orders test. 9 permissions seedées. `items-manage-availability` gate le endpoint toggle. Rapport `RUN_POS_9_5_<DATE>.md`.

---

## Vague POS-9.6 — Drawer multi-sources + KDS/OSS cross-surface + filtres + actions [parallel OK]

**Objectif.** Transformer le drawer POS en **centrale opérationnelle multi-sources**. Afficher toutes les commandes de la branche (kiosk cash, kiosk card, web, app, POS, delivery partners, table), filtrer par source/statut/table/staff/heure, permettre les actions (accept, preparing, ready, delivered, cancel) depuis le drawer. Ajouter colonne "Sur place (POS)" au KDS.

**Durée estimée.** 1 jour.

**Marker synchro Track A.** **`[parallel OK]`** — pas de zone backend partagée critique. `app/Services/BranchActivityService.php` nouveau, contrôleur admin nouveau. Seul `KitchenDisplaySystemComponent.vue` est shared read-side (colonnes) mais Track A ne le modifie pas. Peut démarrer dès POS-9.3 mergée (events canoniques nécessaires au filtre).

| #     | ID finding  | Item                                                                                                                                                                                        | Fichiers                                                                                                                                                       | Effort | Tests                                                                     |
| ----- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------- |
| 9.6.1 | POS-GA-F-15 | **`BranchActivityService::query($branchId, filters)`** retourne paginated orders toutes sources. Endpoint `GET /admin/branch-activity` avec query params `source[], status[], table_id, staff_id, since, until`. | nouveau `app/Services/BranchActivityService.php`, `app/Http/Controllers/Admin/BranchActivityController.php`                                                    | 1 h    | `BranchActivityTest::test_returns_all_sources`, `test_filters_applied`    |
| 9.6.2 | POS-GA-F-15 | **Drawer POS refacto**. Remplacer `loadKioskCashOrders` par `loadBranchActivity` consommant `BranchActivityService`. Sections regroupées (À traiter / En préparation / Prêtes / Clôturées jour). | `resources/js/components/admin/pos/PosComponent.vue:988-1040`, nouveau `resources/js/components/admin/pos/BranchActivityDrawer.vue` (extraction)               | 1 h 30 | Vitest `BranchActivityDrawer.spec.js::renders_all_sources`                |
| 9.6.3 | POS-GA-F-25 | **Filtres UI** (chips source/statut/table/staff/heure) persistés localStorage scopé user+branch. | `resources/js/components/admin/pos/BranchActivityDrawer.vue`, `resources/js/store/pos/posActivity.js`                                                          | 1 h    | Vitest `posActivity.spec.js::persists_filters`                            |
| 9.6.4 | POS-GA-F-22 | **Actions depuis drawer**. Boutons `Accepter`, `En préparation`, `Prête`, `Livrer`, `Annuler`, `Refund`, `Discount` (modale), `Amend` (modale) → appellent endpoints POS-9.2.10 + POS-9.3 + POS-9.7. Permission-gated. | `BranchActivityDrawer.vue` action buttons, service `posOrderActions.js`                                                                                        | 1 h    | Vitest `BranchActivityActions.spec.js::cancel_requires_permission`        |
| 9.6.5 | POS-GA-F-26 | **`OrderType::POS(15)` ajoutée aux colonnes KDS**. 5e colonne "Sur place" ou fusion avec `DINING_TABLE` si `dining_table_id IS NOT NULL` sinon `TAKEAWAY`. Décision pilotée par flag branche `kds.pos_merge_strategy` (Q11 humain). | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:602-655`                                                                 | 45 min | Vitest `KDS.spec.js::pos_orders_visible_per_strategy`                     |
| 9.6.6 | POS-GA-F-27 | **Étendre enum `Source`** avec `UBER_EATS(20), DELIVEROO(30), JUST_EAT(40), AGGREGATOR(99)`. Pas d'intégration API ici (v2+), juste l'enum + UI chip. Listeners outbox enrichissent `payload.source`. | `app/Enums/Source.php:1-12`, migration ALTER sans contrainte back (string stockée)                                                                             | 30 min | `SourceEnumTest`                                                          |
| 9.6.7 | POS-GA-F-54 | **Polling secondaire catalogue en mode dégradé Echo**. Si `window._wsService.connectionState !== 'connected'` depuis > 30 s → `loadItems` + `loadBranchActivity` toutes les 60 s. | `resources/js/components/admin/pos/PosComponent.vue:988-997`                                                                                                   | 30 min | Vitest `PosComponent.spec.js::polls_catalog_when_echo_down`               |

**Total : 7 items · ~7 h.**

**Locks shared** : aucun backend shared. `LOCK_trackB_Source_enum_2026-04-24.md` (enum additive, rétrocompat).

**Gate POS-9.6.** Drawer affiche 5+ sources. Filtres fonctionnels et persistés. 5 actions minimum exécutables depuis drawer. `OrderType::POS(15)` visible KDS. Rapport `RUN_POS_9_6_<DATE>.md`.

---

## Vague POS-9.7 — Amend order + Refund UX + TPE bridge spec [parallel OK]

**Objectif.** Débloquer la mutation post-création (amend : ajouter/retirer/modifier item tant que non DELIVERED) et le refund monétaire partiel. Spécifier et **poser** le bridge TPE avec mocks (intégration TPE réelle = POS-B+ / V2 hardware). Loyalty burn dans `posOrderStore` pour parité kiosk.

**Durée estimée.** 1,5 jour.

**Marker synchro Track A.** **`[parallel OK]`** (POS-9.3 mergée et POS-9.2 state machine en place). `OrderService::addItem/removeItem/updateItem` sont nouveaux mais touchent `OrderService.php` → lock shared.

| #     | ID finding  | Item                                                                                                                                                                         | Fichiers                                                                                                                                                                                | Effort | Tests                                                                        |
| ----- | ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------- |
| 9.7.1 | POS-GA-F-21 | **Endpoints amend**. `PATCH /admin/pos-order/{order}/items` (add/remove/update line). Events `OrderItemAdded`, `OrderItemRemoved`, `OrderItemUpdated` (classes + listeners outbox). Permission `pos-amend`. Bloqué si `status >= PREPARING` sauf permission `pos-amend-after-prep`. | `app/Services/OrderService::addItem/removeItem/updateItem`, `app/Http/Controllers/Admin/PosOrderController@amendItems`, 3 events, 3 listeners, `routes/api.php` | 2 h    | `PosOrderAmendTest::test_add_item_after_create`, `test_blocked_after_prep`   |
| 9.7.2 | POS-GA-F-21 | **Recalcul SSOT sur amend**. `PricingService::recalculate($order)` re-calcule total/TVA après mutation d'items. Diff `Delta` persisté. | `app/Services/Pricing/PricingService.php` (nouvelle méthode `recalculate`)                                                                                                              | 1 h    | `PricingRecalculateTest::test_total_updated_on_item_add`                     |
| 9.7.3 | POS-GA-F-21 | **UI amend**. Modale `OrderAmendComponent.vue` (add item via wizard restore, remove via swipe, update qté). Accessible depuis drawer POS-9.6. | nouveau `resources/js/components/admin/pos/OrderAmendComponent.vue`                                                                                                                     | 1 h 30 | Vitest `OrderAmendComponent.spec.js`                                         |
| 9.7.4 | POS-GA-F-22 | **UI refund partiel**. Modale `RefundComponent.vue` (sélection lignes + montant libre + motif obligatoire). Appelle endpoint POS-9.3 `recordPayments` avec `type=refund`. Stock policy optionnelle : "libérer stock ?" toggle manager. | nouveau `resources/js/components/admin/pos/RefundComponent.vue`                                                                                                                         | 1 h    | Vitest `RefundComponent.spec.js::requires_reason_and_permission`             |
| 9.7.5 | POS-GA-F-18 | **Spec bridge TPE + mock**. `TpeBridgeInterface::pay(amount, method) : TpeResult{auth_code, rrn, acquirer_ref, status}`. Impl `MockTpeBridge` retourne success déterministe en env `local`/`testing`. Placeholder `IngenicoConcertBridge`, `NeptingBridge`, `AdyenTerminalBridge` empty classes avec docblock TODO V2. | nouveau `app/Contracts/TpeBridgeInterface.php`, `app/Services/Payment/MockTpeBridge.php`, 3 stubs | 1 h    | `MockTpeBridgeTest::test_mock_returns_deterministic_success`                 |
| 9.7.6 | POS-GA-F-18 | **Frontend `PaymentComponent` : appel `TpeBridge` sur carte**. Si `PosPaymentMethod::CARD` → spinner "Insérez la carte", mock local retourne auth_code 5s plus tard, persiste dans `OrderPayment.auth_code/rrn`. Feature flag `pos.tpe_bridge = mock|ingenico|nepting`. | `resources/js/components/admin/pos/PaymentComponent.vue:64-67, 191-277`                                                                                                                  | 1 h    | Vitest `PaymentComponent.spec.js::card_flow_uses_tpe_bridge`                 |
| 9.7.7 | POS-GA-F-32 | **Loyalty burn dans `posOrderStore`**. Extraire `LoyaltyService::redeemInTransaction($user, $amount)` (code déjà existant côté kiosk L434-499). Appeler depuis `posOrderStore` L829-836 si `pos_loyalty_burn` dans payload. | `app/Services/LoyaltyService.php` (extraction), `app/Services/OrderService.php:829-836`                                                                                                 | 45 min | `PosLoyaltyBurnTest::test_burn_reduces_total_and_updates_ledger_with_lock`   |
| 9.7.8 | POS-GA-F-51 | **Symétrie ActionLog kiosk**. Ajouter `ActionLog::create` dans `FrontendOrderService::myOrderStore` pour parité POS (déjà fait L887 côté POS). | `app/Services/FrontendOrderService.php:500-555`                                                                                                                                         | 15 min | `KioskActionLogSymmetryTest`                                                 |

**Total : 8 items · ~9 h.**

**Locks shared** : `LOCK_trackB_OrderService_amend_2026-04-25.md` (nouvelles méthodes — pas de modif existant), `LOCK_trackB_FrontendOrderService_actionlog_2026-04-25.md` (1 ligne, sync avec Track A si touche `FrontendOrderService`).

**Gate POS-9.7.** Amend ajoute/retire item avec prix recalculés SSOT + events. Refund partiel trace `OrderPayment` + AuditLog. TPE mock câblé avec feature flag. Loyalty burn POS identique kiosk. Rapport `RUN_POS_9_7_<DATE>.md`.

---

## Vague POS-9.8 — Ticket + TVA cascade + UX caisse [parallel OK]

**Objectif.** Finaliser l'expérience encaissement : TVA cascadée par `order_type`, ticket complet (queue_number + TVA ventilée + conforme CGI), réimpression + journal, UX caisse (historique cross-source, notifications sonores finalisées, token serveur).

**Durée estimée.** 1 jour.

**Marker synchro Track A.** **`[parallel OK]`** — `PricingService::taxBreakdown` (déjà exposé en POS-9.1.13) étendu ici ; nécessite lock shared léger. Le reste = zones POS-exclusives (`ReceiptComponent.vue`, nouvelles routes, nouveau composant history).

| #     | ID finding  | Item                                                                                                                                                                     | Fichiers                                                                                                                                                     | Effort | Tests                                                                         |
| ----- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------ | ----------------------------------------------------------------------------- |
| 9.8.1 | POS-GA-F-37 | **TVA cascade par `order_type`**. `TaxCalculator::resolve($item, $orderType)` applique mapping `dine_in ⇒ 10%, takeaway ⇒ 5.5%, alcohol ⇒ 20%` configurable `config/fiscal.php`. Variations et extras héritent. | `app/Services/Pricing/TaxCalculator.php:9-17`, `app/Services/Pricing/PricingService.php:141-152`, nouveau `config/fiscal.php` | 1 h 30 | `TaxCascadeTest::test_dine_in_10_percent`, `test_takeaway_5_5_percent`, `test_alcohol_20_percent` |
| 9.8.2 | POS-GA-F-20 | **Ticket conforme CGI art. 242 nonies A**. Étendre `ReceiptComponent` : SIRET/TVA intracom branche, ventilation TVA par taux, `fiscal_sequence_no`, `z_report_id` (si clos). Asset branding branche. | `resources/js/components/admin/pos/ReceiptComponent.vue:105-112`, nouvelles props via `OrderResource`                                                         | 1 h    | Vitest `ReceiptComponent.spec.js::renders_cgi_compliant_fields`               |
| 9.8.3 | POS-GA-F-35 | **Imprimer `queue_number` sur ticket** (au lieu de `token`). Client `token` relegué au journal technique. | `resources/js/components/admin/pos/ReceiptComponent.vue:152-156`                                                                                              | 10 min | Vitest `ReceiptComponent.spec.js::prints_queue_number`                        |
| 9.8.4 | POS-GA-F-42 | **Token serveur atomique**. Remplacer `localStorage daily seq` par `Cache::lock('pos_token_{branch}_{today}', 5)->block(3)` + MAX, identique à `queue_number`. Renvoyé par `posOrderStore`. | `app/Services/OrderService.php:773-799` (factoriser un `DailySequenceService`), `resources/js/components/admin/pos/PosComponent.vue:1253-1266`                 | 45 min | `PosTokenAtomicTest::test_no_collision_cross_caisse`                          |
| 9.8.5 | POS-GA-F-34 | **Migration `print_logs`** `(id, branch_id, order_id, printed_by, printed_at, type enum[order_receipt, reprint, kitchen], printer_id nullable, success bool, error nullable)`. `PrintService::log` appelé depuis `ReceiptComponent`. Bouton "Réimprimer" depuis drawer POS-9.6 avec AuditLog. | nouvelle migration, nouveau `app/Services/PrintService.php`, `BranchActivityDrawer.vue` bouton re-print                                                       | 1 h    | `PrintLogTest::test_reprint_logged`                                           |
| 9.8.6 | POS-GA-F-52 | **Historique commandes closes cross-source**. Endpoint `GET /admin/order-history` paginé + filtres. UI `OrderHistoryComponent.vue` avec recherche + re-print + duplicate (clone items vers nouveau panier). | `app/Http/Controllers/Admin/OrderHistoryController.php`, `resources/js/components/admin/pos/OrderHistoryComponent.vue`                                       | 1 h 30 | Vitest `OrderHistoryComponent.spec.js::searches_across_sources`               |
| 9.8.7 | POS-GA-F-55 | **Notifications finalisées**. Son + toast + desktop Notification API (permission granted) + PusherBeams interest `branch.{id}.staff` pour push onglet fermé (prépare POS-GA-F-59). | `resources/js/components/admin/pos/PosComponent.vue:1004-1010`, `resources/js/services/posNotifications.js`                                                   | 45 min | Vitest `posNotifications.spec.js::triggers_4_channels`                        |

**Total : 7 items · ~7 h.**

**Locks shared** : `LOCK_trackB_TaxCalculator_cascade_2026-04-26.md`, `LOCK_trackB_PricingService_resolve_by_order_type_2026-04-26.md`.

**Gate POS-9.8.** Ticket conforme CGI (sur 3 orders exemples : dine-in, takeaway, alcohol). Token serveur sans collision (test parallèle 2 caisses / 100 orders). Réimpression loggée. Historique fonctionnel. Rapport `RUN_POS_9_8_<DATE>.md`.

---

## Vague POS-9.9 — Parité POS/Kiosk + wizard serveur + idempotency scoped branch + allergens unified [SEQUENTIAL WAIT P9.3 kiosk]

**Objectif.** Unifier le contrat `OrderItemPayload` POS ↔ Kiosk, durcir la validation serveur du wizard (min/max/required), remplacer les heuristiques FR par le `role` enum (shared kiosk P9.3), scoper l'idempotency sur `(branch_id, key)`, unifier les allergens (shared kiosk C-5).

**Durée estimée.** 1 jour.

**Marker synchro Track A.** **`[SEQUENTIAL WAIT P9.3 kiosk]`** — utilise `item_attributes.role` enum créé en kiosk P9.3.1. **`[SEQUENTIAL WAIT P9.5 kiosk]`** — utilise migration `idempotency_key` unique composite créée en kiosk P9.5.4. **`[SEQUENTIAL WAIT P9.2 kiosk]`** — utilise `AllergenService::projectFlags` créé en kiosk P9.2.6.

| #     | ID finding  | Item                                                                                                                                                                          | Fichiers                                                                                                                                                       | Effort | Tests                                                              |
| ----- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------ |
| 9.9.1 | POS-GA-F-30 | **Contrat `OrderItemPayloadContract v1`** shared POS+Kiosk+Web. Zod-like schema PHP (enum versions). Adapter `PosItemAdapter::fromLegacy($pos_v0) : v1`. | nouveau `app/Domain/Order/Contracts/OrderItemPayloadContract.php`, `app/Domain/Order/Adapters/PosItemAdapter.php` | 1 h 30 | `OrderItemContractTest::test_pos_and_kiosk_produce_same_v1`        |
| 9.9.2 | POS-GA-F-29 | **`ValidJsonOrder` : min_selection/max_selection/required par attribut**. Pré-chargement `itemAttributes` + vérif conformité sélections wizard. Rejet 422 avec message. | `app/Rules/ValidJsonOrder.php:31-73`, `app/Http/Requests/PosOrderRequest.php:58`                                                                                | 1 h    | `ValidJsonOrderWizardTest::test_sandwich_without_bread_rejected`   |
| 9.9.3 | POS-GA-F-46 | **Remplacer heuristiques noms FR par `ItemAttribute.role`**. `buildWizardRestorePayload` utilise `attribute.role === 'bread'` au lieu de `name.includes('pain')`. | `resources/js/components/admin/pos/ItemComponent.vue:721-915`                                                                                                    | 1 h    | Vitest `ItemComponent.spec.js::restore_uses_role_not_name`         |
| 9.9.4 | POS-GA-F-28 | **Idempotency POS `Cache::lock` + scope `(branch_id, key)`**. Aligner sur pattern kiosk `Cache::lock('pos_order_idempotency_{branch}_{key}', 10)->block(5)`. Migration `orders.idempotency_key` unique composite (shared kiosk P9.5.4). | `app/Services/OrderService.php:550-556`                                                                                                                         | 45 min | `PosIdempotencyBranchScopedTest::test_double_submit_same_key_returns_existing` |
| 9.9.5 | POS-GA-F-63 | **Allergens source unique (pivot)**. Appeler `AllergenService::projectFlags($item)` (kiosk P9.2.6) depuis observer `ItemSaving` côté POS également. Déprécation `items.allergen_flags` JSON avec warning log 3 mois. | `app/Services/AllergenService.php` (réutilisé), `app/Observers/ItemObserver.php`                                                                                 | 45 min | `AllergenUnifiedTest::test_pivot_source_of_truth`                  |

**Total : 5 items · ~5 h.**

**Locks shared** : `LOCK_trackB_OrderService_idempotency_branch_scoped_2026-04-27.md`, `LOCK_trackB_ValidJsonOrder_wizard_rules_2026-04-27.md`. Tous les autres = consommateurs de code kiosk déjà mergé.

**Gate POS-9.9.** Contrat v1 POS et kiosk produisent payload identique sur même item. Wizard rejette sandwich sans pain. Heuristiques FR supprimées (grep `includes('pain')` dans `resources/js/components/admin/pos/` = 0 hit). Idempotency tient sur double-submit 1000 rps simulé. Rapport `RUN_POS_9_9_<DATE>.md`.

---

## Vague POS-9.10 — Build final + dashboard + observabilité + rapport + handoff Track C

**Objectif.** Boucler Phase POS-9. Dashboard opérationnel complet, observabilité outbox, tests E2E étendus, build prod, rapport final, handoff vers Track C (E2E Global Sync Kiosk+POS).

**Durée estimée.** 0,5 à 1 jour.

**Marker synchro Track A.** **`[parallel OK]`** — zone POS-exclusive (dashboard admin + tests POS + handoff).

| #       | ID finding  | Item                                                                                                                                                                                                   | Fichiers / Livrable                                                                                                      | Effort |
| ------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ | ------ |
| 9.10.1  | POS-GA-F-53 | **Tuiles dashboard** : CA J/J-1/J-7/J-30 comparé, ticket moyen, panier moyen, taux conversion kiosk (orders kiosk_done / kiosk_started events), rupture temps réel (list items `is_available=false`), hardware status (imprimantes/TPE/tiroir par branche), uptime borne 24h sparkline. | `app/Services/DashboardService.php` (ajouts), `resources/js/components/admin/dashboard/*` (tuiles)                        | 2 h    |
| 9.10.2  | POS-GA-F-60 | **Outbox alerting** : commande `foodking:outbox:alerts` + metric Sentry/Bugsnag sur `domain_events WHERE attempts >= 5 AND dispatched_at IS NULL`. Notification Echo staff branche `HardwareStatusDegraded`. | `app/Console/Commands/OutboxAlertCommand.php`, `app/Events/HardwareStatusDegraded.php`                                    | 45 min |
| 9.10.3  | POS-GA-F-50 | **Aligner `DomainEvent::scopeFailed(attempts)`** avec `tries=5` (5 pas 4). Ajouter scope `scopeDeadLettered` pour events définitivement failed. | `app/Models/DomainEvent.php:44-48`, `app/Jobs/DispatchDomainEventsJob.php:21-23`                                           | 15 min |
| 9.10.4  | POS-GA-F-61 | **Migration `domain_events.channel`** VARCHAR → JSON natif. | nouvelle migration                                                                                                        | 20 min |
| 9.10.5  | POS-GA-F-59 | **PusherBeams staff POS** : interest `branch.{id}.staff` + push natif (onglet fermé). Intégration SDK Web Push côté frontend. | `resources/js/services/posNotifications.js`, `app/Services/PushNotificationService.php`                                   | 1 h    |
| 9.10.6  | POS-GA-F-62 | **Constant `QUEUE_PAD_LENGTH = 4`** pour `SimulateKioskOrders` + tout formateur queue_number. | `app/Console/Commands/SimulateKioskOrders.php:38`                                                                         | 10 min |
| 9.10.7  | POS-GA-F-44 | **Defense-in-depth `OrderService::list`** : ajouter `->where('branch_id', $authUser->branch_id)` explicite en plus de `BranchScope`. | `app/Services/OrderService.php:102-170`                                                                                    | 15 min |
| 9.10.8  | POS-GA-F-40 | **Playwright POS E2E** : scénarios (a) POS happy-path cash takeaway, (b) double-submit POS, (c) amend order, (d) split payment, (e) refund partiel, (f) cancel avec release stock, (g) branch isolation cross-tenant. | `tests/e2e/pos-happy-path.spec.ts`, `pos-safety.spec.ts`, `pos-multi-tender.spec.ts`, `pos-branch-isolation.spec.ts`       | 2 h    |
| 9.10.9  | —           | **Coverage gate** : exiger `pos-related` files ≥ 80 % lines coverage. | `phpunit.xml`, `vitest.config.js`                                                                                          | 15 min |
| 9.10.10 | —           | **`npm run production` + `php artisan optimize:clear && config:cache && route:cache`** | build prod                                                                                                                | 15 min |
| 9.10.11 | —           | **Rapport final** `reports/execution/POS_PHASE_9_FINAL_<DATE>.md` : phases cochées, evidence (screenshots Playwright, axe, Vitest/PHPUnit output), risques résiduels, diff total lines-of-code. | livrable                                                                                                                  | 45 min |
| 9.10.12 | —           | **Mise à jour `CLAUDE.md` + `AGENTS.md` + `docs/ORDER_FLOW.md` + `docs/AUTHZ_MATRIX.md`** avec nouveaux flows (multi-tender, amend, Z fiscal, permissions granulaires). | docs                                                                                                                      | 45 min |
| 9.10.13 | —           | **Handoff Track C** `tasks/handoff/POS_PHASE_9_HANDOFF.md` + `tasks/handoff/TRACK_C_E2E_BRIEF.md` — scénarios E2E multi-surfaces à tester (kiosk → KDS → POS → OSS → Z fiscal cloture → archive). | docs                                                                                                                      | 30 min |
| 9.10.14 | —           | **Mise à jour `CROSS_TRACK_STATUS.md`** : tous items POS-9.X `status=closed`. | `tasks/phase9-sync/CROSS_TRACK_STATUS.md`                                                                                 | 10 min |

**Total : 14 items · ~9 h.**

**V2+ reportés (hors scope POS-9)** :
- POS-GA-F-56 Money en cents int (refonte lourde, orchestration séparée).
- POS-GA-F-64 Multi-devise scopé branche.
- POS-GA-F-59 intégration native delivery partners (Deliverect/Otter) — enum ajoutée en POS-9.6, API V2.

**Gate POS-9.10.** Build prod OK, < 27 s. Tuiles dashboard fonctionnelles. Playwright POS 7 scénarios verts. Coverage pos-related ≥ 80 %. Rapport final livré. Handoff Track C écrit. Verifier indépendant final 100 % RESOLVED sur les 64 findings.

---

## Couverture invariants §1 (check)

| # | Invariant                                                   | Couvert par test                                                                                                                                   |
| - | ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 | SSOT pricing POS                                            | POS-9.1.8 `PosOrderRequestNullableTotalTest` + POS-9.7.2 `PricingRecalculateTest` + POS-9.8.1 `TaxCascadeTest`                                    |
| 2 | `branch_id` serveur                                         | POS-9.1.3 `BranchIsolationTest` (6 scénarios) + POS-9.1.4 `ActionLogBranchIsolationTest` + POS-9.1.5 `KdsBranchFilterTest`                        |
| 3 | `OrderStateMachine::apply()` seul gatekeeper                | POS-9.2.2-2.4 (3 tests refacto state machine) + POS-9.2.9 CI grep invariant                                                                       |
| 4 | `DB::afterCommit()` systématique                            | POS-9.2.6 `EventsAfterCommitTest::test_rollback_does_not_leak_event`                                                                              |
| 5 | EventContract V1                                            | POS-9.3.6 `OrderCancelledEventTest::test_envelope_v1_valid` + existant `EventContractTest`                                                        |
| 6 | Idempotency POS `Cache::lock` + `(branch_id, key)`          | POS-9.9.4 `PosIdempotencyBranchScopedTest`                                                                                                         |
| 7 | Permissions Spatie avant cancel/refund/discount>seuil/amend | POS-9.1.1 `PosDiscountPermissionTest` + POS-9.3.4 refund + POS-9.7.1 amend + POS-9.5.9 `PosPermissionsSeederTest`                                 |
| 8 | Audit log immuable                                          | POS-9.4.3 `AuditLogImmutabilityTest` + POS-9.4.4 `AuditLogHashChainTest`                                                                           |
| 9 | Z fiscal conforme                                           | POS-9.4.7 `ZReportCloseTest` + POS-9.4.11 `FiscalArchiveTest`                                                                                      |
| 10 | Tiroir-caisse loggé                                        | POS-9.5.3 `CashDrawerServiceTest` + POS-9.5.5 `PosCashDrawerAutoOpenTest`                                                                         |
| 11 | Allergens visibles caissier                                | POS-9.1.14 `ItemResourceAdminAllergensTest`                                                                                                         |
| 12 | TVA cascade par `order_type`                                | POS-9.8.1 `TaxCascadeTest` (3 cas)                                                                                                                 |
| 13 | Multi-tenders                                               | POS-9.3.10 `SplitPaymentTest` + POS-9.3.4 `ChangePaymentStatusTest`                                                                                |
| 14 | Temps réel Pusher + fallback polling                        | POS-9.1.10 `PosComponent.spec.js::updates_item_availability_on_echo_event` + POS-9.6.7 `PosComponent.spec.js::polls_catalog_when_echo_down`       |
| 15 | Dashboard data scopées branche                              | POS-9.10.1 + POS-9.1.4 `test_dashboard_does_not_leak_cross_tenant_logs`                                                                            |

**→ 15/15 invariants couverts par ≥ 1 test dédié.**

---

## DAG & séquencement

```
                           ┌─────────────────────────────────────┐
Legend:                    │ [║] séquentiel avec Track A         │
  ║ = WAIT Track A merged  │ [‖] parallèle OK avec Track A       │
  ‖ = parallel OK          │ LOCK = lock shared à poser          │
                           └─────────────────────────────────────┘

POS-9.1 [‖] stop-bleed ─────────────────────────┐
 (bloque tout, ~1 jour, 4 locks narrow)         │
                                                ▼
POS-9.2 [║ WAIT kiosk P9.5] state machine ──────┤
 (1,5 j, lock OrderService + FrontendOrderService + routes)
                                                │
                ┌───────────────────────────────┤
                ▼                               ▼
POS-9.3 [║ WAIT POS-9.2]           POS-9.6 [‖] drawer multi-sources
 multi-tender events (1,5 j)        (1 j, peut démarrer après POS-9.3)
                │
                ▼
POS-9.4 [║ WAIT POS-9.3]
 NF525 Z/X/audit (2 j, zone POS-exclusive)
                │
                ▼
POS-9.5 [║ WAIT kiosk P9.2 pour toggle] ────────┐
 tiroir + release stock (1 j)                   │
                                                ▼
                                         POS-9.7 [‖] amend+refund+TPE (1,5 j)
                                                │
                                                ▼
                                         POS-9.8 [‖] ticket + TVA cascade + UX (1 j)
                                                │
POS-9.9 [║ WAIT kiosk P9.2 + P9.3 + P9.5] ──────┘
 parité POS/kiosk (1 j — consomme sorties kiosk)
                │
                ▼
POS-9.10 [‖] build + rapport + handoff Track C (0,5 j)
```

### Durée totale

| Scenario                                                        | Jours ouvrés |
|-----------------------------------------------------------------|--------------|
| Séquentiel strict (aucune parallélisation B↔B)                  | 11,5 j       |
| Avec parallélisation interne POS-9.6 ‖ POS-9.4, POS-9.7 ‖ POS-9.8, POS-9.9 ‖ POS-9.10 | **9-10 j**   |

**→ < 12 jours ouvrés ✓ (POS_INVARIANTS §2 POS-B gate).**

---

## Recommandation immédiate (actions suivantes)

### État Track A au moment de la livraison POS-B

Lecture du repo à l'instant : `reports/execution/` ne contient **aucun** `RUN_P9_1_KIOSK_<DATE>.md` → Track A est **encore au plan** (fichier `PLAN_PHASE_9_KIOSK_2026-04-18.md` modifié, pas encore exécuté). Aucun lock dans `tasks/phase9-sync/` (dossier inexistant). Aucun commit `feat(kiosk/phase-9.1)` dans l'historique récent.

**Conclusion.** Track A est à l'état **pré-P9.1**. Track B (POS) peut démarrer **POS-9.1 en parallèle de kiosk P9.1** dès validation humaine.

### Séquence optimale proposée

| Moment              | Track A (Kiosk)                   | Track B (POS)              | Commentaire                                                                                                                                                                                        |
| ------------------- | --------------------------------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Jour 1              | P9.1 stop-the-bleed               | **POS-9.1 stop-the-bleed** | Parallèle OK. Les 4 locks POS-9.1 ne chevauchent pas les 14 items kiosk P9.1 (kiosk touche `NormalItemResource`, `kioskMenu.js`, `FrontendSurfaceFilteringTest` ; POS touche `OrderService::destroy|deliveryBoy`, `action_logs`, `KitchenDisplaySystemOrderService`, `ItemResource` admin). |
| Jour 2              | merge P9.1 + démarrer P9.2        | merge POS-9.1 + rebase     | Merge POS-9.1 **après** P9.1 (P9.1 premier, par convention SYNC §3).                                                                                                                                |
| Jour 3              | P9.2 catalog backend              | **STOP Track B**           | P9.2 crée `AvailabilityController::toggle` et `AllergenService::projectFlags` que POS-9.5 et POS-9.9 consomment → attendre merge P9.2.                                                             |
| Jour 4              | P9.3 wizard robustness            | **POS-9.6 [‖]**            | Dès P9.2 merge sur main, démarrer POS-9.6 (drawer multi-sources) qui n'est pas shared backend. |
| Jour 5              | P9.4 UX hors-wizard               | **POS-9.6 suite**          | Continue. |
| Jour 6              | P9.5 order pipeline (backend)     | **STOP Track B backend**   | P9.5 touche `OrderService` lourdement (allergens snapshot, cleanup stale, idempotency). POS-9.2 ne peut pas démarrer avant merge P9.5. |
| Jour 7              | merge P9.5 + P9.6 analytics       | **POS-9.2 state machine**  | Rebase depuis main. Démarre POS-9.2 sur state machine. |
| Jour 8              | P9.7 i18n/a11y                    | POS-9.3 multi-tender       | |
| Jour 9              | P9.8 tests E2E                    | POS-9.4 NF525 (début)      | Zone POS-exclusive — parallèle OK. |
| Jour 10             | P9.9 compétitifs (sélectif)       | POS-9.4 (fin) + POS-9.5    | |
| Jour 11             | P9.10 build final                 | POS-9.7 + POS-9.8 ‖        | |
| Jour 12             | merge final kiosk                 | POS-9.9 + POS-9.10 ‖       | |
| Jour 13 (buffer)    | —                                 | merge final POS            | Track C E2E démarre. |

**Durée totale séquence optimale** : ~12 jours avec Track A en tête de 1 jour. Si Track A accélère ou Track B parallélise plus, 10-11 jours atteignables.

### Deliverables POS-9.1 attendus à l'issue

1. **PR unique** `feat(pos/phase-9.1): stop-the-bleed P0 fixes` (branche `feat/pos-phase-9-1`) couvrant les 14 items avec **un commit atomique par sous-phase** (`feat(pos/phase-9.1.1)`, …, `feat(pos/phase-9.1.14)`).
2. **Rapport** `reports/execution/RUN_POS_9_1_<DATE>.md` avec :
   - diff + summary lines-of-code par item,
   - evidence Vitest/PHPUnit verts (14 nouveaux tests + full suite),
   - screenshots avant/après pour 5 changes UX visibles (discount permission modale motif, allergen badge POS, tiroir auto-open, son/toast new order, TVA ventilée ticket),
   - sortie des 6 greps invariants (POS_INVARIANTS §3) tous vides.
3. **4 locks shared** posés puis release dans `tasks/phase9-sync/` avec `BROADCAST_2026-04-18.md` écrit à Track A.
4. **`VERIFY_POS_9_1_<DATE>.md`** par sous-agent verifier indépendant : 14 findings POS-GA-F-* marqués `RESOLVED`.
5. **CI verte** complète (PHPUnit + Vitest + Playwright + invariants script).
6. **Build prod** < 27 s, bundle POS stable ±5 %.
7. **`CROSS_TRACK_STATUS.md`** mis à jour avec 14 items POS-9.1 `status=closed`.

---

**Validation humaine demandée avant démarrage effectif de POS-9.1.**

Ambiguïtés produit bloquant certaines vagues (cf `tasks/phase9-pos/QUESTIONS_HUMAN_2026-04-18.md`) :
- Q1 (NF525 scope France v1) — bloque POS-9.4.
- Q2 (multi-tender obligatoire v1 ?) — bloque POS-9.3.
- Q3 (seuils discount, escalation manager) — impacte POS-9.1.1 et POS-9.5.9.
- Q4 (dine-in v1 ou V2+) — impacte POS-9.1.6.
- Q5 (TPE cible) — impacte POS-9.7.5-6 (mock suffit si TPE réel = V2).
- Q8 (stock policy on cancel) — impacte POS-9.5.6.
- Q11 (KDS stratégie merge POS) — impacte POS-9.6.5.
- Q14 (ordre vagues : conformité d'abord ?) — impacte tout le DAG.

Si réponses Q1-Q14 reçues en même temps que validation POS-B → on démarre immédiatement. Sinon le plan est exécutable avec hypothèses par défaut documentées dans chaque item.
