<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CAISSE CONTRÔLE 2026-09-02] Le nom affiché en caisse doit désigner un CLIENT.
 *
 * CONSTAT AU NAVIGATEUR, pas en lecture de code : le nouveau tiroir de contrôle affichait
 * « 👤 Admin Le Cayenne » comme client d'une commande BORNE. La cause était le repli de
 * `displayCustomerName()` sur `$this->user?->name`, qui ne connaît pas le canal : sur une commande
 * borne, ce compte est l'ancre TECHNIQUE de la borne ; sur une commande de comptoir, c'est le
 * compte du CAISSIER lui-même.
 *
 * POURQUOI C'EST PIRE QUE PAS DE NOM. Le propriétaire décrit sa douleur ainsi : « je me perds
 * toujours pour les commandes pas encaissées entre les clients qui viennent ». Un nom absent
 * laisse le caissier se rabattre sur le numéro et le contenu — ce qui marche. Un nom FAUX le fait
 * chercher une personne qui n'existe pas, et le fait douter du numéro qu'il a sous les yeux.
 *
 * LA RÈGLE N'EST PAS NOUVELLE : c'est celle de `displayCustomerPhone()`, écrite le 2026-07-31 avec
 * le propriétaire — « borne et comptoir, où le client est physiquement devant le caissier,
 * renvoient null ». Le nom suit désormais le téléphone, et ce banc épingle les DEUX côtés : ce qui
 * disparaît, et surtout ce qui doit continuer d'apparaître.
 */
class SimpleOrderResourceNomClientCanalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function nomAffiche(Order $order): ?string
    {
        $order->load(['orderItems', 'user', 'transaction']);

        return (new SimpleOrderResource($order))->resolve()['customer_name'];
    }

    private function commande(array $attributs, string $nomDuCompte = 'Admin Le Cayenne'): Order
    {
        $branch = Branch::first() ?? Branch::factory()->create();
        $porteur = User::factory()->create(['name' => $nomDuCompte, 'branch_id' => $branch->id]);

        return Order::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id'   => $porteur->id,
            'order_type' => OrderType::TAKEAWAY,
        ], $attributs));
    }

    /** @test */
    public function une_commande_borne_sans_nom_saisi_n_emprunte_pas_le_nom_du_compte_technique(): void
    {
        $order = $this->commande([
            'source_surface'    => 'kiosk',
            'pos_customer_name' => null,
        ]);

        $this->assertNull($this->nomAffiche($order));
    }

    /** @test */
    public function une_vente_au_comptoir_n_affiche_pas_le_nom_du_caissier_comme_client(): void
    {
        $order = $this->commande([
            'source_surface'    => 'pos',
            'order_type'        => OrderType::POS,
            'pos_customer_name' => null,
        ], 'Caissier Le Cayenne');

        $this->assertNull($this->nomAffiche($order));
    }

    /** @test */
    public function un_nom_saisi_pour_la_commande_prime_sur_tous_les_canaux(): void
    {
        // C'est le cas d'usage du propriétaire : « j'ai pris son nom ». Il ne doit JAMAIS être
        // perdu — y compris sur les canaux où le repli sur le compte est désormais coupé.
        foreach (['kiosk', 'pos', 'phone', 'web'] as $canal) {
            $order = $this->commande([
                'source_surface'    => $canal,
                'pos_customer_name' => 'Sofiane',
            ]);

            $this->assertSame('Sofiane', $this->nomAffiche($order), "canal {$canal}");
        }
    }

    /** @test */
    public function les_canaux_ou_le_client_est_absent_gardent_le_nom_du_compte(): void
    {
        // Là, le titulaire du compte EST le client, et il n'est pas devant le caissier : son nom
        // est la seule prise pour l'identifier au retrait ou pour le rappeler.
        foreach (['web', 'online', 'phone'] as $canal) {
            $order = $this->commande([
                'source_surface'    => $canal,
                'pos_customer_name' => null,
            ], 'Julie Bernard');

            $this->assertSame('Julie Bernard', $this->nomAffiche($order), "canal {$canal}");
        }
    }

    /** @test */
    public function une_livraison_garde_le_nom_du_compte_meme_sans_surface_declaree(): void
    {
        $order = $this->commande([
            'order_type'        => OrderType::DELIVERY,
            'source_surface'    => null,
            'pos_customer_name' => null,
        ], 'Julie Bernard');

        $this->assertSame('Julie Bernard', $this->nomAffiche($order));
    }

    /** @test */
    public function un_nom_saisi_qui_n_est_que_des_espaces_ne_ressuscite_pas_le_compte(): void
    {
        $order = $this->commande([
            'source_surface'    => 'kiosk',
            'pos_customer_name' => '   ',
        ]);

        $this->assertNull($this->nomAffiche($order));
    }
}
