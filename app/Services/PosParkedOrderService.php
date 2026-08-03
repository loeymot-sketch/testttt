<?php

namespace App\Services;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemVariation;
use App\Models\PosParkedOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PosParkedOrderService
{
    public function park(
        int $userId,
        int $branchId,
        array $payload,
        ?string $label = null,
        ?string $idempotencyToken = null
    ): PosParkedOrder {
        $token = $this->normalizeToken($idempotencyToken);

        if ($token !== null) {
            $existing = PosParkedOrder::query()
                ->where('user_id', $userId)
                ->where('idempotency_token', $token)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $items = $payload['lists'] ?? $payload['items'] ?? [];

        $attributes = [
            'branch_id' => $branchId,
            'user_id' => $userId,
            'label' => $this->normalizeLabel($label),
            'payload_json' => $payload,
            'preview_total' => $this->extractPreviewTotal($payload),
            'items_count' => is_array($items) ? count($items) : 0,
            'idempotency_token' => $token,
        ];

        try {
            return PosParkedOrder::query()->create($attributes);
        } catch (QueryException $exception) {
            if ($token === null || ! $this->isDuplicateIdempotencyException($exception)) {
                throw $exception;
            }

            return PosParkedOrder::query()
                ->where('user_id', $userId)
                ->where('idempotency_token', $token)
                ->firstOrFail();
        }
    }

    public function listForOperator(int $userId, int $branchId): Collection
    {
        return PosParkedOrder::query()
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function recall(int $userId, int $branchId, int $parkedId): ?PosParkedOrder
    {
        return DB::transaction(function () use ($userId, $branchId, $parkedId): ?PosParkedOrder {
            $parked = PosParkedOrder::query()
                ->where('id', $parkedId)
                ->where('user_id', $userId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $parked) {
                return null;
            }

            $unavailableVariationWarnings = [];
            $payload = is_array($parked->payload_json) ? $parked->payload_json : [];
            $prunedPayload = $this->pruneUnavailableParkedVariations($payload, $unavailableVariationWarnings);

            $snapshot = $parked->replicate();
            $snapshot->setAttribute('id', $parked->id);
            $snapshot->setAttribute('created_at', $parked->created_at);
            $snapshot->setAttribute('updated_at', $parked->updated_at);
            $snapshot->payload_json = $prunedPayload;
            $snapshot->setAttribute('warnings', [
                'unavailable_variations' => $unavailableVariationWarnings,
            ]);

            $parked->delete();

            return $snapshot;
        });
    }

    /**
     * [G-3 / V14 T03] Drop variations that are no longer sellable (inactive, soft-deleted,
     * or not matching the parked line's item) before returning a recalled payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $warningsOut
     * @return array<string, mixed>
     */
    private function pruneUnavailableParkedVariations(array $payload, array &$warningsOut): array
    {
        $listsKey = null;
        if (isset($payload['lists']) && is_array($payload['lists'])) {
            $listsKey = 'lists';
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            $listsKey = 'items';
        }

        if ($listsKey === null) {
            return $payload;
        }

        $lines = $payload[$listsKey];
        foreach ($lines as $idx => $line) {
            if (! is_array($line)) {
                continue;
            }

            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
            if ($itemId <= 0) {
                continue;
            }

            $rawVars = $line['item_variations'] ?? [];
            if (! is_array($rawVars) || $rawVars === []) {
                continue;
            }

            $item = Item::query()->find($itemId);
            if (! $item) {
                continue;
            }

            $kept = [];
            foreach ($rawVars as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $variationId = isset($entry['id']) ? (int) $entry['id'] : 0;
                if ($variationId <= 0) {
                    continue;
                }

                $variation = ItemVariation::withTrashed()
                    ->where('item_id', $item->id)
                    ->where('id', $variationId)
                    ->first();

                $available = $variation
                    && ! $variation->trashed()
                    && (int) $variation->status === Status::ACTIVE;

                if ($available) {
                    $kept[] = $entry;

                    continue;
                }

                $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '';
                $warningsOut[] = [
                    'item_id' => $itemId,
                    'variation_id' => $variationId,
                    'name' => $name,
                ];

                Log::info('parked.recall.variation_dropped', [
                    'item_id' => $itemId,
                    'variation_id' => $variationId,
                    'name' => $name,
                    'reason' => $variation === null ? 'not_found' : ($variation->trashed() ? 'trashed' : 'inactive'),
                ]);
            }

            $lines[$idx]['item_variations'] = $kept;
        }

        $payload[$listsKey] = $lines;

        return $payload;
    }

    public function discard(int $userId, int $branchId, int $parkedId): bool
    {
        return PosParkedOrder::query()
            ->where('id', $parkedId)
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->delete() > 0;
    }

    public function purgeOlderThanHours(int $hours): int
    {
        return PosParkedOrder::query()
            ->where('created_at', '<', now()->subHours(max(1, $hours)))
            ->delete();
    }

    private function extractPreviewTotal(array $payload): float
    {
        $candidate = $payload['total'] ?? $payload['subtotal'] ?? 0;

        return is_numeric($candidate) ? (float) $candidate : 0.0;
    }

    private function normalizeLabel(?string $label): ?string
    {
        $value = trim((string) $label);

        return $value === '' ? null : $value;
    }

    private function normalizeToken(?string $idempotencyToken): ?string
    {
        $value = trim((string) $idempotencyToken);

        return $value === '' ? null : $value;
    }

    private function isDuplicateIdempotencyException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'pos_parked_user_idem_uniq')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate');
    }
}
