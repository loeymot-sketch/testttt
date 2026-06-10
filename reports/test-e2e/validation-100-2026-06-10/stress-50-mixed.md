# F-017 Suite 7 — Stress Run Report

**Verdict:** PASS
**Generated:** 2026-06-10 07:04:14
**Base URL:** http://127.0.0.1:8766

## Parameters

- orders: 50
- branches: 1
- concurrency: 10
- type: mixed

## Timing

- total_duration_s: 16.732
- batch_duration_s: 9.328
- avg_latency_ms: 186.57
- throughput_rps: 5.36

## HTTP Results

- total: 50
- ok: 50
- failed: 0
- status_breakdown:
  - 201: 50

## DB Invariants

- duplicate_fiscal_sequence_no: 0 (must be 0)
- duplicate_queue_number: 0 (must be 0)
- cross_branch_leak: 0 (must be 0)
- outbox_stale_30s: 8398 (target: 0)

## Notes

- This run is owner-driven; CI structural invariants live in
  `tests/load/RushMidiSimulationTest.php` (PHPUnit @group stress).
- Real Cache::lock contention requires Redis cache + MySQL DB
  (sqlite-memory in CI cannot truly contend).
- A FAIL verdict here means production is at risk: investigate
  before merging.
