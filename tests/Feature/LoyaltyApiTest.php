<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class LoyaltyApiTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 Q3] These tests exercise loyalty
        // redeem MECHANICS (success, insufficient-points), not the V1 discount on/off
        // policy. Enable the discretionary-discount master flag so the pre-redeem gate
        // (LoyaltyController::redeem) does not short-circuit before the mechanics. The
        // OFF behaviour is locked by KioskLoyaltyDoubleRedeemRefusedTest::
        // test_pre_redeem_is_refused_when_discounts_disabled_v1.
        config(['pos.manual_discount_enabled' => true]);
        $this->withHeaders([
            'x-api-key' => '123456',
            'Accept' => 'application/json',
        ]);
    }

    public function test_loyalty_register()
    {
        $response = $this->postJson('/api/frontend/loyalty/register', [
            'name' => 'John Doe',
            'phone' => '+33612345678'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
        ]);
        $this->assertDatabaseHas('users', ['phone' => '+33612345678']);
        $this->assertNotNull($response->json('data.loyalty_code'));
    }

    /**
     * [SEC-FALSIFY-2026-06-08 P1] The public /loyalty/register endpoint must NOT echo a
     * third party's phone or loyalty_code when the submitted email already belongs to
     * another account. Returning them turned this unauthenticated endpoint into a PII
     * oracle (probe an email -> harvest its phone + redemption code). The 409 must still
     * tell the customer the email is taken (code EMAIL_EXISTS) but disclose no PII.
     */
    public function test_register_email_conflict_does_not_leak_third_party_pii()
    {
        $victim = \App\Models\User::forceCreate([
            'name' => 'Victim Loyalty',
            'username' => 'victim_loyalty',
            'email' => 'victim@example.com',
            'phone' => '+33699998888',
            'password' => bcrypt('password'),
            'loyalty_code' => 'SECRET01',
            'loyalty_points' => 120,
            'status' => 1,
        ]);

        // Attacker registers a brand-new phone but submits the victim's email.
        $response = $this->postJson('/api/frontend/loyalty/register', [
            'name' => 'Attacker',
            'phone' => '+33700001111',
            'email' => 'victim@example.com',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['status' => false, 'code' => 'EMAIL_EXISTS']);

        // The victim's PII must be absent anywhere in the response body.
        $body = $response->getContent();
        $this->assertStringNotContainsString($victim->phone, $body, 'register 409 must not leak the existing account phone');
        $this->assertStringNotContainsString($victim->loyalty_code, $body, 'register 409 must not leak the existing account loyalty_code');
        $this->assertNull($response->json('data.existing_phone'));
        $this->assertNull($response->json('data.existing_loyalty_code'));
    }

    public function test_loyalty_check()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Loyalty',
            'username' => 'jane_loyalty',
            'email' => 'jane@example.com',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'XYZ1234',
            'loyalty_points' => 50,
            'status' => 1
        ]);

        // [SEC-FALSIFY-2026-06-08] check() now requires an authorized caller (real kiosk machine,
        // staff, or the account owner). A POS Operator is the canonical staff lookup.
        $staff = \App\Models\User::factory()->create(['branch_id' => 0]);
        $staff->assignRole('POS Operator');
        $this->actingAs($staff, 'sanctum');

        $response = $this->postJson('/api/frontend/loyalty/check', [
            'code' => 'XYZ1234'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['points' => 50]);
    }

    /**
     * [SEC-FALSIFY-2026-06-08 P1] /loyalty/check must NOT let an unprivileged caller (a guest
     * token with no KioskMachine row, no staff role, not the account owner) enumerate another
     * customer's PII (name / points / loyalty_code) by code or phone. Such a caller gets the same
     * 404 as a miss — no PII, no existence oracle. (Sibling of the healed register() 409 leak.)
     */
    public function test_check_guest_cannot_enumerate_another_users_pii()
    {
        $victim = \App\Models\User::forceCreate([
            'name' => 'Victim Secret',
            'username' => 'victim_secret',
            'email' => 'victim2@example.com',
            'phone' => '0612345678',
            'password' => bcrypt('password'),
            'loyalty_code' => 'VICT1234',
            'loyalty_points' => 250,
            'status' => 5,
        ]);
        // The real attack token: a guest holding a kiosk:order token (mintable by anyone with the
        // public client API key) but NO KioskMachine row and no staff role. tokenCan('kiosk:order')
        // is TRUE — so only the isKiosk/staff discriminator (not the ability gate) blocks it.
        $guest = \App\Models\User::factory()->create(['branch_id' => 0]);
        \Laravel\Sanctum\Sanctum::actingAs($guest, ['kiosk:order']);

        foreach (['VICT1234', '0612345678'] as $needle) {
            $response = $this->postJson('/api/frontend/loyalty/check', ['code' => $needle]);
            $response->assertStatus(404);
            $body = $response->getContent();
            $this->assertStringNotContainsString('Victim Secret', $body, 'must not leak the victim name');
            $this->assertStringNotContainsString('VICT1234', $body, 'must not leak the loyalty_code');
            $this->assertStringNotContainsString('250', $body, 'must not leak the points');
        }
    }

    /**
     * [SEC-FALSIFY-2026-06-08 P1] /loyalty/scan is the physical-kiosk QR/NFC endpoint. Via the
     * legacy-plaintext path it resolves a customer by loyalty_code OR phone and returns their first
     * name + points + declared allergens (GDPR health data). `kiosk:order` is satisfied by a GUEST
     * token (no KioskMachine row), so without the isKiosk/staff discriminator a guest could
     * enumerate that PII. The guard fires BEFORE any resolution → a guest gets 403 (no data, no
     * existence oracle) even when legacy plaintext is enabled. Sibling of the check() leak.
     */
    public function test_scan_guest_cannot_enumerate_pii()
    {
        config(['loyalty.qr.accept_legacy_plaintext' => true]); // worst case: plaintext ON
        \App\Models\User::forceCreate([
            'name' => 'Zelda Hidden',
            'username' => 'zelda_hidden',
            'email' => 'zelda@example.com',
            'phone' => '0688887777',
            'password' => bcrypt('password'),
            'loyalty_code' => 'SCANVIC1',
            'loyalty_points' => 300,
            'status' => 5,
        ]);
        // Guest: holds a kiosk:order token (tokenCan true) but NO KioskMachine row → only the
        // isKiosk/staff discriminator blocks it (the ability gate alone would let it through).
        $guest = \App\Models\User::factory()->create(['branch_id' => 0]);
        \Laravel\Sanctum\Sanctum::actingAs($guest, ['kiosk:order']);

        foreach (['FK:SCANVIC1', '0688887777'] as $raw) {
            $response = $this->postJson('/api/frontend/loyalty/scan', [
                'method' => 'qr', 'raw_data' => $raw,
            ]);
            $response->assertStatus(403);
            $body = $response->getContent();
            $this->assertStringNotContainsString('Zelda', $body, 'must not leak the victim first name');
            $this->assertStringNotContainsString('300', $body, 'must not leak the points');
        }
    }

    /**
     * [SEC-FALSIFY-2026-06-08] The scan() discriminator must NOT break the legit physical borne:
     * a real KioskMachine token (the KioskMachineLoginController mints it on KioskMachine.user_id)
     * resolves the customer and gets the minimised profile (first name + points).
     */
    public function test_scan_real_kiosk_resolves_customer()
    {
        config(['loyalty.qr.accept_legacy_plaintext' => true]);
        \App\Models\User::forceCreate([
            'name' => 'Marie Scan',
            'username' => 'marie_scan',
            'email' => 'marie@example.com',
            'phone' => '0699990000',
            'password' => bcrypt('password'),
            'loyalty_code' => 'SCANOK1',
            'loyalty_points' => 80,
            'status' => 5,
        ]);
        $branch = \App\Models\Branch::factory()->create();
        $kioskUser = \App\Models\User::factory()->create(['branch_id' => $branch->id]);
        \App\Models\KioskMachine::forceCreate([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'machine_id' => 'K-TEST-1',
            'username' => 'kiosk_test',
            'password' => bcrypt('kioskpass'),
            'is_login' => 1,
            'status' => 1,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $response = $this->postJson('/api/frontend/loyalty/scan', [
            'method' => 'qr', 'raw_data' => 'FK:SCANOK1',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.ok', true);
        $response->assertJsonPath('data.display_name', 'Marie');           // first name only
        $response->assertJsonPath('data.loyalty_balance_points', 80);
    }

    public function test_loyalty_add_points()
    {
        $admin = \App\Models\User::forceCreate([
            'name' => 'Admin Loyalty',
            'username' => 'admin_loyalty',
            'email' => 'admin-loyalty@example.com',
            'phone' => '5234567890',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);
        $admin->assignRole('Admin');

        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Add',
            'username' => 'jane_add',
            'email' => 'jane2@example.com',
            'phone' => '2234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'ADD99',
            'loyalty_points' => 10,
            'status' => 1
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/frontend/loyalty/add-points', [
            'code' => 'ADD99',
            'points' => 20
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['loyalty_code' => 'ADD99', 'loyalty_points' => 30]);
    }

    public function test_loyalty_redeem()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Redeem',
            'username' => 'jane_redeem',
            'email' => 'jane3@example.com',
            'phone' => '3234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'RED55',
            'loyalty_points' => 100,
            'status' => 1
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'RED55',
            'points' => 100
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['loyalty_code' => 'RED55', 'loyalty_points' => 0]);
    }

    public function test_loyalty_redeem_not_enough_points()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Redeem Block',
            'username' => 'jane_redeem_block',
            'email' => 'jane4@example.com',
            'phone' => '4234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'RED10',
            'loyalty_points' => 10,
            'status' => 1
        ]);

        $response = $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'RED10',
            'points' => 50
        ]);

        $response->assertStatus(400); // Bad Request (points insuffisants)
    }
}
