# Plan – TASK_V1_SYNC_BACKBONE_001 – 2026-04-15

## TASK_ID
TASK_V1_SYNC_BACKBONE_001

## PRIMARY_MODEL
Composer (routine config + frontend adapter)

## TEST_STRATEGY
local-validation

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `config/broadcasting.php` | Remove silent null fallback | Write | No | No |
| `config/queue.php` | Remove silent sync fallback | Write | No | No |
| `app/Providers/AppServiceProvider.php` | Fail-fast prod boot guards | Write | No | No |
| `.env.example` | Already good — minor polish | Write | No | No |
| `resources/js/components/common/ConnectionStatusBanner.vue` | New — reconnect banner | Write | No | No |
| `resources/js/components/admin/pos/PosComponent.vue` | Import + use banner | Write | No | No |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Import + use banner | Write | No | No |
| `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` | Import + use banner | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | Import + use banner | Write | No | No |
| `docs/PRODUCTION_SETUP.md` | New — Horizon/Supervisor/Pusher doc | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — frozen zone
- `app/Services/FrontendOrderService.php` — frozen zone
- `app/Events/*` — reserved for OUTBOX_001 / EVENT_CONTRACT_001
- `database/migrations/*` — no schema changes in this task

## INVARIANTS_AT_RISK
- None — this task touches config, boot guards, frontend UI banner, and docs only. No pricing, no OrderStatus, no branch_id, no dispatch logic, no order services.

## GATE_CONDITIONS
- None anticipated.
- Stop-gate if: scope expansion into `app/Events/*` or `OrderService.php`.

## PRIOR_CONTEXT
Graphiti unavailable — proceeding without prior context.

## Execution Steps

### E1 — Config hardening (backend)
1. `config/broadcasting.php`: change default from `env('BROADCAST_DRIVER', 'null')` to `env('BROADCAST_DRIVER')` (no fallback — forces explicit config).
2. `config/queue.php`: change default from `env('QUEUE_CONNECTION', 'sync')` to `env('QUEUE_CONNECTION', 'sync')` — keep sync for local dev but add boot guard for prod (step E2). No change needed here since the boot guard handles prod safety.
3. **Decision**: keep `queue.php` default as `sync` for local dev convenience. The boot guard in E2 catches prod misconfig.

### E2 — Fail-fast boot guards (AppServiceProvider)
Add to `AppServiceProvider::boot()` after existing code:
```php
if (app()->environment('production')) {
    if (in_array(config('broadcasting.default'), [null, 'null'], true)) {
        throw new \RuntimeException(
            'BROADCAST_DRIVER must be explicitly set in production (expected: pusher|redis). '
            . 'Set BROADCAST_DRIVER in your .env file.'
        );
    }
    if (config('queue.default') === 'sync') {
        throw new \RuntimeException(
            'QUEUE_CONNECTION must not be sync in production (expected: redis|database). '
            . 'Set QUEUE_CONNECTION in your .env file.'
        );
    }
}
```

### E3 — ConnectionStatusBanner.vue
Create `resources/js/components/common/ConnectionStatusBanner.vue`:
- Import `wsService`, `WS_STATE` from `@/services/WebSocketService`.
- Listen to `state_change` events.
- Show nothing when connected.
- Show yellow banner "Reconnexion en cours…" when disconnected for > 5s.
- Show red banner "Hors ligne" when disconnected for > 30s.
- Fixed position top bar, z-index high, non-blocking.

### E4 — Integrate banner into surfaces
Add `<ConnectionStatusBanner />` at the top of the `<template>` in:
1. `PosComponent.vue`
2. `KitchenDisplaySystemComponent.vue`
3. `OrderStatusScreenComponent.vue`
4. `KioskAppComponent.vue`

Each: import component, register in `components: {}`, add tag.

### E5 — Documentation
Create `docs/PRODUCTION_SETUP.md`:
- `.env.production` example (Pusher keys, Redis URL, queue config).
- Supervisor/systemd section for `php artisan queue:work`.
- Horizon section (optional).
- Diagnostic commands.

### E6 — Validation
1. `php artisan config:clear && php artisan config:show broadcasting` → verify no hardcoded null.
2. `npm run build` → 0 errors.
3. Static inspection: confirm no `app/Events/*` or order service edits.

## SYMMETRY_NOTE
N/A — OrderService / FrontendOrderService not in scope.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[x] Passed — cycle closed 2026-04-15
[ ] Gate opened — `docs/gates/GATE_TASK_V1_SYNC_BACKBONE_001_2026-04-15.md`
