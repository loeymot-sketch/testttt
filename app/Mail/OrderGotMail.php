<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderGotMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */

    public int $orderId;
    public mixed $message;

    public function __construct($orderId, $message)
    {
        $this->orderId = $orderId;
        $this->message = $message;
    }

    public function build()
    {
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] adversarial-dispute
        // finding on the SubscriberMail subject fix: same bug — see
        // OrderMail.php for the full rationale.
        return $this->subject("Nouvelle commande #{$this->orderId} — Le Cayenne")->markdown('emails.orderGot');
    }
}
