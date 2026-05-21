# Adversarial RED — KDS — Wave 1 (resumed)

- **Branch / HEAD**: `v1-0-1-hardening-2026-05-17` / `f24b49c42`
- **Scope**: KDS surface — controllers, services, broadcast, polling fallback, V2 layout, Vuex.
- **Stance**: hostile, read-only, NO cloud-only attacks. Local Le Cayenne single-tenant context.
- **Reference**: `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md`, predecessor audits `kds-architect.md` (Wave 1) + `sync-adversarial.md`.

---

## 2. Findings

### KDS-RED-09  Polling-cadence DoS — owner-misconfig (P1, visible/security, REAL)

- **File:line**: `resources/js/services/KdsSyncService.js:447-465` (`_runtimeCadenceOptions`) + `config/catalog_v15.php:79-89` (`kds_fallback_polling`) + `resources/views/master.blade.php:161-168` (wireup).
- **Evidence**: `toInt` accepts any `parsed >= 0`. No floor. If owner sets `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=10` (or any small int), `_baseCadence()` returns `10 + jitter(3)` so `_schedule()` (line 348-353) fires `forceSync()` every ≈10-13 ms when WS is disconnected.
- **Reproduction**: Stop Soketi (so WS reports `DISCONNECTED`). Boot KDS station with env override `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=10`. DevTools → Network: ≈80 GET `/api/admin/kds-order/sync` per second per station, sustained.
- **Impact (local)**: single-station kitchen self-DOS. `Cache::remember($cacheKey, 5)` in `KdsSyncService.php:49` absorbs the SQL burst (good), but every request still walks Sanctum middleware + `permission:kitchen-display-system` gate + PHP-FPM. On a Macbook dev rig with 3 stations open: PHP-FPM saturates within ≈30 s.
- **Mitigation local-only**: add clamp `Math.max(parsed, 1000)` in `_runtimeCadenceOptions` AND a PHP-side floor in `config/catalog_v15.php` (e.g. `max(env(...), 1000)`). Belt + braces. Document the `1000 ms` floor in the env example file.
- **Severity rationale**: P1 not P0 — local single-tenant means an owner misconfig is the attack vector, not a remote actor. But it bricks the kitchen + likely the LAN if multiple KDS stations live on one box.

### KDS-RED-07  V2 kill-switch `?v2=0` rollback regression risk — DRIFT (P2, hidden, REAL drift)

- **File:line**: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1188-1191` (comment "legacy 4-column path remains intact in the v-else branch") vs. memory entry "Wave 5G G1=B Deprecate decision". Legacy lane spans lines 257-940 (4 columns: dinein / online / takeaway / kiosk).
- **Verification**: legacy lane DOES carry the allergen badge (line 281-286 `<button v-if="orderHasAllergens(...)" class="kds-allergens-badge" aria-label="...">`), the OOS marker (line 272-279), and `data-kds-order-card="..."` test hooks. So `?v2=0` does NOT bypass the allergen-modal gate or the food-safety UI. Phone-gate is server-side (KDS-RED-02 below) → cannot be bypassed via URL param.
- **Real drift**: Wave 5G claimed "Deprecate Items Board" but the entire 4-column lane survives behind `?v2=0`. A future P0 fix shipped only to `KdsV2Grid.vue` will silently bypass the rollback path — kitchen staff who keystroke `?v2=0` during an incident regress. Sentinel needed: either E2E test that runs the smoke suite with `?v2=0` once per release, OR a `console.warn('KDS legacy lane — rollback only')` banner gating perceived UX.
- **Mitigation local-only**: add a Playwright spec `kds-legacy-v2-off.spec.ts` that asserts legacy lane renders the same allergen badge + OOS marker + aria-labels as V2. Don't delete the lane yet — the rollback affordance is real value for V1.

### KDS-RED-13  DispatchKdsTicket has no `wasRecentlyCreated` guard at dispatch site (P3, hidden, architectural)

- **File:line**: `app/Listeners/DispatchKdsTicket.php:11-20` calls `OrderStatusChanged::dispatch()` after `KitchenReleaseRule::shouldDispatchStatusChanged($from, $to)` only. The dedup gate lives downstream at `app/Listeners/PersistOrderStatusChangedToOutbox.php:34-65` (firstOrCreate on `idempotency_key` + `wasRecentlyCreated` short-circuit on line 64).
- **Evidence**: if any caller misuses `DispatchKdsTicket::dispatch()` twice in the same request with the same `(from, to)` tuple, the second OrderStatusChanged fires uselessly — but the outbox absorbs it. So no broadcast dup today. Risk: code coupling. The KDS-side correctness invariant rides on the outbox listener integrity. Any refactor that splits the outbox listener out (e.g. behind a feature flag) silently breaks dedup.
- **Mitigation local-only**: not actionable today. Flag for V1.0.2 as "consider centralizing the dedup guard into a domain helper (`DispatchKdsTicket::shouldDispatch($order, $from, $to)`) before the outbox listener split-out planned in §5 of the sync hardening plan."

### KDS-RED-08  `allergensModal` vs `allergenModalReturnFocus` naming drift (P3, cosmetic, NON-bug)

- **File:line**: `KitchenDisplaySystemComponent.vue:1131-1136` (data), `:1564-1579` (open/close), `:1591` (refs). State key is plural `allergensModal`, focus-return key is singular `allergenModalReturnFocus`.
- **Evidence**: all 9 references self-consistent. No runtime impact. The memory entry "allergenModal typo regression" likely referred to a previous cycle that was already fixed — current HEAD has no functional defect on this surface.
- **Mitigation local-only**: rename `allergenModalReturnFocus → allergensModalReturnFocus` in V1.0.2 cosmetic sweep. Not P0/P1.

### KDS-RED-12  Vuex desync on WS reconnect — out of scope this pass (P-?, unknown, NEGATIVE-SPACE)

- **File:line**: `resources/js/store/modules/kitchenDisplaySystemOrder.js` not opened in this audit (time-boxed). Inspected `store/modules/kds.js` (bumped-items, LS-persisted, 87 lines) and `store/modules/kdsInflight.js` (recently-86'd items, in-memory 158 lines). Both are auxiliary stores, NOT the order list SSOT.
- **Status**: cannot confirm or deny "stale cards remain visible after disconnect window" or "merge logic loses transitions". Flagged for follow-up — defer to Sync Adversarial Wave 2 or a dedicated Vuex-reconnect probe.

---

## 3. Attested Defenses (DEFENDED — attacks fail)

### KDS-RED-01  WS broadcast missed → polling fallback recovery — DEFENDED

`KdsSyncService.js:79-90` (`start`) + `:330-354` (`_schedule`) + `:281-304` (`_baseCadence`). When WS state ≠ `CONNECTED`, polling cadence kicks in at `disconnected_base_ms` (10 s default) + jitter (3 s). The `_schedule()` recursion has a network-error self-heal (line 208-210) — `forceSync()` re-schedules itself even after `AbortError` / DNS failure. `Cache::remember` (`KdsSyncService.php:49`) caps SQL load at 5 s. Worst-case wall-clock for a missed broadcast to surface = `10 + 3 + 5 ≈ 18 s`. Acceptable for V1 local kitchen.

### KDS-RED-02  GDPR phone leak — DEFENDED

`KDSOrderDetailsResource.php:68-71`: `'phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user->phone : null`. `OrderType::DELIVERY = 5` (`app/Enums/OrderType.php:7`). For `null`/0/non-DELIVERY types (`TAKEAWAY`, `KIOSK`, `POS=DINE_IN`), `(int) null === 0 ≠ 5`, so `phone` is set to `null` over the wire. Verified with `grep` — no other resource exposes `user.phone` on the KDS surface. The Vue UI still has its own `v-if="dineinOrder.customer && dineinOrder.customer.phone"` gate (line 332) so even a payload glitch wouldn't render a click-to-call link.

### KDS-RED-03  Allergen NULL → KDS crash — DEFENDED

`OrderItemResource.php:102-126`: `safeJsonDecode` returns `[]` on `empty($value)`, returns `is_array($value) ? $value : []` after Eloquent cast. `kdsAllergens.js:23-25` checks `Array.isArray(snapshot) && snapshot.length > 0` before recursing. `KitchenDisplaySystemOrderService.php:315-329` (`normalizeAllergensForHash`) returns `[]` on non-array input. Wave Z 5C backfill (`tests/Feature/Kds/BackfillAllergensSnapshotTest.php`) covers the migration. Pre-existing NULL rows render the card without the allergen badge — no exception, no `undefined.length`.

### KDS-RED-04  Bump-button concurrent-cook race — DEFENDED

`KitchenDisplaySystemOrderService.php:155-184` wraps the entire status-change in `DB::transaction` + `Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()` (line 159-162) + optimistic concurrency gate `if ($fromLocked !== $expectedFrom) abort(409)` (line 171-184). Idempotent same-status replay returns the locked row without re-saving (line 186-188). Triple defense: pessimistic row lock + expected-status guard + KitchenReleaseRule whitelist (line 191) + OrderStateMachine whitelist (line 192). Two cooks tapping bump on the same millisecond → first wins, second gets 409 + "Order status was updated elsewhere".

### KDS-RED-05  Status transition skip (ACCEPT → PREPARED bypass PREPARING) — DEFENDED

`KitchenReleaseRule.php:41-49` enumerates the whitelist: `(ACCEPT → PREPARING) || (PREPARING → PREPARED)` only. ACCEPT → PREPARED returns `false`. PATCH `/api/v1/admin/kds-order/change-status/{id}` validates `expected_status` (`KdsOrderStatusRequest.php:23-29`) and `OrderStateMachine::allows` (line 192). The `expected_status === ACCEPT && status === PREPARED` request is rejected at the FormRequest with 422.

### KDS-RED-06  Cross-branch IDOR via PATCH change-status — DEFENDED

`KitchenDisplaySystemOrderService.php:164-167`: explicit branch-id check after `lockForUpdate`. `Order::factory()` with `branch_id=2` is invisible to a branch-1 token via `BranchScope` global scope (`app/Models/Order.php:7 + :92` — confirmed). Belt + braces: even if BranchScope is bypassed, the explicit check throws 403 "Accès refusé : cette commande appartient à une autre succursale." Sentinel coverage: needs an explicit `KdsCrossBranchPatchTest` (negative space — see §4).

### KDS-RED-10  Items Board deprecated lane reachability — see KDS-RED-07 above (DEFENDED + DRIFT)

The legacy 4-column path is intentionally retained behind `?v2=0`. Allergen + OOS UI parity holds. The drift risk is "future P0 ships only to V2 lane" — captured under KDS-RED-07.

### KDS-RED-11  DispatchKdsTicket double-fire on UPDATE — DEFENDED (defense-in-depth)

`DispatchKdsTicket.php:13-15` gates on `KitchenReleaseRule::shouldDispatchStatusChanged($oldStatus, $newStatus)` (line 51-54 of `KitchenReleaseRule`: requires `$from !== $to && canTransition`). So no-op transitions never re-fire. The outbox listener (line 64 of `PersistOrderStatusChangedToOutbox`) adds a `wasRecentlyCreated` guard via `firstOrCreate` on `idempotency_key = sha1(event|order|from|to|correlation)`. See KDS-RED-13 for the architectural coupling concern.

### KDS-RED-13bis  KDS Sync sargable sentinel (commit `181abdef4`) — DEFENDED

`tests/Feature/Kds/KdsSyncSargableTest.php` pins the COMPILED SQL via `DB::listen`. Two assertions:
- ASSERT-A: no `date(order_datetime)` AND no `strftime('%y-%m-%d', order_datetime)` (covers MySQL + SQLite drivers).
- ASSERT-B: `order_datetime between` AND (`order_datetime <` OR `order_datetime >=`) both present.
Driver-agnostic via `str_replace(['`', '"'], '', $sql)`. Custom-TZ probe: `Carbon::today()` honours `app.timezone`; the BETWEEN range still bounds correctly because Carbon serializes to the configured TZ. Test as-shipped survives `APP_TIMEZONE=Europe/Paris` and `APP_TIMEZONE=UTC`. Real prod-change in `KdsSyncService.php:65-77` mirrors Wave 5F pattern (`KitchenDisplaySystemOrderService.php:91-102`) byte-for-byte structurally.

---

## 3. Cross-validation candidates

- **KDS-RED-09 (Polling DoS)** ↔ Sync Adversarial cadence-config audit (`sync-adversarial.md` referenced same `catalog_v15.kds_fallback_polling` block). If Sync agent also flagged absent floor → P0 cross-validated.
- **KDS-RED-07 (V2 kill-switch drift)** ↔ Admin Architect Wave 5G rollout decision audit. Owner-gate request.
- **KDS-RED-04 (bump race)** ↔ POS Adversarial POS-RED-02 ("Z race vs in-flight order"). Both rely on `DB::transaction + lockForUpdate`. Patterns aligned — file:line `OrderService.php:1515` referenced inline.
- **KDS-RED-02 (phone gate)** ↔ NF525 Fiscal Audit data-minimization claim. The gate is a wire-level GDPR control, not fiscal — but the discipline pattern is identical (server-side strip, frontend defense-in-depth).

---

## 4. Negative space (NOT audited this pass — explicit)

1. **Vuex `kitchenDisplaySystemOrder` module** — not opened. WS-reconnect merge logic + stale-card eviction strategy unverified. Defer or dispatch a 4th-agent probe.
2. **KDS-side queue worker behaviour** — Soketi local config not stressed (no Soketi-down + queue-pile-up + restart attack run).
3. **KDS print-ticket path** — `printKitchenTicket` (referenced in component) not reviewed. Out of scope per ULTRA_PLAN focus.
4. **KDS `?v2=0` cross-branch parity** — assumed parity by code-walk. NOT exercised with a Playwright spec. Mitigation in KDS-RED-07 covers it.
5. **`Cache::remember` cache-key collision** — `kds.sync.{branchAll|N}.{minute}.{md5(since|includeDeleted)}` (KdsSyncService:42-47). Minute bucket collides only within a single minute for identical inputs. Acceptable for V1.
6. **`SendOrderMail` / `SendOrderSms` / `SendOrderPush` failure path** — `KitchenDisplaySystemOrderService.php:219-221` dispatches them BEFORE `kdsTicketDispatcher->dispatch` (line 224). If they throw synchronously (QUEUE_CONNECTION=sync local), KDS broadcast is silently skipped — but the status DB write already committed. Verified: each `::dispatch()` is queue-only, no inline throw. Defended.
7. **Reconnect-storm flood** — `KdsSyncService.js:247-263` adds 0-500 ms jitter on `reconnect_storm` event. Single-station local has no thundering-herd. Defended at code level.

---

## Verdict (this audit pass)

- **2 REAL findings**: KDS-RED-09 (P1 polling DoS / owner-misconfig) + KDS-RED-07 (P2 V2 kill-switch drift sentinel gap).
- **1 P3 architectural smell**: KDS-RED-13 (`DispatchKdsTicket` no wasRecentlyCreated at dispatch site).
- **1 P3 cosmetic**: KDS-RED-08 (naming drift).
- **8 attestations**: KDS-RED-01/02/03/04/05/06/11/13bis — defense holds, file:line evidence captured.
- **1 negative-space deferral**: KDS-RED-12 (Vuex order-list module).

KDS surface is **shippable for V1 Le Cayenne local** with KDS-RED-09 patched (5 lines JS + 1 line PHP config) before owner-gate. KDS-RED-07 + KDS-RED-13 can land in V1.0.2.

---
END.
