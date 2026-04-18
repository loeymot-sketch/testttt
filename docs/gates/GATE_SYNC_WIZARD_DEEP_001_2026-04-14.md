# Gate Brief – SYNC_WIZARD_DEEP_001 – 2026-04-14

## Trigger

A1 requires adding `round($value, 2)` to monetary calculations in two frozen zone files:

- `app/Services/FrontendOrderService.php` (kiosk/frontend order pricing)
- `app/Services/OrderService.php` (POS order pricing)

Both files are frozen zones per `.cursor/rules/project-invariants.mdc`.

## Affected Subsystems

- `FrontendOrderService.php` — tax, subtotal, total calculation (lines 295-296, 302, 321, 452-455)
- `OrderService.php` — tax, subtotal, total calculation (lines 661-668, 690-691, 730, 751-753)

## Invariants at Risk

- **Backend pricing SSOT** — the fix strengthens this invariant (ensures clean 2-decimal values in DB)
- **OrderService / FrontendOrderService symmetry** — same `round()` pattern must be applied to both services identically
- **Dispatch after DB commit** — A1 does NOT move dispatch calls; only wraps existing arithmetic in `round()`

## What Changes

**FrontendOrderService.php** (~6 lines):

- L.302: `$taxPrice = round((...), 2)` — per-line tax
- L.295: `$verifiedTotalPrice = round((...), 2)` — per-line total
- L.452: `$this->frontendOrder->total_tax = round($totalTax, 2)`
- L.453: `$this->frontendOrder->subtotal = round($realSubtotal, 2)`
- L.455: `$this->frontendOrder->total = round(max(0, ...), 2)`

**OrderService.php** (~6 lines):

- L.668: `$taxPrice = round((...), 2)` — per-line tax
- L.661-663: `$verifiedTotalPrice = round((...), 2)` — per-line total
- L.730: `$this->order->total_tax = round($totalTax, 2)`
- L.751: `$this->order->subtotal = round($realSubtotal, 2)`
- L.753: `$this->order->total = round(max(0, ...), 2)`

## Impact Assessment

- **Existing orders in DB:** Unaffected (no migration)
- **Future orders:** Will have clean 2-decimal monetary values instead of potential floating-point drift (e.g., 13.499999 → 13.50)
- **Risk:** Extremely low — `round($x, 2)` is a narrowing operation on already-intended-to-be-2-decimal values
- **Rollback:** Remove `round()` wrappers — zero data impact

## Decision Required

Approve modification of both frozen zone files (FrontendOrderService.php + OrderService.php) for the sole purpose of adding `round($value, 2)` on monetary calculation results before DB persistence.

## Options

1. Approve — add `round($value, 2)` to both services (symmetric, strengthens pricing SSOT)
2. Defer — skip A1, document the rounding gap for a future dedicated cycle
3. Cancel cycle

## Approval

[x] Approved — option selected: 1
[ ] Cancelled
Approved by: Kossay (human)
Date: 2026-04-14