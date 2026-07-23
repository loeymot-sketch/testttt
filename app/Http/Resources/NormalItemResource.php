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

class NormalItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $price = $this->price;

        // Surface filtering: pass ?surface=kiosk|pos|web to restrict extras and variations.
        // null surface = no filtering (admin, backward-compatible).
        $surface = $request->get('surface');

        $filteredExtras = $surface
            ? $this->extras->filter(fn($e) => $e->isVisibleOn($surface))->values()
            : $this->extras;

        $filteredVariations = $surface
            ? $this->variations->filter(fn($v) => $v->isVisibleOn($surface))->values()
            : $this->variations;
        $branchId = $this->resolveComposerBranchId($request);
        $choiceAvailability = $branchId !== null
            ? app(ChoiceAvailabilityResolver::class)->snapshotForItem($this->resource, $branchId, (string) ($surface ?: 'kiosk'))
            : ['variations' => [], 'extras' => [], 'addons' => []];
        $filteredVariations = $filteredVariations->each(function ($variation) use ($choiceAvailability) {
            $availability = $choiceAvailability['variations'][(int) $variation->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $variation->setAttribute('is_available', $availability['is_available']);
            $variation->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });
        $filteredExtras = $filteredExtras->each(function ($extra) use ($choiceAvailability) {
            $availability = $choiceAvailability['extras'][(int) $extra->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $extra->setAttribute('is_available', $availability['is_available']);
            $extra->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });
        $addons = $this->addons->each(function ($addon) use ($choiceAvailability) {
            $availability = $choiceAvailability['addons'][(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null];
            $addon->setAttribute('is_available', $availability['is_available']);
            $addon->setAttribute('unavailable_reason', $availability['unavailable_reason']);
        });

        // Kiosk Phase 9.1.1 — allergens[] projection pour safety UI (header wizard).
        // Sécurité FIC (Règlement UE 1169/2011) + EAA 2025 : exposer la liste normalisée
        // afin que le frontend puisse afficher les badges allergènes et alerter le client
        // avant ajout au panier. Snapshot léger (code/name_key/icon/is_trace uniquement).
        $allergens = $this->relationLoaded('allergens')
            ? $this->allergens
            : $this->allergens()->get();

        $allergensPayload = $allergens->map(function ($allergen) {
            return [
                'id'       => (int) $allergen->id,
                'code'     => (string) $allergen->code,
                'name_key' => (string) $allergen->name_key,
                'icon'     => $allergen->icon,
                'is_trace' => (bool) ($allergen->pivot->is_trace ?? false),
            ];
        })->values();

        // Kiosk Phase 9.1.1 — is_available exposé pour détection mid-wizard.
        // Source de vérité : flag global `Item.is_available` (scope POS/kiosk/web).
        // La disponibilité par branche (`ItemBranchAvailability`) est gérée séparément
        // par `KioskMenuService::projectItems` pour les projections kiosk par branche.
        // [F-DETAILS-BRANCH-AVAIL 2026-07-15] Si ItemService::itemDetails a résolu une dispo
        // EFFECTIVE par branche (branch_id fourni), on la préfère (miroir de SimpleItemResource) ;
        // sinon repli sur le flag global. Ferme la cécité mid-wizard à la rupture par branche.
        $effectiveAvailability = $this->effective_is_available ?? null;
        $isAvailable = $effectiveAvailability !== null
            ? (bool) $effectiveAvailability
            : ($this->is_available === null ? true : (bool) $this->is_available);

        // [VIANDE-COUNT 2026-06-24] SSOT serveur du nombre de viandes à choisir
        // = nombre d'attributs « Viande N » distincts ayant des variations actives.
        // KioskWizardComponent.detectViandeCount() (frozen) lit ce champ EN PRIORITÉ
        // (avant l'heuristique nom : « Méga »→4, « Terminator »/« Bol »→null = étape
        // viande absente/fausse). Méga/Terminator=2, Tacos M/Bols=1, Tacos L=2.
        $viandeCount = $filteredVariations
            ->filter(function ($v): bool {
                $n = mb_strtolower((string) optional($v->itemAttribute)->name);
                return str_contains($n, 'viande') || str_contains($n, 'meat');
            })
            ->pluck('item_attribute_id')
            ->unique()
            ->count();

        return [
            "viande_count" => $viandeCount,
            "id" => $this->id,
            "name" => $this->name,
            "category_name" => optional($this->category)->name,
            // [PLAN_12 ARCH-02] Config wizard depuis la catégorie
            "wizard_template" => optional($this->category)->wizard_template ?? 'simple',
            "has_menu" => optional($this->category)->has_menu ?? false,
            "default_menu_kiosk" => optional($this->category)->default_menu_kiosk ?? false,
            "sauce_included_menu" => optional($this->category)->sauce_included_menu ?? false,
            "slug" => $this->slug,
            "flat_price" => AppLibrary::flatAmountFormat($this->price),
            "convert_price" => AppLibrary::convertAmountFormat($this->price),
            "currency_price" => AppLibrary::currencyAmountFormat($this->price),
            "price" => $this->price,
            "item_type" => $this->item_type,
            "status" => $this->status,
            "is_available" => $isAvailable,
            "availability_reason" => $this->availability_reason ?? null,
            "allergens" => $allergensPayload,
            "description" => $this->description === null ? '' : $this->description,
            "caution" => $this->caution === null ? '' : $this->caution,
            "thumb" => $this->thumb,
            "cover" => $this->cover,
            "preview" => $this->preview,
            "variations" => $filteredVariations->groupBy('item_attribute_id'),
            "itemAttributes" => ItemAttributeResource::collection($this->itemAttributeList($filteredVariations)),
            "extras" => ItemExtraResource::collection($filteredExtras->load('item')),
            "addons" => ItemAddonResource::collection($addons->load('addonItem', 'addonItem.variations', 'addonItem.offer', 'item')),
            "composer_profile" => $this->composerProfilePayload(
                (string) ($surface ?: 'kiosk'),
                $branchId,
                // [PERF 2026-07-23 POS-instant-open] Snapshot dispo déjà calculé ci-dessus
                // (même item/branche/surface) → évite un 2ᵉ ChoiceAvailabilityResolver dans
                // la projection composer. Null quand pas de branche (comportement inchangé).
                $branchId !== null ? $choiceAvailability : null,
            ),
            "offer" => SimpleOfferResource::collection(
                $this->offer->filter(function ($offer) use ($price) {
                    if (
                        Carbon::now()->between(
                            $offer->start_date,
                            $offer->end_date
                        ) && $offer->status === Status::ACTIVE
                    ) {
                        $offer->flat_price = AppLibrary::flatAmountFormat($price - ($price / 100 * $offer->amount));
                        $offer->convert_price = AppLibrary::convertAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        $offer->currency_price = AppLibrary::currencyAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        return $offer;
                    }
                })
            )
        ];
    }

    private function itemAttributeList(
        $variations
    ): \Vanilla\Support\Collection|\IlluminateAgnostic\Str\Support\Collection|\IlluminateAgnostic\StrAgnostic\Str\Support\Collection|\IlluminateAgnostic\Collection\Support\Collection|\IlluminateAgnostic\ArrAgnostic\Arr\Support\Collection|\Illuminate\Support\Collection|\IlluminateAgnostic\Arr\Support\Collection {
        $array = [];
        foreach ($variations as $b) {
            if (!isset($array[$b->itemAttribute->id])) {
                $array[$b->itemAttribute->id] = (object) [
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

    private function composerProfilePayload(string $surface, ?int $branchId, ?array $choiceAvailability = null): ?array
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

        return app(ComposerProfileProjection::class)->project($profile, $this->resource, $surface, $branchId, $choiceAvailability);
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
