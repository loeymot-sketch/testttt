# GOAL — V1 LOCAL Le Cayenne : Synchronisation + Prise de Commande — Production-Final

> **Mission** : amener V1 LOCAL Le Cayenne à **100 % production-ready, sans faute**, en validant que **la commande traverse correctement TOUS les systèmes jusqu'à sa sortie**, selon le **type de commande** et la **surface d'entrée** (BORNE / CAISSE / TÉLÉPHONE), avec produits variés. Validation E2E **visuelle + technique**, en prenant la place du client, avec armée d'agents **GStack + Superpowers + adversaires** par sous-agent. Boucle jusqu'au vert absolu — convergence à deux cycles identiques.
>
> **Owner directive (2026-05-29, /goal)** : « turn as much as you want, the most robust / complex / disciplined / tiring, no limits ; come back only with a 100 % valid result, without fault, without doubt ; even at the end, run another hostile test in a bad mood — see all the bad things, maximum reasoning. » Retour owner = projet prêt prod : réaligner DB → brancher imprimante → encaissement comptoir (CB via app TTP logicielle en attendant les TPE physiques caisse/Valina + contrat acquéreur).
>
> Auteur : Claude (orchestrateur). Skill : `ultra-architect-planify`. Pipeline par tâche : `ultra-audit-profond`. Date : 2026-05-29. HEAD ancre : `962d9d154` (branche `heal/cms-pr1-quickwins-2026-05-18`).

---

## §0 — Préambule

### §0.1 Working-tree decision
- Branche de travail : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `962d9d154`).
- **Backup obligatoire avant tout heal** : `backup/pre-goal-sync-ordertaking-2026-05-29` + DB dump `storage/backups/goal-2026-05-29/foodking-dump.sql` (créés en Wave 1 closure).
- Working-tree actuel : artefacts non-suivis (`.playwright-mcp/`, `baseline-2026-05-29/`, `reports/audit/goal-v1-sync-ordertaking-2026-05-29/`) — **non commités**, additifs, hors scope code.
- **`git add` explicite par fichier uniquement** (jamais `git add .` / `-A` — discipline secret-safety CLAUDE.md §3quater).

### §0.2 Scope-expansion flags
- **AUCUN wireup Mobile/Web ↔ backend** (mandat owner immuable — standalone séparés).
- **AUCUN cloud / SaaS / multi-tenant** action (mandat `feedback_no_cloud_until_owner_initiates.md`).
- Paiement V1 = **encaissement comptoir** (Plan B `kiosk.payment_route_all_to_counter=true`). CB via **app Tap-to-Pay logicielle** intérim, en attendant TPE physiques caisse + Valina + contrat acquéreur (SG Sogemonétique / Worldline). Pas de SumUp imposé — provider à confirmer owner.

### §0.3 Pipeline par tâche (NE PAS re-décrire)
Chaque tâche d'exécution suit `~/.claude/skills/ultra-audit-profond/` (14 étapes : 5 spécialistes read-only parallèle → synthèse → implémenter TDD → RED → test → visuel → RED-visuel → converge). Frozen-zone touch → `~/.claude/skills/lock-plan/`.

### §0.4 Convergence criteria (GLOBAL — borrowed test-e2e rule)
> **DONE = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de findings identiques**, sur chaque système ET sur les intersections cross-surface. Plus : frozen-zone diff = 0 LOC, NF525 chain CHAIN OK (append-only), 0 console error sur chaque surface authentifiée, 0 raw label, 0 layout break, screenshots Read+analysés.

### §0.5 Rejection rules (Axis 6 — appliquées littéralement)
Raw label visible / layout break / console error / frozen-zone diff line / P0 RED non traité / acceptance sans test path / « almost works » / NF525 chain modifiée (delete/truncate) / 2 cycles findings divergents → **REJECT + heal**. Production-perfect ou block. Jamais « good enough ».

### §0.6 Intégration des findings audit
La campagne adversariale (workflow `wf_cac5778f-ba6` : 9 cartographie → 27 lens GStack/RED → 9 verify-gate, ancrée HEAD `962d9d154`, deep sync + prise-de-commande) persiste ses findings vérifiés (greppés file:line ou droppés) dans `reports/audit/goal-v1-sync-ordertaking-2026-05-29/<system>-verified.json`. **Chaque finding confirmé entre dans la file de la wave correspondante** (§X) comme tâche de heal. Findings hallucinés/déjà-couverts = droppés (discipline `verify-before-report`). État baseline visuel déjà capturé : `baseline-2026-05-29/{kiosk-idle,login,pos,kds,oss}.png` — 5 surfaces GREEN.

---

## §1 — Map principal (systèmes centraux V1)

Maturité observée (0-10) + ancres vérifiées HEAD `962d9d154` + tests existants. ⛔ = frozen zone (CLAUDE.md §7).

| # | Système | Maturité | Ancres vérifiées (réelles) | Tests existants |
|---|---|:--:|---|---|
| S1 | **Synchronisation** (Outbox + broadcast + polling fallback + cascades) | 9.0 | `app/Jobs/DispatchDomainEventsJob.php` (backoff `[1,5,15,60,300]` tries=6), `app/Models/DomainEvent.php` (scopePending/Stale/Failed), 15× `app/Listeners/Persist*ToOutbox.php`, `app/Listeners/EscalateOutboxBroadcastSwallowed.php`, `app/Console/Commands/{OutboxRetryFailed,OutboxRescue,MonitorOutboxStaleness,PruneOutbox,OutboxWebhookRetryFailed}Command.php`, `routes/channels.php` (branch.{id} kiosk-token name-check) | `tests/Feature/{Outbox,OutboxRescue,SyncComprehensive,KioskRealtimeBroadcast}Test.php` + `tests/js/{posSyncFallback,kdsSyncCadence,ossSyncFallback,realtimeBroadcastFallback,orderStatusScreenOssSync,runtimeSyncFlagsWiring}.spec.js` + ~16 `tests/e2e/*sync*.spec.js` |
| S2 | **Prise de commande** (lifecycle + state machine + pricing SSOT + composition + quote) | 8.5 | `app/Domain/Order/{OrderStateMachine,PaymentStateMachine,AutoPrepareOnPaidPolicy}.php`, `app/Domain/Kds/KitchenReleaseRule.php`, `app/Http/Controllers/{Admin/PosOrderController,Frontend/OrderController,Frontend/KioskEventController,Table/OrderController}.php`, ⛔`app/Services/Pricing/PricingService.php` | `tests/Feature/{OrderFlow,OrderStateMachineLockForUpdate,ConcurrentOrder,QueueNumberConcurrency,OrderStateTransition,OrderItemCompositionSnapshot}Test.php`, `tests/Feature/Pos/*`, `tests/Feature/Kiosk{QuoteIntegrity,QuoteTokenRequiredOnCommit,PaymentStateMachine}*`, `QuoteTamperTest` |
| S3 | **POS Caisse** (commande staff + cash/card + tiroir + parked + refund) | 8.5 | `app/Http/Controllers/Admin/{PosController,PosOrderController,Pos/ParkedOrderController,PosLoyaltyController}.php`, `resources/js/components/admin/pos/PosComponent.vue`, ⛔`public/js/pos-wizard.js` + ⛔`pos-wizard.css` + ⛔`admin-pos-v4.blade.php` + ⛔`PaymentComponent.vue` + ⛔`v5/PosV5TrancheRow.vue` | `tests/Feature/Pos/*` (PosCashEndpointSentinel, PosPricingSsotProof, PosKioskPricingParity, PosRefundUiPermissionSentinel, PosDiscountForgery, PosTicketRestaurantPayment, …) |
| S4 | **Kiosk Borne** (Vue wizard client + paiement + offline) | 9.0 | `app/Http/Controllers/{Frontend/KioskEventController,Auth/KioskMachineLoginController,Admin/KioskMachineController}.php`, ⛔`Kiosk{Wizard,App,Upsell}Component.vue`, `Kiosk{Idle,Waiting,Confirmation,Payment,CashInstruction,Inactivity}*.vue` | `tests/Feature/Kiosk*`, `tests/Feature/KioskSecurity/*`, `KioskPhase1/5/7`, `KioskOfflinePaymentScopeTest`, `KioskLoyaltyDoubleRedeemRefusedTest` |
| S5 | **KDS Cuisine** (display + bump + recall + history + overflow) | 7.5 | `app/Http/Controllers/Admin/{KitchenDisplaySystemController,KdsSyncController}.php`, `app/Domain/Kds/KitchenReleaseRule.php`, `resources/js/components/admin/kitchenDisplaySystem/{KdsV2Grid,KdsStatusBanner,KdsHistoryDrawer,KitchenDisplaySystemComponent}.vue` | `tests/Feature/KDS/*`, `Kds{ChangeStatusConcurrency,ExpectedStatusConflict,TransitionWhitelist,PaginationOverflow,BranchFilterExact}Test`, `KitchenReleaseRuleTest` |
| S6 | **OSS Écran client** (waiting wall, read-only) | 9.0 | `app/Http/Controllers/Admin/OrderStatusScreenController.php`, `resources/js/components/admin/orderStatusScreen/{OrderStatusScreenComponent,PreparingAndReadyComponent}.vue` | `tests/Feature/OSS/*`, `OSSReadOnlyTest`, `tests/js/{ossSyncFallback,orderStatusScreenOssSync}.spec.js` |
| S7 | **Fiscal NF525 + Admin daily-ops** (Z-report + cash recon + audit chain) | 8.5 | ⛔`app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php`, ⛔ migrations audit_logs/z_reports triggers, `resources/js/components/admin/dashboard/AuditTrailComponent.vue`, cash-overview controllers | `tests/Feature/{Fiscal,Cash,Reconciliation}/*`, `OpsPreflightCaisseV1Test` |
| S8 | **Livreur + Stock cascade** (delivery cash sessions + rupture binary sync) | 8.0 | `app/Http/Controllers/{Admin/DeliveryBoyOrderController,Frontend/DeliveryBoyOrderController}.php`, `app/Listeners/{DecrementStockOnOrderCreated,ReleaseStockOnOrderCanceled,DecrementItemAvailabilityOnOrder,NotifyStockLowOnStockLevelChanged}.php`, `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` | `tests/Feature/{Delivery,Stock,Availability}/*` |

---

## §2 — Map separated (standalone — PAS de wireup V1)

| # | Système | Maturité | Mandat | Audit autorisé |
|---|---|:--:|---|---|
| M1 | **Mobile RN** (`mobile/`) | 7.0 | STANDALONE, 0 API wireup. Palette **noir/orange/jaune/blanc** (PAS rouge Cayenne). | Parité menu SSOT (0 produit inventé), polish V1-final. Read-only. |
| W1 | **Web standalone** (`/Users/1millnonstop/Downloads/web`) | 7.0 | STANDALONE, 0 API wireup. | Parité menu canonical, badges DÉMO, polish. Read-only. |

---

## §3 — Système S1 : Synchronisation cross-surface

### Contract
Toute mutation d'ordre/état/stock/catalogue se propage de façon fiable à TOUTES les surfaces concernées (KDS, OSS, POS, Admin) via **Outbox-first → broadcast Soketi → fallback polling**, sans fenêtre de divergence durable, sans double-effet (idempotent), sans perte (dead-letter escalation).

### Frozen zones pertinentes
Aucune directe (S1 est infra non-frozen) — sauf intersection NF525 (`AuditLogService` ⛔ via `OrderCreated`/`OrderStatusChanged` chain extend).

### Les 7 cascades canoniques (SSOT `public/architecture-diagram.html`, vérifiées)
1. **POS → KDS** : `PosController::storeOrder` → DB txn → `composition_snapshot` frozen → `OrderCreated` (after-commit) → `PersistOrderCreatedToOutbox` FIRST → `DecrementStockOnOrderCreated` → `DispatchDomainEventsJob` (Cache::lock + lockForUpdate) → Pusher OR polling fallback (cap 60s) → KDS Echo OR `KdsSyncService` 5s → `KdsSyncController::list`.
2. **Kiosk → KDS + OSS (NF525 at-creation)** : wizard → `Frontend/OrderController::store` (Sanctum `kiosk:order`) → `fiscal_sequence_no` AT CREATION (Cache::lock 5s + FOR UPDATE) → snapshot + `audit_logs` HMAC → outbox cascade → KDS source=KIOSK + OSS public-wall allowlist.
3. **Allocation fiscale** : `FiscalSequenceService::allocateForBranch` Cache::lock 5s → txn SELECT FOR UPDATE → next_seq = max+1 → UNIQUE(branch_id, fiscal_sequence_no) → `AuditLogService` chain → fail ⇒ `fiscal_alloc_error_at` + `RetryFiscalAllocCommand` cron.
4. **Hub → KDS** : outbox row → Pusher `kds-{branch}` → Echo → `KdsSyncService` cursor → re-render ; down ⇒ polling 5s ceiling 60s.
5. **Hub → OSS** : `OrderStatusChanged` outbox → Pusher `oss-{branch}` → filter `source_surface` allowlist `whereIn([KIOSK,TAKEAWAY])` **FAIL-CLOSED** → `CDSOrderDetailsResource` (6 champs PII-clean) → chime ONLY →READY debounced → wake-lock.
6. **KDS bump** : chef [A]/[B] → `KdsSyncController::changeStatus` + Idempotency-Key → `OrderStateMachine::apply` whitelist forward-only → lockForUpdate + expected_status optimistic conflict → `OrderStatusChanged` → `PersistOrderStatusChangedToOutbox` FIRST → broadcast OSS+POS → undo-toast 5s.
7. **Settings/Catalog** : change → `PersistSettingsUpdatedToOutbox` → broadcast `admin-{branch}` → reactive update + `Invalidate*KioskMenuCache`.
   + **Refund (5 sous-cascades)** : `RefundWithCounterEntry` → audit chain extend + `OrderPaymentStatusChanged` broadcast + loyalty refundPoints + stock release.

### Décomposition en sous-systèmes

#### Sub S1.1 — Outbox reliability & dead-letter
- **Anchors** : `DispatchDomainEventsJob.php`, `DomainEvent.php` (scopeStale 2min / scopeFailed ≥4 attempts), `EscalateOutboxBroadcastSwallowed.php`, `MonitorOutboxStaleness.php`, `OutboxRescueCommand.php`.
- **Tasks** :
  - T-S1.1.1 Vérifier backoff curve effective `tries=6` consomme bien `[1,5,15,60,300]` (dernier réutilisé) — outlast Soketi restart 1-3min.
  - T-S1.1.2 Dead-letter : `attempts ≥ 4` → `OutboxBroadcastSwallowedEvent` → `EscalateOutboxBroadcastSwallowed` alarme ; vérifier qu'aucune row ne reste « pending » silencieuse > seuil.
  - T-S1.1.3 Idempotence broadcast : un même `domain_event` rejoué (`dispatched_at` set) ne re-broadcast pas / ne double-décrémente pas stock.
- **Acceptance** : `tests/Feature/OutboxTest.php` + `OutboxRescueTest.php` PASS + `tests/js/realtimeBroadcastFallback.spec.js` PASS + repro adversariale queue-down → reconcile via cron (test TO BE CREATED at `tests/Feature/Outbox/DeadLetterEscalationE2ETest.php` si gap confirmé).

#### Sub S1.2 — Broadcast → polling fallback reconciliation (fenêtre stale-state)
- **Anchors** : `routes/channels.php`, `resources/js/components/admin/kitchenDisplaySystem/*.vue` (cadence setInterval), `resources/js/components/admin/orderStatusScreen/*.vue`, `resources/js/components/frontend/kiosk/Kiosk{Waiting,Confirmation}Component.vue`.
- **Tasks** :
  - T-S1.2.1 **QUESTION CLÉ** : existe-t-il une fenêtre où KDS/OSS/POS affichent un état d'ordre **périmé** quand Pusher swallow + avant le prochain poll ? Mesurer cadence réelle (ms) par surface + ceiling 60s.
  - T-S1.2.2 Channel auth : `kiosk-token` name-check (immune wildcard `*`), admin/Tenant-Admin role-check, staff own-branch (anti Guest-Echo-Bypass). Re-vérifier post-HEAD.
  - T-S1.2.3 Reconnexion storm : Soketi down→up, re-subscribe sans double-fetch ni doublon de carte.
- **Acceptance** : `tests/js/{posSyncFallback,kdsSyncCadence,ossSyncFallback}.spec.js` PASS + latence cross-surface mesurée LIVE (cible < 2s broadcast, < cadence polling fallback) + E2E `tests/e2e/zone6-sync-resilience.spec.js` GREEN.

#### Sub S1.3 — Cascades stock / catalogue / availability
- **Anchors** : `DecrementStockOnOrderCreated.php`, `ReleaseStockOnOrderCanceled.php`, `ReleaseStockOnRefundCreated.php`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`, `ItemAvailabilityChanged.php`, `StockRuptureDashboardComponent.vue`.
- **Tasks** :
  - T-S1.3.1 Rupture binaire (in/out) toggle Admin → POS + Kiosk reflètent (Echo + polling 60s). Cascade item↔option auto-rupture.
  - T-S1.3.2 Stock decrement/release idempotent sous concurrence + annulation/refund release correct.
- **Acceptance** : `tests/e2e/stock-rupture-sync.spec.js` GREEN + `tests/Feature/Stock/*` PASS + E2E LIVE (Wave 3 X-04).

---

## §4 — Système S2 : Prise de commande (lifecycle, 3 surfaces)

### Contract
Une commande — quels que soient ses produits, son type (À emporter / Livraison / sur-place désactivé V1), et sa surface d'entrée (BORNE / CAISSE / TÉLÉPHONE-standalone) — naît, calcule son prix **100 % backend (SSOT)**, fige son `composition_snapshot`, transite par des états **contrôlés forward-only**, et **sort** correctement (préparée → prête → livrée/retirée → encaissée), sans fraude prix, sans incohérence cross-surface.

### Frozen zones pertinentes
⛔`PricingService.php` (SSOT prix — F1/F2 owner-gate), ⛔`OrderStateMachine.php`, ⛔`pos-wizard.js` (A03-1 menu-role overcharge), ⛔`Kiosk{Wizard,Upsell}Component.vue`.

### Décomposition en sous-systèmes

#### Sub S2.1 — Lifecycle & state machine
- **Anchors** : `OrderStateMachine.php` (whitelist + lockForUpdate), `PaymentStateMachine.php`, `AutoPrepareOnPaidPolicy.php`, `KitchenReleaseRule.php`.
- **Tasks** :
  - T-S2.1.1 Transitions whitelist forward-only ; `apply()` lock-correct ; legacy callers non-racey (cf. historique P0-12).
  - T-S2.1.2 `AutoPrepareOnPaidPolicy` : payé → auto-préparation ; Plan B borne « en attente encaissement » → encaissé comptoir → bascule préparation (flow observé KDS).
  - T-S2.1.3 `queue_number` + `fiscal_sequence_no` uniques sous concurrence (interleaved kiosk+POS per branch, gap-free).
- **Acceptance** : `OrderStateMachineLockForUpdateTest`, `OrderStateTransitionTest`, `ConcurrentOrderTest`, `QueueNumberConcurrencyTest` PASS + E2E lifecycle (Wave 2).

#### Sub S2.2 — Pricing SSOT & composition immutability
- **Anchors** : ⛔`PricingService::calculateOrder`, `OrderItemCompositionSnapshotTest`.
- **Tasks** :
  - T-S2.2.1 Frontend envoie SEULEMENT `item_id, quantity, option_ids` ; prix recalculé backend ; `composition_snapshot` jamais réécrit post-création.
  - T-S2.2.2 **Statut courant F1** (`$calculatedDiscount` unclamped ~5 LOC) + **F2** (multi-rate tax-breakdown drift) — vérifier file:line à HEAD, **frozen-zone ⇒ owner gate G2** (LOCK + countersign, NE PAS éditer).
  - T-S2.2.3 **A03-1** : `pos-wizard.js` n'émet pas `role=menu_*` sur addons menu ⇒ overcharge 1.20-1.80€/commande POS-path. Frozen ⇒ owner gate G4 (LOCK pos-wizard.js).
- **Acceptance** : `PosPricingSsotProofTest`, `PosKioskPricingParityTest`, `OrderItemCompositionSnapshotTest` PASS + parité POS↔Kiosk prouvée. F1/F2/A03-1 = gates documentés (pas heal direct).

#### Sub S2.3 — Quote integrity (kiosk/web)
- **Anchors** : `KioskEventController.php`, `Frontend/OrderController.php`, HMAC quote.
- **Tasks** :
  - T-S2.3.1 Quote token required on commit ; `branch_id` non-forgeable (override silencieux) ; HMAC key required ; expiration.
  - T-S2.3.2 Replay idempotency ; tamper rejection.
- **Acceptance** : `KioskQuoteIntegrityTest`, `KioskQuoteTokenRequiredOnCommitTest`, `KioskQuoteForgesBranchIdSilentlyOverriddenTest`, `QuoteTamperTest`, `QuoteReplayIdempotencyTest` PASS.

---

## §5 — Systèmes S3-S8 (décomposition condensée)

> Densité haute — chaque tâche : anchor vérifié + test path (existant OU `TO BE CREATED`) + surface visuelle si frontend.

### S3 — POS Caisse
- **Sub S3.1 Commande (wizard ⛔)** : T-S3.1.1 composition flow 0 raw label · T-S3.1.2 profile mirror DB↔wizard (cf. fix profile 85 viande+crudite) · T-S3.1.3 add-to-cart edge (allergen modal, parked resume, quote binding). **Accept** : `tests/Feature/Pos/PosParkedRecallVariationAvailabilityTest` + `QuoteBindingTest` + E2E wizard 4 templates GREEN (Wave 2).
- **Sub S3.2 Encaissement & tiroir (⛔PaymentComponent)** : T-S3.2.1 4 scénarios (cash/card/split/ticket-resto) · T-S3.2.2 cash-trail drawer-open forensic · T-S3.2.3 **encaissement BORNE comptoir** (50 en file observées) Plan B. **Accept** : `PosCashEndpointSentinelTest`, `PosTicketRestaurantPaymentTest`, `SplitPaymentEndToEndTest`, `PosCollectKioskCashRouteTest` PASS.
- **Sub S3.3 Refund** : T-S3.3.1 **POS Refund UI button manquant** = owner gate G5 (~6h, `PosRefundModal.vue` + perm `pos-refund`) ; cashiers utilisent cancel-with-reason (NF525 reconciliation gap). **Accept** : `PosRefundUiPermissionSentinelTest` + proposal `proposals/PROPOSAL_POS_REFUND_UI_2026-05-25.md`.

### S4 — Kiosk Borne
- **Sub S4.1 Provisioning machine** : T-S4.1.1 `KioskMachineLoginController` mint `kiosk-token` (name, ability `kiosk:order` only, TTL) ; **BASELINE-OBS** : idle screen pré-auth 401 sur menu/kiosk-event → vérifier qu'une borne provisionnée résout 200. **Accept** : `KioskLoginApiTest`, `KioskAuthTest` PASS + idle screen 0 console error post-provision (Wave 2/4).
- **Sub S4.2 Wizard commande (⛔)** : T-S4.2.1 5 templates (sandwich/tacos/bol/burger/menu) composition · T-S4.2.2 upsell · T-S4.2.3 8-sauces composition_snapshot HARD FAIL guard. **Accept** : `KioskFrontendComprehensiveTest`, `KioskUpsellCategoryTest` PASS + E2E wizard GREEN.
- **Sub S4.3 Paiement & sortie** : T-S4.3.1 Plan B route-to-counter (cash instruction screen) · T-S4.3.2 idle/inactivity timer safety-net · T-S4.3.3 offline payment scope. **Accept** : `KioskOfflinePaymentScopeTest`, `KioskPaymentStateMachineTest` PASS.

### S5 — KDS Cuisine
- **Sub S5.1 Display & transitions** : T-S5.1.1 bump whitelist + concurrency (multi-station optimistic conflict) · T-S5.1.2 recall/undo-toast 5s · T-S5.1.3 branch filter exact. **Accept** : `KdsChangeStatusConcurrencyTest`, `KdsTransitionWhitelistTest`, `KdsExpectedStatusConflictTest` PASS.
- **Sub S5.2 Overflow chef-rush** : T-S5.2.1 **owner gate G3** layout Option A/B/C ≥6 orders (+N chip = safety-net actuel, PAS redesign) · T-S5.2.2 pagination overflow. **Accept** : `KdsPaginationOverflowTest` PASS + proposal layout.
- **Sub S5.3 Multi-screen bump-sync** : T-S5.3.1 **KNOWN** : pastilles « Prêt » mémorisées navigateur (banner LOCAL honnête) — non-bloquant V1 mono-poste ; documenter pour V1.0.X. T-S5.3.2 **KDS undo/archive** owner gate (MASTER-GAP-002, proposal `PROPOSAL_KDS_ARCHIVE_UNDO_2026-05-25.md`).

### S6 — OSS Écran client
- **Sub S6.1 Read-only & ordering** : T-S6.1.1 read-only enforcement (`OSSReadOnlyTest`) · T-S6.1.2 FIFO deterministic (queue_number+id) · T-S6.1.3 allowlist fail-closed `whereIn([KIOSK,TAKEAWAY])`. **Accept** : `OSSReadOnlyTest` + `tests/js/orderStatusScreenOssSync.spec.js` PASS.
- **Sub S6.2 Resilience** : T-S6.2.1 chime debounced →READY · T-S6.2.2 wakeLock + visibilitychange · T-S6.2.3 stale prune 8h. **Accept** : `tests/js/ossSyncFallback.spec.js` PASS + visual wall GREEN (Wave 4).

### S7 — Fiscal NF525 + Admin daily-ops
- **Sub S7.1 Chain integrity (⛔)** : T-S7.1.1 `audit_logs` + `z_reports` HMAC chain CHAIN OK (append-only, jamais delete/truncate) · T-S7.1.2 DELETE trigger MySQL. **Accept** : `php artisan fiscal:verify-chain --all` CHAIN OK + `tests/Feature/Fiscal/*` PASS.
- **Sub S7.2 Z-report close** : T-S7.2.1 **Z-close UI button** owner gate G6 (cron safety-net mitige dead-zone) · T-S7.2.2 Z-loop Path B `business_date` SSOT (frozen `ZReportService` ⇒ owner gate G7 LOCK_FISCAL_BUSINESS_DATE) · T-S7.2.3 PDF clôture + chain extend. **Accept** : Z close E2E LIVE (Wave 5) + `RolloutCanaryDrillTest`.
- **Sub S7.3 Cash reconciliation** : T-S7.3.1 Σ by_mode == Σ by_source (cash+card+banque = total encaissé) · T-S7.3.2 audit-trail widget lit `audit_logs` (pas ActionLog) · T-S7.3.3 **observability widgets** (NF525 chain status + backup status) owner gate G8 (~5-6h). **Accept** : `tests/Feature/Reconciliation/*` + `tests/Feature/Cash/*` PASS + cash-overview numerics balanced (Wave 5).

### S8 — Livreur + Stock
- **Sub S8.1 Cash sessions livreur** : T-S8.1.1 open/close session + reconcile · T-S8.1.2 DELIVERED hook recordMovement. **Accept** : `tests/Feature/Delivery/*` PASS.
- **Sub S8.2 Stock cascade** : voir S1.3 (cross-ref).

---

## §A — Agent Army Map + Fan-Out Matrix

### Rôles (GStack + Superpowers + adversaires)
| Rôle | Subagent type | Tools | Mode |
|---|---|---|---|
| Architect | `general-purpose` | Read-only | patterns / layers / contracts |
| Security+Race | `general-purpose` | Read-only | authz / BranchScope / idempotency / TOCTOU / locks |
| SRE-Sync | `general-purpose` | Read-only | Outbox / broadcast↔fallback / dead-letter / cadence / stale-state |
| DBA | `general-purpose` | Read-only | schema / FK / N+1 / txn / snapshot immutability |
| Implementer | `general-purpose` | Edit+Write+Bash | TDD-first, scope-minimal, **jamais 2 implementers parallèles** |
| RED-team | `general-purpose` | Read-only | hostile : casser sync/order sous failure/concurrence ; disputer GREEN |
| QA Visual | `general-purpose` | Playwright | spec + capture + analyse screenshot |
| RED Visual | `general-purpose` | Read | re-analyse screenshots QA indépendamment, dispute |

### Fan-Out Matrix (par type de tâche)
| Type | Arch | Sec | SRE | DBA | Impl | RED | QA-Vis | RED-Vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Sync cascade | x | x | x | x | x | x | . | . |
| Order lifecycle / pricing | x | x | . | x | x | x | . | . |
| Frontend visual (surface) | x | x | . | . | x | x | x | x |
| Fiscal NF525-adjacent | x | x | . | x | x | x | . | . |
| Cross-surface E2E | x | x | x | x | x | x | x | x |
| Data alignment (seeder) | . | . | . | x | x | x | . | . |

### Dispatch discipline
- 5 spécialistes read-only = **single message multi-Agent** (parallèle).
- Implementer **jamais** parallèle avec un autre implementer (write conflict).
- QA-Visual + RED-Visual = parallèle OK (read-only screenshots).
- RED-team dispute **TOUJOURS** après commit implementer, **AVANT** DONE.
- Chaque sous-agent persiste findings disque (schéma `[P0|P1|P2|P3] file:line — titre / repro / evidence / reco`), main thread synthétise depuis disque (survit interrupt).

---

## §X — Convergence Waves

> Défaut : **séquentiel par wave**, parallèle **dans** la phase audit de la wave. Checkpoint 6-points + interrupt-resume à chaque fin de wave.

### Wave 1 — Pre-flight ✅ (en cours / quasi-clos)
- **Scope** : env up + baselines + NF525/frozen baseline + backup.
- **Fait** : dev server 200, 5 surfaces baseline GREEN (kiosk/login/POS/KDS/OSS), NF525 CHAIN OK, bundles 2026-05-28, DB 1 branch/59 items/191 orders, workflow audit running.
- **Reste** : créer `backup/pre-goal-sync-ordertaking-2026-05-29` + DB dump + frozen-zone SHA256 snapshot.
- **Checkpoint** : baselines Read+analysés ✓.

### Wave 2 — Prise de commande LIVE × 3 surfaces (cœur mission)
- **Scope** : driver une commande réelle bout-en-bout BORNE / CAISSE / TÉLÉPHONE, produits variés, jusqu'à sortie selon type. Visual capture + analyse à chaque étape (place du client). GStack+RED par surface.
- **Tasks** : S2.1, S2.2 (vérif, gates), S2.3, S3.1, S4.1-4.3.
- **Parallélisme** : POS / Kiosk audits disjoints possibles ; lifecycle backend séquentiel.
- **Checkpoint** : commande créée → KDS → OSS → encaissée comptoir, screenshots GREEN, console clean post-auth, fiscal_seq gap-free.

### Wave 3 — Sync cascade adversarial (focus owner)
- **Scope** : 7 cascades + dead-letter + stale-state windows + latence LIVE. RED hostile : queue-down, broadcast-swallow, reconnect storm, concurrence multi-station.
- **Tasks** : S1.1, S1.2, S1.3.
- **Checkpoint** : latence mesurée < cible, 0 fenêtre stale durable, idempotence prouvée, dead-letter escalade.

### Wave 4 — Visual E2E par surface (place du client)
- **Scope** : Playwright captures de CHAQUE surface + états (idle/wizard/payment/empty/error), Read+analyse chaque screenshot (layout / raw label / branding / i18n / a11y). Heal → re-capture → valide.
- **Tasks** : S4.2, S5.1, S6.1-6.2 visual + admin/livreur.
- **Checkpoint** : 0 raw label, 0 layout break, 0 console error, branding intact, dual QA-Vis + RED-Vis concordants.

### Wave 5 — Fiscal + cash + Z + refund + paiement Plan B
- **Scope** : Z close + PDF + chain extend LIVE ; cash recon Σ balanced ; refund 5-sous-cascade ; encaissement comptoir CB via app TTP. Heal non-frozen ; surface gates fiscaux frozen.
- **Tasks** : S7.1, S7.2 (gates), S7.3, S3.3 (gate), S8.1.
- **Checkpoint** : chain CHAIN OK append-only, recon balanced, refund cascade complète.

### Wave 6 — Convergence finale + GO/NO-GO + owner gates (mood adversarial max)
- **Scope** : full smoke (PHPUnit+Vitest+Playwright) ; cross-surface E2E complet (Kiosk→KDS→OSS→Livreur + POS→KDS→OSS) ; frozen-zone diff = 0 ; NF525 attestation ; **2 cycles identiques P0+P1=0**. Puis **RUN HOSTILE FINAL** (RED « bad mood ») cherchant tout défaut résiduel — maximum reasoning.
- **Checkpoint** : convergence rule satisfaite 2× ; owner gates consolidés WHO/WHAT/WHERE ; verdict GO. **Pas d'auto-push.**

### Wave-checkpoint protocol (6-points, fin de chaque wave)
1. Toutes tâches PASS ou baseline-known documenté · 2. Frozen-zone diff = 0 LOC · 3. NF525 chain CHAIN OK/append-only · 4. Visual gate firé (screenshots Read+analysés) · 5. RED dispute fait, P0/P1 healed ou deferred-with-reason · 6. BRAIN §2/§3 + commit checkpoint (`checkpoint-commit` skill, prefix `wip(<wave>)` si partiel).

### Wave-interrupt protocol (usage/limit)
Commit WIP → `reports/test-e2e/goal-sync-ordertaking-2026-05-29/INTERRUPT_<wave>_<ts>.md` (last green SHA + task status + next + sub-agent reports) → BRAIN §2 → reprise : read manifest + git status + smoke last task.

### Convergence-failure protocol (3 heal loops)
3e échec même cluster → STOP → spawn `Plan` subagent (root-cause + pivot/escalate) → `STUCK_<wave>_<ts>.md` → surface owner A/B/C/D, pas d'auto-pick.

---

## §G — Owner Gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Backup pré-heal | Claude | branche backup + DB dump | `backup/pre-goal-...-2026-05-29` | Wave 1 |
| G2 | PricingService F1 (discount unclamped) + F2 (multi-rate tax) | Physical owner | LOCK countersign + clarif single-rate V1 | `plans/LOCK_PRICING_*` §10 | PENDING |
| G3 | KDS chef-rush layout Option A/B/C ≥6 orders | Physical owner | choix A/B/C | proposal KDS layout | PENDING |
| G4 | A03-1 pos-wizard menu-role overcharge (⛔) | Physical owner | LOCK pos-wizard.js countersign | `LOCK_POS_WIZARD_*` + XSS LOCK ADDENDUM | PENDING (10+j) |
| G5 | POS Refund UI button (~6h) | Physical owner | approve `PosRefundModal.vue` + perm | `PROPOSAL_POS_REFUND_UI_2026-05-25.md` | PENDING |
| G6 | Z-close UI button | Physical owner | approve UI scope | proposal Z-close | PENDING (cron mitige) |
| G7 | Z-loop Path B business_date SSOT (⛔ZReportService) | Physical owner | LOCK_FISCAL_BUSINESS_DATE countersign | `PROPOSAL_Z_LOOP_GAP_2026-05-25.md` | PENDING V1.0.X |
| G8 | Observability widgets (NF525 chain + backup status, ~5-6h) | Physical owner | approve widgets | proposal observability | PENDING |
| G9 | D3 LOCK_PAY PaymentComponent currency (7 LOC ⛔) | Physical owner | countersign §10 | `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` | PENDING |
| G10 | Paiement acquéreur CB (app TTP intérim + TPE physiques) | Physical owner | contrat acquéreur + provider TTP choisi | BRAIN §2 / config | PENDING (matériel) |
| G11 | Marche physique 60-90 min (imprimante + flip `.env` + walk) | Physical owner | checklist signée | `OWNER_PHYSICAL_WALK_CHECKLIST.md` | PENDING |

### Owner-Gate-Waiting Protocol
Pendant qu'un gate est PENDING : Claude **n'exécute PAS** la tâche frozen dépendante ; **exécute** tout le reste (validation E2E, heals non-frozen, tests) en parallèle ; liste dans BRAIN §2 les waves bloquées vs runnable. **Aucun gate frozen ne bloque la validation E2E V1** — il bloque seulement le heal du code frozen. La quasi-totalité des gates sont NON-BLOCKING pour « V1 fonctionne » (workarounds en place : cancel-with-reason, cron Z, +N chip, encaissement comptoir).

---

## §R — Références
- `CLAUDE.md` §§4-13 · `PROJECT_BRAIN.md` §1/§2/§6/§7 (49 domaines GREEN) · `public/architecture-diagram.html` (7 flux SSOT)
- `memory/reference_frozen_zones.md` (13 frozen) · `memory/feedback_adversarial_audit_pattern.md` · `memory/feedback_no_cloud_until_owner_initiates.md` · `memory/feedback_v1_personal_le_cayenne_2026-05-28.md`
- Skills : `ultra-audit-profond` (pipeline/tâche) · `test-e2e` (convergence) · `lock-plan` (frozen override) · `checkpoint-commit` · `verify-before-report`
- Proposals : `proposals/PROPOSAL_{POS_REFUND_UI,KDS_ARCHIVE_UNDO,Z_LOOP_GAP}_2026-05-25.md`
- Findings audit : `reports/audit/goal-v1-sync-ordertaking-2026-05-29/<system>-verified.json` (intégrés §X)

---

## §F — Final Rule (DONE criteria)

V1 LOCAL Le Cayenne est **production-final** SEULEMENT quand :
1. La commande traverse correctement les 3 surfaces d'entrée (BORNE/CAISSE/TÉLÉPHONE) × types (À emporter/Livraison) × produits variés, **prouvé E2E LIVE visuel + technique**, place du client.
2. Les 7 cascades sync sont vérifiées sans fenêtre stale durable, idempotentes, dead-letter escaladé, latence mesurée.
3. Convergence : **2 cycles consécutifs P0+P1=0, ensembles identiques**, par système ET intersections.
4. Frozen-zone diff = 0 LOC · NF525 chain CHAIN OK append-only · 0 console error post-auth · 0 raw label · 0 layout break.
5. RUN HOSTILE FINAL (mood adversarial max) ne trouve **aucun** défaut résiduel non-documenté.
6. Owner gates frozen + physiques consolidés (WHO/WHAT/WHERE) — NON-BLOCKING pour « V1 fonctionne », explicitement listés pour countersign.
7. **Aucun auto-push.** Verdict GO surfacé à l'owner avec evidence.

**Production-perfect ou block. Jamais « almost there ». Le retour à l'owner = projet prêt : réaligner DB → brancher imprimante → encaisser au comptoir.**
