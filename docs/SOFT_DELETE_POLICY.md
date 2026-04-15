# Soft delete policy (V1)

## Models

The following models use Laravel `SoftDeletes` (`deleted_at`):

- `Order` / `FrontendOrder` (same `orders` table)
- `OrderItem`
- `Branch`
- `ItemCategory`

## Audit

Soft deletes append a row to `deletion_log` (`App\Models\DeletionLog`) via `App\Observers\SoftDeleteAuditObserver` when `delete()` is used (not `forceDelete()`).

## Queries

- Default queries exclude soft-deleted rows.
- Use `withTrashed()` / `onlyTrashed()` where operational restore flows are implemented (admin tooling may be extended in a later cycle).
