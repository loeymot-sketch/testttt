# X1 — Duplication Cross-Cutting Audit (FoodKing, 2026-05-17)

Auditor scope: read-only sweep across 15 systems for duplicated code, dead code, copy-paste leftovers, and multiple implementations of the same logic. Anti-drift discipline: every duplication pair below cites file:line.

---

## §0 Stale findings (reported as duplicated but actually distinct)

These were flagged in passing by earlier agents or by the prompt itself but, upon inspection, turn out to be intentional or non-overlapping. Recording them so the master consolidator does not double-count.

1. **`resources/js/components/admin/pos/v5/` is NOT abandoned.**
   The directory holds 9 small leaf components (`PosV5Button`, `PosV5Numpad`, `PosV5TrancheRow`, `PosV5StatChip`, `PosV5QtyStepper`, …) and is actively imported by the production POS surface — see `resources/js/components/admin/pos/PosComponent.vue:56-138` and `PaymentComponent.vue:132,252,336`. These are the shared atom-level building blocks of the POS UI; the "v5" naming is an internal version tag, not a graveyard. Net new dead code = 0 LOC.

2. **`resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue` is NOT a forked copy of `KioskWizardComponent.vue`.**
   It is a 15-line compatibility wrapper that `<KioskWizardComponent v-bind="$attrs" />` and is conditionally chosen by `resources/js/router/modules/kioskRoutes.js:178` for staff/POS-kiosk entry paths. The "POS-kiosk wizard convergence" comment at the top is accurate. Drop net 0 LOC.

3. **`app/Services/Order/` vs `app/Services/Orders/` (singular vs plural).** Looks like a typo but is distinct:
   - `app/Services/Order/` = workflow services (`OrderQuoteService`, `RefundWithCounterEntryService`, `SealedOrderGuard`).
   - `app/Services/Orders/` = a single file (`OrderItemAllergenSnapshot.php`) that mirrors the table-name (`order_items`) pluralization. No overlap. Cosmetic nit only — recommend renaming `Orders/` → `Order/` for consistency (very small refactor).

4. **`10× `Persist*ToOutbox.php` listeners are NOT bad duplication.** They are the canonical Outbox Pattern. Each listener has a different event payload (`OrderCreated`, `OrderStatusChanged`, `OrderPaymentStatusChanged`, `ItemAvailabilityChanged`, `ItemVariationAvailabilityChanged`, `ItemExtraAvailabilityChanged`, `CatalogChanged`, `CouponChanged`, `OrderPaidAtCounter`, `OrderTableChanged`), different idempotency-key composition (one-shot vs transition-with-correlation), different channels (per-branch vs fan-out). See §3 below — this is healthy duplication.

5. **`config/product.php` is NOT a versioned redundancy of `config/menu.php`.** It is a 7-key vendor licensing file pointing at `inilabs.net`. Unrelated.

---

## §1 Duplication score

**Score: 62 / 100** (higher = more duplication; 100 = catastrophic copy-paste).

Justification:
- **~6 100 LOC** of identifiable structural duplication across composer wizards, sync services, notification builders, and order service helpers (see §2 + §5 for breakdown).
- **2 dual-model classes** sharing the same `orders` table (Order vs FrontendOrder) — a foundational duplication that propagates into 2 service implementations of `myOrderStore`, `changeStatus`, `safeJsonDecode`, `allocateQueueNumber`, `resolveBusinessDate`, `isQueueNumberUniqueViolation`, etc.
- **9 notification builder classes** with identical 9-branch `OrderStatus::*` switch statements.
- **3 sync services** sharing 60% boilerplate (`_jitter`, `_scheduleNext`, `_cleanup`, `_runtimeConfig`, `_bindWebSocketState`).
- **3 wizard composers** (Vue / Vanilla JS / React Native) implementing the same `composer_profile`-driven step computation.

Score is NOT 90+ because:
- Roughly half the duplication is intentional pattern (Outbox listeners, branch-isolated mailer matrices, healthy sibling sync services with different cadence tunings).
- Most duplication is already commented with cross-references (e.g. `PersistItemVariationAvailabilityChangedToOutbox:14: "Sibling of PersistItemExtraAvailabilityChangedToOutbox"`).
- The model-dual is the only structural drift hazard; the rest is consolidation opportunity, not active bug-source.

---

## §2 Critical duplications (drift risk) — ranked

### CRIT-1 — `Order` vs `FrontendOrder` models share the same `orders` table (P0 drift)

- `app/Models/Order.php:13-58` — `protected $table = "orders"; protected $fillable = [...]`.
- `app/Models/FrontendOrder.php:13-73` — `protected $table = "orders"; protected $fillable = [...]`.

Different `$fillable` sets (`FrontendOrder` adds `transaction_id`, `card_type`; `Order` omits them — but writes to the same DB columns). Both apply `BranchScope`, both implement `BroadcastableOrder`, both use `SoftDeletes`, both use `HasDomainEvents`. `FrontendOrder` also has a `booted()` hook (`source_surface=delivery` for `order_type=DELIVERY`) that `Order` lacks — a row created via `Order::create()` for a delivery flow will MISS this enrichment.

**Drift hazard:** any new column added to `orders` must be added to BOTH `$fillable` arrays or the silently-missed model will reject it.

Controllers split the surfaces almost cleanly:
- `Frontend/OrderController.php`, `Frontend/PaymentReconcileController.php` use `FrontendOrderService` → `FrontendOrder`.
- 16 admin/POS/table/customer controllers (`app/Http/Controllers/Admin/*.php`) use `OrderService` → `Order`.
- But `Frontend/DeliveryBoyOrderController.php:8` uses `OrderService` (which maps to `Order`) — a delivery-boy update on a kiosk-created order will read/write via `Order` even though the row was inserted via `FrontendOrder`.

Verdict: latent inconsistency. Recommendation = collapse FrontendOrder into Order (single model + read-only scopes), or invert and make Order an alias of FrontendOrder. Either way 1 model, 1 fillable, 1 boot.

### CRIT-2 — `myOrderStore` exists in BOTH order services (P0)

- `app/Services/OrderService.php:304-562` (~258 LOC).
- `app/Services/FrontendOrderService.php:131-640` (~509 LOC).

Both methods:
- Take an `OrderRequest`.
- Call `PricingService->calculateOrder()` if `config('pricing.use_ssot_service')`.
- Insert into the same `orders` DB table via different models.
- Each maintains its own idempotency strategy (Order: none at this method; FrontendOrder: explicit Cache::lock + DB unique).

Drift hazard: any pricing-related bug fix applied to one will silently bypass the other. Today this is partially mitigated because `PricingService` is the SSOT — but the pre-pricing data assembly (variation/extra hydration, kiosk-machine resolution, tax application) is DIVERGENT between the two methods and has historically been the source of P0 audit findings (AUDIT-P50, AUDIT-FIX P0, P11-FZH).

### CRIT-3 — Order service helper methods duplicated word-for-word

Confirmed duplicate private helpers between `OrderService` and `FrontendOrderService`:

| Method | OrderService | FrontendOrderService |
|---|---|---|
| `safeJsonDecode` | `:2424` | `:907` |
| `allocateQueueNumber` | `:2331` | `:965` |
| `resolveBusinessDate` | `:2370` | `:1004` |
| `isQueueNumberUniqueViolation` | `:2383` | `:1017` |
| `saveOrderWithQueueNumber` / `saveFrontendOrderWithQueueNumber` | `:2297` | `:931` |

These are pure utility — extracting to `app/Services/Order/OrderHelpersTrait.php` or an `OrderQueueAllocator` collaborator would save ~120 LOC and remove drift-risk on any future queue-number invariant patch.

### CRIT-4 — Wizard composer (`composer_profile`) implemented 3 times

- Vanilla JS POS: `public/js/pos-wizard.js:493` (`buildStepsFromComposerProfile`), `:551` (`buildSteps`), step mapping `:484-490`. File is 5 964 LOC.
- Vue kiosk: `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:779`, `:870-871`, `:892` (3 094 LOC, includes `composer_profile.steps[]` handling).
- React Native mobile: `mobile/screens-item-steps.jsx:1`, `:38-79`, `:111` ("DB-mirror shape"), `:775-976` (1 172 LOC, hardcoded slug heuristics).

All 3 read the same backend payload key `composer_profile.steps[]` (DB-mirror shape from `App\Services\Composer\ComposerProfileProjection`), each re-implements `min_select` / `max_select` / `step_type` interpretation. Three different bug-paths:
- Mobile uses hard-coded slug heuristics (`bowl-frites-`, `bowl-riz-`) → drifts every time the catalog evolves.
- POS Vanilla JS is FROZEN per CLAUDE.md §7 → cannot be patched normally.
- Kiosk Vue is the canonical implementation.

**Drift hazard:** real. The recently completed "Bols 3-step + Frites 1-step + composer_profile hardcoded mirror" cycle (PROJECT_BRAIN, 2026-05-16) explicitly documents that mobile is intentionally a divergent mirror "pour wireup futur mécanique" — but until that wireup happens, any composer schema change must be ported to 3 stacks.

### CRIT-5 — `OrderService` ↔ `KitchenDisplaySystemOrderService` ↔ `OrderStatusScreenOrderService` — overlapping `list()` semantics

- `app/Services/OrderService.php:116` `public function list(...)`
- `app/Services/KitchenDisplaySystemOrderService.php:52` `public function list(...)` (KDS scoping)
- `app/Services/OrderStatusScreenOrderService.php:37` `public function list()` + `:107` `listForBranch(int)` (OSS scoping)
- `app/Services/FrontendOrderService.php:85` `public function myOrder(...)` (different name, same intent for kiosk)

Each `list()` re-implements its own filter pipeline (Status filter, OrderType filter, branch scoping, eager-load shape). The diff has accumulated cluster-specific patches (e.g. `[GAP-29-3]` on KDS, `[Audit-Claude NEW-03 B7]` on OrderService). Risk of inconsistent eager-loads between surfaces (KDS may eager-load relations OSS misses, causing KDS to display a value that OSS never receives in payload). LOW priority — surfaces have legitimately different needs — but a shared `OrderQueryBuilder` helper would remove ~200 LOC.

### CRIT-6 — 3 sync services share 60% boilerplate

`KdsSyncService.js` (470 LOC), `OssSyncService.js` (427 LOC), `PosSyncService.js` (426 LOC), all in `resources/js/services/`.

Shared shape (all 3):
- `_jitter(...)` — random sleep helper (KDS:443, OSS:413, POS:420).
- `_scheduleNext(...)` — timer rearm (KDS internal, OSS:338, POS:335).
- `_cleanup({unsubscribe})` — timer + WS unsubscribe (OSS:352, POS:349).
- `_runtimeConfig()` — config-tag parsing (OSS:109, POS:136).
- `_bindWebSocketState()` — Echo state subscription (OSS:127, POS:150).
- WS state machine: `IDLE | POLLING | BACKOFF | STOPPED` (all 3).

Differences are LEGITIMATE:
- KDS has a cadence ladder (highActivityBaseMs / degradedBaseMs / disconnectedBaseMs) the others don't need.
- OSS has visibility-burst-poll throttling (visibilityBurstMinIntervalMs).
- POS catalog fetch is a single endpoint, others poll multiple.

Net consolidation opportunity = extract a base `BackoffPollingService` class (~200 LOC), let the 3 services inherit + override cadence. See §5.

### CRIT-7 — Wizard recap step / loyalty / coupon logic re-implemented per surface

- POS: `pos-wizard.js:536` ("Always append recap step for consistency with legacy buildSteps").
- Kiosk: `KioskWizardComponent.vue` has its own recap + summary flow.
- Mobile: `screens-item-steps.jsx:38 BOL_SUPPLEMENTS` enum, separate recap logic.

Not a P0 because each surface needs different recap UX, but the inventory of "what's in the cart" / "what's the price preview" is rebuilt 3 times → 3 truth sources for the same backend `composer_profile` payload.

---

## §3 Healthy duplications (intentional patterns) — DO NOT collapse

### H-1 — 10× `Persist*ToOutbox` listeners

- `app/Listeners/PersistOrderCreatedToOutbox.php` (one-shot, idemp key = `sha1(event_type|aggregate_id)`).
- `app/Listeners/PersistOrderStatusChangedToOutbox.php` (transition, idemp key includes `correlation_id`).
- `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php`
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php`
- `app/Listeners/PersistOrderTableChangedToOutbox.php`
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Listeners/PersistCouponChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php`
- `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php`

The shape is consciously identical (firstOrCreate(DomainEvent) → afterCommit dispatch best-effort), with class-level docblocks explicitly cross-referencing siblings ("Sibling of PersistItemExtraAvailabilityChangedToOutbox", "Parity with PersistCatalogChangedToOutbox:92"). The differences are exactly the per-event payload + the channel fan-out logic.

**Verdict: keep duplication.** A common abstract base would obscure the event-specific idempotency strategy that audit-cycles have repeatedly hardened (Sprint 3B, 5C, F-016a-BIS).

### H-2 — `resolveCorrelationId` / `resolveOrigin` / `resolvePaymentMethod` per listener

Each listener has its own copy of these 3 helpers. They are 5-10 LOC each. Extracting to a trait would save ~150 LOC across the 10 listeners but each listener would lose its independent test surface. **Marginal — caller's call.**

### H-3 — 9 Notification Builders (Mail / SMS / Push × Customer / Got / DeliveryBoy)

- `app/Services/OrderMailNotificationBuilder.php` (149 LOC) — customer mail, 9-branch status switch.
- `app/Services/OrderSmsNotificationBuilder.php` (157 LOC) — customer SMS, 9-branch status switch.
- `app/Services/OrderPushNotificationBuilder.php` (185 LOC) — customer push, 9-branch status switch.
- `app/Services/OrderGotMailNotificationBuilder.php` (58 LOC) — admin/manager mail on NEW order (no status branch — fires once).
- `app/Services/OrderGotSmsNotificationBuilder.php` (74 LOC) — admin SMS on NEW order.
- `app/Services/OrderGotPushNotificationBuilder.php` (77 LOC) — admin push on NEW order.
- `app/Services/OrderDeliveryBoyMailNotificationBuilder.php` (65 LOC) — delivery-boy mail on assignment.
- `app/Services/OrderDeliveryBoySmsNotificationBuilder.php` (73 LOC) — delivery-boy SMS.
- `app/Services/OrderDeliveryBoyPushNotificationBuilder.php` (78 LOC) — delivery-boy push.

Pattern: each is a 3-axis matrix (channel × recipient × status). The "Got" + "DeliveryBoy" variants are NEW or ASSIGNED single events (no status switch); the customer variants have a 9-branch status switch (`PENDING / ACCEPT / PREPARING / PREPARED / OUT_FOR_DELIVERY / DELIVERED / CANCELED / REJECTED / RETURNED`).

**Healthy because:** each class can be swapped/disabled per branch via `NotificationAlert::where(['language' => '...'])` rows. A generic `NotificationDispatcher` would still need 9 templates, 3 channels, 3 audiences → same surface area, less explicit. **Score: cosmetic refactor only.**

But: see §5 CONS-2 — the customer 3 (Mail/SMS/Push) ALL load the same `FrontendOrder::find($orderId)` + same `User::find` + same `Source 10 = Kiosk → return early` guard → a `NotificationBuilderBase::resolveRecipients()` could deduplicate ~60 LOC.

### H-4 — `KioskPosWizardComponent` as POS-kiosk delegate

15-line wrapper. Intentional, not duplication.

---

## §4 Dead code map

### D-1 — `_archive/ignored_legacy_web_orchestration/` (1.3 MB)
- `_archive/_UPLOAD_CLAUDE_TEMP`, `_archive/ARCHIVE_NOTE.md`, `_archive/bot`, `_archive/bot-cli.cmd`, `_archive/bot-cli.ps1`.
- Out of all build/test/runtime paths. Safe to delete or `git rm -r` — kept here as forensic only.

### D-2 — `storage/backups/menu-reset-2026-05-13/*.bak`
- `storage/backups/menu-reset-2026-05-13/mobile-menu.js.bak`
- `storage/backups/menu-reset-2026-05-13/menu.php.bak`
- `storage/backups/menu-reset-2026-05-13/kiosk.php.bak`
- Already in `storage/backups/` — git-ignored zone. Harmless but bloat (~few hundred KB).

### D-3 — Stale top-level orphan files (git status shows untracked)
- `Utilisateur non trouvé.,` (literal filename with trailing comma — fluke shell redirect).
- `L'article ne correspond pas.,` (same).
- `,` (single comma file).
- `[` (single bracket file).
These are not dead code (they are accidental untracked files from a shell mishap), but should be cleaned: `rm -- "Utilisateur non trouvé.,"` etc. Not duplicative — just litter.

### D-4 — 2 active `TODO` markers (not stale, both forward-pointing)
- `app/Services/KdsSyncService.php:132` — `TODO(F-03 / Phase 3 dette technique D-03bis)` — flagged in a planned cycle, OK.
- `app/Services/Menu/PosMenuProjection.php:98` — `TODO (Codex — task 2.2 of plan)` — flagged in CV1 plan, OK.

No `FIXME`, no `@deprecated`, no `XXX`, no `HACK` markers found across `app/Services/` and `app/Http/` (encouraging — the codebase has been groomed).

### D-5 — `OrderSetupService.php` (47 LOC)
A 2-method thin wrapper around `Settings::group('order_setup')` with try/catch + Log::info. Used by exactly one controller (`OrderSetupController`). Could be inlined. **Marginal dead-weight.**

### D-6 — `_archive/` worktree copies under `.claude/worktrees/`
Each worktree has its own `_archive/ignored_legacy_web_orchestration/` (~6 copies). Auto-cloned by worktree, ignored by build. No action needed — they get garbage-collected with the worktrees.

**Net dead code in productive paths: < 200 LOC.** The repo is clean.

---

## §5 Top 5 consolidation opportunities (estimated LOC savings)

### CONS-1 — Collapse `Order` ↔ `FrontendOrder` into one model
- **Savings:** ~700 LOC (FrontendOrder model + half of `FrontendOrderService` helper methods + all of CRIT-2 / CRIT-3).
- **Risk:** P0. Touches BranchScope, HasDomainEvents, BroadcastableOrder. Requires deep regression suite (kiosk + delivery + POS + admin paths).
- **Win:** removes the entire model-dual drift hazard (CRIT-1). The single highest-leverage refactor in the codebase.
- **Approach:** keep `FrontendOrder` as a thin `class FrontendOrder extends Order { /* boot only */ }` alias for 1 cycle, then deprecate.

### CONS-2 — Extract `app/Services/Order/OrderHelpersTrait.php`
- **Savings:** ~120 LOC.
- **Pulls together:** `safeJsonDecode`, `allocateQueueNumber`, `resolveBusinessDate`, `isQueueNumberUniqueViolation`, `saveOrderWithQueueNumber`/`saveFrontendOrderWithQueueNumber`.
- **Risk:** LOW. Pure helpers. Add a trait, `use OrderHelpersTrait;` in both services, run PHPUnit on `OrderServiceTest` + `FrontendOrderServiceTest`.
- **Win:** removes CRIT-3 drift. Independent of CONS-1 (can ship first).

### CONS-3 — Extract `resources/js/services/_BackoffPollingService.js` base class
- **Savings:** ~250 LOC across `KdsSyncService.js`, `OssSyncService.js`, `PosSyncService.js`.
- **Owns:** `_jitter`, `_scheduleNext`, `_cleanup`, `_runtimeConfig`, `_bindWebSocketState`, the `IDLE/POLLING/BACKOFF/STOPPED` state machine, the 5xx backoff doubling capped at 30 s.
- **Subclasses override:** cadence ladder (KDS), burst-poll throttle (OSS), endpoint URL + Vuex dispatch payload (all 3).
- **Risk:** MEDIUM. Touches Echo wiring used by 3 surfaces. Requires Playwright soak on KDS + POS + OSS.
- **Win:** removes CRIT-6, makes the 4th sync service (KIOSK menu sync? mobile sync?) trivial to add in a future cycle.

### CONS-4 — Build `app/Services/Notifications/NotificationDispatcher.php` matrix
- **Savings:** ~250 LOC across 9 builders (~916 LOC total today → ~660 LOC).
- **Pulls together:** the 3 customer-channel `Source 10 → return early` guards, the 3 `FrontendOrder::find($orderId)` + `User::find($order->user_id)` head, the 9-branch `OrderStatus::*` switch implemented identically in Mail / SMS / Push.
- **Risk:** LOW-MEDIUM. NotificationAlert DB rows + email/SMS templates stay unchanged. Class signatures unchanged → backward compatible.
- **Win:** new notification channels (e.g. WhatsApp, Webhook) become a 1-class add rather than 3.

### CONS-5 — Extract shared composer step engine (`composerStepCompiler.js`)
- **Savings:** ~400 LOC across `pos-wizard.js` + `KioskWizardComponent.vue` + `screens-item-steps.jsx`.
- **Owns:** `buildStepsFromComposerProfile(profile, item)` → returns an array of `{key, label, type, min, max, items[], ...}`. Pure function, no DOM, no state.
- **Subclasses override:** rendering (each surface keeps its own template).
- **Risk:** HIGH on POS Vanilla JS (FROZEN — see CLAUDE.md §7 — needs explicit LOCK to inject a shared library), MEDIUM on Vue (Vue can import ESM directly), HIGH on React Native (would need a separate bundling path or a literal JS-copy).
- **Realistic win:** start with Vue+RN sharing one source (save ~250 LOC), leave POS alone until the next frozen-zone gate cycle.

---

## Summary tally

| Category | LOC duplication discovered | LOC savable | Effort |
|---|---|---|---|
| CRIT-1 model dual | ~100 (model) + propagated bugs | ~700 | XL |
| CRIT-2 + CRIT-3 service helpers | ~400 | ~120 | M |
| CRIT-4 wizard composer | ~1 200 | ~250 (Vue+RN) | L |
| CRIT-5 list() | ~200 | ~150 | M |
| CRIT-6 sync services | ~700 | ~250 | M |
| CRIT-7 wizard recap | ~150 | ~50 | M |
| H-3 notification matrix | ~200 (head/guard) | ~250 | L |

**Total identifiable LOC reduction potential: ~1 800 LOC.** Of which ~370 LOC are low-risk and can be shipped in a sprint (CONS-2 + CONS-4).

---

Path: `reports/audit/goal-systems-2026-05-17/cross-cutting/X1-duplication.md`
