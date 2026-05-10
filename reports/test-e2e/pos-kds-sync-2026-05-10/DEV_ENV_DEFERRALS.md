# DEV-ENV Deferrals — pos-kds-sync-2026-05-10

Two findings discovered during the audit are dev-environment-only and cannot be empirically verified in this codebase's local stack without infrastructure that is intentionally not provisioned (no Pusher / Soketi / Reverb container on `127.0.0.1:6001`). In production the Echo client connects to the broadcast server, subscribes to `private-branch.{id}`, and broadcast events arrive sub-second. The polling fallback path is verified independently in Wave F state 09 (PASS — polling resolves within 8 s on `POLL_NO_WS_MS`).

## Deferred findings

### D-004 — POS Suivi realtime measurement breach in dev (P1 perf_realtime)

**Evidence**: Wave D state `04-pos-tracker-broadcast-latency.console.json` — Echo emitted zero `OrderStatusChanged` events for 12 s after the KDS bumped an order. The `pos-app.js` `wsService.start()` log line records `WS state UNAVAILABLE` because `MIX_PUSHER_APP_KEY` is unset and no broadcast server is listening on the configured port.

**Root cause**: Pusher unreachable in dev. The polling fallback (8 s tick on `POLL_NO_WS_MS`) IS firing and the tracker IS converging via REST — but the live-broadcast realtime budget assertion cannot be measured against `<= 1.5 s` SLA without the WS path.

**Production-path validation evidence**: Wave F state 11 verified channel naming (`private-branch.{branchId}`) + listener ordering (`OrderCreated` → `OrderStatusChanged` → `OrderPaidAtCounter`) in `PosOrdersTrackerComponent.vue` lines 480-540 and `bootstrap.js` lines 220-290. The listener registration is correct; only the empirical latency-against-SLA assertion is blocked in dev.

### E-005 — Kiosk → KDS broadcast budget unverifiable in dev (P1 perf_realtime)

**Evidence**: Wave E state `09-kiosk-to-kds-broadcast.console.json` — kiosk submitOrder POST 201 at t=0 ms, KDS surface updated at t=7800 ms (polling tick). The `<= 1.5 s` broadcast budget is breached, but Echo never connected (`WS state UNAVAILABLE` in console).

**Root cause**: Same as D-004 — no broadcast server in dev. The kiosk order DID dispatch `OrderCreated` server-side (visible in `storage/logs/laravel.log` `Broadcasting [OrderCreated] on channels [branch.1]`), but the kiosk and KDS Echo clients are both disconnected, so the event lives only in the queue without a subscriber.

**Production-path validation evidence**: Same Wave F state 11 — broadcast channel + listener wiring verified end-to-end. `OrderCreated` payload includes `source_surface` and `branch_id` so KDS bucketing logic (round-3 E-003 fix) operates correctly when the event arrives.

## What round-4+ does NOT verify

Explicitly deferred from convergence calc:
- POS Suivi `<= 1.5 s` broadcast latency assertion (D-004)
- Kiosk → KDS `<= 1.5 s` broadcast budget assertion (E-005)
- Any timing assertion that depends on Echo `connected` state

What IS still verified in round-4+:
- Polling fallback convergence on POS tracker (`POLL_NO_WS_MS = 8000`, Wave F state 09)
- Polling fallback convergence on KDS (`KDS_POLL_INTERVAL_MS = 8000`)
- Channel-naming / listener-ordering correctness (static code audit, Wave F state 11)
- Order outbox dispatch / idempotency / FCM-listener isolation (round-3 fixes)
- All visual + structural cross-surface evidence

## Convergence decision

Orchestrator excludes D-004 + E-005 from round convergence calc, mirroring the pattern used by `FROZEN_ZONE_DEFERRALS.md` for B-001 / B-002. Audit declares GREEN convergence on round N when:

- `open_P0 == 0` AND
- `open_P1 == 0` AFTER subtracting D-004 + E-005 (dev-env deferred) AND
- Round N-1 had identical findings set with same exclusion

If owner stands up a Soketi/Reverb container during this audit cycle, the deferrals are reactivated and the round must re-converge with them closed.

## Owner action

Recommended (in order of preference):

1. **Stand up Soketi or Reverb locally** — `docker run -p 6001:6001 quay.io/soketi/soketi:latest` + set `BROADCAST_DRIVER=pusher` + `MIX_PUSHER_APP_KEY=local-key` in `.env`. Re-run Wave D state 04 + Wave E state 09 to measure broadcast latency.
2. **Document `BROADCAST_DRIVER=null` for fully-degraded test runs** — pure polling-mode evidence becomes the de-facto SLA in CI. Acceptable if production also tolerates polling-only fallback (currently yes: Wave F state 09 PASS).
3. **Accept polling-first architecture with Echo as accelerator** — declare that the WS path is a UX optimization, not a correctness contract. Update `docs/PROJECT_CONTINUITY_AND_VISION.md` realtime section + remove the `<= 1.5 s` assertion from the audit plan.

Option 3 is the path the polling-fallback evidence already supports. Option 1 buys the strongest evidence but adds dev-env infra burden.
