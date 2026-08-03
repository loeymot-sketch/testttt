<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Tax;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-A — Les extras INACTIFS (status != Status::ACTIVE) ne doivent JAMAIS
 * fuir sur les étapes Suppléments/Garnitures de la borne.
 *
 * Repro terrain : item « Bol Frites » exposant « Option Gratiné » actif +
 * son homonyme inactif → doublon dans le wizard + rejet 422 au paiement
 * (le backend refuse un extra inactif à la commande).
 */
class KioskInactiveExtraFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeExtra(Item $item, string $name, int $status, float $price = 1.00): ItemExtra
    {
        $extra = ItemExtra::query()->create([
            'item_id'     => $item->id,
            'name'        => $name,
            'price'       => $price,
            'status'      => $status,
            'visible_on'  => null, // visible partout (kiosk inclus)
            'group_label' => 'supplement',
        ]);

        // Force le status même si un observer/relationship tenterait de le figer.
        $extra->forceFill(['status' => $status])->save();

        return $extra->fresh();
    }

    public function test_kiosk_menu_build_excludes_inactive_extras(): void
    {
        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create([
            'name'     => 'TVA Inactive Extra',
            'type'     => TaxType::PERCENTAGE,
            'tax_rate' => 10,
            'status'   => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name'     => 'Bols',
            'status'   => Status::ACTIVE,
            'channels' => null,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'name'             => 'Bol Frites',
            'slug'             => 'bol-frites-' . uniqid(),
            'price'            => 6.90,
            'status'           => Status::ACTIVE,
            'channels'         => null,
        ]);

        // Contrôles ACTIFS — doivent apparaître.
        $this->makeExtra($item, 'Cheddar', Status::ACTIVE, 1.00);
        $this->makeExtra($item, 'Option Gratiné', Status::ACTIVE, 2.00);
        // Homonyme INACTIF + garniture INACTIVE — doivent disparaître.
        $this->makeExtra($item, 'Option Gratiné', Status::INACTIVE, 2.00);
        $this->makeExtra($item, 'Boule gratinée', Status::INACTIVE, 0.00);

        $payload = app(KioskMenuService::class)->build($branch);
        $projected = collect($payload['items'])->firstWhere('id', $item->id);

        $this->assertIsArray($projected, 'Item Bol Frites absent du payload kiosk.');

        $names = collect($projected['extras'])->pluck('name')->all();
        $statuses = collect($projected['extras'])->pluck('status')->unique()->values()->all();

        // Aucun extra inactif ne doit être exposé.
        $this->assertNotContains(
            'Boule gratinée',
            $names,
            'Un extra INACTIF (garniture) a fui sur la borne.'
        );
        $this->assertSame(
            [Status::ACTIVE],
            $statuses,
            'Le payload contient un extra avec status != ACTIVE.'
        );
        // Pas de doublon actif/inactif du même nom : « Option Gratiné » apparaît 1x.
        $this->assertSame(
            1,
            collect($names)->filter(fn ($n) => $n === 'Option Gratiné')->count(),
            'Doublon actif/inactif de « Option Gratiné » exposé sur la borne.'
        );
        // Les actifs restent bien présents (non-régression).
        $this->assertContains('Cheddar', $names);
        $this->assertContains('Option Gratiné', $names);
    }
}
