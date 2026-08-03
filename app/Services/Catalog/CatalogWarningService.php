<?php

namespace App\Services\Catalog;

use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;

/**
 * CatalogWarningService — Mission #2 lifecycle UX hardening.
 *
 * Surfaces non-blocking warnings on a catalog entity (Item / ItemCategory)
 * to help admins notice incomplete or risky configurations:
 *
 *   - composer_unpublished : item has a composer profile draft but no
 *     published version → wizard will not show.
 *   - composer_missing_for_complex_kind : item is flagged as complex
 *     (item_type has variations/extras) but no composer profile exists.
 *   - channels_null : item or category has channels=NULL → visible on
 *     every surface AND every branch (back-compat trap).
 *   - missing_photo : item has no media collection entry → kiosk renders
 *     a placeholder card, degraded UX.
 *   - branch_availability_unset : item has zero item_branch_availability
 *     rows → relies on default-true behavior, no rupture protection.
 *   - high_daily_consumed : daily_consumed_qty / max_daily_qty > 0.85 →
 *     close to auto-86, alert preventively.
 *
 * The service is READ-ONLY. It does not mutate any model. It returns
 * structured warning entries that ItemController::show / ItemController::index
 * include in the JSON response when
 * config('catalog_v15.warnings.expose_to_admin_show') is true.
 *
 * Frontend consumers:
 *   - resources/js/components/admin/items/ItemShowComponent.vue
 *   - resources/js/components/admin/items/ProductComposerSummaryComponent.vue
 *   - resources/js/components/admin/items/ComposerProfileWarningBadge.vue
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §D Vague 1
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 1.1, 1.5
 */
class CatalogWarningService
{
    /**
     * Severity levels exposed to the frontend.
     */
    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_BLOCKER = 'blocker';

    /**
     * Warning codes — keep stable, frontend localizes them via i18n.
     */
    public const CODE_COMPOSER_UNPUBLISHED            = 'composer_unpublished';
    public const CODE_COMPOSER_MISSING_FOR_COMPLEX    = 'composer_missing_for_complex_kind';
    public const CODE_CHANNELS_NULL                   = 'channels_null';
    public const CODE_MISSING_PHOTO                   = 'missing_photo';
    public const CODE_BRANCH_AVAILABILITY_UNSET       = 'branch_availability_unset';
    public const CODE_HIGH_DAILY_CONSUMED             = 'high_daily_consumed';

    /**
     * Compute warnings for a single Item.
     *
     * @return array<int, array{code:string, severity:string, context:array}>
     *
     * Example output:
     *   [
     *     ['code'=>'composer_unpublished','severity'=>'warning','context'=>['profile_id'=>42]],
     *     ['code'=>'channels_null','severity'=>'info','context'=>[]],
     *   ]
     */
    public function forItem(Item $item, ?int $branchId = null): array
    {
        $warnings = [];

        $profile = ItemWizardProfile::query()
            ->where('item_id', $item->id)
            ->orderByDesc('version')
            ->first();

        if ($profile !== null && !$profile->is_published) {
            $warnings[] = [
                'code'     => self::CODE_COMPOSER_UNPUBLISHED,
                'severity' => self::SEVERITY_WARNING,
                'context'  => ['profile_id' => $profile->id],
            ];
        }

        $isComplexKind = $item->variations()->exists()
            || $item->extras()->exists();

        if ($profile === null && $isComplexKind) {
            $warnings[] = [
                'code'     => self::CODE_COMPOSER_MISSING_FOR_COMPLEX,
                'severity' => self::SEVERITY_BLOCKER,
                'context'  => [],
            ];
        }

        if ($item->channels === null) {
            $warnings[] = [
                'code'     => self::CODE_CHANNELS_NULL,
                'severity' => self::SEVERITY_INFO,
                'context'  => [],
            ];
        }

        if ($item->getFirstMedia('item') === null) {
            $warnings[] = [
                'code'     => self::CODE_MISSING_PHOTO,
                'severity' => self::SEVERITY_INFO,
                'context'  => [],
            ];
        }

        if ($branchId !== null) {
            $availabilityRow = ItemBranchAvailability::query()
                ->where('item_id', $item->id)
                ->where('branch_id', $branchId)
                ->first();

            if ($availabilityRow === null) {
                $warnings[] = [
                    'code'     => self::CODE_BRANCH_AVAILABILITY_UNSET,
                    'severity' => self::SEVERITY_INFO,
                    'context'  => ['branch_id' => $branchId],
                ];
            } elseif ($availabilityRow->max_daily_qty !== null && (int) $availabilityRow->max_daily_qty > 0) {
                $max = (int) $availabilityRow->max_daily_qty;
                $consumed = (int) $availabilityRow->daily_consumed_qty;
                $ratio = $consumed / $max;
                if ($ratio >= 0.85) {
                    $warnings[] = [
                        'code'     => self::CODE_HIGH_DAILY_CONSUMED,
                        'severity' => self::SEVERITY_WARNING,
                        'context'  => [
                            'consumed' => $consumed,
                            'max'      => $max,
                            'ratio'    => round($ratio, 3),
                        ],
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * Compute warnings for a single ItemCategory.
     *
     * Currently only `channels_null` is checked. Extension points
     * documented in plan task 1.5.
     */
    public function forCategory(ItemCategory $category): array
    {
        $warnings = [];

        if ($category->channels === null) {
            $warnings[] = [
                'code'     => self::CODE_CHANNELS_NULL,
                'severity' => self::SEVERITY_INFO,
                'context'  => [],
            ];
        }

        return $warnings;
    }

    /**
     * Convenience helper that wraps forItem in the canonical JSON-friendly
     * shape used by Item resources / API responses.
     */
    public function exposeFor(Item $item, ?int $branchId = null): array
    {
        $warnings = $this->forItem($item, $branchId);

        return [
            'warnings' => $warnings,
            'meta'     => [
                'severity_max' => self::computeSeverityMax($warnings),
            ],
        ];
    }

    private static function computeSeverityMax(array $warnings): string
    {
        $rank = [
            self::SEVERITY_INFO    => 0,
            self::SEVERITY_WARNING => 1,
            self::SEVERITY_BLOCKER => 2,
        ];
        $max = self::SEVERITY_INFO;
        foreach ($warnings as $w) {
            if (($rank[$w['severity'] ?? self::SEVERITY_INFO] ?? 0) > ($rank[$max] ?? 0)) {
                $max = $w['severity'];
            }
        }
        return $max;
    }
}
