<?php

namespace Tests\Feature\Delivery;

use App\Enums\Status;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Connexion dédiée de la remise : SEUL un compte exactement « Delivery Boy » obtient un jeton,
 * court, d'un nom et d'une aptitude à part (il ne chasse pas la session /livreur), jamais valable ailleurs.
 *
 * @group delivery
 */
class DeliveryHandoverLoginTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    private const URL = '/api/frontend/delivery-handover/login';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownHandover();
        parent::tearDown();
    }

    private function login(string $email, string $password = 'password', array $headers = [])
    {
        return $this->withHeaders($headers + ['x-api-key' => 'test-api-key'])->postJson(self::URL, ['email' => $email, 'password' => $password]);
    }

    public function test_a_delivery_boy_gets_a_short_lived_handover_token_with_its_own_name_and_ability(): void
    {
        $r = $this->login('livreur.a@test.fr')->assertStatus(201);

        $this->assertNotEmpty($r->json('token'));
        $this->assertSame(900, $r->json('expires_in'));
        $row = PersonalAccessToken::findToken($r->json('token'));
        $this->assertSame('handover_token', $row->name);
        $this->assertSame(['delivery:handover'], $row->abilities);
        $this->assertTrue($row->expires_at->lte(now()->addMinutes(15)->addSecond()), 'TTL 15 min');
        $this->assertTrue($row->expires_at->gt(now()->addMinutes(14)));
        // Rien d'autre que le nécessaire : pas de menu, pas de permissions, pas d'e-mail renvoyé.
        $this->assertSame(['token', 'expires_in', 'user'], array_keys($r->json()));
        $this->assertArrayNotHasKey('email', $r->json('user'));
    }

    public function test_it_does_not_evict_the_livreur_session_of_the_same_account(): void
    {
        $session = $this->withHeaders(['x-api-key' => 'test-api-key'])->postJson('/api/auth/login', ['email' => 'livreur.a@test.fr', 'password' => 'password'])
            ->assertStatus(201)->json('token');
        $this->login('livreur.a@test.fr')->assertStatus(201);
        $this->login('livreur.a@test.fr')->assertStatus(201);

        $this->assertSame(1, PersonalAccessToken::where('name', 'auth_token')->where('tokenable_id', $this->courier->id)->count(), 'la session /livreur survit');
        $this->withHeaders(['Authorization' => 'Bearer '.$session, 'x-api-key' => 'test-api-key'])->getJson('/api/frontend/delivery-run')->assertOk();
    }

    /** @dataProvider forbiddenAccounts */
    public function test_every_account_that_is_not_a_delivery_boy_is_refused_before_any_token_exists(string $email): void
    {
        $r = $this->login($email)->assertStatus(403);

        $this->assertSame('Ce compte n\'est pas un compte livreur.', $r->json('message'));
        $this->assertArrayNotHasKey('token', $r->json());
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'aucun jeton, de quelque nom que ce soit, n\'a été créé');
    }

    /** @return array<string,array{string}> */
    public static function forbiddenAccounts(): array
    {
        return ['client' => ['client@test.fr'], 'caisse' => ['caisse@test.fr'], 'admin' => ['admin@test.fr']];
    }

    public function test_a_delivery_boy_who_also_holds_another_role_is_refused(): void
    {
        $this->courier->assignRole('Admin');

        $this->login('livreur.a@test.fr')->assertStatus(403);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_bad_credentials_unknown_account_and_inactive_account_share_one_generic_answer(): void
    {
        $wrongPassword = $this->login('livreur.a@test.fr', 'mauvais');
        $unknown = $this->login('personne@test.fr');
        \App\Models\User::query()->whereKey($this->courier->id)->update(['status' => Status::INACTIVE]);
        $inactive = $this->login('livreur.a@test.fr');

        foreach ([$wrongPassword, $unknown, $inactive] as $r) {
            $r->assertStatus(422);
            $this->assertSame('Identifiants incorrects.', $r->json('message'));
        }
        $this->assertSame($wrongPassword->json(), $unknown->json());
        $this->assertSame($wrongPassword->json(), $inactive->json(), 'pas d\'énumération de comptes');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_wrong_password_never_reveals_the_role(): void
    {
        // Même avec un mauvais mot de passe, un compte client ne doit pas répondre autrement qu'un livreur.
        $client = $this->login('client@test.fr', 'mauvais');
        $livreur = $this->login('livreur.a@test.fr', 'mauvais');

        $this->assertSame($client->status(), $livreur->status());
        $this->assertSame($client->json(), $livreur->json());
    }

    public function test_a_delivery_boy_without_a_branch_is_refused(): void
    {
        \App\Models\User::query()->whereKey($this->courier->id)->update(['branch_id' => 0]);

        $this->login('livreur.a@test.fr')->assertStatus(403);
    }

    public function test_the_login_is_throttled_per_account_and_returns_a_french_429(): void
    {
        config(['auth.login_lockout.max_attempts' => 5]);
        $max = 5;
        for ($i = 0; $i < $max; $i++) {
            $rr = $this->login('livreur.a@test.fr', 'mauvais'.$i);
            $this->assertSame(422, $rr->status(), "tentative $i : ".$rr->getContent());
        }
        $r = $this->login('livreur.a@test.fr', 'mauvais-encore');
        $r->assertStatus(429);
        $this->assertStringContainsString('minute', (string) $r->json('message'));
        // Le bon mot de passe n'ouvre pas non plus pendant le blocage.
        $this->login('livreur.a@test.fr')->assertStatus(429);
        RateLimiter::clear('x');
    }

    public function test_the_api_key_is_required(): void
    {
        $this->withHeaders(['x-api-key' => 'faux'])->postJson(self::URL, ['email' => 'livreur.a@test.fr', 'password' => 'password'])
            ->assertStatus(400); // ApiKeyMiddleware : 400 invalid_api_key
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_handover_token_works_nowhere_else(): void
    {
        $handover = $this->handoverLogin('livreur.a@test.fr');
        $h = ['Authorization' => 'Bearer '.$handover, 'x-api-key' => 'test-api-key'];

        $this->withHeaders($h)->getJson('/api/frontend/delivery-run')->assertStatus(401);
        $this->withHeaders($h)->getJson('/api/profile')->assertStatus(401);
        $this->withHeaders($h)->getJson('/api/frontend/delivery-boy-order')->assertStatus(401);
    }

    public function test_a_successful_login_is_audited_without_secrets(): void
    {
        $this->login('livreur.a@test.fr')->assertStatus(201);

        $row = AuditLog::query()->where('action', 'delivery.handover_login')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->courier->id, (int) $row->user_id);
        $this->assertStringNotContainsString('password', json_encode($row->payload));
    }

    public function test_a_refused_role_is_audited_once_credentials_are_proven(): void
    {
        $this->login('client@test.fr')->assertStatus(403);

        $row = AuditLog::query()->where('action', 'delivery.handover_refused')->first();
        $this->assertNotNull($row);
        $this->assertSame('role', $row->payload['reason']);
    }

    /** [relecture adverse C · P2] Un e-mail inconnu coûte autant qu'un vrai : hachage factice au coût configuré, comparé quand même. */
    public function test_an_unknown_email_is_checked_against_a_dummy_hash_of_the_configured_cost(): void
    {
        config(['hashing.bcrypt.rounds' => 12]);
        $this->assertSame(12, (int) \Illuminate\Support\Facades\Hash::info(\App\Http\Controllers\Frontend\DeliveryHandoverController::dummyHash())['options']['cost'],
            'le hachage factice a le coût configuré (et non un coût 10 figé)');
        config(['hashing.bcrypt.rounds' => 4]);
        Cache::flush();

        $spy = new class($this->app) extends \Illuminate\Hashing\HashManager {
            public static array $checked = [];

            public function check($value, $hashedValue, array $options = [])
            {
                self::$checked[] = $hashedValue;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $this->app->instance('hash', $spy);
        \Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');
        $spy::$checked = [];

        $this->login('personne.inconnue@test.fr')->assertStatus(422);

        $this->assertCount(1, $spy::$checked, 'Hash::check est appelé aussi pour un compte inconnu');
        $this->assertSame(4, (int) $spy->info($spy::$checked[0])['options']['cost'], 'au coût configuré');
        // Même nombre d'appels que pour un vrai compte avec mauvais mot de passe.
        $spy::$checked = [];
        $this->login('livreur.a@test.fr', 'mauvais')->assertStatus(422);
        $this->assertCount(1, $spy::$checked);
    }

    /** [relecture adverse C · P3] La base compare sans accent : « jeán@ » et « jean@ » partagent le même seau de tentatives. */
    public function test_accent_and_case_variants_of_an_email_share_one_throttle_bucket(): void
    {
        config(['auth.login_lockout.max_attempts' => 5]);
        foreach (['jean@test.fr', 'JEAN@test.fr', 'jeán@test.fr', ' jean@test.fr', 'Jeán@Test.fr'] as $variant) {
            $this->login($variant, 'mauvais')->assertStatus(422);
        }

        $this->login('jéan@test.fr', 'mauvais')->assertStatus(429);
        $this->login('jean@test.fr', 'mauvais')->assertStatus(429);
    }
}
