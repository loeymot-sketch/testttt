# F-017 Suite 7 — Stress Run Report

**Verdict:** FAIL
**Generated:** 2026-05-30 02:34:49
**Base URL:** http://localhost:8000

## Parameters

- orders: 100
- branches: 1
- concurrency: 10
- type: pos

## Timing

- total_duration_s: 29.651
- batch_duration_s: 29.596
- avg_latency_ms: 295.96
- throughput_rps: 3.38

## HTTP Results

- total: 100
- ok: 0
- failed: 100
- status_breakdown:
  - 401: 100

## DB Invariants

- duplicate_fiscal_sequence_no: 0 (must be 0)
- duplicate_queue_number: 0 (must be 0)
- cross_branch_leak: 0 (must be 0)
- outbox_stale_30s: 0 (target: 0)

## Notes

- This run is owner-driven; CI structural invariants live in
  `tests/load/RushMidiSimulationTest.php` (PHPUnit @group stress).
- Real Cache::lock contention requires Redis cache + MySQL DB
  (sqlite-memory in CI cannot truly contend).
- A FAIL verdict here means production is at risk: investigate
  before merging.
