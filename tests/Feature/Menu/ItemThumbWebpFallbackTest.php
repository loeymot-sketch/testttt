<?php

namespace Tests\Feature\Menu;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\MenuImageThumb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * [WEBP-MIGRATION 2026-07-07] Migration WebP visuellement sans perte des images
 * produit lourdes + résolveur à repli PNG.
 *
 * La commande `images:generate-pos-thumbs` couvre déjà la vignette ≤320px
 * (`thumbs/<name>.webp`, testée par PosThumbFallbackTest). Ce test verrouille le
 * NOUVEAU palier ajouté à `MenuImageThumb::url` : quand AUCUNE vignette n'existe
 * mais qu'un jumeau WebP plein format est posé À CÔTÉ du PNG
 * (`<base>/<name>.webp`, ex. `cwebp -q 90`), le résolveur sert le WebP ; sinon
 * il retombe sur le PNG/JPG original — 0 régression pour les images sans WebP.
 *
 * Travaille dans un base_path temporaire sous public/ pour ne jamais toucher les
 * vrais assets ni config/menu_images.php (LOCK parallèle).
 */
class ItemThumbWebpFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $relBase;
    private string $absBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relBase = 'webp-thumb-test-' . uniqid();
        $this->absBase = public_path($this->relBase);
        File::makeDirectory($this->absBase, 0775, true);

        Config::set('menu_images.base_path', $this->relBase);
        Config::set('menu_images.default', 'item-default.svg');
        Config::set('menu_images.items', ['webp-item' => 'source.png']);
        Config::set('menu_images.addons', []);
        Config::set('menu_images.categories', ['webp-cat' => 'source.png']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->absBase);
        parent::tearDown();
    }

    private function makeSourcePng(string $name = 'source.png'): void
    {
        $img = imagecreatetruecolor(600, 400);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 244, 80, 30, 20));
        imagepng($img, $this->absBase . '/' . $name);
        imagedestroy($img);
    }

    private function makeSiblingWebp(string $name = 'source.webp'): void
    {
        // Le résolveur ne teste que l'existence + filemtime : un stub suffit
        // (la qualité visuelle du WebP réel est prouvée par capture, pas ici).
        file_put_contents($this->absBase . '/' . $name, 'stub-webp-bytes');
    }

    /** (a) Un jumeau .webp à côté du PNG → thumb() renvoie l'URL .webp. */
    public function test_thumb_prefers_sibling_webp_twin_when_present(): void
    {
        $this->makeSourcePng();
        $this->makeSiblingWebp();
        $item = Item::factory()->create(['slug' => 'webp-item']);

        $thumb = $item->thumb;

        $this->assertStringContainsString("{$this->relBase}/source.webp?v=", $thumb);
        $this->assertStringNotContainsString('/thumbs/', $thumb);
        $this->assertStringNotContainsString('source.png', $thumb, 'le WebP doit remplacer le PNG lourd');
    }

    /** (b) Aucun .webp → thumb() renvoie le PNG (repli, 0 régression). */
    public function test_thumb_falls_back_to_png_when_no_webp_exists(): void
    {
        $this->makeSourcePng();
        $item = Item::factory()->create(['slug' => 'webp-item']);

        $thumb = $item->thumb;

        $this->assertStringContainsString("{$this->relBase}/source.png?v=", $thumb);
        $this->assertStringNotContainsString('.webp', $thumb);
    }

    /** La vignette ≤320px reste prioritaire sur le jumeau plein format. */
    public function test_thumbnail_wins_over_sibling_when_both_present(): void
    {
        $this->makeSourcePng();
        $this->makeSiblingWebp();
        File::makeDirectory($this->absBase . '/thumbs');
        file_put_contents($this->absBase . '/thumbs/source.webp', 'stub-thumb');
        $item = Item::factory()->create(['slug' => 'webp-item']);

        $this->assertStringContainsString("{$this->relBase}/thumbs/source.webp?v=", $item->thumb);
    }

    /** Le repli WebP est centralisé : ItemCategory en bénéficie aussi. */
    public function test_category_thumb_prefers_sibling_webp_twin(): void
    {
        $this->makeSourcePng();
        $this->makeSiblingWebp();
        $category = ItemCategory::query()->create(['name' => 'WebP Cat', 'slug' => 'webp-cat', 'status' => 5]);

        $this->assertStringContainsString("{$this->relBase}/source.webp?v=", $category->thumb);
    }

    /** (c) Format d'URL valide : absolue (asset) + cache-bust ?v=. */
    public function test_webp_url_is_absolute_and_cache_busted(): void
    {
        $this->makeSourcePng();
        $this->makeSiblingWebp();
        $item = Item::factory()->create(['slug' => 'webp-item']);

        $thumb = $item->thumb;

        $this->assertMatchesRegularExpression('#^https?://#', $thumb);
        $this->assertMatchesRegularExpression('#\.webp\?v=\d+$#', $thumb);
    }

    /** Helper siblingPath : dérive <base>/<name>.webp, null pour webp/svg. */
    public function test_sibling_path_helper_contract(): void
    {
        $this->assertSame('images/menu/coca.webp', MenuImageThumb::siblingPath('images/menu', 'coca.png'));
        $this->assertSame('images/menu/photo.webp', MenuImageThumb::siblingPath('images/menu', 'photo.jpg'));
        // Une source déjà .webp n'a pas de jumeau distinct.
        $this->assertNull(MenuImageThumb::siblingPath('images/menu', 'already.webp'));
        // Non-raster (SVG par défaut) → jamais réécrit.
        $this->assertNull(MenuImageThumb::siblingPath('images/menu', 'item-default.svg'));
        $this->assertNull(MenuImageThumb::siblingPath('images/menu', ''));
    }

    /** url() renvoie null quand aucun WebP n'existe → l'appelant sert le PNG. */
    public function test_url_returns_null_without_any_webp(): void
    {
        $this->makeSourcePng();

        $this->assertNull(MenuImageThumb::url($this->relBase, 'source.png'));
    }
}
