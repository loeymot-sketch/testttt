<?php

namespace App\Domain\Delivery\Exceptions;

use RuntimeException;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Thrown by an adapter's parseOrder() when the platform payload
 * cannot be mapped to a NormalizedDeliveryOrder (missing required
 * fields, unknown SKU, malformed totals, etc.).
 *
 * Distinct from SignatureMismatchException: a signature failure
 * means "I do not trust the sender", whereas an order-mapping
 * failure means "I trust the sender but their payload is broken".
 */
final class OrderMappingException extends RuntimeException
{
}
