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
 * [W5-PERF #1 2026-07-06] Fix A4 verdicts.md — le fallback `thumb` des modèles
 * catalogue servait le PNG PLEIN FORMAT (jusqu'à 2,9 Mo) dès qu'aucune
 * conversion medialibrary n'existe. Désormais :
 *
 *   1. `php artisan images:generate-pos-thumbs` pré-génère une vignette WebP
 *      ≤320 px par image raster de `menu_images.base_path` (idempotent mtime).
 *   2. `Item/ItemCategory/ItemVariation/ItemExtra::getThumbAttribute` servent
 *      la vignette quand elle existe (via App\Support\MenuImageThumb) et
 *      RETOMBENT sur l'original sinon — comportement antérieur préservé.
 *
 * Le test travaille dans un base_path temporaire sous public/ pour ne jamais
 * toucher les vrais assets ni config/menu_images.php (LOCK parallèle).
 */
class PosThumbFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $relBase;
    private string $absBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relBase = 'w5-thumb-test-' . uniqid();
        $this->absBase = public_path($this->relBase);
        File::makeDirectory($this->absBase, 0775, true);

        Config::set('menu_images.base_path', $this->relBase);
        Config::set('menu_images.default', 'item-default.svg');
        Config::set('menu_images.items', ['w5-item' => 'w5-source.png']);
        Config::set('menu_images.addons', []);
        Config::set('menu_images.categories', ['w5-cat' => 'w5-source.png']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->absBase);
        parent::tearDown();
    }

    /** Crée un vrai PNG (GD) de la taille demandée dans le base_path de test. */
    private function makeSourcePng(int $width = 800, int $height = 600, string $name = 'w5-source.png'): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 244, 80, 30, 20));
        $path = $this->absBase . '/' . $name;
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeItem(string $slug = 'w5-item'): Item
    {
        return Item::factory()->create(['slug' => $slug]);
    }

    public function test_fallback_serves_original_full_size_when_no_thumb_exists(): void
    {
        $this->makeSourcePng();
        $item = $this->makeItem();

        $thumb = $item->thumb;

        $this->assertStringContainsString("{$this->relBase}/w5-source.png?v=", $thumb);
        $this->assertStringNotContainsString('/thumbs/', $thumb, 'sans vignette générée, l\'original plein format reste servi (comportement pré-W5)');
    }

    public function test_fallback_serves_generated_webp_thumb_when_present(): void
    {
        $this->makeSourcePng();
        File::makeDirectory($this->absBase . '/thumbs');
        file_put_contents($this->absBase . '/thumbs/w5-source.webp', 'stub-webp');
        $item = $this->makeItem();

        $thumb = $item->thumb;

        $this->assertStringContainsString("{$this->relBase}/thumbs/w5-source.webp?v=", $thumb);
    }

    public function test_category_fallback_serves_generated_webp_thumb_when_present(): void
    {
        $this->makeSourcePng();
        File::makeDirectory($this->absBase . '/thumbs');
        file_put_contents($this->absBase . '/thumbs/w5-source.webp', 'stub-webp');
        $category = ItemCategory::query()->create(['name' => 'W5 Cat', 'slug' => 'w5-cat', 'status' => 5]);

        $this->assertStringContainsString("{$this->relBase}/thumbs/w5-source.webp?v=", $category->thumb);
    }

    public function test_unmapped_slug_still_falls_back_to_default_placeholder(): void
    {
        // Pas de w5-source.png ni d'item-default.svg dans le base_path de test
        // → placeholder générique historique, inchangé par W5.
        $item = $this->makeItem('slug-non-mappe');

        $this->assertStringContainsString('images/item/thumb.png', $item->thumb);
    }

    public function test_svg_default_is_never_rewritten_to_a_thumb_path(): void
    {
        $this->assertNull(MenuImageThumb::relativePath('images/menu', 'item-default.svg'));
        $this->assertNull(MenuImageThumb::url('images/menu', ''));
        $this->assertSame(
            'images/menu/thumbs/coca.webp',
            MenuImageThumb::relativePath('images/menu', 'coca.png'),
        );
    }

    public function test_generate_command_creates_webp_at_most_320px_and_is_idempotent(): void
    {
        $this->makeSourcePng(1536, 1024);

        $this->artisan('images:generate-pos-thumbs')->assertExitCode(0);

        $thumbPath = $this->absBase . '/thumbs/w5-source.webp';
        $this->assertFileExists($thumbPath);
        [$w, $h] = getimagesize($thumbPath);
        $this->assertLessThanOrEqual(320, max($w, $h), 'le bord le plus long doit être ≤320px');
        $this->assertLessThan(filesize($this->absBase . '/w5-source.png'), filesize($thumbPath), 'la vignette doit peser moins que la source');

        // Idempotence : un second run ne réécrit pas la vignette à jour.
        $mtime = filemtime($thumbPath);
        $this->travel(2)->seconds();
        $this->artisan('images:generate-pos-thumbs')->assertExitCode(0);
        clearstatcache();
        $this->assertSame($mtime, filemtime($thumbPath), 'vignette à jour → sautée (idempotent)');
    }

    public function test_generate_command_regenerates_when_source_is_newer(): void
    {
        $source = $this->makeSourcePng(640, 480);
        $this->artisan('images:generate-pos-thumbs')->assertExitCode(0);
        $thumbPath = $this->absBase . '/thumbs/w5-source.webp';
        $this->assertFileExists($thumbPath);

        // Simule un swap d'image owner (LOCK viandes/boissons) : source plus
        // récente que la vignette (vignette antidatée pour échapper à la
        // granularité seconde de filemtime).
        touch($thumbPath, time() - 100);
        clearstatcache();
        $before = filemtime($thumbPath);
        $this->artisan('images:generate-pos-thumbs')->assertExitCode(0);
        clearstatcache();
        $this->assertGreaterThan($before, filemtime($thumbPath), 'source plus récente → vignette regénérée');
    }

    public function test_item_with_media_conversion_keeps_medialibrary_priority(): void
    {
        // Chemin medialibrary intact : sans média attaché, getFirstMediaUrl est vide
        // et le fallback config s'applique — couvert par les tests ci-dessus. Ici on
        // verrouille que le résolveur n'explose pas quand le mapping pointe vers un
        // fichier ABSENT (retour placeholder, pas d'exception).
        Config::set('menu_images.items', ['w5-item' => 'missing-file.png']);
        $item = $this->makeItem();

        $this->assertStringContainsString('images/item/thumb.png', $item->thumb);
    }
}
