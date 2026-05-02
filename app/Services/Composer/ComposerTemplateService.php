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
     * @return array{template: string, branch_id_scope: ?int, steps: array<int, array<string, mixed>>}
     */
    public function buildPayload(string $template, Item $item, ?int $branchIdScope = null): array
    {
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'custom';

        return [
            'template' => $template,
            'branch_id_scope' => $branchIdScope,
            'steps' => $this->stepsFor($template),
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
}
