# Live Sync Degradation — SRE Reliability Audit (W2)

**Scope:** Does live sync SURVIVE all-day degradation (WS outage, queue worker death, soketi restart)?
**Verdict:** CONDITIONAL-YES. **Read-only audit — no code changed.**
**Key architectural fact:** the poll-fallback endpoints read DB order state directly, NOT the outbox.
`app/Services/KdsSyncService.php:96` — `Order::with(...)->where('updated_at','>=',$sinceForDb)` (+ `branch_id` :111).
So a dead broadcast pipeline does NOT blind the surfaces — they reconcile against `orders` by `updated_at>=since`.

---

## 1. CRONS (relevant to sync/outbox/fiscal) — `app/Console/Kernel.php`

| Cron | Cadence | Rescues / how |
|---|---|---|
| `foodking:outbox:rescue` | everyMinute (`:40`) | Re-claims stale outbox rows. Lane-A: `dispatched_at NULL` + `created_at < now-2min`; Lane-B: crash-claimed `dispatched_at NOT NULL` older than 10min (nulls `dispatched_at`, re-dispatches). Both `attempts<5` (`OutboxRescueCommand:34-48`). |
| `foodking:outbox:monitor --threshold=10` | everyMinute (`:50`) | ALERT only (never enqueues — so it works even if the worker is dead). `Log::error` + non-zero exit when `staleCount>10` OR a crash-claimed orphan exists (`MonitorOutboxStaleness:79-132`). |
| `foodking:outbox:retry-failed --since=24h` | hourly (`:64`) | Re-dispatches `failed(5)` rows (`attempts>=5 AND <12`), nulls `dispatched_at`, writes audit row first (`OutboxRetryFailedCommand:102-159`). |
| `foodking:webhook:retry-failed --since=24h` | hourly (`:76`) | Webhook DLQ replay. |
| `fiscal:verify-z-membership` | dailyAt 06:05 (`:91`) | **Detect-only** — read-only NF525 cross-Z-window orphan detector; non-zero exit → `onFailure` pageable `Log::error` (`:97-103`). No re-broadcast, no fiscal mutation. |
| `foodking:fiscal:retry-alloc` | everyMinute (`:248`) | Retries `fiscal_alloc_error_at` allocation failures. |

Re-broadcast path = rescue + retry-failed (both re-`dispatch(DispatchDomainEventsJob)`). Alert path = monitor + z-membership.

## 2. WS OUTAGE → POLL FALLBACK — CURRENT (good)

- **KDS** delta-poll: `GET /api/admin/kds-order/sync?since=<ISO>&branch_id=N&include_deleted=true` (`KdsSyncService.js:145`). Server returns delta `orders` (updated_at>=since) + `deleted_ids` (`KdsSyncController:75`, `app/Services/KdsSyncService.php:96-144`). **Dedupe vs WS**: client version-gating — `version <= previousVersion` → `versionGated:true`, dropped (`KdsSyncService.js:174-187`). Cadence: 5s degraded / 10s disconnected base + jitter; clamped 250ms–60s (`:25-35,308-319`).
- **OSS** poll: full re-fetch `orderStatusScreenOrder/lists` (`OssSyncService.js:286`), 2s disconnected / 60s connected (`DEFAULTS:9,16`).
- **Reconnect-storm guard EXISTS**: tests `tests/js/kdsReactsToReconnectStorm.spec.js` (forceSync once on `reconnect_storm`, `:67-77`) + `tests/js/wsAuthAndStormCohabitation.spec.js` (storm ≠ session_invalid, independent counters, `:31-135`). Circuit breaker = 4 disconnects/30s → decorrelated jitter 5–30s, one reconnect (`WebSocketService.js:33-36,264-352`). KDS adds 0–500ms herd jitter (`KdsSyncService.js:263-279`).

**[P3] `KdsSyncService.js:301-302` — KDS poll is `Infinity` while WS CONNECTED.**
Evidence: `_baseCadence()` returns `{interval: Infinity}` when `wsState===WS_CONNECTED`; `_schedule()` then only arms a **60s drift timer** (`:353-360`).
Impact: if WS is up but no event ever arrives (e.g. worker dead, soketi alive), the worst-case visible staleness is ~60s. Mitigated by the component's own `autoRefreshInterval` (60s when WS up, `KitchenDisplaySystemComponent.vue:1878,1886`) — a second independent 60s poll. So ~60s, not blind.
Rec: none required for V1; the dual 60s poll is an acceptable sanity-check floor.

## 3. QUEUE WORKER DEATH (outbox stalls; nothing broadcasts)

Detection: `foodking:outbox:monitor` everyMinute → fires within ~60s **only if `staleCount>10`** (`MonitorOutboxStaleness:79`).

**[P2] `MonitorOutboxStaleness.php:79` — staleness alert has a volume floor + needs external pager wiring.**
Evidence: `if ($staleCount <= $threshold && $crashClaimedCount === 0) return SUCCESS;` with `--threshold=10`.
Impact: a worker death in a LOW-VOLUME window (≤10 pending events — plausible off-peak) raises NO alert; only the non-zero exit propagates, which requires an external pager (cron MAILTO / supervisord `exitcodes`) NOT present in repo. Surfaces still self-heal via DB poll (§1 fact), so this is observability-blindness, not data loss.
Rec: lower threshold off-peak OR wire the exit code to a pager in `docs/REALTIME_SETUP.md` (lane already documented `:160-164`).

**Worst-case staleness windows (keep distinct):**
- *Surface-visible (chef/cashier sees):* ~60s WS-up / ~2–5s WS-down — bounded by poll cadence, independent of the outbox.
- *Broadcast-replay (backend pipeline):* pending-stale ~2–3min (`OutboxRescueCommand:35` 2min + everyMinute), crash-claimed ~10min (`:34`).

**[P2] `OutboxRescueCommand.php:47` + `MonitorOutboxStaleness.php:49-77` — attempts≥5 crash-claimed orphan falls through EVERY auto-recovery lane.**
Evidence: rescue lane-B is `->where('attempts','<',5)`; `retry-failed` needs `dispatched_at NULL` (scopeFailed→pending) but the orphan has `dispatched_at != NULL`. Self-documented at `MonitorOutboxStaleness:49-58`: "falls through EVERY re-queue lane … re-drive them MANUALLY". Monitor only ALERTS (`:72-77`).
Impact: a worker killed between Phase-1 claim and Phase-2 broadcast, at attempts≥5, leaves a row that is never auto-rebroadcast — operator must `DispatchDomainEventsJob::dispatch($id)` by hand. Rate impact is bounded because the order state itself is still recovered by the DB poll (lost live-push, not lost data) — **modulo** `ItemAvailabilityChanged` (separate channel, not in the order-scoped poll).

## 4. SOKETI RESTART → reconnect + backfill

- Re-subscription/re-auth on reconnect = **pusher-js built-in**, not app code (`subscribeEcho()` `KitchenDisplaySystemComponent.vue:1893` is mount-only; the unsubscribe-first `:1898` guards re-mount, not reconnect). Honest framing: the app does NOT re-subscribe in its own code.
- **Backfill on reconnect = app's real contribution (GOOD):** KDS `_onWsConnected → this.refreshOrderList()` (`:1853-1855`); OSS `_burstPoll('ws_reconnected')` on disconnected→connected (`OssSyncService.js:177-186`).
- **Mid-outage broadcasts ARE dropped at the WS layer** (Pusher/soketi does not buffer for disconnected clients). They are recovered ONLY via the backfill poll, which re-reads `orders` by `updated_at>=since` — so missed ORDER deltas are recovered.
- **[P2] caveat:** a `ItemAvailabilityChanged` push missed during the outage is NOT recovered by the order-scoped KDS poll (`KdsSyncService.js:145` returns orders/deleted_ids only). The 86-marker on in-flight tickets can drift until the next full `refreshOrderList`/list() (component 60s autoRefresh covers it). Rec: confirm `refreshOrderList` re-pulls availability — it does (debounced full refresh), so residual window ≤60s.

## 5. OUTBOX CLAIM SAFETY — `DispatchDomainEventsJob.php`

- **Double-claim: NO (exactly-once claim).** `DB::transaction(lockForUpdate()->find(); if dispatched_at!==null skip; else set dispatched_at+attempts++)` (`:65-86`). Losing worker sees `dispatched_at!=null` → silent skip (`:75-78`). Broadcast is AFTER commit (`:96-116`).
- **Double-broadcast: at-least-once (acceptable).** Crash-recovery lane nulls `dispatched_at` and re-dispatches → "worst case one extra broadcast (visible re-render, no fiscal corruption — broadcasts are advisory)" (`OutboxRescueCommand:56-58`). Consumer dedupe = client version-gating (`KdsSyncService.js:174-187`).
- **Stale-claim forever: NO (closed).** Crash between Phase-1 and Phase-3b → lane-B reclaims after 10min (`OutboxRescueCommand:34,42-44,59-61`). EXCEPT the attempts≥5 orphan (see §3 P2).

---

### Findings roll-up
[P3] KdsSyncService.js:301 — KDS Infinity-poll when WS-up (mitigated by 60s dual poll)
[P2] MonitorOutboxStaleness.php:79 — staleness alert volume floor (≤10) + external pager not in repo
[P2] OutboxRescueCommand.php:47 — attempts≥5 crash-claimed orphan, manual re-drive only
[P2] KdsSyncService.js:145 — ItemAvailabilityChanged not in order-scoped backfill (≤60s residual via full refresh)

### Note on worker/soketi crash recovery
`scripts/deploy/supervisor.conf.template:40-47,65-72` sets `autorestart=true`, `numprocs=2` for both `queue:work redis --queue=high,default` and soketi → bounds a worker/soketi *process death* to ~seconds **IF DEPLOYED**. It is a TEMPLATE; actual deployment cannot be verified from the repo. Honest SRE framing: process-supervision is designed but unconfirmed on the live box.
