<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Http\Requests\ComposerPersonalPageRequest;
use App\Http\Requests\ComposerProfileRequest;
use App\Http\Resources\ComposerProfileResource;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerDiffService;
use App\Services\Composer\ComposerProfileService;
use App\Services\Composer\ComposerStepService;
use App\Services\Composer\ComposerTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return response()->json([
            'success' => true,
            'data' => ['item_id' => (int) $item->id] + $this->buildAvailableSources($item),
        ]);
    }

    /**
     * [GOAL_WIZARD_DYNAMIC W1 / GAP-E] Category sibling of availableSources(): powers
     * the source picker when composing a CATEGORY wizard. Sources are derived from a
     * representative item of the category (same pattern as applyTemplateToCategory),
     * because options (variations/extras/addons) live on items, not categories.
     */
    public function availableSourcesForCategory(ItemCategory $category): JsonResponse
    {
        $firstItem = $category->items()->first();
        abort_if(! $firstItem, 422, 'Category has no items yet - add at least one product before composing its wizard.');

        return response()->json([
            'success' => true,
            'data' => ['category_id' => (int) $category->id] + $this->buildAvailableSources($firstItem),
        ]);
    }

    /**
     * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 5] Construct-on-the-fly "personal/free page":
     * create a NEW ItemExtra group (group_label = label) carrying each option's price
     * ON THE CONSTRUCT, then bind ONE extra_group step to it — one atomic action.
     *
     * Scope by profile owner:
     *  - item-owned profile -> the group is created on that item.
     *  - category profile    -> the group is REPLICATED onto every item of the category,
     *    because the projection reads each item's OWN extras (inheritance, not copy) so a
     *    category page only renders on a sibling that actually carries the construct.
     *
     * NF525: option prices live on ItemExtra (catalog SSOT); the step never carries a price
     * (ComposerPersonalPageRequest prohibits a page-level price).
     */
    public function createPersonalPage(ComposerPersonalPageRequest $request, ItemWizardProfile $profile): JsonResponse
    {
        // Mutating endpoint (creates ItemExtras + a step) — use the WRITE-tier guard like every
        // sibling write (store/storeForCategory/publish): a non-admin on a global/null-scope
        // profile must 403, not pass through the read-tier helper.
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        $data = $request->validated();
        $label = trim((string) $data['label']);
        abort_if($label === '', 422, 'A page label is required.');

        $items = $profile->item_id
            ? Item::query()->whereKey($profile->item_id)->get()
            : Item::query()->whereNull('deleted_at')->where('item_category_id', $profile->item_category_id)->get();

        abort_if($items->isEmpty(), 422, 'No products to attach the personal page to.');

        // Collision guard (CONSERVATIVE, create-only) — robust by construction:
        // a personal page may only CREATE a NEW options group, never touch an existing one. If ANY
        // ItemExtra with this group_label already exists on a target item, reject. Because the check
        // runs BEFORE the transaction, by the time updateOrCreate runs no group with $label exists, so
        // it can only INSERT — it can NEVER overwrite a real catalog group's prices. No provenance
        // marker, no client-resendable ownership key, no bypass class.
        //
        // The earlier "ownership exception" (allow idempotent re-submit when this profile already
        // owns a step bound to $label) was REMOVED: distinguishing a personal-page step from a normal
        // catalog-bound step required step-level provenance that the editor's delete+recreate flow
        // erased and a client could forge — re-opening a catalog-price overwrite (3 adversarial rounds
        // proved each variant unsound). Trade-off: re-editing a created page by re-POSTing the same
        // label is no longer supported here; its options are edited via the catalog/step editor.
        // Restoring in-builder re-edit safely is an owner design decision — see
        // reports/test-e2e/wizard-dynamic-2026-06-08/WIZARD_W5_PERSONAL_PAGE_REEDIT_DESIGN_GATE.md.
        // Compare case-insensitively IN PHP with the SAME folding the projection uses
        // (mb_strtolower) — NOT via SQL `where('group_label', $label)`, whose equality is decided by
        // the DB collation (MySQL utf8mb4_unicode_ci ≠ PHP mbstring case-folding). If the guard's
        // "equal" set were not a superset of the projection's, a case-variant label (e.g. "sauces"
        // vs catalog "Sauces") would pass the guard yet project a DUPLICATE kiosk step whose render
        // cross-contaminates the pre-existing group's options. Folding here makes guard ⊇ projection
        // by construction on ANY database (SQLite test == MySQL prod).
        $needle = mb_strtolower($label);
        $collides = ItemExtra::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->pluck('group_label')
            ->contains(fn ($gl) => mb_strtolower((string) $gl) === $needle);
        abort_if($collides, 422, "Le libellé « {$label} » est déjà utilisé par un groupe d'options existant — choisissez un autre nom.");

        $visibleOn = $data['visible_on'] ?? ['pos', 'kiosk'];
        $stepService = app(ComposerStepService::class);

        $step = DB::transaction(function () use ($items, $data, $label, $visibleOn, $profile, $stepService) {
            foreach ($items as $item) {
                foreach ($data['options'] as $opt) {
                    // updateOrCreate (not firstOrCreate): re-submitting the same page must UPDATE
                    // the option price/media — firstOrCreate would silently keep the stale price.
                    ItemExtra::query()->updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'name' => $opt['name'],
                            'group_label' => $label,
                        ],
                        [
                            'price' => $opt['price'],
                            'status' => Status::ACTIVE,
                            'visible_on' => $visibleOn,
                            'description' => $opt['description'] ?? null,
                            'image_path' => $opt['image_path'] ?? null,
                        ]
                    );
                }
            }

            // Idempotent: reuse the step already bound to this group_label instead of suffixing a
            // duplicate (a re-submit must not create label / label_2 twins projecting the same options).
            $existing = $profile->steps()
                ->where('source_type', 'extra_group')
                ->where('source_ref', $label)
                ->first();
            if ($existing) {
                return $existing;
            }

            return $stepService->create($profile, [
                'step_key' => $this->personalPageStepKey($profile, $label),
                'label' => $label,
                'source_type' => 'extra_group',
                'source_ref' => $label,
                'min_select' => (int) ($data['min_select'] ?? 0),
                'max_select' => (int) ($data['max_select'] ?? count($data['options'])),
                'is_active' => true,
                'visible_on' => $visibleOn,
                'position' => (int) ($profile->steps()->max('position') ?? 0) + 1,
                'addon_role' => null,
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'step_id' => (int) $step->id,
                'step_key' => (string) $step->step_key,
                'group_label' => $label,
                'options_created' => count($data['options']),
                'items_touched' => $items->count(),
            ],
        ], 201);
    }

    /**
     * Reserved kiosk step_keys that route to a FROZEN specialized component
     * (KioskWizardComponent STEP_KEY_REGISTRY + ADDON_ROLE_TO_TYPE keys). A builder-generated
     * step_key MUST avoid these, otherwise the page is hijacked by e.g. KioskStepSauceComponent
     * — which ignores step.choices and renders the personal page's options as nothing. Mirrored
     * by ComposerPersonalPageStepKeySentinelTest so it can't drift from the JS registry.
     */
    public const RESERVED_KIOSK_STEP_KEYS = [
        'pain', 'galette', 'bun', 'viande', 'meat', 'proteine', 'sauce', 'sauces',
        'garnitures', 'garniture', 'crudites', 'supplements', 'supplement', 'extras',
        'menu', 'formule', 'boisson', 'drink', 'frites', 'side', 'dessert',
        'taille', 'size', 'menu_component',
    ];

    /**
     * Registry-collision-safe, unique step_key for a personal page (DB unique(profile_id, step_key)).
     * A bare label like "Sauce"/"Menu" slugs to a reserved key — prefix it so the page reaches the
     * NON-frozen generic component instead of a frozen specialized one.
     */
    private function personalPageStepKey(ItemWizardProfile $profile, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'page';
        if (in_array($base, self::RESERVED_KIOSK_STEP_KEYS, true)) {
            $base = 'page_' . $base; // escape the kiosk registry → generic render
        }

        $existing = $profile->steps()->pluck('step_key')->all();
        $candidate = $base;
        $i = 2;
        while (in_array($candidate, $existing, true)) {
            $candidate = $base . '_' . $i;
            $i++;
        }

        return $candidate;
    }

    /**
     * Builds the labeled source candidates (item_attribute / extra_group / addon) for
     * a single item — shared by the item and category source-picker endpoints.
     */
    private function buildAvailableSources(Item $item): array
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
            'item_attribute' => $attributes,
            'extra_group' => $extras,
            'addon' => $addons,
        ];
    }
}
