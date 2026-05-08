<?php

namespace App\Listeners;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Events\OrderStatusChanged;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Outbound status sync.
 *
 * Triggered every time {@see OrderStatusChanged} fires anywhere in the
 * system (POS, KDS, kiosk, admin, this very pipeline's cancel path).
 * The listener filters down to "this order originally arrived from a
 * delivery platform" by looking up `delivery_platform_external_orders`
 * on `frontend_order_id`. Pure-internal orders (kiosk / web / POS) miss
 * the lookup and are silently skipped.
 *
 * Why ShouldQueue + 5 retries:
 *   - Network errors against Uber Eats / Deliveroo / Delicity must NOT
 *     bubble back into the user-facing status update — the order is
 *     already mutated, the push is a side-effect.
 *   - 5 attempts with exponential backoff (10s, 30s, 90s, 5min, 15min)
 *     covers a transient platform outage up to ~17 minutes.
 *   - 4xx responses are persisted but NOT retried (the platform is
 *     telling us our state is wrong; retrying would just hammer the API
 *     with the same bad input).
 *   - 5xx responses ARE retried by re-throwing — Laravel queue catches
 *     it and reschedules per the $backoff array.
 *
 * BranchScope handling:
 *   - Both DeliveryPlatformExternalOrder and DeliveryPlatform apply a
 *     global BranchScope on `branch_id`. Queue workers run without an
 *     auth session, which means the scope WHERE-clauses always resolve
 *     to NULL → 0 rows → silent no-op push. We bypass the scope with
 *     `withoutGlobalScopes()` on every read. This mirrors the pattern
 *     used by DeliveryOrderIngestionService (lines 119-122, 161-164).
 */
class PushStatusToDeliveryPlatform implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Match Phase-2's ProcessDeliveryPlatformWebhookJob backoff so the
     * inbound and outbound retry profiles are aligned.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90, 300, 900];

    /** Match $backoff length so the failed() callback fires last. */
    public int $tries = 5;

    public function __construct(
        protected PlatformAdapterRegistry $registry,
    ) {
    }

    public function handle(OrderStatusChanged $event): void
    {
        $orderId = (int) $event->order->id;

        // ---- 1) Is this order linked to an external platform? -------------
        // BranchScope: queue workers have no auth session, so the global
        // scope resolves to NULL on `branch_id`, yielding zero rows. We
        // bypass it explicitly — the surrounding ingestion pipeline does
        // the same thing (DeliveryOrderIngestionService.php:119-122).
        /** @var DeliveryPlatformExternalOrder|null $external */
        $external = DeliveryPlatformExternalOrder::withoutGlobalScopes()
            ->where('frontend_order_id', $orderId)
            ->first();

        if ($external === null) {
            // Pure-internal order (kiosk / web / POS) — nothing to push.
            return;
        }

        // ---- 2) Does this internal status map to a platform vocabulary? --
        $adapter = $this->registry->for((string) $external->platform);
        $mappedStatus = $adapter->mapInternalStatus((int) $event->newStatus);
        if ($mappedStatus === null) {
            // The platform doesn't have an equivalent for this internal
            // status (e.g. Uber Eats has no "OUT_FOR_DELIVERY" — that's
            // owned by the courier on their side). Skip silently.
            return;
        }

        // ---- 3) Resolve the per-branch DeliveryPlatform config -----------
        /** @var DeliveryPlatform|null $cfg */
        $cfg = DeliveryPlatform::withoutGlobalScopes()
            ->where('platform', $external->platform)
            ->where('branch_id', $external->branch_id)
            ->first();

        if ($cfg === null || !$cfg->enabled) {
            // Platform decommissioned / disabled mid-flight — drop silently.
            // The forensic trail (the unchanged external_orders row) is
            // sufficient to reconstruct what happened.
            Log::info('[PushStatusToDeliveryPlatform] platform disabled or missing', [
                'platform'  => $external->platform,
                'branch_id' => $external->branch_id,
                'order_id'  => $orderId,
            ]);
            return;
        }

        // ---- 4) Push --------------------------------------------------
        try {
            $result = $adapter->pushStatus($cfg, (string) $external->external_id, (int) $event->newStatus);

            $external->forceFill([
                'internal_status' => (int) $event->newStatus,
                'external_status' => $mappedStatus,
                'last_pushed_at'  => now(),
                'push_attempts'   => (int) ($external->push_attempts ?? 0) + 1,
                'last_push_error' => $result->success ? null : $result->error,
            ])->save();

            // Retry on 5xx (server-side hiccup, transient). 4xx is the
            // platform telling us the state is wrong; retrying would just
            // hammer the API. Network errors / timeouts surface as
            // success=false + http_status=null → also retry, since they
            // are presumed transient.
            if (!$result->success) {
                $code = $result->http_status;
                $isTransient = ($code === null) || ($code >= 500 && $code <= 599);
                if ($isTransient) {
                    throw new \RuntimeException(sprintf(
                        '[PushStatusToDeliveryPlatform] transient push failure on %s order=%s status=%s: %s',
                        $external->platform,
                        $external->external_id,
                        $code === null ? 'network' : (string) $code,
                        substr((string) $result->error, 0, 240),
                    ));
                }
                // 4xx — record but don't retry.
                Log::warning('[PushStatusToDeliveryPlatform] non-retryable push failure', [
                    'platform'    => $external->platform,
                    'external_id' => $external->external_id,
                    'http_status' => $code,
                    'error'       => substr((string) $result->error, 0, 240),
                ]);
            }
        } catch (\RuntimeException $e) {
            // Re-throw so the queue picks it up for retry.
            throw $e;
        } catch (\Throwable $e) {
            // Defensive — anything else (DB error mid-update, etc.) gets
            // recorded and re-thrown so the queue still retries.
            $external->increment('push_attempts');
            $external->forceFill([
                'last_push_error' => substr($e->getMessage(), 0, 240),
            ])->save();
            throw $e;
        }
    }

    /**
     * Final-failure hook. After the 5 attempts are exhausted Laravel
     * calls this so we can record the final state — the order itself
     * is unchanged (it transitioned successfully); only the platform
     * sync failed.
     */
    public function failed(OrderStatusChanged $event, \Throwable $exception): void
    {
        $external = DeliveryPlatformExternalOrder::withoutGlobalScopes()
            ->where('frontend_order_id', (int) $event->order->id)
            ->first();

        if ($external !== null) {
            $external->forceFill([
                'last_push_error' => substr(
                    '[FINAL] ' . $exception->getMessage(),
                    0,
                    240,
                ),
            ])->save();
        }

        Log::error('[PushStatusToDeliveryPlatform] final failure after retries', [
            'order_id' => (int) $event->order->id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
