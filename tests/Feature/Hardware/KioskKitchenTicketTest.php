<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Listeners\PrintKioskKitchenTicketOnOrderCreated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [BORNE-KITCHEN 2026-06-28] Une commande borne imprime un TICKET CUISINE sur la
 * station 'kitchen' (en plus du KDS écran + copie client comptoir). Spy transport
 * (renderer + service sont `final`).
 */
class KioskKitchenTicketTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->spy = new class implements PrinterTransportInterface {
            public array $sends = [];
            public function send(string $bytes, array $config): bool { $this->sends[] = strlen($bytes); return true; }
            public function lastError(): ?string { return null; }
        };
        $this->app->instance(PrinterTransportInterface::class, $this->spy);
    }

    private function makeOrder(string $sourceSurface = 'kiosk'): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 2, 'total_price' => 13.80,
            'composition_snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Cordon Bleu']], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos L';
        $order = (new Order)->forceFill([
            'id' => 777, 'branch_id' => $this->branch->id, 'order_serial_no' => 'K-1', 'queue_number' => 'A0011',
            'order_type' => OrderType::KIOSK, 'subtotal' => 13.80, 'total' => 13.80, 'source_surface' => $sourceSurface,
            'order_datetime' => '2026-06-28 12:00:00',
        ]);
        $order->setRelation('branch', $this->branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));
        return $order;
    }

    private function makeKitchenPrinter(): void
    {
        Printer::create([
            'branch_id' => $this->branch->id, 'name' => 'Cuisine', 'station' => 'kitchen',
            'status' => Status::ACTIVE, 'width_chars' => 48,
        ]);
    }

    public function test_kiosk_order_prints_kitchen_ticket(): void
    {
        $this->makeKitchenPrinter();
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder()));
        $this->assertCount(1, $this->spy->sends, 'ticket cuisine imprimé');
    }

    public function test_no_kitchen_printer_uses_kds_only_noop(): void
    {
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder()));
        $this->assertCount(0, $this->spy->sends, 'pas d\'imprimante cuisine = KDS seul');
    }

    public function test_non_kiosk_order_skipped(): void
    {
        $this->makeKitchenPrinter();
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder('pos')));
        $this->assertCount(0, $this->spy->sends, 'POS imprime sa cuisine au checkout, pas ici');
    }
}
