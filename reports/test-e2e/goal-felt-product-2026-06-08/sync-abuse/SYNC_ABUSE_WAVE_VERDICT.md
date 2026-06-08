# SYNCHRONIZATION + SECURITY ABUSE WAVE — VERDICT
**Date:** 2026-06-08 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push) · Supervisor: Claude (strict)
**Method:** 2 specialized read-only agents (sync-race auditor + security deep-dive) + a live propagation reality-test driven from the main thread. The owner's named "dernier test abusif" dimension — synchronisation + security — that the prior two waves covered only analytically.

## RESULT: 2 real P1 found + HEALED (1 a V1 security blocker). The sync + mutating core otherwise holds, substantiated. 0 frozen-zone, 0 push.

## ✅ HEALED (commit `e78810e63`, each verified at source + regression-tested)
| Sev | Finding | Fix | Test |
|-----|---------|-----|------|
| **P1 — V1 BLOCKER (security/GDPR)** | `POST /loyalty/check` (`LoyaltyController::check`) returned a victim's **name + points + loyalty_code** by code/phone enumeration to ANY `kiosk:order` token — incl. a **guest token** mintable by anyone with the public client API key. **Proven LIVE**: `{"name":"Victim Secret",...,"loyalty_code":"VICT1234"}`. Sibling of the healed `register()` 409 leak; the falsification sweep had under-rated it P2. | Added the `redeem()` IDOR discriminator — allow only a REAL kiosk machine (`KioskMachine` row), staff, or the account owner; everyone else gets the same **404** as a miss (no existence oracle). | `LoyaltyApiTest::test_check_guest_cannot_enumerate_another_users_pii` + `check()` now acts as authorized staff (7/7) |
| **P1 (sync, functional)** | **SYNC-01** `eventContract.js:401` — `unsubscribe()` called `Echo.leave(channelName)`, tearing down the **SHARED** `branch.{id}` channel for ALL co-subscribers. A child `KioskWaitingComponent` unmount (after an order) killed the kiosk shell's live availability/86-push stream for the rest of the session (shell subscribes once on boot, never re-subscribes). | Detach ONLY this subscriber's handlers — `stopListening` with the specific `rawHandler` (so a co-subscriber on the same event isn't clobbered) and never `Echo.leave` the shared channel. | `eventContractUnsubscribeSync01` (2/2) |

## ✅ POST-CHECK() FULL SWEEP of `LoyaltyController` (advisor-mandated — a 2nd PII-leak in the same file demanded enumerating ALL methods for a 3rd sibling)
Enumerated every method returning name/points/loyalty_code/phone/allergens and confirmed each has the `isKiosk || isStaff || owner` discriminator OR is structurally safe:

| Method | PII returned | Status |
|--------|--------------|--------|
| `check()` :60 | name+points+loyalty_code | **healed** (`e78810e63`) |
| `register()` :138 | 409 path | **healed** earlier (`d27ebb56d`, no existing_phone/code) |
| `scan()` :637 | **first_name + points + declared_allergens** | **HEALED (3rd sibling, `85533d323`)** — see below |
| `addPoints()` :218 | points only | safe — staff-gated at top (`hasAnyRole`→403) |
| `balance()` :403 | delegates to `check()` | safe — inherits check()'s guard |
| `history()` :515 | own ledger | safe — self-scoped to `$request->user()->id`, no enumeration param |
| `optIn()` :423 | delegates to `register()` | safe — inherits register()'s healed 409 |
| `config()` :472 | program config | safe — no PII |
| `generateQr()` :842 | own loyalty_code | safe — self-scoped to `$request->user()` |

### ✅ 3rd sibling HEALED — `scan()` IDOR (commit `85533d323`)
`POST /loyalty/scan` (physical-kiosk QR/NFC) resolves a customer by `loyalty_code` OR phone via the legacy-plaintext path and returned **first_name + points + declared_allergens (GDPR health data)** to ANY `kiosk:order` token (incl. a guest token). Same discriminator added at the top of the method (fires BEFORE any DB lookup → no existence oracle).
- **Severity = conditional**: the plaintext path is gated by `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT` (config default **false**) and the signed path is unforgeable, so the leak is **latent** (only live if the flag is flipped on) — lower than `check()` which was always-live. Healed flag-independently (defense-in-depth) regardless.
- **Borne unaffected (verified at source)**: `KioskMachineLoginController:98` mints the kiosk token on `KioskMachine.user_id` → `isKiosk=true` → real borne passes; `GuestSignupController:146` mints no `KioskMachine` row → guest blocked.
- **Tests** (`LoyaltyApiTest` 9/9): `test_scan_guest_cannot_enumerate_pii` (guest WITH a kiosk:order token — blocked by the discriminator, not the ability gate) + `test_scan_real_kiosk_resolves_customer` (real KioskMachine token → 200, first name + points only).

⇒ Sweep complete: the loyalty PII-enumeration class is fully closed across all 3 sibling methods; no 4th sibling exists.

## ✅ LIVE PROPAGATION REALITY-TEST (`zz-sync-abuse-live`, passing)
Real borne orders (API, rapid sequence on one kiosk session) each: persist in a KDS-visible status with a unique queue number, AND emit **both `OrderCreated` + `OrderStatusChanged` into the `domain_events` outbox**, addressed to `private-branch.1` with the right `broadcast_as`, payloads carrying `order_id` + `queue_number`. The producer side of the real-time fan-out (KDS/OSS/POS-tracker) is wired and fires for every order.
- **Honest scope note:** the *browser-receipt* leg (soketi delivers to a subscribed client) is env-dependent — it needs the `high`-queue worker to drain the outbox + soketi reachable. On this disposable clone the outbox is **not being drained** (`dispatched_at` stays NULL, `attempts=0`, redis `high` empty, no recent failed jobs) — an **OPS state on the clone, not a product defect**: the pipeline is correct and degrades to the abort-guarded polling fallback; prior sessions validated a real client receiving `OrderStatusChanged` on a provisioned deploy. → **deploy gate: ensure the `high`-queue worker + scheduler (`foodking:outbox:rescue`) run in production** (aligns with the known "supervisor/workers" deploy TODO).

## 🟡 BACKLOG — confirmed non-blocking (sync-race + security agents; see `SYNC_RACE_AUDIT.md`, `SECURITY_DEEP_AUDIT.md`)
All view-layer / non-frozen, backend-idempotent (no data loss):
- **SYNC-02 [P2]** POS tracker `fetchOrders()` last-write-wins (no AbortController/seq) → stale column flicker. (Sibling `PosSyncService._poll` already abort-guarded — adopt the same.)
- **SYNC-03 [P2]** OSS `list()` 4 uncoordinated triggers + races the sync service → possible double/dropped chime.
- **SYNC-04 [P3]** POS `_delivering` guard lost on refetch → at worst a redundant idempotent POST.
- **SYNC-05 [P3]** admin `branch_id<=0` push/poll gap (≤60s lag for a branch-0 viewer; cashier/chef are branch_id=1).
- **SYNC-06 [P3]** consumer dedup key omits status → two transitions in one request collapse to one push (missed chime).
- **SEC [P3]** `FrontendOrderService::show()` 403→422 downgrade + message echo (ownership IS enforced; error-mapping nit).

## ✅ ATTESTED CORE-HOLDS (the strong negatives, file:line in the agent reports)
- DB status change is NOT a lost-update (`OrderStateMachine::apply` + `OrderService::changeStatus` both lockForUpdate + idempotent early-return); the "1s version-stamp" is a view flicker.
- Envelope contract enforced LOUDLY server-side BEFORE broadcast (`assertEnvelopeValid` → failed_jobs on mismatch) — the silent-drop class cannot recur.
- Outbox exactly-once (atomic claim); kiosk offline replay idempotent + single-flight (stable localKey).
- Order IDOR safe (BranchScope + `user_id===Auth::id()` gate); `paymentConfirm` owner+amount-echo+dup-txn+idempotent; admin mutation surface fully gated; no secrets in client responses.

## ⇒ The synchronisation + security dimension is V1-sound: the one new security blocker is closed, the one real functional sync bug is closed, the pipeline is proven wired, and every remaining item is a non-blocking view-layer race or a deploy-ops gate — not a code blocker. Whole-project status remains **GO-WITH-OWNER-GATES**.
