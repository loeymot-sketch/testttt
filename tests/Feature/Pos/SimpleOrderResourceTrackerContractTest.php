<?php

namespace Tests\Feature\Pos;

use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CAISSE-WEB-INTEL 2026-08-06] Contrat resource ↔ tracker POS.
 *
 * L'audit intelligence-caisse a montré que le tracker calcule TOUT son
 * temporel (âge, tri oldest-first, aging 5/10 min) sur `order.created_at`
 * alors que SimpleOrderResource ne le shippait pas — les specs vitest
 * l'injectaient en fixture (« fixture qui encode le bug »). Ce test épingle
 * le VRAI payload : si un des champs d'intelligence disparaît du resource,
 * il casse ici, pas silencieusement à la caisse.
 */
class SimpleOrderResourceTrackerContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function makeLine(Order $order, Branch $branch, ?string $instruction): OrderItem
    {
        $item = Item::factory()->create();

        return OrderItem::create([
            'order_id'    => $order->id,
            'branch_id'   => $branch->id,
            'item_id'     => $item->id,
            'quantity'    => 1,
            'discount'    => 0,
            'price'       => 9.90,
            'total_price' => 9.90,
            'instruction' => $instruction,
        ]);
    }

    private function payloadFor(Order $order): array
    {
        $order->load(['orderItems', 'user', 'transaction']);

        return (new SimpleOrderResource($order))->resolve();
    }

    /** @test */
    public function created_at_and_scheduled_fields_are_shipped_raw(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
            'scheduled_at'   => now()->addHours(2)->startOfMinute(),
        ]);

        $payload = $this->payloadFor($order->fresh());

        $this->assertArrayHasKey('created_at', $payload, 'Le tracker trie/vieillit sur created_at — champ obligatoire.');
        $this->assertNotNull($payload['created_at']);
        $this->assertSame($order->fresh()->created_at->toIso8601String(), $payload['created_at']);

        $this->assertSame($order->fresh()->scheduled_at->toIso8601String(), $payload['scheduled_at']);
        $this->assertSame($order->fresh()->scheduled_at->format('H:i'), $payload['scheduled_hm']);
        $this->assertArrayHasKey('is_advance_order', $payload);
    }

    /** @test */
    public function scheduled_fields_are_null_for_asap_orders(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
            'scheduled_at'   => null,
        ]);

        $payload = $this->payloadFor($order->fresh());

        $this->assertNull($payload['scheduled_at']);
        $this->assertNull($payload['scheduled_hm']);
    }

    /** @test */
    public function has_instruction_reflects_order_line_instructions(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
        ]);
        $this->makeLine($order, $branch, 'Allergie arachide');

        $payload = $this->payloadFor($order->fresh());

        $this->assertTrue($payload['has_instruction'], 'Une ligne avec instruction doit lever le flag.');
        $this->assertSame('Allergie arachide', $payload['order_items'][0]['instruction']);
    }

    /** @test */
    public function has_instruction_false_without_notes_and_without_lazy_load(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
        ]);
        $this->makeLine($order, $branch, null);

        $payload = $this->payloadFor($order->fresh());
        $this->assertFalse($payload['has_instruction']);

        // Garde N+1 : relation non chargée ⇒ false, JAMAIS de lazy SELECT.
        $bare = (new SimpleOrderResource(Order::query()->find($order->id)))->resolve();
        $this->assertFalse($bare['has_instruction']);
        $this->assertSame([], $bare['order_items']);
    }
}
