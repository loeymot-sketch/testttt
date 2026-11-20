<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Support\Labels\RecommendedDlcCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] La migration `duration_source` rattrape les produits du catalogue déjà installés en production :
 * sans elle, 95 durées de départ passeraient pour des durées du restaurant, et plus rien ne dirait qu'elles attendent d'être validées.
 */
class DlcDurationSourceBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function lancer(): void
    {
        (require base_path('database/migrations/2026_10_17_150000_add_duration_source_to_dlc_label_products.php'))->up();
    }

    private function fiche(string $name, array $actions, array $extra = []): DlcLabelProduct
    {
        Branch::query()->firstOrCreate(['id' => 1], Branch::factory()->make(['id' => 1])->toArray());
        $p = new DlcLabelProduct($extra + [
            'name' => $name, 'category' => 'Légumes & crudités',
            'shelf_life_value' => $actions[0]['value'], 'shelf_life_unit' => $actions[0]['unit'],
            'actions' => $actions, 'storage' => '0 à +4 °C', 'is_active' => true, 'sort_order' => 1,
        ]);
        $p->branch_id = 1;
        $p->save();

        return $p->fresh();
    }

    private function depart(): array
    {
        foreach (RecommendedDlcCatalog::all() as $row) {
            if ($row['name'] === 'Oignons rouges émincés') return $row;
        }
        $this->fail('Oignons rouges émincés absent du catalogue recommandé');
    }

    public function test_un_produit_du_catalogue_jamais_valide_et_aux_durees_de_depart_est_marque_valeur_de_depart(): void
    {
        $row = $this->depart();
        $p = $this->fiche($row['name'], $row['actions']);
        $this->assertNull($p->duration_source);
        $avant = $p->updated_at;

        $this->lancer();

        $p->refresh();
        $this->assertSame(DlcLabelProduct::SOURCE_STARTING, $p->duration_source);
        $this->assertSame('starting', $p->durationReview());
        $this->assertEquals($avant, $p->updated_at, 'la fiche n\'a pas été modifiée par quelqu\'un : updated_at ne bouge pas');
    }

    public function test_le_nom_est_reconnu_malgre_la_casse_et_les_espaces(): void
    {
        $row = $this->depart();
        $p = $this->fiche('  OIGNONS   rouges émincés ', $row['actions']);

        $this->lancer();

        $this->assertSame(DlcLabelProduct::SOURCE_STARTING, $p->fresh()->duration_source);
    }

    public function test_un_produit_fait_a_la_main_reste_saisi_par_le_restaurant(): void
    {
        $p = $this->fiche('Ma sauce secrète', [['key' => 'opened', 'value' => 3, 'unit' => 'days']]);

        $this->lancer();

        $this->assertNull($p->fresh()->duration_source);
        $this->assertSame('own', $p->fresh()->durationReview());
    }

    public function test_une_duree_changee_par_le_restaurant_n_est_pas_une_valeur_de_depart(): void
    {
        $row = $this->depart();
        $p = $this->fiche($row['name'], [['key' => 'prepared', 'value' => 1, 'unit' => 'days']]);

        $this->lancer();

        $this->assertNull($p->fresh()->duration_source);
    }

    public function test_un_produit_deja_valide_n_est_pas_touche(): void
    {
        $row = $this->depart();
        $p = $this->fiche($row['name'], $row['actions'], ['durations_checked_at' => now()->subDay()]);

        $this->lancer();

        $this->assertNull($p->fresh()->duration_source);
        $this->assertSame('validated', $p->fresh()->durationReview());
    }

    public function test_une_source_deja_ecrite_est_conservee_et_la_relance_ne_change_rien(): void
    {
        $row = $this->depart();
        $a = $this->fiche($row['name'], $row['actions'], ['duration_source' => 'Plan de maîtrise sanitaire']);
        $b = $this->fiche('Tomates tranchées', [['key' => 'prepared', 'value' => 48, 'unit' => 'hours']]);

        $this->lancer();
        $this->lancer();

        $this->assertSame('Plan de maîtrise sanitaire', $a->fresh()->duration_source);
        $this->assertSame(DlcLabelProduct::SOURCE_STARTING, $b->fresh()->duration_source);
    }
}
