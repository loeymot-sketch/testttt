<?php

namespace Tests\Feature\Abuse;

use App\Enums\Status;
use App\Models\Message;
use App\Models\MessageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR object-level-authz — ABUSE: broken object-level authorization (IDOR)
 * on the CUSTOMER-facing message self-thread surface.
 *
 * THREAT MODEL
 *   - routes/api.php:1367 mounts the `frontend/message` group behind
 *     'auth:sanctum' ONLY (no permission gate, no BranchScope on Message).
 *   - Frontend\MessageController::index -> MessageService::list() filters the
 *     thread by the CLIENT-SUPPLIED $request->user_id / $request->branch_id,
 *     NOT by the authenticated user. PaginateRequest::authorize() returns true
 *     and does not constrain user_id.
 *   - Frontend\MessageController::show(Message $message) route-model-binds an
 *     UN-scoped Message and returns it with no ownership check.
 *
 * RESULT (pre-heal): any auth:sanctum token (customer A, or an exfiltrated
 * kiosk:order token) can pass ?user_id=<victim>&branch_id=1 (index) or a
 * victim message id (show) and read another user's private message thread =
 * cross-user PII leak.
 *
 * NOTE — the ADMIN message group (routes/api.php:1143, Admin\MessageController)
 * is gated by 'permission:messages' and legitimately reads ANY thread. It
 * shares MessageService::list/show, so the heal MUST live on the FRONTEND
 * controller (force user_id = Auth::id()) and MUST NOT change the service
 * globally. These tests only exercise the /frontend/message surface.
 */
class FrontendMessageIdorAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders([
            'x-api-key' => 'test-api-key',
            'Accept'    => 'application/json',
        ]);
    }

    private function makeCustomer(string $email, string $phone): User
    {
        return User::factory()->create([
            'branch_id' => 1,
            'email'     => $email,
            'phone'     => $phone,
            'status'    => Status::ACTIVE,
        ]);
    }

    /**
     * Create a message thread owned by $owner (Message.user_id = owner) on
     * branch 1, with one history line authored by $author carrying $secretText.
     */
    private function makeThread(User $owner, User $author, string $secretText): Message
    {
        $message = Message::create([
            'branch_id' => 1,
            'user_id'   => $owner->id,
        ]);
        MessageHistory::create([
            'message_id' => $message->id,
            'user_id'    => $author->id,
            'text'       => $secretText,
            'is_read'    => 0,
        ]);

        return $message;
    }

    /**
     * ABUSE 1 (index IDOR) — customer A asks for customer B's thread by passing
     * ?user_id=<B>. Pre-heal the service trusts the client user_id and returns
     * B's messages. Post-heal the frontend controller forces user_id=Auth::id()
     * so A only ever sees A's own thread; B's secret must be ABSENT.
     */
    public function test_customer_cannot_read_another_users_thread_via_index_user_id(): void
    {
        $customerA = $this->makeCustomer('a@cayenne.test', '0600000010');
        $customerB = $this->makeCustomer('b@cayenne.test', '0600000011');

        $secretB = 'SECRET-B-PRIVATE-THREAD-' . uniqid();
        $this->makeThread($customerB, $customerB, $secretB);

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/frontend/message?branch_id=1&user_id=' . $customerB->id);

        $response->assertOk();

        // B's private content must NOT appear in A's response.
        $body = $response->getContent();
        $this->assertStringNotContainsString(
            $secretB,
            $body,
            "IDOR: customer A read customer B's private message via ?user_id=B. Body=" . $body
        );

        // And no returned thread should belong to B.
        foreach ($response->json('data', []) as $thread) {
            $this->assertNotEquals(
                $customerB->id,
                $thread['user_id'] ?? null,
                'IDOR: A received a thread owned by B'
            );
        }
    }

    /**
     * ABUSE 2 (show IDOR) — customer A opens customer B's message id directly.
     * The route-model-bound Message is un-scoped, so pre-heal it returns 200
     * with B's content. Post-heal it must be refused (403/404) and must not
     * leak B's secret text.
     */
    public function test_customer_cannot_read_another_users_message_via_show(): void
    {
        $customerA = $this->makeCustomer('a2@cayenne.test', '0600000020');
        $customerB = $this->makeCustomer('b2@cayenne.test', '0600000021');

        $secretB = 'SECRET-B-SHOW-' . uniqid();
        $threadB  = $this->makeThread($customerB, $customerB, $secretB);

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/frontend/message/show/' . $threadB->id);

        $this->assertContains(
            $response->status(),
            [403, 404],
            "IDOR: customer A read customer B's message via show; expected 403/404 got "
            . $response->status() . ' body=' . $response->getContent()
        );

        $this->assertStringNotContainsString(
            $secretB,
            $response->getContent(),
            "IDOR: show leaked B's private text to A"
        );
    }

    /**
     * POSITIVE CONTROL — a customer reading their OWN thread still works (index).
     * Guards against an over-broad heal that breaks the legitimate self-thread.
     */
    public function test_customer_can_read_own_thread_via_index(): void
    {
        $customerA = $this->makeCustomer('a3@cayenne.test', '0600000030');

        $ownSecret = 'OWN-A-THREAD-' . uniqid();
        $this->makeThread($customerA, $customerA, $ownSecret);

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        // Even if the client omits/forges user_id, A must see A's own thread.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/frontend/message?branch_id=1&user_id=' . $customerA->id);

        $response->assertOk();
        $this->assertStringContainsString(
            $ownSecret,
            $response->getContent(),
            'Regression: customer A can no longer read their OWN thread'
        );
    }

    /**
     * POSITIVE CONTROL — a customer opening their OWN message id still works (show).
     */
    public function test_customer_can_read_own_message_via_show(): void
    {
        $customerA = $this->makeCustomer('a4@cayenne.test', '0600000040');

        $ownSecret = 'OWN-A-SHOW-' . uniqid();
        $threadA   = $this->makeThread($customerA, $customerA, $ownSecret);

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/frontend/message/show/' . $threadA->id);

        $response->assertOk();
        $this->assertStringContainsString(
            $ownSecret,
            $response->getContent(),
            'Regression: customer A can no longer read their OWN message'
        );
    }

    /**
     * ABUSE 3 — an exfiltrated kiosk:order token (bound to a different user)
     * must not be able to harvest an arbitrary victim's thread by forging
     * ?user_id. Same primitive as ABUSE 1, framed from the kiosk-token threat.
     */
    public function test_kiosk_token_cannot_harvest_arbitrary_user_thread(): void
    {
        $kioskUser = $this->makeCustomer('kiosk@cayenne.test', '0600000050');
        $victim    = $this->makeCustomer('victim@cayenne.test', '0600000051');

        $secretVictim = 'SECRET-VICTIM-' . uniqid();
        $this->makeThread($victim, $victim, $secretVictim);

        $token = $kioskUser->createToken('kiosk-token', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/frontend/message?branch_id=1&user_id=' . $victim->id);

        $response->assertOk();
        $this->assertStringNotContainsString(
            $secretVictim,
            $response->getContent(),
            "IDOR: kiosk:order token harvested victim's private thread"
        );
    }
}
