<?php

namespace Tests\Feature\Abuse;

use App\Enums\Status;
use App\Models\Message;
use App\Models\MessageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR object-level-authz (WRITE side) — ABUSE: broken object-level
 * authorization (IDOR) on the CUSTOMER-facing message store surface. This is
 * the WRITE-side twin of the read-side IDOR already healed on
 * Frontend\MessageController::index/show/destroy (commit 2256e836b).
 *
 * THREAT MODEL
 *   - routes/api.php:1370 mounts `POST frontend/message` behind 'auth:sanctum'
 *     ONLY (no permission gate, no BranchScope on Message).
 *   - MessageRequest::authorize() returns true and its rules do NOT constrain
 *     message_id ownership (message_id is not even validated) nor receiver_id.
 *   - MessageService::store():
 *       * when $request->message_id is set, Message::find($request->message_id)
 *         is UN-SCOPED and a MessageHistory reply is appended to that thread
 *         with NO ownership check (write-injection into a victim's thread).
 *       * when no message_id (new thread), the new Message's owner is
 *         user_id = $request->receiver_id — client-supplied (forge an arbitrary
 *         user as the thread owner).
 *
 * RESULT (pre-heal): any auth:sanctum customer can (a) append a reply to
 * ANOTHER customer's private thread by guessing/sequential message_id, and/or
 * (b) open a thread OWNED by an arbitrary receiver_id.
 *
 * NOTE — the ADMIN message group (routes/api.php:1146, Admin\MessageController)
 * is gated by 'permission:messages' and legitimately creates a thread owned by
 * the target customer (admin messages a customer => user_id = receiver_id =
 * that customer). It shares MessageService::store, so the heal MUST live on the
 * FRONTEND controller and MUST NOT change the service. These tests only
 * exercise the /frontend/message store surface.
 */
class FrontendMessageStoreIdorAbuseTest extends TestCase
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
     * ABUSE (write-injection IDOR) — customer A appends a reply to customer B's
     * thread by passing message_id = B's thread id. Pre-heal the un-scoped
     * Message::find() lets the MessageHistory land in B's thread. Post-heal the
     * frontend controller refuses (403) and B's thread history is UNCHANGED.
     */
    public function test_customer_cannot_append_reply_to_another_users_thread(): void
    {
        $customerA = $this->makeCustomer('a-store@cayenne.test', '0600001010');
        $customerB = $this->makeCustomer('b-store@cayenne.test', '0600001011');

        $threadB = $this->makeThread($customerB, $customerB, 'B-ORIGINAL-' . uniqid());
        $beforeCount = MessageHistory::where('message_id', $threadB->id)->count();

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $injected = 'A-INJECTED-INTO-B-' . uniqid();
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/frontend/message', [
                'branch_id'  => 1,
                'user_id'    => $customerA->id,
                'is_read'    => 0,
                'text'       => $injected,
                'message_id' => $threadB->id,
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'IDOR: customer A appended to customer B\'s thread; expected 403/404 got '
            . $response->status() . ' body=' . $response->getContent()
        );

        // B's thread must be byte-identical: no injected reply landed.
        $this->assertSame(
            $beforeCount,
            MessageHistory::where('message_id', $threadB->id)->count(),
            'IDOR: an injected reply was written into B\'s thread'
        );
        $this->assertDatabaseMissing('message_histories', [
            'message_id' => $threadB->id,
            'text'       => $injected,
        ]);
    }

    /**
     * POSITIVE CONTROL — customer A replying to A's OWN thread still works.
     * Guards against an over-broad heal that breaks the legitimate self-reply.
     */
    public function test_customer_can_reply_to_own_thread(): void
    {
        $customerA = $this->makeCustomer('a2-store@cayenne.test', '0600001020');

        $threadA = $this->makeThread($customerA, $customerA, 'A-ORIGINAL-' . uniqid());

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $reply = 'A-OWN-REPLY-' . uniqid();
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/frontend/message', [
                'branch_id'  => 1,
                'user_id'    => $customerA->id,
                'is_read'    => 0,
                'text'       => $reply,
                'message_id' => $threadA->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('message_histories', [
            'message_id' => $threadA->id,
            'text'       => $reply,
        ]);
    }

    /**
     * ABUSE (forged-owner IDOR) — customer A opens a NEW thread but tries to
     * forge the owner via receiver_id = B. Pre-heal the new Message.user_id is
     * the client-supplied receiver_id (=> B owns A's thread). Post-heal the
     * created thread MUST be owned by A (Auth::id()), never the forged receiver.
     */
    public function test_new_thread_is_owned_by_authenticated_customer_not_forged_receiver(): void
    {
        $customerA = $this->makeCustomer('a3-store@cayenne.test', '0600001030');
        $customerB = $this->makeCustomer('b3-store@cayenne.test', '0600001031');

        $token = $customerA->createToken('frontend', ['kiosk:order']);

        $text = 'A-NEW-THREAD-' . uniqid();
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/frontend/message', [
                'branch_id'   => 1,
                'user_id'     => $customerB->id,   // forged via validated user_id
                'receiver_id' => $customerB->id,   // forged owner the service reads
                'is_read'     => 0,
                'text'        => $text,
                // no message_id => new-thread path
            ]);

        // store() returns 201 Created for a new thread (both pre- and post-heal;
        // the heal only changes the OWNER, not the status).
        $response->assertCreated();

        $createdId = $response->json('data.id');
        $this->assertNotNull($createdId, 'No thread id returned. body=' . $response->getContent());

        // The thread must be owned by A, NOT the forged receiver B.
        $this->assertDatabaseHas('messages', [
            'id'      => $createdId,
            'user_id' => $customerA->id,
        ]);
        $this->assertDatabaseMissing('messages', [
            'id'      => $createdId,
            'user_id' => $customerB->id,
        ]);
    }
}
