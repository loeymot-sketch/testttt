<?php

namespace Tests\Feature\Sentinels;

use App\Models\Item;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [AUDIT 2026-08-12] Tout produit VENDU doit avoir une photo.
 *
 * LE DÉFAUT QUE CETTE SENTINELLE EMPÊCHE
 * --------------------------------------
 * `Item::getThumbAttribute()` cherche l'image dans `config/menu_images.php`, indexée par le slug
 * du produit, et ne lit QUE `items` + `addons` — jamais `categories`. Un slug absent ne casse
 * rien, ne lève aucune erreur, n'apparaît dans aucun journal : il sert la vignette par défaut.
 * Silencieux côté serveur, parfaitement visible côté client, qui voit un « produit sans photo »
 * au milieu de voisins illustrés — borne, caisse et site.
 *
 * Arrivé le 2026-08-12 : « Sandwich Classique » et « Galette Classique », créés le jour même,
 * n'avaient pas leur entrée. Les fichiers image existaient déjà ; il ne manquait que la
 * correspondance. Aucun test ne l'a vu — trouvé en LISANT une capture d'écran.
 *
 * POURQUOI DEUX TESTS ET NON UN
 * -----------------------------
 * Mon premier jet interrogeait directement le catalogue. Il échouait en erreur : la suite tourne
 * sur une base isolée qui n'a pas la table `items`. Et le « réparer » en le faisant passer sur
 * base vide en aurait fait un test incapable d'échouer — exactement ce qu'on cherche à éviter.
 *
 * On sépare donc les deux moitiés :
 *   1. l'intégrité de la table des images (chaque entrée pointe un fichier réel) — vérifiable
 *      PARTOUT, y compris en intégration continue, et capable d'échouer ;
 *   2. la couverture du catalogue réel — n'a de sens que là où le catalogue existe (poste de
 *      développement, préproduction), donc explicitement ignorée ailleurs.
 */
class ItemImageCoverageSentinelTest extends TestCase
{
    /**
     * @test
     * Moitié 1 — chaque image déclarée doit exister sur le disque.
     *
     * Une entrée qui pointe un fichier absent produit le même symptôme qu'une entrée manquante
     * (vignette par défaut), mais se déclare correcte à la lecture du fichier de configuration.
     */
    public function chaque_image_declaree_existe_sur_le_disque(): void
    {
        $base = Config::get('menu_images.base_path', 'images/menu');
        $declarees = Config::get('menu_images.items', [])
            + Config::get('menu_images.addons', [])
            + Config::get('menu_images.categories', []);

        $this->assertNotEmpty($declarees, 'la table des images ne doit jamais être vide');

        $introuvables = [];
        foreach ($declarees as $slug => $fichier) {
            if (! is_file(public_path("{$base}/{$fichier}"))) {
                $introuvables[] = "{$slug} → {$fichier}";
            }
        }

        $this->assertSame(
            [],
            $introuvables,
            "Ces entrées de config/menu_images.php pointent un fichier ABSENT — le client verra "
                ."une vignette générique :\n  - ".implode("\n  - ", $introuvables)
        );
    }

    /**
     * @test
     * Moitié 2 — aucun produit en vente ne doit être absent de la table des images.
     *
     * Ignorée là où le catalogue n'existe pas : mieux vaut un test franchement ignoré qu'un test
     * vert par absence de données.
     */
    public function tout_produit_actif_possede_une_image(): void
    {
        if (! Schema::hasTable('items')) {
            $this->markTestSkipped('Pas de table `items` sur cette base — sentinelle applicable au catalogue réel.');
        }

        $catalogue = Item::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', 5)
            ->get(['id', 'name', 'slug']);

        if ($catalogue->isEmpty()) {
            $this->markTestSkipped('Catalogue vide — rien à garder sur cette base.');
        }

        // Miroir EXACT de la résolution du modèle : `items` + `addons`, jamais `categories`.
        $images = Config::get('menu_images.items', []) + Config::get('menu_images.addons', []);

        $sansImage = $catalogue
            ->reject(fn ($item) => isset($images[$item->slug]))
            ->map(fn ($item) => "#{$item->id} {$item->name} (slug={$item->slug})")
            ->values()
            ->all();

        $this->assertSame(
            [],
            $sansImage,
            "Ces produits sont EN VENTE sans photo :\n  - ".implode("\n  - ", $sansImage)
                ."\nAjouter leur slug dans config/menu_images.php (tableau `items`)."
        );
    }
}
