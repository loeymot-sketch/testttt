<?php

namespace Tests\Feature\Frontend;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W7-D D-014 + D-017 2026-11-04]
 *  - D-014 : `GET /api/frontend/offer/show/{slug}` répondait 500 « Server Error » pour TOUT slug (TypeError `null`
 *    passé à `OfferService::show(Offer)`, hors du `catch (Exception)`), donc 100 % des appels tant que la table est vide.
 *  - D-017 : tout `ModelNotFoundException` disait « Commande introuvable » (code ORDER_NOT_FOUND), même pour une
 *    fiche produit ou une offre. Le code ORDER_NOT_FOUND reste réservé aux commandes.
 */
class IntrouvableTypeEtOffre404Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);
    }

    /** @test */
    public function une_offre_inconnue_repond_404_json_et_jamais_500(): void
    {
        foreach (['slug-inconnu', '1', '%00'] as $slug) {
            $this->getJson('/api/frontend/offer/show/' . $slug)
                ->assertStatus(404)
                ->assertJson(['success' => false, 'code' => 'NOT_FOUND', 'message' => __('all.message.resource_not_found')]);
        }
    }

    /** @test */
    public function un_article_inconnu_n_est_pas_une_commande_introuvable(): void
    {
        $r = $this->getJson('/api/frontend/item/details/999999');

        $r->assertStatus(404);
        $r->assertJson(['code' => 'NOT_FOUND']);
        $this->assertStringNotContainsString('Commande', (string) $r->json('message'));
    }

    /** @test */
    public function une_commande_inconnue_garde_son_code_order_not_found(): void
    {
        $u = User::forceCreate([
            'name' => 'Client W7', 'email' => 'client-w7-404@example.test', 'username' => 'client-w7-404',
            'password' => bcrypt('x'), 'status' => Status::ACTIVE, 'branch_id' => 0,
        ]);
        Sanctum::actingAs($u, ['kiosk:order']);

        $this->getJson('/api/frontend/order/show/999999')
            ->assertStatus(404)
            ->assertJson(['code' => 'ORDER_NOT_FOUND', 'message' => __('all.message.order_not_found')]);
    }
}
