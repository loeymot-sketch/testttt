<?php

namespace Tests\Feature\Auth;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [MULTI-DEVICE 2026-08-07] Le propriétaire exploite plusieurs terminaux
 * simultanés (1 caisse + jusqu'à ~7 accès admin : tablettes de salle,
 * téléphone, poste bureau). Avant ce correctif, `LoginController:155`
 * exécutait :
 *
 *     $user->tokens()->where('name', 'auth_token')->delete();
 *
 * — c'est-à-dire la révocation de TOUS les jetons du compte, pas seulement
 * de celui de l'appareil qui se reconnecte. Conséquence terrain constatée :
 * la connexion sur la tablette B invalidait le jeton de la tablette A, qui
 * se retrouvait en 401 au premier appel API (déconnexion brutale sur la
 * caisse via pos-app.js:62, ou message « impossible de procéder » sur
 * l'admin quand le composant avalait l'erreur).
 *
 * L'intention d'origine (Sprint 5D Z6-01, CLAUDE.md §9) reste valide : il
 * ne faut PAS laisser proliférer des jetons zombies. La correction n'est
 * donc pas de supprimer la révocation mais de la SCOPER À L'APPAREIL :
 * un appareil qui se reconnecte ne tue que son propre jeton précédent.
 *
 * Garde-fous conservés (choix owner) :
 *   - plafond d'appareils simultanés par compte (config auth.max_devices_per_user)
 *   - identité d'appareil tracée sur le jeton (device_id/device_label/last_ip)
 *     pour l'écran « Appareils connectés » ET la traçabilité NF525 (l'audit
 *     `user.login` doit pouvoir dire DEPUIS QUEL POSTE l'action a eu lieu,
 *     ce qui est indispensable quand plusieurs terminaux partagent un compte).
 */
class MultiDeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => self::API_KEY]);

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'admin@test.com',
            'password'  => Hash::make('password123'),
            'status'    => Status::ACTIVE,
        ]);
        $this->admin->assignRole('Admin');

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /**
     * Connexion depuis un appareil identifié. Retourne le Bearer.
     */
    private function loginFrom(string $deviceId, string $label = 'Tablette'): string
    {
        $response = $this->withHeaders([
            'X-Device-Id'    => $deviceId,
            'X-Device-Label' => $label,
        ])->postJson('/api/auth/login', [
            'email'    => 'admin@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);

        return (string) $response->json('token');
    }

    /**
     * Un Bearer est-il encore accepté ? On ne teste QUE l'authentification
     * (non-401), pas le corps de la réponse.
     */
    /**
     * Requête portée UNIQUEMENT par le Bearer.
     *
     * `LoginController` s'authentifie via `Auth::guard('web')->attempt()`.
     * Sans purge, la session résiduelle du client de test authentifierait la
     * requête suivante par cookie (Sanctum → TransientToken) et le test
     * répondrait « le jeton marche » même après sa révocation : un test qui
     * valide un jeton mort est pire qu'un test absent.
     */
    private function asDevice(string $bearer): self
    {
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$bearer}");
    }

    private function tokenStillWorks(string $bearer): bool
    {
        $status = $this->asDevice($bearer)
            ->getJson('/api/profile')
            ->getStatusCode();

        return $status !== 401;
    }

    /**
     * LE BUG SIGNALÉ PAR L'OWNER. Deux terminaux, même compte : le second
     * ne doit pas éjecter le premier.
     */
    /** @test */
    public function test_second_device_login_does_not_revoke_first_device_token(): void
    {
        $caisse   = $this->loginFrom('device-caisse-01', 'Caisse comptoir');
        $tablette = $this->loginFrom('device-tablette-02', 'Tablette salle');

        $this->assertTrue(
            $this->tokenStillWorks($caisse),
            'La caisse a été éjectée par la connexion de la tablette (bug multi-appareils).'
        );
        $this->assertTrue($this->tokenStillWorks($tablette));

        $this->assertSame(
            2,
            $this->admin->tokens()->where('name', 'auth_token')->count(),
            'Les deux appareils doivent conserver chacun leur jeton.'
        );
    }

    /**
     * La protection anti-prolifération d'origine reste active : le MÊME
     * appareil qui se reconnecte n'accumule pas de jetons.
     */
    /** @test */
    public function test_relogin_from_same_device_revokes_only_that_device_previous_token(): void
    {
        $autreAppareil = $this->loginFrom('device-tablette-02', 'Tablette salle');
        $ancien        = $this->loginFrom('device-caisse-01', 'Caisse comptoir');
        $nouveau       = $this->loginFrom('device-caisse-01', 'Caisse comptoir');

        $this->assertFalse(
            $this->tokenStillWorks($ancien),
            'Le jeton précédent du MÊME appareil doit être révoqué (anti-prolifération).'
        );
        $this->assertTrue($this->tokenStillWorks($nouveau));
        $this->assertTrue(
            $this->tokenStillWorks($autreAppareil),
            'La reconnexion de la caisse ne doit pas toucher la tablette.'
        );

        $this->assertSame(2, $this->admin->tokens()->where('name', 'auth_token')->count());
    }

    /**
     * Plafond d'appareils simultanés (garde-fou owner). Au-delà du plafond,
     * c'est l'appareil le PLUS ANCIENNEMENT actif qui tombe — jamais celui
     * qui vient de se connecter.
     */
    /** @test */
    public function test_device_cap_evicts_least_recently_used_device(): void
    {
        config(['auth.max_devices_per_user' => 2]);

        $premier  = $this->loginFrom('device-a', 'A');
        $deuxieme = $this->loginFrom('device-b', 'B');
        $troisieme = $this->loginFrom('device-c', 'C');

        $this->assertSame(2, $this->admin->tokens()->where('name', 'auth_token')->count());
        $this->assertFalse($this->tokenStillWorks($premier), 'Le plus ancien appareil doit être évincé.');
        $this->assertTrue($this->tokenStillWorks($deuxieme));
        $this->assertTrue($this->tokenStillWorks($troisieme));
    }

    /**
     * NF525 : quand plusieurs terminaux partagent un compte, l'identité du
     * poste doit rester traçable dans la chaîne d'audit, sinon on perd le
     * « qui a fait quoi » exigé pour les 6 ans de conservation.
     */
    /** @test */
    public function test_login_audit_records_device_identity(): void
    {
        $this->loginFrom('device-caisse-01', 'Caisse comptoir');

        $row = DB::table('audit_logs')
            ->where('action', 'user.login')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'Aucune ligne audit user.login écrite.');

        $payload = json_decode((string) $row->payload, true) ?: [];
        $this->assertSame('device-caisse-01', $payload['device_id'] ?? null);
        $this->assertSame('Caisse comptoir', $payload['device_label'] ?? null);
    }

    /**
     * Le renouvellement automatique du jeton (toutes les 2h) doit REPORTER
     * l'identité d'appareil. Sans ça, la correction se dégrade en silence :
     * l'appareil devient anonyme, sa reconnexion ne révoque plus rien, il
     * n'est plus révocable depuis l'écran, et le plafond finit par évincer
     * des postes en service.
     */
    /** @test */
    public function test_token_refresh_preserves_device_identity(): void
    {
        $bearer = $this->loginFrom('device-caisse-01', 'Caisse comptoir');

        $this->flushSession();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $refreshed = $this->postJson('/api/refresh-token', ['token' => $bearer]);
        $refreshed->assertStatus(201);

        $token = \Laravel\Sanctum\PersonalAccessToken::findToken((string) $refreshed->json('token'));

        $this->assertNotNull($token);
        $this->assertSame('device-caisse-01', $token->device_id);
        $this->assertSame('Caisse comptoir', $token->device_label);

        // Et la reconnexion de CE poste doit toujours cibler ce jeton-là.
        $this->loginFrom('device-caisse-01', 'Caisse comptoir');
        $this->assertSame(1, $this->admin->tokens()->where('name', 'auth_token')->count());
    }

    /**
     * Écran « Appareils connectés » : lister ses sessions actives.
     */
    /** @test */
    public function test_devices_endpoint_lists_active_sessions(): void
    {
        $this->loginFrom('device-caisse-01', 'Caisse comptoir');
        $tablette = $this->loginFrom('device-tablette-02', 'Tablette salle');

        $response = $this->asDevice($tablette)
            ->getJson('/api/auth/devices');

        $response->assertStatus(200);

        $devices = collect($response->json('devices'));
        $this->assertCount(2, $devices);
        $this->assertEqualsCanonicalizing(
            ['device-caisse-01', 'device-tablette-02'],
            $devices->pluck('device_id')->all()
        );

        $current = $devices->firstWhere('device_id', 'device-tablette-02');
        $this->assertTrue((bool) ($current['is_current'] ?? false), 'La session courante doit être marquée.');
    }

    /**
     * Un jeton expiré n'est pas une session connectée : l'afficher ferait
     * croire à l'exploitant qu'un poste est encore ouvert.
     */
    /** @test */
    public function test_expired_sessions_are_not_listed(): void
    {
        $bearer = $this->loginFrom('device-actif', 'Poste actif');

        $mort = $this->admin->createToken('auth_token', ['*'], now()->subMinute());
        $mort->accessToken->forceFill(['device_id' => 'device-expire'])->save();

        $devices = collect(
            $this->asDevice($bearer)->getJson('/api/auth/devices')->json('devices')
        );

        $this->assertNotContains('device-expire', $devices->pluck('device_id')->all());
        $this->assertContains('device-actif', $devices->pluck('device_id')->all());
    }

    /**
     * Révocation à distance (tablette perdue/volée).
     */
    /** @test */
    public function test_device_can_be_revoked_remotely(): void
    {
        $perdue  = $this->loginFrom('device-perdue', 'Tablette perdue');
        $gardee  = $this->loginFrom('device-gardee', 'Poste bureau');

        $cible = $this->admin->tokens()->where('device_id', 'device-perdue')->firstOrFail();

        $this->asDevice($gardee)
            ->deleteJson("/api/auth/devices/{$cible->id}")
            ->assertStatus(200);

        $this->assertFalse($this->tokenStillWorks($perdue), 'Le jeton révoqué doit être refusé.');
        $this->assertTrue($this->tokenStillWorks($gardee));
    }

    /**
     * Renommer un appareil : sans ça l'écran est inexploitable avec plusieurs
     * postes admin, tous affichés sous le même libellé automatique.
     */
    /** @test */
    public function test_device_can_be_renamed(): void
    {
        $bearer = $this->loginFrom('device-a', 'Administration');
        $cible  = $this->admin->tokens()->where('device_id', 'device-a')->firstOrFail();

        $this->asDevice($bearer)
            ->patchJson("/api/auth/devices/{$cible->id}", ['device_label' => 'Tablette salle 2'])
            ->assertStatus(200)
            ->assertJsonPath('device_label', 'Tablette salle 2');

        $this->assertSame('Tablette salle 2', $cible->fresh()->device_label);
    }

    /**
     * Un utilisateur ne doit jamais pouvoir révoquer la session d'un AUTRE
     * compte via cet endpoint (IDOR).
     */
    /** @test */
    public function test_cannot_revoke_another_users_device(): void
    {
        $autre = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'autre@test.com',
            'password'  => Hash::make('password123'),
            'status'    => Status::ACTIVE,
        ]);
        $autre->assignRole('Admin');
        $tokenAutre = $autre->createToken('auth_token', ['*'])->accessToken;

        $moi = $this->loginFrom('device-moi', 'Poste bureau');

        $this->asDevice($moi)
            ->deleteJson("/api/auth/devices/{$tokenAutre->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenAutre->id]);
    }
}
