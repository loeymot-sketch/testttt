<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderType;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [UBER-PHOTO 2026-08-10 · owner « le nom du client »] Le nom annoncé sur la carte de cuisine.
 *
 * Défaut constaté À L'ÉCRAN (capture Playwright, pas déduit du code) : une commande Uber affichait
 * « Uber Eats » et le téléphone « 0000000042 ». Ce sont l'identité et le numéro de l'utilisateur
 * TECHNIQUE qui sert d'ancrage à la commande — le vrai prénom du client était pourtant déjà scellé
 * sur la commande, et déjà imprimé sur le ticket. L'écran et le papier doivent dire la même chose.
 *
 * Le numéro factice était le plus gênant : affiché à côté d'une commande de livraison, quelqu'un
 * finit par le composer.
 */
class KdsCustomerNameFromOrderTest extends TestCase
{
    use RefreshDatabase;

    private function resource(Order $order): array
    {
        return (new KDSOrderDetailsResource($order))->toArray(request());
    }

    private function order(array $attrs, ?User $user = null): Order
    {
        $order = (new Order)->forceFill(array_merge([
            'order_type' => OrderType::DELIVERY,
        ], $attrs));
        // Collection ELOQUENT (et non `collect()`) : la ressource appelle `loadMissing()` dessus.
        $order->setRelation('orderItems', new \Illuminate\Database\Eloquent\Collection);
        $order->setRelation('user', $user);

        return $order;
    }

    /** @test */
    public function le_nom_porte_par_la_commande_prime_sur_le_compte_technique(): void
    {
        $technique = (new User)->forceFill(['name' => 'Uber Eats', 'phone' => '0000000042']);

        $data = $this->resource($this->order([
            'source_surface' => 'uber_eats',
            'pos_customer_name' => 'Karim B.',
        ], $technique));

        $this->assertSame('Karim B.', $data['customer']['name']);
        $this->assertNull(
            $data['customer']['phone'],
            'Le téléphone factice de l\'ancre technique ne doit JAMAIS s\'afficher : quelqu\'un finirait par le composer.'
        );
    }

    /** @test */
    public function un_client_reel_garde_son_nom_et_son_telephone_en_livraison(): void
    {
        $client = (new User)->forceFill(['name' => 'Sofia M.', 'phone' => '0612345678']);

        $data = $this->resource($this->order(['source_surface' => 'web'], $client));

        $this->assertSame('Sofia M.', $data['customer']['name']);
        $this->assertSame('0612345678', $data['customer']['phone']);
    }

    /** @test */
    public function hors_livraison_le_telephone_reste_masque(): void
    {
        $client = (new User)->forceFill(['name' => 'Sofia M.', 'phone' => '0612345678']);

        $data = $this->resource($this->order([
            'order_type' => OrderType::TAKEAWAY,
            'source_surface' => 'pos',
        ], $client));

        $this->assertSame('Sofia M.', $data['customer']['name']);
        $this->assertNull($data['customer']['phone'], 'Minimisation des données : le numéro ne sert qu\'aux livraisons.');
    }

    /** @test */
    public function sans_aucun_nom_la_carte_n_affiche_pas_de_bloc_client_vide(): void
    {
        $data = $this->resource($this->order(['source_surface' => 'kiosk'], null));

        $this->assertNull($data['customer']);
    }

    /**
     * @test
     *
     * PARITÉ ÉCRAN CUISINE ↔ ÉCRAN CAISSE. Le premier correctif n'avait été appliqué qu'à la
     * carte de cuisine ; la caisse annonçait toujours « Uber E… 📞 0000000042 » — le défaut
     * dominant de ce projet, « un correctif appliqué à une moitié du mécanisme, pas à sa
     * jumelle ». Les deux ressources sont désormais vérifiées ensemble.
     */
    public function la_caisse_annonce_le_meme_client_que_la_cuisine(): void
    {
        $technique = (new User)->forceFill(['name' => 'Uber Eats', 'phone' => '0000000042']);

        $order = $this->order([
            'source_surface' => 'uber_eats',
            'pos_customer_name' => 'Karim B.',
            'total' => 27.40,
            'status' => \App\Enums\OrderStatus::ACCEPT,
            'payment_status' => \App\Enums\PaymentStatus::PAID,
        ], $technique);

        $caisse = (new \App\Http\Resources\SimpleOrderResource($order))->toArray(request());
        $cuisine = $this->resource($order);

        $this->assertSame('Karim B.', $caisse['customer_name']);
        $this->assertSame($cuisine['customer']['name'], $caisse['customer_name'], 'Cuisine et caisse doivent nommer le MÊME client.');
        $this->assertNull($caisse['customer_phone'], 'Le numéro factice de l\'ancre technique ne doit pas atteindre la caisse non plus.');
    }

    /** @test */
    public function le_telephone_saisi_a_la_caisse_prime_sur_celui_du_compte(): void
    {
        $client = (new User)->forceFill(['name' => 'Ancien Nom', 'phone' => '0100000000']);

        $data = $this->resource($this->order([
            'source_surface' => 'pos',
            'pos_customer_name' => 'Client Comptoir',
            'pos_customer_phone' => '0699887766',
        ], $client));

        $this->assertSame('Client Comptoir', $data['customer']['name']);
        $this->assertSame('0699887766', $data['customer']['phone']);
    }
}
