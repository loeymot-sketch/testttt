<?php

namespace App\Services\Idempotency;

/**
 * F-VERIFY-09-02 — Raised when the underlying cache store cannot be reached.
 *
 * The middleware catches this and either fails-closed (503) or fails-open
 * depending on `config('idempotency.fail_open')`.
 */
class IdempotencyStorageUnavailableException extends \RuntimeException
{
}
