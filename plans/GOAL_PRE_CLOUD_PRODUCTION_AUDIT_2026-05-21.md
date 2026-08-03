# GOAL — Pre-Cloud Production Audit V1 Le Cayenne

**Slug** : `pre-cloud-audit-2026-05-21`
**Mission** : audit read-only multi-système pour décider si V1 LOCAL peut passer en cloud + matériel réel + finalisation fiscale. Cycle convergent Wave X (2026-05-21) déjà GREEN local. Ce GOAL valide la **STRUCTURE GLOBALE et la SYNCHRONISATION cross-système** avant cutover.
**Branche** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `4255ec15a`
**Date** : 2026-05-21
**Orchestrator** : Claude (skill `ultra-architect-planify`, advisor consulté)
**Pipeline per task** : skill `ultra-audit-profond` (référence unique, ne pas re-décrire)
**Composition** : `superpower-gstack` + `test-e2e` + `dispatching-parallel-agents`
**Verdict contract** : audit produit par-système → `VERIFIED` | `NEEDS-HEAL` | `BLOCKER`, agrégé en `GO-CLOUD` | `GO-CLOUD-WITH-OWNER-GATES` | `NO-GO`.

---

## §0 — Preamble

### §0.1 — Working-tree decision (Axis 8 mandatory)

État au lancement (HEAD `4255ec15a`) :
- **Bundles auto-rebuilt** (webpack/mix) : `public/js/{admin-oss,kiosk-errors,kiosk-shell,kiosk-wizard,kiosk-wizard-step,vendor}.js` + `mix-manifest.json` — pure artifacts, low-risk.
- **Reports churn** : ~30 fichiers `reports/test-e2e/**/*.{png,json,html,md,csv}` — historique, low-risk.
- **Real source mods** (9 fichiers, `+283 -205` LOC) : `config/{kds,menu,menu_images}.php`, `database/seeders/{Mail,Page,Site}TableSeeder.php`, `lang/fr/installer.php`, `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`, `resources/views/master.blade.php`, `tests/Feature/{KDS/KdsSyncTzAwareTest,Outbox/OutboxReplayAuditTest}.php`.
- **Untracked** : 14 `tests/e2e/*.spec.js` (Wave T/P/X capture specs).

**Décision §0.1** :
1. **Avant W0** : `git add -A && git commit -m "chore(pre-audit): bundles+reports baseline + uncommitted source/test mods"`. Owner OK silencieux requis (P-OWNER baseline) — ces mods sont des évidences de cycles passés (Wave X→Y), pas du WIP.
2. **Si owner refuse commit** : `git stash --include-untracked` avant W0, restore après W5. Avertit : checkpoints `git diff` seront contaminés par le stash flap.
3. **Recommandation Claude** : option 1. Working-tree clean = checkpoints lisibles.

### §0.2 — Scope boundaries (durcis sur recommandation advisor)

| Catégorie | Statut | Détail |
|---|---|---|
| **IN SCOPE** | Audit V1 LOCAL invariants pre-cloud | Read-only verification de la structure + synchronisation. **AUCUN code change** durant cet audit. Convergence = 2 cycles consécutifs `P0+P1=0` avec findings sets identiques (skill `test-e2e` rule). |
| **OUT OF SCOPE (Next GOAL)** | Cloud cutover lui-même | RDS migration, Redis driver swap, ALB/CloudFront, WAF, Secrets vault, hardware branchement, finalisation données fiscales. Trouvailles infra cloud capturées dans §G owner gates + W6 handoff doc (input pour next GOAL). |
| **EXPLICITLY EXCLUDED** | V1.0.X deferred backlog | Multi-tranche split (X1), KDS recall PREPARED→PREPARING (X3), Z5 P1-C/D/E frozen LOCK V1.0.2, FormRequest chip-away 69 baseline, Z6 P1 17 sites cleanup, Z8 P0-4/P1-1 V1, DEL-5/6/7/8/9, Sanctum TTL 480→60min sensitive. Documenté `PROJECT_BRAIN.md §1` + handoff. |

### §0.3 — Convergence criteria (Axis 6 literal) + Per-Sub verdict contract

Audit converge sur **TOUS** les critères suivants :
1. Foundation invariants (W1) tous `VERIFIED` (5/5)
2. Per-surface audit (W2) toutes systèmes `VERIFIED` ou `NEEDS-HEAL ≤ 2 P1` (5/5)
3. Intersections (W3) 7/7 E2E GREEN avec captures visuelles analysées
4. Sync spine (W4) Wave L heals B.1-B.4 re-attestés sous stress simulé
5. Frozen-zone diff = 0 lignes sur 15 fichiers §7 entre `4255ec15a..HEAD` final
6. NF525 chain APPENDED-ONLY ou bit-identical (`count` + `last_hash`)
7. **Deux cycles consécutifs** rejouent les mêmes findings (flake-guard rule)

**Per-Sub verdict contract (applies to all §2-§10 Subs by default)** : Sub = `VERIFIED` ssi (a) tous tests cités PASS, (b) frozen-zone diff = 0 sur §7 files mentionnés, (c) acceptance ligne PASS. Sinon `NEEDS-HEAL` avec P0/P1 findings → routed to §G G-HEAL. `BLOCKER` si invariant fiscal/sécurité critique violé. Les sections §2-§10 omettent "**VERDICT**" et "**Acceptance**" verbose : appliquer ce contrat à chaque Sub.

### §0.4 — Per-task pipeline (référence unique, ne pas re-décrire)

Chaque task de §2-§10 délègue à `ultra-audit-profond` (`~/.claude/skills/ultra-audit-profond/SKILL.md`) — 14-step pipeline avec 5 specialists parallèle (Architect+Security+UX+DBA+SRE) + Implementer + RED-team dispute + QA Visual + RED Visual. **Important** : cet audit étant read-only, le rôle Implementer est désactivé sauf si Wave W5 détecte `NEEDS-HEAL` et owner autorise heal-light scope-minimal.

### §0.5 — Anchors cartography (verified pré-write)

12 cartographers dispatchés en parallèle (read-only, single message dispatch) — résultats persistés `reports/audit/goal-pre-cloud-2026-05-21/anchors/` :
- 5 reports persisted disk (`01-pos.md`, `02-kiosk.md`, `04-sync.md`, `05-fiscal-nf525.md`, `11-idempotency-sentinels.md`)
- 7 reports dans agent return summaries (`03-kds-oss`, `06-multitenant-auth`, `07-stock-pricing`, `08-livreur`, `09-admin`, `10-standalones`, `12-intersections`) — Explore agent read-only limitation, contenu capturé dans transcript W0 doit re-persister via Bash heredoc avant lancement audit.

---

## §1 — Map principal — 8 systèmes + 2 standalones

| # | Système | Maturité | Anchor primaire | Tests existants | Frozen §7 overlap |
|---|---------|----------|-----------------|----------------|-------------------|
| 1 | **Foundation (Couche 0)** | LIVE | `app/Services/{Fiscal/*,Pricing/PricingService.php}` + `app/Models/Scopes/BranchScope.php` + `app/Http/Middleware/IdempotencyKeyMiddleware.php` | 188 + 91 sentinels (NF525+BranchScope+Idempotency+Sentinels+Pricing) | YES — 7 files §7 |
| 2 | **POS (Caisse)** | LIVE | `app/Http/Controllers/Admin/Pos*Controller.php` (8 files, 1132 LOC) + `app/Services/Payment*` (1842 LOC) + `public/js/pos-wizard.js` (5964 LOC §7) | 62 PHPUnit + 28 Vitest | YES — 5 files §7 |
| 3 | **Kiosk (Borne)** | LIVE | `resources/js/components/frontend/kiosk/Kiosk{Wizard,App,Upsell}Component.vue` (5223 LOC §7) + Sanctum `kiosk:order` (18 enforcements) | 45 Feature + 87 JS specs | YES — 3 files §7 |
| 4 | **KDS + OSS** | LIVE (X3 NEW) | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` + `KdsHistoryDrawer.vue` (Wave X3 read-only) + `CDSOrderDetailsResource` | 25 KDS Feature + 9 OSS Feature + 1 E2E | YES — `OrderStateMachine.php` §7 (recall V1.0.2 LOCK) |
| 5 | **Admin Dashboard** | LIVE (X4 NEW) | `app/Http/Controllers/Admin/CashOverviewController.php` (Wave X4) + `StockRuptureDashboardController.php` + `ZReportController.php` + `PermissionController.php` | 8 Feature + 7 E2E + 2 JS | NO direct (middleware-enforced) |
| 6 | **Livreur** | LIVE (2 NEW migrations 2026-05-18) | `DeliveryBoyCashSessionController.php` + `DeliveryBoyCashSession` + `DeliveryBoyCashMovement` models | 10 files, 34 methods | NO (NF525-adjacent cash trail) |
| 7 | **Sync spine** | LIVE (Wave L heals shipped) | `OutboxRetryFailedCommand` + `OutboxRescueCommand` + `OutboxBroadcastSwallowed` listener + Pusher channel-auth `routes/channels.php` | 60 sentinels (11 files, 2635 LOC) | YES — `IdempotencyKeyMiddleware.php` §7 |
| 8 | **Cross-system Intersections** | LIVE | 7 intersections — POS×{KDS,OSS}, Kiosk×{KDS,OSS}, Stock cascade, Refund cascade, Loyalty earn+redeem | 17 cross-system tests + 13 Outbox + 3 Loyalty + 2 Refund | YES — NF525 seal §7 + DispatchableAfterCommit §7 |

### §1bis — Map separated (standalones — out of cloud-cutover scope unless owner reverses)

| # | Système | Location | Wireup central | Audit scope |
|---|---------|----------|----------------|-------------|
| S1 | **Mobile app** | `/mobile/` IN-REPO, feature branch `feature/mobile-app-le-cayenne-2026-05-10` | **0 API wireup** (V0 mock localStorage). 229 E2E. 12/12 wizard parity GREEN. | **SEP-1 single verification** (W6) — separation-discipline holds, no central API touches re-confirmed |
| S2 | **Web démo** | `/Users/1millnonstop/Downloads/web/` EXTERNAL DIR | **0 API wireup** (V0 mock). Data SSOT mirror `data/menu.js` 13 cats/60 items aligned with mobile. | **SEP-2 single verification** (W6) — separation-discipline holds, data parity re-confirmed |

---

## §2 — Système 1 : Foundation Couche 0 (sequential audit — spine)

### Contract
Invariants non-négociables : NF525 fiscal chain, BranchScope multi-tenant, Idempotency duplicate-protection, Pricing SSOT, Sentinel CI baseline-locks. Si l'un dérive → cloud cutover BLOCKED.

### Frozen zones
`PricingService.php` §7, `FiscalSequenceService.php` §7, `ZReportService.php` §7, `AuditLogService.php` §7, `BranchScope.php` §7, `IdempotencyKeyMiddleware.php` §7, `OrderStateMachine.php` §7.

### Anchors (verified — full detail §0.5 + reports/audit/goal-pre-cloud-2026-05-21/anchors/)
- NF525 : 3 services (115+375+727 LOC) + 171 tests + 12 sentinels + `FiscalVerifyChainCommand` (285 LOC) + `LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` active
- BranchScope : 20 models locked + 11 exempted via `BranchScopeCoverageSentinelTest.php`
- Idempotency : 23 routes, middleware 244 LOC, `IdempotencyRequiredRoutesCoverageTest.php`
- Pricing SSOT : `PricingService.php` 814 LOC + 14 callsites + 109 tests
- Sentinels totaux : 91 baseline-locked (13 BranchScope + 5 Authz + 8 Fiscal + 7 Outbox + 6 Idempotency + 52 autres)

### Décomposition

#### Sub 2.1 — NF525 Fiscal chain triple-verify
**Anchors** : `app/Services/Fiscal/*.php` (§7) + `php artisan fiscal:verify-chain --all` + migrations `audit_logs` + `z_reports` BEFORE DELETE triggers
**Tasks** :
- T-2.1.1 Re-attest `fiscal:verify-chain` exit code 0 (CHAIN OK) + capture `count` + `last_hash` → `reports/audit/goal-pre-cloud-2026-05-21/findings/W1-fiscal-baseline.txt`
  - test: `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php` (exists)
- T-2.1.2 Vérifier triggers `audit_logs` + `z_reports` ANTI-DELETE actifs (MySQL `SHOW TRIGGERS LIKE 'audit_logs%'`)
  - test: `tests/Feature/Fiscal/AuditLogImmutabilityTest.php`
- T-2.1.3 Vérifier production boot guard `AppServiceProvider:78-145` refuse `APP_DEBUG=true` + `POS_SIMULATION_HARDWARE=true` + `IDEMPOTENCY_MIDDLEWARE_ENABLED=false`
  - test: `tests/Feature/FoundationBootGuardsTest.php` (12 tests per BRAIN, verify exists)
- T-2.1.4 Confirmer LOCK_FISCAL_WGS_Z6_P1 owner countersign présente (`plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` §10)
- T-2.1.5 6y retention path : verify storage strategy doc OR flag for §G owner gate


#### Sub 2.2 — BranchScope multi-tenant coverage
**Anchors** : `app/Models/Scopes/BranchScope.php` (42 LOC §7) + `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` (baseline 20 models + 11 exempted)
**Tasks** :
- T-2.2.1 Sentinel re-run baseline-lock — `phpunit --filter BranchScopeCoverageSentinelTest`
  - test: `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`
- T-2.2.2 Vérifier 11 exemptions documentées sont intentionnelles (`EXEMPTED_MODELS` array) — Branch, Customer + 9 V1 single-tenant
  - test: (test TO BE CREATED at `tests/Feature/Branch/BranchScopeExemptionRationaleTest.php`) — verify each EXEMPTED_MODELS entry has a comment with rationale
- T-2.2.3 Vérifier `WizardProfileBranchScope` variant nullable comportement
  - test: `tests/Feature/Branch/WizardProfileBranchScopeTest.php`
- T-2.2.4 Re-vérifier IDOR sentinels — Admin S-1 MyOrderDetailsController (heal Wave 13-zone)
  - test: `tests/Feature/Admin/MyOrderDetailsIdorSentinelTest.php`


#### Sub 2.3 — Idempotency duplicate-protection
**Anchors** : `IdempotencyKeyMiddleware.php` (244 LOC §7) + `IdempotencyRequiredRoutesCoverageTest.php` + 23 routes config
**Tasks** :
- T-2.3.1 Sentinel re-run — `phpunit --filter IdempotencyRequiredRoutesCoverageTest`
  - test: `tests/Feature/Sentinels/IdempotencyRequiredRoutesCoverageTest.php`
- T-2.3.2 Vérifier scope `(branch_id, user_id, hash(key))` end-to-end POST 2× same key + diff payload = 409
  - test: `tests/Feature/Middleware/IdempotencyKeyMiddlewareTest.php`
- T-2.3.3 `webhook_events` UNIQUE constraint actif `(provider, webhook_id)` + Stripe + SenangPay handlers
  - test: `tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php` (6 tests per BRAIN)
- T-2.3.4 Wave Y rate-limit env-knob `ADMIN_MUTATION_RATE_LIMIT` + dynamic Retry-After surface
  - test: (test TO BE CREATED at `tests/Feature/RateLimit/AdminMutationRateLimitDynamicTest.php`) if not exists, OR re-run existing


#### Sub 2.4 — Pricing SSOT 14 callsites
**Anchors** : `PricingService.php` (814 LOC §7) + 14 verified callsites (FrontendOrderService, OrderService, OrderQuoteService, PricingPreviewService, ZReportService, 5 test files, 2 HTTP request classes)
**Tasks** :
- T-2.4.1 Re-attest all 14 callsites `grep -rn "PricingService" app/` — match cartography snapshot
  - test: (test TO BE CREATED at `tests/Feature/Sentinels/PricingServiceCallsiteCoverageSentinelTest.php`) — baseline-lock 14 callsites, GROWS or SHRINKS = fail
- T-2.4.2 Confirmer `composition_snapshot` frozen-at-creation invariant (kiosk + POS + frontend)
  - test: `tests/Feature/Order/CompositionSnapshotImmutabilityTest.php`
- T-2.4.3 Pricing 109 tests baseline GREEN — `phpunit tests/Feature/Pricing/`
- T-2.4.4 Vérifier zero bypass — no order origin (Kiosk/POS/Frontend/Mobile) écrit `total` directement
  - test: `tests/Feature/Pricing/NoClientTotalsBypassSentinelTest.php`


#### Sub 2.5 — Sentinel 91-baseline lock
**Anchors** : 91 baseline-locked sentinels across `tests/Feature/Sentinels/*` + `tests/Feature/Branch/*` + `tests/Feature/Fiscal/*` + `tests/Feature/Outbox/*` + `tests/Feature/Sentinels/Idempotency*`
**Tasks** :
- T-2.5.1 Run all 91 sentinels — `phpunit --filter Sentinel` baseline count + GREEN
- T-2.5.2 FormRequest authz drift sentinel — `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` baseline 69 unchanged
- T-2.5.3 Aggregate report → `reports/audit/goal-pre-cloud-2026-05-21/findings/W1-sentinel-baseline.txt`


---

## §3 — Système 2 : POS (Caisse)

### Contract
Caissier fast-food : commande + paiement + tiroir + Z-reports NF525. 4 sub-systèmes audit-only.

### Frozen zones
`public/js/pos-wizard.js` (§7), `public/css/pos-wizard.css` (§7), `resources/views/admin-pos-v4.blade.php` (§7), `resources/js/components/admin/pos/PaymentComponent.vue` (§7), `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` (§7).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 3.1 — POS Wizard + Payment + Cash drawer
**Anchors** : `pos-wizard.js` (FROZEN), `PaymentComponent.vue` (FROZEN), `PaymentService.php`, `CashDrawerService.php`, `SplitPaymentService.php`
**Tasks** :
- T-3.1.1 Audit composition_snapshot end-to-end POS flow (post Wave X X1 counter-collect)
  - test: `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php`
- T-3.1.2 Cash drawer pop forensic + reconciliation cohérence
  - test: `tests/Feature/Pos/PosCashTrailTest.php` (6 tests verified existing BRAIN)
- T-3.1.3 Split payment 4 scenarios (cash+card / card+ticket / cash+ticket / triple)
  - test: `tests/Feature/Pos/SplitPaymentEndToEndTest.php` (6 tests verified BRAIN)
- T-3.1.4 Quote binding integrity (parked → resume → POS)
  - test: `tests/Feature/Pos/QuoteBindingTest.php`
- T-3.1.5 Visual : POS main page + wizard popup + counter-collect modal × 3 modes
  - visual: `http://127.0.0.1:8000/admin/pos` + capture quartet recorder


#### Sub 3.2 — POS X1 + X2 Wave X NEW (verify shipped state holds)
**Anchors** : `PosCounterCollectModal.vue` (699 LOC), `PosShortcutOrderController.php`, `PosShortcutOrderResource.php`, route `POST /admin/pos/counter-collect/{id}/confirm`
**Tasks** :
- T-3.2.1 Counter-collect modal X1 — sibling pattern réutilise V5 atoms, 4-mode picker, X-Idempotency-Key minute-bucket
  - test: `tests/e2e/wave-x-pos-x1-counter-collect.spec.js` (verify exists in tests/e2e/)
- T-3.2.2 Main-page shortcuts X2 — Prêt à livrer + À encaisser borne panels max 4 lignes + Voir plus
  - test: `tests/e2e/wave-x-pos-x2-shortcuts.spec.js`
- T-3.2.3 Echo real-time wired (OrderCreated/StatusChanged/PaidAtCounter)
  - test: (verify in `tests/Feature/Pos/PosEchoSubscribeTest.php` OR test TO BE CREATED)


#### Sub 3.3 — POS NF525 Receipts + Z close
**Anchors** : `ReceiptDataService.php` (SSOT wire-in, BroadcastableOrder interface post F1+F2+F3 heal commit `d3dc4c2c6`)
**Tasks** :
- T-3.3.1 Receipt parity Order + FrontendOrder (kiosk paid via POS reprint)
  - test: `tests/Feature/Pos/PosReceiptParityTest.php`
- T-3.3.2 Z close path — POS cash allocation at-close (vs kiosk at-creation)
  - test: `tests/Feature/Fiscal/PosZCloseAllocationTest.php`
- T-3.3.3 Audit warning when `audit_emitted=false` surface NF525 failure to operator (PS-4 heal commit `a9500bcbd`)
  - test: `tests/Feature/Pos/PosReceiptAlertWhenAuditNotEmittedTest.php`


#### Sub 3.4 — POS Loyalty cashier redeem UI (Wave 13-zone Option B)
**Anchors** : `app/Http/Controllers/Admin/PosLoyaltyController.php` (Wave L Z6+Z8 P0 ed35fced8 branch check), `LoyaltyService.php`, `LoyaltyQrSigner.php`, `plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md`
**Tasks** :
- T-3.4.1 PosLoyaltyController branch check sentinel re-run
  - test: (test TO BE CREATED at `tests/Feature/Pos/PosLoyaltyBranchScopeSentinelTest.php`) if not exists
- T-3.4.2 LOCK plan owner countersign vérifié (option B Vue overlay UI not shipped V1, deferred)
- T-3.4.3 LCS-S-001 QR signed/verify path — `LoyaltyQrSigner` signing key rotation policy doc
  - test: `tests/Feature/Loyalty/LoyaltyQrSignerTest.php`


---

## §4 — Système 3 : Kiosk (Borne)

### Contract
Client borne fast-food : wizard menu + paiement card + confirmation. Sanctum kiosk:order ability (TTL 480min V1).

### Frozen zones
`KioskWizardComponent.vue` (§7, 3104 LOC), `KioskAppComponent.vue` (§7, 1576 LOC), `KioskUpsellComponent.vue` (§7, 543 LOC).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 4.1 — Wizard + Catalog (Kiosk client flow)
**Tasks** :
- T-4.1.1 Wizard 4 templates composer (sandwich / burger / tacos / bowl) — composition_snapshot frozen
  - test: `tests/Feature/Frontend/Kiosk/KioskWizardComposerTest.php`
- T-4.1.2 Dark mode kill verified (commits `04a3a9b3d` + `84901e198`) — no theme drift
  - visual: `http://127.0.0.1:8000/kiosk/idle` + `/kiosk/wizard` × all surfaces
- T-4.1.3 BORNE-001 dine-in V1 gate — error message FR locale, no flow
  - test: `tests/Feature/Frontend/Kiosk/KioskDineInDisabledGateTest.php`
- T-4.1.4 i18n confirmation kiosk.confirmation.* migration — no `kiosk.wizard.confirmation.*` leakage (cf. memory project_kiosk_confirmation_i18n_fix.md)
  - test: (test TO BE CREATED at `tests/Feature/I18n/KioskConfirmationNamespaceSentinelTest.php`)


#### Sub 4.2 — Payment + Sanctum kiosk:order
**Tasks** :
- T-4.2.1 Sanctum kiosk:order ability — 18 enforcements `tokenCan('kiosk:order')` verified
  - test: `tests/Feature/KioskSecurity/KioskTokenAbilityEnforcementTest.php` (baseline 18 callsites)
- T-4.2.2 TTL 480min token + relogin revokes old tokens (prevent sprawl)
  - test: `tests/Feature/KioskSecurity/KioskTokenLifecycleTest.php`
- T-4.2.3 Pre-auth lookups `withoutGlobalScope(BranchScope::class)` explicit pattern
  - test: (test TO BE CREATED at `tests/Feature/KioskSecurity/KioskPreAuthLookupSentinelTest.php`)


#### Sub 4.3 — Kiosk → KDS sync (paid order visibility)
**Tasks** :
- T-4.3.1 OrderCreated event `DispatchableAfterCommit` (trait line 21) — no uncommitted leak
  - test: `tests/Feature/Outbox/OrderCreatedDispatchableAfterCommitTest.php`
- T-4.3.2 Latency kiosk pay → KDS visibility ≤ 6s (Wave P attested baseline)
  - e2e: `tests/e2e/wave-p-2026-05-20/cross-system/flow-a-kiosk-kds.spec.js`
  - visual: capture KDS card visible after kiosk paid
- T-4.3.3 FrontendOrderService BroadcastableOrder interface F1+F2+F3 heal verified
  - test: `tests/Feature/Frontend/FrontendOrderServiceBroadcastableTest.php`


#### Sub 4.4 — Kiosk confirmation + Receipt
**Tasks** :
- T-4.4.1 Confirmation screen — no raw labels (kiosk.X), waiting auto-redirect 10s
  - test: `tests/Feature/Frontend/Kiosk/KioskConfirmationComponentTest.php` (verify exists, NOTE: working tree modified — re-run post commit)
- T-4.4.2 Receipt path NF525 — `audit_emitted=true` post kiosk paid
  - test: `tests/Feature/Fiscal/KioskPaidAuditEmittedTest.php`


---

## §5 — Système 4 : KDS + OSS

### Contract
KDS = cuisine voit commandes paid (lanes PREPARING/PREPARED). OSS = client public wall pickup. Echo + polling 5s fallback. Allergen snapshot wire.

### Frozen zones
`OrderStateMachine.php` §7 — no recall PREPARED→PREPARING (V1.0.2 LOCK if needed).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 5.1 — KDS Lanes + Bump
**Tasks** :
- T-5.1.1 Echo subscribe OrderCreated + OrderStatusChanged (subscribeEcho:1855-1860)
  - test: `tests/Feature/KDS/KdsEchoSubscribeTest.php`
- T-5.1.2 Bump per-order independent dispatch (Wave V `7e9588cc6` no 3s undo race)
  - test: `tests/Feature/KDS/KdsBumpPerOrderIndependenceTest.php`
- T-5.1.3 5s polling fallback when WebSocket degrade
  - test: (test TO BE CREATED at `tests/e2e/kds-polling-fallback-after-pusher-disconnect.spec.js`)
- T-5.1.4 OrderStateMachine PREPARING→PREPARED single direction (recall forbidden V1)
  - test: `tests/Feature/Order/OrderStateMachineTest.php`


#### Sub 5.2 — KDS Historique X3 Wave X3 NEW (read-only V1)
**Tasks** :
- T-5.2.1 Drawer slide-right read-only — endpoint `GET /api/admin/kds-order/history-today`
  - test: `tests/e2e/wave-x-B-kds-historique.spec.js` (Wave X round-2 GREEN)
- T-5.2.2 TZ-aware Paris-local bounds + 50-cap + branch-scoped
  - test: `tests/Feature/KDS/KdsHistoryTodayTest.php`
- T-5.2.3 Revert PREPARED→PREPARING deferred V1.0.2 LOCK — verify doc + no UI button leak
  - test: (test TO BE CREATED at `tests/Feature/KDS/KdsHistoryReadOnlySentinelTest.php`)


#### Sub 5.3 — OSS Wall + Pickup
**Tasks** :
- T-5.3.1 Fail-closed allowlist `whereIn(KIOSK, TAKEAWAY)` excludes DELIVERY + POS
  - test: `tests/Feature/OSS/OssCustomerScreenFilterTest.php`
- T-5.3.2 Deterministic order `orderBy(queue_number, id)` FIFO
  - test: `tests/Feature/OSS/OssDeterministicOrderTest.php`
- T-5.3.3 CDSOrderDetailsResource PII-stripped (5-field minimal — no name/phone/email)
  - test: `tests/Feature/OSS/CdsOrderDetailsResourcePiiTest.php`
- T-5.3.4 wakeLock TV display + pickup→removal 6.1s baseline (Wave P)
  - e2e: `tests/e2e/wave-p-2026-05-20/cross-system/oss-pickup-removal.spec.js`


#### Sub 5.4 — KDS+OSS sync contract (Echo + polling + allergens)
**Tasks** :
- T-5.4.1 allergens_snapshot wire `KDSOrderItemsResource:39` + `OrderItemResource:37` matching cast
  - test: `tests/Feature/KDS/KdsAllergensSnapshotWireTest.php`
- T-5.4.2 Wave Q-4 allergen data retraction verified (45/45 items `allergen_flags=[]`, 0 pivot rows)
  - test: `tests/e2e/wave-q4-no-fake-allergens.spec.js` (verify exists, 4/4 GREEN per BRAIN)
- T-5.4.3 KitchenDisplaySystemOrderService::orderItems (lines 297-326) split merged lines by allergen-hash
  - test: `tests/Feature/KDS/KdsOrderItemsAllergenSplitTest.php`


---

## §6 — Système 5 : Admin Dashboard

### Contract
Catalogue + observability + cash-overview + Z + reports + permissions. Wave X4 cash-overview unifié shipped.

### Frozen zones
None direct (middleware-enforced).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 6.1 — Cash overview X4 + Z report admin UI
**Tasks** :
- T-6.1.1 Wave X4 3-source reconciliation (POS+borne+livreur) — honest 3-cell pattern
  - e2e: `tests/e2e/wave-x-C-admin-cash-overview.spec.js` (Wave X round-2 GREEN)
- T-6.1.2 500 hard cap + Paris TZ + branch isolation
  - test: `tests/Feature/Admin/CashOverviewControllerTest.php`
- T-6.1.3 Z report open/close throttle:10,1 + monotonic sequence enforced
  - test: `tests/Feature/Fiscal/ZReportControllerTest.php`
- T-6.1.4 Visual check Wave X4 captures × 9 states (default + filters + reconciliation + empty)
  - visual: `tests/e2e/__screenshots__/wave-x4-cash-overview/*.png` (working-tree dirty — re-capture post commit)


#### Sub 6.2 — Catalogue + Stock dashboard
**Tasks** :
- T-6.2.1 StockRuptureDashboardController unified SSOT (Wave Y M1 catalog browser)
  - test: `tests/Feature/Stock/StockRuptureDashboardTest.php`
- T-6.2.2 Binary toggle per product with concurrency-2 + inter-batch delay (commit `4255ec15a`)
  - test: `tests/Feature/Stock/StockBulkToggleConcurrencyTest.php`
- T-6.2.3 ItemBranchAvailabilityProjection consistency
  - test: `tests/Feature/Admin/ItemBranchAvailabilityProjectionTest.php`


#### Sub 6.3 — Permissions + Settings + Reports
**Tasks** :
- T-6.3.1 PermissionController index gate sentinel (commit `6a01c71bf`)
  - test: `tests/Feature/Admin/PermissionControllerIndexAuthzTest.php`
- T-6.3.2 Settings 8 groups gated by `permission:settings`
  - test: `tests/Feature/Admin/SettingsPermissionGateTest.php`
- T-6.3.3 Reports endpoints (sales/items/credit-balance/cash-sessions) — branch isolation
  - test: `tests/Feature/Admin/ReportsBranchIsolationTest.php`


#### Sub 6.4 — Admin observability + outbox monitor
**Tasks** :
- T-6.4.1 `/api/observability/sync-overview` + `/outbox` endpoints — tenant admin only
  - test: `tests/Feature/Admin/ObservabilityEndpointsTest.php`
- T-6.4.2 Client-metrics throttle 60,1 vs admin-mutation bucket
  - test: (test TO BE CREATED at `tests/Feature/RateLimit/ObservabilityThrottleTest.php`)


---

## §7 — Système 6 : Livreur

### Contract
Delivery boy (livreur) : dispatch order, cash session, reconciliation. 2 NEW migrations 2026-05-18 (`delivery_boy_cash_sessions` + `delivery_boy_cash_movements`).

### Frozen zones
None direct (NF525-adjacent cash trail = audit_logs append).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 7.1 — DeliveryBoyCashSession lifecycle
**Tasks** :
- T-7.1.1 UNIQUE partial index `(branch_id, delivery_boy_id) WHERE status=open` enforced
  - test: `tests/Feature/Livreur/DeliveryBoyCashSessionUniqueOpenTest.php`
- T-7.1.2 Cache::lock 3s + DB lockForUpdate (3-layer defense)
  - test: `tests/Feature/Livreur/DeliveryBoyCashSessionLockingTest.php`
- T-7.1.3 closeSession + reconcileSession terminal states + expected = opening + Σ(movements)
  - test: `tests/Feature/Livreur/DeliveryBoyCashSessionReconciliationTest.php`


#### Sub 7.2 — DeliveryBoyCashMovement audit chain
**Tasks** :
- T-7.2.1 Movement types order_collect / change_given / drawer_open / drawer_close / adjustment
  - test: `tests/Feature/Livreur/DeliveryBoyCashMovementTypesTest.php`
- T-7.2.2 DELETE forbidden trigger (migration 120300)
  - test: (test TO BE CREATED at `tests/Feature/Livreur/DeliveryBoyCashMovementImmutabilityTest.php`)
- T-7.2.3 BranchScope on DeliveryBoyCashMovement
  - test: `tests/Feature/Livreur/DeliveryBoyCashMovementBranchScopeTest.php`


#### Sub 7.3 — Wave L D.2 cashBack DB::transaction + reconciliation X4
**Tasks** :
- T-7.3.1 PaymentService::cashBack DB::transaction wrap (Wave L D.2 commit `5a487c64a`)
  - test: `tests/Feature/Payment/PaymentServiceCashBackTransactionTest.php`
- T-7.3.2 X4 cash-overview livreur source pulls DeliveryBoyCashSession + Movement
  - test: `tests/Feature/Admin/CashOverviewLivreurSourceTest.php`
- T-7.3.3 Manual workaround scalability flag — DEL-5 wire-up missing (P1 deferred Sister Sprint 4)
  - documented: §G owner gate G-LIV-1


---

## §8 — Système 7 : Sync spine (Outbox + Pusher + polling)

### Contract
Cross-surface event delivery. Outbox-write → Pusher broadcast → Echo client OR 5s polling fallback. Wave L heals B.1-B.4 shipped.

### Frozen zones
`IdempotencyKeyMiddleware.php` §7 (interlinked with Outbox idempotency).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition

#### Sub 8.1 — Outbox write + listeners
**Tasks** :
- T-8.1.1 11 listeners persist domain events to Outbox — verify each path
  - test: `tests/Feature/Outbox/OutboxListenersFullCoverageTest.php` (baseline-lock listener count)
- T-8.1.2 DomainEvent UNIQUE(idempotency_key) at line 25 (PersistOrderCreatedToOutbox)
  - test: `tests/Feature/Outbox/DomainEventIdempotencyKeyTest.php`
- T-8.1.3 DispatchableAfterCommit trait on critical events (OrderCreated line 21)
  - test: `tests/Feature/Outbox/DispatchableAfterCommitInvariantTest.php`


#### Sub 8.2 — Outbox deliver + retry + rescue (Wave L re-attest)
**Tasks** :
- T-8.2.1 B.1 OutboxRetryFailedCommand preserve attempts + cap 12 (commit `7db47f022`)
  - test: `tests/Feature/Outbox/OutboxRetryAttemptsPreservedTest.php` (4 sentinels, B.1 BRAIN)
- T-8.2.2 B.2 OutboxBroadcastSwallowed listener + fiscal channel escalation (commit `bca6ca356`)
  - test: `tests/Feature/Outbox/OutboxBroadcastSwallowedListenerTest.php`
- T-8.2.3 B.4 OutboxRescueCommand two-lane (pending-stale + crash-claimed ≥10min) (commit `cda1d1b4e`)
  - test: `tests/Feature/Outbox/OutboxRescueTwoLaneTest.php`
- T-8.2.4 B.3 polling_fallback dead config cleanup (commit `8bea2c005`) — re-attest no resurfacing
  - test: (test TO BE CREATED at `tests/Feature/Config/PollingFallbackConfigCleanupSentinelTest.php`)


#### Sub 8.3 — Pusher channel-auth + Echo
**Tasks** :
- T-8.3.1 routes/channels.php branch-scoped + token-name gated + role-checked (Wave Q-4 heal)
  - test: `tests/Feature/Broadcasting/ChannelAuthBranchScopedTest.php`
- T-8.3.2 Sanctum wildcard concern (R3 mgmt audit 2026-05-18) — verify Pusher auth doesn't accept wildcard tokens
  - test: (test TO BE CREATED at `tests/Feature/Broadcasting/PusherSanctumWildcardRejectionTest.php`)
- T-8.3.3 Echo client reconnect under network flap (simulated 5s offline → reconnect)
  - e2e: `tests/e2e/sync-pusher-reconnect-resilience.spec.js` OR (test TO BE CREATED)


#### Sub 8.4 — Polling fallback per-surface
**Tasks** :
- T-8.4.1 KDS 5s polling + Kiosk 15s + POS 30s (per-surface intervals)
  - test: (test TO BE CREATED at `tests/e2e/polling-intervals-per-surface.spec.js`)
- T-8.4.2 Polling drift measurement — instrument actual interval vs config
  - measurement: capture 60s polling logs from each surface


---

## §9 — Système 8 : Cross-system Intersections (W3 — highest yield)

### Contract
7 intersections — historically BRAIN audits show P0s cluster here. Each runs `test-e2e` skill (adversarial dual-team capture+RED visual).

### Anchors (cf. §0.5)
Cf. §0.5 + `reports/audit/goal-pre-cloud-2026-05-21/anchors/`.

### Décomposition (1 task per intersection, dispatchés en parallèle dans W3)

#### Sub 9.1 — POS×KDS (idempotency-key propagation 11 callsites)
- T-9.1.1 E2E real Playwright : POS order create → KDS visible avec allergens_snapshot, idempotency-key replay → 409 sur diff payload
  - test: `tests/e2e/intersection-pos-kds.spec.js` OR (test TO BE CREATED)
  - visual: capture KDS card after POS create

#### Sub 9.2 — POS×OSS (X2 shortcuts)
- T-9.2.1 E2E : PATCH /admin/pos-order/{id}/change-status (PREPARED→DELIVERED) → OSS wall update
  - test: `tests/e2e/intersection-pos-oss.spec.js` OR (test TO BE CREATED)
  - visual: OSS removal after status change

#### Sub 9.3 — Kiosk×KDS (paid → visible ≤6s baseline)
- T-9.3.1 E2E : Kiosk borne paid → KDS card visible, latency mesurée
  - test: `tests/e2e/wave-p-2026-05-20/cross-system/flow-a-kiosk-kds.spec.js` (re-run)
  - visual: KDS card + timestamp

#### Sub 9.4 — Kiosk×OSS (pickup → removal)
- T-9.4.1 E2E : Kiosk paid → OSS wall shows queue → DELIVERED → removal 6.1s baseline
  - test: `tests/e2e/intersection-kiosk-oss.spec.js` OR (test TO BE CREATED)

#### Sub 9.5 — Stock cascade (OrderCreated → StockLevel → Availability → UI hide)
- T-9.5.1 E2E : POS order created, stock=0 reached, Kiosk catalog auto-hides item
  - test: `tests/e2e/intersection-stock-cascade.spec.js` OR (test TO BE CREATED)
- T-9.5.2 DecrementStockOnOrderCreated listener isolated error (no cascade failure)
  - test: `tests/Feature/Stock/DecrementStockOnOrderCreatedIsolationTest.php`

#### Sub 9.6 — Refund cascade (RefundCreated → cashBack DB::transaction → LoyaltyService NOOP early-detect)
- T-9.6.1 E2E : POS refund → counter-entry UNIQUE(parent_order_id) → 23000 catch on dup → 409 MIRROR_ALREADY_EXISTS
  - test: `tests/Feature/Pos/RefundWithCounterEntryCascadeTest.php`
- T-9.6.2 LoyaltyService::refundPoints early-detect idempotent NOOP (Wave L A.2 `e799db200`)
  - test: `tests/Feature/Loyalty/LoyaltyRefundPointsNoopTest.php`

#### Sub 9.7 — Loyalty earn+redeem (cross-surface)
- T-9.7.1 LoyaltyQrSigner sign+verify path (LCS-S-001 heal)
  - test: `tests/Feature/Loyalty/LoyaltyQrSignerTest.php`
- T-9.7.2 PosLoyaltyController branch check (Wave L `ed35fced8`)
  - test: (test TO BE CREATED at `tests/Feature/Pos/PosLoyaltyBranchScopeSentinelTest.php`)
- T-9.7.3 LoyaltyController earn at paid + redeem flow cross-surface (kiosk + POS)
  - e2e: `tests/e2e/intersection-loyalty-cross-surface.spec.js` OR (test TO BE CREATED)

**Acceptance per intersection** : E2E PASS + visual capture + RED visual independent re-analysis (`test-e2e` skill rule) + 0 raw labels + cohérence latency ≤6s pour sync. **VERDICT par intersection** : VERIFIED OR NEEDS-HEAL.

---

## §10 — Standalones (W6 single-task verification per advisor)

### Sub 10.1 — Mobile (`/mobile/`)
- T-10.1.1 **SEP-1** Verify mobile dir has 0 wireup vers central — `grep -r "fetch\|axios" /mobile/` returns only mock/localStorage paths
  - test: (test TO BE CREATED at `tests/Feature/Sentinels/MobileSeparationDisciplineSentinelTest.php`) — baseline-lock no central API URL


### Sub 10.2 — Web démo (`/Users/1millnonstop/Downloads/web/`)
- T-10.2.1 **SEP-2** Verify web dir SSOT data parity with mobile — `data/menu.js` 13 cats / 60 items match
  - test: (test TO BE CREATED at `tests/Feature/Sentinels/WebMobileDataParitySentinelTest.php`) — baseline-lock parity hash


---

## §A — Agent army map + Fan-out matrix

Per `ultra-architect-planify` Axis 4. 9 base roles. Read-only audit = Implementer DISABLED sauf escalation owner-approved heal-light scope-minimal.

| Rôle | Subagent type | Prompt template | Activé |
|------|---------------|-----------------|--------|
| Architect | `Plan` / `general-purpose` | `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` | YES |
| Security | `general-purpose` | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (SECURITY mode) | YES |
| UX / A11y | `general-purpose` | inline brief WCAG 2.1 | YES (frontend tasks only) |
| DBA | `general-purpose` | inline brief schema+FK+N+1 | YES (DB-touching tasks) |
| SRE / Sync | `general-purpose` | inline brief Outbox+Pusher+polling | YES (sync tasks only) |
| Implementer | `general-purpose` | `~/.claude/skills/superpower-gstack/agents/implementer-prompt.md` | **DISABLED** (read-only audit) — re-activé si W5 detect NEEDS-HEAL + owner gate G-HEAL |
| RED-team | `general-purpose` | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (RED mode) | YES |
| QA Visual | `general-purpose` | inline brief Playwright capture + Read | YES (frontend tasks) |
| RED Visual | `general-purpose` | inline brief re-analyze QA screenshots independently | YES (frontend tasks) |

### Fan-out par task type

| Task type | Architect | Security | UX | DBA | SRE | RED | QA Vis | RED Vis |
|-----------|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Foundation invariant (W1) | x | x | . | x | . | x | . | . |
| Backend logic per-surface (W2) | x | x | . | x | . | x | . | . |
| Frontend visual per-surface (W2) | x | x | x | . | . | x | x | x |
| Intersection E2E (W3) | x | x | . | x | x | x | x | x |
| Sync stress (W4) | x | x | . | x | x | x | . | . |

### Dispatch discipline
- **5 read-only specialists** dans une seule task = SINGLE MESSAGE multi-Agent calls (~3min wall-clock)
- **QA Visual + RED Visual** parallèle OK (read-only screenshots)
- **W2 5 systèmes en parallèle** = 5 tasks single-message = 25 specialists concurrent peak
- **W3 7 intersections en parallèle** = 7 tasks single-message = 35-49 specialists concurrent peak

### Agent reporting contract
Tous subagents persistent vers `reports/audit/goal-pre-cloud-2026-05-21/findings/W<N>/<role>-<task>.json`. Schema :
```
[P0|P1|P2|P3] <file>:<line> — <one-line title>
  reproduction: <exact command or click path>
  evidence: <screenshot path | console error | test name>
  recommendation: <scope-minimal fix proposal>
```
Hard cap 1500 mots par agent.

---

## §X — Convergence waves

### W0 — Pre-flight
**Scope** : commit working-tree baseline + capture NF525 chain + verify backups + advisor record
**Parallelism** : sequential
**Budget** : 30 min
**Checkpoint** :
- [ ] Working tree clean (§0.1 commit done OR stash)
- [ ] `backup/pre-pre-cloud-audit-2026-05-21` branch created from `4255ec15a`
- [ ] DB dump : `storage/backups/pre-cloud-audit/foodking-dump-2026-05-21.sql`
- [ ] NF525 baseline captured : `audit_logs count + last_hash` + `z_reports count` → `reports/audit/goal-pre-cloud-2026-05-21/findings/W0-nf525-baseline.txt`
- [ ] 7 missing cartography reports persisted to disk (03/06/07/08/09/10/12.md from transcript)
- [ ] BRAIN.md §2 + §4 updated with mission start

**Interrupt-resume** : if W0 incomplete on session end, write `INTERRUPT_W0_<ts>.md` + commit any partial.

### W1 — Foundation invariants (spine, sequential)
**Scope** : §2 Sub 2.1-2.5 (NF525 + BranchScope + Idempotency + Pricing SSOT + Sentinels)
**Parallelism** : **sequential** (each Sub touches shared CI baseline) + read-only fan-out within each Sub (5 specialists parallel)
**Budget** : 2-3h
**Checkpoint** :
- [ ] All 5 Sub-systems `VERIFIED` OR exactly named heal scope
- [ ] `fiscal:verify-chain` exit 0
- [ ] 91 sentinels GREEN
- [ ] FormRequest authz baseline 69 unchanged
- [ ] BRAIN.md §2 wave-close note

**Interrupt-resume** : Sub-level commits (`audit(W1.X): VERIFIED <Sub>`). Resume = re-read last `VERIFIED` Sub then continue next.

### W2 — Per-surface audit (parallel)
**Scope** : §3-§7 (POS / Kiosk / KDS+OSS / Admin / Livreur)
**Parallelism** : **5 systems in parallel** (single-message dispatch, no implementer overlap = no write conflict)
**Budget** : 3-4h
**Checkpoint** :
- [ ] 5/5 systems return VERIFIED OR NEEDS-HEAL ≤ 2 P1
- [ ] No new P0 introduced
- [ ] Frozen-zone diff = 0 since `4255ec15a` (per `git diff --shortstat <frozen-list>`)
- [ ] All Sub acceptance criteria evidence persisted
- [ ] BRAIN.md §2 wave-close note

**Interrupt-resume** : per-system markers `audit(W2.<sys>): VERIFIED`. Resume re-checks which markers exist.

### W3 — Intersection E2E (parallel)
**Scope** : §9 — 7 intersections via `test-e2e` skill (adversarial dual-team)
**Parallelism** : **7 intersections in parallel** (independent surfaces, independent test files)
**Budget** : 3-4h (test-e2e converges typically 2-4 rounds per intersection)
**Checkpoint** :
- [ ] 7/7 intersections E2E GREEN
- [ ] Visual captures Read + analyzed (QA + RED Visual)
- [ ] Latencies Kiosk→KDS ≤6s, OSS pickup→removal ≤7s, POS→KDS allergens visible
- [ ] No raw labels, no layout break, no console error
- [ ] BRAIN.md §2 wave-close note

**Interrupt-resume** : per-intersection markers `audit(W3.<intersect>): GREEN`.

### W4 — Sync spine under stress
**Scope** : §8 — Outbox retry + Pusher channel-auth + polling drift measurement
**Parallelism** : **sequential** (shared Outbox + queue state can contaminate parallel runs)
**Budget** : 2h
**Checkpoint** :
- [ ] Outbox retry under simulated network flap : claimed records pass attempts cap 12 + Rescue two-lane reaches them
- [ ] Pusher channel-auth rejects wildcard Sanctum token
- [ ] Polling drift ≤ ±20% from config per surface
- [ ] Wave L heals B.1-B.4 re-attested at end-of-wave HEAD
- [ ] BRAIN.md §2 wave-close note

**Interrupt-resume** : Sub-level markers `audit(W4.<sub>): stress-OK`. Resume = re-run last failed stress scenario.

### W5 — Convergence + Verdict
**Scope** : Apply Axis 6 rejection rules literally. Loop W2+W3+W4 if any FAIL until 2 consecutive cycles produce identical findings sets with P0+P1=0.
**Parallelism** : sequential
**Budget** : 1-2h per cycle, max 3 cycles before §10 convergence-failure escalation
**Checkpoint** :
- [ ] 2 consecutive cycles identical findings (skill `test-e2e` rule)
- [ ] P0+P1 NEW = 0 (V1.0.X deferred items DOCUMENTED, not counted)
- [ ] Frozen-zone diff = 0 lignes sur 15 §7 files entre `4255ec15a..HEAD-final`
- [ ] NF525 chain APPENDED-ONLY or bit-identical
- [ ] Final verdict emitted : `GO-CLOUD` | `GO-CLOUD-WITH-OWNER-GATES` | `NO-GO`
- [ ] BRAIN.md §2 + §3 + §6 DECISIONS LOG updated
- [ ] Graphiti `foodking` group : push episode "Pre-Cloud Audit converged <verdict>"

**Interrupt-resume** : cycle-level markers `audit(W5.cycle-<N>): findings-set-hash=<sha>`. Resume = re-load last cycle findings hash, run next cycle, compare set-equality.

### W6 — Cloud-readiness handoff doc + Standalones SEP
**Scope** : Aggregate ALL cloud-infra owner actions captured W1-W4 into a handoff doc. INPUT to next GOAL (cloud cutover itself, OUT OF THIS AUDIT'S SCOPE). + §10 SEP-1 + SEP-2.
**Parallelism** : sequential (single writer)
**Budget** : 1h
**Checkpoint** :
- [ ] `reports/audit/goal-pre-cloud-2026-05-21/HANDOFF_CLOUD_CUTOVER_INPUTS.md` produced (lists all 12+ cloud-infra risks from cartography : Redis driver, distributed lock 86 cron, Pusher load test, ALB rate-limit, RDS trigger preservation, IP-forwarding, CSRF same-origin, vault secrets, 6y retention storage, WAF admin allowlist, Z report archive pipeline, multi-instance cache atomicity)
- [ ] SEP-1 mobile separation verified
- [ ] SEP-2 web data parity verified
- [ ] BRAIN.md §4 NEXT TO DO updated with "next GOAL = cloud cutover, gated by §G owner actions"

**Interrupt-resume** : handoff doc single-writer; on partial, resume from last section header. SEP-1/SEP-2 marker commits `audit(W6.SEP-<N>): VERIFIED`.

---

## §G — Owner gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|------|-------------|-----|------|-------|--------|
| **G-WT-1** | Working-tree commit baseline before W0 | Physical owner (silent OK) | Commit message ack OR explicit "stash instead" | Conversation log + W0 first commit | PENDING |
| **G-CLD-REDIS** | Production cache driver = Redis (not file) before cutover | Physical owner | `.env CACHE_DRIVER=redis` on cloud + Redis cluster spec doc | Cloud env config + deploy log | NEXT-GOAL |
| **G-CLD-LOCK-DIST** | Distributed lock for 86 preventive cron under multi-instance | Physical owner | Redis-backed lock OR single-instance cron declared | Cron config + deploy log | NEXT-GOAL |
| **G-CLD-VAULT** | Secrets rotation Stripe + SenangPay + Pusher to vault | Physical owner | AWS Secrets Manager / HashiCorp Vault rotation receipt | Deploy log + audit_log entry | NEXT-GOAL |
| **G-CLD-WAF** | CloudFront + WAF Admin IP allowlist | Physical owner | Terraform/CDK config commit + WAF rule live | IaC repo commit hash | NEXT-GOAL |
| **G-CLD-ALB-RL** | ALB rate-limit + Sanctum IP-forwarding compatibility | Physical owner | ALB stress test report + Idempotency replay test under multi-instance | Test report path | NEXT-GOAL |
| **G-CLD-RDS** | RDS trigger preservation `audit_logs` + `z_reports` BEFORE DELETE | Physical owner | RDS upgrade dry-run dump SHOW TRIGGERS output | DB dump path | NEXT-GOAL |
| **G-CLD-CSRF** | Sanctum stateful domains specific (not wildcard) | Physical owner | `.env SANCTUM_STATEFUL_DOMAINS` real list | Cloud env config | NEXT-GOAL |
| **G-CLD-RET** | 6y retention NF525 cloud storage strategy | Physical owner | Tiered archival doc + S3 lifecycle policy | IaC repo + compliance log | NEXT-GOAL |
| **G-CLD-PUSHER** | Pusher channel-auth load test @ 100 concurrent | Physical owner | Load test report | Test report path | NEXT-GOAL |
| **G-HEAL** | Authorize heal-light scope-minimal if W5 detects NEEDS-HEAL | Physical owner | Explicit "go heal" with scope listed | Conversation log | PENDING (only triggered if needed) |
| **G-LIV-1** | DEL-5 wire-up (DeliveryBoyCashMovement on OrderService DELIVERED) | Physical owner (decision V1 vs V1.0.X) | Decision V1 in / V1.0.X defer | Conversation log + BRAIN §1 | PENDING |
| **G-HW** | Hardware branchement (cash drawer / TPE / printer) post cloud cutover | Physical owner (physical) | Photo install + first real transaction Z report | NF525 audit log entry | PENDING (next GOAL post-cutover) |
| **G-FISCAL-FINAL** | Finaliser données fiscales (real start sequence_no per branch) | Physical owner | DB seed real fiscal sequence + first Z opens | NF525 audit log entry | PENDING (next GOAL post-cutover) |

**Owner-gate waiting protocol** : while G-WT-1 PENDING, do not launch W0. While G-HEAL PENDING (only if needed post-W5), do not Implementer. All G-CLD-* gates are next-GOAL inputs — they do NOT block this audit (audit can complete and emit verdict before any G-CLD-* fired).

---

## §R — References

- `~/.claude/skills/ultra-architect-planify/SKILL.md` — this skill
- `~/.claude/skills/ultra-audit-profond/SKILL.md` — per-task pipeline
- `~/.claude/skills/superpower-gstack/SKILL.md` — composition partner
- `~/.claude/skills/test-e2e/SKILL.md` — adversarial dual-team for W3
- `CLAUDE.md` §§ 4-13 (FoodKing operating memory)
- `PROJECT_BRAIN.md` §1 §2 §3 §4 §6 §7 §8 (mandatory read at W0)
- `memory/reference_frozen_zones.md` (canonical 15-file frozen list)
- `memory/feedback_adversarial_audit_pattern.md` (RED methodology)
- `memory/feedback_massive_team_orchestration_e2e_per_system.md` (mandate)
- `memory/feedback_no_cloud_until_owner_initiates.md` (mandate — owner triggers cloud, not Claude)
- `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` (active LOCK still applies)
- `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md` (last convergence baseline)
- `reports/test-e2e/wave-p-2026-05-20/WAVE-P-FINAL-SYNTHESIS.md` (Wave K→P prior)
- `reports/audit/goal-pre-cloud-2026-05-21/anchors/` (12 cartography reports — 5 disk + 7 in transcript)

---

## §F — Final rule

Audit DONE = **TOUTES** :
1. W0-W6 checkpoints PASS
2. 2 consecutive convergence cycles identical findings (skill `test-e2e` rule, P0+P1 NEW = 0)
3. Frozen-zone diff = 0 lignes sur 15 §7 files entre baseline `4255ec15a` et HEAD final
4. NF525 chain APPENDED-ONLY ou bit-identical (`fiscal:verify-chain --all` exit 0)
5. 91 sentinels GREEN
6. Final verdict emitted dans `reports/audit/goal-pre-cloud-2026-05-21/FINAL_VERDICT.md` : `GO-CLOUD` | `GO-CLOUD-WITH-OWNER-GATES` (lists required G-CLD-* before cutover) | `NO-GO` (lists blockers)
7. W6 handoff doc produced as INPUT to next GOAL (cloud cutover)
8. BRAIN.md §2 + §3 + §6 DECISIONS LOG updated
9. Graphiti `foodking` group : episode pushed
10. Owner gates §G all PENDING-PENDING ou CLOSED, none silently bypassed

**Production-perfect, not "almost there". `GO-CLOUD-WITH-OWNER-GATES` is acceptable if cloud-infra gates are explicit and owner-actionable. Silent "good enough" is NOT.**

Le but de ce GOAL : **savoir avec preuve si la structure tient le passage au cloud**, pas d'écraser plus de features. Si verdict = `NO-GO`, fail closed et émettre les blockers — owner décide heal scope OU défer V1.0.X.

---

## Launch protocol ("lance le GOAL")

Quand owner dit "lance le GOAL" / "vas-y" / "go" :

1. **Read** ce GOAL + `PROJECT_BRAIN.md §2`. Confirm HEAD = `4255ec15a`.
2. **W0 pre-flight** : G-WT-1 owner confirmation → commit baseline → backup branch + DB dump → NF525 baseline → re-persist 7 missing cartography reports.
3. **W1 sequential** : 5 Sub-systems via 5 `ultra-audit-profond` invocations enchainées.
4. **W2 parallel** : 5 systems single-message dispatch (POS + Kiosk + KDS+OSS + Admin + Livreur).
5. **W3 parallel** : 7 intersections via 7 `test-e2e` invocations single-message.
6. **W4 sequential** : sync stress.
7. **W5 convergence** : 2-cycle identical findings rule.
8. **W6 handoff** : cloud-readiness doc + SEP-1 + SEP-2.
9. **Final verdict** : produce `FINAL_VERDICT.md` + BRAIN + Graphiti.
10. **Owner sign-off** : present verdict + gates list. Owner decides : (a) lance cloud cutover next GOAL, (b) heal-light first then re-audit, (c) defer V1.0.X items.

**Interrupt-resume** : every wave checkpoint commits BRAIN §2 + per-wave marker commit `audit(W<N>): converged`. Resume = read BRAIN §2 + last marker, run 1-task smoke, continue.
