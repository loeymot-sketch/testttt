<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

class CatalogChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public string $entityType,
        public int $entityId,
        public string $changeType,
        public ?int $branchId = null,
        public array $payloadDiff = [],
    ) {
    }

    public static function fromMenuMutation(object $event): ?self
    {
        if ($event instanceof self) {
            return $event;
        }

        $branchId = property_exists($event, 'branchId') ? $event->branchId : null;

        if ($event instanceof ItemAvailabilityChanged) {
            return new self(
                entityType: 'item',
                entityId: (int) $event->itemId,
                changeType: $event->type === 'branch_availability' ? 'availability_changed' : 'updated',
                branchId: $branchId !== null ? (int) $branchId : null,
                payloadDiff: [
                    'type' => $event->type,
                    'is_available' => $event->isAvailable,
                    'reason' => $event->reason,
                ],
            );
        }

        if ($event instanceof ItemCreated) {
            return new self('item', (int) $event->itemId, 'created', $branchId !== null ? (int) $branchId : null);
        }

        // [GOAL-I2-HEAL-02 2026-05-24] Phase I.3 RISK-01 AMBER bridge:
        // ItemUpdated (admin rename/reprice/desc) emits CATALOG_CHANGED outbox row
        // so kiosk private-branch.{id} subscribers refresh without waiting 60s TTL.
        if ($event instanceof ItemUpdated) {
            return new self('item', (int) $event->itemId, 'updated', $branchId !== null ? (int) $branchId : null);
        }

        if ($event instanceof ItemDeleted) {
            return new self('item', (int) $event->itemId, 'deleted', $branchId !== null ? (int) $branchId : null);
        }

        if ($event instanceof CategoryCreated) {
            return new self('category', (int) $event->categoryId, 'created', $branchId !== null ? (int) $branchId : null);
        }

        if ($event instanceof CategoryUpdated) {
            return new self('category', (int) $event->categoryId, 'updated', $branchId !== null ? (int) $branchId : null);
        }

        if ($event instanceof CategoryDeleted) {
            return new self('category', (int) $event->categoryId, 'deleted', $branchId !== null ? (int) $branchId : null);
        }

        if ($event instanceof ComposerProfileChanged) {
            return new self(
                entityType: 'composer_profile',
                entityId: (int) $event->profileId,
                changeType: $event->changeType,
                branchId: $event->branchId,
                payloadDiff: $event->payloadDiff,
            );
        }

        if ($event instanceof StockLevelChanged) {
            $stockLevelIds = array_values(array_map('intval', $event->stockLevelIds));
            return new self(
                entityType: 'stock_level',
                entityId: (int) ($stockLevelIds[0] ?? 0),
                changeType: 'availability_changed',
                branchId: (int) $event->branchId,
                payloadDiff: [
                    'stock_level_ids' => $stockLevelIds,
                ],
            );
        }

        // [WAVE5-DATA-004] Bridge ItemExtra/ItemVariation availability events so
        // they also emit a generic CATALOG_CHANGED row in the outbox. Sentinel:
        // tests/Feature/Sentinels/ItemExtraVariationBridgeCatalogChangedSentinelTest.
        if ($event instanceof ItemExtraAvailabilityChanged) {
            return new self(
                entityType: 'item_extra',
                entityId: (int) $event->extraId,
                changeType: 'availability_changed',
                branchId: $branchId !== null ? (int) $branchId : null,
                payloadDiff: [
                    'is_available' => $event->isAvailable,
                    'reason' => $event->reason,
                ],
            );
        }

        if ($event instanceof ItemVariationAvailabilityChanged) {
            return new self(
                entityType: 'item_variation',
                entityId: (int) $event->variationId,
                changeType: 'availability_changed',
                branchId: $branchId !== null ? (int) $branchId : null,
                payloadDiff: [
                    'is_available' => $event->isAvailable,
                    'reason' => $event->reason,
                ],
            );
        }

        return null;
    }
}
