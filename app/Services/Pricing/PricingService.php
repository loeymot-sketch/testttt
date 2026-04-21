<?php

namespace App\Services\Pricing;

use App\Enums\TaxType;
use App\Libraries\AppLibrary;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Menu\AvailabilityService;

final class PricingService
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator = new TaxCalculator,
        private readonly DiscountCalculator $discountCalculator = new DiscountCalculator,
        private readonly ?AvailabilityService $availabilityService = null,
        private readonly CompositionSnapshotBuilder $snapshotBuilder = new CompositionSnapshotBuilder,
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

        $dbVariations = $variationIds !== []
            ? ItemVariation::query()->whereIn('id', $variationIds)->get()->keyBy('id')
            : collect();
        $dbExtras = $extraIds !== []
            ? ItemExtra::query()->whereIn('id', $extraIds)->get()->keyBy('id')
            : collect();

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

                $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                $unitSum = $itemPrice + $variationTotal + $extraTotal;
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

                $taxPrice = $this->taxCalculator->lineTaxAmount(
                    $verifiedTotalPrice,
                    $taxType,
                    $taxRate,
                    $req->roundLineTax
                );

                // [T07 SSOT] Build the immutable composition_snapshot at order creation
                // time. NF525 contract: this snapshot must NEVER be re-written and is
                // the source of truth for reprint / fiscal export. mass-insert below
                // bypasses the Eloquent 'array' cast → json_encode here is mandatory.
                $compositionSnapshot = $this->snapshotBuilder->build(
                    $item,
                    $dbVariations,
                    $dbExtras,
                    $dbAttributes,
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
        $rawTotal = $realSubtotal + $totalTax + $delivery - $calculatedDiscount;
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
}
