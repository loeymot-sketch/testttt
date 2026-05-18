<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\ItemRequest;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave Z V1.0.1 Sprint H5 — Z5-P1-02
 *
 * Verifies ItemRequest::rules() declares barcode + kds_station rules so the
 * fields aren't silently dropped by FormRequest gatekeeping.
 */
class ItemRequestBarcodeKdsStationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_barcode_rule_present_with_unique_constraint(): void
    {
        Item::factory()->create(['barcode' => 'ABC123']);

        $rules = (new ItemRequest())->rules();

        $this->assertArrayHasKey('barcode', $rules);
        $this->assertContains('nullable', $rules['barcode']);
        $this->assertContains('string', $rules['barcode']);
        $this->assertContains('max:64', $rules['barcode']);
        $this->assertTrue(
            collect($rules['barcode'])->contains(fn ($r) => str_contains((string) $r, 'unique:items')),
            'barcode rule must contain unique:items constraint'
        );
    }

    /** @test */
    public function test_kds_station_rule_present(): void
    {
        $rules = (new ItemRequest())->rules();

        $this->assertArrayHasKey('kds_station', $rules);
        $this->assertContains('nullable', $rules['kds_station']);
        $this->assertContains('string', $rules['kds_station']);
        $this->assertContains('max:32', $rules['kds_station']);
    }
}
