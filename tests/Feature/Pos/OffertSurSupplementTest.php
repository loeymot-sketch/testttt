<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #5 2026-10-02] Bouton « Offert » sur un supplément / une sauce.
 *
 * Contrat : le PRIX reste décidé par le backend (PricingService, SSOT — jamais un prix envoyé par le
 * client). Le client n'envoie que des IDS d'extras à offrir (`item_extras_offered`) ; ces extras ne
 * passent PAS dans `item_extras` → PricingService les facture 0 par construction, sans que sa
 * logique bouge. L'offert est scellé dans le composition_snapshot (immuable), tracé dans l'audit
 * chaîné (qui / quoi / quand), et la ligne reste visible sur le ticket avec la mention « OFFERT ».
 */
class OffertSurSupplementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    private User $operator;

    private Branch $branch;

    private Item $item;

    private ItemExtra $cheddar;

    private ItemExtra $salade;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => 'test-api-key']);
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->operator->assignRole('POS Operator');
        $this->operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($this->operator, $this->branch);
        $customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $this->item = Item::factory()->create(['item_category_id' => $category->id, 'tax_id' => $tax->id, 'price' => 8.00, 'status' => Status::ACTIVE]);
        $this->cheddar = ItemExtra::create(['item_id' => $this->item->id, 'name' => 'Cheddar', 'price' => 0.90, 'status' => Status::ACTIVE]);
        $this->salade = ItemExtra::create(['item_id' => $this->item->id, 'name' => 'Salade', 'price' => 0, 'status' => Status::ACTIVE]);

        $this->payload = [
            'token' => null, 'customer_id' => $customer->id, 'branch_id' => $this->branch->id, 'subtotal' => 0, 'discount' => 0,
            'coupon_id' => 0, 'total' => 0, 'order_type' => OrderType::TAKEAWAY, 'is_advance_order' => Ask::NO,
            'source' => Source::POS, 'pos_payment_method' => PosPaymentMethod::CASH, 'pos_received_amount' => 0,
        ];
    }

    private function items(array $line): string
    {
        return json_encode([array_merge(['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []], $line)]);
    }

    private function quote(string $items)
    {
        return $this->actingAs($this->operator, 'sanctum')->postJson('/api/admin/pos/quote', array_merge($this->payload, ['items' => $items]));
    }

    private function commit(string $items, array $quote)
    {
        return $this->actingAs($this->operator, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($this->payload, [
                'items' => $items,
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]));
    }

    public function test_un_supplement_paye_coute_son_prix_par_defaut(): void
    {
        $q = $this->quote($this->items(['item_extras' => [['id' => $this->cheddar->id, 'quantity' => 2]]]))->assertOk()->json('data');

        $this->assertEqualsWithDelta(9.80, (float) $q['total_ttc'], 0.0001, 'référence : 8,00 + 2 × 0,90');
    }

    public function test_offert_met_la_ligne_a_zero_cote_backend_sans_prix_client(): void
    {
        $items = $this->items(['item_extras_offered' => [['id' => $this->cheddar->id, 'quantity' => 2]]]);

        $q = $this->quote($items)->assertOk()->json('data');

        $this->assertEqualsWithDelta(8.00, (float) $q['total_ttc'], 0.0001, 'les 2 cheddars offerts ne coûtent rien');
    }

    public function test_offert_partiel_une_unite_payee_une_offerte(): void
    {
        $items = $this->items([
            'item_extras' => [['id' => $this->cheddar->id, 'quantity' => 1]],
            'item_extras_offered' => [['id' => $this->cheddar->id, 'quantity' => 1]],
        ]);

        $q = $this->quote($items)->assertOk()->json('data');

        $this->assertEqualsWithDelta(8.90, (float) $q['total_ttc'], 0.0001);
    }

    public function test_commande_complete_snapshot_scelle_audit_et_ticket_OFFERT(): void
    {
        $items = $this->items([
            'item_extras' => [['id' => $this->cheddar->id, 'quantity' => 1]],
            'item_extras_offered' => [['id' => $this->cheddar->id, 'quantity' => 1]],
        ]);
        $quote = $this->quote($items)->assertOk()->json('data');

        $res = $this->commit($items, $quote);
        $this->assertContains($res->status(), [200, 201], $res->getContent());
        $orderId = (int) $res->json('data.id');

        // 1. Total scellé = 8,00 + 1 cheddar payé (le 2ᵉ est offert).
        $this->assertEqualsWithDelta(8.90, (float) Order::findOrFail($orderId)->total, 0.0001);

        // 2. Snapshot immuable : l'offert est une ligne VISIBLE à 0 €, avec son prix catalogue.
        $oi = OrderItem::where('order_id', $orderId)->firstOrFail();
        $extras = collect($oi->composition_snapshot['extras'] ?? []);
        $offert = $extras->first(fn ($e) => ! empty($e['offered']));
        $this->assertNotNull($offert, 'la ligne offerte reste dans le snapshot');
        $this->assertSame('Cheddar', $offert['extra_name']);
        $this->assertEqualsWithDelta(0.0, (float) $offert['line_total'], 0.0001);
        $this->assertEqualsWithDelta(0.90, (float) $offert['catalog_unit_price'], 0.0001, 'valeur offerte conservée pour l\'audit');

        // 3. Audit : qui, quoi, quand.
        $audit = AuditLog::where('action', 'order.line_offered')->where('resource_id', $orderId)->first();
        $this->assertNotNull($audit, 'trace d\'audit obligatoire');
        $this->assertSame($this->operator->id, (int) $audit->user_id, 'qui');
        $payload = is_array($audit->payload) ? $audit->payload : json_decode((string) $audit->payload, true);
        $this->assertSame('Cheddar', $payload['extra_name'], 'quoi');
        $this->assertSame(1, (int) $payload['quantity']);
        $this->assertEqualsWithDelta(0.90, (float) $payload['offered_value'], 0.0001);
        $this->assertNotNull($audit->created_at, 'quand');

        // 4. Ticket client : la ligne est imprimée avec « OFFERT ».
        $order = Order::with(['orderItems', 'branch'])->findOrFail($orderId);
        $order->fiscal_sequence_no = $order->fiscal_sequence_no ?: 1;
        $bytes = app(OrderReceiptEscPosRenderer::class)->renderClientTicket($order);
        $this->assertStringContainsString('OFFERT', $bytes);
        $this->assertStringContainsString('Cheddar', $bytes);
    }

    public function test_un_extra_gratuit_n_est_pas_offrable(): void
    {
        $this->quote($this->items(['item_extras_offered' => [['id' => $this->salade->id, 'quantity' => 1]]]))->assertStatus(422);
    }

    public function test_un_extra_d_un_autre_produit_est_refuse(): void
    {
        $other = Item::factory()->create(['item_category_id' => $this->item->item_category_id, 'tax_id' => $this->item->tax_id, 'price' => 5, 'status' => Status::ACTIVE]);
        $foreign = ItemExtra::create(['item_id' => $other->id, 'name' => 'Boursin', 'price' => 0.90, 'status' => Status::ACTIVE]);

        $this->quote($this->items(['item_extras_offered' => [['id' => $foreign->id, 'quantity' => 1]]]))->assertStatus(422);
    }

    public function test_les_formes_invalides_sont_refusees(): void
    {
        foreach ([
            [['id' => $this->cheddar->id, 'quantity' => 0]],
            [['id' => $this->cheddar->id, 'quantity' => 500]],
            [['id' => 0, 'quantity' => 1]],
            'oui',
        ] as $bad) {
            $this->quote($this->items(['item_extras_offered' => $bad]))->assertStatus(422);
        }
    }

    public function test_un_prix_envoye_par_le_client_est_ignore(): void
    {
        $items = $this->items(['item_extras_offered' => [['id' => $this->cheddar->id, 'quantity' => 1, 'price' => 99, 'unit_price' => 99, 'line_total' => 99]]]);

        $q = $this->quote($items)->assertOk()->json('data');

        $this->assertEqualsWithDelta(8.00, (float) $q['total_ttc'], 0.0001);
    }

    public function test_changer_l_offert_apres_le_devis_invalide_la_signature(): void
    {
        $paid = $this->items(['item_extras' => [['id' => $this->cheddar->id, 'quantity' => 1]]]);
        $quote = $this->quote($paid)->assertOk()->json('data');

        // Même devis, mais on tente de basculer l'extra en « offert » au commit.
        $tampered = $this->items(['item_extras_offered' => [['id' => $this->cheddar->id, 'quantity' => 1]]]);
        $res = $this->commit($tampered, $quote);

        $this->assertNotContains($res->status(), [200, 201], 'un devis signé payant ne peut pas devenir offert');
    }
}
