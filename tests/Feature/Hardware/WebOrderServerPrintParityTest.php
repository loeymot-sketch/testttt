<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Listeners\PrintKioskKitchenTicketOnOrderCreated;
use App\Listeners\PrintKioskOrderToCounter;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [S4 2026-07-18 · parité impression serveur borne↔web] Les listeners serveur
 * (ticket cuisine + copie comptoir) étaient gatés `source_surface==='kiosk'` → la
 * WEB n'imprimait jamais côté serveur (asymétrie live au câblage PRINT_DRIVER).
 * La garde est élargie à web/online. On prouve :
 *   - web (source_surface='web') imprime ticket cuisine ET copie comptoir ;
 *   - SANS imprimante configurée → NO-OP strict (aucun effet de bord) ;
 *   - kiosk continue d'imprimer (non-régression) ;
 *   - pos reste exclu (POS imprime à son checkout).
 * Spy transport (renderer + service sont `final`), miroir de KioskKitchenTicketTest.
 */
class WebOrderServerPrintParityTest extends TestCase
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

    private function makeOrder(string $sourceSurface = 'web'): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 12.50,
            'composition_snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet']], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill([
            'id' => 909, 'branch_id' => $this->branch->id, 'order_serial_no' => 'W-1', 'queue_number' => 'A0091',
            'order_type' => OrderType::TAKEAWAY, 'subtotal' => 12.50, 'total' => 12.50, 'source_surface' => $sourceSurface,
            'order_datetime' => '2026-07-18 12:00:00',
        ]);
        $order->setRelation('branch', $this->branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));
        return $order;
    }

    private function makeKitchenPrinter(): void
    {
        Printer::create([
            'branch_id' => $this->branch->id, 'name' => 'Cuisine', 'station' => 'kitchen_hot',
            'status' => Status::ACTIVE, 'width_chars' => 48,
        ]);
    }

    private function makeReceiptPrinter(): void
    {
        Printer::create([
            'branch_id' => $this->branch->id, 'name' => 'Comptoir', 'station' => 'receipt',
            'status' => Status::ACTIVE, 'width_chars' => 48,
        ]);
    }

    /** S4 : la web imprime son ticket CUISINE serveur (parité borne). */
    public function test_web_order_prints_kitchen_ticket(): void
    {
        $this->makeKitchenPrinter();
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder('web')));
        $this->assertCount(1, $this->spy->sends, 'web : ticket cuisine imprimé côté serveur.');
    }

    /** S4 : la web imprime sa copie COMPTOIR serveur (parité borne). */
    public function test_web_order_prints_counter_copy(): void
    {
        $this->makeReceiptPrinter();
        (new PrintKioskOrderToCounter)->handle(new OrderCreated($this->makeOrder('web')));
        $this->assertCount(1, $this->spy->sends, 'web : copie comptoir imprimée côté serveur.');
    }

    /** Effet de bord ZÉRO quand aucune imprimante n'est configurée (le cas V1). */
    public function test_web_order_noop_when_no_printer(): void
    {
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder('web')));
        (new PrintKioskOrderToCounter)->handle(new OrderCreated($this->makeOrder('web')));
        $this->assertCount(0, $this->spy->sends, 'aucune imprimante → NO-OP strict, aucun effet de bord.');
    }

    /** Non-régression : la borne continue d'imprimer. */
    public function test_kiosk_still_prints(): void
    {
        $this->makeKitchenPrinter();
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder('kiosk')));
        $this->assertCount(1, $this->spy->sends, 'kiosk : ticket cuisine toujours imprimé.');
    }

    /** Non-régression : le POS reste exclu (il imprime à son checkout, pas ici). */
    public function test_pos_still_skipped(): void
    {
        $this->makeKitchenPrinter();
        $this->makeReceiptPrinter();
        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated($this->makeOrder('pos')));
        (new PrintKioskOrderToCounter)->handle(new OrderCreated($this->makeOrder('pos')));
        $this->assertCount(0, $this->spy->sends, 'pos : exclu de l\'impression serveur (checkout POS).');
    }
}
