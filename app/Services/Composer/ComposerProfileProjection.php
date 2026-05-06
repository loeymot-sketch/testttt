<?php

namespace App\Services\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Stock\ChoiceAvailabilityResolver;

final class ComposerProfileProjection
{
    public function __construct(
        private readonly ?ChoiceAvailabilityResolver $choiceAvailabilityResolver = null,
    ) {
    }

    public function project(?ItemWizardProfile $profile, Item $item, string $surface, ?int $branchId = null): ?array
    {
        if (! $profile) {
            return null;
        }

        $choiceAvailability = $branchId !== null && $branchId > 0
            ? $this->choiceAvailabilityResolver()->snapshotForItem($item, $branchId, $surface)
            : null;

        $steps = $profile->steps
            ->filter(fn (ItemWizardStep $step): bool => $this->stepVisibleOn($step, $surface))
            ->values()
            ->map(fn (ItemWizardStep $step): array => [
                'id' => (int) $step->id,
                'step_key' => (string) $step->step_key,
                'label' => (string) $step->label,
                'source_type' => (string) $step->source_type,
                'source_ref' => (string) $step->source_ref,
                'min_select' => (int) $step->min_select,
                'max_select' => (int) $step->max_select,
                'allow_repeat' => (bool) $step->allow_repeat,
                'visible_on' => $step->visible_on,
                'stockable_choices' => (bool) $step->stockable_choices,
                'position' => (int) $step->position,
                'is_active' => (bool) $step->is_active,
                'addon_role' => $step->addon_role,
                'choices' => $this->choices($step, $item, $surface, $choiceAvailability),
            ])
            ->all();

        return [
            'id' => (int) $profile->id,
            'item_id' => (int) $profile->item_id,
            'template' => (string) $profile->template,
            'version' => (int) $profile->version,
            'is_published' => (bool) $profile->is_published,
            'published_at' => optional($profile->published_at)->toIso8601String(),
            'branch_id_scope' => $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            'steps' => $steps,
        ];
    }

    private function stepVisibleOn(ItemWizardStep $step, string $surface): bool
    {
        $visibleOn = $step->visible_on;
        return empty($visibleOn) || in_array($surface, (array) $visibleOn, true);
    }

    /**
     * @param  array{
     *   variations: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   extras: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   addons: array<int, array{is_available: bool, unavailable_reason: ?string}>
     * }|null  $choiceAvailability
     */
    private function choices(ItemWizardStep $step, Item $item, string $surface, ?array $choiceAvailability): array
    {
        $sourceType = (string) $step->source_type;
        $sourceRef = mb_strtolower(trim((string) $step->source_ref));
        $usesStockableChoices = (bool) $step->stockable_choices;

        if ($sourceType === 'item_attribute') {
            return $item->variations
                ->filter(fn ($variation): bool => (int) $variation->status === Status::ACTIVE
                    && $variation->isVisibleOn($surface)
                    && $this->matchesAttributeRef($variation->itemAttribute, $sourceRef))
                ->map(function ($variation) use ($choiceAvailability): array {
                    $availability = $choiceAvailability !== null
                        ? ($choiceAvailability['variations'][(int) $variation->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $variation->id,
                        'name' => (string) $variation->name,
                        'source_type' => 'variation',
                        'item_attribute_id' => $variation->item_attribute_id !== null ? (int) $variation->item_attribute_id : null,
                        'status' => (int) $variation->status,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        if ($sourceType === 'extra_group') {
            return $item->extras
                ->filter(fn ($extra): bool => (int) $extra->status === Status::ACTIVE
                    && $extra->isVisibleOn($surface)
                    && ($sourceRef === '' || mb_strtolower((string) $extra->group_label) === $sourceRef))
                ->map(function ($extra) use ($choiceAvailability): array {
                    $availability = $choiceAvailability !== null
                        ? ($choiceAvailability['extras'][(int) $extra->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $extra->id,
                        'name' => (string) $extra->name,
                        'source_type' => 'extra',
                        'group_label' => $extra->group_label,
                        'status' => (int) $extra->status,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        if ($sourceType === 'addon') {
            $role = $step->addon_role;
            if ($role === null && in_array($sourceRef, ItemAddon::ROLES, true)) {
                $role = $sourceRef;
            }

            return $item->addons
                ->filter(function ($addon) use ($role, $surface): bool {
                    if ($role !== null && $addon->role !== $role) {
                        return false;
                    }

                    $addonItem = $addon->addonItem;
                    if (! $addonItem) {
                        return false;
                    }

                    return (int) $addonItem->status === Status::ACTIVE
                        && (bool) ($addonItem->is_available ?? true)
                        && $addonItem->isVisibleOn($surface);
                })
                ->map(function ($addon) use ($choiceAvailability, $usesStockableChoices): array {
                    $availability = $usesStockableChoices
                        ? ($choiceAvailability['addons'][(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $addon->id,
                        'name' => (string) ($addon->addonItem?->name ?? ''),
                        'source_type' => 'addon',
                        'addon_item_id' => (int) $addon->addon_item_id,
                        'role' => $addon->role,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        return [];
    }

    private function matchesAttributeRef($attribute, string $sourceRef): bool
    {
        if (! $attribute) {
            return false;
        }

        if ($sourceRef === '') {
            return true;
        }

        return (string) $attribute->id === $sourceRef
            || mb_strtolower((string) $attribute->name) === $sourceRef;
    }

    private function choiceAvailabilityResolver(): ChoiceAvailabilityResolver
    {
        return $this->choiceAvailabilityResolver ?? app(ChoiceAvailabilityResolver::class);
    }
}
