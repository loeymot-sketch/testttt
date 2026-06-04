# F-017 Suite 7 — Stress Run Report

**Verdict:** FAIL
**Generated:** 2026-05-24 19:33:18
**Base URL:** http://localhost:8000

## Parameters

- orders: 50
- branches: 1
- concurrency: 5
- type: mixed

## Timing

- total_duration_s: 1.574
- batch_duration_s: 1.036
- avg_latency_ms: 20.71
- throughput_rps: 48.28

## HTTP Results

- total: 50
- ok: 0
- failed: 50
- status_breakdown:
  - 403: 25
  - 422: 25

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
