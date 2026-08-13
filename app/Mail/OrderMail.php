<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */

    public string $name;
    public int $orderId;
    public mixed $message;

    public function __construct($name, $orderId, $message)
    {
        $this->name    = $name;
        $this->orderId = $orderId;
        $this->message = $message;
    }

    public function build()
    {
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] adversarial-dispute
        // finding on the SubscriberMail subject fix: same bug — a hardcoded,
        // non-identifying subject while the order id only appears in the
        // body. An inbox full of "Order Notification" is unusable for
        // telling which order a message concerns. Also ADR-007 FR-lock.
        return $this->subject("Commande #{$this->orderId} — Le Cayenne")->markdown('emails.order');
    }
}
