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
     * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 4] Turnkey binding hints. A template
     * step's source_ref starts empty; at apply-time we bind it to the item's
     * REAL attribute name (item_attribute) / group_label (extra_group) by
     * matching these keyword hints. This makes "apply template -> works" true
     * out of the box instead of leaving every step match-all (the old
     * "ça fonctionne pas"). Addon steps bind via addon_role, not source_ref.
     *
     * @var array<string, array<int, string>>
     */
    private const SOURCE_REF_HINTS = [
        'taille'      => ['taille', 'size', 'format'],
        'viande'      => ['viande', 'meat', 'protéine', 'proteine'],
        'sauce'       => ['sauce'],
        'pain'        => ['pain', 'galette', 'bread'],
        'garnitures'  => ['garniture', 'crudité', 'crudite', 'légume', 'legume', 'salade'],
        'supplements' => ['supplément', 'supplement', 'extra', 'topping', 'fromage'],
    ];

    /**
     * @return array{template: string, branch_id_scope: ?int, steps: array<int, array<string, mixed>>}
     */
    public function buildPayload(string $template, Item $item, ?int $branchIdScope = null): array
    {
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'custom';
        $item->loadMissing(['variations.itemAttribute', 'extras']);

        return [
            'template' => $template,
            'branch_id_scope' => $branchIdScope,
            'steps' => $this->bindSourcesToItem($this->stepsFor($template), $item),
        ];
    }

    /**
     * Bind each blueprint step's empty source_ref to the item's real constructs.
     * - item_attribute: matched by attribute name -> source_ref set. If NO
     *   attribute fits (e.g. a "taille" step on a tacos with no size attribute),
     *   the step is DEACTIVATED (is_active=false) — it stays in the skeleton (the
     *   owner can bind+activate it later) but never renders the match-all
     *   "all variations" bug (matchesAttributeRef('') -> true).
     * - extra_group: matched by group_label; if unmatched, kept active but
     *   projects 0 choices -> the kiosk wizard already skips it (no dead page).
     * - addon: untouched (addon_role drives resolution, not source_ref).
     * All steps are preserved (count + positions unchanged).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function bindSourcesToItem(array $steps, Item $item): array
    {
        $attrNames = $item->variations
            ->map(fn ($v) => $v->itemAttribute?->name)
            ->filter()
            ->unique()
            ->values();
        $groupLabels = $item->extras
            ->pluck('group_label')
            ->filter()
            ->unique()
            ->values();

        return array_map(function (array $step) use ($attrNames, $groupLabels): array {
            if ((string) ($step['source_ref'] ?? '') !== '') {
                return $step; // already bound (custom step) -> leave untouched
            }

            $hints = self::SOURCE_REF_HINTS[$step['step_key']] ?? [(string) ($step['step_key'] ?? '')];

            if (($step['source_type'] ?? '') === 'item_attribute') {
                $match = $this->firstMatch($attrNames, $hints);
                if ($match === null) {
                    $step['is_active'] = false; // deactivate unbindable attribute step
                } else {
                    $step['source_ref'] = $match;
                }
            } elseif (($step['source_type'] ?? '') === 'extra_group') {
                $match = $this->firstMatch($groupLabels, $hints);
                if ($match !== null) {
                    $step['source_ref'] = $match;
                }
            }

            return $step;
        }, $steps);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $candidates
     * @param  array<int, string>  $hints
     */
    private function firstMatch($candidates, array $hints): ?string
    {
        foreach ($candidates as $candidate) {
            $lower = mb_strtolower((string) $candidate);
            foreach ($hints as $hint) {
                if ($hint !== '' && str_contains($lower, mb_strtolower($hint))) {
                    return (string) $candidate;
                }
            }
        }

        return null;
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
