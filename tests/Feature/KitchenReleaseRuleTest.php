<?php

namespace Tests\Feature;

use App\Domain\Kds\KitchenReleaseRule;
use App\Enums\OrderType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use Tests\TestCase;

class KitchenReleaseRuleTest extends TestCase
{
    public function test_kitchen_release_predicate_allows_only_forward_kds_transitions(): void
    {
        $this->assertTrue(KitchenReleaseRule::canTransition(
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING
        ));
        $this->assertTrue(KitchenReleaseRule::canTransition(
            OrderStatus::PREPARING,
            OrderStatus::PREPARED
        ));

        $this->assertFalse(KitchenReleaseRule::canTransition(
            OrderStatus::PREPARING,
            OrderStatus::CANCELED
        ));
        $this->assertFalse(KitchenReleaseRule::canTransition(
            OrderStatus::PREPARED,
            OrderStatus::DELIVERED
        ));
    }

    public function test_kitchen_release_dispatch_predicate_dedupes_noop_status(): void
    {
        $this->assertFalse(KitchenReleaseRule::shouldDispatchStatusChanged(
            OrderStatus::ACCEPT,
            OrderStatus::ACCEPT
        ));

        $this->assertTrue(KitchenReleaseRule::shouldDispatchStatusChanged(
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING
        ));
    }

    public function test_kitchen_release_statuses_are_kds_visible_only(): void
    {
        $this->assertSame([
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
        ], KitchenReleaseRule::visibleStatuses());

        $this->assertFalse(KitchenReleaseRule::isVisibleStatus(OrderStatus::PENDING));
        $this->assertFalse(KitchenReleaseRule::isVisibleStatus(OrderStatus::DELIVERED));
    }

    public function test_released_to_kitchen_requires_paid_order_after_accept(): void
    {
        $this->assertFalse(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::PENDING,
            PaymentStatus::PAID,
            OrderType::KIOSK,
            null
        ));

        $this->assertFalse(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::ACCEPT,
            PaymentStatus::UNPAID,
            OrderType::KIOSK,
            null
        ));

        $this->assertTrue(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::ACCEPT,
            PaymentStatus::PAID,
            OrderType::KIOSK,
            null
        ));

        $this->assertTrue(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::PREPARING,
            PaymentStatus::PAID,
            OrderType::POS,
            PosPaymentMethod::CARD
        ));
    }

    public function test_pos_cash_is_released_to_kitchen_before_paid_flag(): void
    {
        $this->assertTrue(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::ACCEPT,
            PaymentStatus::UNPAID,
            OrderType::POS,
            PosPaymentMethod::CASH
        ));

        $this->assertFalse(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::PENDING,
            PaymentStatus::UNPAID,
            OrderType::POS,
            PosPaymentMethod::CASH
        ));
    }

    public function test_board_release_admits_pending_counter_and_paid_and_pos_cash(): void
    {
        // Board predicate (what list() shows + what changeStatus() lets a chef bump).
        $this->assertTrue(KitchenReleaseRule::isReleasedForBoard(
            PaymentStatus::PAID,
            OrderType::DELIVERY,
            null
        ));

        // PENDING_COUNTER — Plan B kiosk→counter encashment: on the board even
        // though payment is not yet secured. This is the key difference from
        // isReleasedToKitchen(), which excludes it.
        $this->assertTrue(KitchenReleaseRule::isReleasedForBoard(
            PaymentStatus::PENDING_COUNTER,
            OrderType::KIOSK,
            null
        ));
        $this->assertFalse(KitchenReleaseRule::isReleasedToKitchen(
            OrderStatus::ACCEPT,
            PaymentStatus::PENDING_COUNTER,
            OrderType::KIOSK,
            null
        ));

        $this->assertTrue(KitchenReleaseRule::isReleasedForBoard(
            PaymentStatus::UNPAID,
            OrderType::POS,
            PosPaymentMethod::CASH
        ));
    }

    public function test_board_release_blocks_unpaid_non_cash_orders(): void
    {
        $this->assertFalse(KitchenReleaseRule::isReleasedForBoard(
            PaymentStatus::UNPAID,
            OrderType::DELIVERY,
            null
        ));

        $this->assertFalse(KitchenReleaseRule::isReleasedForBoard(
            PaymentStatus::UNPAID,
            OrderType::POS,
            PosPaymentMethod::CARD
        ));
    }
}
