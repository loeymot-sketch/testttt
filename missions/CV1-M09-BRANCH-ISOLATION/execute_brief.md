# EXECUTE BRIEF — CV1-M09-BRANCH-ISOLATION (M-09)

## Inviolable

1. Read `AGENTS.md`, this mission `input.json`, this `plan_excerpt.md`, and the M-09 section of the parent plan before editing.
2. Gate frozen is approved only as Option C — Partial allowlist by method/surface.
3. Do not touch fiscal, schema, KDS, offline, web payment, Stripe, routes, migrations, or UI files.
4. Touch only the allowlist.
5. Rework scope is limited to sentinels #8, #9 and #11 because they are marked `@fix-mission CV1-M09-BRANCH-ISOLATION`.

## Objective

Replace unsafe branch-id pattern matching with exact branch equality in the plan-listed service paths, formalize the `branch_id=0` policy, and add tests plus lint to prevent recurrence.

## Implementation requirements

- `OrderService.php`:
  - In generic `$orderFilter` handling, special-case `branch_id` with exact equality.
  - Keep existing LIKE behavior for textual search fields.
  - In `salesReportOverview`, use exact equality for `branch_id`.
  - Formalize `branch_id=0` policy as admin-global only at the plan-listed paths. Do not widen access.
- `FrontendOrderService.php`:
  - Use exact equality for `branch_id`.
- `scripts/lint-fk-branch-isolation.sh`:
  - Fail if product PHP code contains `where('branch_id', 'like')`, `orWhere('branch_id', 'like')`, or obvious double-quoted variants.
  - Exclude generated/vendor/test files where appropriate.
- `tests/Feature/Branch/`:
  - Add focused tests for no prefix bleed (`1` must not match `10`), exact branch list/show/report behavior where feasible, and admin-global `branch_id=0` policy.
- Branch guards:
  - `GET /api/admin/pos-order/show/{order}` must return 403 for staff from another branch.
  - `GET /api/admin/transaction?branch_id=<foreign>` must return 403 for staff from another branch, and non-global actors must never get unscoped cross-branch transactions.
  - `GET /api/admin/oss-order?branch_id=0` must return 403 for non-admin staff and 200 for real global Admin (`Admin` role + `branch_id=0`).

## Validation

Run the most focused passing commands available:

```bash
bash scripts/lint-fk-branch-isolation.sh
php artisan test --filter=Branch
php artisan test --filter=Sentinel
```

If the full sentinel filter is too broad or blocked by unrelated/future-gated failures, record exact failing tests. M-09 cannot close unless #7, #8, #9 and #11 pass. #10 is fiscal M-08 and remains blocked by fiscal gate.

## Output contract

Return one JSON object with `files_to_modify`, `implementation_steps`, `code_blocks`, `risks`, `notes`, and `execution_trace`. Include `execution_trace.delegation = "codex-extension"` and list the invariants checked.

Include a `SYMMETRY_NOTE` in `notes` because both OrderService and FrontendOrderService are in scope.
