<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderGotMail;
use App\Mail\OrderMail;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Adversarial-dispute finding
 * on the SubscriberMail subject fix: OrderMail and OrderGotMail have the
 * identical bug — a hardcoded, non-identifying English subject while the
 * order id/serial only ever appears in the body. An inbox full of "Order
 * Notification" / "You got a new order" is unusable for telling which order
 * any message concerns. Also brings both into ADR-007 FR-lock compliance,
 * matching the already-fixed SignupOtpMail / WheelPrizeMail siblings.
 */
class OrderMailSubjectTest extends TestCase
{
    public function test_order_mail_subject_identifies_the_order(): void
    {
        $mail = new OrderMail('Client Test', 42, 'Votre commande est en préparation.');
        $mail->build();

        $this->assertStringContainsString('42', $mail->subject);
        $this->assertStringNotContainsString('Order Notification', $mail->subject);
    }

    public function test_order_got_mail_subject_identifies_the_order(): void
    {
        // order_serial_no is a numeric-string column in practice (e.g. "2805266"),
        // matching OrderGotMail's strictly-typed `int $orderId` constructor param.
        $mail = new OrderGotMail('2805266', 'Nouvelle commande reçue.');
        $mail->build();

        $this->assertStringContainsString('2805266', $mail->subject);
        $this->assertStringNotContainsString('You got a new order', $mail->subject);
    }
}
