<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** [CAYENNE HYGIÈNE 2026-10-18 · demande du propriétaire n°1] Favoris : « mes produits du jour », partagés, sans toucher à la fiche. */
class DlcFavoriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function caisse(int $branch = 1): User
    {
        $user = User::factory()->create(['branch_id' => $branch]);
        $user->givePermissionTo(['pos-orders']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function product(string $name = 'Oignons rouges émincés', int $branch = 1, array $extra = []): DlcLabelProduct
    {
        $p = new DlcLabelProduct($extra + [
            'name' => $name, 'category' => 'Légumes & crudités', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days',
            'actions' => [['key' => 'prepared', 'value' => 3, 'unit' => 'days']], 'storage' => '0 à +4 °C', 'is_active' => true, 'sort_order' => 1,
        ]);
        $p->branch_id = $branch;
        $p->save();

        return $p->fresh();
    }

    public function test_un_produit_n_est_pas_favori_par_defaut_puis_l_est_puis_ne_l_est_plus(): void
    {
        $this->caisse();
        $p = $this->product();
        $this->assertFalse($p->is_favorite);
        $this->getJson('/api/admin/labels/products')->assertOk()->assertJsonPath('data.0.is_favorite', false);

        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->getJson('/api/admin/labels/products')->assertJsonPath('data.0.is_favorite', true);
        $this->assertTrue($p->fresh()->is_favorite);

        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => false])->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->assertFalse($p->fresh()->is_favorite);
    }

    public function test_demander_deux_fois_la_meme_valeur_ne_change_rien(): void
    {
        $this->caisse();
        $p = $this->product();
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertOk();
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->assertSame(1, DlcLabelProduct::query()->where('is_favorite', true)->count());
    }

    public function test_mettre_en_favori_ne_modifie_pas_la_fiche_ni_ne_fait_tomber_la_validation_des_durees(): void
    {
        $this->caisse();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00'));
        $p = $this->product('Tomates tranchées', 1, ['durations_checked_at' => now(), 'durations_checked_by' => 1]);
        $avant = $p->updated_at;
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00'));

        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertOk();

        $p->refresh();
        $this->assertTrue($p->is_favorite);
        $this->assertNotNull($p->durations_checked_at, 'la validation des durées par le responsable reste intacte');
        $this->assertSame('validated', $p->durationReview());
        $this->assertEquals($avant, $p->updated_at, 'updated_at ne bouge pas : ce n\'est pas une modification de fiche');
    }

    public function test_le_produit_d_un_autre_restaurant_est_introuvable(): void
    {
        $this->caisse(1);
        $autre = $this->product('Produit du site 2', 2);
        $this->putJson("/api/admin/labels/products/{$autre->id}/favorite", ['favorite' => true])->assertNotFound();
        $this->assertFalse($autre->fresh()->is_favorite);
    }

    public function test_la_valeur_est_obligatoire_et_booleenne(): void
    {
        $this->caisse();
        $p = $this->product();
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", [])->assertStatus(422)->assertJsonValidationErrors('favorite');
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => 'peut-être'])->assertStatus(422);
    }

    public function test_sans_authentification_ni_droit_c_est_refuse(): void
    {
        $p = $this->product();
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertUnauthorized();

        $nobody = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($nobody, ['*']);
        $this->putJson("/api/admin/labels/products/{$p->id}/favorite", ['favorite' => true])->assertForbidden();
        $this->assertFalse($p->fresh()->is_favorite);
    }

    public function test_modifier_une_fiche_ne_peut_pas_glisser_un_favori_en_douce(): void
    {
        $this->caisse();
        $p = $this->product();
        $this->putJson("/api/admin/labels/products/{$p->id}", [
            'name' => $p->name, 'category' => $p->category, 'storage' => $p->storage, 'is_favorite' => true,
            'actions' => [['key' => 'prepared', 'value' => 3, 'unit' => 'days']], 'allergens' => [], 'no_allergens' => true,
            'default_copies' => 1, 'is_active' => true,
        ])->assertOk();
        $this->assertFalse($p->fresh()->is_favorite, 'le favori ne se change que par son propre point d\'entrée');
    }
}
