# F-017 Suite 7 — Stress Run Report

**Verdict:** FAIL
**Generated:** 2026-05-23 22:04:56
**Base URL:** http://127.0.0.1:8000

## Parameters

- orders: 100
- branches: 1
- concurrency: 2
- type: pos

## Timing

- total_duration_s: 2.657
- batch_duration_s: 2.607
- avg_latency_ms: 26.07
- throughput_rps: 38.36

## HTTP Results

- total: 100
- ok: 0
- failed: 100
- status_breakdown:
  - 422: 100

## DB Invariants

- duplicate_fiscal_sequence_no: 0 (must be 0)
- duplicate_queue_number: 0 (must be 0)
- cross_branch_leak: 0 (must be 0)
- outbox_stale_30s: 751 (target: 0)

## Notes

- This run is owner-driven; CI structural invariants live in
  `tests/load/RushMidiSimulationTest.php` (PHPUnit @group stress).
- Real Cache::lock contention requires Redis cache + MySQL DB
  (sqlite-memory in CI cannot truly contend).
- A FAIL verdict here means production is at risk: investigate
  before merging.
