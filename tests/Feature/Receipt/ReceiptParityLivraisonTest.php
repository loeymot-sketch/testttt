<?php

namespace Tests\Feature\Receipt;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Order;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use App\Support\CoordonneesClientTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.2.3] Parité, sur UNE MÊME livraison du site : le ticket thermique, la ressource
 * qui alimente le reçu HTML de la caisse (`OrderDetailsResource` → `ReceiptComponent.vue`), la planche Tournées et
 * l'écran du livreur disent le même total, le même téléphone, la même adresse et le même règlement à la porte.
 * Une seule source : `CoordonneesClientTicket`. Si un écran s'écarte, c'est ici que ça casse.
 */
class ReceiptParityLivraisonTest extends TestCase
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

    private function texte(string $bytes): string
    {
        $t = (string) iconv('CP858', 'UTF-8//IGNORE', $bytes);
        $t = (string) preg_replace('/\x1B[@!\-EaGMdt].?|\x1D[!VB(].{0,4}|\x1B[dt].|[\x00-\x08\x0B-\x1F]/u', ' ', $t);

        return (string) preg_replace('/\s+/u', ' ', $t);
    }

    public function test_ticket_thermique_ressource_caisse_planche_et_livreur_disent_la_meme_chose(): void
    {
        $stop = $this->delivery([
            'source_surface' => 'delivery', 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'door_payment_mode' => 'card',
        ]);
        $order = Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user', 'address'])->findOrFail($stop->order_id);

        // 1. Ticket thermique.
        $ticket = $this->texte(app(OrderReceiptEscPosRenderer::class)->renderClientTicket($order));
        $this->assertMatchesRegularExpression('/A REGLER TTC\s*:\s*([\d\s\x{202F}\x{00A0}]+,\d{2}) ?€/u', $ticket);
        preg_match('/A REGLER TTC\s*:\s*([\d\s\x{202F}\x{00A0}]+,\d{2}) ?€/u', $ticket, $m);
        $totalTicket = (float) str_replace(',', '.', preg_replace('/[^\d,]/u', '', $m[1]));

        // 2. Ressource de l'écran caisse (reçu HTML).
        $resource = (new OrderDetailsResource($order))->toArray(Request::create('/'));

        // 3. Planche Tournées (caisse) et 4. écran du livreur : mêmes champs, même source.
        $telephone = CoordonneesClientTicket::telephone($order);
        $adresse = CoordonneesClientTicket::adresseLivraisonUneLigne($order);

        $this->assertSame(round((float) $order->total, 2), $totalTicket, 'total du ticket ≠ total de la commande');
        $this->assertSame(round((float) $order->total, 2), $resource['total'], 'total de la ressource caisse ≠ total de la commande');

        $this->assertSame('0612345678', $telephone);
        $this->assertSame($telephone, $resource['pos_customer_phone']);
        $this->assertStringContainsString('Tel : '.$telephone, $ticket);

        $this->assertSame('10 Rue Test, Hénin-Beaumont, 2B, 62110', $adresse, 'adresse une ligne (voie, complément, CP seul car la voie porte déjà la ville)');
        foreach (CoordonneesClientTicket::lignesAdresseLivraison($order) as $ligne) {
            $this->assertStringContainsString($ligne, $ticket);
        }

        $this->assertSame('CARTE AU LIVREUR', CoordonneesClientTicket::reglementALaPorte($order));
        $this->assertStringContainsString('A REGLER A LA LIVRAISON : CARTE AU LIVREUR', $ticket);
        $this->assertStringNotContainsString('EN CAISSE', $ticket);
        // La ressource expose ce que ReceiptComponent.vue combine en « À régler à la livraison : Carte au livreur ».
        $this->assertSame('card', $resource['door_payment_mode'] ?? $order->door_payment_mode);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) ($resource['payment_method'] ?? $order->payment_method));
        $this->assertSame(PaymentStatus::UNPAID, (int) ($resource['payment_status'] ?? $order->payment_status));
    }

    /**
     * [T-1.4.5] L'écran du livreur (`DeliveryRunController::present` → `DeliveryWaveService::collectionFor`) et le ticket
     * (`CoordonneesClientTicket::reglementALaPorte`) sont deux lectures de la même commande : elles doivent dire le même mode.
     *
     * @dataProvider modesALaPorte
     */
    public function test_mode_a_la_porte_identique_sur_le_ticket_et_chez_le_livreur(array $over, string $ticket, string $livreur): void
    {
        $stop = $this->delivery($over + ['source_surface' => 'delivery', 'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::UNPAID]);
        $order = Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user', 'address'])->findOrFail($stop->order_id);

        $this->assertSame($ticket, CoordonneesClientTicket::reglementALaPorte($order));
        $this->assertSame($livreur, app(\App\Services\Delivery\DeliveryWaveService::class)->collectionFor($order)['collect_method']);
        $this->assertStringContainsString('A REGLER A LA LIVRAISON : '.$ticket, $this->texte(app(OrderReceiptEscPosRenderer::class)->renderClientTicket($order)));
    }

    public static function modesALaPorte(): array
    {
        return [
            'espèces' => [['payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'door_payment_mode' => null], 'ESPÈCES', \App\Models\DeliveryStop::COLLECT_CASH],
            'carte au livreur' => [['payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'door_payment_mode' => 'card'], 'CARTE AU LIVREUR', \App\Models\DeliveryStop::COLLECT_CARD],
            'titre-restaurant' => [['payment_method' => PaymentGateway::TICKET_RESTAURANT, 'door_payment_mode' => null], 'TITRE-RESTAURANT', \App\Services\Delivery\DeliveryWaveService::COLLECT_MEAL_VOUCHER],
        ];
    }
}
