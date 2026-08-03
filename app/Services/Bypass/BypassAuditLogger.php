<?php

namespace App\Services\Bypass;

use Illuminate\Support\Facades\Log;

/**
 * [BYPASS-P2] Centralized audit logger for bypass mode activations.
 *
 * Whenever payment.bypass.enabled OR printing.bypass.enabled is true and
 * a guarded code path is executed, we MUST log a structured warning so the
 * audit trail is preserved. This complements the AppServiceProvider boot()
 * production guard (which abort()'s if bypass is enabled in production).
 *
 * Invariants preserved by bypass mode:
 *   - sealing fiscal NF525 (FiscalSequenceService::next + HMAC chain)
 *   - Outbox events (OrderPaidAtCounter, OrderStatusChanged)
 *   - audit log (AuditLogService)
 *   - idempotency middleware
 *
 * Only the hardware HTTP/USB calls (TPE driver, thermal printer TCP/IP) are
 * short-circuited. See docs/runbooks/BYPASS_MODE_OPERATIONAL.md.
 */
class BypassAuditLogger
{
    public static function paymentBypassed(array $context = []): void
    {
        if (!(bool) config('payment.bypass.enabled', false)) {
            return;
        }

        Log::warning('[BYPASS-PAYMENT] TPE call short-circuited — bypass mode active', array_merge([
            'gate' => config('payment.bypass.gate'),
            'env' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    public static function printingBypassed(array $context = []): void
    {
        if (!(bool) config('printing.bypass.enabled', false)) {
            return;
        }

        Log::warning('[BYPASS-PRINTING] TCP/IP printer call short-circuited — bypass mode active', array_merge([
            'gate' => config('printing.bypass.gate'),
            'env' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }
}
