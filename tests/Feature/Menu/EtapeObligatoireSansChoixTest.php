<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Services\Menu\EtapesBloquantesDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [INCIDENT CAISSE 2026-09-03] Le 2026-09-03 à 22:27:08, une seule opération a éteint
 * les 45 lignes de viande de Cayenne / Suprême / Sandwich Classique en production. Les
 * choix ont été retirés, l'obligation « Viande 1 » (min_select=1) est restée : les trois
 * produits phares du restaurant sont devenus INVENDABLES, sans qu'aucun test ne rougisse
 * et sans qu'aucun écran ne l'annonce. Le propriétaire l'a découvert en service.
 *
 * Cette suite verrouille la DÉTECTION de cette famille de défaut, pour qu'une édition de
 * carte ne puisse plus jamais mettre le service à terre en silence. Deux formes :
 *
 *   A. RÉSERVÉ À UNE AUTRE SURFACE — des choix actifs existent (donc l'étape est
 *      obligatoire pour le serveur, cf. MultiVariationConstraint::requiredAttributesByOrderedItem
 *      qui dérive l'obligation des variations ACTIVES) mais aucun n'est visible sur la
 *      surface consultée. C'est le piège qui attend la règle demandée par le propriétaire
 *      « les viandes du Cayenne seulement à la caisse » : la borne exigerait une viande
 *      qu'elle n'a pas le droit d'afficher.
 *
 *   B. TOUS LES CHOIX ÉTEINTS — les lignes existent encore mais aucune n'est active.
 *      C'est littéralement l'incident du 2026-09-03.
 *
 * Un produit sain ne doit JAMAIS être signalé : un faux positif ici ferait perdre
 * confiance dans le seul instrument qui protège le service.
 */
class EtapeObligatoireSansChoixTest extends TestCase
{
    use RefreshDatabase;

    private function categorie(): ItemCategory
    {
        return ItemCategory::create([
            'name'   => 'Sandwichs',
            'slug'   => 'sandwichs-'.uniqid(),
            'status' => Status::ACTIVE,
        ]);
    }

    private function produit(string $nom): Item
    {
        return Item::create([
            'name'             => $nom,
            'slug'             => strtolower(str_replace(' ', '-', $nom)).'-'.uniqid(),
            'item_category_id' => $this->categorie()->id,
            'price'            => 7.50,
            'status'           => Status::ACTIVE,
        ]);
    }

    private function attributObligatoire(string $nom = 'Viande 1'): ItemAttribute
    {
        return ItemAttribute::create([
            'name'       => $nom,
            'min_select' => 1,
            'max_select' => 3,
            'status'     => Status::ACTIVE,
        ]);
    }

    /** @param array<int,string>|null $visibleSur */
    private function choix(Item $i, ItemAttribute $a, string $nom, int $statut, ?array $visibleSur = null): ItemVariation
    {
        return ItemVariation::create([
            'item_id'           => $i->id,
            'item_attribute_id' => $a->id,
            'name'              => $nom,
            'price'             => 0,
            'status'            => $statut,
            'visible_on'        => $visibleSur,
        ]);
    }

    /** @test */
    public function un_produit_sain_n_est_jamais_signale(): void
    {
        $item = $this->produit('Tacos M');
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::ACTIVE);
        $this->choix($item, $attr, 'Viande Hachée', Status::ACTIVE);

        $constats = (new EtapesBloquantesDetector())->detecter('kiosk');

        $this->assertSame([], $constats, 'aucun faux positif sur un produit normal');
    }

    /** @test */
    public function forme_b_tous_les_choix_eteints_bloque_le_produit(): void
    {
        // Reproduction exacte du 2026-09-03 22:27:08 : les lignes restent, toutes éteintes.
        $item = $this->produit('Cayenne');
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::INACTIVE);
        $this->choix($item, $attr, 'Viande Hachée', Status::INACTIVE);

        $constats = (new EtapesBloquantesDetector())->detecter('kiosk');

        $this->assertCount(1, $constats, 'le produit éteint doit être signalé');
        $this->assertSame($item->id, $constats[0]['item_id']);
        $this->assertSame('Viande 1', $constats[0]['etape']);
        $this->assertSame('tous_les_choix_eteints', $constats[0]['raison']);
        $this->assertSame(0, $constats[0]['choix_disponibles']);
    }

    /** @test */
    public function forme_a_choix_reserves_a_la_caisse_bloque_la_borne_mais_pas_la_caisse(): void
    {
        // Le piège de la règle demandée : « les viandes du Cayenne seulement à la caisse ».
        $item = $this->produit('Cayenne');
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::ACTIVE, ['pos']);
        $this->choix($item, $attr, 'Viande Hachée', Status::ACTIVE, ['pos']);

        $surBorne = (new EtapesBloquantesDetector())->detecter('kiosk');
        $surCaisse = (new EtapesBloquantesDetector())->detecter('pos');

        $this->assertCount(1, $surBorne, 'la borne exige une viande qu\'elle ne peut pas afficher');
        $this->assertSame('reserve_a_une_autre_surface', $surBorne[0]['raison']);
        $this->assertSame(0, $surBorne[0]['choix_disponibles']);

        $this->assertSame([], $surCaisse, 'la caisse, elle, voit les choix : rien à signaler');
    }

    /** @test */
    public function une_etape_facultative_n_est_pas_bloquante(): void
    {
        $item = $this->produit('Bol Frites');
        $facultatif = ItemAttribute::create([
            'name' => 'Viande 4', 'min_select' => 0, 'max_select' => 1, 'status' => Status::ACTIVE,
        ]);
        $this->choix($item, $facultatif, 'Tenders', Status::INACTIVE);

        $constats = (new EtapesBloquantesDetector())->detecter('kiosk');

        $this->assertSame([], $constats, 'sans obligation, aucun blocage possible');
    }

    /** @test */
    public function un_produit_inactif_n_est_pas_signale(): void
    {
        $item = $this->produit('Ancien Sandwich');
        $item->update(['status' => Status::INACTIVE]);
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::INACTIVE);

        $constats = (new EtapesBloquantesDetector())->detecter('kiosk');

        $this->assertSame([], $constats, 'un produit retiré de la carte ne bloque personne');
    }

    /** @test */
    public function la_commande_sort_en_echec_quand_un_produit_est_invendable(): void
    {
        // La commande doit MORDRE : sans code de sortie non nul, elle ne peut servir
        // de porte dans un script de modification de carte.
        $item = $this->produit('Cayenne');
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::INACTIVE);

        $this->artisan('menu:verifier-etapes', ['--surface' => ['kiosk']])
            ->expectsOutputToContain('BLOQUÉ')
            ->assertExitCode(1);
    }

    /** @test */
    public function la_commande_reussit_sur_une_carte_saine(): void
    {
        $item = $this->produit('Tacos M');
        $attr = $this->attributObligatoire();
        $this->choix($item, $attr, 'Poulet mariné', Status::ACTIVE);

        $this->artisan('menu:verifier-etapes', ['--surface' => ['kiosk']])
            ->assertExitCode(0);
    }

    /** @test */
    public function le_minimum_compte_pas_seulement_la_presence(): void
    {
        // min_select = 2 avec un seul choix visible : insatisfiable, donc bloquant.
        $item = $this->produit('Méga');
        $attr = ItemAttribute::create([
            'name' => 'Viande 1', 'min_select' => 2, 'max_select' => 3, 'status' => Status::ACTIVE,
        ]);
        $this->choix($item, $attr, 'Poulet mariné', Status::ACTIVE);

        $constats = (new EtapesBloquantesDetector())->detecter('kiosk');

        $this->assertCount(1, $constats, 'un minimum de 2 avec 1 seul choix est insatisfiable');
        $this->assertSame('choix_insuffisants', $constats[0]['raison']);
        $this->assertSame(1, $constats[0]['choix_disponibles']);
        $this->assertSame(2, $constats[0]['minimum_exige']);
    }
}
