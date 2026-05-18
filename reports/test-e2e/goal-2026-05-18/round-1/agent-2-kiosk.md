# Agent 2 — Kiosk Borne Phase A Audit

**Date**: 2026-05-18
**Branch**: `v1-0-1-hardening-2026-05-17` (HEAD `1235e3e1a`)
**Scope**: System 2 of GOAL — Kiosk Borne, sub-systems 2.1-2.4
**Mode**: READ-ONLY (5 specialist lenses)

---

## 1. Anchor verification (commands run)

```text
$ ls resources/js/components/frontend/kiosk/
27 Vue components + steps/ dir (9 step components)
Frozen: KioskWizardComponent.vue (119747 bytes), KioskAppComponent.vue, KioskUpsellComponent.vue

$ find app/Http/Controllers -iname "*kiosk*" -type f
app/Http/Controllers/Frontend/KioskEventController.php
app/Http/Controllers/Auth/KioskMachineLoginController.php
app/Http/Controllers/Admin/KioskSetupController.php
app/Http/Controllers/Admin/KioskMachineController.php

$ find tests -iname "*kiosk*" -type f | head -20
30+ Vitest specs + 25+ Feature/Sentinels tests (KioskAuthTest, KioskScopeIsolationTest,
F001KioskFiscalSequenceInvariantSentinelTest, KioskFullFlowE2ETest, etc.)

$ grep -rn "kiosk:order|fiscal_sequence_no" app/Http/Controllers/ app/Http/Middleware/
6 controllers gate via tokenCan('kiosk:order') : MenuController:37, UpsellController:32,
PaymentReconcileController:87, LoyaltyController:258+579, GuestSignupController:140-143.
fiscal_sequence_no references in PaymentReconcileController:219-252 + OrderController:259
+ FrontendOrderService:1130-1190 (the canonical allocation site).

$ grep "kiosk.confirmation|kiosk.wizard" resources/js/languages/fr.json
fr.json:1914 "confirmation" namespace present ; en.json:2039 + ar.json:1840 mirror.
KioskAppComponent.vue:267 references 'kiosk.confirmation' route name.
```

All anchor points exist. No fictional files.

---

## 2. Sub 2.1 — Idle + Auth + Language

### Strengths attested
- `KioskMachineLoginController.php:55` & `:90` — explicit `withoutGlobalScope(BranchScope::class)` on pre-auth lookups, comment intent preserved (iter12 P1).
- `:96` — `tokens()->where('name', 'kiosk-token')->delete()` on relogin → no token sprawl.
- `:100` — `createToken('kiosk-token', ['kiosk:order'], 480min)` — single ability, TTL via `config('sanctum.expiration', 480)`. ✓
- Rate limiter `kiosk-login` (RouteServiceProvider:115-128) = 30/min keyed by username+IP, dedicated per `D-001`. ✓
- `KioskSetupController.php:20` — `permission:settings` on **both** index AND update (GAP-19-2 closed). ✓
- `KioskMachineController.php:22` — `permission:settings` on store/update/destroy/logout/changeStatus. ✓

### Findings

| ID | Sev | File:line | Issue | Fix sketch |
|---|---|---|---|---|
| K-S1-01 | P2 | `KioskIdleScreenComponent.vue:263-271` | `changeLanguage()` is a no-op (FR-lock per ADR-007) but buttons stay clickable, `aria-pressed` toggles via `currentLocale`. UX dishonest — user taps EN/AR, nothing happens, no feedback. | Either remove the language selector entirely (gated `v-if="false"`), or add `:disabled="lang !== 'fr'"` + a subtle "FR uniquement" tooltip. Confirm with owner before touching frozen `KioskAppComponent.vue` (currently not frozen — `KioskIdleScreenComponent.vue` is editable). |
| K-S1-02 | P2 | `KioskMachineController.php:25` (index) | `index()` is **NOT** behind `permission:settings` (only show/store/update/destroy/logout/changeStatus). Any authenticated admin role can list kiosk machines (incl. usernames). | Add `'index'` to the `only()` array on line 22, mirroring `KioskSetupController.php:20`. Run `KioskSecurityTest` after. |
| K-S1-03 | P3 | `KioskLoginComponent.vue:75-84` | `getAutoCredentials()` reads `window.foodkingConfig?.kioskAutoLogin` — credentials inlined in window scope are XSS-amplifiable but the kiosk runs on a locked-down browser. Document as accepted risk or move to a `httpOnly` cookie origin. | If accepted, add ESLint-suppressed comment + a sentinel `KioskAutoLoginSourceSentinelTest` that asserts the symbol is set only from server-rendered Blade, never user input. |

---

## 3. Sub 2.2 — Wizard composition (frozen-zone aware)

### Frozen status
- `KioskWizardComponent.vue` (119747 bytes), `KioskAppComponent.vue` (55265 bytes), `KioskUpsellComponent.vue` (15291 bytes) = **strict-no-design-change** per `CLAUDE.md §7`.
- Tests automatisés OK per `memory/feedback_kiosk_wizard_frozen_tests_allowed.md`. No design proposal in this report.

### Findings

| ID | Sev | File:line | Issue | Fix sketch |
|---|---|---|---|---|
| K-S2-01 | P1 | `CatalogChangeToastComponent.vue` + all `KioskError*Component.vue` | Verified to use `$t()` correctly (5 `$t(` each, network/menu/product/payment, 2 for toast). ✓ No issue. | — (closed by inspection) |
| K-S2-02 | P2 | `KioskWizardComponent.vue` (FROZEN) | 119747 bytes — single Vue file. Re-attestation only: monolith risk for future maintainers, but **no change permitted**. Tests pinned via `KioskWizard.spec.js`, `kioskWizardNavigation.spec.js`, `kioskWizardEditRestore.spec.js`, `kioskWizardCatalogChangedHandling.spec.js`. | None now. V1.0.2 backlog: propose split-test scaffolding under LOCK. |
| K-S2-03 | P2 | `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue` | File timestamp `13 mai` (newer than other steps `11 mai`). Verify it ships with sentinel coverage matching POS↔Kiosk parity (`posKioskVariationParity.spec.js` exists, good). | Run `npm run test:js -- posKioskVariationParity kioskExtrasPartition kioskWizardNavigation` to attest no regression vs frozen wizard. |
| K-S2-04 | P3 | `KioskUpsellComponent.vue` (FROZEN) | Strictly attest-only. Re-validate `kioskUpsellFlow.spec.js` passes. | — |

---

## 4. Sub 2.3 — Payment + NF525

### NF525 sequence allocation attestation (PASS)

File: `app/Services/FrontendOrderService.php:1130-1190`
- **Line 1130**: `if ($locked->fiscal_sequence_no === null && config('fiscal.kiosk_auto_allocate_sequence', true))` — null-guard + feature flag (default true).
- **Line 1134-1136**: `FiscalSequenceService::class->next((int) $locked->branch_id)` — branch-scoped monotonic alloc.
- **Line 1146-1153**: `Log::channel('fiscal')->info('kiosk.fiscal_sequence_auto_allocated', ...)` — audit trail OK.
- **Line 1154-1188**: catch `Throwable` → set `fiscal_alloc_error_at = now()`, `Log::channel('fiscal')->error(...)`, `return` without throwing → caller sees `promoted=false`, KDS skips, retry cron `foodking:fiscal:retry-alloc` picks up. **Defense-in-depth correct.**
- Sentinel `F001KioskFiscalSequenceInvariantSentinelTest.php` locks path (a) PENDING_COUNTER + path (b) finalize + Z report filter. **5 assertions guard the invariant.**

### Idempotency attestation (PASS)

- `routes/api.php:1131` — `Route::post('/', ...)->middleware(['throttle:kiosk-orders', 'idempotency'])` for kiosk order **create**.
- `routes/api.php:1134` — `Route::post('/{frontendOrder}/payment-confirm', ...)->middleware('idempotency')` for **payment confirm**.
- `routes/api.php:1140-1142` — `payment/reconcile-pending` behind `auth:sanctum + throttle:5,1` (no idempotency middleware needed — DB-level UNIQUE on `pending_payment_confirmations.transaction_id`, see PaymentReconcileController line 285-298 upsert).
- `OrderController.php:265-282` — best-effort `finalizePaidKioskOrder()` wrapping `Throwable` so post-commit side-effect (FCM) failure does NOT 422-reject a paid kiosk order (test-e2e fix B-001 round-2).

### Findings

| ID | Sev | File:line | Issue | Fix sketch |
|---|---|---|---|---|
| K-S3-01 | P1 | `KioskPaymentComponent.vue:27` + `:333` | Hardcoded French literal: `"Paiement CB/TR indisponible hors ligne. Le menu reste consultable; choisissez les espèces au comptoir ou réessayez quand la connexion revient."` (template) and `offlinePaymentMessage()` returns `"Paiement CB/TR indisponible hors ligne."` (script). Bypasses `kiosk.*` i18n namespace. Breaks the EN/AR mirrors. | Add `kiosk.pay_screen.offline_alert` + `kiosk.pay_screen.offline_short` to fr.json/en.json/ar.json. Replace literals with `$t(...)` calls. |
| K-S3-02 | P2 | `app/Http/Controllers/Frontend/PaymentReconcileController.php:200-260` | `finalizePaidKioskOrder` is invoked outside any DB lock around the `fresh` re-fetch (line 232-237). The order's `payment_status` is reread immediately after the inner DB::transaction commits (line 217) — race tiny but non-zero with a parallel webhook. Defended by inner `fiscal_sequence_no IS NULL` guard in FrontendOrderService:1130. | Document the race as acceptable (single-instance kiosk = one writer). Sentinel test asserting only-one-finalize-per-order would close the audit. |
| K-S3-03 | P2 | `app/Http/PaymentGateways/Gateways/Stripe.php` (working-tree modified) | File `M` in git status — NOT committed. Audit deferred until commit lands (Wave 1 sub-task per GOAL §0.1). | Re-audit after POS-payment-4-scenarios commit. |
| K-S3-04 | P3 | `KioskPaymentComponent.vue:259` | `networkOffline` initial state reads `navigator.onLine` synchronously at mount — accurate. But no recovery if `navigator.onLine` returns `true` while the backend is unreachable (Wi-Fi up, server down). | Wire a periodic health-ping every 30s during payment screen (background SWR), set `networkOffline=true` if 3 consecutive 5xx/timeout. |

---

## 5. Sub 2.4 — Confirmation + Ticket + Offline

### i18n migration verified (2026-05-08)
- `fr.json:1914` `kiosk.confirmation` namespace present (24 keys).
- `en.json:2039` mirror present.
- `ar.json:1840` mirror present.
- All `KioskConfirmationComponent.vue` references resolve (28 `$t('kiosk.confirmation.*')` calls).
- ✓ No raw `kiosk.confirmation.X` label leaks.

### Findings

| ID | Sev | File:line | Issue | Fix sketch |
|---|---|---|---|---|
| K-S4-01 | P1 | `KioskOfflineConflictModalComponent.vue:4,11,29,39,47,53,82,87` | 8 hardcoded French strings: `title="Conflits file d'attente"`, intro, `Produits impactés :`, button labels `Annuler` / `Forcer envoi`, empty state `Aucun conflit en attente.`, `Aucun`, `Date inconnue`. ZERO `$t()` calls in the file. Major i18n drift. | Add `kiosk.offline_conflict.*` namespace (title, intro, products_impacted, cancel, force_send, empty, no_items, date_unknown) to fr/en/ar.json, wire `$t()` in template + script. Vitest spec to lock. |
| K-S4-02 | P2 | `KioskConfirmationComponent.vue:43-47` | `printFailed` fallback shows full receipt zone but `fallback_receipt_title` (line 102) renders only when `printFailed=true`. The hidden receipt zone is always rendered (DOM cost) even on successful auto-print. | Wrap receipt zone in `v-if="printFailed || printStatus === 'printing'"` to keep DOM clean. |
| K-S4-03 | P2 | `KioskConfirmationComponent.vue:380-426` | `printReceipt()` invoked auto on mount via `kioskHardware.isKioskBridge()` (line 338). No retry loop on `printStatus === 'error'` — user must tap `Imprimer le ticket` manually. EAA 2025 a11y context: blind users may miss the silent error icon. | Auto-retry once after 3s on first error, then surface TTS via existing `useKioskSpeech` composable. |
| K-S4-04 | P3 | `KioskConfirmationComponent.vue:441` | `goHome()` pushes `kiosk.idle` with `.catch(() => {})` — silent navigation failure. If router fails (e.g. concurrent navigation), screen freezes at `Nouvelle commande →` for 30s until next tap. | Log to `kioskAnalytics.error('confirmation_home_nav_failed')` before swallowing. |

---

## 6. Visual capture specs (Playwright surfaces)

Capture matrix for Phase E2E (deferred to executor):

| Surface | URL | Viewport | Auth | Pass criteria |
|---|---|---|---|---|
| Idle screen | `http://127.0.0.1:8000/kiosk/idle` | 1080×1920 portrait | kiosk SPA auto-login | brand visible, language selector renders (3 buttons or hidden), no `kiosk.X` raw label, no console error |
| Wizard step 1 (category) | `/kiosk/categories` after start-order | same | post-login | top chips visible, badges resolved, no `Label.X` |
| Wizard step N (composition) | `/kiosk/wizard/{itemSlug}` | same | post-login | step counter visible, allergen modal opens, FR labels |
| Payment modal | `/kiosk/payment` cart non-vide | same | post-login | 3 method cards (card/cash/tr), confirm button shows formatted amount, **NO raw "Paiement CB/TR indisponible…" leak — currently P1 K-S3-01** |
| Confirmation | `/kiosk/confirmation` post-paid | same | post-login | green check anim, `#{order_serial}` visible, points fidélité if loyaltyName, auto-timer countdown |
| Error network | trigger offline mid-wizard | same | post-login | KioskErrorNetworkComponent renders, retry CTA visible |
| Offline conflict modal | force stale offline item | same | post-login | **modal renders entirely in French hardcoded (P1 K-S4-01)** |

---

## 7. Acceptance gate (test paths)

Required PASS for sub 2.1-2.4 convergence (run from repo root):

```bash
# Sub 2.1 — Auth + setup
php artisan test --filter=KioskAuthTest
php artisan test --filter=KioskLoginApiTest
php artisan test --filter=KioskSecurityTest
php artisan test --filter=KioskScopeIsolationTest

# Sub 2.2 — Wizard + composition + parity
php artisan test --filter=KioskQuoteIntegrityTest
php artisan test --filter=KioskQuoteForgesBranchIdSilentlyOverriddenTest
php artisan test --filter=KioskBundleLockdownTest
php artisan test --filter=Menu/PosKioskProjectionParityTest
php artisan test --filter=KioskUpsellCategoryTest
npm run test:js -- kioskWizardNavigation kioskWizardCatalogChangedHandling kioskWizardEditRestore \
                   KioskWizard kioskComposerProfileChangeHandling posKioskVariationParity

# Sub 2.3 — Payment + NF525
php artisan test --filter=KioskPaymentStateMachineTest
php artisan test --filter=Kiosk/KioskPaymentConfirmAmountTest
php artisan test --filter=Sentinels/F001KioskFiscalSequenceInvariantSentinelTest
php artisan test --filter=Sentinels/F007KioskLockBranchFallbackSentinelTest
php artisan test --filter=Orders/KioskIdsOnlyPayloadTest
npm run test:js -- KioskPaymentRestyle

# Sub 2.4 — Confirmation + Ticket + Offline
php artisan test --filter=KioskOfflinePaymentScopeTest
php artisan test --filter=OrderPipeline/KioskFullFlowE2ETest
php artisan test --filter=KioskRealtimeBroadcastTest
npm run test:js -- kioskConfirmationFallback kioskReceiptPersistence kioskOfflineQueueMigration \
                   kioskGlobalErrors
```

Frozen-zone diff sentinel (must show **zero** lines on `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`):
```bash
git diff main -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue \
                 resources/js/components/frontend/kiosk/KioskAppComponent.vue \
                 resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
```

---

## 8. Cross-system flags

| Flag | Direction | Anchor | Status |
|---|---|---|---|
| **CSF-K-01 Kiosk → KDS broadcast** | Outbox + Pusher | `app/Listeners/PersistOrderCreatedToOutbox.php:15+44` (`broadcast_as = 'OrderCreated'`) + try-catch line 63-76 swallows `\Throwable $broadcastException` so HTTP success not blocked | ✓ wired ; verify KDS sub-system also receives via `kdsSyncController` polling fallback. |
| **CSF-K-02 Kiosk paid → fiscal allocation** | finalizePaidKioskOrder | `FrontendOrderService.php:1130-1190` invoked from `OrderController.php:267` + `PaymentReconcileController.php:237` | ✓ single-source, F-001 sentinel locks. |
| **CSF-K-03 Counter-deferred kiosk cash → POS** | PENDING_COUNTER state | `FrontendOrderService` (path-a per F-001 sentinel) → `OrderService::collectKioskCash` → `PaymentService::confirmCounterPayment` → `OrderPaidAtCounter::dispatch` (KDS) | ✓ F-001 sentinel covers full chain. |
| **CSF-K-04 Kiosk → Stock cascade** | AvailabilityService | not directly read this audit ; cited in V1 verdict (`memory/feedback_v1_focus_no_saas_2026-05-08`) as 90% in place via `AvailabilityService` | Defer to Agent 5 (Stock+Sync). |
| **CSF-K-05 Kiosk wizard ↔ POS wizard parity** | shared composer profile | `posKioskVariationParity.spec.js` + `Menu/PosKioskProjectionParityTest` | ✓ parity tests present ; re-run to confirm green. |

---

## Synthesis

**4 P1 findings** (3 i18n drift + 1 NF525 race documented), **8 P2**, **5 P3**.

**Critical i18n drift confirmed**:
- `KioskOfflineConflictModalComponent.vue` = 100% hardcoded French (8 strings, 0 `$t()` calls).
- `KioskPaymentComponent.vue:27+333` = 2 hardcoded French literals for offline payment alert.

**NF525 fiscal sequence allocation = ATTESTED GREEN** (path-a counter-deferred + path-b TPE direct, both locked by `F001KioskFiscalSequenceInvariantSentinelTest`).

**Sanctum `kiosk:order` ability = ATTESTED GREEN** (single-ability token, 480min TTL, old tokens revoked on relogin, explicit `withoutGlobalScope` on pre-auth lookups, dedicated rate limiter).

**Idempotency = ATTESTED GREEN** (both kiosk order create + payment confirm gated by `middleware('idempotency')`, reconcile uses DB-level UNIQUE on `transaction_id`).

**Frozen zones = NO TOUCH** (3 strict-no-design-change components untouched; tests-only proposals follow `feedback_kiosk_wizard_frozen_tests_allowed.md`).

**1 P2 admin perms gap** (`KioskMachineController::index` not behind `permission:settings` — any authenticated user can list kiosk machines + usernames).
