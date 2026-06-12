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
use App\Models\ItemWizardStep;
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

    /**
     * [GOAL CMS GESTION T-W5b 2026-06-10] Delete a whole wizard profile.
     * Published profiles are refused with 409 (unpublish first).
     */
    public function destroy(Request $request, ItemWizardProfile $profile)
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        try {
            $this->profiles->destroy($profile);

            return response()->json(['status' => true], 200);
        } catch (\Exception $exception) {
            $status = (int) $exception->getCode() === 409 ? 409 : 422;

            return response()->json(['status' => false, 'message' => $exception->getMessage()], $status);
        }
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
        // [GOAL POLISH T-P1.1 2026-06-10 — R2-NEW-01] UNION over ALL active
        // items of the category: deriving sources from items()->first() only
        // hid every attribute/extra-group absent from that representative
        // item (Taille, Viande 2, …) — the category wizard's source picker
        // could never scope those steps.
        $items = $category->items()->get();
        abort_if($items->isEmpty(), 422, 'Category has no items yet - add at least one product before composing its wizard.');

        $merged = ['item_attribute' => collect(), 'extra_group' => collect(), 'addon' => collect()];
        foreach ($items as $item) {
            $sources = $this->buildAvailableSources($item);
            // attributes dedup by id; extra groups by group label (merge
            // choices, dedup by choice id); addons by id.
            foreach ($sources['item_attribute'] as $attribute) {
                if (! $merged['item_attribute']->has($attribute['id'])) {
                    $merged['item_attribute']->put($attribute['id'], $attribute);
                } else {
                    $existing = $merged['item_attribute']->get($attribute['id']);
                    $existing['choices'] = collect($existing['choices'])
                        ->concat($attribute['choices'])
                        ->unique('id')->values()->all();
                    $merged['item_attribute']->put($attribute['id'], $existing);
                }
            }
            foreach ($sources['extra_group'] as $group) {
                if (! $merged['extra_group']->has($group['id'])) {
                    $merged['extra_group']->put($group['id'], $group);
                } else {
                    $existing = $merged['extra_group']->get($group['id']);
                    $existing['choices'] = collect($existing['choices'])
                        ->concat($group['choices'])
                        ->unique('id')->values()->all();
                    $existing['count'] = count($existing['choices']);
                    $merged['extra_group']->put($group['id'], $existing);
                }
            }
            foreach ($sources['addon'] as $addon) {
                $merged['addon']->put($addon['id'], $addon);
            }
        }

        return response()->json([
            'success' => true,
            'data' => ['category_id' => (int) $category->id] + [
                'item_attribute' => $merged['item_attribute']->values(),
                'extra_group' => $merged['extra_group']->values(),
                'addon' => $merged['addon']->values(),
            ],
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
        // [CPC-01 heal 2026-06-11] Fold case AND accents so the guard is a true SUPERSET of MySQL
        // utf8mb4_unicode_ci (which folds BOTH). mb_strtolower alone (accent-SENSITIVE) let
        // "Supplément" pass the guard while the removal sweep — running under the accent-INSENSITIVE
        // DB collation — then matched and SOFT-DELETED the real "supplement" group's 9 catalog
        // options (adversarial CPC-01, reproduced end-to-end 9/9). Str::ascii strips diacritics +
        // expands ligatures → guard ⊇ DB equality on any driver. Proven: Supplément/SUPPLÉMENT/
        // supplément all fold to 'supplement' (blocked); plural "Suppléments" → 'supplements' stays
        // distinct (correctly allowed — genuinely different word).
        $needle = $this->foldGroupLabel($label);
        $collides = ItemExtra::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->pluck('group_label')
            ->contains(fn ($gl) => $this->foldGroupLabel((string) $gl) === $needle);
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
     * [W5 re-edit — option A, owner-picked 2026-06-09] Edit an EXISTING personal page IN PLACE.
     *
     * The page is identified by its STEP, route-bound by PRIMARY KEY ($step) — server-trusted, NOT a
     * client-supplied label. The group it edits is the step's OWN server-stored binding
     * ($step->source_ref); the request body can NEVER redirect the edit to a different group. So this
     * endpoint is collision-free by construction: it cannot "discover" and overwrite some other
     * pre-existing catalog group (the failure mode that sank the 3 create-time re-edit attempts). It
     * only ever touches the one group this step already points at. The create endpoint keeps its
     * conservative create-only guard; re-edit is the explicit, in-place editor.
     *
     * Semantics: full option sync of the bound group across the profile's items — updateOrCreate the
     * submitted options (price/media refresh), soft-delete options no longer present — plus the step's
     * display label + min/max/visible_on. The group identity (source_ref) and step_key are immutable
     * here (renaming the group is out of scope — it would reintroduce collision handling).
     */
    public function updatePersonalPage(ComposerPersonalPageRequest $request, ItemWizardProfile $profile, ItemWizardStep $step): JsonResponse
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        // The step must belong to THIS profile and be an option-group page. Route-model binding does
        // not scope {step} to {profile}, so enforce it (prevents editing another profile's step).
        abort_if((int) $step->profile_id !== (int) $profile->id, 404);
        abort_if($step->source_type !== 'extra_group', 422, "Cette page n'est pas une page d'options modifiable.");

        $groupLabel = (string) $step->source_ref;            // server-derived binding — never from the body
        abort_if($groupLabel === '', 422, 'This step is not bound to an options group.');

        $data = $request->validated();
        $visibleOn = $data['visible_on'] ?? ($step->visible_on ?: ['pos', 'kiosk']);

        $items = $profile->item_id
            ? Item::query()->whereKey($profile->item_id)->get()
            : Item::query()->whereNull('deleted_at')->where('item_category_id', $profile->item_category_id)->get();
        abort_if($items->isEmpty(), 422, 'No products to attach the personal page to.');

        $submittedNames = collect($data['options'])->pluck('name')->map(fn ($n) => (string) $n)->all();

        DB::transaction(function () use ($items, $data, $groupLabel, $visibleOn, $submittedNames, $step) {
            foreach ($items as $item) {
                foreach ($data['options'] as $opt) {
                    ItemExtra::query()->updateOrCreate(
                        ['item_id' => $item->id, 'name' => $opt['name'], 'group_label' => $groupLabel],
                        [
                            'price' => $opt['price'],
                            'status' => Status::ACTIVE,
                            'visible_on' => $visibleOn,
                            'description' => $opt['description'] ?? null,
                            'image_path' => $opt['image_path'] ?? null,
                        ]
                    );
                }

                // Removal: soft-delete options of THIS group no longer present in the submission.
                // [CPC-01 heal 2026-06-11] BYTE-EXACT removal. The coarse DB `where('group_label')`
                // runs under the connection collation (MySQL utf8mb4_unicode_ci folds accents) and
                // would over-select a DIFFERENT group whose label is accent/case-equal (e.g. legacy
                // data carrying BOTH "supplement" and "Supplément"). Every row of THIS step's group
                // shares the EXACT byte label (createPersonalPage updateOrCreate keyed on the verbatim
                // $label), so narrow to a byte-exact match in PHP before deleting — a group the step
                // does not own is never touched, on any driver. The guard above blocks creating such
                // a twin going forward; this also protects any pre-existing twin. Soft-delete is
                // reversible and does not alter past orders (NF525 composition_snapshot is frozen).
                $removableIds = ItemExtra::query()
                    ->where('item_id', $item->id)
                    ->where('group_label', $groupLabel)
                    ->whereNotIn('name', $submittedNames)
                    ->get(['id', 'group_label'])
                    ->filter(fn ($extra) => (string) $extra->group_label === (string) $groupLabel)
                    ->pluck('id');
                if ($removableIds->isNotEmpty()) {
                    ItemExtra::query()->whereIn('id', $removableIds)->delete();
                }
            }

            // Update display + selection props on the step. source_ref / step_key stay immutable.
            $step->forceFill([
                'label' => (string) $data['label'],
                'min_select' => (int) ($data['min_select'] ?? $step->min_select),
                'max_select' => (int) ($data['max_select'] ?? max((int) $step->max_select, count($data['options']))),
                'visible_on' => $visibleOn,
            ])->save();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'step_id' => (int) $step->id,
                'step_key' => (string) $step->step_key,
                'group_label' => $groupLabel,
                'options_synced' => count($data['options']),
                'items_touched' => $items->count(),
            ],
        ], 200);
    }

    /**
     * [W1 re-edit pre-fill] Return the editable state of an EXISTING personal page so the builder
     * modal can open PRE-FILLED, then submit back to updatePersonalPage(). Read-only; keyed on the
     * server-trusted step PK (the SAME binding the PUT edits), so the pre-fill and the subsequent
     * edit always describe the one group this step points at.
     *
     * Options ARE returned WITH price here: the admin editor edits the construct directly, and price
     * lives on ItemExtra (the SSOT). This is the opposite concern from the kiosk/POS PROJECTION, which
     * stays price-free and joins price by choice id — that invariant is untouched (we don't feed this
     * payload into composer_profile; it only hydrates the admin form, mirroring the create modal's own
     * price inputs).
     */
    public function showPersonalPage(Request $request, ItemWizardProfile $profile, ItemWizardStep $step): JsonResponse
    {
        $this->authorizeBranchScope($request, $profile->branch_id_scope);

        abort_if((int) $step->profile_id !== (int) $profile->id, 404);
        abort_if($step->source_type !== 'extra_group', 422, "Cette page n'est pas une page d'options modifiable.");

        $groupLabel = (string) $step->source_ref;
        abort_if($groupLabel === '', 422, 'This step is not bound to an options group.');

        // Read the UNION of options across ALL items the PUT will write to — NOT a single
        // representative. [W5 adversarial-fix P1] updatePersonalPage soft-deletes options absent from
        // the submission across EVERY item in scope; a category group can have HETEROGENEOUS sibling
        // option-sets (e.g. category 'supplement' has 3 distinct sets across 12 items). If this pre-fill
        // showed only one item's subset, options present only on OTHER siblings would be invisible in
        // the modal and then SILENTLY soft-deleted on save. The union surfaces every option that exists
        // on any sibling, so a removal is always an informed, on-screen choice — never a surprise.
        $items = $profile->item_id
            ? Item::query()->whereKey($profile->item_id)->get()
            : Item::query()->whereNull('deleted_at')
                ->where('item_category_id', $profile->item_category_id)
                ->get();
        abort_if($items->isEmpty(), 422, 'No products to read the personal page from.');

        $options = ItemExtra::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->where('group_label', $groupLabel)
            ->orderBy('id')
            ->get()
            // Dedupe by name with the SAME case-folding the create guard / projection use, so the union
            // is one row per distinct option (first occurrence keeps its price/media as representative).
            ->unique(fn ($extra) => mb_strtolower((string) $extra->name))
            ->map(fn ($extra) => [
                'name' => (string) $extra->name,
                'price' => (float) $extra->price,
                'description' => $extra->description !== null ? (string) $extra->description : '',
                'image_path' => $extra->image_path !== null ? (string) $extra->image_path : null,
            ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'step_id' => (int) $step->id,
                'label' => (string) $step->label,
                'group_label' => $groupLabel,
                'min_select' => (int) $step->min_select,
                'max_select' => (int) $step->max_select,
                'visible_on' => $step->visible_on ?: ['pos', 'kiosk'],
                'options' => $options,
            ],
        ]);
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
    /**
     * [CPC-01 heal 2026-06-11] Normalise a group_label for COLLISION DETECTION so the PHP guard is a
     * true SUPERSET of the database collation used by the removal sweep. MySQL utf8mb4_unicode_ci
     * folds BOTH case AND accents; mb_strtolower alone folds only case, leaving an accent gap that
     * let "Supplément" pass the guard yet collide-and-delete the real "supplement" catalog group.
     * Str::ascii strips diacritics and expands ligatures, so folding here ⊇ DB equality on every
     * driver (SQLite test == MySQL prod). NOT used for storage or rendering — only the guard.
     */
    private function foldGroupLabel(string $label): string
    {
        return Str::ascii(mb_strtolower(trim($label)));
    }

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

        // [GOAL CMS heal P1-4 2026-06-10] each source carries its read-only
        // `choices` (id, name, PRICE from the catalog construct) so the
        // builder can show what each option costs. Admin-only payload — the
        // kiosk projection stays price-free (NF525 SSOT untouched).
        $attributes = $item->variations
            ->filter(fn ($variation) => $variation->itemAttribute !== null)
            ->groupBy(fn ($variation) => (int) $variation->itemAttribute->id)
            ->map(function ($variations) {
                $attr = $variations->first()->itemAttribute;

                return [
                    'id' => (int) $attr->id,
                    'name' => (string) $attr->name,
                    'source_type' => 'item_attribute',
                    'choices' => $variations->map(fn ($variation) => [
                        'id' => (int) $variation->id,
                        'name' => (string) $variation->name,
                        'price' => (float) $variation->price,
                    ])->values()->all(),
                ];
            })->values();

        $extras = $item->extras
            ->groupBy(fn ($extra) => (string) ($extra->group_label ?? 'default'))
            ->map(fn ($group, $label) => [
                'id' => (string) $label,
                'name' => $label === 'default' ? 'Extras' : (string) $label,
                'source_type' => 'extra_group',
                'count' => $group->count(),
                'choices' => $group->map(fn ($extra) => [
                    'id' => (int) $extra->id,
                    'name' => (string) $extra->name,
                    'price' => (float) $extra->price,
                ])->values()->all(),
            ])->values();

        $addons = $item->addons
            ->map(fn ($addon) => [
                'id' => (int) $addon->id,
                'name' => $addon->addonItem?->name ?? "Addon #{$addon->id}",
                'source_type' => 'addon',
                'addon_role' => $addon->role,
                'price' => $addon->addonItem !== null ? (float) $addon->addonItem->price : null,
            ])->values();

        return [
            'item_attribute' => $attributes,
            'extra_group' => $extras,
            'addon' => $addons,
        ];
    }
}
