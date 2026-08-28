<?php

namespace App\Http\Resources;


use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\KioskMachine;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $price = $this->price;

        // [POS-9.1.14] Expose normalized allergens to the admin / POS surface.
        // Without this, the cashier and the back-office had no allergen
        // signal — a customer asking "is there gluten?" got no answer.
        // FIC compliance (UE 1169/2011) + EAA 2025. POS-GA-F-36.
        // Shape mirrors NormalItemResource (kiosk) for cross-surface
        // consistency. Falls back gracefully if the relation is missing
        // (legacy items without entries in `item_allergen`).
        $allergens = $this->relationLoaded('allergens')
            ? $this->allergens
            : (method_exists($this->resource, 'allergens') ? $this->allergens()->get() : collect());

        $allergensPayload = $allergens->map(function ($allergen) {
            return [
                'id'       => (int) $allergen->id,
                'code'     => (string) $allergen->code,
                'name_key' => (string) $allergen->name_key,
                'icon'     => $allergen->icon,
                'is_trace' => (bool) ($allergen->pivot->is_trace ?? false),
            ];
        })->values();
        $surface = (string) $request->get('surface', 'pos');
        $branchId = $this->resolveComposerBranchId($request);
        $choiceAvailability = $branchId !== null
            ? app(ChoiceAvailabilityResolver::class)->snapshotForItem($this->resource, $branchId, $surface)
            : ['variations' => [], 'extras' => [], 'addons' => []];
        $variations = $this->variations->each(function ($variation) use ($choiceAvailability) {
            $availability = $choiceAvailability['variations'][(int) $variation->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $variation->setAttribute('is_available', $availability['is_available']);
            $variation->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });
        $extras = $this->extras->each(function ($extra) use ($choiceAvailability) {
            $availability = $choiceAvailability['extras'][(int) $extra->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $extra->setAttribute('is_available', $availability['is_available']);
            $extra->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });
        $addons = $this->addons->each(function ($addon) use ($choiceAvailability) {
            $availability = $choiceAvailability['addons'][(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $addon->setAttribute('is_available', $availability['is_available']);
            $addon->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });

        return [
            "id"               => $this->id,
            "name"             => $this->name,
            "slug"             => $this->slug,
            "item_category_id" => $this->item_category_id,
            "tax_id"           => $this->tax_id,
            "flat_price"       => AppLibrary::flatAmountFormat($this->price),
            "convert_price"    => AppLibrary::convertAmountFormat($this->price),
            "currency_price"   => AppLibrary::currencyAmountFormat($this->price),
            "price"            => $this->price,
            "item_type"        => $this->item_type,
            "is_featured"      => $this->is_featured,
            // [ONB-02 2026-08-28] Le rang manquait ICI AUSSI.
            //
            // La liste passe par `SimpleItemResource`, la fiche par celle-ci : les
            // deux hydratent le meme formulaire. Corriger l'une et laisser l'autre
            // aurait reproduit le defaut sur un chemin sur deux — c'est le motif
            // « jumeau oublie », rencontre quatre fois cette semaine.
            "order"            => $this->order,
            // [GAP-27-1] Expose is_upsell so admin UI can read/write it (Ask::NO=10 default)
            "is_upsell"        => $this->is_upsell ?? 10,
            // [v1-0-1-h5 Z5-P1-01 2026-05-17] Expose channels so the admin
            // items form can hydrate the channel checkbox group on edit.
            // NULL means "visible everywhere" (Item::displaysOn); an array
            // (e.g. ['kiosk','pos']) restricts the item to those surfaces.
            "channels"         => $this->channels,
            "status"           => $this->status,
            "description"      => $this->description === null ? '' : $this->description,
            "caution"          => $this->caution === null ? '' : $this->caution,
            // [POS-9.1.14] Allergens projection (see comment above the toArray body).
            "allergens"        => $allergensPayload,
            "allergen_flags"   => $this->allergen_flags ?? [],
            "sort_order"       => $this->order,
            // [TERRAIN-HEAL 2026-07-16 · ITEMRES-ORDERCOUNT] $this->orders->count() hydratait TOUTE
            // la collection orders par item (N+1 + mémoire) pour n'en compter que le nombre. On préfère
            // withCount (orders_count), sinon la relation déjà chargée, sinon un COUNT(*) léger.
            "order_count"      => $this->orders_count
                ?? ($this->relationLoaded('orders') ? $this->orders->count() : $this->orders()->count()),
            "thumb"            => $this->thumb,
            "cover"            => $this->cover,
            "preview"          => $this->preview,
            "category_name"    => optional($this->category)->name,
            "wizard_template"  => optional($this->category)->wizard_template ?? 'simple',
            "has_menu"         => (bool)(optional($this->category)->has_menu ?? false),
            "category"         => new ItemCategoryResource($this->category),
            "tax"              => new TaxResource($this->tax),
            "variations"       => $variations->groupBy('item_attribute_id'),
            "itemAttributes"   => ItemAttributeResource::collection($this->itemAttributeList($variations)),
            "extras"           => ItemExtraResource::collection($extras),
            "addons"           => ItemAddonResource::collection($addons->load('addonItem')),
            "composer_profile" => $this->composerProfilePayload(
                $surface,
                $branchId,
            ),
            "offer"            => SimpleOfferResource::collection(
                $this->offer->filter(function ($offer) use ($price) {
                    if (Carbon::now()->between($offer->start_date, $offer->end_date) && $offer->status === Status::ACTIVE) {
                        $amount                = ($price - ($price / 100 * $offer->amount));
                        $offer->flat_price     = AppLibrary::flatAmountFormat($amount);
                        $offer->convert_price  = AppLibrary::convertAmountFormat($amount);
                        $offer->currency_price = AppLibrary::currencyAmountFormat($amount);
                        return $offer;
                    }
                })
            )
        ];
    }

    private function itemAttributeList($variations)
    {
        $array = [];
        foreach ($variations as $b) {
            if (!isset($array[$b->itemAttribute->id])) {
                $array[$b->itemAttribute->id] = (object)[
                    'id'           => $b->itemAttribute->id,
                    'name'         => $b->itemAttribute->name,
                    'status'       => $b->itemAttribute->status,
                    'min_select'   => $b->itemAttribute->min_select ?? 0,
                    'max_select'   => $b->itemAttribute->max_select ?? 1,
                    'allow_repeat' => $b->itemAttribute->allow_repeat ?? false,
                ];
            }
        }
        return collect($array);
    }

    private function composerProfilePayload(string $surface, ?int $branchId): ?array
    {
        $query = ItemWizardProfile::query()
            ->with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
            ->where('item_id', $this->id)
            ->where('is_published', true);

        if ($branchId !== null) {
            $query->where(function ($scope) use ($branchId): void {
                $scope->where('branch_id_scope', $branchId)
                    ->orWhereNull('branch_id_scope');
            })->orderByRaw('CASE WHEN branch_id_scope = ? THEN 0 ELSE 1 END', [$branchId]);
        } else {
            $query->whereNull('branch_id_scope');
        }

        $profile = $query
            ->latest('version')
            ->latest('id')
            ->first();

        if (! $profile) {
            return null;
        }

        return app(ComposerProfileProjection::class)->project($profile, $this->resource, $surface, $branchId);
    }

    private function resolveComposerBranchId($request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        if (method_exists($user, 'tokenCan') && $user->tokenCan('kiosk:order')) {
            $branchId = (int) KioskMachine::query()
                ->where('user_id', $user->id)
                ->value('branch_id');

            return $branchId > 0 ? $branchId : null;
        }

        $requestedBranch = $request->get('branch_id') ?? $request->get('branch_id_scope');
        if (($user->hasRole('Admin') || $user->hasRole('Tenant Admin')) && $requestedBranch !== null && $requestedBranch !== '') {
            $branchId = (int) $requestedBranch;
            return $branchId > 0 ? $branchId : null;
        }

        $candidate = $user->branch_id;

        if ($candidate === null || $candidate === '') {
            return null;
        }

        $branchId = (int) $candidate;
        return $branchId > 0 ? $branchId : null;
    }

}
