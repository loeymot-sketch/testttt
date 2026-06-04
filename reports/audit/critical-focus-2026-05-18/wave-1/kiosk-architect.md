# Kiosk Borne — GStack Architect Audit (Wave 1)

Scope: Kiosk Borne (client self-service) — local V1 Le Cayenne, branch `v1-0-1-hardening-2026-05-17` @ `6908edbde`. Read-only audit; FROZEN files are inspected but never proposed for edit. No cloud surface in scope.

---

## 1. Surface Inventory

### Frontend — Vue components

| Role | File | LOC | Status |
|---|---|---|---|
| Wizard shell (composer dispatcher) | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 3104 | FROZEN |
| App router shell (auto-login, hardware boot) | `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | 1576 | FROZEN |
| Upsell drawer | `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | 543 | FROZEN |
| Cart bottom-sheet | `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | 1235 | non-frozen |
| Payment screen (TPE + cash + reconcile) | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | 1289 | non-frozen |
| Loyalty link/lookup | `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` | 1071 | non-frozen |
| Waiting (poll order status) | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | 861 | non-frozen |
| Confirmation (receipt + auto-return) | `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` | 731 | non-frozen |
| Idle screen, login, inactivity, offline modal, promo, errors | `resources/js/components/frontend/kiosk/Kiosk{IdleScreen,Login,InactivityOverlay,OfflineConflictModal,PromoCarousel,Error*}Component.vue` | — | non-frozen |
| Composer steps | `resources/js/components/frontend/kiosk/steps/KioskStep{Pain,Viande,Sauce,Garnitures,Supplements,Menu,Taille,FritesStyle,GenericChoices}Component.vue` | — | non-frozen |
| Design-system atoms (real path) | `resources/js/components/frontend/kiosk/ds/Ks{A11ySettings,AllergenBadge,Badge,Button,Card,CartBottomSheet,Chip,ConsentModal,FilterChip,Hero,Modal,PriceLine,Stepper,ThemeToggle,VirtualKeyboard}.vue` | — | non-frozen |

Scope-doc drift: prompt cites `kiosk/ks/*` — actual path is `kiosk/ds/Ks*.vue`. Cosmetic, no heal required.

### Backend controllers (kiosk surface — `/api/frontend/*`)

| Controller | File | LOC | Sanctum gate |
|---|---|---|---|
| Menu | `app/Http/Controllers/Frontend/MenuController.php` | 89 | in-controller `tokenCan('kiosk:order')` :37 |
| Order CRUD + confirm | `app/Http/Controllers/Frontend/OrderController.php` | 314 | `OrderRequest::authorize()` :81/346 + `PaymentConfirmRequest::authorize()` |
| Upsell | `app/Http/Controllers/Frontend/UpsellController.php` | 124 | in-controller :32 |
| Payment reconcile (F-008) | `app/Http/Controllers/Frontend/PaymentReconcileController.php` | 317 | in-controller :87 |
| Loyalty | `app/Http/Controllers/Frontend/LoyaltyController.php` | 730 | in-controller :258/:579 |
| Kiosk event log | `app/Http/Controllers/Frontend/KioskEventController.php` | 290 | route-level `abilities:kiosk:order` (api.php:1232) |
| Promo validate | `app/Http/Controllers/Frontend/PromoController.php` | 66 | `PromoValidateRequest::authorize()` |
| Pricing preview | `app/Http/Controllers/Frontend/PricingPreviewController.php` | 80 | `PricingPreviewRequest::authorize()` |
| Guest signup | `app/Http/Controllers/Frontend/GuestSignupController.php` | — | — |
| Machine login | `app/Http/Controllers/Auth/KioskMachineLoginController.php` | 132 | mint token `['kiosk:order']` :100 |

Service: `app/Services/FrontendOrderService.php` (1237 LOC) — pricing SSOT, composition snapshot freeze, fiscal allocation hooks.

---

## 2. Critical Invariants — Verification

| Invariant | Evidence | Verdict |
|---|---|---|
| Sanctum `kiosk:order` ability strict scope | Mint: `KioskMachineLoginController.php:98-102` with `['kiosk:order']`, TTL `config('sanctum.expiration', 480)`; old token revoke :96. Gate sentinels: `tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php:29` (non-kiosk staff → 403), `F008PaymentReconcileAbilitySentinelTest.php:47-77`, `AntiGravityTest.php:181 test_t07_kiosk_cannot_read_pos_orders`. | OK — kiosk tokens cannot reach `/api/admin/pos*` (admin routes guarded by RBAC `permission:settings` + role check). |
| Pricing SSOT (item_id + quantity + option_ids only) | Payload builder: `kioskCart.js:98-112 sanitizeKioskOrderItem` emits only `item_id`, `quantity`, `instruction`, `item_variations[{id,quantity?}]`, `item_extras[{id,quantity?}]`, `item_addons[]`. Service strips client totals: `FrontendOrderService.php:257 unset($validatedRequest['total','subtotal','discount'])`. Server recompute: `:277 PricingService::calculateOrder(PricingRequest::forKiosk(...))`. Quote signature: `kioskCart.js:627 frontend/order/quote` returns `quote_token` + signature replayed at submit `:170-176`. | OK — frontend cannot bypass server pricing. Quote signature replay reduces tampering surface. |
| `composition_snapshot` frozen at order creation | SSOT path: `FrontendOrderService.php:288 $kioskSsot->orderItemInsertRows` already includes snapshot from `PricingService`. Legacy path: `:425 (new CompositionSnapshotBuilder())->build(...)` then `:441 'composition_snapshot' => json_encode($compositionSnapshot)`. Schema: `OrderItem.php:44/71` `composition_snapshot` fillable + array cast. | OK — written inside the same DB transaction as `OrderItem::insert(...)`. No post-payment overwrite path located. |
| F-008 reconcile pending payments | Frontend: `KioskPaymentComponent.vue:746-792 confirmBackendPayment` retries 3× with stable `X-Idempotency-Key=kiosk-payment-confirm-{orderId}-{tx}`; on exhaustion `:784 _appendPendingReconcile(...)` writes to `kioskOfflineQueue`. Boot + 60s loop: `:288-300 _reconcilePendingPayments`. Backend: `PaymentReconcileController.php:55 reconcile()` accepts batched entries (max 50), per-entry `Cache::lock("f008:reconcile:tx:{tx}",10)` :164, idempotent `STATUS_RESOLVED` on already-PAID :187. Schema: `database/migrations/2026_05_08_120000_create_pending_payment_confirmations_table.php` UNIQUE(transaction_id). | OK — no double-charge vector: amount echo gate is re-applied :173, side-effect failures detected via `$afterFail->fiscal_sequence_no` post-flag :250-258 (does NOT downgrade to `failed` when DB is actually paid + sealed). |
| F-002 amount echo (`amount_cents` vs `order.total*100` ±1¢) | `OrderController.php:137-152` pre-transaction strict gate, `Log::warning AUDIT-F-002` + 422 `AMOUNT_ECHO_MISMATCH`. Mirrored in `PaymentReconcileController.php:173-184`. TPE driver echo: `KioskPaymentComponent.vue:679-688 amount_cents_approved` extracted from bridge frame; stub mirror `:659`. | OK — single error code stable for ops dashboards. Bridge fallback `?? amountCents` :681 documented (legacy firmware); backend will still reject if mismatch — defense-in-depth holds. |
| F-001 fiscal allocation | `FrontendOrderService.php:1130-1190 finalizePaidKioskOrder` — `Cache::lock`+`lockForUpdate`, gated by `config('fiscal.kiosk_auto_allocate_sequence', true)`, error path sets `fiscal_alloc_error_at = now()` :1174 instead of throw (retry cron `foodking:fiscal:retry-alloc` picks up). Status stays PENDING until success. Sentinel: `tests/Feature/Kiosk/F001KioskFiscalSequenceInvariantSentinelTest.php`. | OK — no NF525 silent gap; orphan path is observable via `fiscal_alloc_error_at`. |
| FR-lock ADR-007 (`KIOSK_LOCALE_SWITCH_ALLOWED=false`) | Config: `config/kiosk.php:31 $localeSwitchAllowed = filter_var(env('KIOSK_LOCALE_SWITCH_ALLOWED', false), FILTER_VALIDATE_BOOLEAN);` exposed as `locale_switch_allowed` :102/:153. Runtime: `resources/js/i18n.js:9-21` `KIOSK_LOCALE='fr'`, `detectLocale()` ignores `navigator.language` on `/kiosk` paths. UI removal: `ds/KsA11ySettings.vue:25-30,195-255` selector deleted. Persisted-state exclusion: `store/index.js:273` `kioskSettings.locale` is NOT in the persistedstate paths list. Sentinel: `tests/js/kioskFrLockImmutable.spec.js:207`. | OK with caveat — see §4-W1 (residual mutation path). |
| `POS_SIMULATION_HARDWARE` flag (local mandate) | Config: `config/pos.php:37 'simulation_hardware' => filter_var(env('POS_SIMULATION_HARDWARE', false), FILTER_VALIDATE_BOOLEAN)`. Production guard: `AppServiceProvider.php:81-88` throws if `APP_ENV=production` && `simulation_hardware=true`. Sentinel: `tests/Feature/Sentinels/PosSimulationHardwareProductionGuardSentinelTest.php`. Kiosk stub mode (browser, no `kioskHardware.isKioskBridge()`): `KioskPaymentComponent.vue:651-661` echoes `amountCents` → backend F-002 still authoritative; never bypasses pricing / composition. | OK — local-only simulation; production day flip is environmental and guarded by a boot-time exception. |

---

## 3. Wizard Composer Profile Parity (mobile ↔ web ↔ kiosk)

`computeWizardTotal` is a **mobile/web standalone helper** (`tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js:222`, `tests/e2e/test-e2e-fullflow-2026-05-18.spec.js:134`). It does **not exist in the kiosk tree** (`grep -rn computeWizardTotal resources/js` → 0 hits). Parity is by **contract**, not shared helper:

- Mobile/web: `computeWizardTotal` is a *display-only* helper — never sent.
- Kiosk: pricing is server-only. Payload (`kioskCart.js:98-112`) sends `item_id` + `quantity` + `item_variations[{id,quantity?}]` + `item_extras[{id,quantity?}]` + `item_addons[]`. `PricingService::calculateOrder` (`FrontendOrderService.php:277`) is the single authority. `KioskCartComponent.vue:226/404` displays `item.total` — Vuex-computed mirror, never echoed.

Composer profile runtime (FROZEN `KioskWizardComponent.vue`):
- `publishedComposerProfile()` :778-783 — accepts only `is_published !== false`, filters `steps[].is_active !== false`.
- `composerActiveSteps()` :784-800 — maps each step via `composerStepType` :806 (explicit kind first; else `generic_choices` if `source_type ∈ {item_attribute, extra_group, addon}`); appends `recap`.
- `effectiveWizardTemplate()` :884-906 — composer-first; falls back to `item.wizard_template` → `category.wizard_template` → `detectTemplateFromName()` :907 consulting `config/kiosk.php → wizard_template_aliases` (K-004) before legacy substring heuristic.
- Templates supported: `simple | tacos | sandwich | burger | assiette | omelette | salade | snacking` (`config/kiosk.php:64`).
- `menu_formule` is **not** a template — menus are addons (`addon_role`) inside a parent profile (`KioskWizardComponent.vue:842`).
- Bols: 5 dedicated `composer_profiles` seeded by `MenuResetLeCayenneCommand.php:874` (base → sauce → supp → drink).

Backend projection: `KioskMenuService.php:358`. Heuristic-fallback telemetry fires at :900.

---

## 4. Weak Spots

**W1 — `kioskSettings/setLocale` runtime path not guarded by `locale_switch_allowed`.** `resources/js/store/modules/kioskSettings.js:251-253 setLocale` calls mutation `SET_LOCALE` :168 with no check against `window.fk_kiosk_config?.locale_switch_allowed`. ADR-007 defenses are: (a) UI removed (`ds/KsA11ySettings.vue`), (b) persisted-state exclusion (`store/index.js:273`), (c) boot detection (`i18n.js:21 KIOSK_LOCALE='fr'`). However, any in-bundle caller (third-party widget, future regression, dev console) can mutate `state.locale`; `applyKioskA11yFromStore(store)` in `composables/useKioskA11y.js` would then propagate (i18n.js:11-19 already documents this residual). **Severity: P2 (no persistent poisoning; in-memory only).**

**W2 — Heuristic template fallback is observability-only, not blocking.** `KioskWizardComponent.vue:899-905` tracks `missing_published_composer_profile` but still renders a heuristic wizard. If an admin renames an item out of any alias (`config/kiosk.php:69-93`) AND the category lacks `wizard_template`, the user gets `simple` fallback — items that need composer choices are reduced to add-to-cart. **Severity: P2** — already mitigated by `wizard_template_aliases` (K-004) + telemetry; LOCK plan required to add a UI breakdown (frozen file).

**W3 — `confirmBackendPayment` retries 3× with stable idempotency key (`KioskPaymentComponent.vue:755`), then queues for reconcile.** Backend `IdempotencyKeyMiddleware` 2xx-replay cache returns cached responses; per-entry `Cache::lock` (PaymentReconcileController:164) serialises duplicates so no double-promote. **Verdict: tight; relies on reconcile lock correctness.** Covered by `PaymentReconcileTest.php`.

**W4 — Offline queue replay strips `quote_token`/`signature`/`total`/`subtotal`/`discount` (`kioskCart.js:777-782`) and regenerates a fresh quote at replay.** Offline payload retains `kiosk_promo_code` + `loyalty_code`. If those tokens have expired, the backend silently drops the discount (`loyalty_applied=false` surfaced in toast `KioskPaymentComponent.vue:399-403`). Electronic methods are refused offline upfront (`kioskCart.js:763-768`). **Verdict: correct by design; coverage gap → §6-G1.**

**W5 — `KioskMachineLoginController.php:96` revokes all `kiosk-token` tokens for the linked user at relogin.** If two bornes share `user_id` (mis-seed), relogin kills the other's token. Revoke is user-scoped, not machine-scoped. **Severity: P2 deploy-discipline** — `KioskMachine.user_id` should be 1:1. Sentinel candidate.

**W6 — `cancelCardPayment` (`KioskPaymentComponent.vue:735-743`) voids the order via `change-status reason:tpe_cancel_user`.** If network is down at cancel time, the order remains PENDING orphan; no retry path. **Severity: P2** — counter-staff voids manually via admin dashboard.

---

## 5. Existing Test Coverage Map

| Domain | Tests |
|---|---|
| Fiscal F-001 / F-002 | `tests/Feature/Kiosk/F001KioskFiscalSequenceInvariantSentinelTest.php`, `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` |
| Reconcile F-008 | `tests/Feature/Kiosk/PaymentReconcileTest.php`, `tests/Feature/Sentinels/F008PaymentReconcileAbilitySentinelTest.php`, `tests/js/sentinels/f008KioskPaymentReconcileQueue.spec.js`, `tests/Playwright/kiosk-offline-waiting.spec.js`, `tests/e2e/reconciliation-flows.spec.js` |
| Sanctum scope | `tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php`, `tests/Feature/AntiGravityTest.php::test_t07_kiosk_cannot_read_pos_orders`, `tests/Feature/Sentinels/PaymentConfirmCrossBranchSentinelTest.php` |
| Branch isolation | `tests/Feature/Isolation/MultiBranchIsolationE2ETest.php`, `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php`, `tests/Feature/Kiosk/F007KioskLockBranchFallbackSentinelTest.php` |
| Pricing SSOT | `tests/Feature/Kiosk/PricingSsotFlagProductionStableSentinelTest.php`, `tests/js/kioskPricingPreview.spec.js`, `tests/js/posKioskVariationParity.spec.js` |
| Composer profile | `tests/js/kioskWizardComposerProfile.spec.js`, `tests/js/kioskWizardTemplateAliases.spec.js`, `tests/js/kioskWizardStepRegistry.spec.js`, `tests/js/kioskComposerProfileChangeHandling.spec.js` |
| Offline queue | `tests/js/kioskOfflineQueue.spec.js`, `tests/js/kioskOfflineQueueV2.spec.js`, `tests/js/kioskOfflineQueueMigration.spec.js`, `tests/js/kioskCartOfflinePaymentScope.spec.js` |
| FR-lock | `tests/js/kioskFrLockImmutable.spec.js` |
| TPE flow + retry | `tests/js/kioskPaymentTpeTimeout.spec.js`, `tests/js/kioskPaymentRetryGate.spec.js`, `tests/js/kioskHardwareService.spec.js` |
| Idempotency middleware | `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php` |
| Cash counter-deferred | `tests/Feature/Kiosk/F009KioskCashCounterDeferredInvariantSentinelTest.php`, `tests/js/kioskCounterPaymentFlow.spec.js` |
| Promo / loyalty | `tests/js/kioskCartPromo.spec.js`, `tests/js/kioskLoyaltyConsentWiring.spec.js`, `tests/Feature/Kiosk/FinalizePromotionGuardTest.php` |
| Dine-in disabled V1 | `tests/Feature/Kiosk/KioskDineInDisabledV1SentinelTest.php`, `tests/js/kioskOrderTypeExplicit.spec.js` |
| Hardware simulation guard | `tests/Feature/Sentinels/PosSimulationHardwareProductionGuardSentinelTest.php` |

---

## 6. Test Coverage GAPS

**G1 — Offline replay with stale promo/loyalty.** No test covers cash-payment queued offline + replayed >promo TTL; observable in `KioskPaymentComponent.vue:399-403` toast. Recommended: Vitest spec on `kioskOfflineQueue` replay path that asserts `loyalty_applied=false` triggers the toast.

**G2 — Heuristic-fallback breadcrumb / tier-0 absence regression.** `kioskWizardTemplateAliases.spec.js` covers happy alias hits; missing: a sentinel asserting `kioskAnalytics.trackHeuristicFallback` fires when alias + `category.wizard_template` are absent. Defends against silent regression of menu reset.

**G3 — `kioskSettings/setLocale` runtime guard absence.** No spec asserts `dispatch('kioskSettings/setLocale','en')` is a no-op when `locale_switch_allowed=false`. Pair with §7-R1.

**G4 — TPE bridge `amount_cents_approved` divergence.** `KioskPaymentComponent.vue:679-688` falls back to `amountCents` if bridge omits `amount_cents_approved`. No Vitest asserts backend rejects when bridge returns a divergent value. Backend coverage exists (`KioskPaymentConfirmAmountTest.php`); frontend-side bridge-contract test missing.

**G5 — Two kiosks sharing `user_id` token-revoke collision (W5).** No sentinel proves the deploy invariant `KioskMachine.user_id` is 1:1 in seeders. Recommend a PHPUnit assertion against the seeder.

**G6 — `cancelCardPayment` void on offline (W6).** No test for `cancelCardPayment` when network is offline → no retry path documented for the void. Add an E2E to surface as orphan PENDING with operator playbook reference.

**G7 — Cross-scope abuse: kiosk token POST to `/api/admin/pos`.** `AntiGravityTest::test_t07_kiosk_cannot_read_pos_orders` proves GET denial. POST coverage on `/api/admin/pos` with a `kiosk:order` token is implicit (route uses admin RBAC) but not direct. Sentinel candidate.

**G8 — Composer parity for `sauce extras + menu addons`.** Self-documented gap: `tests/js/posKioskVariationParity.spec.js:20-22` `Skipped : sauce extras + menu addons (full/frites/boisson) — la passerelle POS↔Kiosk pour ces composants nécessiterait un mapping selections ↔ pos_line_addons hors scope V1 (issue à créer pour V1.0.1)`. Tracked debt.

---

## 7. Recommendations (NON-frozen only; LOCK plans flagged)

**R1 — Add `setLocale` runtime guard (W1, G3).** Edit `resources/js/store/modules/kioskSettings.js:251` so the `setLocale` action no-ops when `window.fk_kiosk_config?.locale_switch_allowed === false`. Add Vitest sentinel. Non-frozen; scope-minimal (5-line guard + spec).

**R2 — Add Vitest sentinel for heuristic-fallback telemetry (G2).** New spec `tests/js/kioskWizardHeuristicFallbackTelemetry.spec.js` mocking `kioskAnalytics.trackHeuristicFallback` and asserting it fires when `composer_profile` + alias miss. Pure addition; FROZEN wizard untouched.

**R3 — Add bridge-contract Vitest for `amount_cents_approved` divergence (G4).** Spec on `_invokeTpe` stubbing `kioskHardware.tpeCharge` to return a divergent `amount_cents_approved`; assert downstream `confirmBackendPayment` is invoked with the divergent value (so backend F-002 gate triggers). Non-frozen.

**R4 — Add PHPUnit sentinel for `KioskMachine.user_id` uniqueness (W5/G5).** New `tests/Feature/Sentinels/KioskMachineUserIdUniqueSentinelTest.php` — runs `KioskMachineTableSeeder` then asserts `KioskMachine::query()->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->doesntExist()`. Catches deploy mis-seed before relogin collision.

**R5 — Add Playwright/E2E for `cancelCardPayment` offline path (W6/G6).** Block axios POST `/change-status/*`, click TPE cancel, assert UI re-enables + analytics event `order_cancelled` fires; documents the orphan-PENDING risk with operator playbook in the report.

**R6 — Add PHPUnit sentinel for kiosk token POST to `/api/admin/pos*` (G7).** Mirror `test_t07_kiosk_cannot_read_pos_orders` with `postJson('/api/admin/pos', ...)`. Direct assertion of the scope-strictness invariant.

**R7 — LOCK plan suggestion (NOT a heal) for FROZEN `KioskWizardComponent.vue` to add a blocking modal when `effectiveWizardTemplate()` returns `simple` by heuristic for an item with `wizard_required=true`.** Currently only telemetry. Owner gate + frozen-zone override required (W2). Author a `LOCK_KIOSK_WIZARD_HEURISTIC_BLOCK.md` via `/lock-plan` only after owner approval.

**R8 — No-action items verified clean:** `KioskMachineLoginController.php` (token mint), `OrderRequest::authorize` (ability check), `FrontendOrderService.php:1130-1190` (fiscal alloc retry pattern), `PaymentReconcileController.php` (per-entry lock + amount-echo gate). These are production-correct as written.

---

Sentinel surface delta if R1-R6 land: +6 specs / +2 sentinels covering identified gaps without touching FROZEN files. No NF525 invariants moved. No cloud surface invoked.

GStack Architect — Kiosk — Wave 1
