<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [POINTS À LA VALIDATION 2026-11-06 · décision propriétaire]
 *
 * « C'est validé lorsque la commande est en cours » : une commande du site ou de l'application
 * crédite ses points dès que le restaurant la VALIDE (acceptée, en préparation, prête, en
 * livraison, remise), JAMAIS tant qu'elle est en attente. Le crédit n'a lieu qu'une fois sur
 * tout le parcours, et une annulation après validation le reprend.
 */
class PointsCreditesALaValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);
    }

    private function client(): User
    {
        return User::factory()->create(['loyalty_points' => 0, 'loyalty_code' => 'VAL' . strtoupper(substr(uniqid(), -6))]);
    }

    private function commande(User $user, int $orderType, int $status = OrderStatus::PENDING): FrontendOrder
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();

        return FrontendOrder::query()->create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'idempotency_key' => 'VAL-' . uniqid(),
            'total' => 12.40, 'subtotal' => 12.40, 'discount' => 0,
            'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
            'status' => $status, 'order_type' => $orderType, 'source_surface' => 'web',
            'order_datetime' => now(), 'preparation_time' => 15, 'business_date' => now()->toDateString(),
        ]);
    }

    private function solde(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->id)->value('loyalty_points');
    }

    private function passer(FrontendOrder $order, int $de, int $vers): void
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => $vers]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($order->fresh(), $de, $vers));
    }

    public function test_une_commande_en_attente_ne_rapporte_aucun_point(): void
    {
        $user = $this->client();
        $order = $this->commande($user, OrderType::DELIVERY);

        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($order, OrderStatus::PENDING, OrderStatus::PENDING));

        $this->assertSame(0, $this->solde($user), 'commande pas encore reçue par le restaurant : aucun point');
        $this->assertNull($order->fresh()->loyalty_points_awarded);
    }

    public function test_les_points_sont_credites_des_la_validation_pour_chaque_type_de_commande(): void
    {
        foreach ([OrderType::DELIVERY, OrderType::TAKEAWAY, OrderType::POS, OrderType::KIOSK] as $type) {
            $user = $this->client();
            $order = $this->commande($user, $type);

            $this->passer($order, OrderStatus::PENDING, OrderStatus::ACCEPT);

            $this->assertSame(124, $this->solde($user), "type {$type} : 12,40 € × 10 = 124 points dès l'acceptation");
            $this->assertSame(124, (int) $order->fresh()->loyalty_points_awarded);
        }
    }

    public function test_le_parcours_complet_ne_credite_qu_une_seule_fois(): void
    {
        $user = $this->client();
        $order = $this->commande($user, OrderType::DELIVERY);

        $this->passer($order, OrderStatus::PENDING, OrderStatus::ACCEPT);
        $this->passer($order, OrderStatus::ACCEPT, OrderStatus::PREPARING);
        $this->passer($order, OrderStatus::PREPARING, OrderStatus::PREPARED);
        $this->passer($order, OrderStatus::PREPARED, OrderStatus::OUT_FOR_DELIVERY);
        $this->passer($order, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED);

        $this->assertSame(124, $this->solde($user), 'en cours → en préparation → prête → en livraison → livrée : un seul crédit');
        $this->assertSame(1, DB::table('loyalty_transactions')->where('order_id', $order->id)->where('type', 'earn')->count());
    }

    public function test_une_commande_validee_directement_en_preparation_est_creditee(): void
    {
        $user = $this->client();
        $order = $this->commande($user, OrderType::TAKEAWAY);

        $this->passer($order, OrderStatus::PENDING, OrderStatus::PREPARING);

        $this->assertSame(124, $this->solde($user));
    }

    public function test_une_commande_rejetee_depuis_l_attente_ne_rapporte_rien(): void
    {
        $user = $this->client();
        $order = $this->commande($user, OrderType::DELIVERY);

        $this->passer($order, OrderStatus::PENDING, OrderStatus::REJECTED);

        $this->assertSame(0, $this->solde($user));
    }

    public function test_une_annulation_apres_validation_reprend_exactement_les_points(): void
    {
        $user = $this->client();
        $order = $this->commande($user, OrderType::DELIVERY);
        $this->passer($order, OrderStatus::PENDING, OrderStatus::ACCEPT);
        $this->assertSame(124, $this->solde($user));

        app(LoyaltyService::class)->clawbackEarnedPoints($user->id, 124, (int) $order->id, 'Clawback fidélité — commande annulée');

        $this->assertSame(0, $this->solde($user), 'annulée après validation : les points gagnés sont repris');
    }
}
