# Adversarial RED — Fiscal+KDS Heals Wave 2 — Wave 3 dispute

**Branch** `v1-0-1-hardening-2026-05-17` @ `f24b49c42`
**Commits** `048c48439` (`feat(fiscal): fiscal:verify-chain`), `181abdef4` (`test(kds): sargable sentinel`; prod heal hitchhiked in `8dc6ec331`).
**Stance** HOSTILE, read-only. NF525 mandatory. NO cloud. Frozen-zone edits NOT proposed — remediation targets only the new wrapper, tests, `Kernel.php`, non-frozen `KdsSyncService.php`, and `config/database.php`.

---

## 1. Heal 5 — `fiscal:verify-chain` CLI — verdict: **HEAL-INSUFFICIENT**

Primitive exists, happy/tamper paths covered. Operationally dishonest: cannot distinguish `CHAIN OK` from non-existent branch / DB outage / missing secret, and is not scheduled. CLAUDE.md §8 demanded a primitive — got one, not a usable one.

**FISCAL-ADV3-01 — Empty result ≡ false-negative `CHAIN OK`** **P1**
`FiscalVerifyChainCommand.php:38-46` casts `--branch` to int and forwards to `AuditLogService::verifyChain()`. Service at `AuditLogService.php:199-225` iterates `AuditLog::query()->where('branch_id', $branchId)->orderBy('id')->cursor()`. Branch N with zero rows → foreach skipped → returns `null` → command prints `CHAIN OK (branch=N)` exit 0. Test `FiscalVerifyChainCommandTest.php:84-114` does NOT exercise branch-does-not-exist. Owner running `--branch=99` against a single-resto Le Cayenne gets green on a chain that does not exist. Oracle broken: "no rows" must be WARNING / non-zero, not `CHAIN OK`.

**FISCAL-ADV3-02 — Exit-code collapse: any failure ≡ TAMPER** **P1**
`FiscalVerifyChainCommand.php:36-55` has no try/catch. All failure modes share exit `1`:
- DB unreachable → `QueryException` → exit 1.
- `fiscal.audit_secret` unset → `AuditLogService.php:271-275` `RuntimeException` → exit 1.
- Weak secret in prod → `assertProductionSafe` `AuditLogService.php:294-318` → exit 1.
- Real tamper → `self::FAILURE` = 1.

Monitoring on `!= 0` cannot disambiguate tamper from infra outage from misconfig. CLAUDE.md §8 frames this as last line of fiscal defense — exit codes must be machine-distinguishable. Recommend `1`=tamper, `2`=infra/config, structured stderr JSON.

**FISCAL-ADV3-03 — Not scheduled, no alerting wired** **P1**
Grep on `app/Console/Kernel.php` for `verify-chain`/`VerifyChain`: zero hits. `schedule()` registers `foodking:fiscal:retry-alloc` (line 142) and `foodking:fiscal:archive` (line 171) but never the new primitive. CLAUDE.md §8 implies continuous integrity, not human-pull. Tamper between Z-archive runs (which call `ZReportService::verifyChain` — `ZReportService.php:88,201`) is detected only when an operator types the command. Detection window unbounded.

**FISCAL-ADV3-04 — `(int)` silently swallows malformed input** **P2**
`FiscalVerifyChainCommand.php:38`: `--branch=abc` → 0, `--branch=""` → 0, `--branch=-5` → -5. No validation. Typo verifies system chain instead of failing loudly. Require numeric, positive, `Branch::where('id', $branchId)->exists()`.

**FISCAL-ADV3-05 — Hard-coded default `--branch=1`** **P2**
`FiscalVerifyChainCommand.php:32` pins Le Cayenne to `branch_id=1` forever. Branch-ID reshuffle → owner sees `CHAIN OK (branch=1)` on a dead chain, misses tamper on the live one. Drop default; require explicit `--branch=`.

**FISCAL-ADV3-06 — No cross-branch walk from CLI** **P2**
`verifyChain(?int $branchId = null)` has a null path (`AuditLogService.php:200-203`) walking the global table. CLI always `(int)` — null unreachable. V1 single-resto: tolerable. V1.0.2 multi-branch: blind. Accept `--branch=all` → pass `null`.

**FISCAL-ADV3-07 — Trigger reject path never CI-covered** **NEGATIVE SPACE / P2**
`FiscalVerifyChainCommandTest.php:70-77` drops triggers, forges, reinstalls. Chain detection is hash-based + trigger-independent BY DESIGN (`AuditLogService.php:213-222`) — sound. But RefreshDatabase = SQLite; trigger migration `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:65-83` is MySQL-only. Production-path immutability has zero CI coverage on this command.

**Process smell**: commit `181abdef4`'s own message admits prod KdsSyncService change rode in on `8dc6ec331` (`fix(outbox)`, parallel agent). Heal split across two commits with mismatched scope — governance smell.

---

## 2. Heal 1 — KDS `whereDate → whereBetween` — verdict: **HEAL-INSUFFICIENT**

Syntactic non-sargable form gone, sentinel pins it. Three substantive defects remain plus one P0 TZ skew.

**KDS-ADV3-01 — Timezone skew: PHP Paris-local vs MySQL UTC session** **P0**
`KdsSyncService.php:70-75` calls `Carbon::today()`/`Carbon::tomorrow()`. Resolved via `config/app.php:'timezone' => 'Europe/Paris'`. Bound rendered as naive `'2026-05-18 00:00:00'` (Paris-local), shipped to DB.
**Verified**: `config/database.php` has NO `timezone` key on the `mysql` connection. Default → MySQL session TZ = server TZ, typically UTC on cloud RDS/Docker. `orders.order_datetime` is `timestamp` (`2022_11_17_110810_create_orders_table.php:31`) — TIMESTAMP converts on read/write using session TZ.
Prod consequence:
- Paris-local 00:00–02:00 orders stored as UTC 22:00–00:00 prev-day → fall OUTSIDE the BETWEEN range → KDS sync misses them.
- Inverse: UTC prev-day 22:00+ orders pulled in inappropriately.
Magnitude: 1h winter / 2h summer of nightly orders silently dropped. NF525-adjacent — DELIVERED transitions invisible to KDS during the window. Fix: `->setTimezone('UTC')` on bounds, or `'timezone' => '+00:00'` on mysql config.

**KDS-ADV3-02 — Composite index missing; sentinel proves syntax, not plan** **P1**
`idx_orders_datetime` is single-column (`2026_03_12_130000_add_performance_indexes.php:28-29`). Query at `KdsSyncService.php:65-77` filters `status IN (...)` + `updated_at >=` + `order_datetime BETWEEN` + optional `branch_id =`. MySQL picks ONE index; with high-selectivity `branch_id`+`status` available, it likely picks neither this nor any of them. WhereBetween necessary but not sufficient — heal ships zero EXPLAIN evidence. Sentinel asserts SQL shape, not plan. Capture EXPLAIN on MySQL fixture; consider composite `(branch_id, status, order_datetime)`.

**KDS-ADV3-03 — Sentinel runs on SQLite — the exact driver the audit said masks the bug** **P1**
`KdsSyncSargableTest.php:33` uses `RefreshDatabase` → SQLite in-memory. Wave 1 audit's framing: SQLite hides MySQL `DATE()` non-sargability. Sentinel catches a regression to `whereDate()` (via `strftime` regex `:121-126`) but can NEVER prove plan on MySQL. No MySQL CI job referenced. Performance assertion the audit demanded remains unsubstantiated.

**KDS-ADV3-04 — Advance branch has no lower bound** **P1**
`KdsSyncService.php:74` filters `where('order_datetime', '<', Carbon::tomorrow())`. No floor. Advance orders from months ago that somehow remained `ACCEPT/PREPARING/PREPARED` (dev data, abandoned advance, status-machine bugs from Wave 5F) all flow. `whereIn(status, [...])` keeps the visible count small but the optimizer's range scan widens unbounded over 12+ months. Add `>= Carbon::today()->subDays(7)`.

**KDS-ADV3-05 — Per-client `since` defeats the 5s cache** **P2**
`KdsSyncService.php:42-47` cache key includes `md5($since->format(DATE_ATOM) . '|' . (int) $includeDeleted)`. Every KDS client polls with its own `since` → different keys → DB hit every time. Minute-bucket + `Cache::remember(..., 5)` is effectively dead under multi-terminal load. Sargable predicates matter only when cache misses — and the cache currently always misses. Out of scope for this heal but flagging because perf was the audit's framing.

---

## 3. P0 / P1 findings

| ID | Sev | Surface | One-line |
|---|---|---|---|
| KDS-ADV3-01 | **P0** | `KdsSyncService.php:70-75` + `config/database.php` (missing tz) | PHP Paris bounds vs MySQL UTC → 1-2h nightly orders dropped from KDS sync |
| FISCAL-ADV3-01 | P1 | `FiscalVerifyChainCommand.php:38-46` | Non-existent branch → `CHAIN OK` exit 0 (false-negative oracle) |
| FISCAL-ADV3-02 | P1 | `FiscalVerifyChainCommand.php:36-55` | Infra / config errors share exit `1` with TAMPER |
| FISCAL-ADV3-03 | P1 | `Kernel.php` (absent) | Command exists but not scheduled — detection window unbounded |
| KDS-ADV3-02 | P1 | `2026_03_12_130000_add_performance_indexes.php:28-29` | Single-column `idx_orders_datetime`; no EXPLAIN evidence |
| KDS-ADV3-03 | P1 | `KdsSyncSargableTest.php:33` | Sentinel on SQLite — proves shape, not MySQL plan |
| KDS-ADV3-04 | P1 | `KdsSyncService.php:74` | Advance branch unbounded below |

P2: FISCAL-ADV3-04/05/06/07, KDS-ADV3-05.

---

## 4. Negative space

- No EXPLAIN / EXPLAIN ANALYZE shipped — heal claims 10x-30x without prod-equivalent measurement.
- No MySQL CI job — both new tests SQLite. Audit's framing (MySQL `DATE()`) never E2E-exercised.
- No `verifyChain` perf data at scale — `cursor()` over N; threshold N unknown.
- No cron / alerting pipeline — CLI without operational call site.
- No TZ regression test — RefreshDatabase + SQLite + Carbon all Paris, bug invisible in CI.
- No multi-terminal cache test — 5s window dead under realistic load, never measured.
- No production-immutability path coverage — fiscal test never runs on a driver where the `BEFORE DELETE` trigger lives (`2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:65-83`, MySQL-only).

---

**Wave 3 RED verdict**: both heals **HEAL-INSUFFICIENT**. The CLI and the sentinel landed; the operational posture they were supposed to close did not. Wave 4 must address KDS-ADV3-01 (P0) before V1 ships to any deployment whose MySQL session TZ ≠ Europe/Paris.
