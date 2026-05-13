# K20 — Cross-Cutting Integrator (NF525 + Branch isolation + Sanctum + Frozen drift + a11y/i18n)

> Branch `feature/mobile-app-le-cayenne-2026-05-10` — actual HEAD at run = `6a33a9763` (prompt cited `245e8ab57`, drift recorded).
> Mode: read-only, cross-domain ONLY. Single-domain findings deferred to K01-K19.

## Files audited (cross-domain seams only)
- `resources/js/store/modules/kioskCart.js` (~830 lines) — quote build, submit, offline replay
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (cited only for quote re-issue path)
- `app/Services/Order/OrderQuoteService.php` (~450 lines) — HMAC quote SSOT
- `app/Services/Pricing/PricingService.php` (FROZEN — observed for contract boundary only)
- `app/Services/Pricing/CompositionSnapshotBuilder.php` (writer)
- `app/Models/OrderItem.php:44,71` — `composition_snapshot` fillable + array cast
- `app/Http/Resources/OrderItemResource.php:31-104` — read boundary
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:98-102` — token mint
- `app/Http/Controllers/Auth/RefreshTokenController.php:42-53` — P0-07 fix verified
- `app/Http/Requests/OrderRequest.php:35-66` — FormRequest `kiosk:order` ability gate
- `routes/api.php:255-1265` extracts — middleware mapping admin vs kiosk
- `routes/channels.php` (40 lines) — broadcast channel ability gate
- `lang/{fr,en,ar}/all.php` + `resources/js/languages/{fr,en,ar}.json` — i18n parity
- 7 frozen-zone files via `git diff main..HEAD --numstat` (table below)

---

## §A — NF525 Pricing SSOT contract: PASS (defense-in-depth verified)

### Trace (KioskWizard → kioskCart → POST /api/frontend/order → PricingService::calculateOrder)

1. **Wizard build** (`KioskWizardComponent.vue`, FROZEN-but-drifted) emits `{ item_id, quantity, item_variations: [{item_variation_id, item_variation_option_id, ...}], item_extras: [{item_extra_id, quantity}] }`. No `price`/`total`/`subtotal` field is the basis of pricing — local `convert_price`, `item_variation_total`, `item_extra_total` are kept for **display only**.
2. **Cart store** (`resources/js/store/modules/kioskCart.js:142-180`):
   - `buildKioskQuotePayload` sends `items: JSON.stringify(...sanitizeKioskOrderItem)` containing strictly `item_id, item_variations[], item_extras[], quantity, instruction, customizations`. No price.
   - `buildKioskOrderPayload` (line 160-180) DOES add `subtotal/discount/delivery_charge/total` from the **server-signed quote** (`quote_token + quote_signature` lines 170-176). These are NOT trusted by backend for pricing — they participate in HMAC verification only.
3. **Backend POST /frontend/order**:
   - `OrderQuoteService::sealForCommit(...)` (line 109-125): re-runs `calculatePricing()` (line 118 calls `$this->quote(...)` which calls `PricingService::calculateOrder`) and **compares** server-computed total to client-sent `expectedTotal`. Mismatch → `HttpException(409)` (line 121). Quote signature verified via `resolveReplay` lines 330-355 using `hash_hmac('sha256', $canonicalJson, $this->hmacKey())`.
   - `PricingService::calculateOrder` rebuilds prices from `Item::find($requestedItemIds)` server-side.
4. **`composition_snapshot` write boundary**: built by `CompositionSnapshotBuilder` inside `PricingService::calculateOrder` (line 266-291 of FROZEN service), persisted at `app/Services/OrderService.php:455, 810, 1241` and `app/Services/FrontendOrderService.php:441` via `'composition_snapshot' => json_encode($compositionSnapshot)` **inside the create transaction**. No UPDATE/PATCH path writes this column — `app/Models/OrderItem.php:44` lists it as fillable but only `create()` calls reference it (grep confirmed). Refund path (`app/Services/Order/RefundWithCounterEntryService.php:135`) **copies** the original snapshot to the counter-entry row — does NOT mutate.

### Verdict: **PASS** — frontend cannot poison pricing; client `total` participates only in HMAC mismatch detection.

### Cross-domain Risk K20-CD-01 (P1)
Offline replay path (`kioskCart.js:776-779`) strips `quote_token/quote_signature/subtotal` before queueing. Once back online, the replay layer regenerates a fresh quote (per comment line 772). **No automated test asserts the replay always re-quotes before POST** — a future refactor could short-circuit. Suggested: add a sentinel `KioskOfflineReplayMustReQuoteTest` asserting the queued payload sent to `/frontend/order` carries a fresh `quote_token` not equal to the pre-offline one.

---

## §B — Branch isolation cross-surface: PASS (1 P1 weakness already flagged by K16)

### Trace
- Token mint (`KioskMachineLoginController.php:98-102`): `$user->createToken('kiosk-token', ['kiosk:order'], ttl)` where `$user = User::find($kioskMachine->user_id)`. The User row carries `branch_id` of the machine implicitly (via business rule, not enforced at token level).
- Pre-auth lookup uses `KioskMachine::withoutGlobalScope(BranchScope::class)` (line 55, 90) — **explicitly justified** with iter12 P1 comment. Branch is resolved server-side in `OrderQuoteService::resolveBranchId` (lines 164-201) via `KioskMachine::where('user_id', $actor->id)->first()`.
- Order create: `app/Services/OrderService.php` and `FrontendOrderService.php` rely on `BranchScope` for read/write boundary on `OrderItem`/`Order`/`OrderPayment` (FROZEN scope, 0-line diff confirmed).
- Broadcast: `routes/channels.php:25-39` correctly gates kiosk tokens to their own `KioskMachine::branch_id` (lines 27-30).

### Verdict: **PASS** with K16-P1-01 caveat documented (defense-in-depth weakness on `PosOrderController::show:108`).

### Cross-domain Risk K20-CD-02 (P1)
No CI invariant grep guards against `withoutGlobalScope(BranchScope::class)` regressing into hot paths. Suggested: add `tests/Architecture/BranchScopeBypassGuard.php` (Pest arch test or Bash grep in CI) that whitelists exactly the 4 known legitimate sites (`KioskMachineLoginController` x2, `PosOrderController::show:108`, broadcast `App\Models\User` channel) and fails on any new occurrence.

---

## §C — Sanctum ability leakage: PARTIAL PASS (1 P1 latent design flaw)

### What works (confirmed)
- `P0-07` RefreshTokenController fixed at `RefreshTokenController.php:42-53` — `$abilities` array preserved, no wildcard injection.
- `P0-08` FormRequest pattern: `app/Http/Requests/OrderRequest.php:35-66` checks `$user->tokenCan('kiosk:order')` for any `PersonalAccessToken` holder. TransientToken/guard tolerated for tests. K16 sentinel `tests/Feature/Frontend/OrderRouteAbilityTest.php` covers this.
- Broadcast channel: ability check at `routes/channels.php:27-30` properly restricts kiosk tokens.
- Kiosk-event throttle endpoints (api.php:1218, 1264) have explicit `abilities:kiosk:order` route middleware.

### Cross-domain Risk K20-CD-03 (P1) — **The Admin-routes-don't-check-abilities pattern**
- `routes/api.php:255, 269` admin route groups use `middleware(['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:*'])` — **no `abilities:` check**.
- Defense is delegated to per-controller Spatie `->can('pos')`, `->can('items_show')`, `abort_unless($user->can(...))` (35+ occurrences in `app/Http/Controllers/Admin/`).
- A kiosk token (`['kiosk:order']` ability) calling, e.g., `POST /api/admin/menu/availability/toggle` would PASS `auth:sanctum`. The check `auth()->user()?->can('pos')` (api.php:730, 746, etc.) relies on the User row's Spatie roles. In production, the kiosk-machine User is **not supposed to** carry the `pos` role — but this is a **configuration invariant**, not a code-enforced one. A misconfigured tenant where the kiosk machine's owning User has the `pos` permission would silently expose admin mutations to any kiosk PIN holder.

**Suggested fix**: add a generic middleware `EnsureNotKioskToken` (or `abilities:*` on admin groups, since admin tokens carry `['*']`) to fail-closed at the route layer. Effective change is a single-line middleware injection on `admin.*` route groups. CI sentinel: `tests/Feature/Auth/KioskTokenCannotCallAdminTest.php` asserting POST `/api/admin/menu/availability/toggle` with `Bearer <kiosk-token>` → 403.

---

## §D — `composition_snapshot` frozen integrity: PASS

### Read+write boundary
- **Write sites (4 total, all create-path inside transactions):**
  - `app/Services/Pricing/PricingService.php:291` (SSOT builder)
  - `app/Services/OrderService.php:455, 810, 1241`
  - `app/Services/FrontendOrderService.php:441`
- **Read sites (no mutation):**
  - `app/Http/Resources/OrderItemResource.php:31-104` (legacy fallback to raw JSON)
  - `app/Http/Resources/KDSOrderItemsResource.php:31-35`
  - `app/Http/Controllers/Admin/PosOrderController.php:211, 243`
  - `app/Services/KitchenDisplaySystemOrderService.php:265`
  - `app/Services/Stock/StockService.php:280`
  - `resources/js/helpers/kdsCustomization.js:96, 105, 113` + `posReceiptBuilder.js:149`
- **Refund/counter-entry path** (`app/Services/Order/RefundWithCounterEntryService.php:135`): COPIES the original `$item->composition_snapshot` to the new counter-entry row — does NOT mutate the original.
- **No UPDATE/PATCH** writes this column anywhere in `app/`. `OrderItem.php:44,71` lists fillable + array cast — fillable is acceptable because no code path passes the column to `update()`. Verified via grep: zero matches for `update(['composition_snapshot'`, `->composition_snapshot =`, `->forceFill(['composition_snapshot`.

### Verdict: **PASS** — `composition_snapshot` is set ONCE at create, never re-written.

### Cross-domain Risk K20-CD-04 (P2)
The frozen-by-convention guarantee is **not enforced by a model observer**. Suggested: an `OrderItem` observer that aborts on `updating` if `isDirty('composition_snapshot')`, with a Feature test sentinel. Defense-in-depth only — current code paths are clean.

---

## §E — i18n key coverage cross-locale: 1 P1 + 1 P2

### Counts (resources/js/languages/{fr,en,ar}.json — Vue i18n SSOT)
- FR has **604** `kiosk.*` keys
- EN has **596** (9 missing vs FR)
- AR has **592** (13 missing vs FR)

### Cross-domain Risk K20-CD-05 (P1) — Latent EN/AR gap masked by FR-lock
EN-only-missing keys (8 of 9): `kiosk.consent.privacy_body`, `kiosk.max_quantity_reached`, **`kiosk.promo.applied`**, **`kiosk.promo.apply`**, **`kiosk.promo.label`**, **`kiosk.promo.loading`**, **`kiosk.promo.placeholder`**, + `kiosk.filters.` (broken stub key).
AR has the same 8 + 4 more.

The **entire `kiosk.promo.*` cluster** is FR-only. `resources/js/i18n.js:21` enforces `KIOSK_LOCALE='fr'` at runtime, so production never sees raw labels — **but** the audit memory `project_kiosk_confirmation_i18n_fix` notes the FR-lock is "résiduel" (composables/useKioskA11y can still propagate non-FR via persisted store). If FR-lock is ever loosened (V1.x multi-locale unlock), AR/EN kiosk would display raw `kiosk.promo.apply` keys.

**Suggested fix**: backfill 9 EN + 13 AR keys (≤20 lines diff per locale). Add a Vitest test `tests/js/kioskI18nParity.spec.js` that asserts `Object.keys(kioskKeys_fr) ⊆ kioskKeys_en ∧ ⊆ kioskKeys_ar`.

### `kiosk.confirmation.*` migration verified
- FR has **23** `kiosk.confirmation.*` keys, **0** `kiosk.wizard.confirmation.*` keys. K11 PASS confirmed independently. Memory `project_kiosk_confirmation_i18n_fix` migration was actually applied.

### Cross-domain Risk K20-CD-06 (P2) — Broken stub key
`kiosk.filters.` (FR) is a malformed key terminating in a dot — likely a leftover serialization artifact. Single key, low severity, but flags a parity-check gap.

---

## §F — Frozen-zone drift GLOBAL summary (CRITICAL)

`git diff main..HEAD --numstat` at HEAD `6a33a9763`:

| File | Insertions | Deletions | Status vs CLAUDE.md §7 |
|---|---:|---:|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | **1663** | **228** | **VIOLATED** (frozen — owner gate required) |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | **834** | **175** | **VIOLATED** |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | **31** | **26** | **VIOLATED** (small, design refresh) |
| `public/js/pos-wizard.js` | **216** | **21** | **VIOLATED** (POS frozen — composer-aware path) |
| `app/Services/Pricing/PricingService.php` | **593** | **17** | **VIOLATED** (NF525 frozen — composer projection wiring) |
| `app/Services/Fiscal/FiscalSequenceService.php` | 0 | 0 | OK |
| `app/Models/Scopes/BranchScope.php` | 0 | 0 | OK |
| **TOTAL** | **3337** | **467** | **5 frozen files violated, 3804 net touched** |

### Verdict: **PROJECT_BRAIN.md §2 claim of "0 lines diff frozen-zones" is FALSE.**

### Cross-domain Risk K20-CD-07 (P0) — Frozen-zone breach without `LOCK_*.md` doc
- `plans/` directory contains NO `LOCK_KIOSK_WIZARD_*.md` or `LOCK_POS_WIZARD_*.md` matching this branch.
- CLAUDE.md §7 declares modification requires "gate explicite owner ou test régression triple-vert". No LOCK doc, no triple-vert evidence in commit messages of frozen files (`a220b9bd8`, `6d94acd13`, etc.).
- The `PricingService` drift (+593/-17) is especially sensitive because it's NF525-critical and the diff adds 4 new dependencies (`AvailabilityService`, `CompositionSnapshotBuilder`, `ComposerProfileProjection`, `ChoiceAvailabilityResolver`). A misconfigured DI binding could silently change pricing semantics.

**Suggested fix**: stop-the-line. Either:
1. Revert the 5 frozen files to `main` baseline and reapply changes through formal LOCK process, OR
2. Produce retro-LOCK docs (`LOCK_KIOSK_WIZARD_2026-05-10.md`, `LOCK_POS_WIZARD_2026-05-10.md`, `LOCK_KIOSK_APP_2026-05-10.md`, `LOCK_KIOSK_UPSELL_2026-05-10.md`, `LOCK_PRICING_SERVICE_2026-05-10.md`) with owner sign-off + Pest sentinels asserting NF525 invariants on PricingService.

---

## §G — A11y WCAG 2.1 AA + EAA 2025 systemic patterns

### Counts across kiosk components (15 .vue files, ~6500 lines)
- `aria-label` / `aria-labelledby` / `aria-describedby`: **118 occurrences**
- `role="button|dialog|status|alert"`: **43 occurrences**
- Files with `alt=` attributes: **15** (all `<img>` with alt detected; 0 systemic missing-alt)
- Non-semantic `@click` on `<div|span|li>` (without `role="button"`): **2 cases**, both `@click.self` overlay dismissers (`KioskWaitingComponent.vue:109, 122`) — pattern is intentional (close-on-backdrop) but should also accept `Escape` key.

### Cross-domain Risk K20-CD-08 (P2) — Systemic overlay-dismiss missing keyboard parity
- 2 overlay-dismiss patterns (`@click.self`) do NOT have matching `@keydown.escape` or `@keyup.escape` handlers. Single-file each, but the pattern is replicated → "systemic" by design.
- WCAG 2.1.1 (Keyboard) violation for keyboard-only users on those 2 overlays (timeout dismiss + cancel-confirm).
- Suggested fix: add `@keydown.esc.self` on both elements; or pull into a shared `KsBackdrop` component.

### Cross-domain Risk K20-CD-09 (P2) — sr-only label class shared across components without typed slot
The "sr-only" pattern (`kiosk-wizard-sr-only`, etc.) is duplicated rather than extracted into `KsScreenReaderOnly` slot. Cross-domain because design system (`ds/Ks*`) already has primitives — divergence reduces a11y review surface but increases maintenance. Low severity, V1.0.1 sprint candidate.

---

## Top 3 cross-domain risks (synthesis)

1. **K20-CD-07 (P0 — Frozen-zone breach without LOCK)** — 5 frozen files, +3337/-467 lines, including NF525-critical `PricingService.php` (+593/-17). **Block pre-merge V1** until LOCK docs or revert.
2. **K20-CD-03 (P1 — Admin-routes-don't-check-Sanctum-abilities)** — kiosk token could call `/api/admin/*` if its owning User row holds Spatie `pos`/admin permissions. Configuration-invariant only, not code-enforced. Single-middleware fix, but requires CI sentinel.
3. **K20-CD-05 (P1 — Latent EN/AR i18n gap)** — `kiosk.promo.*` cluster is FR-only (8 keys missing in EN, 12 in AR). Masked by FR-lock, but a V1.x multi-locale unlock would expose raw key labels. Sentinel + 22-line backfill.

## Verdict

**NO-GO V1 merge** on grounds of K20-CD-07 (frozen-zone breach without LOCK process). Other findings are P1/P2 healable on V1.0.1 timeline.

NF525 pricing contract: **Y** (PASS).
Branch isolation: **Y** (PASS with K16-P1-01 caveat).
Ability leak (admin routes): **N partial** (kiosk token → admin routes not gated at route layer; relies on Spatie config).
Frozen drift TOTAL across 7 files: **+3337 / -467 lines** (5 files violated).

## Existing E2E coverage (cross-domain seams)
- `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php` — P0-07 regression (4 cases)
- `tests/Feature/Frontend/OrderRouteAbilityTest.php` — P0-08 ability gate
- `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php` — K16-P1-01 sentinel
- `tests/Feature/Sentinels/F008PaymentReconcileAbilitySentinelTest.php` — kiosk:order on `/payment-confirm`
- `tests/js/kioskFrLockImmutable.spec.js` — FR-lock at runtime

## Proposed new cross-domain E2E tests
- **T-K20-01 (NF525 SSOT)**: client posts `/frontend/order` with `total: 0.01` (tampered) but valid `quote_token + quote_signature`. Assert 409 with `Order total does not match sealed quote total.` (covers `OrderQuoteService::sealForCommit` line 121).
- **T-K20-02 (Ability leak)**: with a kiosk `Bearer` token, POST `/api/admin/menu/availability/toggle` with valid payload. Assert 403 regardless of underlying User Spatie role (forces route-level gating).
- **T-K20-03 (composition_snapshot immutable)**: feature test attempts `OrderItem::find($id)->update(['composition_snapshot' => '[]'])` — assert observer aborts or column is read-only post-create.
- **T-K20-04 (Branch isolation invariant)**: arch test (Pest) failing on any new `withoutGlobalScope(BranchScope::class)` outside the 4-site whitelist.
- **T-K20-05 (i18n parity)**: Vitest asserting `keys('kiosk.*') in fr.json ⊆ en.json ⊆ ar.json`.

## Risks & open questions for owner gate
- The frozen-zone drift accumulated across multiple sprints (`feat(kds/sprint-2)`, `feat(kiosk/v2)`) **without** any `LOCK_*.md` in `plans/`. Owner needs to decide: retroactive LOCK doc + acceptance, or revert and re-apply through formal process. PROJECT_BRAIN.md §2 wording needs correction.
- `PricingService.php` (+593/-17) adds 4 dependencies (DI nullable defaults). Owner must confirm the change set passes NF525 invariant (composition_snapshot frozen, no client-trusted prices) — current evidence says yes, but a formal NF525 sentinel pass is not in this PR.
- Admin-routes-Sanctum-abilities gate: confirm with owner whether route-level `abilities:*` is acceptable or whether documented Spatie-role contract is the intended defense (i.e. kiosk-machine User MUST NOT have admin perms by ops policy).
