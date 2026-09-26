<?php

namespace App\Http\Requests\Concerns;

use App\Models\KioskMachine;
use App\Rules\MultiVariationConstraint;
use Illuminate\Validation\Validator;

trait ValidatesOrderItemVariations
{
    /**
     * Après les règles de base : valide item_variations pour chaque ligne,
     * avec chargement groupé (pas de N+1 sur item_variations / item_attributes).
     */
    protected function validateOrderItemVariationsAfter(Validator $validator): void
    {
        $raw = $this->input('items');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $items = is_array($decoded) ? $decoded : null;
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            return;
        }

        if (! is_array($items) || $items === []) {
            return;
        }

        MultiVariationConstraint::validateCollectionKeyedByItemIndex(
            $items,
            function (int $index, string $message) use ($validator): void {
                $validator->errors()->add("items.{$index}.item_variations", $message);
            },
            $this->branchIdForVariationConstraints(),
        );
    }

    /**
     * The physical-kiosk pricing-preview endpoint deliberately rejects a
     * client-supplied branch_id. Resolve its branch from the authenticated
     * machine so a branch-specific published composer profile remains the
     * validation contract. Other surfaces retain their validated branch_id.
     */
    private function branchIdForVariationConstraints(): ?int
    {
        $branchId = (int) $this->input('branch_id', 0);
        if ($branchId > 0) {
            return $branchId;
        }

        $user = $this->user();
        if (! $user || ! $user->tokenCan('kiosk:order')) {
            return null;
        }

        $machineBranchId = KioskMachine::query()
            ->where('user_id', (int) $user->id)
            ->value('branch_id');

        return $machineBranchId !== null && (int) $machineBranchId > 0
            ? (int) $machineBranchId
            : null;
    }
}
