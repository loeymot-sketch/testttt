<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [C4-CAISSE-TELEPHONE 2026-07-07] Mode « Commande téléphone » à la caisse.
 *
 * Le caissier prend une commande par téléphone : elle est ENREGISTRÉE + envoyée en cuisine
 * à l'avance, mais N'EST PAS encaissée maintenant — le paiement est DIFFÉRÉ jusqu'à ce que le
 * client vienne la chercher (encaissement au comptoir via la file « à encaisser » existante).
 *
 * Preuves :
 *  (a) Création → auto-accept/prepare (board-release cuisine) + PENDING_COUNTER + COUNTER_DEFERRED
 *      + CASH_ON_DELIVERY + source_surface='phone' + fiscal_sequence_no NULL (NF525 : aucune
 *      séquence fiscale sur une commande non encaissée) + nom/téléphone client persistés.
 *  (b) Elle apparaît dans la file /counter-collect/pending (sinon INENCAISSABLE).
 *  (c) Encaissement counter-collect ultérieur → PAID + fiscal_sequence_no alloué + CHAIN OK.
 *
 * Mirroir du harness PosWalkinDeferredCreateTest (simulation_hardware ON → pas de tiroir requis ;
 * TVA 0% item simple, pas de wizard profile).
 */
class PhoneOrderDeferredTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('pos.walkin_route_to_counter', false); // prouve que phone_order suffit seul
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030400',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl', 'price' => 1.50, 'status' => Status::ACTIVE,
        ]);
    }

    /**
     * Payload « commande téléphone » tel que l'envoie phoneOrderSubmit (PosComponent.vue) :
     * pas de saisie de paiement, marqueur phone_order, nom + téléphone client.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function phonePayload(array $overrides = []): array
    {
        return array_merge([
            'token' => '7',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => null,
            'phone_order' => true,
            'pos_customer_name' => 'Madame Durand',
            'pos_customer_phone' => '06 12 34 56 78',
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 1.50,
                'quantity' => 2,
                'total_price' => 3.00,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    private function createPhoneOrder(array $overrides = []): Order
    {
        // Un ordre POS DOIT porter un devis scellé (quote_token + signature) — exactement ce que
        // fait phoneOrderSubmit (PosComponent.vue) en appelant /admin/pos/quote d'abord.
        $payload = $this->payloadWithPosQuote($this->operator, $this->phonePayload($overrides));

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'phone-'.uniqid())
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(201);

        return Order::withoutGlobalScopes()->find($response->json('data.id'));
    }

    /** @test */
    public function commande_telephone_est_creee_differee_sans_sequence_fiscale(): void
    {
        $order = $this->createPhoneOrder();

        // Différé : pas encaissée, marqueur counter-collect complet.
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status);
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $order->pos_payment_method);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $order->payment_method);

        // Canal distinct → file d'encaissement / historique / KDS badge « Tél ».
        $this->assertSame('phone', (string) $order->source_surface);

        // NF525 : AUCUNE séquence fiscale allouée tant que non encaissée.
        $this->assertNull($order->fiscal_sequence_no, 'commande téléphone non encaissée ne doit PAS brûler de séquence fiscale');

        // Envoyée en cuisine à l'avance (auto-accept + board-release → PREPARING).
        $this->assertSame(OrderStatus::PREPARING, (int) $order->status);

        // Nom + téléphone client persistés.
        $this->assertSame('Madame Durand', (string) $order->pos_customer_name);
        $this->assertSame('06 12 34 56 78', (string) $order->pos_customer_phone);
    }

    /** @test */
    public function commande_telephone_apparait_dans_la_file_a_encaisser(): void
    {
        $order = $this->createPhoneOrder();

        $res = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/counter-collect/pending');
        $res->assertOk();

        $rows = collect($res->json('data'));
        $ids = $rows->pluck('id')->all();
        $this->assertContains($order->id, $ids, 'la commande téléphone DOIT être visible dans la file à encaisser');

        // Le canal + le nom/téléphone remontent pour le libellé « Tél » et le rappel client.
        $row = $rows->firstWhere('id', $order->id);
        $this->assertSame('phone', $row['source_surface']);
        $this->assertSame('Madame Durand', $row['pos_customer_name']);
        $this->assertSame('06 12 34 56 78', $row['pos_customer_phone']);
    }

    /** @test */
    public function encaissement_a_l_arrivee_scelle_paid_avec_fiscal_et_chaine_intacte(): void
    {
        $order = $this->createPhoneOrder();
        $this->assertNull($order->fiscal_sequence_no);

        // Le client arrive → encaissement normal (counter-collect existant), espèces.
        $res = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'collect-'.$order->id)
            ->postJson('/api/admin/pos/counter-collect/'.$order->id.'/confirm', [
                'mode' => PosPaymentMethod::CASH,
                'received' => 10.00,
            ]);
        $res->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_no, 'la séquence fiscale s\'alloue à l\'encaissement');

        // CHAIN OK — la chaîne d'audit HMAC reste intacte (branche + globale).
        $audit = app(AuditLogService::class);
        $this->assertNull($audit->verifyChain($this->branch->id), 'chaîne d\'audit branche corrompue');
        $this->assertNull($audit->verifyChain(null), 'chaîne d\'audit globale corrompue');
    }

    /** @test */
    public function commande_telephone_ne_brule_pas_de_sequence_meme_flag_global_off(): void
    {
        // Garde de non-régression : sans walkin_route_to_counter, un POS normal (sans phone_order)
        // reste PAID+fiscal à la création — seul phone_order (ou defer_to_counter) diffère.
        $normalPayload = $this->payloadWithPosQuote($this->operator, $this->phonePayload([
            'phone_order' => false,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
        ]));
        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'normal-'.uniqid())
            ->postJson('/api/admin/pos', $normalPayload);
        $response->assertStatus(201);

        $normal = Order::withoutGlobalScopes()->find($response->json('data.id'));
        $this->assertSame(PaymentStatus::PAID, (int) $normal->payment_status);
        $this->assertNotNull($normal->fiscal_sequence_no);
        $this->assertSame('pos', (string) $normal->source_surface, 'sans phone_order le canal reste pos');
    }
}
