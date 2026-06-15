<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ComposerProfileRequest;
use App\Http\Resources\ComposerProfileResource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerDiffService;
use App\Services\Composer\ComposerProfileProjection;
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
     * [WIZARD-STUDIO W1 2026-06-14] Read-only DRAFT preview projection for the
     * visual Wizard Studio. Projects an UNPUBLISHED (draft) profile through the
     * SAME ComposerProfileProjection the live kiosk uses, so the operator's draft
     * renders identically to what customers will see — fed into the (frozen,
     * untouched) KioskWizardComponent mounted read-only in the Studio pane.
     *
     * Non-frozen. NF525-safe: GET only, no body, projection emits no price (price
     * stays catalog SSOT). Read-only: no order/cart path is reachable from here.
     * Sole deviation from the live shape: `is_published` is forced true so the
     * frozen consumer gate (KioskWizardComponent: `is_published === false` → null)
     * accepts the draft — done HERE in new code, never by editing the frozen file.
     */
    public function previewProjection(Request $request, ItemWizardProfile $profile): JsonResponse
    {
        // Read-only branch authz (same guard as show()/diff(); NOT the writable variant).
        $this->authorizeBranchScope($request, $profile->branch_id_scope);

        // The projector needs a concrete Item. Item-owned → its item; category-owned
        // → a representative active item of the category (per-item availability may
        // differ across the category; the Studio surfaces this caveat in the UI).
        $item = $profile->item_id
            ? Item::find($profile->item_id)
            : optional($profile->category)->items()->orderBy('id')->first(); // deterministic representative

        abort_if(! $item, 404, 'Aucun produit disponible pour prévisualiser ce wizard.');

        // Eager-load the relations the projection walks per step, to avoid N+1 (this endpoint
        // is hit on every Studio reload). Mirrors availableSources()'s loadMissing.
        $item->loadMissing(['variations.itemAttribute', 'extras', 'addons.addonItem']);

        $branchId = $profile->branch_id_scope ?? (int) ($request->user()?->branch_id ?? 0);

        $projected = app(ComposerProfileProjection::class)->project($profile, $item, 'kiosk', $branchId);

        if ($projected !== null) {
            // Linchpin: let the FROZEN kiosk consumer gate accept the draft. New code only.
            $projected['is_published'] = true;
        }

        return response()->json([
            'data' => [
                'item' => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    // Display only — NF525 price stays catalog SSOT; never on a step.
                    'price' => (float) $item->price,
                    'composer_profile' => $projected,
                ],
            ],
        ]);
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

    /**
     * [WIZARD-STUDIO W6 2026-06-15] Bindable sources for a CATEGORY wizard (unflagged V1 path),
     * resolved from a representative active item. Each entry carries the exact (source_type,
     * source_ref) the builder must persist so the page resolves to real, distinct options
     * (source_ref by attribute NAME so it matches every item in the category, not one item's id).
     * NF525-neutral, read-only.
     */
    public function availableSourcesForCategory(Request $request, ItemCategory $category): JsonResponse
    {
        // Gate = permission:catalog.compose (route group). The catalog (categories/items/variations/
        // extras/addons) is GLOBAL in V1 LOCAL (no BranchScope — branch isolation applies to
        // operational data: orders/stock/sessions, not the menu). So any catalog.compose author may
        // read a category's bindable sources; no per-branch scoping applies (and nothing here is
        // branch-private). V2-SaaS NOTE: when the catalog becomes multi-tenant, add branch scoping
        // to ItemCategory/Item and gate this endpoint accordingly (tracked with the BranchScope V2 backlog).
        $item = $category->items()->orderBy('id')->first();
        abort_if(! $item, 404, 'Aucun produit dans cette catégorie pour proposer des sources.');
        $item->loadMissing(['variations.itemAttribute', 'extras', 'addons.addonItem']);

        $attributes = $item->variations
            ->pluck('itemAttribute')
            ->filter()
            ->unique('id')
            ->map(fn ($attr) => [
                'id' => (int) $attr->id,
                'name' => (string) $attr->name,
                'source_type' => 'item_attribute',
                'source_ref' => (string) $attr->name, // match by name across the whole category
            ])->values();

        $extras = $item->extras
            ->groupBy(fn ($extra) => (string) ($extra->group_label ?? 'default'))
            ->map(fn ($group, $label) => [
                'id' => (string) $label,
                'name' => $label === 'default' ? 'Extras' : (string) $label,
                'source_type' => 'extra_group',
                'source_ref' => $label === 'default' ? '' : (string) $label,
                'count' => $group->count(),
            ])->values();

        $addons = $item->addons
            ->filter(fn ($addon) => $addon->role !== null)
            ->unique('role')
            ->map(fn ($addon) => [
                'id' => (string) $addon->role,
                'name' => $addon->addonItem?->name ?? ucfirst((string) $addon->role),
                'source_type' => 'addon',
                'source_ref' => (string) $addon->role,
                'addon_role' => $addon->role,
            ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'category_id' => (int) $category->id,
                'item_attribute' => $attributes,
                'extra_group' => $extras,
                'addon' => $addons,
            ],
        ]);
    }
}
