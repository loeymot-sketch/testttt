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
 * Phase-1 STUB. The full Delicity integration (signed URL +
 * shared-secret HMAC, status push via POST /api/v1/orders/{id}/state)
 * is implemented in Phase 2.
 */
final class DelicityAdapter implements PlatformAdapter
{
    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool
    {
        throw new LogicException('DelicityAdapter::verifySignature not implemented yet (Phase 2).');
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        throw new LogicException('DelicityAdapter::parseOrder not implemented yet (Phase 2).');
    }

    public function externalIdFrom(array $payload): string
    {
        throw new LogicException('DelicityAdapter::externalIdFrom not implemented yet (Phase 2).');
    }

    public function eventTypeFrom(array $payload): string
    {
        throw new LogicException('DelicityAdapter::eventTypeFrom not implemented yet (Phase 2).');
    }

    public function pushStatus(DeliveryPlatform $cfg, string $externalId, int $internalStatus): PushResult
    {
        throw new LogicException('DelicityAdapter::pushStatus not implemented yet (Phase 2).');
    }

    public function mapInternalStatus(int $internalStatus): ?string
    {
        throw new LogicException('DelicityAdapter::mapInternalStatus not implemented yet (Phase 2).');
    }
}
