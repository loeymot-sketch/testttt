<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [ONB-07 2026-08-27] Le menu de la borne ne repart pas en N+1 sur les images.
 *
 * Mesuré sur la base de travail AVANT correctif : **788 requêtes SQL en 404 ms** pour
 * construire l'écran d'accueil de la borne — dont **765 sur la table `media`** : 433
 * pour `ItemVariation`, 332 pour `ItemExtra`. Une requête par variante et par
 * supplément.
 *
 * Le plus instructif est pourquoi ça avait survécu à deux corrections. Le commentaire
 * du code affirmait :
 *
 *     « Only Item implements HasMedia. Variations/Extras do NOT have media »
 *
 * C'est faux : `ItemVariation:15` et `ItemExtra:13` déclarent tous deux
 * `implements HasMedia`, et leurs accesseurs appellent `getFirstMediaUrl()`. Deux
 * personnes ont chargé `Item` et `addonItem` en se fiant à cette phrase, et sont
 * passées à côté des deux relations qui coûtaient réellement.
 *
 * Ce test compte les requêtes plutôt que de vérifier une ligne de code : une
 * assertion sur `with('variations.media')` casserait au moindre remaniement tout en
 * laissant revenir le défaut par un autre chemin.
 */
class MenuBorneSansNPlusUnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Plafond volontairement large. On ne mesure pas une performance, on garde un
     * ORDRE DE GRANDEUR : avec le défaut, ce chiffre partait à plusieurs centaines.
     */
    private const PLAFOND_REQUETES = 45;

    private function menuAvecDesVariantesEtDesSupplements(int $nbArticles = 12): Branch
    {
        $filiale = Branch::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Filiale de test', 'email' => 't@example.test',
                'phone' => '+33600000000', 'city' => 'Lille',
                'state' => 'Hauts-de-France', 'zip_code' => '59000',
                'address' => '1 rue de Test', 'status' => Status::ACTIVE,
            ]
        );

        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        for ($i = 0; $i < $nbArticles; $i++) {
            $article = Item::factory()->create([
                'item_category_id' => $categorie->id,
                'status'           => Status::ACTIVE,
            ]);

            // Deux variantes et deux suppléments chacun : c'est exactement ce qui
            // déclenchait une requête media par ligne. Créés directement — ces deux
            // modèles n'ont pas d'usine dans le projet.
            $attribut = ItemAttribute::firstOrCreate(
                ['name' => 'Taille (test)'],
                ['status' => Status::ACTIVE]
            );

            for ($v = 0; $v < 2; $v++) {
                ItemVariation::create([
                    'item_id'           => $article->id,
                    'item_attribute_id' => $attribut->id,
                    'name'              => "Variante {$i}-{$v}",
                    'price'             => 1.50,
                    'status'            => Status::ACTIVE,
                ]);

                ItemExtra::create([
                    'item_id' => $article->id,
                    'name'    => "Supplement {$i}-{$v}",
                    'price'   => 0.50,
                    'status'  => Status::ACTIVE,
                ]);
            }
        }

        return $filiale;
    }

    public function test_le_nombre_de_requetes_ne_croit_pas_avec_le_catalogue(): void
    {
        $filiale = $this->menuAvecDesVariantesEtDesSupplements(12);

        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            $requetes[] = $q->sql;
        });

        app(KioskMenuService::class)->build($filiale);

        $mediaParModele = [];
        foreach ($requetes as $sql) {
            if (str_contains($sql, 'from `media`')) {
                $mediaParModele[] = $sql;
            }
        }

        $this->assertLessThan(
            self::PLAFOND_REQUETES,
            count($requetes),
            sprintf(
                "Le menu borne a emis %d requetes pour 12 articles (plafond %d). "
                . "Dont %d sur la table `media` — signe d'un chargement image par image. "
                . "Verifier les relations `variations.media` et `extras.media` dans "
                . "KioskMenuService : ItemVariation et ItemExtra implementent HasMedia, "
                . "contrairement a ce qu'affirmait un ancien commentaire.",
                count($requetes),
                self::PLAFOND_REQUETES,
                count($mediaParModele)
            )
        );
    }

    public function test_doubler_le_catalogue_ne_double_pas_les_requetes(): void
    {
        // Le vrai test d'un N+1 : la courbe, pas le point. Un chargement correct
        // emet le meme nombre de requetes quelle que soit la taille du catalogue.
        $filiale = $this->menuAvecDesVariantesEtDesSupplements(6);

        $compte = function (Branch $b): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            app(KioskMenuService::class)->build($b);

            return $n;
        };

        $petit = $compte($filiale);

        // On double le catalogue.
        $this->menuAvecDesVariantesEtDesSupplements(6);
        $grand = $compte($filiale->fresh());

        $this->assertLessThanOrEqual(
            $petit + 5,
            $grand,
            "Doubler le catalogue a fait passer les requetes de {$petit} a {$grand}. "
            . "Un chargement par anticipation correct ne bouge presque pas ; une "
            . "croissance proportionnelle signale un N+1."
        );
    }
}
