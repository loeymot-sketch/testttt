<?php

namespace App\Services\Pricing;

use App\Enums\TaxType;
use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\Tax;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\CouponService;
use App\Services\Menu\AvailabilityService;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Illuminate\Support\Collection;

final class PricingService
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator = new TaxCalculator,
        private readonly DiscountCalculator $discountCalculator = new DiscountCalculator,
        private readonly ?AvailabilityService $availabilityService = null,
        private readonly CompositionSnapshotBuilder $snapshotBuilder = new CompositionSnapshotBuilder,
        private readonly ?ComposerProfileProjection $composerProfileProjection = null,
        private readonly ?ChoiceAvailabilityResolver $choiceAvailabilityResolver = null,
    ) {}

    /**
     * Server-side cart pricing for order creation (lines + tax + coupon/manual).
     * Kiosk loyalty redemption stays in FrontendOrderService (DB lock + ledger).
     */
    public function calculateOrder(
        PricingRequest $req,
        CouponService $couponService,
    ): PricingResult {
        $requestItems = $req->requestItems;
        if (! is_array($requestItems)) {
            $requestItems = [];
        }

        $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->values()->all();

        if ($req->branchId > 0 && $requestedItemIds !== []) {
            $availability = $this->availabilityService ?? app(AvailabilityService::class);
            // Preview (`orderId === 0`) : lecture seule. Commande réelle : lock sous transaction.
            $availability->assertItemsOrderableForBranch(
                $req->branchId,
                $requestedItemIds,
                $req->orderId > 0
            );
        }

        $dbItems = Item::query()
            ->select('id', 'price', 'tax_id')
            ->whereIn('id', $requestedItemIds)
            ->get()
            ->keyBy('id');

        $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');

        $variationIds = collect($requestItems)
            ->pluck('item_variations')
            ->flatten(1)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $extraIds = collect($requestItems)
            ->pluck('item_extras')
            ->flatten(1)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $addonIds = collect($requestItems)
            ->pluck('item_addons')
            ->flatten(1)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $dbVariations = $variationIds !== []
            ? ItemVariation::query()->whereIn('id', $variationIds)->get()->keyBy('id')
            : collect();
        $dbExtras = $extraIds !== []
            ? ItemExtra::query()->whereIn('id', $extraIds)->get()->keyBy('id')
            : collect();
        $dbAddons = $addonIds !== []
            ? ItemAddon::query()->with('addonItem')->whereIn('id', $addonIds)->get()->keyBy('id')
            : collect();

        if ($req->branchId > 0 && $dbAddons->isNotEmpty()) {
            $availability = $this->availabilityService ?? app(AvailabilityService::class);
            $availability->assertItemsOrderableForBranch(
                $req->branchId,
                $dbAddons->pluck('addon_item_id')->filter()->unique()->values()->all(),
                $req->orderId > 0
            );
        }

        $this->assertOptionsOrderable($req, $variationIds, $extraIds, $addonIds, $dbVariations, $dbExtras, $dbAddons);
        $this->assertComposerStepConstraints($req);

        // [T07 SSOT] Preload all involved attributes once for the snapshot builder
        // (avoids N+1 inside the per-item loop).
        $attributeIds = $dbVariations->pluck('item_attribute_id')->filter()->unique()->values()->all();
        $dbAttributes = $attributeIds !== []
            ? ItemAttribute::query()->whereIn('id', $attributeIds)->get()->keyBy('id')
            : collect();

        $itemsArray = [];
        $lines = [];
        $realSubtotal = 0.0;
        $totalTax = 0.0;
        $i = 0;

        if ($requestItems !== []) {
            foreach ($requestItems as $item) {
                $dbItem = $dbItems[$item->item_id] ?? null;
                if (! $dbItem) {
                    throw new \InvalidArgumentException(
                        "Item ID {$item->item_id} introuvable. Commande rejetée.",
                        422
                    );
                }
                $itemPrice = (float) $dbItem->price;

                // [T05] Multi-quantity support: variations carry an optional `quantity`
                // field (default 1, backward-compat with legacy [{id}] payloads).
                $variationTotal = 0.0;
                if (isset($item->item_variations) && is_array($item->item_variations)) {
                    foreach ($item->item_variations as $variation) {
                        $varId = $variation->id ?? null;
                        if (! $varId) {
                            continue;
                        }
                        $dbVar = $dbVariations[$varId] ?? null;
                        if (! $dbVar) {
                            throw new \InvalidArgumentException(
                                "Variation ID {$varId} introuvable pour l'article {$item->item_id}.",
                                422
                            );
                        }
                        if ($req->enforceCrossItemGuards && (int) $dbVar->item_id !== (int) $item->item_id) {
                            throw new \InvalidArgumentException(
                                "Variation ID {$varId} n'appartient pas à l'article {$item->item_id}.",
                                422
                            );
                        }
                        $varQuantity = max(1, (int) ($variation->quantity ?? 1));
                        $variationTotal += (float) $dbVar->price * $varQuantity;
                    }
                }

                // [T05] Constraints validation per attribute (min/max/allow_repeat from item_attributes T01).
                $this->assertVariationConstraints($item, $dbVariations);

                // [T05] Multi-quantity support: extras carry an optional `quantity`
                // field (default 1, backward-compat with legacy [{id}] payloads).
                $extraTotal = 0.0;
                if (isset($item->item_extras) && is_array($item->item_extras)) {
                    foreach ($item->item_extras as $extra) {
                        $extraId = $extra->id ?? null;
                        if (! $extraId) {
                            continue;
                        }
                        $dbExt = $dbExtras[$extraId] ?? null;
                        if (! $dbExt) {
                            throw new \InvalidArgumentException(
                                "Extra ID {$extraId} introuvable pour l'article {$item->item_id}.",
                                422
                            );
                        }
                        if ($req->enforceCrossItemGuards && (int) $dbExt->item_id !== (int) $item->item_id) {
                            throw new \InvalidArgumentException(
                                "Extra ID {$extraId} n'appartient pas à l'article {$item->item_id}.",
                                422
                            );
                        }
                        $extraQuantity = max(1, (int) ($extra->quantity ?? 1));
                        $extraTotal += (float) $dbExt->price * $extraQuantity;
                    }
                }

                $addonTotal = 0.0;
                if (isset($item->item_addons) && is_array($item->item_addons)) {
                    foreach ($item->item_addons as $addon) {
                        $addonId = $addon->id ?? null;
                        if (! $addonId) {
                            continue;
                        }
                        $dbAddon = $dbAddons[$addonId] ?? null;
                        if (! $dbAddon) {
                            throw new \InvalidArgumentException(
                                "Addon ID {$addonId} introuvable pour l'article {$item->item_id}.",
                                422
                            );
                        }
                        if ($req->enforceCrossItemGuards && (int) $dbAddon->item_id !== (int) $item->item_id) {
                            throw new \InvalidArgumentException(
                                "Addon ID {$addonId} n'appartient pas à l'article {$item->item_id}.",
                                422
                            );
                        }
                        $addonQuantity = max(1, (int) ($addon->quantity ?? 1));
                        $addonTotal += (float) ($dbAddon->addonItem?->price ?? 0) * $addonQuantity;
                    }
                }

                $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                $unitSum = $itemPrice + $variationTotal + $extraTotal + $addonTotal;
                $verifiedTotalPrice = $unitSum * $verifiedQuantity;
                if ($req->roundLineTotals) {
                    $verifiedTotalPrice = round($verifiedTotalPrice, 2);
                }

                $realSubtotal += $verifiedTotalPrice;

                $taxId = (int) ($dbItem->tax_id ?? 0);
                $taxObj = $taxes[$taxId] ?? null;
                $taxName = $taxObj?->name;
                $taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;
                $taxType = $taxObj ? (int) $taxObj->type : TaxType::FIXED;

                // [TTC-MODE] When `pricing.tax_inclusive_prices=true`, item.price is
                // TTC: extract tax from the line total instead of adding it on top.
                // Otherwise legacy HT behavior (tax added on top).
                if ((bool) config('pricing.tax_inclusive_prices', false)) {
                    $taxPrice = $this->taxCalculator->lineTaxAmountFromTTC(
                        $verifiedTotalPrice,
                        $taxType,
                        $taxRate,
                        $req->roundLineTax
                    );
                } else {
                    $taxPrice = $this->taxCalculator->lineTaxAmount(
                        $verifiedTotalPrice,
                        $taxType,
                        $taxRate,
                        $req->roundLineTax
                    );
                }

                // [T07 SSOT] Build the immutable composition_snapshot at order creation
                // time. NF525 contract: this snapshot must NEVER be re-written and is
                // the source of truth for reprint / fiscal export. mass-insert below
                // bypasses the Eloquent 'array' cast → json_encode here is mandatory.
                $compositionSnapshot = $this->snapshotBuilder->build(
                    $item,
                    $dbVariations,
                    $dbExtras,
                    $dbAttributes,
                    $dbAddons,
                );

                $itemsArray[$i] = [
                    'order_id' => $req->orderId,
                    'branch_id' => $req->branchId,
                    'item_id' => $item->item_id,
                    'quantity' => $verifiedQuantity,
                    'discount' => 0,
                    'tax_name' => $taxName,
                    'tax_rate' => $taxRate,
                    'tax_type' => $taxType,
                    'tax_amount' => $taxPrice,
                    'price' => $itemPrice,
                    'item_variations' => json_encode($item->item_variations ?? []),
                    'item_extras' => json_encode($item->item_extras ?? []),
                    'composition_snapshot' => json_encode($compositionSnapshot),
                    'instruction' => $item->instruction ?? null,
                    'item_variation_total' => $variationTotal,
                    'item_extra_total' => $extraTotal,
                    'total_price' => $verifiedTotalPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $lines[] = new PricingLineResult(
                    (int) $item->item_id,
                    $verifiedQuantity,
                    $itemPrice,
                    $variationTotal,
                    $extraTotal,
                    $verifiedTotalPrice,
                    $taxName,
                    $taxRate,
                    $taxType,
                    $taxPrice,
                    $itemsArray[$i]['item_variations'],
                    $itemsArray[$i]['item_extras'],
                    $itemsArray[$i]['instruction'],
                    $addonTotal,
                );

                $totalTax += $taxPrice;
                $i++;
            }
        }

        if ($req->roundOrderTotalTax) {
            $totalTax = round($totalTax, 2);
        }

        $subtotalForDiscount = $realSubtotal;
        if ($req->roundSubtotal) {
            $subtotalForDiscount = round($realSubtotal, 2);
        }

        $calculatedDiscount = 0.0;
        if ($req->couponId > 0) {
            $calculatedDiscount = $this->discountCalculator->couponDiscount(
                $couponService,
                $req->couponId,
                (float) $subtotalForDiscount,
                $req->couponCustomerUserId
            );
        } elseif ($req->manualDiscountRequest > 0.0 && in_array($req->context, ['pos', 'table'], true)) {
            $calculatedDiscount = $this->discountCalculator->manualDiscount(
                $req->manualDiscountRequest,
                (float) $subtotalForDiscount
            );
        }

        $delivery = $req->deliveryCharge;
        // [TTC-MODE] In tax-inclusive mode, `$realSubtotal` is already the sum of TTC line
        // totals (tax is INSIDE each line). Adding `$totalTax` again would double-count.
        // Legacy HT mode keeps the original `subtotal_HT + tax + delivery - discount` formula.
        if ((bool) config('pricing.tax_inclusive_prices', false)) {
            $rawTotal = $realSubtotal + $delivery - $calculatedDiscount;
        } else {
            $rawTotal = $realSubtotal + $totalTax + $delivery - $calculatedDiscount;
        }
        $finalTotal = $req->roundFinalOrderTotal ? round(max(0.0, $rawTotal), 2) : max(0.0, $rawTotal);

        $displaySubtotal = $req->roundSubtotal ? round($realSubtotal, 2) : $realSubtotal;

        return new PricingResult(
            $itemsArray,
            $lines,
            $realSubtotal,
            $displaySubtotal,
            $totalTax,
            $calculatedDiscount,
            $delivery,
            $finalTotal,
            [],
        );
    }

    /**
     * [T05] Validate per-attribute constraints (min_select / max_select / allow_repeat)
     * defined on `item_attributes` table (T01 columns).
     *
     * Defaults preserve legacy single-select behaviour:
     *   - min_select=0 (optional)
     *   - max_select=1 (single select)
     *   - allow_repeat=false (no duplicate variation_id within same attribute)
     *
     * Throws \InvalidArgumentException 422 with explicit message per violation.
     */
    private function assertVariationConstraints(object $item, $dbVariations): void
    {
        if (! isset($item->item_variations) || ! is_array($item->item_variations)) {
            return;
        }

        // Group payload variations by attribute_id (resolved from DB).
        $byAttribute = [];      // [attrId => total_qty]
        $varOccurByAttr = [];   // [attrId => [varId => total_qty_for_that_var]]

        foreach ($item->item_variations as $variation) {
            $varId = $variation->id ?? null;
            if (! $varId) {
                continue;
            }
            $dbVar = $dbVariations[$varId] ?? null;
            if (! $dbVar) {
                continue;
            }
            $attrId = (int) $dbVar->item_attribute_id;
            $qty = max(1, (int) ($variation->quantity ?? 1));
            $byAttribute[$attrId] = ($byAttribute[$attrId] ?? 0) + $qty;
            $varOccurByAttr[$attrId][$varId] = ($varOccurByAttr[$attrId][$varId] ?? 0) + $qty;
        }

        if ($byAttribute === []) {
            return;
        }

        $attrs = \App\Models\ItemAttribute::query()
            ->whereIn('id', array_keys($byAttribute))
            ->get()
            ->keyBy('id');

        foreach ($byAttribute as $attrId => $totalQty) {
            $attr = $attrs[$attrId] ?? null;
            if (! $attr) {
                continue;
            }

            $min = (int) ($attr->min_select ?? 0);
            $max = (int) ($attr->max_select ?? 1);
            $allowRepeat = (bool) ($attr->allow_repeat ?? false);

            if ($max > 0 && $totalQty > $max) {
                throw new \InvalidArgumentException(
                    "Attribut {$attr->name} : maximum {$max} sélection(s), reçu {$totalQty}.",
                    422
                );
            }
            if ($min > 0 && $totalQty < $min) {
                throw new \InvalidArgumentException(
                    "Attribut {$attr->name} : minimum {$min} sélection(s) requise(s), reçu {$totalQty}.",
                    422
                );
            }
            if (! $allowRepeat) {
                foreach (($varOccurByAttr[$attrId] ?? []) as $varId => $qty) {
                    if ($qty > 1) {
                        throw new \InvalidArgumentException(
                            "Attribut {$attr->name} : la variation #{$varId} ne peut être sélectionnée qu'une seule fois (allow_repeat=false).",
                            422
                        );
                    }
                }
            }
        }
    }

    private function assertOptionsOrderable(
        PricingRequest $req,
        array $variationIds,
        array $extraIds,
        array $addonIds,
        $dbVariations,
        $dbExtras,
        $dbAddons
    ): void {
        $surface = in_array($req->context, ['pos', 'kiosk', 'web'], true)
            ? $req->context
            : 'web';

        foreach ($variationIds as $variationId) {
            $variation = $dbVariations[$variationId] ?? null;
            if (! $variation) {
                throw new \InvalidArgumentException(
                    "Variation ID {$variationId} introuvable. Commande rejetée.",
                    422
                );
            }
            if ((int) $variation->status !== Status::ACTIVE) {
                throw new \InvalidArgumentException(
                    "Variation ID {$variationId} inactive dans le catalogue. Commande rejetée.",
                    422
                );
            }
            if (! $variation->isVisibleOn($surface)) {
                throw new \InvalidArgumentException(
                    "Variation ID {$variationId} indisponible sur {$surface}. Commande rejetée.",
                    422
                );
            }
        }

        foreach ($extraIds as $extraId) {
            $extra = $dbExtras[$extraId] ?? null;
            if (! $extra) {
                throw new \InvalidArgumentException(
                    "Supplément ID {$extraId} introuvable. Commande rejetée.",
                    422
                );
            }
            if ((int) $extra->status !== Status::ACTIVE) {
                throw new \InvalidArgumentException(
                    "Supplément ID {$extraId} inactif dans le catalogue. Commande rejetée.",
                    422
                );
            }
            if (! $extra->isVisibleOn($surface)) {
                throw new \InvalidArgumentException(
                    "Supplément ID {$extraId} indisponible sur {$surface}. Commande rejetée.",
                    422
                );
            }
        }

        foreach ($addonIds as $addonId) {
            $addon = $dbAddons[$addonId] ?? null;
            if (! $addon) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addonId} introuvable. Commande rejetée.",
                    422
                );
            }

            $addonItem = $addon->addonItem;
            if (! $addonItem) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addonId} sans article associé. Commande rejetée.",
                    422
                );
            }
            if ((int) $addonItem->status !== Status::ACTIVE) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addonId} inactif dans le catalogue. Commande rejetée.",
                    422
                );
            }
            if (! (bool) ($addonItem->is_available ?? true)) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addonId} indisponible dans le catalogue. Commande rejetée.",
                    422
                );
            }
            if (! $addonItem->isVisibleOn($surface)) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addonId} indisponible sur {$surface}. Commande rejetée.",
                    422
                );
            }
        }

        if ($req->branchId > 0) {
            $this->choiceAvailabilityResolver()->assertSelectionsOrderable(
                $req->branchId,
                $dbVariations->values(),
                $dbExtras->values(),
                $dbAddons->values(),
                $surface,
                $req->orderId > 0
            );
        }
    }

    private function assertComposerStepConstraints(PricingRequest $req): void
    {
        $itemIds = collect($req->requestItems)->pluck('item_id')->filter()->unique()->values();
        if ($itemIds->isEmpty()) {
            return;
        }

        $profiles = ItemWizardProfile::query()
            ->with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
            ->whereIn('item_id', $itemIds->all())
            ->where('is_published', true)
            ->where(function ($query) use ($req): void {
                $query->whereNull('branch_id_scope')
                    ->when($req->branchId > 0, fn ($q) => $q->orWhere('branch_id_scope', $req->branchId));
            })
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $profiles): ItemWizardProfile => $profiles
                ->sort(fn (ItemWizardProfile $a, ItemWizardProfile $b): int => $this->compareComposerProfiles($a, $b))
                ->first());

        if ($profiles->isEmpty()) {
            return;
        }

        $items = Item::query()
            ->with([
                'variations.itemAttribute',
                'extras',
                'addons.addonItem',
            ])
            ->whereIn('id', $profiles->keys()->all())
            ->get()
            ->keyBy('id');

        $surface = in_array($req->context, ['pos', 'kiosk', 'web'], true) ? $req->context : 'pos';

        foreach ($req->requestItems as $line) {
            $itemId = (int) ($this->payloadValue($line, 'item_id') ?? 0);
            $profile = $profiles->get($itemId);
            $item = $items->get($itemId);
            if (! $profile || ! $item) {
                continue;
            }

            $projected = $this->composerProfileProjection()->project($profile, $item, $surface);
            $this->assertComposerSelectionsBelongToPublishedProfile($line, $projected);
            foreach (($projected['steps'] ?? []) as $step) {
                if (! in_array($step['source_type'] ?? '', ['item_attribute', 'extra_group', 'addon'], true)) {
                    throw new \InvalidArgumentException(
                        'Composition : type de source non supporté dans le profil publié.',
                        422
                    );
                }

                $counts = $this->composerSelectedCountsForStep($line, $step);
                $total = array_sum($counts);
                $min = (int) ($step['min_select'] ?? 0);
                $max = (int) ($step['max_select'] ?? 0);
                $label = (string) ($step['label'] ?? $step['step_key'] ?? 'Composer');

                if ($total < $min) {
                    throw new \InvalidArgumentException(
                        "Composition {$label} : minimum {$min} sélection(s) requise(s), reçu {$total}.",
                        422
                    );
                }

                if ($max > 0 && $total > $max) {
                    throw new \InvalidArgumentException(
                        "Composition {$label} : maximum {$max} sélection(s), reçu {$total}.",
                        422
                    );
                }

                if (! (bool) ($step['allow_repeat'] ?? false)) {
                    foreach ($counts as $choiceId => $count) {
                        if ($count > 1) {
                            throw new \InvalidArgumentException(
                                "Composition {$label} : le choix #{$choiceId} ne peut être sélectionné qu'une seule fois.",
                                422
                            );
                        }
                    }
                }

                $choicesById = collect($step['choices'] ?? [])->keyBy(fn (array $choice): string => (string) ($choice['id'] ?? ''));
                foreach (array_keys($counts) as $choiceId) {
                    $choice = $choicesById->get((string) $choiceId);
                    if (is_array($choice) && array_key_exists('is_available', $choice) && ! (bool) $choice['is_available']) {
                        $reason = $choice['unavailable_reason'] ?? 'stock_rupture';
                        throw new \InvalidArgumentException(
                            "Composition {$label} : le choix #{$choiceId} est indisponible ({$reason}).",
                            422
                        );
                    }
                }

            }
        }
    }

    private function assertComposerSelectionsBelongToPublishedProfile(object $line, array $projected): void
    {
        $allowedByPayload = [
            'item_variations' => [],
            'item_extras' => [],
            'item_addons' => [],
        ];

        foreach (($projected['steps'] ?? []) as $step) {
            $payloadKey = match ($step['source_type'] ?? '') {
                'item_attribute' => 'item_variations',
                'extra_group' => 'item_extras',
                'addon' => 'item_addons',
                default => null,
            };
            if ($payloadKey === null) {
                continue;
            }
            foreach (($step['choices'] ?? []) as $choice) {
                $id = $choice['id'] ?? null;
                if ($id !== null) {
                    $allowedByPayload[$payloadKey][(string) $id] = true;
                }
            }
        }

        foreach ($allowedByPayload as $payloadKey => $allowedIds) {
            foreach ((array) ($this->payloadValue($line, $payloadKey) ?? []) as $selected) {
                $id = $this->payloadValue($selected, 'id');
                if ($id === null) {
                    continue;
                }
                if (! isset($allowedIds[(string) $id])) {
                    throw new \InvalidArgumentException(
                        "Composition : le choix #{$id} n'appartient pas au profil publié.",
                        422
                    );
                }
            }
        }
    }

    private function composerSelectedCountsForStep(object $line, array $step): array
    {
        $payloadKey = match ($step['source_type'] ?? '') {
            'item_attribute' => 'item_variations',
            'extra_group' => 'item_extras',
            'addon' => 'item_addons',
            default => null,
        };
        if ($payloadKey === null) {
            return [];
        }

        $choiceIds = collect($step['choices'] ?? [])
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->flip();

        if ($choiceIds->isEmpty()) {
            return [];
        }

        $counts = [];
        foreach ((array) ($this->payloadValue($line, $payloadKey) ?? []) as $selected) {
            $id = $this->payloadValue($selected, 'id');
            if ($id === null || ! $choiceIds->has((string) $id)) {
                continue;
            }
            $quantity = max(1, (int) ($this->payloadValue($selected, 'quantity') ?? 1));
            $counts[(string) $id] = ($counts[(string) $id] ?? 0) + $quantity;
        }

        return $counts;
    }

    private function payloadValue($payload, string $key)
    {
        if (is_array($payload)) {
            return $payload[$key] ?? null;
        }

        if (is_object($payload)) {
            return $payload->{$key} ?? null;
        }

        return null;
    }

    private function compareComposerProfiles(ItemWizardProfile $a, ItemWizardProfile $b): int
    {
        $aScope = $a->branch_id_scope === null ? 0 : 1;
        $bScope = $b->branch_id_scope === null ? 0 : 1;

        return [$bScope, (int) $b->version, (int) $b->id] <=> [$aScope, (int) $a->version, (int) $a->id];
    }

    private function composerProfileProjection(): ComposerProfileProjection
    {
        return $this->composerProfileProjection ?? app(ComposerProfileProjection::class);
    }

    private function choiceAvailabilityResolver(): ChoiceAvailabilityResolver
    {
        return $this->choiceAvailabilityResolver ?? app(ChoiceAvailabilityResolver::class);
    }
}
