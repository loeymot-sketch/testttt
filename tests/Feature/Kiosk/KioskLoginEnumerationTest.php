<?php

namespace Tests\Feature\Kiosk;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Enums\Ask;
use App\Models\KioskMachine;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

/**
 * F3 — login must not leak account state. The distinct inactive-machine /
 * inactive-user messages used to be returned BEFORE the password check, giving
 * an attacker a username-enumeration + account-state oracle with no credentials.
 * After the heal the password is verified first; the specific messages are only
 * reachable with a valid password (legitimate staff keep the helpful copy).
 */
class KioskLoginEnumerationTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => '123456']);
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    private function makeMachine(int $machineStatus, int $userStatus = 5): void
    {
        $branch = Branch::forceCreate(['name' => 'B', 'city' => 'Paris', 'state' => 'IDF', 'zip_code' => '75000', 'address' => '1 rue', 'status' => 1]);
        $user = User::forceCreate([
            'name' => 'U', 'email' => 'u' . uniqid() . '@e.com', 'username' => 'u' . uniqid(),
            'password' => bcrypt('password'), 'status' => $userStatus,
        ]);
        KioskMachine::forceCreate([
            'machine_id' => (string) random_int(100000, 999999),
            'username' => 'borne1',
            'password' => bcrypt('secret123'),
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'is_login' => Ask::NO,
            'status' => $machineStatus,
        ]);
    }

    private function loginMsg(array $payload): ?string
    {
        return $this->postJson('/api/auth/kiosk-login', $payload)->json('errors.validation');
    }

    /** Wrong password on an INACTIVE machine must return the SAME generic message as a not-found username. */
    public function test_wrong_password_inactive_machine_is_indistinguishable_from_not_found(): void
    {
        $this->makeMachine(10); // machine inactive (status != ACTIVE=5)
        $inactive = $this->loginMsg(['username' => 'borne1', 'password' => 'wrong-password']);
        $notFound = $this->loginMsg(['username' => 'nope-' . uniqid(), 'password' => 'wrong-password']);
        $this->assertSame($notFound, $inactive, 'inactive-machine + wrong pwd must equal the not-found generic message');
        $this->assertNotSame(trans('all.message.kiosk_machine_inactive'), $inactive, 'must not leak machine-inactive state');
    }

    /** Wrong password on an INACTIVE linked user must not leak 'user inactive'. */
    public function test_wrong_password_inactive_user_does_not_leak(): void
    {
        $this->makeMachine(Status::ACTIVE, 10); // active machine, inactive user
        $msg = $this->loginMsg(['username' => 'borne1', 'password' => 'wrong-password']);
        $this->assertNotSame(trans('all.message.kiosk_user_inactive'), $msg);
        $this->assertSame(trans('all.message.credentials_invalid'), $msg);
    }

    /** Legit staff with the CORRECT password still get the helpful inactive message (UX preserved). */
    public function test_correct_password_inactive_machine_still_informative(): void
    {
        $this->makeMachine(10);
        $msg = $this->loginMsg(['username' => 'borne1', 'password' => 'secret123']);
        $this->assertSame(trans('all.message.kiosk_machine_inactive'), $msg);
    }

    /** Sanity: an active machine with correct credentials still logs in (201). */
    public function test_active_machine_correct_password_logs_in(): void
    {
        $this->makeMachine(Status::ACTIVE);
        $this->postJson('/api/auth/kiosk-login', ['username' => 'borne1', 'password' => 'secret123'])
            ->assertStatus(201);
    }
}
