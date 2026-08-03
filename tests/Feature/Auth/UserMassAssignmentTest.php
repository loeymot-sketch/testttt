<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Sprint H1 Z6-05 2026-05-17] Public signup MUST NOT mass-assign
 * server-controlled fields (branch_id, is_guest, status). CLAUDE.md §9.
 *
 * PRIMARY-SOURCE CONTEXT (verified at audit time before authoring):
 *   - SignupController::register() reads only first_name / last_name / email
 *     / phone / country_code / password from $request->post(...) and
 *     hard-codes branch_id=0 and is_guest=Ask::NO in User::create([...]).
 *     status is omitted at create-time so DB default (Status::ACTIVE=5)
 *     applies via the migration.
 *   - SignupRequest::rules() does NOT validate branch_id / is_guest / status,
 *     so $request->validated() already does not expose them.
 *
 * THREAT MODEL:
 *   - Current vector: an attacker POST-ing branch_id, is_guest, status to
 *     /api/auth/signup/register cannot influence the persisted row because
 *     the controller enumerates fields explicitly.
 *   - FUTURE vector (the one Z6-05 hardens against): if a refactor later
 *     switches to `User::create($request->validated())` or adds those keys
 *     to rules(), the User model's permissive $fillable would silently
 *     persist attacker-controlled values.
 *
 * STRATEGY (per MASTER plan §9 H1.2 + post-audit reconciliation):
 *   - Defense-in-depth at the FormRequest layer: SignupRequest overrides
 *     validated() to unset the 3 sensitive keys before persistence.
 *   - This is a preventive lock — no behavior change visible to the
 *     current SignupController path (it reads $request->post(...)).
 *   - This test asserts the safe-behavior invariant (regression lock):
 *     attacker payload cannot escalate branch / is_guest / status no
 *     matter which call pattern the controller uses.
 *   - User::$fillable stays permissive for legacy admin tooling that
 *     explicitly sets these in trusted code (admin user-create UI,
 *     factory seeders, console commands).
 */
class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // SignupController::register() takes the early-success branch when
        // site_phone_verification == Activity::DISABLE (no OTP enforcement).
        // Without this row the controller falls into the `!$otp->exists()`
        // path which also short-circuits to success, but writing it
        // explicitly mirrors the H1.1 GuestSignupAbilityScopeTest pattern.
        DB::table('settings')->insert([
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** @test */
    public function test_public_signup_strips_branch_id_is_guest_status(): void
    {
        // Create a branch the attacker tries to forge into.
        $forgedBranch = Branch::factory()->create();

        $email = 'mallory_' . time() . '@example.com';

        $attackerPayload = [
            // Legitimate SignupRequest fields:
            'first_name'   => 'Mallory',
            'last_name'    => 'Attacker',
            'email'        => $email,
            'phone'        => '0612345678',
            'country_code' => '33',
            'password'     => 'attacker-secret-123',
            // ATTACKER MASS-ASSIGNMENT VECTORS — must be ignored server-side:
            'branch_id' => $forgedBranch->id,   // forge into another branch
            'is_guest'  => Ask::YES,             // claim guest status post-hoc
            'status'    => Status::INACTIVE,    // bypass any future moderation
        ];

        $response = $this->postJson('/api/auth/signup/register', $attackerPayload);

        // SignupController::register() returns 201 Created on success.
        $response->assertStatus(201);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'Signup should create the user row on success.');

        // THE LOAD-BEARING INVARIANTS (Z6-05):
        // No attacker payload value may influence these three fields.
        $this->assertSame(
            0,
            (int) $user->branch_id,
            'branch_id MUST be server-controlled (0 for public signup) — '
            . 'attacker forgery payload (branch_id=' . $forgedBranch->id . ') ignored.'
        );
        $this->assertSame(
            (int) Ask::NO,
            (int) $user->is_guest,
            'is_guest MUST be server-controlled (Ask::NO for non-guest signup) — '
            . 'attacker payload (is_guest=' . Ask::YES . ') ignored.'
        );
        $this->assertSame(
            (int) Status::ACTIVE,
            (int) $user->status,
            'status MUST be the DB default (Status::ACTIVE) — '
            . 'attacker payload (status=' . Status::INACTIVE . ') ignored. '
            . 'Future moderation flows must remain server-controlled.'
        );
    }

    /** @test */
    public function test_signup_request_validated_strips_sensitive_fields(): void
    {
        // Direct FormRequest-layer test: regardless of controller flow, the
        // validated() override must NOT expose branch_id / is_guest / status.
        // This is the load-bearing assertion for the future-vector — if any
        // future contributor adds these keys to rules() AND switches the
        // controller to User::create($request->validated()), the override
        // must still strip them.
        //
        // To prove the override is load-bearing (not vacuously true because
        // parent::validated() omits unvalidated keys), we use a test-only
        // SignupRequest subclass that DOES validate these keys, then verify
        // the override strips them anyway. This is the only way to assert
        // the override does real work — without it, parent::validated()
        // alone would expose the fields.
        $payload = [
            'first_name'   => 'Test',
            'last_name'    => 'User',
            'email'        => 'lock_' . time() . '@example.com',
            'phone'        => '0612345679',
            'country_code' => '33',
            'password'     => 'secret123',
            // Sensitive keys — the override below must strip them even when
            // rules() actively validates them (simulated future regression):
            'branch_id' => 99,
            'is_guest'  => Ask::YES,
            'status'    => Status::INACTIVE,
        ];

        $request = SignupRequestWithSensitiveRulesForTest::create(
            '/api/auth/signup/register',
            'POST',
            $payload
        );
        $request->setContainer(app());
        $request->setRedirector(app(\Illuminate\Routing\Redirector::class));
        // validateResolved() runs validation; afterwards $request->validated()
        // returns the rules()-validated subset.
        $request->validateResolved();

        $validated = $request->validated();

        $this->assertIsArray($validated);
        // First sanity-check: the test subclass actually validated the keys
        // (else this whole test would be vacuous).
        $this->assertArrayHasKey(
            'first_name',
            $validated,
            'Sanity check: rules() must validate legitimate fields.'
        );

        // THE LOAD-BEARING ASSERTIONS — the validated() override must strip:
        $this->assertArrayNotHasKey(
            'branch_id',
            $validated,
            'SignupRequest::validated() MUST strip branch_id (mass-assignment vector) '
            . 'even when rules() validates it. Defense-in-depth lock.'
        );
        $this->assertArrayNotHasKey(
            'is_guest',
            $validated,
            'SignupRequest::validated() MUST strip is_guest (privilege upgrade vector) '
            . 'even when rules() validates it. Defense-in-depth lock.'
        );
        $this->assertArrayNotHasKey(
            'status',
            $validated,
            'SignupRequest::validated() MUST strip status (moderation bypass vector) '
            . 'even when rules() validates it. Defense-in-depth lock.'
        );
    }
}

/**
 * [Sprint H1 Z6-05 2026-05-17] Test-only subclass that extends rules() to
 * actively validate the 3 sensitive keys. This simulates a future-vector
 * regression where a contributor broadens SignupRequest::rules() to accept
 * branch_id / is_guest / status from the request payload. The parent
 * validated() override MUST still strip these keys, proving the strip
 * layer is load-bearing rather than vacuously true.
 */
class SignupRequestWithSensitiveRulesForTest extends \App\Http\Requests\SignupRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'branch_id' => ['nullable', 'integer'],
            'is_guest'  => ['nullable', 'integer'],
            'status'    => ['nullable', 'integer'],
        ]);
    }
}
