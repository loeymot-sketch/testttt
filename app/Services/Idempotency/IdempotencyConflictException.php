<?php

namespace App\Services\Idempotency;

/**
 * F-VERIFY-09-02 — Raised when the same idempotency key is reused with a
 * different payload hash (RFC draft IETF idempotency-key §2.6).
 */
class IdempotencyConflictException extends \RuntimeException
{
}
