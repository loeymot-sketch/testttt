<?php

namespace App\Services\Composer;

use App\Events\ComposerProfilePublished;
use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\ItemWizardStepVersion;
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
        $base = fn (): \Illuminate\Database\Eloquent\Builder => ItemWizardProfile::query()
            ->with('steps')
            ->where('item_id', $item->id);

        if ($branchIdScope !== null) {
            $scoped = $base()->where('branch_id_scope', $branchIdScope)->latest('id')->first();
            if ($scoped !== null) {
                return $scoped;
            }

            return $base()->whereNull('branch_id_scope')->latest('id')->first();
        }

        return $base()->whereNull('branch_id_scope')->latest('id')->first();
    }

    public function createForItem(Item $item, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($item, $payload): ItemWizardProfile {
            $profile = ItemWizardProfile::query()->create([
                'item_id' => $item->id,
                'item_category_id' => null,
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

    public function showForCategory(ItemCategory $category, ?int $branchIdScope = null): ?ItemWizardProfile
    {
        $base = fn (): \Illuminate\Database\Eloquent\Builder => ItemWizardProfile::query()
            ->with('steps')
            ->where('item_category_id', $category->id);

        if ($branchIdScope !== null) {
            $scoped = $base()->where('branch_id_scope', $branchIdScope)->latest('id')->first();
            if ($scoped !== null) {
                return $scoped;
            }

            return $base()->whereNull('branch_id_scope')->latest('id')->first();
        }

        return $base()->whereNull('branch_id_scope')->latest('id')->first();
    }

    public function createForCategory(ItemCategory $category, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($category, $payload): ItemWizardProfile {
            $profile = ItemWizardProfile::query()->create([
                'item_id' => null,
                'item_category_id' => $category->id,
                'template' => $payload['template'],
                'branch_id_scope' => $payload['branch_id_scope'] ?? null,
                'version' => 1,
                'is_published' => false,
            ]);

            $category->update(['wizard_profile_id' => $profile->id]);

            foreach (($payload['steps'] ?? []) as $step) {
                $this->stepService->create($profile, $step, false);
            }

            return $profile->fresh('steps');
        });
    }

    public function resolveForItem(Item $item, ?int $branchIdScope = null): ?ItemWizardProfile
    {
        if ($item->item_category_id) {
            $category = $item->relationLoaded('category')
                ? $item->category
                : ItemCategory::query()->find($item->item_category_id);

            if ($category) {
                $profile = $this->showForCategory($category, $branchIdScope);
                if ($profile) {
                    return $profile;
                }
            }
        }

        return $this->showForItem($item, $branchIdScope);
    }

    public function update(ItemWizardProfile $profile, array $payload): ItemWizardProfile
    {
        $this->assertVersionMatches($profile, $payload);

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

    public function publish(ItemWizardProfile $profile, array $payload = []): ItemWizardProfile
    {
        $this->assertVersionMatches($profile, $payload);

        return DB::transaction(function () use ($profile): ItemWizardProfile {
            $this->assertPublishable($profile);
            $profile->publish();
            $fresh = $profile->fresh('steps');
            ItemWizardStepVersion::create([
                'profile_id' => $fresh->id,
                'version' => $fresh->version,
                'snapshot' => $fresh->steps
                    ->sortBy('position')
                    ->map(fn (ItemWizardStep $step): array => $step->toArray())
                    ->values()
                    ->all(),
                'published_at' => now(),
                'published_by_id' => auth()->id() ?: null,
            ]);
            ComposerProfilePublished::dispatch((int) $fresh->id);
            ComposerProfileChanged::dispatch(...$this->composerChangedPayload($fresh, 'published'));

            return $fresh;
        });
    }

    private function assertPublishable(ItemWizardProfile $profile): void
    {
        $fresh = $profile->fresh(['steps', 'item']);

        if (! $fresh) {
            throw ValidationException::withMessages([
                'steps' => 'Composer profile not found.',
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
        }

        if ($fresh->item_id && $fresh->item) {
            $fullItem = $fresh
                ->fresh(['steps', 'item.variations.itemAttribute', 'item.extras', 'item.addons.addonItem'])
                ->item;

            foreach ($activeSteps as $step) {
                if ((int) $step->min_select > 0 && ! $this->requiredStepHasChoicesForItem($fresh, $fullItem, $step)) {
                    throw ValidationException::withMessages([
                        'steps' => 'Composer profile contains a required step without available choices.',
                    ]);
                }
            }
        }
    }

    private function requiredStepHasChoicesForItem(ItemWizardProfile $profile, Item $item, ItemWizardStep $step): bool
    {
        $surfaces = $step->visible_on ?: ['pos', 'kiosk', 'web'];

        foreach ((array) $surfaces as $surface) {
            $projected = $this->projection->project($profile, $item, (string) $surface, $profile->branch_id_scope);
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

    /**
     * [GOAL CMS GESTION T-W5b 2026-06-10] Delete a whole wizard profile.
     *
     * Published profiles are REFUSED (409): the owner must unpublish first so
     * the kiosk never loses a live wizard by accident. Hard delete cascades
     * steps + step versions (FK cascadeOnDelete) and detaches
     * `item_categories.wizard_profile_id` (FK nullOnDelete) — which also
     * unblocks category deletion (C1.2 guard).
     *
     * @throws \Exception code 409 while the profile is published
     */
    public function destroy(ItemWizardProfile $profile): void
    {
        if ((bool) $profile->is_published) {
            throw new \Exception(
                'Ce wizard est publié. Dépubliez-le avant de le supprimer.',
                409
            );
        }

        DB::transaction(function () use ($profile): void {
            // [heal P2-2] Re-check under row lock: a concurrent publish
            // committed between the optimistic check above and this
            // transaction must not let a LIVE wizard be hard-deleted.
            $locked = ItemWizardProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return; // already gone
            }
            if ((bool) $locked->is_published) {
                throw new \Exception(
                    'Ce wizard est publié. Dépubliez-le avant de le supprimer.',
                    409
                );
            }

            $payload = $this->composerChangedPayload($locked, 'deleted');
            // Explicit detach (don't rely on FK nullOnDelete — absent on
            // sqlite where ALTER-added constraints are ignored).
            ItemCategory::query()
                ->where('wizard_profile_id', $locked->id)
                ->update(['wizard_profile_id' => null]);
            $locked->delete();
            ComposerProfileChanged::dispatch(...$payload);
        });
    }

    private function composerChangedPayload(ItemWizardProfile $profile, string $changeType): array
    {
        return [
            (int) $profile->id,
            $changeType,
            $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            [
                'item_id' => $profile->item_id ? (int) $profile->item_id : null,
                'item_category_id' => $profile->item_category_id ? (int) $profile->item_category_id : null,
                'version' => (int) $profile->version,
                'is_published' => (bool) $profile->is_published,
            ],
        ];
    }

    private function assertVersionMatches(ItemWizardProfile $profile, array $payload): void
    {
        if (array_key_exists('version', $payload) && (int) $payload['version'] !== (int) $profile->version) {
            abort(response()->json([
                'message' => 'Profile version conflict',
                'expected' => (int) $profile->version,
                'got' => (int) $payload['version'],
            ], 409));
        }
    }
}
