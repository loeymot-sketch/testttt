<?php

namespace Tests\Feature\Settings;

use App\Http\Requests\LicenseRequest;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] The [ULTRA-AUDIT V4-DEPLOY
 * 2026-07-02] fix blocked \r/\n/" on CompanyRequest::company_name (it is
 * written verbatim into .env via EnvEditor, and a raw newline lets an
 * attacker inject an independent .env line, e.g. APP_DEBUG=true). MailRequest
 * and LicenseRequest write to .env through the exact same EnvEditor::addData
 * path (MailService.php, LicenseRequest::withValidator) but never received
 * the equivalent guard — the forgotten twin.
 */
class MailLicenseEnvInjectionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function validMailPayload(): array
    {
        return [
            'mail_host' => 'smtp.example.com',
            'mail_port' => '587',
            'mail_username' => 'no-reply@example.com',
            'mail_password' => 'secret',
            'mail_encryption' => 'tls',
            'mail_from_name' => 'Le Cayenne',
            'mail_from_email' => 'no-reply@example.com',
        ];
    }

    public function test_mail_settings_update_rejects_env_injection_via_from_name(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/mail', array_merge($this->validMailPayload(), [
                'mail_from_name' => "Le Cayenne\nAPP_DEBUG=true",
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mail_from_name']);
    }

    public function test_mail_settings_update_rejects_env_injection_via_password(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/mail', array_merge($this->validMailPayload(), [
                'mail_password' => "secret\"\r\nAPP_DEBUG=true",
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mail_password']);
    }

    public function test_mail_settings_update_accepts_valid_payload(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/mail', $this->validMailPayload());

        $response->assertOk();
    }

    /**
     * LicenseRequest::withValidator calls a real external HTTP license-check
     * API in its after() callback, which always fires regardless of prior
     * rule failures — so this is a unit-level Validator::make() check against
     * the rules() array directly, avoiding any network call.
     */
    public function test_license_request_rules_reject_env_injection_in_license_key(): void
    {
        $request = new LicenseRequest();

        $validator = Validator::make(
            ['license_key' => "ABC123\nMIX_API_KEY=stolen"],
            $request->rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('license_key', $validator->errors()->toArray());
    }

    public function test_license_request_rules_accept_clean_license_key(): void
    {
        $request = new LicenseRequest();

        $validator = Validator::make(
            ['license_key' => 'ABC123-DEF456-GHI789'],
            $request->rules()
        );

        $this->assertFalse($validator->fails());
    }
}
