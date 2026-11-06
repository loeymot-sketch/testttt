<?php

namespace Tests\Feature\Auth;

use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use App\Services\Auth\CustomerSessionPolicy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * [SESSION DURABLE 2026-10-30 · T-S2.1.1] « Le client n'est jamais déconnecté ».
 *
 * Défaut : un jeton client est émis pour 30 jours (GuestSignupController /
 * SocialAuthController -> DeviceTokenService::issueForDevice(..., 60*24*30)), mais le
 * Guard de Sanctum exige AUSSI `created_at > now() - config('sanctum.expiration')`
 * (480 min par défaut). Le client était donc déconnecté au bout de 8 h, quoi que dise
 * `expires_at`.
 *
 * Correctif : `CustomerSessionPolicy`, branchée sur `Sanctum::$accessTokenAuthenticationCallback`.
 * Elle ne rattrape QUE le jeton client (nom `auth_token`, aptitude exacte `kiosk:order`,
 * propriétaire actif dont l'unique rôle est Client, durée de vie explicitement supérieure
 * au plafond global, `expires_at` encore dans le futur). Tout le reste — borne, caisse,
 * admin, jeton sans échéance — garde la limite de 480 minutes.
 *
 * Les rôles sont créés ICI avec des identifiants explicites : la production attribue
 * `EnumRole::CUSTOMER` (id 2) par identifiant, et `seedSpatieRoles()` ne garantit pas
 * cette numérotation.
 */
class CustomerTokenSurvivesEightHoursTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();

        foreach ([
            EnumRole::ADMIN    => 'Admin',
            EnumRole::CUSTOMER => 'Customer',
            EnumRole::CHEF     => 'Chef',
        ] as $id => $name) {
            DB::table('roles')->insert([
                'id' => $id, 'name' => $name, 'guard_name' => 'sanctum',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['app.api_key' => self::API_KEY]);
        $this->useGlobalLimit(480);

        // Réserve id=1 (sentinelle « root admin » de User : statut forcé à ACTIVE).
        User::factory()->create(['status' => Status::ACTIVE]);

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);

        $this->t0 = Carbon::parse('2026-10-30 08:00:00');
        Carbon::setTestNow($this->t0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ outils

    private function customer(array $roleIds = [EnumRole::CUSTOMER], int $status = Status::ACTIVE): User
    {
        $user = User::factory()->create(['status' => $status]);
        foreach ($roleIds as $roleId) {
            $user->assignRole($roleId);
        }

        return $user;
    }

    /** Jeton client réel : mêmes paramètres que GuestSignupController / SocialAuthController. */
    private function customerToken(User $user, ?int $ttl = 60 * 24 * 30): string
    {
        $request = request();
        $request->headers->set('X-Device-Id', 'device-' . $user->id . '-abcdef');

        return app(\App\Services\Auth\DeviceTokenService::class)
            ->issueForDevice($user, 'auth_token', ['kiosk:order'], $request, $ttl)
            ->plainTextToken;
    }

    private function me(string $plain): \Illuminate\Testing\TestResponse
    {
        // Le garde `sanctum` mémorise l'utilisateur résolu d'une requête à l'autre dans
        // un même test : sans cet oubli, la 2e requête ne revalide JAMAIS le jeton.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/profile');
    }

    /** Le Guard de Sanctum capte `sanctum.expiration` à sa construction : le reconstruire. */
    private function useGlobalLimit(?int $minutes): void
    {
        config(['sanctum.expiration' => $minutes]);
        $this->app['auth']->forgetGuards();
    }

    /** La borne est refusée (403) par d'autres garde-fous sur /api/profile : seul le 401 compte ici. */
    private function assertTokenAccepted(string $plain): void
    {
        $this->assertNotSame(401, $this->me($plain)->status(), 'Le jeton aurait dû être accepté par le Guard.');
    }

    private function after(int $minutes): void
    {
        Carbon::setTestNow($this->t0->copy()->addMinutes($minutes));
    }

    // ----------------------------------------------------- le défaut (rouge avant)

    /** @test */
    public function test_customer_token_is_still_valid_nine_hours_after_login(): void
    {
        $plain = $this->customerToken($this->customer());

        $this->me($plain)->assertOk();

        $this->after(9 * 60);
        $this->me($plain)->assertOk();
    }

    /** @test */
    public function test_customer_token_lives_until_its_own_expires_at_not_beyond(): void
    {
        $plain = $this->customerToken($this->customer());

        $this->after(60 * 24 * 29);
        $this->me($plain)->assertOk();

        $this->after(60 * 24 * 30 + 1);
        $this->me($plain)->assertStatus(401);
    }

    // ------------------------------------------- ce qui doit rester à 480 minutes

    /** @test */
    public function test_kiosk_machine_token_still_dies_after_eight_hours(): void
    {
        $user  = $this->customer([EnumRole::CHEF]);
        $plain = $user->createToken('kiosk-token', ['kiosk:order'], now()->addMinutes(480))->plainTextToken;

        $this->after(60);
        $this->assertTokenAccepted($plain);

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_staff_wildcard_token_still_dies_after_eight_hours(): void
    {
        $user  = $this->customer([EnumRole::ADMIN]);
        $plain = $user->createToken('auth_token', ['*'], now()->addMinutes(480))->plainTextToken;

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    // ------------------------------------------------ adversaire : ne pas éterniser

    /** @test */
    public function test_staff_token_with_a_long_expires_at_is_still_cut_at_eight_hours(): void
    {
        // Si un jeton staff recevait un jour une longue échéance (config relevée puis
        // rabaissée, bug d'émission) : l'échéance seule ne doit JAMAIS suffire.
        $user  = $this->customer([EnumRole::ADMIN]);
        $plain = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_kiosk_token_with_a_long_expires_at_is_still_cut_at_eight_hours(): void
    {
        // Même aptitude `kiosk:order` que le client, mais un autre nom : la borne reste à 8 h.
        $user  = $this->customer([EnumRole::CUSTOMER]);
        $plain = $user->createToken('kiosk-token', ['kiosk:order'], now()->addDays(30))->plainTextToken;

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_customer_who_is_also_staff_is_not_eternal(): void
    {
        $user  = $this->customer([EnumRole::CUSTOMER, EnumRole::CHEF]);
        $plain = $this->customerToken($user);

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_user_without_customer_role_is_not_eternal(): void
    {
        $plain = $this->customerToken($this->customer([]));

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_token_without_expires_at_keeps_the_global_limit(): void
    {
        $plain = $this->customerToken($this->customer(), null);
        $this->assertNull(PersonalAccessToken::findToken($plain)->expires_at);

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_customer_token_with_a_short_lifetime_keeps_the_global_limit(): void
    {
        // Durée de vie non supérieure au plafond : ce n'est pas un jeton « longue durée ».
        $plain = $this->customerToken($this->customer(), 480);

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_lowering_the_global_limit_later_does_not_promote_a_480_minute_token(): void
    {
        // Un jeton client rafraîchi AVANT le correctif vaut 480 min. Si le plafond est
        // rabaissé ensuite, sa durée (480) dépasse le plafond (240) sans être une
        // session de 30 jours : il ne doit pas être rattrapé.
        $plain = $this->customerToken($this->customer(), 480);

        $this->useGlobalLimit(240);
        $this->after(5 * 60 - 60); // 4 h : au-delà de 240 min, encore dans les 480 min
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_issuers_use_the_policy_lifetime_constant(): void
    {
        $this->assertSame(43200, CustomerSessionPolicy::LIFETIME_MINUTES);
        foreach (['GuestSignupController', 'SocialAuthController'] as $c) {
            $src = file_get_contents(app_path("Http/Controllers/Auth/{$c}.php"));
            $this->assertStringContainsString('CustomerSessionPolicy::LIFETIME_MINUTES', $src, $c);
            $this->assertStringNotContainsString('request(), 60 * 24 * 30)', $src, $c);
        }
    }

    /** @test */
    public function test_customer_token_with_extra_ability_is_not_eternal(): void
    {
        $user  = $this->customer();
        $plain = $user->createToken('auth_token', ['kiosk:order', 'orders:admin'], now()->addDays(30))->plainTextToken;

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    // ------------------------------------------------------- révocation et statut

    /** @test */
    public function test_revoked_customer_token_is_rejected(): void
    {
        $user  = $this->customer();
        $plain = $this->customerToken($user);
        $this->after(9 * 60);
        $this->me($plain)->assertOk();

        $user->tokens()->delete();

        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_deactivated_customer_is_rejected_after_nine_hours(): void
    {
        $user  = $this->customer();
        $plain = $this->customerToken($user);
        $user->update(['status' => Status::INACTIVE]);

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_soft_deleted_customer_is_rejected_after_nine_hours(): void
    {
        $user  = $this->customer();
        $plain = $this->customerToken($user);
        $user->delete();

        $this->after(9 * 60);
        $this->me($plain)->assertStatus(401);
    }

    /** @test */
    public function test_clock_skew_back_does_not_break_a_fresh_customer_token(): void
    {
        $plain = $this->customerToken($this->customer());

        Carbon::setTestNow($this->t0->copy()->subMinutes(10)); // horloge qui recule
        $this->me($plain)->assertOk();
    }

    /** @test */
    public function test_no_global_limit_configured_changes_nothing(): void
    {
        $this->useGlobalLimit(null);
        $plain = $this->customerToken($this->customer());

        $this->after(9 * 60);
        $this->me($plain)->assertOk();
    }

    // ------------------------------------------------ la politique, en isolation

    /** @test */
    public function test_policy_never_downgrades_a_valid_result(): void
    {
        $token = new PersonalAccessToken();

        $this->assertTrue(CustomerSessionPolicy::authenticate($token, true));
        $this->assertFalse(CustomerSessionPolicy::authenticate($token, false));
    }

    /** @test */
    public function test_policy_itself_refuses_an_inactive_owner(): void
    {
        // Défense en profondeur : EnsureUserStatusActive coupe déjà la requête, mais la
        // politique ne doit pas, à elle seule, prolonger le jeton d'un compte désactivé.
        $user  = $this->customer();
        $plain = $this->customerToken($user);
        $token = PersonalAccessToken::findToken($plain);
        $this->assertTrue(CustomerSessionPolicy::isCustomerToken($token));

        $user->update(['status' => Status::INACTIVE]);
        $this->assertFalse(CustomerSessionPolicy::isCustomerToken(PersonalAccessToken::findToken($plain)));
    }

    // ------------------------------------------------------ /refresh-token client

    /** @test */
    public function test_refresh_keeps_a_customer_session_at_thirty_days(): void
    {
        $user  = $this->customer();
        $plain = $this->customerToken($user);

        $this->after(9 * 60);

        $response = $this->postJson('/api/refresh-token', ['token' => $plain])->assertStatus(201);
        $new      = PersonalAccessToken::findToken($response->json('token'));

        $this->assertNotNull($new);
        $this->assertSame('auth_token', $new->name);
        $this->assertSame(['kiosk:order'], $new->abilities);
        $this->assertSame(
            60 * 24 * 30,
            (int) $new->created_at->diffInMinutes($new->expires_at),
            'Le renouvellement d\'une session client doit repartir sur 30 jours, pas sur 480 minutes.'
        );
        $this->assertSame('device-' . $user->id . '-abcdef', $new->device_id);

        // Et le nouveau jeton survit à son tour au plafond de 8 h.
        $this->after(9 * 60 + 9 * 60);
        $this->me($response->json('token'))->assertOk();
    }

    /** @test */
    public function test_refresh_of_a_kiosk_token_stays_at_the_global_limit(): void
    {
        $user  = $this->customer([EnumRole::CHEF]);
        $plain = $user->createToken('kiosk-token', ['kiosk:order'], now()->addMinutes(480))->plainTextToken;

        $response = $this->postJson('/api/refresh-token', ['token' => $plain])->assertStatus(201);
        $new      = PersonalAccessToken::findToken($response->json('token'));

        $this->assertSame(480, (int) $new->created_at->diffInMinutes($new->expires_at));
    }

    /** @test */
    public function test_refresh_of_a_staff_token_stays_at_the_global_limit(): void
    {
        $user  = $this->customer([EnumRole::ADMIN]);
        $plain = $user->createToken('auth_token', ['*'], now()->addMinutes(480))->plainTextToken;

        $response = $this->postJson('/api/refresh-token', ['token' => $plain])->assertStatus(201);
        $new      = PersonalAccessToken::findToken($response->json('token'));

        $this->assertSame(['*'], $new->abilities);
        $this->assertSame(480, (int) $new->created_at->diffInMinutes($new->expires_at));
    }

    /** @test */
    public function test_refresh_of_a_customer_who_is_also_staff_does_not_become_eternal(): void
    {
        $user  = $this->customer([EnumRole::CUSTOMER, EnumRole::CHEF]);
        $plain = $this->customerToken($user);

        $response = $this->postJson('/api/refresh-token', ['token' => $plain])->assertStatus(201);
        $new      = PersonalAccessToken::findToken($response->json('token'));

        $this->assertSame(480, (int) $new->created_at->diffInMinutes($new->expires_at));
    }

    /** @test */
    public function test_expired_customer_token_cannot_be_refreshed(): void
    {
        $plain = $this->customerToken($this->customer());

        $this->after(60 * 24 * 31);

        $this->postJson('/api/refresh-token', ['token' => $plain])->assertStatus(401);
    }
}
