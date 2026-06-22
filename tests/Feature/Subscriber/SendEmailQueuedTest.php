<?php

/**
 * [GENIE Wave 1 — T1.3 2026-06-16] SubscriberService::sendEmail did a synchronous Mail::bcc(all)->send()
 * — blocking the HTTP request on an SMTP round-trip to the whole subscriber table. Now it ->queue()s the
 * send (off the request thread). This pins: the mail is QUEUED, not sent synchronously.
 */

namespace Tests\Feature\Subscriber;

use App\Mail\SubscriberMail;
use App\Models\Subscriber;
use App\Services\SubscriberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendEmailQueuedTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_subscriber_email_is_queued_not_sent_synchronously(): void
    {
        Mail::fake();
        foreach (['a@x.test', 'b@x.test', 'c@x.test'] as $e) {
            Subscriber::create(['email' => $e]);
        }

        $req = new \App\Http\Requests\SubscriberEmailRequest();
        $req->merge(['subject' => 'Promo', 'message' => 'Hello']);

        app(SubscriberService::class)->sendEmail($req);

        Mail::assertQueued(SubscriberMail::class);   // off the request thread
        Mail::assertNothingSent();                   // NOT a synchronous blast
    }

    /** @test */
    public function test_no_subscribers_no_mail(): void
    {
        Mail::fake();
        $req = new \App\Http\Requests\SubscriberEmailRequest();
        $req->merge(['subject' => 'x', 'message' => 'y']);

        app(SubscriberService::class)->sendEmail($req);

        Mail::assertNothingQueued();
    }
}
