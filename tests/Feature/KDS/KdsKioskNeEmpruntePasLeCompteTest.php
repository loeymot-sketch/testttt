<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderType;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [REVUE ADVERSE 2026-10-19] L'écran cuisine affichait le compte TECHNIQUE de la borne (« Compte Borne… »)
 * comme « client » d'une commande sans nom, alors que le ticket et la caisse n'affichent rien. Un nom faux
 * fait chercher quelqu'un qui n'existe pas.
 */
class KdsKioskNeEmpruntePasLeCompteTest extends TestCase
{
    use RefreshDatabase;

    private function carte(array $attrs, ?User $user): array
    {
        $order = (new Order)->forceFill(array_merge(['order_type' => OrderType::TAKEAWAY], $attrs));
        $order->setRelation('orderItems', new \Illuminate\Database\Eloquent\Collection);
        $order->setRelation('user', $user);

        return (new KDSOrderDetailsResource($order))->toArray(request());
    }

    public function test_une_commande_borne_sans_nom_n_affiche_aucun_client_en_cuisine(): void
    {
        $technique = (new User)->forceFill(['name' => 'Compte Borne Technique']);

        $this->assertNull($this->carte(['source_surface' => 'kiosk'], $technique)['customer']);
    }

    public function test_une_commande_borne_avec_nom_affiche_ce_nom(): void
    {
        $technique = (new User)->forceFill(['name' => 'Compte Borne Technique']);
        $c = $this->carte(['source_surface' => 'kiosk', 'pos_customer_name' => 'Karim'], $technique)['customer'];

        $this->assertSame('Karim', $c['name']);
    }

    public function test_le_site_garde_le_nom_du_compte_sauf_un_nom_de_substitution(): void
    {
        $this->assertSame('Sofia M.', $this->carte(['source_surface' => 'web'], (new User)->forceFill(['name' => 'Sofia M.']))['customer']['name']);
        $this->assertNull($this->carte(['source_surface' => 'web'], (new User)->forceFill(['name' => 'Guest User']))['customer'], '« Guest User » n\'est pas une personne');
    }
}
