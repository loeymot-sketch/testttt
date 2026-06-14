# Supervisor independent cross-check (parallel to the 8-lane audit army)

## Scheduled-task landscape (Kernel.php) — reactive surface
outbox:rescue/monitor/retry-failed/prune · CleanupStalePendingKioskOrders (PR-01 auto-reject) ·
fiscal:close-all-active-branches@23:59 + open@00:01 · pos:purge-parked-orders@03:15 ·
stock:scan-rupture (*/5 auto-86) · fiscal:retry-alloc (everyMinute, my COD extension) ·
availability:reset-stale-quota · sanctum:prune-expired · backup-daily · storage:cleanup.

## CROSS-CHECK FINDING (my own G-DELIV-FISCAL heal, second-degree) — Z-WINDOW LATE-SALVAGE
**Candidate P2 (NF525 edge).** My G-DELIV-FISCAL retry-cron salvage (RetryFiscalAllocCommand generic branch, fiscal:retry-alloc everyMinute) allocates a fiscal_sequence_no for a COD delivery whose INLINE allocation failed. If that retry fires AFTER the day's `fiscal:close-all-active-branches`@23:59, the sale escapes the Z entirely: day-N's Z was signed without the seq, and day-N+1's Z aggregates by order_datetime so it excludes a day-N order. The normal (inline, at-DELIVERED) path is correct — only the rare alloc-failure + cross-midnight-retry path leaks.
**Repro reasoning:** ZReportService aggregates `whereNotNull(fiscal_sequence_no)` within an order_datetime window; a seq stamped after that window's Z is signed lands the order in neither window.
**Recommendation:** (a) make the retry-cron skip (or alert) orders whose order_datetime is in an already-CLOSED Z window, and surface them as a manual reconciliation item; OR (b) when allocating a late seq, attribute it to the open Z window with an audit note. Owner-gate (touches Z semantics). Latent (rare double-failure path) — P2.
