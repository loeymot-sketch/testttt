<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.1.1] Ce que le propriétaire a constaté : une commande passée sur le SITE
 * sort en cuisine et à la caisse SANS le téléphone du client, SANS son adresse de livraison et avec « A REGLER EN
 * CAISSE » alors qu'il a choisi comment régler à la porte. Ce test reproduit le défaut (rouge d'abord) et fixe le
 * contrat : les deux tickets thermiques portent les coordonnées, et le règlement imprimé est celui que le client a choisi.
 *
 * Le site envoie `payment_method` (1 espèces, 4 carte en ligne, 5 titre-restaurant) et le serveur pose
 * `orders.door_payment_mode='card'` pour « carte au livreur » (FrontendOrderService). `source_surface='web'`.
 *
 * @group hardware
 */
class ClientTicketLivraisonCoordonneesTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
    }

    protected function tearDown(): void
    {
        $this->tearDownHandover();
        parent::tearDown();
    }

    /** Ticket décodé (CP858 → UTF-8), commandes ESC/POS retirées, espaces repliés : on lit ce que le papier montre. */
    private function texte(string $bytes): string
    {
        $t = (string) iconv('CP858', 'UTF-8//IGNORE', $bytes);
        $t = (string) preg_replace('/\x1B[@!\-EaGMdt].?|\x1D[!VB(].{0,4}|\x1B[dt].|[\x00-\x08\x0B-\x1F]/u', ' ', $t);

        return (string) preg_replace('/\s+/u', ' ', $t);
    }

    private function livraisonWeb(array $over = []): Order
    {
        $stop = $this->delivery($over + ['source_surface' => 'web', 'status' => OrderStatus::ACCEPT]);

        return Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user', 'address'])->findOrFail($stop->order_id);
    }

    private function client(Order $o): string
    {
        return $this->texte(app(OrderReceiptEscPosRenderer::class)->renderClientTicket($o));
    }

    private function cuisine(Order $o): string
    {
        return $this->texte(app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($o));
    }

    private function assertCoordonnees(string $t, string $ticket): void
    {
        $this->assertStringContainsString('Karim Test', $t, "$ticket : nom du client");
        $this->assertStringContainsString('0612345678', str_replace([' ', '.'], '', $t), "$ticket : téléphone du compte client (le site ne saisit pas pos_customer_phone)");
        $this->assertStringContainsString('10 Rue Test', $t, "$ticket : adresse de livraison");
        $this->assertStringContainsString('62110', $t, "$ticket : code postal");
        $this->assertStringContainsString('2B', $t, "$ticket : complément d'adresse");
    }

    public function test_livraison_web_especes_a_la_porte_le_ticket_client_porte_telephone_adresse_et_reglement(): void
    {
        $t = $this->client($this->livraisonWeb());

        $this->assertCoordonnees($t, 'client');
        $this->assertStringNotContainsString('A REGLER EN CAISSE', $t, 'une livraison ne se règle pas en caisse');
        $this->assertMatchesRegularExpression('/LIVRAISON.{0,40}ESP[EÈ]CES|ESP[EÈ]CES.{0,40}LIVRAISON/u', $t, 'le règlement imprimé = espèces à la livraison');
    }

    /** Cas RÉEL : FrontendOrder::creating force `source_surface='delivery'` pour toute livraison du site (pas 'web'). */
    public function test_la_surface_delivery_forcee_par_le_serveur_donne_le_meme_ticket(): void
    {
        $t = $this->client($this->livraisonWeb(['source_surface' => 'delivery', 'door_payment_mode' => 'card']));

        $this->assertCoordonnees($t, 'client (surface delivery)');
        $this->assertStringContainsString('CARTE AU LIVREUR', $t);
        $this->assertStringNotContainsString('A REGLER EN CAISSE', $t);
    }

    public function test_livraison_web_carte_au_livreur_est_annoncee_comme_telle(): void
    {
        $t = $this->client($this->livraisonWeb(['door_payment_mode' => 'card']));

        $this->assertCoordonnees($t, 'client');
        $this->assertStringNotContainsString('A REGLER EN CAISSE', $t);
        $this->assertStringNotContainsString('ESPECES', str_replace('È', 'E', $t), 'pas « espèces » quand le client a choisi la carte');
        $this->assertStringContainsString('CARTE', $t, 'carte bancaire au livreur');
    }

    public function test_livraison_web_titre_restaurant_a_la_porte(): void
    {
        $t = $this->client($this->livraisonWeb(['payment_method' => PaymentGateway::TICKET_RESTAURANT]));

        $this->assertCoordonnees($t, 'client');
        $this->assertStringNotContainsString('A REGLER EN CAISSE', $t);
        $this->assertMatchesRegularExpression('/TITRE.?RESTAURANT/iu', $t);
    }

    public function test_livraison_web_payee_en_ligne_n_a_rien_a_regler(): void
    {
        $t = $this->client($this->livraisonWeb(['payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::PAID]));

        $this->assertCoordonnees($t, 'client');
        $this->assertStringNotContainsString('A REGLER', $t);
        $this->assertStringContainsString('PAYÉ', $t);
    }

    public function test_le_ticket_cuisine_d_une_livraison_web_porte_telephone_et_adresse(): void
    {
        $t = $this->cuisine($this->livraisonWeb());

        $this->assertStringContainsString('LIVRAISON', $t);
        $this->assertStringContainsString('Karim Test', $t);
        $this->assertStringContainsString('0612345678', str_replace([' ', '.'], '', $t), 'téléphone en cuisine (rappel client)');
        $this->assertStringContainsString('10 Rue Test', $t, 'adresse en en-tête cuisine (une ligne)');
    }

    public function test_un_retrait_web_porte_le_telephone_mais_jamais_d_adresse_ni_de_livraison(): void
    {
        $o = $this->livraisonWeb(['order_type' => OrderType::TAKEAWAY]);
        $t = $this->client($o);

        $this->assertStringContainsString('0612345678', str_replace([' ', '.'], '', $t));
        $this->assertStringNotContainsString('10 Rue Test', $t, 'un retrait n\'imprime pas d\'adresse de livraison');
        $this->assertStringNotContainsString('LIVRER', $t);
    }

    public function test_une_commande_de_caisse_sans_saisie_n_emprunte_rien_au_compte_porteur(): void
    {
        // Contrat existant (TicketNomClientTest) : la caisse n'emprunte ni nom ni téléphone à son compte technique.
        $stop = $this->delivery(['source_surface' => 'pos', 'order_type' => OrderType::POS]);
        $o = Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user', 'address'])->findOrFail($stop->order_id);
        $t = $this->client($o);

        $this->assertStringNotContainsString('Karim Test', $t);
        $this->assertStringNotContainsString('0612345678', str_replace([' ', '.'], '', $t));
    }
}
