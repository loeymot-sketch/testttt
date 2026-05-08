<?php

namespace App\Domain\Delivery;

use App\Models\DeliveryPlatform;
use Illuminate\Http\Request;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Contract every per-platform adapter must satisfy
 * (UberEatsAdapter, DeliverooAdapter, DelicityAdapter).
 *
 * The interface intentionally splits responsibilities so that
 * the controller layer never speaks platform-specific JSON:
 *
 *   verifySignature() — trust gate (HMAC against webhook_secret)
 *   externalIdFrom()  — dedupe key (used BEFORE parsing)
 *   eventTypeFrom()   — routing (created / accepted / canceled / etc.)
 *   parseOrder()      — normalisation to NormalizedDeliveryOrder
 *   mapInternalStatus() — outbound: FoodKing OrderStatus → platform string
 *   pushStatus()      — outbound HTTP call
 *
 * The Request is the Laravel Illuminate variant, NOT the Symfony
 * one — adapters often need request()->ip() and other Laravel
 * helpers, and the explicit type-hint avoids container mistakes
 * downstream.
 */
interface PlatformAdapter
{
    /**
     * Verify the incoming webhook signature against the per-branch
     * webhook secret. Implementations MUST be constant-time
     * (`hash_equals`) to avoid timing attacks.
     *
     * Returns false rather than throwing — the controller decides
     * whether a failure is a SignatureMismatchException or a soft
     * "log and 401".
     *
     * @param  string  $rawBody  the unparsed body, exactly as sent
     *                           by the platform (JSON re-encoding
     *                           breaks HMAC for some platforms).
     */
    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool;

    /**
     * Convert a verified, decoded payload into the platform-agnostic
     * NormalizedDeliveryOrder DTO. Throws OrderMappingException
     * on malformed input.
     *
     * @param  array<string,mixed>  $payload
     */
    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder;

    /**
     * Extract the platform's order id from a payload. Used as the
     * UNIQUE dedupe key and called BEFORE parseOrder() so that
     * re-deliveries can short-circuit cheaply.
     *
     * @param  array<string,mixed>  $payload
     */
    public function externalIdFrom(array $payload): string;

    /**
     * Classify the inbound event ('order.created', 'order.cancelled',
     * 'courier.assigned', ...). Used for routing in the controller
     * layer; not all events trigger ingest.
     *
     * @param  array<string,mixed>  $payload
     */
    public function eventTypeFrom(array $payload): string;

    /**
     * Push an internal status change back to the platform.
     */
    public function pushStatus(DeliveryPlatform $cfg, string $externalId, int $internalStatus): PushResult;

    /**
     * Translate FoodKing OrderStatus (int) into the platform's
     * own status vocabulary (e.g. 'POS_ACCEPTED', 'DENIED').
     * Returns null if the platform has no equivalent — the
     * caller should NOT push in that case.
     */
    public function mapInternalStatus(int $internalStatus): ?string;
}
