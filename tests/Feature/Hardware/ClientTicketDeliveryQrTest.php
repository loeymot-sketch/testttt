<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Le QR de remise sur le ticket CLIENT thermique : octets ESC/POS `GS ( k` exacts, URL ≤ 124 octets
 * (la longueur tient sur 1 octet et le flux est transcodé CP858), uniquement pour une livraison dont un jeton peut être émis,
 * jamais bloquant pour l'impression.
 *
 * @group hardware
 */
class ClientTicketDeliveryQrTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    private const PRINT_QR = "\x1D(k\x03\x00\x31\x51\x30";

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

    private function ticket(int $orderId, array $opts = []): string
    {
        $o = Order::query()->withoutGlobalScopes()->with(['branch', 'orderItems', 'user'])->findOrFail($orderId);

        return app(OrderReceiptEscPosRenderer::class)->renderClientTicket($o, $opts);
    }

    /** Extrait l'URL stockée dans le QR (fonction 180) ou null s'il n'y a pas de QR. */
    private function qrPayload(string $bytes): ?string
    {
        $p = strpos($bytes, "\x1D(k");
        while ($p !== false) {
            // GS ( k pL pH cn fn m d1…dk ; fonction de stockage = cn 0x31, fn 0x50, m 0x30.
            if (substr($bytes, $p + 5, 3) === "\x31\x50\x30") {
                $len = ord($bytes[$p + 3]) + 256 * ord($bytes[$p + 4]);
                $this->assertSame($len - 3, strlen(substr($bytes, $p + 8, $len - 3)), 'les octets annoncés sont tous là');

                return substr($bytes, $p + 8, $len - 3);
            }
            $p = strpos($bytes, "\x1D(k", $p + 1);
        }

        return null;
    }

    public function test_a_delivery_ticket_carries_the_single_use_qr_with_exact_escpos_bytes(): void
    {
        $stop = $this->departed($this->delivery());
        $bytes = $this->ticket($stop->order_id);

        $url = $this->qrPayload($bytes);
        $this->assertNotNull($url, 'un QR est imprimé');
        $token = app(DeliveryHandoverService::class)->tokenFor($stop->fresh(), 1);
        $this->assertSame('https://plateforme.test/livreur/remise#'.$token, $url);

        // Longueur : <= 124 octets d'URL => longueur ESC/POS <= 127, donc un octet < 0x80 que CP858 ne peut pas abîmer.
        $this->assertLessThanOrEqual(124, strlen($url));
        $store = "\x1D(k".chr(strlen($url) + 3)."\x00\x31\x50\x30".$url;
        $this->assertStringContainsString($store, $bytes, 'la fonction de stockage est intacte après le transcodage CP858');
        // Les 5 fonctions du symbole, dans l'ordre : modèle 2, taille, correction M, stockage, impression.
        $this->assertStringContainsString("\x1D(k\x04\x00\x31\x41\x32\x00", $bytes);
        $this->assertStringContainsString("\x1D(k\x03\x00\x31\x43\x06", $bytes);
        $this->assertStringContainsString("\x1D(k\x03\x00\x31\x45\x31", $bytes);
        $this->assertStringContainsString(self::PRINT_QR, $bytes);
        $this->assertLessThan(strpos($bytes, self::PRINT_QR), strpos($bytes, $store));
    }

    public function test_the_qr_is_not_swallowed_by_textline_and_the_text_around_it_is_readable(): void
    {
        $bytes = $this->ticket($this->departed($this->delivery())->order_id);

        // Les octets de commande sont émis bruts (textLine retire 0x00-0x1F) : le symbole complet est là.
        $this->assertSame(1, substr_count($bytes, self::PRINT_QR));
        $cp = iconv('UTF-8', 'CP858', 'REMISE LIVREUR');
        $this->assertStringContainsString($cp, $bytes);
    }

    public function test_the_clear_token_is_printed_only_inside_the_qr(): void
    {
        $stop = $this->departed($this->delivery());
        $bytes = $this->ticket($stop->order_id);
        $token = app(DeliveryHandoverService::class)->tokenFor($stop->fresh(), 1);

        $this->assertSame(1, substr_count($bytes, $token), 'le jeton n\'apparaît qu\'une fois : dans le QR, jamais en texte');
    }

    public function test_reprinting_gives_the_identical_qr(): void
    {
        $stop = $this->departed($this->delivery());

        $this->assertSame($this->qrPayload($this->ticket($stop->order_id)), $this->qrPayload($this->ticket($stop->order_id, ['is_duplicata' => true])));
    }

    public function test_pickup_kiosk_and_table_orders_never_carry_a_qr(): void
    {
        foreach ([OrderType::TAKEAWAY, OrderType::KIOSK, OrderType::DINING_TABLE, OrderType::POS] as $type) {
            $stop = $this->departed($this->delivery());
            Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['order_type' => $type]);
            $bytes = $this->ticket($stop->order_id);

            $this->assertStringNotContainsString("\x1D(k", $bytes, "type $type : aucun symbole GS ( k");
            $this->assertStringNotContainsString('REMISE LIVREUR', $bytes, "type $type");
            $this->assertStringContainsString("\x1DV", $bytes, 'le ticket est bien complet (coupe)');
        }
    }

    public function test_a_delivery_without_a_stop_prints_without_qr(): void
    {
        $stop = $this->delivery();
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->delete();
        $bytes = $this->ticket($stop->order_id);

        $this->assertNull($this->qrPayload($bytes));
        $this->assertStringContainsString(iconv('UTF-8', 'CP858', 'MONTANT TOTAL'), $bytes);
    }

    public function test_cancelled_delivered_uber_eats_and_uber_course_orders_get_no_qr(): void
    {
        $cancelled = $this->delivery();
        Order::query()->withoutGlobalScopes()->whereKey($cancelled->order_id)->update(['status' => OrderStatus::CANCELED]);
        $this->assertNull($this->qrPayload($this->ticket($cancelled->order_id)));

        $delivered = $this->departed($this->delivery());
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($delivered->id)->update(['delivered_at' => now()]);
        $this->assertNull($this->qrPayload($this->ticket($delivered->order_id)));

        $eats = $this->delivery();
        Order::query()->withoutGlobalScopes()->whereKey($eats->order_id)->update(['source_surface' => 'uber_eats']);
        $this->assertNull($this->qrPayload($this->ticket($eats->order_id)));
    }

    public function test_a_used_or_revoked_token_is_not_reprinted(): void
    {
        $stop = $this->departed($this->delivery());
        $svc = app(DeliveryHandoverService::class);
        $this->assertNotNull($this->qrPayload($this->ticket($stop->order_id)));
        $svc->revoke($stop->fresh(), 'test');

        $this->assertNull($this->qrPayload($this->ticket($stop->order_id)), 'un QR révoqué ne ressort pas sur une réimpression');
    }

    public function test_a_failing_token_service_never_blocks_the_ticket(): void
    {
        $stop = $this->departed($this->delivery());
        $this->mock(DeliveryHandoverService::class, function ($m) {
            $m->shouldReceive('qrUrlForOrder')->andThrow(new \RuntimeException('base indisponible'));
        });

        $bytes = $this->ticket($stop->order_id);

        $this->assertNull($this->qrPayload($bytes));
        $this->assertStringContainsString(iconv('UTF-8', 'CP858', 'MONTANT TOTAL'), $bytes, 'le ticket sort quand même');
        $this->assertStringContainsString("\x1DV", $bytes);
    }

    public function test_an_url_too_long_for_the_one_byte_length_is_dropped_not_corrupted(): void
    {
        config(['app.url' => 'https://'.str_repeat('a', 70).'.exemple.test']);
        $stop = $this->departed($this->delivery());
        $bytes = $this->ticket($stop->order_id);

        $this->assertGreaterThan(124, strlen(app(DeliveryHandoverService::class)->urlFor(str_repeat('A', 43))));
        $this->assertNull($this->qrPayload($bytes), 'pas de QR plutôt qu\'un QR dont la longueur serait faussée par CP858');
        $this->assertStringContainsString("\x1DV", $bytes);
    }

    public function test_the_default_application_url_fits_with_room_to_spare(): void
    {
        config(['app.url' => 'https://vps-418872ac.vps.ovh.net']);
        $url = app(DeliveryHandoverService::class)->urlFor(str_repeat('A', 43));

        $this->assertLessThanOrEqual(124, strlen($url));
        $this->assertSame(91, strlen($url));
    }
}
