<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\FrontendOrderService;
use App\Services\OrderService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M6-002 M5-102] Ventes simultanées refusées : le numéro de file d'une vente EN COURS
 * (pas encore validée) ne doit jamais être redonné à une autre vente.
 *
 * Modèle de l'instantané : en REPEATABLE-READ, la vente concurrente ne voit pas la commande que la
 * première est en train d'écrire. On le reproduit fidèlement en réservant un numéro SANS écrire la
 * commande : c'est exactement ce que « voit » l'autre transaction. L'ancienne allocation, qui ne
 * lisait que les commandes visibles, redonnait le même numéro (et ses reprises aussi, indéfiniment) ;
 * mesuré en réel sur MySQL : 9 ventes caisse sur 15 refusées en 422.
 *
 * La vraie concurrence (15 ventes simultanées sur MySQL) est prouvée par curl dans le rapport de la
 * grappe D (verif-globale-2026-10-21).
 */
class QueueNumberAllocatorConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use Concerns\HasPosQuoteBinding;

    public function test_a_number_reserved_by_a_sale_in_flight_is_never_reissued_caisse_then_borne(): void
    {
        config(['kiosk.queue_start_number' => 32]);
        $branch = Branch::factory()->create();

        // Vente caisse en cours : numéro réservé, commande pas encore visible des autres.
        $caisse = $this->allocate(app(OrderService::class), $branch->id, '2026-10-22', 'pos');
        // Borne au même instant : ne voit pas la commande de la caisse.
        $borne = $this->allocate(app(FrontendOrderService::class), $branch->id, '2026-10-22', 'kiosk');

        $this->assertSame('A0032', $caisse);
        $this->assertSame('A0033', $borne, 'la borne a reçu le numéro de la vente caisse en cours');
    }

    public function test_successive_retries_always_get_a_fresh_number(): void
    {
        config(['kiosk.queue_start_number' => 32]);
        $branch = Branch::factory()->create();
        $service = app(OrderService::class);

        // Trois réservations sans commande visible = la 1re vente + les reprises d'une vente refusée :
        // chaque reprise doit avancer, pas retenter le même numéro.
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->allocate($service, $branch->id, '2026-10-22', 'pos');
        }

        $this->assertSame(['A0032', 'A0033', 'A0034'], $numbers);
    }

    public function test_a_rolled_back_sale_gives_its_number_back(): void
    {
        config(['kiosk.queue_start_number' => 32]);
        $branch = Branch::factory()->create();
        $service = app(OrderService::class);

        try {
            DB::transaction(function () use ($service, $branch): void {
                $this->assertSame('A0032', $this->allocate($service, $branch->id, '2026-10-22', 'pos'));
                throw new RuntimeException('vente refusée (tiroir fermé, etc.)');
            });
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame('A0032', $this->allocate($service, $branch->id, '2026-10-22', 'pos'),
            'une vente annulée ne doit pas laisser de trou dans la file');
    }

    public function test_counter_resyncs_on_orders_created_without_it(): void
    {
        config(['kiosk.queue_start_number' => 32]);
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        // Commande déjà en base (créée avant le compteur, ou par le simulateur).
        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-10-22',
            'queue_number' => 'A0040',
        ]);

        $this->assertSame('A0041', $this->allocate(app(FrontendOrderService::class), $branch->id, '2026-10-22', 'kiosk'));
        $this->assertSame('A0042', $this->allocate(app(OrderService::class), $branch->id, '2026-10-22', 'pos'));
        // Autre branche, même jour : sa propre file.
        $other = Branch::factory()->create();
        $this->assertSame('A0032', $this->allocate(app(OrderService::class), $other->id, '2026-10-22', 'pos'));
    }

    /**
     * La vente caisse écrit aussi la chaîne d'audit (service GELÉ), qui relit son dernier maillon SANS
     * verrou, donc dans l'instantané de la transaction. Mesuré sur MySQL avec le seul compteur : 0 doublon
     * de file mais 9 ventes sur 15 refusées sur `audit_logs_branch_prev_unique`. La ligne compteur doit
     * donc être verrouillée en PREMIÈRE instruction de la transaction : la vente suivante attend le COMMIT
     * AVANT sa première lecture, et son instantané contient la vente précédente.
     */
    public function test_pos_sale_locks_the_queue_counter_before_any_read_of_its_transaction(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $tax = Tax::create([
            'name' => 'TVA 10%', 'code' => 'TVA10VGD', 'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::create(['name' => 'Cat VGD', 'slug' => 'cat-vgd', 'status' => Status::ACTIVE]);
        $branch = Branch::factory()->create();
        $item = Item::create([
            'name' => 'Plat VGD', 'slug' => 'plat-vgd', 'price' => 10.00, 'tax_id' => $tax->id,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');

        $payload = $this->payloadWithPosQuote($cashier, [
            'customer_id' => $cashier->id,
            'branch_id' => $branch->id,
            'total' => 11.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::TICKET_RESTAURANT,
            'pos_payment_note' => 'VG-D',
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ]);

        $events = [];
        Event::listen(TransactionBeginning::class, function () use (&$events): void {
            $events[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$events): void {
            $events[] = $query->sql;
        });

        $response = $this->actingAs($cashier)
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/pos', $payload);
        $response->assertStatus(201);

        $first = null;
        foreach ($events as $i => $event) {
            if ($event !== 'BEGIN' && str_contains($event, 'order_queue_counters')) {
                $first = $i;
                break;
            }
        }
        $this->assertNotNull($first, 'la vente caisse ne verrouille pas le compteur de file');
        // BEGIN de la vente puis BEGIN (point de sauvegarde) de la réservation : aucune lecture entre les deux.
        $this->assertSame(['BEGIN', 'BEGIN'], array_slice($events, $first - 2, 2),
            'le compteur de file doit être verrouillé AVANT toute lecture de la transaction de vente ; vu avant : '
            .json_encode(array_slice($events, max(0, $first - 4), 4)));
        foreach (array_slice($events, 0, $first) as $event) {
            $this->assertStringNotContainsString('insert into "orders"', (string) $event);
        }
        $this->assertSame('A0032', Order::query()->findOrFail((int) $response->json('data.id'))->queue_number);
    }

    private function allocate(object $service, int $branchId, string $businessDate, string $context): string
    {
        $method = new ReflectionMethod($service, 'allocateQueueNumber');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $branchId, $businessDate, $context);
    }
}
