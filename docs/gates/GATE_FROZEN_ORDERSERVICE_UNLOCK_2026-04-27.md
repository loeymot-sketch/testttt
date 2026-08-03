# Gate Brief - HG-FROZEN-ORDERSERVICE-UNLOCK - 2026-04-27

Gate ID: `HG-FROZEN-ORDERSERVICE-UNLOCK`  
Date: 2026-04-27  
Author: Codex extension orchestration pass  
Human context: user requested complete POS/Kiosk/KDS/payment/queue/dashboard sync execution and previously approved the Train A unblock decisions in `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`.

## Status

Decision state: APPROVED IN PRINCIPLE BY EXISTING HUMAN ADDENDUM, TECHNICALLY BLOCKED BY STAGED FROZEN FILES.

This brief does not self-approve a new gate. It records that the existing addendum already approved strict hunks for:

- D-M13 queue allocation.
- POS walk-in customer.
- Delivery-fee backend authority.
- POS/Kiosk parity required by those changes.

The manual safety hook still stops because it only checks whether frozen files are staged:

```text
[HALT] Frozen zone staged: app/Services/OrderService.php - gate clearance required.
```

## Frozen Files Currently Involved

| File | Git state | Business surface | Risk |
| --- | --- | --- | --- |
| `app/Services/OrderService.php` | staged + unstaged | POS/web/table order creation, queue, fiscal, discounts, branch guards | P0 |
| `app/Services/FrontendOrderService.php` | staged + unstaged | kiosk/frontend order creation, queue, kiosk payment confirmation | P0 |

## What The Current Diff Contains

### Staged `OrderService.php`

Observed staged surfaces:

- Remove old queue-number fallback using `microtime(true)`.
- Replace repeated queue allocation blocks with `saveOrderWithQueueNumber(...)`.
- Add duplicate retry on DB unique violation.
- Add POS quote sealing through `OrderQuoteService`.
- Add stricter branch checks for delivery/order visibility.
- Add no-op protection for repeated status/payment updates.
- Preserve server-side pricing and cash validation after recalculation.
- Improve discount audit payload.

### Unstaged `OrderService.php`

Observed unstaged surfaces:

- Add `business_date` assignment before queue save.
- Scope queue lock by `branch_id + business_date`.
- Scope queue lookup by `business_date`.
- Detect `orders_branch_business_date_queue_unique`.
- Add `resolveBusinessDate(...)`.

### Staged `FrontendOrderService.php`

Observed staged surfaces:

- Remove old kiosk/frontend queue fallback using `microtime(true)`.
- Add queue allocation helper with duplicate retry.
- Add kiosk quote sealing through `OrderQuoteService`.
- Add branch filter support.
- Add no-op protection on kiosk cancellation/status paths.
- Add fiscal note that kiosk payment confirmation must not allocate fiscal sequence.

### Unstaged `FrontendOrderService.php`

Observed unstaged surfaces:

- Add `Carbon` import.
- Add `business_date` assignment before queue save.
- Scope queue lock and queue lookup by `branch_id + business_date`.
- Detect `orders_branch_business_date_queue_unique`.
- Add `resolveBusinessDate(...)`.

## Invariants That Must Be Preserved

1. Backend pricing remains SSOT. No frontend final-price authority.
2. `OrderStatus` enum remains authoritative. No new magic status strings.
3. `branch_id` isolation remains strict for POS, kiosk, KDS, OSS and delivery boy flows.
4. Events/jobs must be dispatched after DB commit or via the established outbox contract.
5. `OrderService` and `FrontendOrderService` changes require parity notes.
6. Fiscal sequence must not be consumed by kiosk simulated/manual payment confirmation.
7. Queue number uniqueness must be guaranteed by DB strategy, not only app locks.

## Required Human Choice Before More Product Code

Codex can proceed with the remaining implementation only after one of these operational choices is made.

### Option A - Continue With Current Staged Frozen Hunks

Use the existing human addendum as the frozen-zone clearance, keep staged content as part of Train A, and record that `safety-check.sh` is noisy because it does not inspect gate files.

Recommended if the staged hunks are intentionally part of the current release train.

### Option B - Isolate Staging Without Reverting Content

Run a non-destructive unstage of the two frozen files, leaving their working-tree content intact, then restage only files mission by mission.

Recommended if the team wants `safety-check.sh` to pass before each train.

### Option C - Stop Product Edits Until Human/Claude Reconciles The Frozen Diff

No more backend/order product edits. Continue only reports/plans until Claude or the human owner classifies the diff.

Recommended if there is doubt about who owns the staged hunks.

## Technical Recommendation

Option B is the cleanest operational path because it does not delete code and restores mission-by-mission review.  
Option A is acceptable only if the team accepts that `safety-check.sh` will continue to fail until the frozen staging is committed or isolated.

## Evidence Required For Close

- `git diff --cached -- app/Services/OrderService.php app/Services/FrontendOrderService.php` reviewed.
- `git diff -- app/Services/OrderService.php app/Services/FrontendOrderService.php` reviewed.
- Queue sentinels pass.
- POS no-client-ID tests pass.
- Kiosk locked-surface tests pass.
- Full `php artisan test` pass.
- Full `npx vitest run` pass.
- `npm run production` pass.
- Claude terminal audit after Codex implementation, if requested by user.

