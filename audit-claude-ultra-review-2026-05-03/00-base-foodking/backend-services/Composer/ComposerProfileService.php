<?php

namespace App\Services\Composer;

use App\Events\ComposerProfilePublished;
use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComposerProfileService
{
    private const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon'];

    public function __construct(
        private readonly ComposerStepService $stepService,
        private readonly ComposerProfileProjection $projection,
    )
    {
    }

    public function showForItem(Item $item, ?int $branchIdScope = null): ?ItemWizardProfile
    {
        return ItemWizardProfile::query()
            ->with('steps')
            ->where('item_id', $item->id)
            ->when(
                $branchIdScope !== null,
                fn ($query) => $query->where('branch_id_scope', $branchIdScope),
                fn ($query) => $query->whereNull('branch_id_scope')
            )
            ->latest('id')
            ->first();
    }

    public function createForItem(Item $item, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($item, $payload): ItemWizardProfile {
            $profile = ItemWizardProfile::query()->create([
                'item_id' => $item->id,
                'template' => $payload['template'],
                'branch_id_scope' => $payload['branch_id_scope'] ?? null,
                'version' => 1,
                'is_published' => false,
            ]);

            foreach (($payload['steps'] ?? []) as $step) {
                $this->stepService->create($profile, $step, false);
            }

            return $profile->fresh('steps');
        });
    }

    public function update(ItemWizardProfile $profile, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($profile, $payload): ItemWizardProfile {
            $profile->update([
                'template' => $payload['template'],
                'branch_id_scope' => $payload['branch_id_scope'] ?? null,
                'version' => ((int) $profile->version) + 1,
            ]);

            if (array_key_exists('steps', $payload)) {
                $profile->steps()->delete();
                foreach (($payload['steps'] ?? []) as $step) {
                    $this->stepService->create($profile, $step, false);
                }
            }

            $fresh = $profile->fresh('steps');
            if ($fresh->is_published) {
                ComposerProfileChanged::dispatch(...$this->composerChangedPayload($fresh, 'updated'));
            }

            return $fresh;
        });
    }

    public function publish(ItemWizardProfile $profile): ItemWizardProfile
    {
        return DB::transaction(function () use ($profile): ItemWizardProfile {
            $this->assertPublishable($profile);
            $profile->publish();
            $fresh = $profile->fresh('steps');
            ComposerProfilePublished::dispatch((int) $fresh->id);
            ComposerProfileChanged::dispatch(...$this->composerChangedPayload($fresh, 'published'));

            return $fresh;
        });
    }

    private function assertPublishable(ItemWizardProfile $profile): void
    {
        $fresh = $profile->fresh([
            'steps',
            'item.variations.itemAttribute',
            'item.extras',
            'item.addons.addonItem',
        ]);

        if (! $fresh || ! $fresh->item) {
            throw ValidationException::withMessages([
                'steps' => 'Composer profile cannot be published without an item.',
            ]);
        }

        $activeSteps = $fresh->steps
            ->filter(fn (ItemWizardStep $step): bool => (bool) $step->is_active)
            ->values();

        if ($activeSteps->isEmpty()) {
            throw ValidationException::withMessages([
                'steps' => 'Composer profile cannot be published without active steps.',
            ]);
        }

        foreach ($activeSteps as $step) {
            if (! in_array((string) $step->source_type, self::SOURCE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'steps' => 'Composer profile contains an unsupported source type.',
                ]);
            }

            if ((int) $step->max_select < (int) $step->min_select) {
                throw ValidationException::withMessages([
                    'steps' => 'Composer profile contains an invalid selection range.',
                ]);
            }

            if ((int) $step->min_select > 0 && ! $this->requiredStepHasChoices($fresh, $step)) {
                throw ValidationException::withMessages([
                    'steps' => 'Composer profile contains a required step without available choices.',
                ]);
            }
        }
    }

    private function requiredStepHasChoices(ItemWizardProfile $profile, ItemWizardStep $step): bool
    {
        $surfaces = $step->visible_on ?: ['pos', 'kiosk', 'web'];

        foreach ((array) $surfaces as $surface) {
            $projected = $this->projection->project($profile, $profile->item, (string) $surface, $profile->branch_id_scope);
            $projectedStep = collect($projected['steps'] ?? [])->firstWhere('id', (int) $step->id);

            if ($projectedStep && count($projectedStep['choices'] ?? []) > 0) {
                return true;
            }
        }

        return false;
    }

    public function unpublish(ItemWizardProfile $profile): ItemWizardProfile
    {
        return DB::transaction(function () use ($profile): ItemWizardProfile {
            $profile->unpublish();
            $fresh = $profile->fresh('steps');
            ComposerProfileChanged::dispatch(...$this->composerChangedPayload($fresh, 'unpublished'));

            return $fresh;
        });
    }

    private function composerChangedPayload(ItemWizardProfile $profile, string $changeType): array
    {
        return [
            (int) $profile->id,
            $changeType,
            $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            [
                'item_id' => (int) $profile->item_id,
                'version' => (int) $profile->version,
                'is_published' => (bool) $profile->is_published,
            ],
        ];
    }
}
