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
 * Phase-1 STUB. The full Deliveroo Restaurant Hub integration
 * (X-Deliveroo-Hmac-Sha256 header, /pos-orders/v1/orders endpoint
 * for sync, /pos-orders/v1/orders/{id}/sync_status for status push)
 * is implemented in Phase 2.
 */
final class DeliverooAdapter implements PlatformAdapter
{
    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool
    {
        throw new LogicException('DeliverooAdapter::verifySignature not implemented yet (Phase 2).');
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        throw new LogicException('DeliverooAdapter::parseOrder not implemented yet (Phase 2).');
    }

    public function externalIdFrom(array $payload): string
    {
        throw new LogicException('DeliverooAdapter::externalIdFrom not implemented yet (Phase 2).');
    }

    public function eventTypeFrom(array $payload): string
    {
        throw new LogicException('DeliverooAdapter::eventTypeFrom not implemented yet (Phase 2).');
    }

    public function pushStatus(DeliveryPlatform $cfg, string $externalId, int $internalStatus): PushResult
    {
        throw new LogicException('DeliverooAdapter::pushStatus not implemented yet (Phase 2).');
    }

    public function mapInternalStatus(int $internalStatus): ?string
    {
        throw new LogicException('DeliverooAdapter::mapInternalStatus not implemented yet (Phase 2).');
    }
}
