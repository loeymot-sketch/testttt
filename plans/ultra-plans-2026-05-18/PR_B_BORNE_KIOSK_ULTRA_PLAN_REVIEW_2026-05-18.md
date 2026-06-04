# PR-B — Borne / Kiosk Ultra-Review + Ultra-Plan

> Read-only audit. Scope: kiosk backend (`/api/frontend/*`, `/api/admin/kiosk-*`) + kiosk Vue surface. Branch `v1-0-1-hardening-2026-05-17`, HEAD `a34d1f696`. Frozen wizard files = audit-only.

---

## §0 Executive Summary

**Verdict** : GO-CONDITIONAL on PR-B carve-out. Kiosk auth + Sanctum scoping + BranchScope = solid (Wave 5G/5I work landed). One **P0** (composer-profile drift surfacing as "Composition : le choix #N n'appartient pas au profil publié" on legitimate kiosk submits) + four **P1** (i18n key-path debt, FR-lock leakage path, two FrontendOrderService hot-path concerns) + six **P2** (UX/a11y/test gaps). Frozen-zone diff vs `main` = **+1903 LOC KioskWizardComponent / +1009 KioskAppComponent / +57 KioskUpsellComponent** — large but *expected* (iter1-14 + Wave Z hardening accumulation, baseline reference is `HEAD@phase0`, **not** `main`, per BRAIN §2). PR-B = ~10-14h wall-clock, scope-minimal heals + sentinels.

**Counts** : 1 P0 · 4 P1 · 6 P2 · 12 tasks.

---

## §1 Method + Anti-Drift

- All file:line citations verified via `grep -n` / `Read` on current HEAD (`a34d1f696`).
- Frozen-zone tree (`KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `KioskPosWizardComponent.vue`, `KioskCartComponent.vue`, `KioskCategoriesComponent.vue`, `KioskPromoCarouselComponent.vue`, `KioskOrderSummaryComponent.vue`, `KioskProductListComponent.vue`) → **audit-only**; only flag drift vs `HEAD@phase0` baseline, never edit. Tests on these files = allowed (per `feedback_kiosk_wizard_frozen_tests_allowed`).
- Non-frozen kiosk surfaces (Payment / App boot strap / Login / Admin / Error variants / CashInstruction / Confirmation / Waiting) → edit allowed within scope-minimal pattern.
- NF525 invariants (§8 CLAUDE.md) untouched : `composition_snapshot` immutability, `fiscal_sequence_no` monotonic, `audit_logs` chain HMAC.

---

## §2 Findings — Ultra-Review

### P0-K1 — Composer profile drift surfaces as "le choix #N n'appartient pas au profil publié"
- **Anchor** : `app/Services/Pricing/PricingService.php:693` (`assertComposerSelectionsBelongToPublishedProfile`) called from `:603`.
- **Root cause** : the error is thrown by `PricingService::assertComposerSelectionsBelongToPublishedProfile` (NOT `FrontendOrderService` — owner-narrative misattribution noted). It compares `item_variations[i].id` / `item_extras[i].id` / `item_addons[i].id` from the request against the projected `published` profile choices keyed by `(string) ($choice['id'] ?? '')`. Three sub-causes converge :
  1. Published profile rows out-of-date relative to live `item_attributes` / `extra_groups` IDs (drift introduced by `Align*Seeder` republish-sweeps, see BRAIN §2 "republish-all sweep" V1.0.x backlog).
  2. `composer_profile.is_published=true` but `published_at` snapshot missing one or more `choices` (`ComposerProfileProjection.php:55-56`).
  3. Frontend `KioskWizardComponent.vue:778-888` reads `this.resolvedItem.composer_profile` — if the kiosk locally caches an item resource older than the last `ComposerProfileChanged` dispatch (`ComposerProfileService.php:142,149,240`), it will POST stale IDs.
- **Severity** : P0 — blocks order creation on legitimate kiosk submits, no client-side recovery path. Symptom : 422 + JSON `message="Composition : le choix #5 n'appartient pas au profil publié."` reaching the kiosk error toast.
- **Sentinel gap** : no test asserts that a republished profile evicts the kiosk-cached projection before next quote.

### P1-K2 — i18n key-path migration debt + FR-lock invariant
- `kiosk.confirmation.*` migration committed on `clever-hypatia-1e4f84` (memory `project_kiosk_confirmation_i18n_fix`). Cross-branch rebase debt : keys added on `blissful-mclean-c915c2` (csat_*, total_balance, payment_method, estimated_ready, minutes_range) under the *broken* `kiosk.wizard.confirmation.*` path. Verify `resources/js/languages/fr.json` final state at PR-B merge time.
- ADR-007 FR-immutable invariant : `config/kiosk.php:31` reads `KIOSK_LOCALE_SWITCH_ALLOWED=false` env default. `app/Http/Middleware/ValidateKioskLocale.php` validates request-side locale against `Branch.activeLocales()` but **does not enforce** "must be `fr` when switch_allowed=false" — relies on UI to never send the header. Hostile/curious client can send `X-Kiosk-Locale: en` and pass if branch has multi-locale enabled. Need server-side guard.

### P1-K3 — FrontendOrderService dual-purpose branch resolution edge case
- `app/Services/FrontendOrderService.php:144-151` : `$kioskMachine = KioskMachine::where('user_id', Auth::id())->first();` then fallback to `Auth::user()?->branch_id`. If the **user has multiple kiosks** (test machine + prod machine on same user_id), `->first()` returns arbitrary one — branch_id leak risk if branches mixed. Also `KioskMachine` carries BranchScope (`app/Models/KioskMachine.php:38`) so the query after auth is silently scoped — meaning a kiosk-token user whose `auth->branch_id=0` (admin token) won't find any kiosk → 422. Verify test coverage.

### P1-K4 — Kiosk-token `OrderQuoteService::resolveBranchId` path bypass
- `app/Services/Order/OrderQuoteService.php:164-184` : kiosk surface resolves branch via `KioskMachine::query()->where('user_id', $actor->id)->first()`. If `BranchScope` is active (it is, line 38 of model), and `$actor` is in `branch_id=0` admin context, the kiosk row is filtered out → `HttpException(403)` `'Kiosk quote requires a registered kiosk machine.'`. Counter-intuitive vs login path (which uses `withoutGlobalScope`). Fix : align with login pattern.

### P1-K5 — `routes/api.php:1127-1135` order group lacks `abilities:kiosk:order` route-level guard
- Comment at `routes/api.php:1116-1126` explicitly documents the choice (FormRequest path closes the gap to avoid Sanctum's `CheckAbilities` 401 for session-guard callers). However : `Route::post('/quote', [PosController::class, 'quote'])` at `:1130` reuses **PosController**, not FrontendOrderController. Verify `PosController::quote` enforces kiosk ability when `surface=kiosk` (cf. `OrderQuoteService.php:168` `tokenCan('kiosk:order')` check). Cover via sentinel.

### P2-K6 — Frozen-zone drift vs `main` (audit-only flag)
- `git diff --stat main..HEAD -- resources/js/components/frontend/kiosk/*.vue` :
  - `KioskWizardComponent.vue` +1903 / -257
  - `KioskAppComponent.vue` +1009 / -42
  - `KioskUpsellComponent.vue` +57 / -8
- **Not** a regression — expected per BRAIN §2 (iter1-14 hardening + Wave Z accumulation). Baseline = `reports/audit/ultra-goal-2026-05-13/frozen-zones-baseline.diff` (`HEAD@phase0`). PR-B does **NOT** modify these files. Document the baseline in commit message at merge.

### P2-K7 — Composer profile cache invalidation surface
- Frontend menu fetch `/api/frontend/menu` (route `:1244`) returns `ItemResource` with embedded `composer_profile`. If a chef republishes a profile, the kiosk SPA in-memory store (`kioskMenu.js`) is not invalidated until next poll. Wave 5G `SettingsUpdated` fanout pattern exists — extend to `ComposerProfileChanged` for kiosk Pusher channel.

### P2-K8 — A11y / focus order on touch-screen Wizard
- Wizard step transitions (`KioskStep*Component.vue` series) : verify `aria-live="polite"` on step header + focus moves to step heading on transition. Currently audited as gap (no tests). Frozen — Vitest spec only.

### P2-K9 — Kiosk-locale observability noise
- `ValidateKioskLocale.php:43-49,77-84` logs at `info` level to `observability` channel. High-cardinality `requested` + `route_name` could spam if a misconfigured kiosk client retries. Add a `Cache::add` rate-window or downgrade to `notice` per pair-key.

### P2-K10 — `KioskMachineLoginController::logout` doesn't honor BranchScope
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:117-121` : `KioskMachine::where('user_id', $user->id)->get()` — scoped by BranchScope but user is now authenticated (so scope activates) → if user was switched between branches mid-session, can miss kiosks on the prior branch. Low risk in practice (kiosks pinned to branch) but verify.

### P2-K11 — Kiosk SPA token storage (XSS risk surface)
- `kioskMachine.js` Vuex module uses `axios` + token from server (`KioskMachineResource`). Verify token is stored in `localStorage` only (not `document.cookie`) and that CSP `script-src` restricts inline. Cross-check `CspReportController.php` reports for `frame-ancestors` violations on kiosk URL.

### P2-K12 — `KioskEventController` throttle ceiling
- `routes/api.php:1232,1278` : `throttle:30,1` (30/min). For high-frequency telemetry (idle ping, click trace, error reports), 30/min is tight — Wave Z noted possible drops. Verify with `tests/Playwright/kiosk-order.js` actual emit rate.

---

## §3 Frozen-Zone Constraint Map

| File | Frozen? | PR-B touch? | Sentinel allowed? |
| --- | --- | --- | --- |
| `KioskWizardComponent.vue` | YES (CLAUDE.md §7) | NO | YES (Vitest) |
| `KioskAppComponent.vue` | YES | NO | YES |
| `KioskUpsellComponent.vue` | YES | NO | YES |
| `KioskPosWizardComponent.vue` | YES | NO | YES |
| `KioskCartComponent.vue` | YES (memory) | NO | YES |
| `KioskCategoriesComponent.vue` | YES (memory) | NO | YES |
| `KioskPaymentComponent.vue` | NO | OK if scope-minimal | YES |
| `KioskAppComponent.vue` boot | YES — flagged only |  |  |
| `KioskLoginComponent.vue` / `KioskConfirmationComponent.vue` / `KioskWaitingComponent.vue` / `KioskCashInstructionComponent.vue` / `KioskError*Component.vue` | NO | OK | YES |
| `app/Services/Pricing/PricingService.php` | YES (NF525) | NO heal-only, LOCK if needed | YES |
| `app/Services/FrontendOrderService.php` | NO | OK | YES |
| `app/Services/Order/OrderQuoteService.php` | NO | OK | YES |
| `app/Http/Controllers/Auth/KioskMachineLoginController.php` | NO | OK | YES |

---

## §4 Ultra-Plan — 12 Tasks Ordered by Risk

> Order : NF525 / security first, then UX, then telemetry. Each task = file:line + named test + acceptance.

### T-B1 [P0-K1] Composer profile cache-invalidation sentinel + cross-surface evict
- **Files** : `app/Services/Composer/ComposerProfileService.php:142,149` (read-only), NEW listener `app/Listeners/Composer/EvictKioskComposerCache.php`, NEW broadcast on `private-kiosk.branch.{id}` channel.
- **Pattern** : mirror Wave 5G `PersistSettingsUpdatedToOutbox` (BRAIN §3 "Settings update fanout").
- **Test** : `tests/Feature/Kiosk/ComposerProfileChangedEvictsKioskCacheTest.php` — publish profile → assert event broadcast → kiosk-side mock cache flushed.
- **Acceptance** : `ComposerProfileChanged` event dispatch causes kiosk SPA to refetch `/api/frontend/menu` within 5s; subsequent POST `/api/frontend/order` does NOT trigger "le choix #N n'appartient pas" on a freshly-published profile.

### T-B2 [P0-K1] Pre-submit kiosk client validation against current composer projection
- **Files** : KioskWizardComponent.vue (FROZEN — sentinel only). NEW test `tests/js/kioskComposerSubmitGuard.spec.js` documenting that on `is_published=false` *during* wizard session, the next quote call retriggers menu fetch.
- **Test** : Vitest stub `axios` → simulate 422 with body `Composition : le choix #N n'appartient pas` → assert wizard recovers via menu refetch + retry once + surfaces friendly error.
- **Acceptance** : 422 with this exact message string triggers exactly one retry; second 422 surfaces toast `kiosk.error.menu_drift` (NEW i18n key, see T-B6).

### T-B3 [P1-K2] FR-lock server-side enforcement
- **File** : `app/Http/Middleware/ValidateKioskLocale.php:73` — add early check : if `config('kiosk.locale_switch_allowed')===false` and `$locale !== 'fr'` → return 400 `LOCALE_LOCKED_FR_ONLY`.
- **Test** : `tests/Feature/Kiosk/KioskLocaleFrLockTest.php` — assert 400 + log entry.
- **Acceptance** : `KIOSK_LOCALE_SWITCH_ALLOWED=false` (default) blocks `X-Kiosk-Locale: en|ar|*` even when Branch.available_locales includes them. NO regression when flag=true.

### T-B4 [P1-K3] FrontendOrderService kiosk machine resolution hardening
- **File** : `app/Services/FrontendOrderService.php:144` — replace `where('user_id', Auth::id())->first()` with explicit `tokenCan('kiosk:order')` branch + `withoutGlobalScope(BranchScope::class)` parity with login path.
- **Test** : `tests/Feature/FrontendOrder/KioskMachineMultiBranchResolutionTest.php` — user_id with 2 KioskMachines on different branches → assert 422 (not silent first-pick).
- **Acceptance** : Multiple-kiosks-per-user edge raises 422 with `code=KIOSK_MULTI_MACHINE_AMBIGUOUS`; single-machine path unchanged.

### T-B5 [P1-K4] OrderQuoteService kiosk lookup pattern alignment
- **File** : `app/Services/Order/OrderQuoteService.php:172` — add `withoutGlobalScope(BranchScope::class)` parity with `KioskMachineLoginController.php:55`.
- **Test** : `tests/Feature/Kiosk/KioskQuoteAdminContextTest.php` — actor with kiosk:order ability but branch_id=0 → assert quote succeeds against the kiosk row (no false 403).
- **Acceptance** : Quote path no longer 403s on admin-impersonation kiosk debug flow; branch isolation preserved via downstream `$kiosk->branch_id` enforcement.

### T-B6 [P1-K5] PosController kiosk-surface quote ability sentinel
- **Files** : `app/Http/Controllers/Admin/PosController.php` (verify `quote()` ability), `routes/api.php:1130`.
- **Test** : `tests/Feature/Frontend/QuoteKioskAbilityRequiredTest.php` — token without `kiosk:order` → assert 403, not 200/empty.
- **Acceptance** : `POST /api/frontend/order/quote` with non-kiosk token = 403. Existing kiosk happy path 200 unchanged.

### T-B7 [P1-K2] i18n key-path rebase debt closure
- **Files** : `resources/js/languages/{fr,en,ar}.json` — verify `kiosk.confirmation.*` keys complete; remove any residual `kiosk.wizard.confirmation.*` block; deduplicate `kiosk.promo` block (flagged in memory `project_kiosk_confirmation_i18n_fix`).
- **Test** : `tests/js/kioskConfirmationI18n.spec.js` (already exists, 143 cases) — extend to assert NO `kiosk.wizard.confirmation` key in any locale.
- **Acceptance** : All 3 locale JSONs flat under `kiosk.confirmation.*`; `print_failed` + `print_failed_hint` present in fr/en (ar = best-effort, owner-review).

### T-B8 [P2-K6] Frozen-zone drift baseline doc + sentinel
- **File** : NEW `tests/Feature/Sentinels/KioskFrozenZoneBaselineTest.php` reading `reports/audit/ultra-goal-2026-05-13/frozen-zones-baseline.diff` and asserting LOC sum within ±5% tolerance.
- **Acceptance** : Sentinel green on HEAD; fails on any unauthorized diff vs baseline (catches drift not approved by Owner-Gate).

### T-B9 [P2-K7] ComposerProfileChanged → kiosk Pusher fanout
- **File** : NEW `app/Listeners/Composer/BroadcastComposerProfileToKiosk.php` listening on `ComposerProfileChanged` from `ComposerProfileService.php:142,149,169`.
- **Test** : `tests/Feature/Kiosk/ComposerProfileBroadcastTest.php`.
- **Acceptance** : Republishing profile fires `kiosk.menu.invalidate` Pusher event; verified via Echo mock.

### T-B10 [P2-K9] KioskLocale observability rate-limit
- **File** : `app/Http/Middleware/ValidateKioskLocale.php:43,77` — wrap `Log::channel('observability')` calls with `Cache::add("kiosk_locale_log_{$ip}_{$reason}", 1, 60)` guard.
- **Test** : `tests/Unit/Middleware/KioskLocaleObservabilityRateLimitTest.php`.
- **Acceptance** : 100 identical bad-locale requests in 60s produce ≤ 1 log line per IP+reason pair.

### T-B11 [P2-K8] Wizard a11y focus-order Vitest spec (frozen file, test-only)
- **File** : NEW `tests/js/kioskWizardA11yFocus.spec.js`.
- **Acceptance** : Step transition moves focus to step heading; `aria-live="polite"` region announces step label.

### T-B12 [P2-K10/K11/K12] Cluster — minor hardening
- K10 : `KioskMachineLoginController.php:117` → add `withoutGlobalScope(BranchScope::class)` to logout sweep.
- K11 : verify CSP report channel — no inline `<script>` from kiosk SPA; add Vitest snapshot.
- K12 : `KioskEventController` throttle review — measure actual emit rate via Playwright capture, raise to `60,1` if needed (config-driven).
- **Test** : `tests/Feature/Kiosk/KioskLogoutBranchScopeBypassTest.php` + 1 Playwright soak run.
- **Acceptance** : Logout sweeps ALL user kiosks; CSP zero violations on idle screen; KioskEvent never drops in nominal use.

---

## §5 Test Coverage Gates

- PHPUnit filter : `Kiosk|FrontendOrder|OrderQuote|ComposerProfile|PricingService` — expect ≥ 200 cases post-PR-B (baseline ~150).
- Vitest filter : `kiosk*` — expect ≥ 25 specs (baseline `kioskConfirmationI18n` + Vitest store stubs).
- Playwright : `03-kiosk-wizard.spec.js`, `kiosk-happy-path.spec.js`, `red-b-kiosk-i18n-round3-2026-05-18.spec.js` (already present) — must remain green; ADD `kiosk-composer-drift-recovery.spec.js`.
- NF525 chain : `audit_logs` count + last_hash bit-identical pre/post-PR-B.
- Visual mandate (§6 CLAUDE.md) : capture `kiosk/idle`, `kiosk/menu`, `kiosk/wizard step 2`, `kiosk/confirmation` — Read screenshot, attest layout/raw-label/empty-state intact.

---

## §6 Estimated Wall-Clock + Owner-Gate Surface

- T-B1+T-B2 (P0-K1 composer drift) : 4-5h (2 listeners + 2 sentinels + Vitest stub).
- T-B3+T-B4+T-B5+T-B6 (4 P1 hardening) : 3-4h (small backend edits + 4 PHPUnit tests).
- T-B7 (i18n) : 1h (JSON sweep + extended Vitest).
- T-B8+T-B9 (sentinels + Pusher) : 2h.
- T-B10+T-B11+T-B12 (P2 cluster) : 2h.
- **Total** : ~12-14h focused, single-PR scope.
- **Owner gate** : NONE expected if T-B1 root-cause fix stays in `PricingService::assertComposerSelectionsBelongToPublishedProfile`-adjacent surface (the assertion itself = frozen NF525 SSOT). If T-B1 requires editing `PricingService.php` lines : raise LOCK plan (carteul `LOCK_PRICING_KIOSK_COMPOSER_DRIFT_2026-05-18.md`) before any edit.

---

## §7 Risk Register

1. **NF525 chain integrity** — none of the 12 tasks touch `FiscalSequenceService` / `ZReportService` / `AuditLogService`. Sentinel re-asserts at end of PR.
2. **Frozen-zone discipline** — 9 wizard/cart/upsell files locked. Sentinel T-B8 enforces.
3. **Cross-branch idempotency** — T-B4 must preserve `lockBranchId` namespacing (`FrontendOrderService.php:157`).
4. **Sanctum scope regression** — T-B3 + T-B6 must not break the documented FormRequest-vs-route-middleware decision (`routes/api.php:1116-1126`).
5. **i18n rebase landmine** — T-B7 must verify `clever-hypatia-1e4f84` migration is fully merged into `v1-0-1-hardening-2026-05-17` (cross-check `git log resources/js/languages/fr.json`).
6. **PR-A overlap** — confirm PR-A (POS Caisse) does not also touch `OrderQuoteService.php` / `PricingService.php` in the same window; coordinate merge order.

---

**End of PR-B Ultra-Plan.** Target deliverable: 1 branch `pr-b/borne-kiosk-2026-05-18`, 12 commits (1 per task), each with sentinel + acceptance attestation.
