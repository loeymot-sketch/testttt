<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Models\Order;
use App\Services\DashboardService;
use Tests\TestCase;

/**
 * [F-EOD-TENDER-PRECEDENCE 2026-07-15 / P1] Le PDF de synthèse EOD (NF525, archivé 6 ans,
 * censé « agree with the Z ») bucketait les tenders CARTE/ticket-resto/mobile des commandes
 * borne Plan B en « Espèces », parce que resolvePaymentBucketKey n'exploitait pos_payment_method
 * que si order_type===POS. Or Le Cayenne tourne Plan B : la commande borne reste
 * order_type=TAKEAWAY/KIOSK mais confirmCounterPayment écrit le vrai tender dans pos_payment_method.
 * Le fix aligne exactement sur la précédence du Z (pos_payment_method ?: payment_method).
 */
class EodTenderPrecedenceSentinelTest extends TestCase
{
    private function bucketKey(Order $order): string
    {
        $svc = app(DashboardService::class);
        $m = new \ReflectionMethod($svc, 'resolvePaymentBucketKey');
        $m->setAccessible(true);
        return $m->invoke($svc, $order);
    }

    private function order(int $orderType, int $paymentMethod, int $posPaymentMethod): Order
    {
        return (new Order)->forceFill([
            'order_type' => $orderType,
            'payment_method' => $paymentMethod,
            'pos_payment_method' => $posPaymentMethod,
        ]);
    }

    /** LE BUG — commande borne Plan B payée CARTE au comptoir → 'card', PAS 'cash'. */
    public function test_plan_b_kiosk_card_tender_buckets_as_card(): void
    {
        $order = $this->order(OrderType::KIOSK, PaymentGateway::CASH_ON_DELIVERY, PosPaymentMethod::CARD);
        $this->assertSame('card', $this->bucketKey($order),
            'Une carte encaissée au comptoir sur une commande borne Plan B doit compter en CARTE, pas en espèces (sinon le PDF EOD contredit le Z).');
    }

    /** Plan B ticket-restaurant → 'ticket'. */
    public function test_plan_b_takeaway_ticket_restaurant_buckets_as_ticket(): void
    {
        $order = $this->order(OrderType::TAKEAWAY, PaymentGateway::CASH_ON_DELIVERY, PosPaymentMethod::TICKET_RESTAURANT);
        $this->assertSame('ticket', $this->bucketKey($order));
    }

    /** Plan B mobile → 'mobile'. */
    public function test_plan_b_kiosk_mobile_buckets_as_mobile(): void
    {
        $order = $this->order(OrderType::KIOSK, PaymentGateway::CASH_ON_DELIVERY, PosPaymentMethod::MOBILE_BANKING);
        $this->assertSame('mobile', $this->bucketKey($order));
    }

    /** NON-RÉGRESSION — une vente POS pure cash reste 'cash'. */
    public function test_pure_pos_cash_sale_still_cash(): void
    {
        $order = $this->order(OrderType::POS, PaymentGateway::CASH_ON_DELIVERY, PosPaymentMethod::CASH);
        $this->assertSame('cash', $this->bucketKey($order));
    }

    /** NON-RÉGRESSION — commande en ligne sans pos_payment_method → repli sur payment_method. */
    public function test_online_order_without_pos_method_falls_back_to_payment_method(): void
    {
        $order = $this->order(OrderType::DELIVERY, PaymentGateway::CARD, 0);
        $this->assertSame('card', $this->bucketKey($order));
    }
}
