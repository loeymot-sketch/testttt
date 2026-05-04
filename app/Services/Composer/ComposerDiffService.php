<?php

namespace App\Services\Composer;

use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Support\Collection;

final class ComposerDiffService
{
    private const COMPARED_FIELDS = [
        'label',
        'source_type',
        'source_ref',
        'source_item_attribute_id',
        'min_select',
        'max_select',
        'allow_repeat',
        'visible_on',
        'stockable_choices',
        'position',
        'is_active',
        'addon_role',
    ];

    public function diff(ItemWizardProfile $profile): array
    {
        $profile->loadMissing('steps');

        $draftSteps = $profile->steps->values();
        $draftByKey = $this->stepRowsByKey($draftSteps);
        $hasHistoricalSnapshot = $this->hasHistoricalSnapshot($profile);

        $publishedVersion = (bool) $profile->is_published || $hasHistoricalSnapshot
            ? (int) $profile->version
            : null;

        if (! (bool) $profile->is_published && ! $hasHistoricalSnapshot) {
            return $this->payload(
                $profile,
                $publishedVersion,
                $draftByKey === [],
                array_values($draftByKey),
            );
        }

        if (! $hasHistoricalSnapshot) {
            return $this->payload($profile, $publishedVersion, true);
        }

        $publishedByKey = $this->publishedRowsByKey($profile);

        $added = [];
        foreach ($draftByKey as $stepKey => $draftStep) {
            if (! array_key_exists($stepKey, $publishedByKey)) {
                $added[] = $draftStep;
            }
        }

        $removed = [];
        foreach ($publishedByKey as $stepKey => $publishedStep) {
            if (! array_key_exists($stepKey, $draftByKey)) {
                $removed[] = $publishedStep;
            }
        }

        $modified = [];
        foreach ($draftByKey as $stepKey => $draftStep) {
            if (! array_key_exists($stepKey, $publishedByKey)) {
                continue;
            }

            $changedFields = $this->changedFields($publishedByKey[$stepKey], $draftStep);
            if ($changedFields !== []) {
                $modified[] = [
                    'step_key' => $stepKey,
                    'before' => $publishedByKey[$stepKey],
                    'after' => $draftStep,
                    'changed_fields' => $changedFields,
                ];
            }
        }

        return $this->payload(
            $profile,
            $publishedVersion,
            $added === [] && $removed === [] && $modified === [],
            $added,
            $removed,
            $modified,
        );
    }

    private function payload(
        ItemWizardProfile $profile,
        ?int $publishedVersion,
        bool $isClean,
        array $added = [],
        array $removed = [],
        array $modified = [],
    ): array {
        return [
            'profile_id' => (int) $profile->id,
            'published_version' => $publishedVersion,
            'draft_version' => (int) $profile->version,
            'is_clean' => $isClean,
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];
    }

    /**
     * @param  Collection<int, ItemWizardStep>  $steps
     */
    private function stepRowsByKey(Collection $steps): array
    {
        return $steps
            ->map(fn (ItemWizardStep $step): array => $this->stepResourceRow($step))
            ->keyBy(fn (array $step): string => (string) $step['step_key'])
            ->all();
    }

    private function publishedRowsByKey(ItemWizardProfile $profile): array
    {
        $latestVersion = $profile->versions()->orderByDesc('version')->first();

        return $this->arrayRowsByKey($latestVersion?->snapshot ?? []);
    }

    private function hasHistoricalSnapshot(ItemWizardProfile $profile): bool
    {
        return $profile->versions()->exists();
    }

    private function arrayRowsByKey(array $steps): array
    {
        $rows = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $row = $this->arrayResourceRow($step);
            $stepKey = (string) $row['step_key'];
            if ($stepKey !== '') {
                $rows[$stepKey] = $row;
            }
        }

        return $rows;
    }

    private function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach (self::COMPARED_FIELDS as $field) {
            if ($this->comparable($field, $before[$field] ?? null) !== $this->comparable($field, $after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function stepResourceRow(ItemWizardStep $step): array
    {
        return [
            'id' => $step->id,
            'profile_id' => $step->profile_id,
            'step_key' => $step->step_key,
            'label' => $step->label,
            'source_type' => $step->source_type,
            'source_ref' => $step->source_ref,
            'source_item_attribute_id' => $step->source_item_attribute_id,
            'min_select' => $step->min_select,
            'max_select' => $step->max_select,
            'allow_repeat' => $step->allow_repeat,
            'visible_on' => $step->visible_on,
            'stockable_choices' => $step->stockable_choices,
            'position' => $step->position,
            'is_active' => $step->is_active,
            'addon_role' => $step->addon_role,
        ];
    }

    private function arrayResourceRow(array $step): array
    {
        return [
            'id' => $step['id'] ?? null,
            'profile_id' => $step['profile_id'] ?? null,
            'step_key' => $step['step_key'] ?? '',
            'label' => $step['label'] ?? '',
            'source_type' => $step['source_type'] ?? '',
            'source_ref' => $step['source_ref'] ?? '',
            'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
            'min_select' => $step['min_select'] ?? 0,
            'max_select' => $step['max_select'] ?? 1,
            'allow_repeat' => $step['allow_repeat'] ?? false,
            'visible_on' => $step['visible_on'] ?? null,
            'stockable_choices' => $step['stockable_choices'] ?? false,
            'position' => $step['position'] ?? 0,
            'is_active' => $step['is_active'] ?? true,
            'addon_role' => $step['addon_role'] ?? null,
        ];
    }

    private function comparable(string $field, mixed $value): mixed
    {
        return match ($field) {
            'source_item_attribute_id' => $value === null ? null : (int) $value,
            'min_select', 'max_select', 'position' => (int) $value,
            'allow_repeat', 'stockable_choices', 'is_active' => (bool) $value,
            'visible_on' => $this->comparableArray($value),
            'addon_role' => $value === null ? null : (string) $value,
            default => (string) ($value ?? ''),
        };
    }

    private function comparableArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $array = is_array($value) ? $value : [$value];
        $array = array_map(static fn ($item): string => (string) $item, $array);
        sort($array);

        return array_values($array);
    }
}
