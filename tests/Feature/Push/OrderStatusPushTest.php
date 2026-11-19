<?php

namespace Tests\Feature\Push;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStatusChanged;
use App\Jobs\SendWebPushJob;
use App\Listeners\SendWebPushOnOrderStatusChange;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Push\OrderPushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [NFC / NOTIFICATIONS 2026-11-20 · S1.3] Quand notifier le client, avec quel texte, et JAMAIS deux fois.
 * Table des textes figée (GOAL §3 / T-1.3.2) ; aucun montant dépensé ; l'événement de statut n'est jamais perturbé.
 */
class OrderStatusPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['webpush.enabled' => true, 'webpush.public_key' => 'PUB', 'webpush.private_key' => 'PRIV', 'webpush.allowed_hosts' => ['push.example.test']]);
        Cache::flush();
    }

    private function commande(array $plus = [], ?User &$client = null): Order
    {
        if ($client === null) {
            $client = User::factory()->create(['loyalty_points' => 0]);
            $client->assignRole('Customer'); // la définition « client » est celle de la fidélité (CustomerAccount::isCustomer)
        }

        return Order::factory()->create(array_merge([
            'user_id' => $client->id, 'branch_id' => Branch::factory()->create()->id, 'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::ACCEPT, 'queue_number' => 17,
        ], $plus));
    }

    private function abonner(User $u): void
    {
        $e = 'https://push.example.test/send/'.$u->id;
        WebPushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', $e), 'endpoint' => $e, 'p256dh' => 'k', 'auth' => 'a']);
    }

    // ───── Table des textes ─────

    public function test_table_des_textes_a_emporter(): void
    {
        $o = $this->commande();
        $this->assertSame('Commande n° 17 validée, on la prépare.', OrderPushMessage::build($o, OrderStatus::ACCEPT)['body']);
        $this->assertNull(OrderPushMessage::build($o, OrderStatus::PREPARING), '« en préparation » ne notifie pas');
        $this->assertSame('Votre commande n° 17 est prête, venez la chercher.', OrderPushMessage::build($o, OrderStatus::PREPARED)['body']);
        $this->assertNull(OrderPushMessage::build($o, OrderStatus::OUT_FOR_DELIVERY), 'pas de « en route » pour une commande à emporter');
        $this->assertSame('Bon appétit ! Merci pour votre commande.', OrderPushMessage::build($o, OrderStatus::DELIVERED)['body']);
        $this->assertSame('Votre commande n° 17 a été annulée.', OrderPushMessage::build($o, OrderStatus::CANCELED)['body']);
        $this->assertSame('Votre commande n° 17 a été annulée.', OrderPushMessage::build($o, OrderStatus::REJECTED)['body']);
        $this->assertNull(OrderPushMessage::build($o, OrderStatus::PENDING));
        $this->assertNull(OrderPushMessage::build($o, OrderStatus::RETURNED));
    }

    public function test_livraison_prevenue_au_depart_du_livreur_pas_a_la_sortie_de_cuisine(): void
    {
        $o = $this->commande(['order_type' => OrderType::DELIVERY]);
        $this->assertNull(OrderPushMessage::build($o, OrderStatus::PREPARED));
        $this->assertSame('Votre commande n° 17 est en route.', OrderPushMessage::build($o, OrderStatus::OUT_FOR_DELIVERY)['body']);
    }

    public function test_les_points_sont_dits_quand_ils_sont_credites_et_jamais_un_montant(): void
    {
        $client = User::factory()->create(['loyalty_points' => 250]);
        $client->assignRole('Customer');
        $o = $this->commande(['loyalty_points_awarded' => 120, 'total' => 12.00], $client);
        $m = OrderPushMessage::build($o, OrderStatus::ACCEPT);
        $this->assertSame('Commande n° 17 validée, on la prépare. +120 points · solde 250.', $m['body']);
        $this->assertSame(120, $m['points_awarded']);
        $this->assertSame(250, $m['points_balance']);
        $this->assertDoesNotMatchRegularExpression('/€|euro|dépens/iu', $m['body'], 'jamais de montant dépensé');
        $sans = OrderPushMessage::build($this->commande(['loyalty_points_awarded' => 0]), OrderStatus::ACCEPT);
        $this->assertStringNotContainsString('points', $sans['body']);
        $this->assertArrayNotHasKey('points_awarded', $sans);
    }

    public function test_la_charge_porte_le_tag_de_remplacement_et_le_lien_de_suivi(): void
    {
        $o = $this->commande();
        $m = OrderPushMessage::build($o, OrderStatus::PREPARED);
        $this->assertSame('order-'.$o->id, $m['tag']);
        $this->assertSame('/#orders', $m['url']);
        $this->assertSame('Le Cayenne', $m['title']);
        $this->assertSame(1, $m['v']);
        $this->assertLessThan(1024, strlen(json_encode($m)));
    }

    // ───── Écouteur ─────

    private function declencher(Order $o, int $nouveau): void
    {
        (new SendWebPushOnOrderStatusChange())->handle(new OrderStatusChanged($o, OrderStatus::PENDING, $nouveau));
    }

    public function test_un_envoi_par_statut_notifiable_et_validee_part_avec_5_secondes_de_retard(): void
    {
        Queue::fake();
        $client = null;
        $o = $this->commande([], $client);
        $this->abonner($client);
        $this->declencher($o, OrderStatus::ACCEPT);
        Queue::assertPushed(SendWebPushJob::class, fn ($j) => $j->userId === $client->id && $j->orderId === $o->id && $j->status === OrderStatus::ACCEPT && $j->delay !== null);
        $this->declencher($o, OrderStatus::PREPARED);
        Queue::assertPushed(SendWebPushJob::class, fn ($j) => $j->status === OrderStatus::PREPARED && $j->delay === null);
        Queue::assertPushed(SendWebPushJob::class, 2);
    }

    public function test_le_meme_statut_emis_deux_fois_ne_notifie_qu_une_fois(): void
    {
        Queue::fake();
        $client = null;
        $o = $this->commande([], $client);
        $this->abonner($client);
        $this->declencher($o, OrderStatus::PREPARED);
        $this->declencher($o, OrderStatus::PREPARED);
        Queue::assertPushed(SendWebPushJob::class, 1);
    }

    public function test_rien_sans_abonnement_sans_client_ou_quand_web_push_est_coupe(): void
    {
        Queue::fake();
        $client = null;
        $o = $this->commande([], $client);
        $this->declencher($o, OrderStatus::PREPARED); // client sans appareil abonné
        Queue::assertNothingPushed();

        $this->abonner($client);
        config(['webpush.enabled' => false]);
        $this->declencher($o, OrderStatus::DELIVERED);
        Queue::assertNothingPushed();

        config(['webpush.enabled' => true]);
        $this->declencher($o, OrderStatus::PREPARING); // statut muet
        Queue::assertNothingPushed();
    }

    public function test_une_commande_de_caisse_ou_de_borne_ne_notifie_jamais_meme_si_le_compte_a_un_appareil(): void
    {
        Queue::fake();
        $client = null;
        $caisse = $this->commande(['source_surface' => 'pos'], $client);
        $this->abonner($client);
        $this->declencher($caisse, OrderStatus::PREPARED);
        $borne = $this->commande(['source_surface' => 'kiosk'], $client);
        $this->declencher($borne, OrderStatus::PREPARED);
        Queue::assertNothingPushed();
        $web = $this->commande(['source_surface' => 'web'], $client);
        $this->declencher($web, OrderStatus::PREPARED);
        Queue::assertPushed(SendWebPushJob::class, 1);
    }

    public function test_un_ancien_client_sans_role_n_est_pas_ecarte(): void
    {
        Queue::fake();
        $ancien = User::factory()->create(); // ni rôle Customer ni is_guest : comme certains comptes d'avant les rôles
        $this->abonner($ancien);
        $o = $this->commande([], $ancien);
        $this->declencher($o, OrderStatus::PREPARED);
        Queue::assertPushed(SendWebPushJob::class, 1);
    }

    public function test_le_compte_d_un_membre_de_l_equipe_ne_recoit_pas_de_notification_de_client(): void
    {
        Queue::fake();
        $equipe = User::factory()->create();
        $equipe->assignRole('POS Operator');
        $this->abonner($equipe);
        $o = $this->commande([], $equipe);
        $this->declencher($o, OrderStatus::PREPARED);
        Queue::assertNothingPushed();
    }

    public function test_apres_une_panne_de_file_le_meme_changement_re_emis_notifie_encore(): void
    {
        $client = null;
        $o = $this->commande([], $client);
        $this->abonner($client);
        config(['queue.default' => 'connexion-inexistante']);
        $this->declencher($o, OrderStatus::PREPARED); // la mise en file échoue : la clé de dédoublonnage ne doit pas rester
        config(['queue.default' => 'sync']);
        Queue::fake();
        $this->declencher($o, OrderStatus::PREPARED);
        Queue::assertPushed(SendWebPushJob::class, 1);
    }

    public function test_une_panne_de_file_n_empeche_jamais_le_changement_de_statut(): void
    {
        $client = null;
        $o = $this->commande([], $client);
        $this->abonner($client);
        config(['queue.default' => 'connexion-inexistante']); // la mise en file lève : l'écouteur doit l'absorber
        $this->declencher($o, OrderStatus::DELIVERED); // ne doit PAS lever
        $this->declencher($o, OrderStatus::ACCEPT);
        $this->assertTrue(true);
    }
}
