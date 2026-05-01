<?php

namespace Tests\Feature;

use Tests\TestCase;

class KioskOfflinePaymentScopeTest extends TestCase
{
    public function test_kiosk_cancel_paths_use_order_status_enum_not_magic_literal(): void
    {
        $payment = file_get_contents(base_path('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'));
        $waiting = file_get_contents(base_path('resources/js/components/frontend/kiosk/KioskWaitingComponent.vue'));

        $this->assertStringContainsString('orderStatusEnum.CANCELED', $payment);
        $this->assertStringContainsString('STATUS_CANCELLED', $waiting);
        $this->assertStringNotContainsString('{ status: 16 }', $payment);
        $this->assertStringNotContainsString('{ status: 16 }', $waiting);
    }

    public function test_payment_confirm_contract_is_deferred_card_or_tr_only(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Frontend/OrderController.php'));

        $this->assertStringContainsString('PaymentGateway::CARD', $controller);
        $this->assertStringContainsString('PaymentGateway::TICKET_RESTAURANT', $controller);
        $this->assertStringContainsString('This order is not waiting for a deferred kiosk card payment.', $controller);
        $this->assertStringNotContainsString('$locked->payment_method = $request->payment_method', $controller);
    }
}
