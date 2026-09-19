<?php

namespace App\Services\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Stock\ChoiceAvailabilityResolver;

final class ComposerProfileProjection
{
    public function __construct(
        private readonly ?ChoiceAvailabilityResolver $choiceAvailabilityResolver = null,
    ) {
    }

    public function project(?ItemWizardProfile $profile, Item $item, string $surface, ?int $branchId = null, ?array $choiceAvailability = null): ?array
    {
        if (! $profile) {
            return null;
        }

        // [PERF 2026-07-23 POS-instant-open] Réutilise le snapshot de disponibilité déjà
        // calculé par le resource appelant (NormalItemResource) pour le MÊME triplet
        // (item, branche, surface), au lieu de relancer un appel identique à
        // ChoiceAvailabilityResolver::snapshotForItem. Fallback : si l'appelant ne fournit
        // rien (MenuProjectionService / KioskMenuService / PricingService / tests), on
        // calcule comme avant → comportement et payload strictement inchangés.
        $choiceAvailability = $choiceAvailability
            ?? ($branchId !== null && $branchId > 0
                ? $this->choiceAvailabilityResolver()->snapshotForItem($item, $branchId, $surface)
                : null);

        $steps = $profile->steps
            ->filter(fn (ItemWizardStep $step): bool => (bool) $step->is_active && $this->stepVisibleOn($step, $surface))
            ->values()
            ->map(fn (ItemWizardStep $step): array => [
                'id' => (int) $step->id,
                'step_key' => (string) $step->step_key,
                'label' => (string) $step->label,
                'source_type' => (string) $step->source_type,
                'source_ref' => (string) $step->source_ref,
                'min_select' => (int) $step->min_select,
                'max_select' => (int) $step->max_select,
                'allow_repeat' => (bool) $step->allow_repeat,
                'visible_on' => $step->visible_on,
                'stockable_choices' => (bool) $step->stockable_choices,
                'position' => (int) $step->position,
                'is_active' => (bool) $step->is_active,
                'addon_role' => $step->addon_role,
                'choices' => $this->choices($step, $item, $surface, $choiceAvailability),
            ])
            // [INCIDENT COMPOSEUR 2026-09-03/04] Une étape sans le moindre choix est une
            // IMPASSE : l'écran affiche un titre et aucune tuile, et si l'étape est
            // obligatoire le client ne peut plus avancer. Deux incidents réels en 24 h :
            // les 45 viandes de Cayenne/Suprême/Classique éteintes d'un coup en laissant
            // « Viande 1 » obligatoire (trois produits phares invendables), puis les six
            // burgers affichant « Choisis ta viande » avec ZÉRO tuile après détachement
            // de leurs variations alors que le profil publié gardait l'étape active.
            // Les trois types de source construisent une vraie liste : une liste vide ne
            // propose rien à cliquer, quel que soit le type. On ne projette donc pas.
            // Une étape dont les choix existent mais sont en RUPTURE reste projetée :
            // « indisponible » s'affiche et s'explique — ce n'est pas une impasse.
            // ⚠️ Restreint aux types de source CONNUS. Un type non supporté produit lui
            // aussi une liste vide, mais il doit continuer d'atteindre PricingService, qui
            // le REFUSE (« type de source non supporté »). Le filtrer ici l'avalerait en
            // silence — exactement le genre d'escamotage que la discipline NF525 interdit.
            ->reject(fn (array $step): bool => $step['choices'] === []
                && in_array($step['source_type'], ['item_attribute', 'extra_group', 'addon'], true))
            ->values()
            ->all();

        return [
            'id' => (int) $profile->id,
            'item_id' => (int) $profile->item_id,
            'template' => (string) $profile->template,
            'version' => (int) $profile->version,
            'is_published' => (bool) $profile->is_published,
            'published_at' => optional($profile->published_at)->toIso8601String(),
            'branch_id_scope' => $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            'steps' => $steps,
        ];
    }

    private function stepVisibleOn(ItemWizardStep $step, string $surface): bool
    {
        $visibleOn = $step->visible_on;
        return empty($visibleOn) || in_array($surface, (array) $visibleOn, true);
    }

    /**
     * @param  array{
     *   variations: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   extras: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   addons: array<int, array{is_available: bool, unavailable_reason: ?string}>
     * }|null  $choiceAvailability
     */
    private function choices(ItemWizardStep $step, Item $item, string $surface, ?array $choiceAvailability): array
    {
        $sourceType = (string) $step->source_type;
        $sourceRef = mb_strtolower(trim((string) $step->source_ref));
        $usesStockableChoices = (bool) $step->stockable_choices;

        if ($sourceType === 'item_attribute') {
            return $item->variations
                ->filter(fn ($variation): bool => (int) $variation->status === Status::ACTIVE
                    && $variation->isVisibleOn($surface)
                    && $this->matchesAttributeRef($variation->itemAttribute, $sourceRef))
                ->map(function ($variation) use ($choiceAvailability): array {
                    $availability = $choiceAvailability !== null
                        ? ($choiceAvailability['variations'][(int) $variation->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $variation->id,
                        'name' => (string) $variation->name,
                        'source_type' => 'variation',
                        'item_attribute_id' => $variation->item_attribute_id !== null ? (int) $variation->item_attribute_id : null,
                        'status' => (int) $variation->status,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        if ($sourceType === 'extra_group') {
            if ($sourceRef === '') {
                // Step « Suppléments » sans source_ref : on lie au step_key
                // (aliases supplement), pas à TOUS les extras.
                $sourceRef = mb_strtolower(trim((string) $step->step_key));
            }

            return $item->extras
                ->filter(fn ($extra): bool => (int) $extra->status === Status::ACTIVE
                    && $extra->isVisibleOn($surface)
                    && $this->matchesExtraGroup($extra, $sourceRef))
                ->map(function ($extra) use ($choiceAvailability): array {
                    $availability = $choiceAvailability !== null
                        ? ($choiceAvailability['extras'][(int) $extra->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $extra->id,
                        'name' => (string) $extra->name,
                        'source_type' => 'extra',
                        'group_label' => $extra->group_label,
                        'status' => (int) $extra->status,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        if ($sourceType === 'addon') {
            $role = $step->addon_role;
            if ($role === null && in_array($sourceRef, ItemAddon::ROLES, true)) {
                $role = $sourceRef;
            }
            $wantedAddonId = ($role === null && $sourceRef !== '' && ctype_digit($sourceRef))
                ? (int) $sourceRef
                : null;
            if ($role === null && $wantedAddonId === null) {
                // Avant : source_ref vide = toutes les boissons ET les frites.
                return [];
            }

            return $item->addons
                ->filter(function ($addon) use ($role, $wantedAddonId, $surface): bool {
                    if ($wantedAddonId !== null && (int) $addon->id !== $wantedAddonId) {
                        return false;
                    }
                    if ($role !== null && $addon->role !== $role) {
                        return false;
                    }

                    $addonItem = $addon->addonItem;
                    if (! $addonItem) {
                        return false;
                    }

                    return (int) $addonItem->status === Status::ACTIVE
                        && (bool) ($addonItem->is_available ?? true)
                        && $addonItem->isVisibleOn($surface);
                })
                ->map(function ($addon) use ($choiceAvailability, $usesStockableChoices): array {
                    $availability = $usesStockableChoices
                        ? ($choiceAvailability['addons'][(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null])
                        : ['is_available' => true, 'unavailable_reason' => null];

                    return [
                        'id' => (int) $addon->id,
                        'name' => (string) ($addon->addonItem?->name ?? ''),
                        'source_type' => 'addon',
                        'addon_item_id' => (int) $addon->addon_item_id,
                        'role' => $addon->role,
                        'is_available' => $availability['is_available'],
                        'unavailable_reason' => $availability['unavailable_reason'],
                    ];
                })
                ->values()
                ->all();
        }

        return [];
    }

    private function matchesAttributeRef($attribute, string $sourceRef): bool
    {
        if (! $attribute) {
            return false;
        }

        if ($sourceRef === '') {
            // Avant : page viande sans source_ref listait aussi les sauces.
            return false;
        }

        if ((string) $attribute->id === $sourceRef) {
            return true;
        }

        $name = mb_strtolower(trim((string) $attribute->name));
        if ($name === $sourceRef) {
            return true;
        }

        // « viande » doit attraper « Viande 1 » / « Viande 2 », pas « Sauce ».
        if (str_starts_with($name, $sourceRef)) {
            $rest = substr($name, strlen($sourceRef));

            return $rest === '' || preg_match('/^[\s_\-\d(]/u', $rest) === 1;
        }

        return preg_match(
            '/(?:^|[\s_\-])'.preg_quote($sourceRef, '/').'(?:$|[\s_\-\d(])/u',
            $name
        ) === 1;
    }

    /**
     * Avant : le picker admin groupe les extras sans étiquette sous id « default ».
     * La caisse comparait à group_label === 'default' et la page restait vide.
     */
    private function matchesExtraGroup($extra, string $sourceRef): bool
    {
        if ($sourceRef === '') {
            // Avant : extra sans groupe ouvrait TOUS les extras de l'article.
            return false;
        }

        $label = mb_strtolower(trim((string) ($extra->group_label ?? '')));

        if ($sourceRef === 'default' && ($label === '' || $label === 'default')) {
            return true;
        }

        if ($label === $sourceRef) {
            return true;
        }

        $aliases = ComposerTemplateService::EXTRA_GROUP_ALIASES[$sourceRef] ?? [];

        return in_array($label === '' ? 'default' : $label, $aliases, true);
    }

    private function choiceAvailabilityResolver(): ChoiceAvailabilityResolver
    {
        return $this->choiceAvailabilityResolver ?? app(ChoiceAvailabilityResolver::class);
    }
}
