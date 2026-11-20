<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W7-D D-006 2026-11-04] Le catalogue (articles, catégories, détails) n'offrait aucune validation de cache : 47 Ko
 * re-téléchargés à chaque ouverture de l'application. Désormais : `ETag` = empreinte du corps, `Cache-Control:
 * private, max-age=0, must-revalidate`, et un `If-None-Match` qui correspond vaut 304 sans corps. Un corps qui change
 * (prix) change l'ETag.
 */
class CatalogueEtagTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['status' => Status::ACTIVE, 'name' => 'Sandwichs W7']);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => Status::ACTIVE, 'name' => 'Article W7', 'price' => 7.40,
        ]);
    }

    /** @test */
    public function la_liste_des_articles_porte_un_etag_et_repond_304_si_inchangee(): void
    {
        $r1 = $this->getJson('/api/frontend/item');
        $r1->assertStatus(200);
        $etag = $r1->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('must-revalidate', (string) $r1->headers->get('Cache-Control'));

        $r2 = $this->withHeader('If-None-Match', $etag)->getJson('/api/frontend/item');
        $r2->assertStatus(304);
        $this->assertSame('', (string) $r2->getContent());
        $this->assertSame($etag, $r2->headers->get('ETag'));

        // ETag faible et liste : tolérés (même règle que la vitrine).
        $this->withHeader('If-None-Match', 'W/"autre", '.$etag)->getJson('/api/frontend/item')->assertStatus(304);
    }

    /** @test */
    public function un_prix_modifie_change_l_etag(): void
    {
        $etag = $this->getJson('/api/frontend/item')->headers->get('ETag');

        $this->item->forceFill(['price' => 7.90])->save();

        $r = $this->withHeader('If-None-Match', $etag)->getJson('/api/frontend/item');
        $r->assertStatus(200);
        $this->assertNotSame($etag, $r->headers->get('ETag'));
    }

    /** @test */
    public function categories_et_details_ont_la_meme_mecanique(): void
    {
        foreach (['/api/frontend/item-category', '/api/frontend/item/details/'.$this->item->id] as $url) {
            $r1 = $this->getJson($url);
            $r1->assertStatus(200);
            $etag = $r1->headers->get('ETag');
            $this->assertNotEmpty($etag, $url);
            $this->withHeader('If-None-Match', $etag)->getJson($url)->assertStatus(304);
        }
    }
}
