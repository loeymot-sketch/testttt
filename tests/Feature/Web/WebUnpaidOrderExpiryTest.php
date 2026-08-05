<?php

namespace Tests\Feature\Web;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-8AXES V7 T-5.1.2 2026-08-05] Une carte WEB abandonnée (créée avant le
 * paiement Mollie — funnel.jsx:607 — puis onglet fermé / retour navigateur /
 * expiration sans webhook) restait UNPAID pour TOUJOURS : polluait l'historique
 * client et le suivi (« EN PRÉPARATION » mensonger), sans jamais expirer.
 *
 * La garde caisse existe déjà (R1 SÉCU 2026-08-04 : web PENDING+UNPAID carte
 * exclue de la file « Commandes web ») ; ce test verrouille le 2e étage :
 * la PURGE. Contrat :
 *  - web + PENDING + UNPAID + carte en ligne + plus vieille que le TTL → REJECTED ;
 *  - une carte web PAYÉE n'est JAMAIS touchée ;
 *  - une web récente (dans le TTL) n'est pas touchée ;
 *  - une web COMPTOIR (PENDING_COUNTER — client qui viendra payer) n'est PAS
 *    purgée par ce chemin (son cycle légitime est le counter-collect).
 *  - NF525 : uniquement des lignes SANS fiscal_sequence_no.
 */
class WebUnpaidOrderExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
    }

    private function makeWebOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'source_surface' => 'web',
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CARD,
            'fiscal_sequence_no' => null,
            'created_at' => now()->subHours(3),
            'order_datetime' => now()->subHours(3),
        ], $attrs));
    }

    public function test_stale_unpaid_web_card_order_is_rejected(): void
    {
        $stale = $this->makeWebOrder();

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(
            OrderStatus::REJECTED,
            (int) $stale->refresh()->status,
            'Une carte web abandonnée au-delà du TTL doit être rejetée (fin du fantôme « en préparation »).'
        );
    }

    public function test_recent_unpaid_web_card_order_is_untouched(): void
    {
        $recent = $this->makeWebOrder(['created_at' => now()->subMinutes(5), 'order_datetime' => now()->subMinutes(5)]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::PENDING, (int) $recent->refresh()->status,
            'Un paiement 3DS peut prendre plusieurs minutes — pas de purge dans le TTL.');
    }

    public function test_paid_web_order_is_never_touched(): void
    {
        $paid = $this->makeWebOrder([
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::ACCEPT, (int) $paid->refresh()->status);
        $this->assertSame(PaymentStatus::PAID, (int) $paid->refresh()->payment_status);
    }

    public function test_web_counter_order_awaiting_pickup_is_not_purged_by_this_path(): void
    {
        $counter = $this->makeWebOrder([
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'status' => OrderStatus::PENDING,
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::PENDING, (int) $counter->refresh()->status,
            'Le client qui paiera au comptoir n\'est pas un abandon de paiement en ligne.');
    }
}
