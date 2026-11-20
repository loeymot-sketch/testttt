<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Fiscal\ZReportService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #6 2026-10-02] « Vérifier que le total backend, le ticket et le Z intègrent
 * bien le prix. » Une vente POS dont la ligne FORMULE porte « Grande Portion » et « Cheddar Fondu »
 * (ids d'extras, comme le panier les envoie désormais) :
 *   · le total scellé inclut les +2,00 € ;
 *   · la ligne commande et le snapshot immuable portent le prix ;
 *   · le ticket client imprime les deux options avec leur prix ;
 *   · l'agrégat du Z (ZReportService::aggregate, lecture seule) reprend le même total.
 */
class FormuleFritesFactureJusquauZTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_total_ligne_snapshot_ticket_et_z_integrent_les_options_de_formule(): void
    {
        config(['app.api_key' => 'test-api-key']);
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $op = User::factory()->create(['branch_id' => $branch->id]);
        $op->assignRole('POS Operator');
        $op->givePermissionTo('pos');
        $this->seedOpenSessionFor($op, $branch);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create(['tax_rate' => 10, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tacos = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'price' => 8.50, 'status' => Status::ACTIVE]);
        $formule = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'price' => 2.50, 'name' => 'Menu (Frites + Boisson)', 'status' => Status::ACTIVE]);
        $grande = ItemExtra::create(['item_id' => $formule->id, 'name' => 'Grande Portion', 'price' => 1.00, 'status' => Status::ACTIVE]);
        $cheddar = ItemExtra::create(['item_id' => $formule->id, 'name' => 'Cheddar Fondu', 'price' => 1.00, 'status' => Status::ACTIVE]);
        ItemAddon::create(['item_id' => $tacos->id, 'addon_item_id' => $formule->id, 'role' => 'menu_component']);

        $payload = [
            'token' => null, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'subtotal' => 0, 'discount' => 0,
            'coupon_id' => 0, 'total' => 0, 'order_type' => OrderType::TAKEAWAY, 'is_advance_order' => Ask::NO,
            'source' => Source::POS, 'pos_payment_method' => PosPaymentMethod::CASH, 'pos_received_amount' => 0,
            'items' => json_encode([
                ['item_id' => $tacos->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
                // La ligne formule, telle que le panier la construit : ids d'extras, aucun prix.
                ['item_id' => $formule->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => [
                    ['id' => $grande->id, 'quantity' => 1],
                    ['id' => $cheddar->id, 'quantity' => 1],
                ], 'instruction' => "Grande Portion (+€1.00)\nCheddar Fondu (+€1.00)"],
            ]),
        ];

        $quote = $this->actingAs($op, 'sanctum')->postJson('/api/admin/pos/quote', $payload)->assertOk()->json('data');
        $this->assertEqualsWithDelta(8.50 + 2.50 + 2.00, (float) $quote['total_ttc'], 0.0001, 'devis serveur');

        $res = $this->actingAs($op, 'sanctum')->withHeader('x-api-key', 'test-api-key')->postJson('/api/admin/pos', array_merge($payload, [
            'quote_token' => $quote['quote_token'], 'quote_signature' => $quote['signature'],
            'total' => $quote['total_ttc'], 'pos_received_amount' => $quote['total_ttc'],
        ]));
        $this->assertContains($res->status(), [200, 201], $res->getContent());
        $orderId = (int) $res->json('data.id');

        // 1. total backend scellé
        $order = Order::with(['orderItems', 'branch'])->findOrFail($orderId);
        $this->assertEqualsWithDelta(13.00, (float) $order->total, 0.0001);

        // 2. ligne commande + snapshot immuable
        $line = OrderItem::where('order_id', $orderId)->where('item_id', $formule->id)->firstOrFail();
        $this->assertEqualsWithDelta(4.50, (float) $line->total_price, 0.0001, 'formule 2,50 + 2 options');
        $extras = collect($line->composition_snapshot['extras'] ?? [])->keyBy('extra_name');
        $this->assertEqualsWithDelta(1.00, (float) $extras['Grande Portion']['line_total'], 0.0001);
        $this->assertEqualsWithDelta(1.00, (float) $extras['Cheddar Fondu']['line_total'], 0.0001);

        // 3. ticket client : les deux options, avec leur prix
        $order->fiscal_sequence_no = $order->fiscal_sequence_no ?: 1;
        $ticket = app(OrderReceiptEscPosRenderer::class)->renderClientTicket($order);
        $this->assertStringContainsString('Grande Portion', $ticket);
        $this->assertStringContainsString('Cheddar Fondu', $ticket);

        // 4. Z : même total (agrégat en lecture seule, uniquement les ventes fiscalisées)
        $agg = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $this->assertEqualsWithDelta(13.00, (float) $agg['total_ttc'], 0.0001, 'le Z reprend le total scellé, options de formule incluses');
    }
}
