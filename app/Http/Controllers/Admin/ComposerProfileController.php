<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ComposerProfileRequest;
use App\Http\Resources\ComposerProfileResource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerDiffService;
use App\Services\Composer\ComposerProfileService;
use App\Services\Composer\ComposerTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComposerProfileController extends AdminController
{
    public function __construct(
        private readonly ComposerProfileService $profiles,
        private readonly ComposerTemplateService $templates,
    ) {
        parent::__construct();
    }

    public function show(Request $request, Item $item)
    {
        $branchIdScope = $request->integer('branch_id_scope') ?: null;
        $user = $request->user();
        if ($branchIdScope === null && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Tenant Admin')) {
            $branchIdScope = (int) $user->branch_id ?: null;
        }

        $profile = $this->profiles->showForItem($item, $branchIdScope);

        abort_if(! $profile, 404);

        $this->authorizeBranchScope($request, $profile->branch_id_scope);

        return new ComposerProfileResource($profile);
    }

    public function store(ComposerProfileRequest $request, Item $item)
    {
        $this->authorizeWritableBranchScope($request, $request->integer('branch_id_scope') ?: null);

        return new ComposerProfileResource($this->profiles->createForItem($item, $request->validated()));
    }

    public function showForCategory(Request $request, ItemCategory $category)
    {
        $branchIdScope = $request->integer('branch_id_scope') ?: null;
        $user = $request->user();
        if ($branchIdScope === null && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Tenant Admin')) {
            $branchIdScope = (int) $user->branch_id ?: null;
        }

        $profile = $this->profiles->showForCategory($category, $branchIdScope);

        abort_if(! $profile, 404);

        $this->authorizeBranchScope($request, $profile->branch_id_scope);

        return new ComposerProfileResource($profile);
    }

    public function storeForCategory(ComposerProfileRequest $request, ItemCategory $category)
    {
        $this->authorizeWritableBranchScope($request, $request->integer('branch_id_scope') ?: null);

        return new ComposerProfileResource($this->profiles->createForCategory($category, $request->validated()));
    }

    public function update(ComposerProfileRequest $request, ItemWizardProfile $profile)
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);
        $this->authorizeWritableBranchScope($request, $request->integer('branch_id_scope') ?: null);

        return new ComposerProfileResource($this->profiles->update($profile, $request->validated()));
    }

    public function publish(Request $request, ItemWizardProfile $profile)
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        return new ComposerProfileResource($this->profiles->publish($profile));
    }

    public function unpublish(Request $request, ItemWizardProfile $profile)
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        return new ComposerProfileResource($this->profiles->unpublish($profile));
    }

    public function diff(Request $request, ItemWizardProfile $profile): JsonResponse
    {
        $this->authorizeBranchScope($request, $profile->branch_id_scope);

        return response()->json(app(ComposerDiffService::class)->diff($profile));
    }

    /**
     * Apply a named wizard template (sandwich/tacos/...) to bootstrap a starter
     * profile. When the admin selected a branch in the composer UI, the resulting
     * profile is scoped to that branch; otherwise it is global (branch_id_scope=null).
     * Branch-scoped seeding is only allowed for the user's own branch (Branch Admin/
     * Manager) or for any branch (Admin / Tenant Admin); a global seed still requires
     * Admin / Tenant Admin.
     */
    public function applyTemplate(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(ComposerTemplateService::TEMPLATES)],
            'branch_id_scope' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchIdScope = isset($data['branch_id_scope']) ? (int) $data['branch_id_scope'] : null;

        $this->authorizeWritableBranchScope($request, $branchIdScope);

        $payload = $this->templates->buildPayload($data['template'], $item, $branchIdScope);
        $profile = $this->profiles->applyTemplateToItem($item, $payload);

        return response()->json([
            'success' => true,
            'data' => new ComposerProfileResource($profile->loadMissing('steps')),
        ]);
    }

    public function applyTemplateToCategory(Request $request, ItemCategory $category): JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(ComposerTemplateService::TEMPLATES)],
            'branch_id_scope' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchIdScope = isset($data['branch_id_scope']) ? (int) $data['branch_id_scope'] : null;

        $this->authorizeWritableBranchScope($request, $branchIdScope);

        $firstItem = $category->items()->first();
        abort_if(! $firstItem, 422, 'Category has no items yet - add at least one product before applying a template.');

        $payload = $this->templates->buildPayload($data['template'], $firstItem, $branchIdScope);
        $profile = $this->profiles->applyTemplateToCategory($category, $payload);

        return response()->json([
            'success' => true,
            'data' => new ComposerProfileResource($profile->loadMissing('steps')),
        ]);
    }

    /**
     * Avant : le wizard catégorie n'avait pas d'API de sources. L'écran
     * affichait un sélecteur vide : impossible de relier une page à Viande.
     */
    public function availableSourcesForCategory(ItemCategory $category): JsonResponse
    {
        $items = $category->items()
            ->with(['variations.itemAttribute', 'extras', 'addons.addonItem'])
            ->get();
        abort_if($items->isEmpty(), 422, 'Category has no items yet - add at least one product before editing sources.');

        $attributes = collect();
        $extras = collect();
        $addons = collect();

        foreach ($items as $item) {
            $buckets = $this->sourceBuckets($item);
            $attributes = $attributes->concat($buckets['item_attribute']);
            $extras = $extras->concat($buckets['extra_group']);
            $addons = $addons->concat($buckets['addon']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'item_id' => (int) $items->first()->id,
                'item_attribute' => $attributes->unique('id')->values(),
                'extra_group' => $extras->unique('id')->values(),
                'addon' => $addons->unique('id')->values(),
            ],
        ]);
    }

    /**
     * Returns the labeled source candidates available for an item's wizard
     * (item_attribute / extra_group / addon). Powers the source picker in the
     * admin StepEditor — replaces the previous raw `source_ref` text input.
     */
    public function availableSources(Item $item): JsonResponse
    {
        $buckets = $this->sourceBuckets($item);

        return response()->json([
            'success' => true,
            'data' => [
                'item_id' => (int) $item->id,
                'item_attribute' => $buckets['item_attribute'],
                'extra_group' => $buckets['extra_group'],
                'addon' => $buckets['addon'],
            ],
        ]);
    }

    /**
     * @return array{item_attribute: array<int, array<string, mixed>>, extra_group: array<int, array<string, mixed>>, addon: array<int, array<string, mixed>>}
     */
    private function sourceBuckets(Item $item): array
    {
        $item->loadMissing(['variations.itemAttribute', 'extras', 'addons.addonItem']);

        $attributes = $item->variations
            ->pluck('itemAttribute')
            ->filter()
            ->unique('id')
            ->map(fn ($attr) => [
                'id' => (int) $attr->id,
                'name' => (string) $attr->name,
                'source_type' => 'item_attribute',
            ])->values();

        $extras = $item->extras
            ->groupBy(fn ($extra) => (string) ($extra->group_label ?? 'default'))
            ->map(fn ($group, $label) => [
                'id' => (string) $label,
                'name' => $label === 'default' ? 'Extras' : (string) $label,
                'source_type' => 'extra_group',
                'count' => $group->count(),
            ])->values();

        $addons = $item->addons
            ->map(fn ($addon) => [
                'id' => (int) $addon->id,
                'name' => $addon->addonItem?->name ?? "Addon #{$addon->id}",
                'source_type' => 'addon',
                'addon_role' => $addon->role,
            ])->values();

        return [
            'item_attribute' => $attributes->all(),
            'extra_group' => $extras->all(),
            'addon' => $addons->all(),
        ];
    }
}
