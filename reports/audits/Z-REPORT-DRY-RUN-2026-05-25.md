# Z-report Dry-Run — Bad-Mood FIX-5

**Date** : 2026-05-25
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD pre-fix** : `b3b110844`
**Env** : `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_DATABASE=foodking`
**Tester** : Heal-agent FIX-5 (Claude Code)
**Source finding** : `reports/audits/BAD-MOOD-AUDIT-5-PROD-READY.json` SPOF-07 + P0-5

---

## 1. Why this dry-run

`bad-mood AUDIT-5` flagged that the `z_reports` table was empty (0 rows). Monday 23:59 Paris will be the **first ever** execution of `fiscal:close-all-active-branches` in production. AUDIT-5 P0-5 explicitly required this be exercised on dev DB before Monday so the mechanical close lane is proven before it carries real fiscal data.

The Z-close lane is bound to two artisan commands (both V1-LOCAL safety-nets) :
- `fiscal:open-all-active-branches` (Kernel.php scheduled `00:05` Paris, `GOAL-L2-HEAL-07`)
- `fiscal:close-all-active-branches` (Kernel.php scheduled `23:55` Paris, `GOAL-G2-HEAL-06`)

A cross-chain anchor (`GOAL-K2-HEAL-06`) was added at `app/Providers/AppServiceProvider.php:107-152` so each successful close writes a `z_report.closed` row into the `audit_logs` HMAC chain in addition to signing the `z_reports.signature` itself.

---

## 2. Pre-close state (baseline)

```
=== pre-baseline ===
z_reports TOTAL : 0
z_reports OPEN  : 0
z_reports CLOSED: 0

audit_logs count: 30
audit_logs last_hash (32c): 007c7f6e10845915ee933789ff4e144d

Branches : id=1 "Le Cayenne (principal)" active=Y

Triggers on z_reports : z_reports_no_delete DELETE
Triggers on audit_logs: audit_logs_no_update UPDATE, audit_logs_no_delete DELETE

orders total: 2
orders PAID: 0
```

Chain integrity baseline :

```
$ php artisan fiscal:verify-chain --all
  + branch=1 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
```

OK, clean start. Triggers are installed locally so the DELETE/UPDATE guards mirror the prod expectation (`audit_logs_no_update`, `audit_logs_no_delete`, `z_reports_no_delete`).

---

## 3. Dry-run flags exercised first

Both commands ship with `--dry-run` (specifically to iterate-and-report without touching state). Exercised before any real call to confirm the branch-iteration paths are sane :

```
$ php artisan fiscal:open-all-active-branches --dry-run
fiscal:open-all-active-branches: scanned=1 opened=1 skipped=0 failed=0 (DRY-RUN)

$ php artisan fiscal:close-all-active-branches --dry-run
fiscal:close-all-active-branches: scanned=1 closed=0 skipped=1 failed=0 (DRY-RUN)
```

Predicate logic correct :
- pre-open : 0 OPEN Z exists → open would proceed (1 would-open)
- pre-open : no OPEN Z to close → close skips with `z_close.safety_net.skip` (1 skipped)

No errors, no false alarms, exit 0.

---

## 4. Real Z-open

```
$ php artisan fiscal:open-all-active-branches
fiscal:open-all-active-branches: scanned=1 opened=1 skipped=0 failed=0
```

State after open :

```
z_reports count : 1
z_reports row :
  id           = 1
  branch_id    = 1
  sequence_no  = 1
  opened_at    = 2026-05-25 12:49:29
  closed_at    = null
  opened_by    = null      (cron-driven, no actor)
  closed_by    = null
  total_*      = 0.00 / 0 (empty window)
  prev_hash    = null
  signature    = null       (OPEN does not sign — signature is set on close)
  status       = open
audit_logs count : 30       (unchanged — cross-chain anchor only fires on CLOSE)
```

Behaviour matches `ZReportService::open()` invariants : a new OPEN row is materialised with sequence_no=1, no signature yet (signature is computed at close from the aggregate + prev_hash), no audit_logs entry yet (the K2-HEAL-06 hook is gated on `wasChanged('status') && status===CLOSED`).

---

## 5. Real Z-close

```
$ php artisan fiscal:close-all-active-branches
fiscal:close-all-active-branches: scanned=1 closed=1 skipped=0 failed=0
```

State after close :

```
z_reports count : 1
z_reports row (post-close) :
  id           = 1
  branch_id    = 1
  sequence_no  = 1
  opened_at    = 2026-05-25 12:49:29
  closed_at    = 2026-05-25 12:49:39
  opened_by    = null
  closed_by    = null
  total_ht     = 0.00
  total_ttc    = 0.00
  total_tva    = 0.00
  total_by_method   = []   (empty window)
  total_by_tax_rate = []
  order_count  = 0
  cancel_count = 0
  refund_count = 0
  prev_hash    = null       (first-ever Z, no chained predecessor)
  signature    = 504a44c0e17b71dd5f0b5f01547970d5...  (HMAC SHA-256, full 64c)
  status       = closed
```

Close mechanics verified :
- `closed_at` populated
- `signature` is full 64c hex HMAC SHA-256
- `prev_hash` is null because this is the first Z in the chain (correct ; subsequent closes will chain to this signature)
- aggregates are all-zero / empty because no paid orders existed in the window (intentional — see §7 caveat)

---

## 6. Cross-chain anchor verification (K2-HEAL-06)

The critical signal — the audit_logs chain must have grown by exactly one entry with `action='z_report.closed'` and a full payload tying back to the closed Z row.

```
audit_logs count : 31    (baseline 30 + 1 → exact +1, as expected)

z_report.closed row (audit_logs id=31) :
  id           = 31
  branch_id    = 1
  user_id      = null
  action       = z_report.closed
  resource     = z_report
  resource_id  = 1
  payload      = {
    "closed_at":   "2026-05-25T12:49:39+02:00",
    "closed_by":   null,
    "prev_hash":   null,
    "signature":   "504a44c0e17b71dd5f0b5f01547970d58cc07d9bdc2cfd2dd11365efd1a9ef5e",
    "total_ttc":   0,
    "order_count": 0,
    "sequence_no": 1,
    "z_report_id": 1
  }
  prev_hash    = 007c7f6e10845915ee933789ff4e144d...  (== baseline last_hash)
  current_hash = 81277b1d2ea8d61cdc8c032853fe3069...
  ip           = 127.0.0.1
  user_agent   = Symfony   (artisan via Console kernel — no HTTP request)
  created_at   = 2026-05-25 12:49:39
```

Cross-chain anchor PASSES every assertion :

| Assertion | Expected | Actual | Pass |
|---|---|---|---|
| audit_logs row count delta | +1 | 30 → 31 | OK |
| action | `z_report.closed` | `z_report.closed` | OK |
| resource / resource_id | `z_report` / 1 | `z_report` / 1 | OK |
| payload contains signature | full 64c | 504a44c0...d1a9ef5e | OK |
| payload signature == z_reports.signature | bit-identical | bit-identical | OK |
| payload sequence_no == z_reports.sequence_no | 1 | 1 | OK |
| payload prev_hash == z_reports.prev_hash | null | null | OK |
| audit_logs.prev_hash chains to baseline last | 007c7f6e10845915... | 007c7f6e10845915... | OK |

The chain is continuous : prev_hash of the new audit_logs entry == current_hash of the previous tail. HMAC integrity preserved.

---

## 7. Post-close chain verification

```
$ php artisan fiscal:verify-chain --all
  + branch=1 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
```

Both chains green : `audit_logs` HMAC chain re-walked top-to-bottom OK, `z_reports` chain (1 entry) OK.

---

## 8. What was NOT exercised in this dry-run (honest caveats)

This dry-run **proves the mechanical lane** (open → close → cross-chain anchor → re-verify) but does **NOT** exercise :

1. **Non-empty aggregates.** The dev DB had zero paid orders in the window. The `aggregate()` path runs with all-zero totals : `total_ttc=0`, `total_ht=0`, `total_tva=0`, `order_count=0`. The aggregate-computation invariants (sum of orders matches total_by_method, TVA breakdown is correct, cancel/refund counts populated) were not stressed. Owner Monday close will be the first run against real paid orders.

2. **Cashier-supervised close path.** The dry-run used the artisan command (cron-driven, `opened_by=null`, `closed_by=null`). Real cashier close-of-day via UI may set `closed_by` (cashier user id). The K2-HEAL-06 payload includes `closed_by` so the audit row will reflect the actor.

3. **Concurrent close / open contention.** `Cache::lock('z_report_b{branchId}', 5)` is the concurrency guard inside `ZReportService::close()`. Single-process dry-run did not race this.

4. **Orphaned paid orders pre-check.** `ZReportService::close()` line 229 calls `warnOnOrphanedPaidOrders()` which warns if any kiosk-paid order in the window has not yet received its `fiscal_sequence_no`. Zero orders means zero possible orphans — this branch was a no-op today.

5. **Chain validation pre-close.** `ZReportService::close()` line 201 calls `$this->verifyChain($branchId)` as the first thing inside the transaction. Baseline chain was OK → no throw expected, none observed. If the chain ever gets corrupted in prod, the close will refuse rather than write a bad signature.

6. **Dev DB ≠ prod DB.** Different cache driver in prod (Redis configured), different traffic volume, different user count. Mechanics proven here ; volume not.

---

## 9. Owner-actionable findings

| ID | Finding | Severity | Action |
|---|---|---|---|
| F-1 | Dry-run is CLEAN. Z-open + Z-close mechanics + cross-chain anchor + chain verify all PASS on dev DB. | OK | Monday close can proceed with this evidence. |
| F-2 | Cron will fire at `23:55` Paris (`G2-HEAL-06`) for close, then `00:05` for open. Both `onOneServer + withoutOverlapping + runInBackground`. | INFO | No action — already wired. Verify scheduler is running in prod ( `php artisan schedule:run` cron entry). |
| F-3 | First real close will be against `branch_id=1` with `sequence_no=1` (because dev had `seq=1` ; assuming prod is also virgin). If owner has done any manual tinker-driven close in prod, `seq` will start higher. | INFO | Owner verify : `php artisan tinker --execute='echo \DB::table("z_reports")->count();'` on prod DB. If 0, Monday close = first-ever. If > 0, chain semantics differ. |
| F-4 | Aggregate path (totals / TVA breakdown / methods) was NOT exercised because dev had 0 paid orders. **Owner should manually verify Monday's first close payload by hand** (audit_logs.payload.total_ttc should equal sum of POS+kiosk paid orders in that day's window). | P1 | After first Monday close : `SELECT total_ttc, total_ht, total_tva, total_by_method, order_count FROM z_reports ORDER BY id DESC LIMIT 1` and cross-check vs the day's POS orders. |
| F-5 | Companion `fiscal:open-all-active-branches` cron at `00:05` Paris will open Z `sequence_no=2` for Tuesday. Dry-run open works. If Monday close fails AND Tuesday open fires, Tuesday Z is sequence_no=2 chained to a missing prev close → invariant break (already documented in BAD-MOOD-AUDIT-5 SPOF-07 mitigation gap). | P1 | If Monday `23:55` close fails (`fiscal.error` channel page), STOP the `00:05` Tuesday open cron until close is recovered manually. |
| F-6 | `Cache::lock('z_report_b1', 5s)` in close + chain validator runs WITHIN the same DB transaction. Acquired via Redis. If Redis is dead at `23:55`, close throws `RuntimeException("ZReportService: cannot acquire z_report_b1.")` and Z stays OPEN. | P1 | Owner Monday : verify Redis healthy at `23:50` ; if unhealthy, restart Redis or use the override `php artisan fiscal:close-all-active-branches --branch=1` manually after Redis recovers. |
| F-7 | The `--branch=<id>` override on both commands gives ops a recovery hatch. | INFO | Documented in `--help` of each command. Keep this in the runbook. |

---

## 10. Frozen-zone discipline

- `app/Services/Fiscal/ZReportService.php` : NOT modified (frozen §7). Inspected read-only at lines 180-286 for close path semantics.
- `app/Providers/AppServiceProvider.php` : NOT modified (K2-HEAL-06 cross-chain anchor at lines 77-152 was inspected read-only).
- No code change in this fix. The only file added is this report.

NF525 chain bit-identical : audit_logs went from 30 → 31 via the legitimate K2-HEAL-06 close path. Both verify-chain runs (pre and post) returned `CHAIN OK`. No side-effect on existing rows.

---

## 11. Verdict

**Z-report dry-run : CLEAN.**

The mechanical close lane (open → close → cross-chain anchor) is proven on dev DB. Monday `23:55` Paris should mirror this behaviour mechanically — the only unknowns are aggregate values (non-zero paid orders) and cashier actor population (`closed_by`).

This dry-run discharges AUDIT-5 P0-5 ("Dry-run `fiscal:close-all-active-branches` against real branch_id=1 BEFORE Monday"). The remaining four P0 blockers from AUDIT-5 (payment gateway seed, Spatie roles, disk pressure, POS_SIMULATION_HARDWARE flip) are out of scope of this FIX-5 — see `BAD-MOOD-AUDIT-5-PROD-READY.json` for ownership.

---

## 12. Reproducibility

Anyone can re-run this dry-run with :

```bash
# Pre-baseline
php artisan tinker --execute='echo "z_reports:".\DB::table("z_reports")->count()."\naudit_logs:".\DB::table("audit_logs")->count()."\n";'
php artisan fiscal:verify-chain --all

# Dry exercises
php artisan fiscal:open-all-active-branches --dry-run
php artisan fiscal:close-all-active-branches --dry-run

# Real
php artisan fiscal:open-all-active-branches
php artisan fiscal:close-all-active-branches

# Post-state
php artisan tinker --execute='
$z = \DB::table("z_reports")->orderByDesc("id")->first();
$a = \DB::table("audit_logs")->where("action","z_report.closed")->orderByDesc("id")->first();
echo "z_reports last id={$z->id} status={$z->status} sig=".substr($z->signature,0,16)."\n";
echo "audit_logs anchor id={$a->id} action={$a->action}\n";
echo "payload: {$a->payload}\n";
'
php artisan fiscal:verify-chain --all
```

Expected outputs are reproduced verbatim in §4-§7 above.
