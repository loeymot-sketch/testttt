<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryHandoverToken;
use App\Models\Order;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Interrupteur d'impression du QR (`delivery.handover.qr_print_enabled`, env `DELIVERY_HANDOVER_QR_PRINT`).
 * DÉFAUT FAUX : la lisibilité sur l'imprimante réelle n'est pas prouvée. Fermé = aucun octet de QR, aucun jeton créé ; les routes de
 * remise restent actives. Ouvert = QR présent comme avant.
 *
 * @group delivery
 */
class DeliveryHandoverQrPrintFlagTest extends TestCase
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

    private function ticket(int $orderId): string
    {
        $o = Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user'])->findOrFail($orderId);

        return app(OrderReceiptEscPosRenderer::class)->renderClientTicket($o);
    }

    public function test_the_shipped_default_is_false_a_sentinel_fails_if_it_ever_becomes_true(): void
    {
        $this->assertFalse((bool) config('delivery.handover.qr_print_enabled'), 'défaut livré : le QR ne s\'imprime PAS');
        $src = file_get_contents(base_path('config/delivery.php'));
        $this->assertMatchesRegularExpression("/'qr_print_enabled'\\s*=>\\s*\\(bool\\)\\s*env\\('DELIVERY_HANDOVER_QR_PRINT',\\s*false\\)/", $src, 'le défaut du fichier de config est FALSE');
        // Jamais env() dans le rendu : la clé est lue par config() (config:cache).
        $renderer = file_get_contents(app_path('Services/Hardware/OrderReceiptEscPosRenderer.php'));
        $this->assertStringNotContainsString('DELIVERY_HANDOVER_QR_PRINT', $renderer);
        $this->assertStringContainsString("config('delivery.handover.qr_print_enabled'", $renderer);
    }

    public function test_closed_by_default_no_qr_byte_and_no_token_is_created(): void
    {
        $stop = $this->departed($this->delivery());

        $bytes = $this->ticket($stop->order_id);

        $this->assertStringNotContainsString("\x1D(k", $bytes);
        $this->assertStringNotContainsString('REMISE LIVREUR', $bytes);
        $this->assertSame(0, DeliveryHandoverToken::query()->withoutGlobalScopes()->count(), 'aucun jeton créé tant que le QR n\'est pas imprimé');
        $this->assertStringContainsString("\x1DV", $bytes, 'le ticket sort normalement');
    }

    public function test_open_the_qr_is_printed_and_a_token_exists(): void
    {
        config(['delivery.handover.qr_print_enabled' => true]);
        $stop = $this->departed($this->delivery());

        $bytes = $this->ticket($stop->order_id);

        $this->assertStringContainsString("\x1D(k", $bytes);
        $this->assertSame(1, DeliveryHandoverToken::query()->withoutGlobalScopes()->count());
    }

    public function test_the_handover_routes_stay_active_when_printing_is_closed(): void
    {
        $stop = $this->departed($this->delivery());
        $qr = $this->qrToken($stop); // jeton imprimé avant la fermeture de l\'interrupteur
        $bearer = $this->handoverLogin('livreur.a@test.fr');

        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/resolve', ['token' => $qr])->assertOk();
    }
}
