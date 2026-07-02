<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\KioskMachine;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TICKET-BORNE-LONG 2026-07-02] L'endpoint borne GET /api/frontend/order/show/{id}/escpos DOIT
 * répondre 200 + escpos_b64 (client ET cuisine). Angle mort découvert par l'audit e2e : le
 * contrôleur oubliait `use Illuminate\Http\Request;` → `Request` résolvait `App\Http\Controllers\
 * Frontend\Request` (inexistant) → HTTP 500 déterministe → la borne n'imprimait JAMAIS via le
 * renderer serveur (feed long/coupe partielle inatteignables). Ce test frappe l'endpoint HTTP réel.
 */
class BorneEscposEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:FrontendOrder,1:string} */
    private function setupKioskOrder(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['username' => 'kiosk_esc_' . uniqid(), 'branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 'esc-test-' . uniqid(),
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'username'   => 'kiosk-esc',
            'password'   => bcrypt('123456'),
            'is_login'   => \App\Enums\Ask::NO,
            'status'     => \App\Enums\Status::ACTIVE,
        ]);
        $item = Item::factory()->create(['price' => 7.90]);
        // FrontendOrder partage la table `orders` — pas de factory dédiée → forceFill direct.
        $order = new FrontendOrder;
        $order->forceFill([
            'branch_id'      => $branch->id,
            'user_id'        => $user->id,
            'total'          => 7.90,
            'subtotal'       => 7.90,
            'discount'       => 0,
            'total_tax'      => 0,
            'order_type'     => \App\Enums\OrderType::TAKEAWAY,
            'source'         => \App\Enums\Source::WEB,
            'source_surface' => 'kiosk',
            'payment_status' => \App\Enums\PaymentStatus::PENDING_COUNTER,
            'status'         => \App\Enums\OrderStatus::ACCEPT,
            'queue_number'   => 'A0001',
            'order_datetime' => now(),
        ]);
        $order->save();
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

        $token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        return [$order, $token];
    }

    /** @test */
    public function endpoint_escpos_borne_repond_200_pour_client_et_cuisine(): void
    {
        [$order, $token] = $this->setupKioskOrder();

        foreach (['client', 'kitchen'] as $ticket) {
            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'x-api-key'     => config('app.api_key'),
            ])->getJson("/api/frontend/order/show/{$order->id}/escpos?ticket={$ticket}");

            $res->assertOk(); // aurait échoué en 500 avant le fix (Request non importé)
            $res->assertJsonPath('order_id', $order->id);
            $this->assertNotEmpty($res->json('escpos_b64'), "escpos_b64 vide pour ticket={$ticket}");
        }
    }

    /** @test */
    public function endpoint_escpos_borne_refuse_sans_auth(): void
    {
        [$order] = $this->setupKioskOrder();

        $res = $this->withHeaders(['x-api-key' => config('app.api_key')])
            ->getJson("/api/frontend/order/show/{$order->id}/escpos?ticket=client");

        $this->assertContains($res->getStatusCode(), [401, 403], 'sans token kiosk → doit être refusé');
    }
}
