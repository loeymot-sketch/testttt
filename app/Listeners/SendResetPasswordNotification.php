<?php

namespace App\Listeners;

use App\Events\SendResetPassword;
use App\Mail\ResetPassword;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * [SEC-H15-TIMING-01 heal 2026-06-14 — ultra-review].
 * Queued so the reset email is sent OUT OF BAND. The forgot-password endpoint
 * already returns an identical neutral 200 for known/unknown emails, but it
 * dispatched THIS notification synchronously ONLY for a real user — and the
 * synchronous SMTP send (MAIL_MAILER=smtp) added ~100-500ms of latency to the
 * known-email path, re-opening the enumeration oracle through response TIMING.
 * Implementing ShouldQueue defers the SMTP send to the worker, so the HTTP
 * response time no longer branches on account existence (the residual ~1ms DB
 * insert is below remote-timing measurement noise).
 */
class SendResetPasswordNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     *
     * @param \App\Events\SendResetPassword $event
     * @return void
     */
    public function handle(SendResetPassword $event)
    {
        try {
            Mail::to($event->info['email'])->send(new ResetPassword($event->info['pin']));
        } catch (Exception $e) {
            Log::info($e->getMessage());
        }
    }
}
