<?php

namespace App\Domain\Delivery\Exceptions;

use RuntimeException;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Thrown when an inbound webhook's HMAC / signature header fails
 * verification against the per-branch webhook_secret.
 *
 * Always logs to delivery_webhook_events with signature_valid=0.
 * Controllers translate this to an HTTP 401 (not 400) so that
 * the platform can rotate keys without ambiguity.
 */
final class SignatureMismatchException extends RuntimeException
{
}
