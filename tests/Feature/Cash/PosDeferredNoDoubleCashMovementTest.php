<?php

namespace Tests\Feature\Cash;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V2 2026-07-02 — P2 cash-trail] Régression du double/phantom cash_movement.
 *
 * BUG (refute V2) : posOrderStore enregistrait un cash_movement à la CRÉATION en se basant sur
 * `$request->pos_payment_method` (valeur BRUTE client = CASH même quand defer_to_counter=1), sans
 * gate `$deferToCounter`. Une commande DIFFÉRÉE (PENDING_COUNTER, non payée) écrivait donc un
 * cash-in à la création PUIS un 2e à l'encaissement → double mouvement de tiroir (corruption
 * piste NF525 / réconciliation). Le fix gate la ligne par `&& ! $deferToCounter`
 * (OrderService.php ~1260). La commande différée reçoit son mouvement UNIQUEMENT à l'encaissement.
 */
class PosDeferredNoDoubleCashMovementTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        // Isoler le flag defer explicite (pas via la config globale walk-in).
        config(['pos.walkin_route_to_counter' => false]);
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /** @return array{0: User, 1: Branch, 2: Item} */
    private function fixture(): array
    {
        $tax = Tax::create(['name' => 'TVA 10%', 'code' => 'TVA10', 'tax_rate' => 10, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::create(['name' => 'Cat', 'slug' => 'cat', 'status' => Status::ACTIVE]);
        $branch = Branch::factory()->create();
        $item = Item::create(['name' => 'Item', 'slug' => 'item', 'price' => 7.00, 'tax_id' => $tax->id, 'status' => Status::ACTIVE, 'item_category_id' => $category->id]);
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        return [$admin, $branch, $item];
    }

    private function basePayload(User $admin, Branch $branch, Item $item): array
    {
        return [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'subtotal' => 7.00,
            'total' => 7.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 7.00,
            'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
        ];
    }

    /** Une commande DIFFÉRÉE (defer_to_counter=1) NE DOIT enregistrer AUCUN cash_movement à la création. */
    public function test_deferred_cash_order_records_zero_cash_movement_at_creation(): void
    {
        [$admin, $branch, $item] = $this->fixture();
        // Session tiroir OUVERTE (conditions réelles de la caisse en service — la repro live
        // du bug #5426 avait une session ouverte). Le point du test : MÊME session ouverte,
        // une commande DIFFÉRÉE ne doit PAS écrire de mouvement à la création (avant fix : 1).
        $this->seedOpenSessionFor($admin, $branch);
        $payload = $this->basePayload($admin, $branch, $item) + ['defer_to_counter' => true];

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');

        $this->assertSame(
            0,
            DB::table('cash_movements')->where('order_id', $orderId)->count(),
            'Une commande différée ne doit PAS créer de mouvement de tiroir à la création (il sera créé à l\'encaissement).'
        );
    }

    /** Régression : le chemin CASH immédiat (non différé) enregistre TOUJOURS exactement 1 mouvement. */
    public function test_immediate_cash_order_records_exactly_one_cash_movement(): void
    {
        [$admin, $branch, $item] = $this->fixture();
        $this->seedOpenSessionFor($admin, $branch); // CASH immédiat exige une session ouverte.
        $payload = $this->basePayload($admin, $branch, $item); // pas de defer_to_counter

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');

        $this->assertSame(
            1,
            DB::table('cash_movements')->where('order_id', $orderId)->count(),
            'Le chemin CASH immédiat doit enregistrer exactement 1 mouvement de tiroir (le fix ne doit pas le casser).'
        );
    }

    /**
     * [ULTRA-AUDIT V3 2026-07-02 — modèle owner « caisse = INLINE »] Avec walkin_route_to_counter=false
     * (config live actuelle), une commande caisse CASH non-différée doit être PAYÉE INLINE :
     * payment_status=PAID, séquence fiscale allouée À LA CRÉATION, 1 cash_movement, et elle NE DOIT PAS
     * apparaître dans la file « à encaisser » (counter-collect/pending) — ça, c'est la borne (Plan B).
     */
    public function test_caisse_inline_order_is_paid_with_fiscal_and_absent_from_counter_queue(): void
    {
        [$admin, $branch, $item] = $this->fixture(); // setUp force walkin_route_to_counter=false
        $this->seedOpenSessionFor($admin, $branch);
        $payload = $this->basePayload($admin, $branch, $item); // CASH, pas de defer_to_counter

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $order = \App\Models\Order::withoutGlobalScopes()->findOrFail($orderId);

        // Payée INLINE : PAID + fiscal alloué à la création + 1 mouvement de tiroir.
        $this->assertSame(\App\Enums\PaymentStatus::PAID, (int) $order->payment_status, 'caisse inline = PAID immédiat');
        $this->assertNotNull($order->fiscal_sequence_no, 'caisse inline = séquence fiscale allouée à la création');
        $this->assertSame(1, DB::table('cash_movements')->where('order_id', $orderId)->count());

        // N'apparaît PAS dans la file « à encaisser » (réservée à la borne Plan B).
        $pending = $this->actingAs($admin)->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/pos/counter-collect/pending')->assertOk()->getContent();
        $this->assertStringNotContainsString('"id":' . $orderId, $pending, 'une commande caisse inline NE DOIT PAS être dans à-encaisser');
    }
}
