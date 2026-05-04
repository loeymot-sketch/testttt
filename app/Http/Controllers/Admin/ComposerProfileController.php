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
        $profile = $this->profiles->createForItem($item, $payload);

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
        $profile = $this->profiles->createForCategory($category, $payload);

        return response()->json([
            'success' => true,
            'data' => new ComposerProfileResource($profile->loadMissing('steps')),
        ]);
    }

    /**
     * Returns the labeled source candidates available for an item's wizard
     * (item_attribute / extra_group / addon). Powers the source picker in the
     * admin StepEditor — replaces the previous raw `source_ref` text input.
     */
    public function availableSources(Item $item): JsonResponse
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

        return response()->json([
            'success' => true,
            'data' => [
                'item_id' => (int) $item->id,
                'item_attribute' => $attributes,
                'extra_group' => $extras,
                'addon' => $addons,
            ],
        ]);
    }
}
