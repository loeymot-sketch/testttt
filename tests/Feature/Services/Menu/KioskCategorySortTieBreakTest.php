<?php

namespace Tests\Feature\Services\Menu;

use App\Models\ItemCategory;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * [F-KIOSK-CAT-SORT-TIEBREAK 2026-07-15] KioskMenuService::projectCategories triait les
 * catégories via la forme tableau sortBy([fn1, fn2]) — Laravel interprète fn2 comme une
 * DIRECTION, pas un tie-breaker → ordre NON-déterministe/faux à sort égal (le même bug corrigé
 * pour les items sous Wave Y A-001 mais laissé sur les catégories). Conséquence : la borne
 * pouvait présenter/atterrir sur une catégorie arbitraire quand deux catégories partagent un sort.
 * Le fix chaîne les sortBy (stable). Ce test verrouille l'ordre déterministe (sort asc, id en
 * tie-breaker).
 */
class KioskCategorySortTieBreakTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> ids dans l'ordre projeté */
    private function projectedOrder(Collection $cats): array
    {
        $svc = app(KioskMenuService::class);
        $m = new \ReflectionMethod($svc, 'projectCategories');
        $m->setAccessible(true);
        return collect($m->invoke($svc, $cats))->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_categories_with_equal_sort_break_ties_by_id_deterministically(): void
    {
        // A et B partagent sort=1 (comme une catégorie de test créée à sort=1 qui tie « Sandwichs »).
        $a = ItemCategory::create(['name' => 'Alpha', 'slug' => 'alpha-'.uniqid(), 'sort' => 1, 'status' => 1]);
        $b = ItemCategory::create(['name' => 'Bravo', 'slug' => 'bravo-'.uniqid(), 'sort' => 1, 'status' => 1]);
        $c = ItemCategory::create(['name' => 'Charlie', 'slug' => 'charlie-'.uniqid(), 'sort' => 2, 'status' => 1]);

        // Ordre d'entrée volontairement mélangé.
        $order = $this->projectedOrder(collect([$c, $b, $a]));

        // Attendu : sort ascendant, id croissant sur l'égalité de sort → [a, b, c].
        $this->assertSame([$a->id, $b->id, $c->id], $order,
            'À sort égal, l’ordre doit être déterministe (id croissant) puis par sort ascendant — pas l’ordre non-déterministe de la forme tableau.');
    }

    public function test_full_sort_order_is_ascending_by_sort(): void
    {
        $s6 = ItemCategory::create(['name' => 'Bols', 'slug' => 's6-'.uniqid(), 'sort' => 6, 'status' => 1]);
        $s1 = ItemCategory::create(['name' => 'Sandwichs', 'slug' => 's1-'.uniqid(), 'sort' => 1, 'status' => 1]);
        $s4 = ItemCategory::create(['name' => 'Burgers', 'slug' => 's4-'.uniqid(), 'sort' => 4, 'status' => 1]);

        $order = $this->projectedOrder(collect([$s6, $s1, $s4]));

        $this->assertSame([$s1->id, $s4->id, $s6->id], $order,
            'Les catégories doivent sortir par sort ascendant (1, 4, 6).');
    }
}
