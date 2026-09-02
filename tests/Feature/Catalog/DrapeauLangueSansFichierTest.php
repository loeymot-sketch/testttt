<?php

namespace Tests\Feature\Catalog;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [2026-09-02] La fiche produit servait un 404 `/storage/1/english.png` : la ligne media existait,
 * le fichier non, et l'accesseur ne regardait que la ligne. Un 404 d'image ne lève rien côté
 * serveur — seul le relevé réseau d'une capture l'attrape. Ce banc le fige côté modèle.
 */
class DrapeauLangueSansFichierTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_ligne_media_sans_fichier_retombe_sur_la_vignette_par_defaut(): void
    {
        Storage::fake('public');

        $language = Language::create([
            'name' => 'Anglais',
            'code' => 'en',
            'display_mode' => 1,
            'status' => 5,
        ]);

        $language->addMedia(UploadedFile::fake()->image('english.png'))
            ->toMediaCollection('language');

        // Le fichier est là : on sert bien son URL.
        $this->assertStringContainsString('english', $language->refresh()->image);

        // On efface le FICHIER, on garde la LIGNE — exactement l'état trouvé en base le 2026-09-02.
        $media = $language->getFirstMedia('language');
        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

        $this->assertSame(
            asset('images/item/thumb.png'),
            $language->refresh()->image,
            'une ligne media orpheline doit retomber sur la vignette par défaut, pas produire un 404',
        );
    }
}
