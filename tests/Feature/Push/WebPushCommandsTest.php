<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** [NFC / NOTIFICATIONS 2026-11-20 · S1.4] Commandes d'exploitation : clés, essai, ménage. */
class WebPushCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function abonnement(User $u, string $fin, array $plus = []): WebPushSubscription
    {
        $e = 'https://push.example.test/send/'.$fin;

        return WebPushSubscription::query()->create(array_merge(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', $e), 'endpoint' => $e, 'p256dh' => 'k', 'auth' => 'a'], $plus));
    }

    public function test_webpush_keys_affiche_une_paire_valide_sans_rien_ecrire(): void
    {
        $avant = file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null;
        $this->artisan('webpush:keys')->expectsOutputToContain('WEBPUSH_PUBLIC_KEY=')->expectsOutputToContain('WEBPUSH_PRIVATE_KEY=')->assertSuccessful();
        $this->assertSame($avant, file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null, 'la commande n\'écrit jamais le .env');
    }

    public function test_webpush_test_refuse_quand_web_push_est_coupe_ou_sans_appareil(): void
    {
        config(['webpush.enabled' => false]);
        $u = User::factory()->create();
        $this->artisan('webpush:test', ['user_id' => $u->id])->assertFailed();
        config(['webpush.enabled' => true, 'webpush.public_key' => 'PUB', 'webpush.private_key' => 'PRIV']);
        $this->artisan('webpush:test', ['user_id' => $u->id])->expectsOutputToContain('Aucun appareil')->assertFailed();
    }

    public function test_webpush_prune_supprime_les_morts_et_garde_les_vivants(): void
    {
        $u = User::factory()->create();
        // SILENCIEUX depuis 120 jours (jamais rouvert, jamais notifié) : supprimé
        $mort = $this->abonnement($u, 'mort', ['last_success_at' => now()->subDays(120)]);
        \Illuminate\Support\Facades\DB::table('web_push_subscriptions')->where('id', $mort->id)->update(['updated_at' => now()->subDays(120)]);
        // Client qui commande rarement (dernier succès il y a 120 jours) MAIS dont le site a renvoyé l'abonnement il y a 3 jours : GARDÉ
        $rare = $this->abonnement($u, 'rare', ['last_success_at' => now()->subDays(120)]);
        \Illuminate\Support\Facades\DB::table('web_push_subscriptions')->where('id', $rare->id)->update(['updated_at' => now()->subDays(3)]);
        $neuf = $this->abonnement($u, 'neuf'); // jamais notifié mais abonné aujourd'hui : gardé
        $vivant = $this->abonnement($u, 'vivant', ['last_success_at' => now()->subDays(3)]);
        $this->artisan('webpush:prune')->assertSuccessful();
        $this->assertNull($mort->fresh());
        $this->assertNotNull($rare->fresh(), 'un appareil vivant d\'un client qui commande rarement n\'est pas supprimé');
        $this->assertNotNull($vivant->fresh());
        $this->assertNotNull($neuf->fresh());
    }

    public function test_le_menage_est_planifie_chaque_jour(): void
    {
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'webpush:prune')));
    }
}
