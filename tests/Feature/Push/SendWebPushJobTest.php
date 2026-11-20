<?php

namespace Tests\Feature\Push;

use App\Jobs\SendWebPushJob;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Push\WebPushSender;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

/**
 * [NFC / NOTIFICATIONS 2026-11-20 · S1.2] Envoi Web Push : désactivé = rien ; 201 = succès noté ; 404/410 = abonnement supprimé ;
 * échecs répétés = supprimé ; jamais d'exception vers l'appelant. Aucun test ne sort sur Internet (faux WebPush injecté).
 */
class SendWebPushJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function actif(): void
    {
        config(['webpush.enabled' => true, 'webpush.public_key' => 'PUB', 'webpush.private_key' => 'PRIV', 'webpush.max_failures' => 3, 'webpush.allowed_hosts' => ['push.example.test']]);
    }

    private function abonner(User $u, string $fin, array $plus = []): WebPushSubscription
    {
        $e = 'https://push.example.test/send/'.$fin;

        return WebPushSubscription::query()->create(array_merge([
            'user_id' => $u->id, 'endpoint_hash' => hash('sha256', $e), 'endpoint' => $e, 'p256dh' => 'k', 'auth' => 'a',
        ], $plus));
    }

    /** @param array<string,int> $statuts point d'accès (fin) → code HTTP renvoyé par le service de notification */
    private function envoyeur(array $statuts, ?array &$vus = null): WebPushSender
    {
        $vus = [];

        return new WebPushSender(function () use ($statuts, &$vus) {
            return new class($statuts, $vus) extends WebPush {
                public array $file = [];

                public function __construct(private array $statuts, private array &$vus)
                {
                }

                public function queueNotification($subscription, ?string $payload = null, array $options = [], array $auth = []): void
                {
                    $this->file[] = [$subscription->getEndpoint(), $payload];
                    $this->vus[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => $payload, 'options' => $options];
                }

                public function flush(?int $batchSize = null): \Generator
                {
                    foreach ($this->file as [$endpoint]) {
                        if (str_ends_with($endpoint, '/BAD')) {
                            // comme la VRAIE bibliothèque : le chiffrement / la signature se font dans flush(), pas dans queueNotification()
                            throw new \InvalidArgumentException('only uncompressed keys are supported');
                        }
                        $code = 500;
                        foreach ($this->statuts as $fin => $c) {
                            if (str_ends_with($endpoint, '/'.$fin)) {
                                $code = $c;
                            }
                        }
                        $ok = $code >= 200 && $code < 300;
                        yield new MessageSentReport(new PsrRequest('POST', $endpoint), new PsrResponse($code), $ok, $ok ? 'OK' : 'refus '.$code);
                    }
                    $this->file = [];
                }
            };
        });
    }

    public function test_desactive_aucune_requete_et_aucune_erreur(): void
    {
        config(['webpush.enabled' => false, 'webpush.public_key' => 'PUB', 'webpush.private_key' => 'PRIV']);
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $vus = null;
        $this->assertSame(0, $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x']));
        $this->assertSame([], $vus);
    }

    public function test_cles_absentes_equivaut_a_desactive(): void
    {
        config(['webpush.enabled' => true, 'webpush.public_key' => '', 'webpush.private_key' => '']);
        $this->assertFalse(WebPushSender::actif());
    }

    public function test_201_succes_note_et_charge_transmise_en_json(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $a = $this->abonner($u, 'A', ['failure_count' => 2]);
        $vus = null;
        $n = $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'Bon appétit !', 'tag' => 'order-1']);
        $this->assertSame(1, $n);
        $a->refresh();
        $this->assertNotNull($a->last_success_at);
        $this->assertSame(0, $a->failure_count);
        $this->assertSame('Bon appétit !', json_decode($vus[0]['payload'], true)['body']);
    }

    // ───── Urgence et durée de vie : valeurs validées (audit adverse tours 1 et 2, S-02 / S2-02) ─────

    public function test_l_urgence_du_message_est_transmise_au_service_et_jamais_chiffree(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $vus = null;
        $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x', 'urgency' => 'normal']);
        $this->assertSame('normal', $vus[0]['options']['urgency']);
        $this->assertArrayNotHasKey('urgency', json_decode($vus[0]['payload'], true), 'un réglage d\'envoi n\'est pas une donnée du message');
        $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame('high', $vus[0]['options']['urgency'], 'sans urgence demandée : celle de la configuration');
    }

    public function test_une_urgence_invalide_retombe_sur_haute_au_lieu_de_vider_le_parc(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        foreach ([['urgency' => 'urgent!'], ['urgency' => ''], ['urgency' => ['x']], ['urgency' => 5]] as $charge) {
            $vus = null;
            $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x'] + $charge);
            $this->assertSame('high', $vus[0]['options']['urgency'], json_encode($charge));
        }
        config(['webpush.urgency' => 'Normal ']); // casse et espaces tolérés
        $vus = null;
        $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame('normal', $vus[0]['options']['urgency']);
        config(['webpush.urgency' => 'tres-urgent']); // faute de frappe dans le .env : jamais un 400 sur chaque appareil
        $this->envoyeur(['A' => 201], $vus)->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame('high', $vus[0]['options']['urgency']);
    }

    public function test_une_duree_de_vie_hors_bornes_retombe_sur_quinze_minutes(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        foreach ([[-5, 900], [0, 900], [10, 900], [999999, 900], [3600, 3600], [900, 900]] as [$configure, $attendu]) {
            config(['webpush.ttl' => $configure]);
            $recu = null;
            $sender = new WebPushSender(function (array $p) use (&$recu) {
                $recu = $p['defaut'];

                return new class extends WebPush {
                    public function __construct() {}

                    public function queueNotification($subscription, ?string $payload = null, array $options = [], array $auth = []): void {}

                    public function flush(?int $batchSize = null): \Generator { yield from []; }
                };
            });
            $sender->sendToUser($u->id, ['body' => 'x']);
            $this->assertSame($attendu, $recu['TTL'], 'TTL configuré '.$configure);
        }
    }

    public function test_410_supprime_l_abonnement_mort_mais_garde_les_autres(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'MORT');
        $this->abonner($u, 'VIVANT');
        $n = $this->envoyeur(['MORT' => 410, 'VIVANT' => 201])->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(1, $n);
        $this->assertFalse(WebPushSubscription::query()->where('endpoint', 'like', '%/MORT')->exists());
        $this->assertTrue(WebPushSubscription::query()->where('endpoint', 'like', '%/VIVANT')->exists());
    }

    public function test_echecs_repetes_finissent_par_supprimer_l_abonnement(): void
    {
        $this->actif(); // max_failures = 3
        $u = User::factory()->create();
        $a = $this->abonner($u, 'KO');
        $e = $this->envoyeur(['KO' => 400]); // refus DÉFINITIF propre à l'appareil (400) : compté
        $e->sendToUser($u->id, ['body' => 'x']);
        $e->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(2, $a->fresh()->failure_count);
        $e->sendToUser($u->id, ['body' => 'x']);
        $this->assertNull($a->fresh(), 'supprimé au 3e échec consécutif');
    }

    public function test_une_panne_passagere_du_service_n_est_jamais_comptee_contre_l_appareil(): void
    {
        $this->actif(); // max_failures = 3
        $u = User::factory()->create();
        $a = $this->abonner($u, 'A');
        foreach ([500, 502, 503, 429, 408, 413, 500, 500, 500] as $code) { // 413 = NOTRE message trop gros : jamais la faute de l'appareil
            $this->envoyeur(['A' => $code])->sendToUser($u->id, ['body' => 'x']);
        }
        $this->assertNotNull($a->fresh(), 'huit pannes passagères de suite : l\'abonnement valide est conservé');
        $this->assertSame(0, $a->fresh()->failure_count);
        // 401 / 403 = NOTRE signature refusée (clé VAPID inexacte) : jamais contre l'appareil non plus
        foreach ([401, 403, 401, 403, 401, 403] as $code) {
            $this->envoyeur(['A' => $code])->sendToUser($u->id, ['body' => 'x']);
        }
        $this->assertNotNull($a->fresh());
        $this->assertSame(0, $a->fresh()->failure_count);
        // les refus définitifs propres à l'appareil (400…), eux, comptent
        $this->envoyeur(['A' => 400])->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(1, $a->fresh()->failure_count);
    }

    public function test_une_configuration_vapid_invalide_ne_supprime_aucun_abonnement(): void
    {
        $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $this->abonner($u, 'B');
        // clé privée tronquée, clé publique avec une faute de frappe, clé entre guillemets : fautes du SERVEUR, pas des appareils
        foreach ([
            ['publicKey' => $vapid['publicKey'], 'privateKey' => substr($vapid['privateKey'], 0, 20)],
            ['publicKey' => 'x'.$vapid['publicKey'], 'privateKey' => $vapid['privateKey']],
            ['publicKey' => $vapid['publicKey'], 'privateKey' => '"'.$vapid['privateKey'].'"'],
            // bonne longueur, clé CORROMPUE (un caractère changé au milieu) : ne casse qu'à la signature
            ['publicKey' => substr($vapid['publicKey'], 0, 40).($vapid['publicKey'][40] === 'A' ? 'B' : 'A').substr($vapid['publicKey'], 41), 'privateKey' => $vapid['privateKey']],
        ] as $cles) {
            config(['webpush.enabled' => true, 'webpush.public_key' => $cles['publicKey'], 'webpush.private_key' => $cles['privateKey'], 'webpush.allowed_hosts' => ['push.example.test']]);
            $this->assertSame(0, (new WebPushSender())->sendToUser($u->id, ['body' => 'x']));
            $this->assertSame(2, WebPushSubscription::query()->where('user_id', $u->id)->count(), 'aucun abonnement supprimé pour une faute de configuration');
        }
    }

    public function test_une_configuration_vapid_VALIDE_laisse_passer_la_verification_et_livre(): void
    {
        // Sans `fabrique` injectée : la vérification de signature du démarrage s'exécute VRAIMENT avec de vraies clés (régression : une
        // première version rejetait des clés valides, donc n'envoyait JAMAIS rien — les tests d'échec passaient à vide).
        $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();
        config(['webpush.enabled' => true, 'webpush.public_key' => $vapid['publicKey'], 'webpush.private_key' => $vapid['privateKey'], 'webpush.allowed_hosts' => ['push.example.test']]);
        $u = User::factory()->create();
        $b64 = fn (string $o) => rtrim(strtr(base64_encode($o), '+/', '-_'), '=');
        $paire = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
        $pub = "\x04".str_pad($paire['x'], 32, "\0", STR_PAD_LEFT).str_pad($paire['y'], 32, "\0", STR_PAD_LEFT);
        $a = $this->abonner($u, 'BON', ['p256dh' => $b64($pub), 'auth' => $b64(random_bytes(16))]);
        $pile = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new PsrResponse(201)]));
        $sender = new class($pile) extends WebPushSender {
            public function __construct(private $pile) { parent::__construct(null); }

            protected function webpush(): WebPush
            {
                return new WebPush(['VAPID' => ['subject' => (string) config('webpush.subject'), 'publicKey' => (string) config('webpush.public_key'), 'privateKey' => (string) config('webpush.private_key')]], [], 4, ['handler' => $this->pile]);
            }
        };
        $this->assertSame(1, $sender->sendToUser($u->id, ['body' => 'x']), 'clés VAPID valides : la vérification du démarrage laisse passer et la notification est livrée');
        $this->assertNotNull($a->fresh()->last_success_at);
    }

    public function test_une_liste_d_hotes_vide_ne_supprime_aucun_abonnement(): void
    {
        $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();
        config(['webpush.enabled' => true, 'webpush.public_key' => $vapid['publicKey'], 'webpush.private_key' => $vapid['privateKey'], 'webpush.allowed_hosts' => []]);
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $this->assertSame(0, (new WebPushSender())->sendToUser($u->id, ['body' => 'x']));
        $this->assertSame(1, WebPushSubscription::query()->count(), 'faute de configuration : rien n\'est effacé');
    }

    public function test_une_exception_ne_remonte_jamais_a_l_appelant(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $sender = new WebPushSender(function () { throw new \RuntimeException('réseau en panne'); });
        $this->assertSame(0, $sender->sendToUser($u->id, ['body' => 'x']));
    }

    public function test_la_tache_construit_le_texte_a_l_envoi_depuis_la_commande(): void
    {
        $this->actif();
        $u = User::factory()->create(['loyalty_points' => 130]);
        $this->abonner($u, 'A');
        $order = \App\Models\Order::factory()->create([
            'user_id' => $u->id, 'branch_id' => \App\Models\Branch::factory()->create()->id, 'status' => \App\Enums\OrderStatus::ACCEPT, 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'queue_number' => 42, 'loyalty_points_awarded' => 120,
        ]);
        \Illuminate\Support\Facades\DB::table('loyalty_transactions')->insert(['user_id' => $u->id, 'loyalty_code' => 'T1', 'order_id' => $order->id, 'type' => 'earn', 'points' => 120, 'balance_after' => 130, 'source_surface' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $vus = null;
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::ACCEPT))->handle($this->envoyeur(['A' => 201], $vus));
        $charge = json_decode($vus[0]['payload'], true);
        $this->assertSame('Commande n° 42 validée', $charge['title']);
        $this->assertSame('On s’en occupe : vous recevrez une alerte dès qu’elle est prête. 120 points gagnés, solde : 130 points.', $charge['body']);
        $this->assertArrayNotHasKey('urgency', $charge, 'l\'urgence est un réglage d\'envoi : jamais dans le message chiffré');
        $this->assertSame('order-'.$order->id, $charge['tag']);
        $this->assertSame(120, $charge['points_awarded']);
    }

    public function test_une_notification_validee_retardee_est_abandonnee_si_la_commande_est_deja_prete(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $order = \App\Models\Order::factory()->create(['user_id' => $u->id, 'branch_id' => \App\Models\Branch::factory()->create()->id, 'status' => \App\Enums\OrderStatus::PREPARED, 'order_type' => \App\Enums\OrderType::TAKEAWAY]);
        $vus = null;
        // « validée » (retardée de 5 s) arrive APRÈS « prête » : elle remplacerait « prête » à l'écran du client — elle est abandonnée
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::ACCEPT))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertSame([], $vus);
        // et « prête » elle-même part bien
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::PREPARED))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertCount(1, $vus);
    }

    public function test_une_commande_annulee_ne_recoit_plus_aucune_notification_d_avancement(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $order = \App\Models\Order::factory()->create(['user_id' => $u->id, 'branch_id' => \App\Models\Branch::factory()->create()->id, 'status' => \App\Enums\OrderStatus::CANCELED]);
        $vus = null;
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::ACCEPT))->handle($this->envoyeur(['A' => 201], $vus));
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::PREPARED))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertSame([], $vus, 'ni « validée » ni « prête » pour une commande annulée');
        // … mais « annulée » elle-même part
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::CANCELED))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertCount(1, $vus);
        $this->assertStringContainsString('annulée', json_decode($vus[0]['payload'], true)['body']);
    }

    public function test_la_table_de_peremption(): void
    {
        $S = \App\Enums\OrderStatus::class;
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::PREPARED, $S::ACCEPT));
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::DELIVERED, $S::PREPARED));
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::OUT_FOR_DELIVERY, $S::ACCEPT));
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::REJECTED, $S::PREPARED));
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::RETURNED, $S::DELIVERED));
        $this->assertFalse(\App\Services\Push\OrderPushMessage::estPerimee($S::ACCEPT, $S::ACCEPT), 'même statut : pas périmée');
        $this->assertFalse(\App\Services\Push\OrderPushMessage::estPerimee($S::ACCEPT, $S::PREPARED), 'on n\'abandonne pas une notification en avance sur la base');
        $this->assertFalse(\App\Services\Push\OrderPushMessage::estPerimee($S::PREPARED, $S::CANCELED), '« annulée » n\'est jamais périmée');
        $this->assertFalse(\App\Services\Push\OrderPushMessage::estPerimee($S::PREPARING, $S::PREPARED));
        $this->assertFalse(\App\Services\Push\OrderPushMessage::estPerimee($S::PREPARING, $S::ACCEPT), '« en préparation » ne périme pas « validée » (paiement en ligne)');
        $this->assertTrue(\App\Services\Push\OrderPushMessage::estPerimee($S::PREPARED, $S::PREPARING));
    }

    public function test_un_abonnement_inutilisable_est_supprime_sans_empoisonner_les_autres_appareils(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'BAD');
        $ok = $this->abonner($u, 'OK');
        $n = $this->envoyeur(['OK' => 201])->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(1, $n, 'l\'appareil valide reçoit quand même la notification');
        $this->assertFalse(WebPushSubscription::query()->where('endpoint', 'like', '%/BAD')->exists(), 'l\'abonnement inutilisable est retiré');
        $this->assertNotNull($ok->fresh()->last_success_at);
    }

    public function test_avec_la_vraie_bibliotheque_une_cle_invalide_n_empeche_pas_les_autres_appareils(): void
    {
        $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();
        config(['webpush.enabled' => true, 'webpush.public_key' => $vapid['publicKey'], 'webpush.private_key' => $vapid['privateKey'], 'webpush.allowed_hosts' => ['push.example.test']]);
        $u = User::factory()->create();
        $b64 = fn (string $o) => rtrim(strtr(base64_encode($o), '+/', '-_'), '=');
        // appareil MAUVAIS : clé de 1 octet ; appareil BON : vraie paire P-256
        $this->abonner($u, 'MAUVAIS', ['p256dh' => 'k', 'auth' => 'a']);
        $paire = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
        $pub = "\x04".str_pad($paire['x'], 32, "\0", STR_PAD_LEFT).str_pad($paire['y'], 32, "\0", STR_PAD_LEFT);
        $bon = $this->abonner($u, 'BON', ['p256dh' => $b64($pub), 'auth' => $b64(random_bytes(16))]);
        $requetes = [];
        $pile = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new PsrResponse(201)]));
        $pile->push(\GuzzleHttp\Middleware::history($requetes));
        $sender = new WebPushSender(fn ($p) => new WebPush($p['auth'], $p['defaut'], 15, ['handler' => $pile]));
        $this->assertSame(1, $sender->sendToUser($u->id, ['body' => 'Bon appétit']), 'l\'appareil valide est servi (vraie bibliothèque, vrai chiffrement)');
        $this->assertCount(1, $requetes);
        $this->assertSame('aes128gcm', $requetes[0]['request']->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $requetes[0]['request']->getHeaderLine('Authorization'));
        $this->assertFalse(WebPushSubscription::query()->where('endpoint', 'like', '%/MAUVAIS')->exists(), 'la clé invalide est retirée');
        $this->assertNotNull($bon->fresh()->last_success_at);
    }

    public function test_une_redirection_n_est_jamais_comptee_comme_une_reussite(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $a = $this->abonner($u, 'A');
        $n = $this->envoyeur(['A' => 307])->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(0, $n);
        $this->assertNull($a->fresh()->last_success_at);
        $this->assertSame(1, $a->fresh()->failure_count);
    }

    public function test_une_adresse_hors_liste_en_base_n_est_jamais_jointe_et_la_ligne_est_supprimee(): void
    {
        $this->actif();
        $u = User::factory()->create();
        WebPushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'a'), 'endpoint' => 'https://127.0.0.1/interne', 'p256dh' => 'k', 'auth' => 'a']);
        WebPushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'b'), 'endpoint' => 'http://169.254.169.254/latest', 'p256dh' => 'k', 'auth' => 'a']);
        $vus = null;
        $this->assertSame(0, $this->envoyeur([], $vus)->sendToUser($u->id, ['body' => 'x']));
        $this->assertSame([], $vus, 'aucune requête vers une adresse interne');
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_une_adresse_ecrite_autrement_est_quand_meme_rapprochee_du_rapport(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $a = WebPushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'c'), 'endpoint' => 'https://PUSH.example.test:443/Case/X', 'p256dh' => 'k', 'auth' => 'a']);
        $n = $this->envoyeur(['X' => 201])->sendToUser($u->id, ['body' => 'x']);
        $this->assertSame(1, $n, 'majuscules / port par défaut : le rapport est retrouvé');
        $this->assertNotNull($a->fresh()->last_success_at);
    }

    public function test_le_journal_ne_contient_jamais_l_adresse_de_l_appareil(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'SECRET_TOKEN_abc123');
        $contextes = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($msg, $ctx = []) use (&$contextes) { $contextes[] = json_encode([$msg, $ctx]); });
        $this->envoyeur(['SECRET_TOKEN_abc123' => 500])->sendToUser($u->id, ['body' => 'x']);
        $this->assertNotEmpty($contextes);
        $this->assertStringNotContainsString('SECRET_TOKEN_abc123', implode('|', $contextes));
        $this->assertStringContainsString('500', implode('|', $contextes), 'le code HTTP, lui, est journalisé');
    }

    public function test_validee_est_envoyee_meme_si_la_commande_payee_en_ligne_est_deja_en_preparation(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        // paiement en ligne : la politique d'auto-préparation a déjà passé la commande en préparation avant l'envoi retardé
        $order = \App\Models\Order::factory()->create(['user_id' => $u->id, 'branch_id' => \App\Models\Branch::factory()->create()->id, 'status' => \App\Enums\OrderStatus::PREPARING, 'order_type' => \App\Enums\OrderType::TAKEAWAY, 'queue_number' => 9, 'loyalty_points_awarded' => 80]);
        \Illuminate\Support\Facades\DB::table('loyalty_transactions')->insert(['user_id' => $u->id, 'loyalty_code' => 'T2', 'order_id' => $order->id, 'type' => 'earn', 'points' => 80, 'balance_after' => 80, 'source_surface' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $vus = null;
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::ACCEPT))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertCount(1, $vus, '« validée + points » n\'est PAS abandonnée par « en préparation »');
        $this->assertStringContainsString('80 points gagnés', json_decode($vus[0]['payload'], true)['body']);
    }

    public function test_la_tache_n_envoie_rien_pour_un_statut_muet(): void
    {
        $this->actif();
        $u = User::factory()->create();
        $this->abonner($u, 'A');
        $order = \App\Models\Order::factory()->create(['user_id' => $u->id, 'branch_id' => \App\Models\Branch::factory()->create()->id, 'status' => \App\Enums\OrderStatus::PREPARING]);
        $vus = null;
        (new SendWebPushJob($u->id, null, $order->id, \App\Enums\OrderStatus::PREPARING))->handle($this->envoyeur(['A' => 201], $vus));
        $this->assertSame([], $vus, '« en préparation » ne notifie pas');
    }
}
