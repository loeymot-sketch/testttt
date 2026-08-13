<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriberMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */

    public string $title;
    public mixed $message;

    public function __construct($title, $message)
    {
        $this->title = $title;
        $this->message = $message;
    }

    public function build()
    {
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] NC-06: the real
        // Subject header must carry what the admin actually typed — it was
        // hardcoded to a generic English string (ADR-007 FR-lock violation)
        // while the entered title only ever appeared as body text.
        return $this->subject($this->title)->markdown('emails.subscriber');
    }
}
