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
            'name' => 'Sandwichs',
            'parent_id' => null,
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
        $this->assertNull($data['parent_id']);
    }

    /**
     * [2026-09-02] La hiérarchie existe en base et la borne la rend ; la ressource doit l'exposer,
     * et ne doit PAS exploser quand l'objet source ne porte pas la colonne (projection, stub).
     */
    /** @test */
    public function it_exposes_the_parent_category(): void
    {
        $enfant = new ItemCategoryResource((object) [
            'id' => 42,
            'name' => 'Tacos Signature',
            'parent_id' => 5,
            'slug' => 'tacos-signature',
            'description' => null,
            'status' => 5,
            'sort' => 2,
            'thumb' => 'https://cdn.test/thumb.png',
            'cover' => 'https://cdn.test/cover.png',
            'wizard_template' => 'tacos',
            'has_menu' => false,
            'kiosk_upsell_include' => false,
            'kiosk_upsell_skip_after_cart' => false,
        ]);
        $this->assertSame(5, $enfant->toArray(new Request())['parent_id']);

        $sansColonne = new ItemCategoryResource((object) [
            'id' => 7,
            'name' => 'Frites',
            'slug' => 'frites',
            'description' => null,
            'status' => 5,
            'sort' => 3,
            'thumb' => 'https://cdn.test/thumb.png',
            'cover' => 'https://cdn.test/cover.png',
            'wizard_template' => 'tacos',
            'has_menu' => false,
            'kiosk_upsell_include' => false,
            'kiosk_upsell_skip_after_cart' => false,
        ]);
        $this->assertNull($sansColonne->toArray(new Request())['parent_id']);
    }
}
