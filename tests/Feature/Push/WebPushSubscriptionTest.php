<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [NFC / NOTIFICATIONS 2026-11-20 · S1.1] Abonnement des appareils d'un client aux notifications Web Push.
 * Un appareil = un point d'accès unique ; se réabonner ne crée jamais de doublon ; le 6e appareil évince le plus ancien.
 */
class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/frontend/push/subscription';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456', 'webpush.allowed_hosts' => ['push.example.test']]);
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    /** Clés de format réel : clé publique non compressée (65 octets, 0x04…) et secret de 16 octets, en base64url. */
    private static function cle(string $graine): array
    {
        $b64 = fn (string $o) => rtrim(strtr(base64_encode($o), '+/', '-_'), '=');

        return ['p256dh' => $b64("\x04".substr(hash('sha512', $graine, true), 0, 64)), 'auth' => $b64(substr(hash('sha256', 'a'.$graine, true), 0, 16))];
    }

    private function corps(string $endpoint = 'https://push.example.test/send/AAA', array $plus = []): array
    {
        return array_merge(['endpoint' => $endpoint, 'keys' => self::cle('appareil'), 'origin' => 'nfc'], $plus);
    }

    public function test_la_cle_publique_est_absente_quand_web_push_est_coupe(): void
    {
        config(['webpush.enabled' => false, 'webpush.public_key' => 'PUB', 'webpush.private_key' => 'PRIV']);
        $this->getJson('/api/frontend/push/key')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.key', null);
    }

    public function test_la_cle_publique_est_servie_quand_web_push_est_actif(): void
    {
        config(['webpush.enabled' => true, 'webpush.public_key' => 'PUBKEY', 'webpush.private_key' => 'PRIV']);
        $this->getJson('/api/frontend/push/key')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'PUBKEY');
        // la clé privée ne sort JAMAIS
        $this->assertStringNotContainsString('PRIV', (string) $this->getJson('/api/frontend/push/key')->getContent());
    }

    public function test_un_visiteur_non_connecte_ne_peut_pas_s_abonner(): void
    {
        $this->postJson(self::URL, $this->corps())->assertStatus(401);
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_un_client_connecte_enregistre_son_appareil_sans_exposer_les_secrets(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u, ['*']);
        $r = $this->postJson(self::URL, $this->corps())->assertStatus(201);
        $this->assertStringNotContainsString('p256dh', (string) $r->getContent());
        $this->assertStringNotContainsString(self::cle('appareil')['p256dh'], (string) $r->getContent());
        $a = WebPushSubscription::query()->firstOrFail();
        $this->assertSame($u->id, (int) $a->user_id);
        $this->assertSame(hash('sha256', 'https://push.example.test/send/AAA'), $a->endpoint_hash);
        $this->assertSame('nfc', $a->origin);
    }

    public function test_se_reabonner_depuis_le_meme_appareil_ne_cree_pas_de_doublon(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps())->assertStatus(201);
        $this->postJson(self::URL, $this->corps(plus: ['keys' => self::cle('autre')]))->assertStatus(201);
        $this->assertSame(1, WebPushSubscription::query()->count());
        $this->assertSame(self::cle('autre')['p256dh'], WebPushSubscription::query()->first()->p256dh);
    }

    public function test_un_appareil_reconnecte_sous_un_autre_compte_change_de_proprietaire(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Sanctum::actingAs($a, ['*']);
        $this->postJson(self::URL, $this->corps())->assertStatus(201);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($b, ['*']);
        $this->postJson(self::URL, $this->corps())->assertStatus(201);
        $this->assertSame(1, WebPushSubscription::query()->count());
        $this->assertSame($b->id, (int) WebPushSubscription::query()->first()->user_id);
    }

    public function test_un_point_d_acces_en_http_ou_exotique_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps('http://push.example.test/x'))->assertStatus(422);
        $this->postJson(self::URL, $this->corps('javascript:alert(1)'))->assertStatus(422);
        $this->postJson(self::URL, ['endpoint' => 'https://push.example.test/x'])->assertStatus(422); // sans clés
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    /** @return array<string, array{0:string}> */
    public static function adressesPiegees(): array
    {
        return [
            'loopback IPv4' => ['https://127.0.0.1/x'], 'localhost' => ['https://localhost:8766/api'], 'métadonnées cloud' => ['https://169.254.169.254/latest/meta-data'],
            'IPv6' => ['https://[::1]/'], 'réseau privé' => ['https://10.0.0.5:9200/'], 'réseau privé 192' => ['https://192.168.1.1/'],
            'hôte interne' => ['https://internal.corp.local/admin'], 'majuscules' => ['HTTPS://127.0.0.1/x'], 'sans hôte' => ['https:///x'],
            'port exotique' => ['https://push.example.test:8443/x'], 'identifiants' => ['https://user:pw@push.example.test/x'],
            'préfixe de nom' => ['https://evilpush.example.test/x'], 'suffixe de nom' => ['https://push.example.test.evil.com/x'],
        ];
    }

    /** @dataProvider adressesPiegees */
    public function test_une_adresse_interne_ou_hors_liste_est_refusee_avant_tout_enregistrement(string $adresse): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps($adresse))->assertStatus(422);
        $this->assertSame(0, WebPushSubscription::query()->count(), 'rien n\'est enregistré (le serveur n\'enverra jamais vers cette adresse)');
    }

    public function test_les_vrais_services_de_notification_des_navigateurs_sont_acceptes(): void
    {
        $defaut = (require config_path('webpush.php'))['allowed_hosts'];
        config(['webpush.allowed_hosts' => $defaut]);
        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/xyz', 'https://web.push.apple.com/QAbc123',
            'https://wns2-par02p.notify.windows.com/w/?token=x',
        ] as $i => $adresse) {
            $this->postJson(self::URL, $this->corps($adresse))->assertStatus(201);
        }
        // une adresse qui ressemble mais n'en est pas
        $this->postJson(self::URL, $this->corps('https://fcm.googleapis.com.evil.com/x'))->assertStatus(422);
        $this->postJson(self::URL, $this->corps('https://evilgoogleapis.com/x'))->assertStatus(422);
        // Googleapis est multi-locataire : seul `fcm.googleapis.com` est accepté (un seau de stockage appartient à n'importe qui)
        $this->postJson(self::URL, $this->corps('https://storage.googleapis.com/seau-pirate/x'))->assertStatus(422);
        $this->postJson(self::URL, $this->corps('https://seau.storage.googleapis.com/x'))->assertStatus(422);
    }

    public function test_l_interrupteur_de_banc_est_sans_effet_hors_local_et_test(): void
    {
        config(['webpush.allowed_hosts' => ['127.0.0.1'], 'webpush.insecure_endpoints' => true]);
        $this->assertTrue(\App\Services\Push\PushEndpoint::estAutorise('http://127.0.0.1:9201/x'), 'en test : le banc local fonctionne');
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(\App\Services\Push\PushEndpoint::estAutorise('http://127.0.0.1:9201/x'), 'hors local / test : l\'interrupteur est ignoré même s\'il est posé');
        $this->assertFalse(\App\Services\Push\PushEndpoint::estAutorise('https://127.0.0.1/x'), 'et les adresses IP restent refusées');
    }

    public function test_une_cle_d_appareil_invalide_est_refusee_a_l_abonnement(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach ([['p256dh' => 'k', 'auth' => 'a'], ['p256dh' => 'pas du base64 !!', 'auth' => self::cle('x')['auth']], ['p256dh' => self::cle('x')['p256dh'], 'auth' => 'court']] as $cles) {
            $this->postJson(self::URL, $this->corps(plus: ['keys' => $cles]))->assertStatus(422);
        }
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_un_tableau_a_la_place_d_une_chaine_donne_422_et_jamais_500(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps(plus: ['origin' => ['x']]))->assertStatus(201); // origine illisible → « site »
        $this->postJson(self::URL, ['endpoint' => ['x'], 'keys' => self::cle('a')])->assertStatus(422);
        $this->postJson(self::URL, $this->corps(plus: ['keys' => ['p256dh' => ['x'], 'auth' => ['y']]]))->assertStatus(422);
    }

    public function test_la_meme_adresse_ecrite_autrement_est_un_seul_appareil(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps('HTTPS://PUSH.example.test:443/Case/X'))->assertStatus(201);
        $this->postJson(self::URL, $this->corps('https://push.example.test/Case/X'))->assertStatus(201);
        $this->assertSame(1, WebPushSubscription::query()->count());
        $this->assertSame('https://push.example.test/Case/X', WebPushSubscription::query()->first()->endpoint, 'stockée sous sa forme normalisée');
    }

    public function test_une_adresse_que_guzzle_refuse_donne_422_et_jamais_500(): void
    {
        $defaut = (require config_path('webpush.php'))['allowed_hosts'];
        config(['webpush.allowed_hosts' => $defaut]);
        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach (['https://evil.com\\.updates.push.services.mozilla.com/x', 'https://evil.com\\.push.apple.com/x', 'https://evil.com\\.notify.windows.com/x'] as $adresse) {
            $this->postJson(self::URL, $this->corps($adresse))->assertStatus(422);
        }
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_se_desabonner_avec_une_adresse_ecrite_autrement_retire_bien_l_appareil(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps('https://push.example.test/Case/X'))->assertStatus(201);
        $this->deleteJson(self::URL, ['endpoint' => 'HTTPS://PUSH.example.test:443/Case/X'])->assertOk();
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_une_origine_inconnue_est_ramenee_a_site_au_lieu_de_faire_echouer_l_abonnement(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps(plus: ['origin' => 'flyer']))->assertStatus(201);
        $this->assertSame('site', WebPushSubscription::query()->firstOrFail()->origin);
    }

    public function test_un_jeton_de_borne_ne_peut_pas_s_abonner(): void
    {
        $u = User::factory()->create();
        $jeton = $u->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$jeton])->postJson(self::URL, $this->corps())->assertStatus(403);
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_le_sixieme_appareil_evince_le_moins_recemment_reussi_et_le_plafond_tient_a_cinq(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u, ['*']);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::URL, $this->corps('https://push.example.test/send/'.$i))->assertStatus(201);
        }
        // TOUS ont déjà reçu des notifications (cas réel : aucun n'est « jamais réussi ») ; l'appareil 1 le moins récemment
        foreach ([1 => 30, 2 => 5, 3 => 4, 4 => 3, 5 => 2] as $n => $jours) {
            WebPushSubscription::query()->where('endpoint', 'like', '%/'.$n)->update(['last_success_at' => now()->subDays($jours)]);
        }
        $this->postJson(self::URL, $this->corps('https://push.example.test/send/6'))->assertStatus(201);
        $this->assertSame(5, WebPushSubscription::query()->where('user_id', $u->id)->count(), 'plafond EXACT de 5 appareils');
        $this->assertFalse(WebPushSubscription::query()->where('endpoint', 'like', '%/1')->exists(), 'le moins récemment réussi part');
        $this->assertTrue(WebPushSubscription::query()->where('endpoint', 'like', '%/6')->exists(), 'le nouvel appareil est gardé');
        // un 7e et un 8e : toujours 5
        $this->postJson(self::URL, $this->corps('https://push.example.test/send/7'))->assertStatus(201);
        $this->postJson(self::URL, $this->corps('https://push.example.test/send/8'))->assertStatus(201);
        $this->assertSame(5, WebPushSubscription::query()->where('user_id', $u->id)->count());
    }

    public function test_se_reabonner_a_l_identique_fait_avancer_updated_at(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson(self::URL, $this->corps())->assertStatus(201);
        WebPushSubscription::query()->update(['updated_at' => now()->subDays(80)]);
        $this->postJson(self::URL, $this->corps())->assertStatus(201);
        $this->assertTrue(WebPushSubscription::query()->first()->updated_at->gt(now()->subDay()), 'le réabonnement prouve que l\'appareil est vivant');
    }

    public function test_la_suppression_du_compte_retire_ses_appareils(): void
    {
        $u = User::factory()->create();
        $autre = User::factory()->create();
        foreach ([[$u, 'U1'], [$u, 'U2'], [$autre, 'A1']] as [$qui, $fin]) {
            $e = 'https://push.example.test/send/'.$fin;
            WebPushSubscription::query()->create(['user_id' => $qui->id, 'endpoint_hash' => hash('sha256', $e), 'endpoint' => $e, 'p256dh' => 'k', 'auth' => 'a']);
        }
        app(\App\Services\Account\AnonymisationCompte::class)->anonymiserIdentite($u);
        $this->assertSame(0, WebPushSubscription::query()->where('user_id', $u->id)->count());
        $this->assertSame(1, WebPushSubscription::query()->where('user_id', $autre->id)->count(), 'les autres clients ne sont pas touchés');
    }

    public function test_se_desabonner_ne_retire_que_son_propre_appareil(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Sanctum::actingAs($a, ['*']);
        $this->postJson(self::URL, $this->corps('https://push.example.test/send/A'))->assertStatus(201);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($b, ['*']);
        $this->postJson(self::URL, $this->corps('https://push.example.test/send/B'))->assertStatus(201);
        // b tente de retirer l'appareil de a : rien ne bouge
        $this->deleteJson(self::URL, ['endpoint' => 'https://push.example.test/send/A'])->assertOk();
        $this->assertSame(2, WebPushSubscription::query()->count());
        $this->deleteJson(self::URL, ['endpoint' => 'https://push.example.test/send/B'])->assertOk();
        $this->assertSame(1, WebPushSubscription::query()->count());
    }
}
