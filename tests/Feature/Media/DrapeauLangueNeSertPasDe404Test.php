<?php

namespace Tests\Feature\Media;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LE DRAPEAU DE LANGUE NE DOIT JAMAIS POINTER VERS UN FICHIER ABSENT.
 *
 * Défaut réel mesuré le 2026-08-25 : `media#1` déclarait `english.png` pour la langue
 * « Anglais », mais le fichier avait disparu du disque. L'accessor ne testait que
 * `!empty($url)` — une adresse vers un fichier absent n'est pas vide, elle passait donc le
 * garde. Résultat : 52 requêtes en 404 sur une seule campagne de captures, sur toutes les
 * pages portant le sélecteur de langue (dont la caisse).
 *
 * Le premier test ci-dessous ROUGIT sur l'ancien code : c'est celui qui compte.
 */
class DrapeauLangueNeSertPasDe404Test extends TestCase
{
    use RefreshDatabase;

    private function langue(): Language
    {
        return Language::create([
            'name' => 'Anglais',
            'code' => 'en',
            'display_mode' => 5,
            'status' => 5,
        ]);
    }

    /**
     * LE CAS QUI CASSAIT : média déclaré, fichier supprimé sous les pieds.
     *
     * @test
     */
    public function un_media_dont_le_fichier_a_disparu_retombe_sur_le_repli(): void
    {
        Storage::fake('public');
        $langue = $this->langue();

        $langue->addMedia(UploadedFile::fake()->image('english.png'))
            ->toMediaCollection('language');

        $media = $langue->refresh()->getFirstMedia('language');
        $this->assertNotNull($media, 'le média doit exister avant qu\'on supprime son fichier');

        // On supprime le FICHIER sans toucher à la ligne en base — exactement ce que des
        // tests e2e ont fait en écrivant dans `storage/app/public/1/`.
        @unlink($media->getPath());
        $this->assertFalse(is_file($media->getPath()), 'le fichier doit bien être absent');

        $url = $langue->refresh()->image;

        $this->assertStringNotContainsString(
            'english.png',
            $url,
            'RÉGRESSION : l\'accessor sert encore l\'adresse du média alors que son fichier '
            . 'a disparu — c\'est un 404 garanti sur chaque page qui affiche le drapeau.'
        );
        $this->assertStringContainsString('images/item/thumb.png', $url);
    }

    /** @test */
    public function un_media_dont_le_fichier_existe_est_bien_servi(): void
    {
        Storage::fake('public');
        $langue = $this->langue();

        $langue->addMedia(UploadedFile::fake()->image('english.png'))
            ->toMediaCollection('language');

        $media = $langue->refresh()->getFirstMedia('language');
        $this->assertTrue(is_file($media->getPath()), 'préalable : le fichier doit exister');

        $this->assertStringContainsString(
            'english.png',
            $langue->refresh()->image,
            'un média intact doit toujours être servi — le garde ne doit pas tout aplatir '
            . 'sur le repli, sinon on perd les vrais drapeaux.'
        );
    }

    /** @test */
    public function aucun_media_declare_retombe_sur_le_repli(): void
    {
        Storage::fake('public');

        $this->assertStringContainsString(
            'images/item/thumb.png',
            $this->langue()->image,
            'comportement historique conservé : sans média, on sert le repli.'
        );
    }

    /**
     * L'accessor ne doit jamais lever : une page d'admin ne tombe pas pour un drapeau.
     *
     * @test
     */
    public function l_accessor_ne_leve_jamais(): void
    {
        Storage::fake('public');
        $langue = $this->langue();

        $langue->addMedia(UploadedFile::fake()->image('english.png'))
            ->toMediaCollection('language');

        @unlink($langue->refresh()->getFirstMedia('language')->getPath());

        $url = null;
        try {
            $url = $langue->refresh()->image;
        } catch (\Throwable $e) {
            $this->fail('l\'accessor a levé « ' . $e->getMessage() . ' » — inacceptable pour un drapeau');
        }

        $this->assertIsString($url);
        $this->assertNotSame('', $url, 'le drapeau doit toujours avoir une adresse affichable');
    }
}
