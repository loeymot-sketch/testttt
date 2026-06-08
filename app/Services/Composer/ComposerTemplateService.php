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
     *
     * - item_attribute: matched by attribute name. When SEVERAL attributes fit a
     *   single step's hints (e.g. a "Big" tacos carrying BOTH "Viande 1" and
     *   "Viande 2"), the step is EXPANDED into one bound step per attribute — the
     *   first keeps the template key/label/min_select, each extra becomes its own
     *   step (key "<key>_2", "<key>_3"…, label = the attribute name, and OPTIONAL:
     *   min_select=0). This fixes the audit P1 where only the first attribute
     *   rendered and the 2nd meat page was silently dropped. If NO attribute fits,
     *   the step is DEACTIVATED (is_active=false) — kept in the skeleton for later
     *   binding but never rendering the match-all "all variations" bug
     *   (matchesAttributeRef('') -> true).
     * - extra_group: matched by group_label; if unmatched, the step is
     *   DEACTIVATED (symmetric with item_attribute) — never left with an empty
     *   source_ref, which the projection treats as match-all (EVERY extra of the
     *   item, audit P1) rather than "0 choices".
     * - addon: untouched (addon_role drives resolution, not source_ref).
     *
     * Each real attribute binds to at most one step (claimed-tracking), step_keys
     * stay unique (DB unique(profile_id, step_key)), and positions are renumbered
     * gap-free so expanded steps slot in right after their origin (the steps()
     * relation orders by position).
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

        $claimedAttrs = [];   // lower-cased attribute names already bound to a step
        $usedKeys = [];       // step_key uniqueness guard (DB unique(profile_id, step_key))
        foreach ($steps as $s) {
            if (isset($s['step_key'])) {
                $usedKeys[(string) $s['step_key']] = true;
            }
        }

        $out = [];
        foreach ($steps as $step) {
            if ((string) ($step['source_ref'] ?? '') !== '') {
                $out[] = $step; // already bound (custom step) -> leave untouched
                continue;
            }

            $sourceType = (string) ($step['source_type'] ?? '');
            $hints = self::SOURCE_REF_HINTS[$step['step_key']] ?? [(string) ($step['step_key'] ?? '')];

            if ($sourceType === 'item_attribute') {
                $matches = $this->allMatches($attrNames, $hints)
                    ->reject(fn ($name) => isset($claimedAttrs[mb_strtolower((string) $name)]))
                    ->values();

                if ($matches->isEmpty()) {
                    $step['is_active'] = false; // deactivate unbindable attribute step
                    $out[] = $step;
                    continue;
                }

                $first = (string) $matches->first();
                $claimedAttrs[mb_strtolower($first)] = true;
                $step['source_ref'] = $first;
                $out[] = $step;

                // Each additional matching attribute -> its OWN expanded step.
                foreach ($matches->slice(1) as $extraName) {
                    $extraName = (string) $extraName;
                    $claimedAttrs[mb_strtolower($extraName)] = true;
                    $clone = $step;
                    $clone['step_key'] = $this->uniqueStepKey((string) $step['step_key'], $usedKeys);
                    $clone['label'] = $extraName;   // e.g. "Viande 2"
                    $clone['source_ref'] = $extraName;
                    $clone['min_select'] = 0;       // 2nd+ matching attribute is optional
                    $out[] = $clone;
                }
            } elseif ($sourceType === 'extra_group') {
                $match = $this->firstMatch($groupLabels, $hints);
                if ($match === null) {
                    $step['is_active'] = false; // never leave empty -> projection match-all
                } else {
                    $step['source_ref'] = $match;
                }
                $out[] = $step;
            } else {
                $out[] = $step; // addon / other -> untouched
            }
        }

        // Renumber positions gap-free so expanded steps keep deterministic order.
        $position = 1;
        foreach ($out as &$ordered) {
            $ordered['position'] = $position++;
        }
        unset($ordered);

        return $out;
    }

    /**
     * First candidate whose lower-cased value contains any hint, or null.
     *
     * @param  \Illuminate\Support\Collection<int, string>|iterable<int, string>  $candidates
     * @param  array<int, string>  $hints
     */
    private function firstMatch($candidates, array $hints): ?string
    {
        $match = $this->allMatches($candidates, $hints)->first();

        return $match !== null ? (string) $match : null;
    }

    /**
     * All candidates (in order) whose lower-cased value contains any hint.
     * Drives multi-attribute step expansion.
     *
     * @param  \Illuminate\Support\Collection<int, string>|iterable<int, string>  $candidates
     * @param  array<int, string>  $hints
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function allMatches($candidates, array $hints): \Illuminate\Support\Collection
    {
        return collect($candidates)->filter(function ($candidate) use ($hints): bool {
            $lower = mb_strtolower((string) $candidate);
            foreach ($hints as $hint) {
                if ($hint !== '' && str_contains($lower, mb_strtolower($hint))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private function uniqueStepKey(string $base, array &$used): string
    {
        $i = 2;
        do {
            $candidate = $base . '_' . $i;
            $i++;
        } while (isset($used[$candidate]));

        $used[$candidate] = true;

        return $candidate;
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
