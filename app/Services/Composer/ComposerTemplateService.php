<?php

namespace App\Services\Composer;

use App\Models\Item;

/**
 * Builds wizard step starter payloads keyed by template name.
 *
 * Used by ComposerProfileController::applyTemplate to generate a starter
 * profile (consumable by ComposerProfileService::createForItem) that the
 * admin can subsequently customise. Step shape matches what
 * ComposerStepService::normalize accepts (step_key, label, source_type,
 * source_ref, min_select, max_select, position, is_active, visible_on,
 * addon_role).
 */
class ComposerTemplateService
{
    public const TEMPLATES = ['simple', 'sandwich', 'tacos', 'assiette', 'snacking', 'menu', 'custom'];

    /**
     * Alias d'un step_key vers les group_label réels du menu Le Cayenne.
     * Le picker admin envoie aussi « default » quand group_label est vide.
     */
    public const EXTRA_GROUP_ALIASES = [
        'garnitures' => ['garnitures', 'garniture', 'crudite', 'crudites', 'crudité', 'crudités'],
        'garniture' => ['garniture', 'garnitures', 'crudite', 'crudites', 'crudité', 'crudités'],
        'supplements' => ['supplements', 'supplement', 'supplément', 'suppléments', 'extra', 'extras', 'default'],
        'supplement' => ['supplement', 'supplements', 'supplément', 'suppléments', 'extra', 'extras', 'default'],
    ];

    /**
     * @return array{template: string, branch_id_scope: ?int, steps: array<int, array<string, mixed>>}
     */
    public function buildPayload(string $template, Item $item, ?int $branchIdScope = null): array
    {
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'custom';
        $item->loadMissing(['variations.itemAttribute', 'extras', 'addons']);

        return [
            'template' => $template,
            'branch_id_scope' => $branchIdScope,
            'steps' => array_map(
                fn (array $step): array => $this->bindStep($step, $item),
                $this->stepsFor($template)
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stepsFor(string $template): array
    {
        $base = function (string $key, string $label, int $position, array $extras = []): array {
            return array_merge([
                'step_key' => $key,
                'label' => $label,
                'position' => $position,
                'source_type' => 'item_attribute',
                'source_ref' => '',
                'min_select' => 1,
                'max_select' => 1,
                'is_active' => true,
                'visible_on' => ['pos', 'kiosk'],
                'addon_role' => null,
            ], $extras);
        };

        return match ($template) {
            'sandwich' => [
                $base('pain', 'Choisis ton pain', 1),
                $base('viande', 'Choisis ta viande', 2),
                $base('sauce', 'Choisis ta sauce', 3),
                $base('garnitures', 'Choisis tes garnitures', 4, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 6,
                ]),
                $base('supplements', 'Suppléments', 5, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 5,
                ]),
            ],
            'tacos' => [
                $base('taille', 'Choisis la taille', 1),
                $base('viande', 'Choisis tes viandes', 2, [
                    'min_select' => 1,
                    'max_select' => 4,
                ]),
                $base('sauce', 'Choisis ta sauce', 3),
                $base('garnitures', 'Choisis tes garnitures', 4, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 6,
                ]),
                $base('supplements', 'Suppléments', 5, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 5,
                ]),
                $base('menu', 'Choisis ta formule', 6, [
                    'source_type' => 'addon',
                    'min_select' => 0,
                    'max_select' => 1,
                    'addon_role' => 'menu_component',
                ]),
            ],
            'assiette' => [
                $base('viande', 'Choisis ta viande', 1, [
                    'min_select' => 1,
                    'max_select' => 2,
                ]),
                $base('sauce', 'Choisis ta sauce', 2),
                $base('garnitures', 'Choisis tes garnitures', 3, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 6,
                ]),
            ],
            'snacking' => [
                $base('supplements', 'Suppléments', 1, [
                    'source_type' => 'extra_group',
                    'min_select' => 0,
                    'max_select' => 5,
                ]),
            ],
            'menu' => [
                $base('plat', 'Choisis ton plat', 1, [
                    'source_type' => 'addon',
                    'addon_role' => 'menu_component',
                ]),
                $base('boisson', 'Choisis ta boisson', 2, [
                    'source_type' => 'addon',
                    'addon_role' => 'drink',
                ]),
                $base('dessert', 'Choisis ton dessert', 3, [
                    'source_type' => 'addon',
                    'addon_role' => 'dessert',
                ]),
            ],
            default => [],
        };
    }

    /**
     * Avant : le template tacos posait des pages « Viande » / « Sauce » avec
     * source_ref vide. En caisse, chaque page mélangeait toutes les options.
     * On câble le step_key (viande, sauce…) et le vrai groupe extras du produit.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function bindStep(array $step, Item $item): array
    {
        $sourceType = (string) ($step['source_type'] ?? '');

        if ($sourceType === 'item_attribute') {
            $step['source_ref'] = (string) $step['step_key'];
            $matches = $item->variations
                ->pluck('itemAttribute')
                ->filter()
                ->unique('id')
                ->filter(fn ($attribute): bool => $this->attributeMatchesKey(
                    (string) $attribute->name,
                    (string) $step['step_key']
                ))
                ->values();

            if ($matches->count() === 1) {
                $step['source_item_attribute_id'] = (int) $matches->first()->id;
            }

            return $step;
        }

        if ($sourceType === 'extra_group') {
            $step['source_ref'] = $this->bindExtraGroup($item, (string) $step['step_key']);

            return $step;
        }

        if ($sourceType === 'addon') {
            $step['source_ref'] = (string) ($step['addon_role'] ?? $step['step_key']);
        }

        return $step;
    }

    private function bindExtraGroup(Item $item, string $stepKey): string
    {
        $aliases = self::EXTRA_GROUP_ALIASES[$stepKey] ?? [$stepKey];
        $seen = [];

        foreach ($item->extras as $extra) {
            $raw = trim((string) ($extra->group_label ?? ''));
            $normalized = mb_strtolower($raw);
            if ($raw === '') {
                $seen['default'] = 'default';
                continue;
            }
            $seen[$normalized] = $raw;
        }

        foreach ($aliases as $alias) {
            if (isset($seen[$alias])) {
                return $seen[$alias];
            }
        }

        return $stepKey;
    }

    private function attributeMatchesKey(string $name, string $stepKey): bool
    {
        $name = mb_strtolower(trim($name));
        $aliases = match ($stepKey) {
            'taille' => ['taille', 'size'],
            'viande' => ['viande', 'meat', 'filling'],
            'sauce' => ['sauce'],
            'pain' => ['pain', 'bread'],
            default => [$stepKey],
        };

        foreach ($aliases as $alias) {
            if ($name === $alias) {
                return true;
            }
            if (str_starts_with($name, $alias)) {
                $rest = substr($name, strlen($alias));
                if ($rest === '' || preg_match('/^[\s_\-\d(]/u', $rest) === 1) {
                    return true;
                }
            }
            if (preg_match('/(?:^|[\s_\-])'.preg_quote($alias, '/').'(?:$|[\s_\-\d(])/u', $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
