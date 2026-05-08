<?php

namespace App\Services\Delivery\Adapters;

use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PlatformAdapter;
use App\Domain\Delivery\PushResult;
use App\Models\DeliveryPlatform;
use Illuminate\Http\Request;
use LogicException;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Phase-1 STUB. The full Uber Eats Direct integration (HMAC SHA-256
 * over (timestamp + body), POST /v1/orders/{id}/transition for
 * status push, etc.) is implemented in Phase 2.
 *
 * Until then every entry point throws LogicException so that any
 * accidental wiring (e.g. registering this adapter as the default
 * routing target) fails loud at the request boundary rather than
 * silently dropping orders.
 */
final class UberEatsAdapter implements PlatformAdapter
{
    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool
    {
        throw new LogicException('UberEatsAdapter::verifySignature not implemented yet (Phase 2).');
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        throw new LogicException('UberEatsAdapter::parseOrder not implemented yet (Phase 2).');
    }

    public function externalIdFrom(array $payload): string
    {
        throw new LogicException('UberEatsAdapter::externalIdFrom not implemented yet (Phase 2).');
    }

    public function eventTypeFrom(array $payload): string
    {
        throw new LogicException('UberEatsAdapter::eventTypeFrom not implemented yet (Phase 2).');
    }

    public function pushStatus(DeliveryPlatform $cfg, string $externalId, int $internalStatus): PushResult
    {
        throw new LogicException('UberEatsAdapter::pushStatus not implemented yet (Phase 2).');
    }

    public function mapInternalStatus(int $internalStatus): ?string
    {
        throw new LogicException('UberEatsAdapter::mapInternalStatus not implemented yet (Phase 2).');
    }
}
