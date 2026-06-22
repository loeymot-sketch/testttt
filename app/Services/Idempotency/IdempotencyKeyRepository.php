<?php

namespace App\Services\Idempotency;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F-VERIFY-09-02 — Storage abstraction for HTTP-level idempotency.
 *
 * Implementations MUST provide atomic `acquire()` semantics (equivalent to
 * `SET NX EX` on Redis). Tests use a Cache-backed implementation that works
 * across the array, file and redis cache drivers.
 */
interface IdempotencyKeyRepository
{
    /**
     * Look up an existing record. Returns null when no record (or only a
     * PENDING placeholder) is stored.
     *
     * @throws IdempotencyStorageUnavailableException
     */
    public function find(string $scopedKey): ?IdempotencyRecord;

    /**
     * Atomically reserve the key with a PENDING placeholder. Returns true
     * when the caller acquired the lock, false when a concurrent request
     * already holds it.
     *
     * @throws IdempotencyStorageUnavailableException
     */
    public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool;

    /**
     * Persist the final response (only invoked on 2xx).
     */
    public function complete(string $scopedKey, SymfonyResponse $response, string $payloadHash, int $ttlSeconds): void;

    /**
     * Remove the placeholder so the next request may retry.
     */
    public function release(string $scopedKey): void;

    /**
     * Poll up to $waitMs for a concurrent caller to publish a COMPLETED record.
     */
    public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord;
}
