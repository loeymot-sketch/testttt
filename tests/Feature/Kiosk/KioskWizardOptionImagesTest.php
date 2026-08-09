<?php

namespace Tests\Feature\Kiosk;

use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Chaque choix du wizard borne s'affiche avec une photo. Quand la
 * correspondance nom → fichier manque, l'accesseur `thumb` retombe sur
 * `item-default.svg` : le client voit une case grise à la place de l'aliment.
 *
 * Relevé le 2026-08-09 sur le catalogue réel : 131 options sur 1133 étaient dans
 * ce cas, dont les DEUX PREMIÈRES ÉTAPES du wizard sandwich et bol — donc la
 * toute première chose que voit le client. Deux causes de code, corrigées ici :
 *
 *   1. `ItemVariation::getThumbAttribute` ne traitait que « Sauce », « Crudité »,
 *      « Garniture » et « Viande ». « Type de Pain » et « Base bol » tombaient
 *      dans le `else` → image par défaut, alors que les photos existaient déjà
 *      sur le disque.
 *   2. « Sauce supplémentaire » était bien déclarée dans `supplements`, mais
 *      l'accesseur retirait le préfixe « Sauce » et allait chercher
 *      « supplémentaire » dans `sauces ». Fichier présent, config juste, et
 *      42 options grises quand même.
 *
 * Les modèles sont construits EN MÉMOIRE : ce test éprouve la résolution, pas
 * un jeu de données. Il reste donc vrai sur une base vierge.
 */
class KioskWizardOptionImagesTest extends TestCase
{
    private function estParDefaut(?string $thumb): bool
    {
        $defaut = (string) Config::get('menu_images.default', 'item-default.svg');

        return $thumb === null || $thumb === '' || str_contains($thumb, $defaut);
    }

    private function variation(string $attribut, string $nom): ItemVariation
    {
        $v = new ItemVariation(['name' => $nom]);
        $v->setRelation('itemAttribute', new ItemAttribute(['name' => $attribut]));

        return $v;
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    public static function premieresEtapes(): array
    {
        return [
            'sandwich — pain'    => ['Type de Pain', 'Pain'],
            'sandwich — galette' => ['Type de Pain', 'Galette'],
            'bol — frites'       => ['Base bol', 'Frites'],
            'bol — riz'          => ['Base bol', 'Riz basmati'],
        ];
    }

    /**
     * @dataProvider premieresEtapes
     */
    public function test_les_premieres_etapes_du_wizard_ont_leur_photo(string $attribut, string $nom): void
    {
        $this->assertFalse(
            $this->estParDefaut($this->variation($attribut, $nom)->thumb),
            "« {$nom} » ({$attribut}) s'affiche en case grise. C'est la première chose "
            .'que voit le client. Vérifier la branche « Pain »/« Base » de '
            .'ItemVariation::getThumbAttribute et le bloc menu_images.bases.'
        );
    }

    public function test_une_viande_et_une_sauce_restent_illustrees(): void
    {
        // Garde-fou de non-régression sur les branches qui marchaient déjà.
        $this->assertFalse($this->estParDefaut($this->variation('Viande 1', 'Nuggets')->thumb));
        $this->assertFalse($this->estParDefaut($this->variation('Sauce (1ère Gratuite)', 'Ketchup')->thumb));
    }

    public function test_le_choix_generique_sauce_supplementaire_a_sa_photo(): void
    {
        $e = new ItemExtra(['name' => 'Sauce supplémentaire']);

        $this->assertFalse(
            $this->estParDefaut($e->thumb),
            'Piège vécu : la correspondance existe dans `supplements`, mais l’accesseur '
            .'retirait le préfixe « Sauce » et cherchait dans `sauces`. Le nom COMPLET '
            .'doit être essayé en premier.'
        );
    }

    public function test_un_supplement_nomme_reste_illustre(): void
    {
        $this->assertFalse($this->estParDefaut((new ItemExtra(['name' => 'Cheddar']))->thumb));
        $this->assertFalse($this->estParDefaut((new ItemExtra(['name' => 'Oignons cuits']))->thumb));
    }

    public function test_une_option_inconnue_retombe_bien_sur_l_image_par_defaut(): void
    {
        // Le repli doit exister : sans lui, une option inconnue n'aurait AUCUNE
        // image et casserait la mise en page au lieu d'afficher un neutre.
        $this->assertTrue(
            $this->estParDefaut((new ItemExtra(['name' => 'Ingrédient qui n’existe pas']))->thumb),
            "Le repli sur l'image par défaut doit rester en place."
        );
    }

    public function test_chaque_fichier_declare_dans_la_config_existe_sur_le_disque(): void
    {
        $base = public_path((string) Config::get('menu_images.base_path', 'images/menu'));
        $absents = [];

        foreach (['sauces', 'supplements', 'crudite_extras', 'crudites', 'viandes', 'bases', 'items', 'categories', 'addons'] as $bloc) {
            foreach ((array) Config::get("menu_images.{$bloc}", []) as $nom => $fichier) {
                if (! is_string($fichier)) {
                    continue;
                }
                if (! file_exists($base.'/'.$fichier)) {
                    $absents[] = "{$bloc}.{$nom} → {$fichier}";
                }
            }
        }

        $this->assertSame([], $absents, 'Fichiers déclarés mais absents du disque : '.implode(', ', $absents));
    }
}
