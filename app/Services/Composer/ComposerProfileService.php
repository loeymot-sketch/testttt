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
        return $this->pickAdminProfile(
            fn (): \Illuminate\Database\Eloquent\Builder => ItemWizardProfile::query()
                ->with('steps')
                ->where('item_id', $item->id),
            $branchIdScope
        );
    }

    public function createForItem(Item $item, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($item, $payload): ItemWizardProfile {
            $profile = ItemWizardProfile::query()->create([
                'item_id' => $item->id,
                'item_category_id' => null,
                'template' => $payload['template'],
                'branch_id_scope' => $payload['branch_id_scope'] ?? null,
                'version' => max(1, (int) ($payload['version'] ?? 1)),
                'is_published' => false,
            ]);

            foreach (($payload['steps'] ?? []) as $step) {
                $this->stepService->create($profile, $step, false);
            }

            return $profile->fresh('steps');
        });
    }

    /**
     * Appliquer un template sans créer un 2e profil version=1 que le POS ignore.
     * Brouillon existant → on le réécrit. Profil déjà publié → nouveau brouillon
     * avec version supérieure, pour qu'un Publier suivant gagne en caisse.
     */
    public function applyTemplateToItem(Item $item, array $payload): ItemWizardProfile
    {
        $scope = $payload['branch_id_scope'] ?? null;
        $current = $this->showForItem($item, $scope);

        if ($current && ! $current->is_published) {
            return $this->update($current, [
                'template' => $payload['template'],
                'branch_id_scope' => $scope,
                'version' => $current->version,
                'steps' => $payload['steps'] ?? [],
            ]);
        }

        $maxVersion = (int) ItemWizardProfile::query()
            ->where('item_id', $item->id)
            ->max('version');
        $payload['version'] = $maxVersion + 1;

        return $this->createForItem($item, $payload);
    }

    public function applyTemplateToCategory(ItemCategory $category, array $payload): ItemWizardProfile
    {
        $scope = $payload['branch_id_scope'] ?? null;
        $current = $this->showForCategory($category, $scope);

        if ($current && ! $current->is_published) {
            return $this->update($current, [
                'template' => $payload['template'],
                'branch_id_scope' => $scope,
                'version' => $current->version,
                'steps' => $payload['steps'] ?? [],
            ]);
        }

        $maxVersion = (int) ItemWizardProfile::query()
            ->where('item_category_id', $category->id)
            ->max('version');
        $payload['version'] = $maxVersion + 1;

        return $this->createForCategory($category, $payload);
    }

    public function showForCategory(ItemCategory $category, ?int $branchIdScope = null): ?ItemWizardProfile
    {
        return $this->pickAdminProfile(
            fn (): \Illuminate\Database\Eloquent\Builder => ItemWizardProfile::query()
                ->with('steps')
                ->where('item_category_id', $category->id),
            $branchIdScope
        );
    }

    /**
     * Admin : un brouillon plus récent (version) gagne sur un clone publié
     * de catégorie (id plus haut). La caisse continue de lire max version publiée.
     */
    private function pickAdminProfile(\Closure $base, ?int $branchIdScope): ?ItemWizardProfile
    {
        $scopes = $branchIdScope !== null
            ? [
                fn () => $base()->where('branch_id_scope', $branchIdScope),
                fn () => $base()->whereNull('branch_id_scope'),
            ]
            : [
                fn () => $base()->whereNull('branch_id_scope'),
            ];

        foreach ($scopes as $scoped) {
            $draft = $scoped()->where('is_published', false)->orderByDesc('version')->orderByDesc('id')->first();
            if ($draft) {
                return $draft;
            }
            $any = $scoped()->orderByDesc('version')->orderByDesc('id')->first();
            if ($any) {
                return $any;
            }
        }

        return null;
    }

    public function createForCategory(ItemCategory $category, array $payload): ItemWizardProfile
    {
        return DB::transaction(function () use ($category, $payload): ItemWizardProfile {
            $profile = ItemWizardProfile::query()->create([
                'item_id' => null,
                'item_category_id' => $category->id,
                'template' => $payload['template'],
                'branch_id_scope' => $payload['branch_id_scope'] ?? null,
                // Avant : toujours version=1. Ré-appliquer un template après
                // publication recréait un profil que la caisse ignorait.
                'version' => max(1, (int) ($payload['version'] ?? 1)),
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

        if ($profile->is_published) {
            return $this->forkUnpublishedDraft($profile, $payload);
        }

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

            // POS/kiosk ne lisent que item_id. Un wizard catégorie « publié »
            // sans copie sur les produits n'existait que dans l'admin.
            $fanOutIds = $this->fanOutCategoryPublish($fresh);

            $snapshot = $fresh->steps
                ->sortBy('position')
                ->map(fn (ItemWizardStep $step): array => $step->toArray())
                ->values()
                ->all();
            if ($fanOutIds !== []) {
                // Métadonnée ignorée par ComposerDiffService (pas de step_key).
                array_unshift($snapshot, ['fan_out_profile_ids' => $fanOutIds]);
            }

            ItemWizardStepVersion::create([
                'profile_id' => $fresh->id,
                'version' => $fresh->version,
                'snapshot' => $snapshot,
                'published_at' => now(),
                'published_by_id' => auth()->id() ?: null,
            ]);
            ComposerProfilePublished::dispatch((int) $fresh->id);
            ComposerProfileChanged::dispatch(...$this->composerChangedPayload($fresh, 'published'));

            return $fresh;
        });
    }

    /**
     * Recopie le wizard catégorie sur chaque produit, en version supérieure
     * aux profils item déjà publiés, pour que la caisse prenne le nouveau.
     */
    /**
     * @return array<int, int>
     */
    private function fanOutCategoryPublish(ItemWizardProfile $categoryProfile): array
    {
        if (! $categoryProfile->item_category_id) {
            return [];
        }

        $items = Item::query()
            ->where('item_category_id', $categoryProfile->item_category_id)
            ->get();

        $ids = [];
        foreach ($items as $item) {
            $ids[] = $this->upsertPublishedItemClone($categoryProfile, $item);
        }

        return $ids;
    }

    private function upsertPublishedItemClone(ItemWizardProfile $source, Item $item): int
    {
        $scope = $source->branch_id_scope;

        $maxVersion = (int) ItemWizardProfile::query()
            ->where('item_id', $item->id)
            ->max('version');
        $nextVersion = max($maxVersion, (int) $source->version) + 1;

        // Toujours une NOUVELLE ligne : un brouillon produit ne doit pas
        // se transformer en « publié » ni perdre ses étapes. La caisse lit
        // la version max publiée, donc le clone gagne sans écraser l'historique.
        $target = new ItemWizardProfile();
        $target->fill([
            'item_id' => $item->id,
            'item_category_id' => null,
            'template' => $source->template,
            'branch_id_scope' => $scope,
            'is_published' => true,
            'published_at' => now(),
            'version' => $nextVersion,
        ]);
        $target->save();

        foreach ($source->steps as $step) {
            $this->stepService->create($target, [
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
            ], false);
        }

        $freshTarget = $target->fresh('steps');
        ComposerProfileChanged::dispatch(
            ...$this->composerChangedPayload($freshTarget, 'published')
        );

        return (int) $freshTarget->id;
    }

    /**
     * Avant : Dépublier le wizard catégorie remettait le badge « Brouillon »
     * mais les clones item_id restaient publiés — la caisse continuait.
     */
    private function reverseCategoryPublish(ItemWizardProfile $profile): void
    {
        if (! $profile->item_category_id) {
            return;
        }

        $ids = [];
        foreach ($profile->versions()->orderByDesc('version')->get() as $version) {
            foreach ((array) $version->snapshot as $row) {
                if (! is_array($row) || ! isset($row['fan_out_profile_ids']) || ! is_array($row['fan_out_profile_ids'])) {
                    continue;
                }
                foreach ($row['fan_out_profile_ids'] as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }

        foreach (array_keys($ids) as $id) {
            $clone = ItemWizardProfile::query()->find($id);
            if (! $clone || ! $clone->item_id || ! $clone->is_published) {
                continue;
            }

            $clone->unpublish();
            ComposerProfileChanged::dispatch(
                ...$this->composerChangedPayload($clone->fresh('steps'), 'unpublished')
            );
        }
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

        $probeItems = collect();
        if ($fresh->item_id && $fresh->item) {
            $probeItems = collect([
                $fresh
                    ->fresh(['steps', 'item.variations.itemAttribute', 'item.extras', 'item.addons.addonItem'])
                    ->item,
            ]);
        } elseif ($fresh->item_category_id) {
            $probeItems = Item::query()
                ->with(['variations.itemAttribute', 'extras', 'addons.addonItem'])
                ->where('item_category_id', $fresh->item_category_id)
                ->get();
        }

        foreach ($probeItems as $probeItem) {
            foreach ($activeSteps as $step) {
                $this->assertBoundSourceWhenAmbiguous($probeItem, $step);
                if ((int) $step->min_select > 0 && ! $this->requiredStepHasChoicesForItem($fresh, $probeItem, $step)) {
                    throw ValidationException::withMessages([
                        'steps' => 'Composer profile contains a required step without available choices.',
                    ]);
                }
            }
        }
    }

    /**
     * Avant : une page « Viande » sans source_ref projetait aussi les sauces.
     * On refuse de publier si le produit a plusieurs groupes et la page n'est
     * reliée à aucun.
     */
    private function assertBoundSourceWhenAmbiguous(Item $item, ItemWizardStep $step): void
    {
        $ref = trim((string) ($step->source_ref ?? ''));
        if ($ref !== '') {
            return;
        }

        if ((string) $step->source_type === 'item_attribute') {
            $count = $item->variations->pluck('item_attribute_id')->filter()->unique()->count();
            if ($count > 1) {
                throw ValidationException::withMessages([
                    'steps' => 'Relie chaque page à un attribut (Viande, Sauce…) sinon la caisse mélange les choix.',
                ]);
            }
        }

        if ((string) $step->source_type === 'extra_group') {
            $count = $item->extras
                ->map(fn ($extra): string => mb_strtolower(trim((string) ($extra->group_label ?? 'default'))))
                ->unique()
                ->count();
            if ($count > 1) {
                throw ValidationException::withMessages([
                    'steps' => 'Relie chaque page à un groupe d\'extras (crudité, supplément…) sinon la caisse mélange les choix.',
                ]);
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
            $this->reverseCategoryPublish($profile);
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
                'item_id' => $profile->item_id ? (int) $profile->item_id : null,
                'item_category_id' => $profile->item_category_id ? (int) $profile->item_category_id : null,
                'version' => (int) $profile->version,
                'is_published' => (bool) $profile->is_published,
            ],
        ];
    }

    /**
     * Avant : Enregistrer un wizard déjà en caisse réécrivait la ligne
     * publiée. Toast « brouillon », caisse déjà changée (produit) ou
     * clones périmés (catégorie). On fork un vrai brouillon, version max+1.
     */
    private function forkUnpublishedDraft(ItemWizardProfile $published, array $payload): ItemWizardProfile
    {
        $published->loadMissing('steps');

        $steps = $payload['steps'] ?? $published->steps
            ->sortBy('position')
            ->map(fn (ItemWizardStep $step): array => [
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
            ])
            ->values()
            ->all();

        $body = [
            'template' => $payload['template'] ?? $published->template,
            'branch_id_scope' => array_key_exists('branch_id_scope', $payload)
                ? $payload['branch_id_scope']
                : $published->branch_id_scope,
            'steps' => $steps,
        ];
        $scope = $body['branch_id_scope'] !== null ? (int) $body['branch_id_scope'] : null;

        if ($published->item_id) {
            $item = Item::query()->findOrFail((int) $published->item_id);
            $current = $this->showForItem($item, $scope);
            if ($current && ! $current->is_published) {
                return $this->update($current, $body + ['version' => $current->version]);
            }
            $body['version'] = ((int) ItemWizardProfile::query()->where('item_id', $item->id)->max('version')) + 1;

            return $this->createForItem($item, $body);
        }

        if ($published->item_category_id) {
            $category = ItemCategory::query()->findOrFail((int) $published->item_category_id);
            $current = $this->showForCategory($category, $scope);
            if ($current && ! $current->is_published) {
                return $this->update($current, $body + ['version' => $current->version]);
            }
            $body['version'] = ((int) ItemWizardProfile::query()
                ->where('item_category_id', $category->id)
                ->max('version')) + 1;

            return $this->createForCategory($category, $body);
        }

        throw ValidationException::withMessages([
            'profile' => 'Impossible de créer un brouillon pour ce wizard.',
        ]);
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
