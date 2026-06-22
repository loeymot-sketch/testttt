<?php

namespace Tests\Feature\Abuse;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * VECTOR branch_rbac — ABUSE: leaked kiosk token reaches the /api/profile
 * surface that the J2-HEAL-02 BlockKioskTokenFromAdminRoutes middleware does
 * NOT cover.
 *
 * THREAT MODEL (identical to the one that justified
 * BlockKioskTokenFromAdminRoutes — see that middleware's docblock):
 *   - The kiosk borne sits in a public space.
 *   - Its Sanctum token (['kiosk:order'] ability, name 'kiosk-token') lives in
 *     device/localStorage and is exfiltratable via physical access / devtools /
 *     network sniffing.
 *   - In the default Le Cayenne config the KioskMachine is bound to the admin
 *     user admin@lecayenne.fr (KioskMachineTableSeeder:28-47), so the token's
 *     ->user() resolves to the ADMIN.
 *
 * The /api/admin/* groups are protected by 'block_kiosk_token_admin'
 * (routes/api.php:274,295). But /api/profile (routes/api.php:249) is guarded by
 * 'auth:sanctum' ONLY — no block_kiosk_token_admin, no permission gate, and
 * ProfileRequest::authorize() returns true.
 *
 * ABUSE 1 (info leak): GET /api/profile returns the admin's name/email/phone.
 * ABUSE 2 (account takeover): PUT /api/profile rewrites the admin's email with
 *   NO old-password proof (ProfileRequest has no old_password rule). Attacker
 *   sets email to one they control, then runs forgot-password = full takeover.
 *
 * NOTE: changePassword IS defended (ChangePasswordRequest requires old_password
 * via Hash::check) — that path is asserted as the positive control.
 */
class KioskTokenProfileEscalationAbuseTest extends TestCase
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

    private function makeAdminWithKioskTokenUser(): User
    {
        $admin = User::factory()->create([
            'branch_id' => 0,
            'email'     => 'admin@lecayenne.fr',
            'name'      => 'Le Cayenne Admin',
            'phone'     => '+33600000001',
            'password'  => Hash::make('AdminSecret123'),
            'status'    => Status::ACTIVE,
        ]);
        $admin->assignRole('Admin');

        // In the default Le Cayenne config a KioskMachine is bound to this admin
        // user (KioskMachineTableSeeder:28-47) — that is WHY the kiosk-token's
        // ->user() resolves to the admin. The binding row itself is not needed
        // to reproduce the abuse: the token is minted on the admin user below,
        // exactly as KioskMachineLoginController:106 does, so it carries admin
        // identity regardless. (KioskMachine import kept for documentation.)

        return $admin;
    }

    /**
     * ABUSE 2 — the dangerous one. A kiosk-only token rewrites the admin's
     * email with no old-password proof. If this returns 2xx and the DB email
     * changes, it is a P0 account-takeover primitive.
     */
    public function test_kiosk_token_cannot_rewrite_admin_email_via_profile_update(): void
    {
        $admin = $this->makeAdminWithKioskTokenUser();

        // Mint a token mirroring KioskMachineLoginController: name 'kiosk-token',
        // ability ['kiosk:order'] only.
        $token = $admin->createToken('kiosk-token', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->putJson('/api/profile', [
                'first_name'   => 'Pwned',
                'last_name'    => 'Attacker',
                'email'        => 'attacker@evil.example',
                'phone'        => '+33699999999',
                'country_code' => '+33',
            ]);

        // A kiosk-scoped token MUST NOT be able to mutate the admin identity.
        $this->assertContains(
            $response->status(),
            [401, 403],
            'kiosk-token PUT /api/profile must be refused (401/403); got ' . $response->status()
            . ' body=' . $response->getContent()
        );

        $this->assertDatabaseHas('users', [
            'id'    => $admin->id,
            'email' => 'admin@lecayenne.fr',
        ]);
        $this->assertDatabaseMissing('users', [
            'id'    => $admin->id,
            'email' => 'attacker@evil.example',
        ]);
    }

    /**
     * ABUSE 1 — info leak. A kiosk-only token should not be able to read the
     * admin's full profile (email/phone are PII + the email is the takeover
     * pivot).
     */
    public function test_kiosk_token_cannot_read_admin_profile(): void
    {
        $admin = $this->makeAdminWithKioskTokenUser();
        $token = $admin->createToken('kiosk-token', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/profile');

        $this->assertContains(
            $response->status(),
            [401, 403],
            'kiosk-token GET /api/profile must be refused (401/403); got ' . $response->status()
            . ' body=' . $response->getContent()
        );
    }

    /**
     * POSITIVE CONTROL — changePassword is defended (needs old_password). This
     * documents WHY the takeover pivots through the email-change path, not the
     * password path.
     */
    public function test_kiosk_token_cannot_change_admin_password_without_old_password(): void
    {
        $admin = $this->makeAdminWithKioskTokenUser();
        $token = $admin->createToken('kiosk-token', ['kiosk:order']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/profile/change-password', [
                'old_password'          => 'WRONG-i-dont-know-it',
                'password'              => 'NewAttackerPass123',
                'password_confirmation' => 'NewAttackerPass123',
            ]);

        // Must NOT succeed; 422 (old_password mismatch) or 401/403 are all OK.
        $this->assertNotContains($response->status(), [200, 201, 204],
            'change-password without correct old_password must fail; got ' . $response->status());

        // Old password still works (unchanged).
        $this->assertTrue(Hash::check('AdminSecret123', $admin->fresh()->password));
    }
}
