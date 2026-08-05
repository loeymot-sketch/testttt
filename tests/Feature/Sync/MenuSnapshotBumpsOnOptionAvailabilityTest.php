<?php

namespace Tests\Feature\Sync;

use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SYNC B8 SENTINELLE 2026-08-05] `snapshot_version` DOIT bumper au 86 d'un EXTRA ou d'une VARIATION
 * (pas seulement item-level) — sinon une surface qui DROP le WS pendant le toggle puis reconnecte
 * et fait confiance au version-diff raterait le refresh.
 *
 * ⚠️ B8 ÉTAIT UN FAUX GAP (audit RED L3 le disait « no bump listener » ; vérif TDD RED-first le
 * réfute) : le bump EST déjà câblé sur les 2 events via `InvalidateKioskMenuCacheOnCatalogChange`
 * (`:53 $this->snapshot->bump($branchId)`, enregistré sur ItemExtra/VariationAvailabilityChanged
 * dans EventServiceProvider) — pas via `BumpMenuSnapshotOnItemAvailabilityChanged` (item-only, ce
 * que L3 avait regardé). Ce test VERROUILLE le comportement correct contre une régression (si un
 * refactor retirait le bump du chemin option). Aucun listener ajouté = 0 code redondant.
 */
class MenuSnapshotBumpsOnOptionAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_version_bumps_when_an_extra_is_86(): void
    {
        $branchId = 1;
        $before = app(MenuSnapshot::class)->current($branchId);

        event(new ItemExtraAvailabilityChanged(
            extraId: 42,
            branchId: $branchId,
            isAvailable: false,
            reason: 'out_of_stock'
        ));

        $after = app(MenuSnapshot::class)->current($branchId);
        $this->assertGreaterThan($before, $after, 'Le 86 d\'un EXTRA doit bumper snapshot_version (B8).');
    }

    public function test_snapshot_version_bumps_when_a_variation_is_86(): void
    {
        $branchId = 1;
        $before = app(MenuSnapshot::class)->current($branchId);

        event(new ItemVariationAvailabilityChanged(
            variationId: 7,
            branchId: $branchId,
            isAvailable: false,
            reason: 'out_of_stock'
        ));

        $after = app(MenuSnapshot::class)->current($branchId);
        $this->assertGreaterThan($before, $after, 'Le 86 d\'une VARIATION doit bumper snapshot_version (B8).');
    }
}
