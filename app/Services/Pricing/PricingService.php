<?php

namespace App\Services\Pricing;

use App\Enums\TaxType;
use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Item;
use App\Services\Composer\ComposerTemplateService;
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
                        // [test-e2e/borne E-001 fix 2026-05-10] NF525 reconciliation —
                        // The kiosk menu-formula upgrade (Salade Royale + Coca-Cola)
                        // sends the parent item's "Menu (Frites + Boisson)" addon
                        // (price 3.00€) but tagged with role='menu_full|menu_frites|
                        // menu_boisson'. Frontend already shows the ratio'd price
                        // (e.g. 3.00€ × 0.4 = 1.20€ for "boisson"). Backend was
                        // charging 0€ because the role was ignored and the wizard
                        // wasn't pushing the menu addon. With the wizard now pushing
                        // the menu addon row + role, we apply the same ratio here
                        // so SSOT matches the customer-facing total.
                        $unitAddonPrice = $this->menuRoleAdjustedAddonPrice(
                            (string) ($addon->role ?? ''),
                            (float) ($dbAddon->addonItem?->price ?? 0)
                        );
                        $addonTotal += $unitAddonPrice * $addonQuantity;
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
            $this->assertComposerSelectionsBelongToPublishedProfile($line, $projected, $item, $surface);
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

    /**
     * [INCIDENT CAISSE 2026-09-03] Le profil contraint ce qu'il DÉCRIT — pas le reste.
     *
     * Signalé par le propriétaire, capture à l'appui : au moment d'encaisser, la caisse
     * refusait avec « le choix #450 n'appartient pas au profil publié ». Ticket construit,
     * montant affiché, monnaie calculée — et paiement impossible.
     *
     * Mesuré sur le catalogue réel : le wizard facture la 2ᵉ sauce 0,50 € via un extra
     * générique « Sauce supplémentaire » qui appartient bien à l'article
     * (LOCK_CAISSE_SAUCE_SEAL du 2026-07-16), et qui porte le groupe `sauce`. Or le profil
     * publié ne décrit d'étapes `extra_group` que pour `crudite` et `supplement` : les
     * sauces GRATUITES passent par une étape `item_attribute`, si bien qu'aucune étape ne
     * couvre l'extra PAYANT du groupe `sauce`.
     *
     * Sur le MÊME article, « Viande supplémentaire » (groupe `supplement`) passait donc et
     * « Sauce supplémentaire » (groupe `sauce`) bloquait la vente. La seule différence était
     * qu'une étape existait pour l'un et pas pour l'autre — un détail de configuration qui
     * décidait si le restaurant pouvait encaisser.
     *
     * Ce que ce garde protège réellement, c'est l'INJECTION : un client ne doit pas pouvoir
     * facturer une option qui n'est pas vendue avec ce produit, ni une option retirée de la
     * carte. Cette frontière-là est conservée intégralement. Ce qui change : quand le profil
     * ne décrit AUCUNE étape pour une famille (ou, pour les extras, pour un groupe donné),
     * on retombe sur la frontière du catalogue — l'option doit appartenir à l'article, être
     * active et visible sur cette surface. Quand le profil décrit la famille, sa liste fait
     * foi, inchangée : une option retirée d'une étape publiée reste refusée
     * (`ProfilePublishMidCartRejectionTest`).
     */
    private function assertComposerSelectionsBelongToPublishedProfile(
        object $line,
        array $projected,
        ?Item $item = null,
        string $surface = 'pos'
    ): void {
        $allowedByPayload = [
            'item_variations' => [],
            'item_extras' => [],
            'item_addons' => [],
        ];
        $famillesDecrites = [];
        $groupesExtrasDecrits = [];

        foreach (($projected['steps'] ?? []) as $step) {
            $sourceType = $step['source_type'] ?? '';
            $payloadKey = match ($sourceType) {
                'item_attribute' => 'item_variations',
                'extra_group' => 'item_extras',
                'addon' => 'item_addons',
                default => null,
            };
            if ($payloadKey === null) {
                continue;
            }
            $famillesDecrites[$payloadKey] = true;
            if ($sourceType === 'extra_group') {
                $ref = mb_strtolower(trim((string) ($step['source_ref'] ?? '')));
                if ($ref === '') {
                    $ref = mb_strtolower(trim((string) ($step['step_key'] ?? '')));
                }
                if ($ref !== '') {
                    $groupesExtrasDecrits[$ref] = true;
                }
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
                if (isset($allowedIds[(string) $id])) {
                    continue;
                }
                if ($this->choixCouvertParLeCatalogue($payloadKey, (int) $id, $item, $surface, $famillesDecrites, $groupesExtrasDecrits)) {
                    continue;
                }

                throw new \InvalidArgumentException(
                    "Composition : le choix #{$id} n'appartient pas au profil publié.",
                    422
                );
            }
        }
    }

    /**
     * Le repli : l'option appartient-elle à l'article vendu, est-elle active et visible, et
     * le profil publié se tait-il à son sujet ? Les trois conditions sont nécessaires.
     */
    private function choixCouvertParLeCatalogue(
        string $payloadKey,
        int $id,
        ?Item $item,
        string $surface,
        array $famillesDecrites,
        array $groupesExtrasDecrits
    ): bool {
        if ($item === null) {
            return false;
        }

        if ($payloadKey === 'item_extras') {
            $extra = $item->extras->firstWhere('id', $id);
            if ($extra === null || (int) $extra->status !== Status::ACTIVE || ! $extra->isVisibleOn($surface)) {
                return false;
            }
            $groupe = mb_strtolower(trim((string) ($extra->group_label ?? '')));
            $groupe = $groupe === '' ? 'default' : $groupe;

            // Le groupe est-il décrit par une étape publiée ? Si oui, la liste de l'étape
            // fait foi et l'absence de cet extra est une décision, pas un trou.
            foreach (array_keys($groupesExtrasDecrits) as $ref) {
                if ($this->groupeExtraCorrespond($groupe, (string) $ref)) {
                    return false;
                }
            }

            return true;
        }

        // Variations et produits ajoutés : repli uniquement si le profil ne décrit AUCUNE
        // étape de cette famille.
        if (isset($famillesDecrites[$payloadKey])) {
            return false;
        }

        if ($payloadKey === 'item_variations') {
            $variation = $item->variations->firstWhere('id', $id);

            return $variation !== null && (int) $variation->status === Status::ACTIVE;
        }

        if ($payloadKey === 'item_addons') {
            return $item->addons->contains(fn ($addon): bool => (int) $addon->id === $id
                || (int) ($addon->addon_item_id ?? 0) === $id);
        }

        return false;
    }

    /** Même correspondance que la projection : égalité, `default`, ou alias déclaré. */
    private function groupeExtraCorrespond(string $groupe, string $ref): bool
    {
        if ($ref === '') {
            return false;
        }
        if ($groupe === $ref) {
            return true;
        }
        if ($ref === 'default' && $groupe === 'default') {
            return true;
        }

        return in_array($groupe, ComposerTemplateService::EXTRA_GROUP_ALIASES[$ref] ?? [], true);
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

    /**
     * [test-e2e/borne E-001 fix 2026-05-10] NF525 SSOT reconciliation —
     * kiosk menu-formula ratio applied server-side.
     *
     * Background:
     *  - Frontend `kioskPricing.js` displays the menu-formula upgrade using
     *    `config('kiosk.menu_pricing')` ratios (full=1.0, fries=0.6, drink=0.4)
     *    applied to the parent "Menu (Frites + Boisson)" addon item price.
     *  - Previously this ratio existed ONLY on the frontend → backend charged
     *    either 0€ (addon not in payload) or full addonItem.price (3.00€),
     *    NEVER the ratio'd intermediate (1.20€ / 1.80€). Per-order leak of
     *    up to 1.20€ on every Salade-with-Coca formule. Sealed under-priced
     *    composition_snapshot ⇒ NF525 fiscal SSOT breach.
     *
     * Contract:
     *  - The kiosk wizard now pushes the parent "menu" addon row when
     *    menuChoice ∈ ('full','frites','boisson') with `role` set to
     *    'menu_full' / 'menu_frites' / 'menu_boisson'.
     *  - This helper recognizes those roles and returns
     *    addonItem.price × matching ratio.
     *  - Roles outside the menu_* family fall through to the unchanged
     *    addonItem.price (legacy behavior preserved).
     *
     * Single source of truth: `config('kiosk.menu_pricing')` — the same
     * config consumed by `kioskPricing.js` via `window.foodkingConfig.kioskMenuPricing`
     * (master.blade.php exposes it). Frontend and backend cannot drift.
     */
    public function menuRoleAdjustedAddonPrice(string $role, float $fullPrice): float
    {
        $role = strtolower(trim($role));
        if ($role === '' || ! str_starts_with($role, 'menu_')) {
            return $fullPrice;
        }

        $ratios = (array) config('kiosk.menu_pricing', []);
        $ratio = match ($role) {
            'menu_full'    => (float) ($ratios['full_ratio']  ?? 1.0),
            'menu_frites'  => (float) ($ratios['fries_ratio'] ?? 0.6),
            'menu_boisson' => (float) ($ratios['drink_ratio'] ?? 0.4),
            default        => 1.0, // unknown menu_* role — treat as full price
        };

        if (! is_finite($ratio) || $ratio < 0.0) {
            $ratio = 1.0;
        }

        return round($fullPrice * $ratio, 2);
    }
}
