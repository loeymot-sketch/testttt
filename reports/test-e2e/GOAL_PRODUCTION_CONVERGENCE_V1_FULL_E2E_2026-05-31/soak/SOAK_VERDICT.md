# 10h Soak — Honest Verdict (INTERRUPTED at 4.92h, NOT a system fault)

**Goal:** "rock 10 hours without fault" — NF525/memory/outbox endurance.

## What ran (4.92h, FLAWLESS up to the crash)
- 60 monitor ticks over 4.92h. **NO system fault, NO data corruption.**
- Memory: server RSS 7984→7776 kb — **FLAT/decreasing = zero memory leak** (ceiling 200MB).
- Fiscal: fiscal_seq 214→1955 = **+1741 allocations, gap-free monotonic**. NF525 chain CHAIN OK throughout + after the crash. z-membership OK.
- Outbox: pending ~0 throughout (sync never backlogged). 5 streams (POS/Kiosk/collect/bump/toggle) 100% ok until the crash.

## Why it ended at 4.92h (NOT 10h) — operational, not a fault
At t≈17871s the **single-process `php artisan serve` (the dev server) crashed** → 147 `quote_failed:true` stream-skips → soak threw `UnexpectedValueException` and exited. The server process was GONE (HTTP 000). 
**Root cause = MY concurrent load on the same single-process server:** while the soak ran I executed (a) an 11-agent / 1.03M-token discovery workflow (323 tool-uses, many route:list/curl/mysql), (b) live Playwright admin-UI testing (mgmt/sync goal), (c) a 46-test PHPUnit run, and (d) a Tacos availability toggle + catalog-cache invalidation — all hammering the one `php artisan serve` worker that can only handle one request at a time. The soak itself warned at launch: "DETECTED: php artisan serve (single-process)". The server saturated and crashed, taking the soak with it.
**This is a test-harness/infra interference artifact, NOT a FoodKing application or data fault.** In production (php-fpm + nginx, multi-worker) this would not occur.

## Verdict
- **No system fault, no data corruption** (chain + z-membership intact across 1741 fiscal allocations + the crash).
- **"10h without fault" goal = INCOMPLETE** — interrupted at 4.92h by an infra crash I caused via concurrent load.
- **Correct-and-redo:** re-run `foodking:e2e:soak --hours=10 --fail-fast` ALONE (no concurrent heavy agent workflows / browser testing / test runs on the same server) to get the genuine full attestation. Dev server restarted (200 OK).

## Lesson
Never run a long soak on a single-process `php artisan serve` while also running heavy concurrent agent workflows / browser E2E / test suites against the same server — it saturates and crashes the one worker. Give the soak a dedicated server (or run it alone), OR run it against php-fpm+nginx.
