<?php

namespace App\Domain\Delivery;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Result of pushing an internal status change back to the
 * external platform (e.g. "we marked this order PREPARED, please
 * notify your courier").
 *
 * The DTO is intentionally minimal so that the persistence layer
 * (DeliveryPlatformExternalOrder.last_pushed_at /
 * last_push_error / push_attempts) can record outcome without
 * leaking adapter internals.
 */
final class PushResult
{
    /**
     * @param  array<string,mixed>|null  $raw_response
     */
    public function __construct(
        public readonly bool    $success,
        public readonly ?int    $http_status = null,
        public readonly ?string $error = null,
        public readonly ?array  $raw_response = null,
    ) {
    }
}
