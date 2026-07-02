<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosTicketBytesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TICKET-BORNE-LONG 2026-07-02] Le ticket CLIENT imprimé par la BORNE doit être LONG
 * (queue de papier ~12 cm pour qu'il ressorte et ne tombe pas) + coupe PARTIELLE (il
 * reste accroché, ne tombe jamais). La CAISSE garde son ticket court (le caissier le tend).
 * Owner : « le ticket borne est trop court, il tombe par terre ».
 */
class BorneClientTicketLongFeedTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['price' => 7.90]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'kiosk',
            'total' => 7.90,
            'subtotal' => 7.90,
            'queue_number' => 'A0001',
        ]);
        (new OrderItem)->forceFill([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 7.90,
            'total_price' => 7.90,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'tax_amount' => 0,
        ])->save();

        return $order;
    }

    private function tailFeedLines(string $bytes): int
    {
        $cut = strpos($bytes, "\x1DV");
        if ($cut === false) {
            return -1;
        }
        $n = 0;
        for ($i = $cut - 1; $i >= 0 && ord($bytes[$i]) === 0x0A; $i--) {
            $n++;
        }

        return $n;
    }

    /** @test */
    public function borne_client_ticket_est_long_et_coupe_partielle(): void
    {
        config()->set('printing.cut.kiosk_client_feed_lines', 30);
        config()->set('printing.cut.kiosk_client_mode', 'partial');

        $order = $this->makeOrder();
        $svc = app(EscPosTicketBytesService::class);

        $borne = $svc->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // Queue de papier longue (>= 20 lignes ≈ 8 cm au minimum).
        $this->assertGreaterThanOrEqual(20, $this->tailFeedLines($borne), 'ticket borne trop court (queue insuffisante)');
        // Coupe PARTIELLE (GS V 1) → ne tombe pas.
        $this->assertStringContainsString("\x1DV\x01", $borne, 'la borne doit couper en PARTIEL (ticket reste accroché)');
    }

    /** @test */
    public function caisse_client_ticket_reste_court_et_coupe_totale(): void
    {
        config()->set('printing.cut.feed_lines_before_cut', 8);
        config()->set('printing.cut.mode', 'full');

        $order = $this->makeOrder();
        $svc = app(EscPosTicketBytesService::class);

        $caisse = $svc->render((int) $order->branch_id, (int) $order->id, 'client', false, false);
        $this->assertNotNull($caisse);
        // La caisse n'est PAS rallongée (queue courte).
        $this->assertLessThanOrEqual(12, $this->tailFeedLines($caisse), 'la caisse ne doit pas être rallongée');
        $this->assertStringContainsString("\x1DV\x00", $caisse, 'la caisse coupe en TOTAL');
    }
}
