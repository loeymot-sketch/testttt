# PR-B Borne / Kiosk — Ultra-Review Verdict (2026-05-18)

> Read-only deep audit by 5-perspective subagent on branch `v1-0-1-hardening-2026-05-17`, HEAD `a34d1f696`. Plan reviewed: `plans/ultra-plans-2026-05-18/PR_B_BORNE_KIOSK_ULTRA_PLAN_REVIEW_2026-05-18.md`.

---

## §1 Verdict

**GO-CONDITIONAL with one P0 escalation.** The plan is largely accurate but **omits a P0 security finding** (kiosk credentials exposed unauthenticated on `/kiosk/*` URLs via `master.blade.php` template) that must be addressed before V1 production cloud-deploy. PR-B's 12 tasks remain valid; one MUST be added (T-B0 below). With T-B0 fixed, PR-B converges to GO.

**Counts** : 1 P0 plan-anchored confirmed · 1 P0 NEW · 4 P1 confirmed (1 already partially closed) · 6 P2 mostly confirmed. Plan estimate `12-14h` becomes `14-18h` with T-B0 added.

---

## §2 Plan-Findings Status

| Plan ID | Title | Verdict | Notes |
|---|---|---|---|
| **P0-K1** | Composer profile drift "le choix #N" | **CONFIRMED EXACT** | `PricingService.php:693` throw verified character-perfect. Throw site is `assertComposerSelectionsBelongToPublishedProfile` (line 659-699), invoked from line 603 inside `validateRequestItems`. Sub-cause analysis is sound. Plan correctly disambiguates root from `FrontendOrderService`. Minor: plan cites `ComposerProfileService.php:142,149` — actual dispatch lines are 142 (`updated`), 169 (`published`), 245 (`unpublished`). The 149 is incorrect, should be 169. Listener `InvalidateKioskMenuCacheOnCatalogChange` IS wired (EventServiceProvider line 223) — server cache invalidated; **SPA in-memory cache is NOT** — gap legitimate. |
| **P1-K2** | i18n key-path + FR-lock | **PARTIALLY CLOSED + 1 still-valid sub-finding** | `kiosk.wizard.confirmation.*` residual = **0 hits** across all 5 locale JSONs (`grep -c kiosk.wizard.confirmation resources/js/languages/*.json`). The rebase landmine is closed. **FR-lock sub-finding is still valid**: `ValidateKioskLocale.php:31-98` only validates against `Branch.activeLocales()`, never against `config('kiosk.locale_switch_allowed')`. Hostile client can send `X-Kiosk-Locale: en` on a branch with multi-locale enabled and pass even when V1 FR-lock is `false`. ADR-007 enforcement is UI-only, not server. |
| **P1-K3** | FrontendOrderService multi-kiosk-per-user edge | **CONFIRMED** | `app/Services/FrontendOrderService.php:144,184` — both query `KioskMachine::where('user_id', Auth::id())->first()` without ordering. Combined with `BranchScope` (active post-auth), if `Auth::user()->branch_id=0` (admin), the scope no-ops (`BranchScope.php:33-35` — admin sees all branches), so `->first()` returns the lowest-id kiosk arbitrarily. Risk is leak of branch context on admin-impersonation only. For non-admin staff with a single kiosk, no impact. |
| **P1-K4** | OrderQuoteService kiosk lookup bypass | **CONFIRMED** | `app/Services/Order/OrderQuoteService.php:172-184` — `KioskMachine::query()->where('user_id', $actor->id)->first()` runs under BranchScope (model line 38). When `$actor->branch_id != $kiosk->branch_id` the row is filtered → 403 "Kiosk quote requires a registered kiosk machine." Login path uses `withoutGlobalScope(BranchScope::class)` (line 55, 90 of `KioskMachineLoginController.php`). The pattern asymmetry is real. Severity P2-low in practice (kiosk users are typically pinned to their branch), but the fix is one-line alignment. |
| **P1-K5** | `/api/frontend/order/quote` ability sentinel | **PARTIALLY CONFIRMED with NEW nuance** | Route `:1130` correctly maps to `PosController::quote`. Reading the handler (`app/Http/Controllers/Admin/PosController.php:164-215`): on `api/frontend/*`, the `permission:pos` gate is **explicitly bypassed** (line 172-174), then `OrderQuoteService::resolveBranchId` (line 167-170) checks `tokenCan('kiosk:order')` — **but only when `$token !== null`**. Session/guard-auth (TransientToken-free) bypasses the ability check entirely. Practical impact: an admin web-session user could call `/api/frontend/order/quote` without a kiosk token and only fail on the kiosk-machine row lookup. Sentinel ABSOLUTELY needed. |
| **P2-K6** | Frozen-zone drift vs main | **CONFIRMED EXACT** | `git diff --stat main..HEAD -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue` = `+1903 / -257`. `KioskAppComponent.vue` = `+1009 / -42`. `KioskUpsellComponent.vue` = `+57 / -8`. Plan numbers verified character-perfect. Baseline rationale (iter1-14 + Wave Z accumulation, `HEAD@phase0` snapshot) aligned with `PROJECT_BRAIN.md §2`. Sentinel T-B8 is the right defense. |
| **P2-K7** | Composer cache invalidation surface | **CONFIRMED** | `InvalidateKioskMenuCacheOnCatalogChange` (`app/Listeners/`) fires on ComposerProfileChanged but only `Cache::forget('kiosk.menu.branch.{id}')` + snapshot bump. No Pusher broadcast to kiosk SPA. The SPA refetches `/api/frontend/menu` lazily on next poll or navigation. Until then, `resolvedItem.composer_profile` in `KioskWizardComponent.vue:779` returns stale data. |
| **P2-K8** | Wizard a11y focus order | **PRESUMED OPEN** | Not deep-read in this audit window; consistent with documented frozen-zone-test-allowed posture per `feedback_kiosk_wizard_frozen_tests_allowed`. Add Vitest spec per plan T-B11. |
| **P2-K9** | KioskLocale observability noise | **CONFIRMED** | `ValidateKioskLocale.php:43-49,77-84` writes to observability channel at info level on every bad-locale. Cardinality control absent. T-B10 fix straightforward. |
| **P2-K10** | KioskMachineLoginController logout scope | **CONFIRMED EXACT** | Line 117-121: `KioskMachine::where('user_id', $user->id)->get()` runs under BranchScope (model line 38). Inconsistent with login path which uses `withoutGlobalScope(BranchScope::class)` (line 55, 90). Edge-case risk if a user is moved between branches. One-line fix. |
| **P2-K11** | Kiosk SPA token storage | **CONFIRMED** | `resources/js/store/modules/kioskCart.js:384,392` — `state.kioskToken` mutation. `resources/js/store/index.js:267` — `"kioskCart.kioskToken"` persisted via vuex-persistedstate (localStorage). Stored in localStorage = XSS-stealable. Mitigations rely on CSP `script-src` (verify via T-B12). |
| **P2-K12** | KioskEvent throttle ceiling | **CONFIRMED** | `routes/api.php:1232,1278` `throttle:30,1`. Tight for high-frequency telemetry. Plan T-B12 measurement-driven approach is correct. |

---

## §3 NEW Findings (not in plan)

### **P0-NEW1 — Kiosk machine credentials exposed unauthenticated on `/kiosk/*` URLs**

- **Anchor** : `resources/views/master.blade.php:113`, `app/Http/Controllers/Frontend/RootController.php:14-20`, `routes/web.php:62`, `config/kiosk.php:142-145`
- **Mechanism** : `routes/web.php:62` catch-all `Route::get('/{any}', [RootController::class, 'index'])->middleware(['installed'])` matches `/kiosk/*` with **no auth, no IP allowlist, no kiosk-machine cookie gate**. `RootController::index` renders `master.blade.php`. Line 113 injects `kioskAutoLogin: @json(request()->is('kiosk*') ? config('kiosk.spa_payload') : null)`. `config('kiosk.spa_payload')` returns `{username, password}` (cleartext) sourced from env `KIOSK_MACHINE_USERNAME` / `KIOSK_MACHINE_PASSWORD`. In `APP_ENV=local`, falls back to hardcoded `kiosk-lecayenne / kiosk123` (config/kiosk.php:133-140).
- **Exploit path** :
  1. `curl https://prod-host.fr/kiosk/idle` (or any `/kiosk/*` URL).
  2. Receives full SPA HTML with `window.foodkingConfig.kioskAutoLogin = {"username":"...","password":"..."}` in `<script>` block.
  3. Use those credentials against `/api/auth/kiosk-login` to mint a `kiosk:order` Sanctum token.
  4. Place fake orders, scrape menu, abuse upsell endpoints, etc.
- **Severity** : **P0** — credential exposure on a network-reachable host bypasses physical-kiosk threat model. Owner-physique action items already include AWS key rotation (commit `a4a88df06`); add kiosk credential isolation to that list.
- **Suggested mitigation (scope-minimal)** :
  - **Option A (preferred)** : Inject `kioskAutoLogin` only when request matches `kiosk*` AND a server-side runtime guard passes (IP allowlist via `config('kiosk.allowed_ips')` and/or local network only).
  - **Option B** : Move kiosk machine auth to a **client-certificate** (mTLS) gate so the auto-login payload is never in HTML.
  - **Option C (V1 quick-fix)** : Stop persisting `KIOSK_MACHINE_USERNAME/PASSWORD` in env; require manual machine login on first boot with one-time secret. SPA stores token in localStorage as today.
- **Sentinel** : NEW PHPUnit feature test `tests/Feature/Kiosk/KioskAutoLoginPayloadUnauthenticatedAccessTest.php` — `GET /kiosk/idle` (no auth, no cookies) → assert response does NOT contain `kioskAutoLogin` with non-null `password` field. Fail-closed by default.

### **P2-NEW2 — `MenuController::kiosk` cache key not scoped by locale**

- **Anchor** : `app/Http/Controllers/Frontend/MenuController.php:67` — `$cacheKey = "kiosk.menu.branch.{$branchId}"`.
- **Concern** : The `kiosk.locale` middleware can carry `X-Kiosk-Locale: en` / `ar`, but the cache key isn't qualified by locale. If `KioskMenuService::build($branch)` returns locale-dependent strings (item names, category labels) and the cache fills on a `fr` request, subsequent `en` requests get the `fr` payload until cache expires (60s default).
- **Severity** : P2 / UX bug — not security.
- **Suggested mitigation** : Suffix cache key with the resolved locale; or document why `KioskMenuService::build` is locale-invariant.

### **P2-NEW3 — `PricingPreviewController::preview` ignores kiosk-machine token requirement at controller layer**

- **Anchor** : `app/Http/Controllers/Frontend/PricingPreviewController.php:33-44` — `$user = $request->user()` then `KioskMachine::query()->where('user_id', $user->id)->first()`.
- **Concern** : The controller does NOT call `tokenCan('kiosk:order')` — it relies on `PricingPreviewRequest::authorize()` (`app/Http/Requests/Kiosk/PricingPreviewRequest.php:28` returns `$user && $user->tokenCan('kiosk:order')`). That's correct, BUT a session-guard user with TransientToken passes `tokenCan()` because TransientToken returns true for any ability. So a web admin in branch_id=0 *could* hit `/api/frontend/pricing/preview` and 503 only because they have no kiosk machine row. Defense-in-depth missing.
- **Severity** : P2 — leverage gated by lack of `KioskMachine` row; still recommend explicit `if ($user->currentAccessToken() !== null && ! $user->tokenCan('kiosk:order'))` 401.

---

## §4 Acceptance Critique

Per task in plan §4:

- **T-B1 / T-B2 (P0-K1)** : Approach sound. Caveat — broadcasting `kiosk.menu.invalidate` on Pusher requires Echo client wiring on the kiosk SPA. Verify `kioskMenu.js` listens for the event or accept that the listener will be added in T-B9 (plan T-B1+T-B9 should commit together).
- **T-B3 (P1-K2 FR-lock)** : Acceptance criteria pass; ADR-007 alignment. Suggest also blocking the `?lang=` query path symmetrically — currently `extractLocale` reads both `header` and `query` (line 100-111).
- **T-B4 (P1-K3)** : Plan's proposed fix introduces a `KIOSK_MULTI_MACHINE_AMBIGUOUS` 422. Better: deterministic `->orderBy('branch_id')->first()` documented intent OR enforce DB UNIQUE (user_id, branch_id) on kiosk_machines table. Status quo silent-pick can be replaced by deterministic-pick at lower regression risk than 422.
- **T-B5 (P1-K4)** : Direct one-line alignment. LGTM.
- **T-B6 (P1-K5)** : Sentinel essential. Also need to assert TransientToken bypass closed (cf NEW3 above).
- **T-B7** : i18n closure mostly redundant on this HEAD (verified — kiosk.wizard.confirmation.* = 0 hits). Reduce scope to assertion-only.
- **T-B8 (P2-K6)** : Sentinel design pattern correct. Verify the baseline diff file exists or create it pre-merge.
- **T-B9 (P2-K7)** : Pair with T-B1.
- **T-B10 (P2-K9)** : LGTM.
- **T-B11 (P2-K8)** : LGTM. Frozen-zone-test-allowed pattern.
- **T-B12 cluster (K10/K11/K12)** : LGTM. K10 one-line `withoutGlobalScope` add.

**MUST-ADD T-B0** : Address P0-NEW1 kiosk credential exposure. Estimate 2-4h depending on Option A/B/C chosen. Owner-gate decision recommended.

---

## §5 Frozen-Zone Drift Status

PR-B touches NONE of the 9 frozen kiosk frontend components. Confirmed:

```
git diff --stat HEAD -- resources/js/components/frontend/kiosk/
  (nothing in this branch since main divergence touches the frozen six)
```

All wizard logic concerns are addressed via:
- Backend services (FrontendOrderService, OrderQuoteService, PricingService — frozen, audit-only)
- New listeners (composer cache fanout)
- New sentinels (Vitest specs on frozen Vue files)
- Non-frozen kiosk SFCs (KioskPaymentComponent, KioskLoginComponent, KioskConfirmationComponent, Error variants) — none touched by current plan tasks.

`PricingService.php` (frozen NF525): NO edit needed because T-B1 fixes the upstream cache invalidation, not the assertion. If a future task needs to mutate the assertion or composer projection, LOCK plan required per CLAUDE.md §7 — plan §6 already states this.

---

## §6 Owner-Gates

- **OG-B1 (NEW, P0-NEW1)** — Choose mitigation strategy for kiosk credential exposure (Option A / B / C above). Coordinate with Owner-physique 10-action checklist that already includes AWS key rotation. **Blocking V1 prod cloud-deploy if Option C dropped without alternative.**
- **OG-B2 (plan §6)** — None expected if PR-B stays out of `PricingService.php`. Confirmed: T-B1 + T-B2 don't need PricingService edits.
- **OG-B3 (i18n)** — None needed (i18n migration already converged on this HEAD).
- **OG-B4 (frozen-zone test additions)** — Vitest specs on `KioskWizardComponent.vue` allowed per `feedback_kiosk_wizard_frozen_tests_allowed`. No gate.

---

## §7 Final Recommendation

**GO-CONDITIONAL on PR-B** with three actions:

1. **MUST**: Add T-B0 to close P0-NEW1 (kiosk credential exposure on `/kiosk/*` URLs). Owner-gate the mitigation strategy choice (OG-B1).
2. **MUST**: Implement T-B1 + T-B2 + T-B9 as a single coordinated commit (server cache evict + Pusher fanout + SPA listener + composer-drift sentinel).
3. **SHOULD**: Reduce T-B7 scope to assertion-only sentinel (i18n already converged); reallocate the saved 1h to T-B0.

Estimated revised wall-clock : **14-18h** including T-B0.

Frozen-zone discipline absolute (0 touch). NF525 chain unchanged (no PricingService edits planned). Branch isolation: PR-B improves it via T-B4 + T-B5 + T-B6 + K10 alignment.

**End of PR-B Verdict.**
