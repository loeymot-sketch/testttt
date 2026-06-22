EXECUTE_DELEGATION: foodking-complex-implementer

# RUN V14 T15 - HARDWARE PRINTER ESC/POS

## Status

PASSED

## DB schema

Table `printers`:

- `id`
- `branch_id`
- `name`
- `type`
- `host`
- `port`
- `station`
- `width_chars`
- `status`
- `options`
- `created_at`
- `updated_at`

Indexes / constraints:

- `printers_branch_status_idx` on (`branch_id`, `status`)
- `printers_branch_station_idx` on (`branch_id`, `station`)
- FK `branch_id -> branches.id` with cascade delete

## Endpoints

- `GET /api/admin/printers`
- `POST /api/admin/printers`
- `GET /api/admin/printers/{printer}`
- `PUT|PATCH /api/admin/printers/{printer}`
- `DELETE /api/admin/printers/{printer}`
- `POST /api/admin/printers/{printer}/test-print`

## Tests

- `php artisan test --filter='Printer'`: PASSED (`10/10`)
  - `PrinterServiceTest`: `6/6`
  - `PrinterControllerTest`: `4/4`
- `php artisan test --filter='Pos|Order|Pricing'`: no new regression introduced
  - Result: `354 passed`, `3 failed`, `3 skipped`
  - Tolerated pre-existing failures only:
    - `Tests\Feature\DispatchAfterCommitTest` (`OrderCreated`)
    - `Tests\Feature\DispatchAfterCommitTest` (`OrderStatusChanged`)
    - `Tests\Feature\Orders\OrderAllergenSnapshotComposedTest`
- `npx vitest run tests/js/posPrinter.spec.js tests/js/PosComponent.spec.js`: PASSED (`4/4`)
  - `posPrinter.spec.js`: `3/3`
  - `PosComponent.spec.js`: `1/1`

## Files created / modified

- `database/migrations/2026_04_20_210000_create_printers_table.php`
- `app/Models/Printer.php`
- `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php`
- `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php`
- `app/Services/Hardware/PrinterTransport/NullPrinterTransport.php`
- `app/Services/Hardware/EscPosCommandBuilder.php`
- `app/Services/Hardware/EscPosPrinterService.php`
- `app/Http/Controllers/Admin/PrinterController.php`
- `app/Http/Requests/Admin/PrinterRequest.php`
- `app/Http/Resources/PrinterResource.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `resources/js/services/posPrinter.js`
- `resources/js/languages/en.json`
- `resources/js/languages/fr.json`
- `resources/js/languages/ar.json`
- `tests/Feature/PrinterServiceTest.php`
- `tests/Feature/PrinterControllerTest.php`
- `tests/js/posPrinter.spec.js`

## Invariant-sensitive checks

- Pricing SSOT untouched
- No `OrderService`, `FrontendOrderService`, `PricingService`, `PaymentService`, `Fiscal/*`, `PaymentComponent.vue`, `ReceiptComponent.vue`, `PosComponent.vue`, `kioskHardware.js`, `kioskPrinter.js`, or `ItemComponent.vue` edits
- `Printer` is branch-scoped through `BranchScope`
- TCP transport uses `fsockopen(..., 2.0)` + `stream_set_timeout(..., 2)` to stay below the allowed blocking window
- Testing environment binds `NullPrinterTransport`; production/non-testing binds `TcpPrinterTransport`
- No external ESC/POS dependency added

## Residual TODOs

- Auto-print after payment remains deferred to a later cycle / E2E integration
- USB / WebUSB transport remains future work
- `ReceiptComponent` integration belongs to `T21+`
- Future T21 integration could call `posPrinter.testPrint(printerId)` after mount or after explicit operator action

## Notes

- `php artisan migrate` confirms the migration is registered and `printers` exists locally.
- `.cursor/hooks/post-execute.sh` was executed. Its generic `php artisan test --stop-on-failure` run stops on the same pre-existing out-of-scope backend failures listed above.
