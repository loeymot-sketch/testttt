<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\ItemCategoryResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class ItemCategoryResourceTest extends TestCase
{
    /** @test */
    public function it_exposes_legacy_kiosk_image_aliases(): void
    {
        $resource = new ItemCategoryResource((object) [
            'id' => 1,
            'parent_id' => null, // [release-v1 heal 2026-06-10] colonne hiérarchie (migration 2026_04_18) — stub schema-exact pour ItemCategoryResource.parent_id
            'name' => 'Sandwichs',
            'slug' => 'nos-sandwichs',
            'description' => null,
            'status' => 5,
            'sort' => 1,
            'thumb' => 'https://cdn.test/thumb.png',
            'cover' => 'https://cdn.test/cover.png',
            'wizard_template' => 'sandwich',
            'has_menu' => true,
            'kiosk_upsell_include' => true,
            'kiosk_upsell_skip_after_cart' => false,
        ]);

        $data = $resource->toArray(new Request());

        $this->assertSame('https://cdn.test/thumb.png', $data['image']);
        $this->assertSame('https://cdn.test/cover.png', $data['image_full_path']);
        $this->assertSame('https://cdn.test/thumb.png', $data['thumb']);
        $this->assertSame('https://cdn.test/cover.png', $data['cover']);
    }
}
