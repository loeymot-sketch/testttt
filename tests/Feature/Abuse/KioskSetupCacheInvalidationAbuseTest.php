<?php

namespace Tests\Feature\Abuse;

use App\Events\SettingsUpdated;
use App\Http\Requests\KioskSetupRequest;
use App\Models\User;
use App\Services\KioskSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Factory as ValidationFactory;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * ABUSE #5 — KioskSetup update left the settings cache stale (P3).
 *
 * The Smartisan settings cache keys on "keys=<list>&group=<group>". `list()`
 * calls ->all() which caches under keys=<EMPTY>; `update()` calls ->set($assoc)
 * which only forgets keys=<the-assoc-keys>. The two cache entries DIFFER, so the
 * group ->all() blob survived an update — admins changing kiosk-setup saw the
 * OLD value until the cache aged out.
 *
 * IMPORTANT (surfaced contradiction): the prompt's suggested fix was "dispatch
 * SettingsUpdated like CurrencyController". That alone does NOT fix this — the
 * SettingsUpdated listener (PersistSettingsUpdatedToOutbox) only writes a
 * domain_events outbox row for live broadcast; it never forgets the settings
 * cache key. So this test forces settings.cache.enabled=true (the prod
 * condition; it is FALSE under phpunit so the staleness is otherwise invisible)
 * and asserts list() returns FRESH after an update. The real heal explicitly
 * forgets the group's all() cache key in KioskSetupService::update(); the
 * SettingsUpdated dispatch is added for live-broadcast parity with
 * CurrencyController, not for local cache invalidation.
 *
 * @see app/Services/KioskSetupService.php
 * @see app/Http/Controllers/Admin/CurrencyController.php
 * @see vendor/smartisan/laravel-settings/src/Settings.php (forgetCacheIfEnabled / resolveCacheKey)
 */
class KioskSetupCacheInvalidationAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        // Reproduce the PRODUCTION condition: settings cache ON (it defaults OFF under phpunit,
        // which would make any staleness assertion vacuous).
        config(['settings.cache.enabled' => true]);

        // Seed an initial kiosk_setup value.
        Settings::group('kiosk_setup')->set(['kiosk_welcome_title' => 'Ancien Titre']);
    }

    private function service(): KioskSetupService
    {
        return app(KioskSetupService::class);
    }

    /** Build a validated KioskSetupRequest carrying the given payload. */
    private function request(array $payload): KioskSetupRequest
    {
        $request = KioskSetupRequest::create('/api/admin/kiosk-setup', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setValidator(
            app(ValidationFactory::class)->make($payload, (new KioskSetupRequest())->rules())
        );

        return $request;
    }

    /** @test */
    public function list_returns_the_fresh_value_after_an_update_even_with_cache_enabled(): void
    {
        $service = $this->service();

        // Warm the group ->all() cache via list().
        $warm = $service->list();
        $this->assertSame('Ancien Titre', $warm['kiosk_welcome_title'] ?? null);

        // Update the kiosk-setup value through the service.
        $service->update($this->request([
            'kiosk_welcome_title' => 'Nouveau Titre',
        ]));

        // The freshly read list() MUST reflect the new value — not the stale cached blob.
        $fresh = $this->service()->list();
        $this->assertSame(
            'Nouveau Titre',
            $fresh['kiosk_welcome_title'] ?? null,
            'KioskSetupService::list() returned a STALE cached value after update().'
        );
    }

    /** @test */
    public function the_raw_group_all_blob_is_invalidated_after_update(): void
    {
        // Warm the exact cache entry list() uses (group all()).
        Settings::group('kiosk_setup')->all();

        $this->service()->update($this->request([
            'kiosk_welcome_title' => 'Titre Frais',
        ]));

        $reread = Settings::group('kiosk_setup')->all();
        $this->assertSame('Titre Frais', $reread['kiosk_welcome_title'] ?? null);
    }

    /** @test */
    public function update_dispatches_settings_updated_for_broadcast_parity(): void
    {
        // Live-broadcast parity with CurrencyController — kiosk surfaces should be told to
        // refresh. The dispatch lives in KioskSetupController, so this goes through the endpoint.
        Event::fake([SettingsUpdated::class]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->putJson('/api/admin/setting/kiosk-setup', [
            'kiosk_welcome_title' => 'Diffusion',
        ]);

        $this->assertContains($response->status(), [200, 201], 'Body: ' . $response->getContent());

        Event::assertDispatched(SettingsUpdated::class, function (SettingsUpdated $event): bool {
            return in_array('kiosk_setup', $event->changedKeys, true);
        });
    }
}
