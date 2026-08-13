<?php

namespace Tests\Feature\KDS;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * « UNE COMMANDE VALIDÉE TROP TÔT PEUT REVENIR » — le filet de cette action.
 *
 * ── POURQUOI CE BANC EXISTE ──────────────────────────────────────────────────────────────────
 * `KitchenDisplaySystemOrderService::reopen()` a été livrée et DÉPLOYÉE le 2026-08-13 au matin, et
 * aucun test ne la couvrait. C'est une action qui MODIFIE l'état d'une commande : c'est précisément
 * la catégorie où une garde élargie par mégarde coûte un plat — ou pire, un plat refait pour un
 * client déjà parti, risque que son propre commentaire nomme.
 *
 * Ce banc n'est pas de la politesse : il verrouille par des assertions les quatre garanties que ce
 * commentaire promet, et il répond à deux questions que mon audit a soulevées sans y répondre.
 *
 * ── LES DEUX QUESTIONS DE L'AUDIT, TRANCHÉES ICI ─────────────────────────────────────────────
 * 1. `PREPARED` est l'un des déclencheurs du CRÉDIT DE POINTS (pour une commande à emporter ou
 *    borne, `AwardLoyaltyPointsOnDelivery` crédite dès PREPARED). Rouvrir puis re-valider peut-il
 *    créditer DEUX FOIS ? Ce banc l'éprouve.
 * 2. `PREPARING` est le déclencheur de l'IMPRESSION AUTOMATIQUE du ticket cuisine. Rouvrir
 *    peut-il faire ressortir un SECOND ticket pour le même plat ? `reopen()` ne diffuse aucun
 *    `OrderStatusChanged` — ce banc épingle ce choix, pour qu'on ne l'« améliore » pas sans voir
 *    qu'il protège de deux doublons.
 */
class KdsReopenPreparedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('k', 40));
        Settings::group('loyalty_setup')->set(['loyalty_points_per_euro' => 10]);

        $this->branche = Branch::factory()->create();
        $this->chef = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000777']);
        $this->chef->assignRole('Chef');
        $this->actingAs($this->chef, 'sanctum');
    }

    private function commande(int $statut, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => $this->branche->id,
            'subtotal'           => 20.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge'    => 0.00, 'total' => 20.00,
            'status'             => $statut,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
            'prepared_at'        => now(),
        ], $extra));
    }

    private function rouvrir(Order $o): array
    {
        return app(KitchenDisplaySystemOrderService::class)->reopen($o->fresh());
    }

    // ── LA GARANTIE CENTRALE ─────────────────────────────────────────────────────────────────

    /** Une commande PRÊTE revient en préparation, et la trace est écrite. */
    public function test_une_commande_prete_revient_en_preparation_avec_sa_trace(): void
    {
        $o = $this->commande(OrderStatus::PREPARED);

        $r = $this->rouvrir($o);

        $this->assertSame($o->id, $r['order_id']);
        $this->assertSame(OrderStatus::PREPARING, (int) $o->fresh()->status);

        $t = OrderStatusTransition::query()->where('order_id', $o->id)->latest('id')->first();
        $this->assertNotNull($t, 'aucune trace : une action qui change l\'état doit se raconter');
        $this->assertSame(OrderStatus::PREPARED, (int) $t->from_status);
        $this->assertSame(OrderStatus::PREPARING, (int) $t->to_status);
        $this->assertSame('kitchen_reopen', $t->reason);
        $this->assertSame((int) $this->chef->id, (int) $t->actor_id, 'on sait QUI a rouvert');
    }

    /**
     * `prepared_at` est EFFACÉ. Le laisser ferait mentir toutes les durées de préparation, et
     * l'écran client annoncerait une commande « prête depuis 10 minutes » pendant qu'on la refait.
     */
    public function test_l_heure_de_pret_est_effacee_sinon_toutes_les_durees_mentent(): void
    {
        $o = $this->commande(OrderStatus::PREPARED, ['prepared_at' => now()->subMinutes(10)]);

        $this->rouvrir($o);

        $this->assertNull($o->fresh()->prepared_at);
    }

    // ── LES REFUS : LE PÉRIMÈTRE DE LA CUISINE ───────────────────────────────────────────────

    /**
     * SEULE une commande PRÊTE se rouvre. Une commande déjà REMISE au client, annulée, rejetée ou
     * partie en livraison n'est plus l'affaire de la cuisine : la rouvrir donnerait un plat à refaire
     * pour quelqu'un qui n'est plus là.
     */
    public function test_seule_une_commande_prete_se_rouvre(): void
    {
        foreach ([
            OrderStatus::DELIVERED,
            OrderStatus::PREPARING,
            OrderStatus::ACCEPT,
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::OUT_FOR_DELIVERY,
        ] as $statut) {
            $o = $this->commande($statut);

            try {
                $this->rouvrir($o);
                $this->fail("statut {$statut} rouvert : la cuisine refait un plat qui n'est plus le sien");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode(), "statut {$statut}");
            }

            $this->assertSame($statut, (int) $o->fresh()->status, "le statut {$statut} a bougé malgré le refus");
        }
    }

    /**
     * UNE COMMANDE D'UNE AUTRE CAISSE NE SE ROUVRE PAS — et la cuisine reçoit une phrase FRANÇAISE.
     *
     * ── CE QUE CE TEST A TROUVÉ ──────────────────────────────────────────────────────────────
     * J'attendais le `abort(403)` « autre succursale » écrit dans `reopen()`. Il n'est JAMAIS
     * atteint : `Order` porte `BranchScope`, donc pour un compte de caisse la ligne n'existe pas
     * dans la requête et `firstOrFail()` lève `ModelNotFoundException` AVANT. Et un compte
     * administrateur (`branch_id = 0`) passe la condition `$userBranchId > 0` sans s'arrêter. La
     * protection tient — la commande n'est jamais rouverte — mais elle tient par le SCOPE, pas par
     * ce `abort`.
     *
     * ET CE QUE J'AI CRU À TORT : je pensais que le message interne anglais
     * (« No query results for model … ») remontait à la cuisine. Le test l'a démenti — la LIAISON
     * IMPLICITE de modèle échoue avant le contrôleur et renvoie un 404 standard, qui ne fuit rien.
     * Le filet français posé dans le contrôleur ne couvre donc qu'une fenêtre étroite (la ligne
     * disparaît entre la liaison et la relecture sous verrou), et son commentaire le dit.
     *
     * On éprouve la ROUTE, pas le service : ce qui compte est ce que la cuisine LIT.
     */
    public function test_une_commande_d_une_autre_caisse_est_refusee_en_francais(): void
    {
        $autre = Branch::factory()->create();
        $o = $this->commande(OrderStatus::PREPARED, ['branch_id' => $autre->id]);

        // 404, et c'est JUSTE : la liaison implicite de modèle ne trouve pas la ligne (BranchScope),
        // donc la requête n'atteint même pas le contrôleur. Rien ne fuit.
        $r = $this->postJson("/api/admin/kds-order/reopen/{$o->id}")->assertStatus(404);

        $corps = $r->getContent();
        $this->assertStringNotContainsString('No query results', $corps,
            'un message interne en anglais atteint l\'écran de la cuisine');
        $this->assertStringNotContainsString('App\\Models', $corps,
            'un chemin de classe interne est affiché à la cuisine');

        $this->assertSame(OrderStatus::PREPARED, (int) $o->fresh()->status,
            'la commande d\'une autre caisse a bougé');
    }

    /** Et par la route aussi, une commande déjà remise est refusée proprement. */
    public function test_par_la_route_une_commande_deja_remise_est_refusee_proprement(): void
    {
        $o = $this->commande(OrderStatus::DELIVERED);

        $r = $this->postJson("/api/admin/kds-order/reopen/{$o->id}")->assertStatus(422);

        $this->assertStringNotContainsString('No query results', (string) $r->json('message'));
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->fresh()->status);
    }

    // ── LES DEUX DOUBLONS QUE CETTE ACTION POURRAIT RÉVEILLER ────────────────────────────────

    /**
     * ROUVRIR NE DIFFUSE PAS `OrderStatusChanged`, ET C'EST VOULU.
     *
     * Cet événement porte QUATRE auditeurs : l'outbox, l'impression automatique du ticket cuisine,
     * le crédit des points, et la notification au client. Le diffuser ici ferait ressortir un SECOND
     * ticket pour le même plat et rejouerait une tentative de crédit.
     *
     * ⛔ Ne pas « réparer » cette absence sans lire ce banc : les surfaces (caisse, écran client)
     * lisent le statut EN DIRECT à chaque sondage — vérifié — donc elles voient le retour en
     * préparation sans avoir besoin de l'événement. Il n'y a pas d'état périmé à corriger.
     */
    public function test_rouvrir_ne_rejoue_ni_ticket_cuisine_ni_notification(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $o = $this->commande(OrderStatus::PREPARED);
        $this->rouvrir($o);

        Event::assertNotDispatched(OrderStatusChanged::class,
            'l\'événement a été diffusé : un second ticket cuisine va sortir pour le même plat');
    }

    /**
     * ET LES POINTS NE SONT PAS CRÉDITÉS DEUX FOIS. `PREPARED` est un déclencheur de crédit pour une
     * commande à emporter : rouvrir puis re-valider ne doit pas payer le client deux fois.
     */
    public function test_rouvrir_puis_revalider_ne_credite_pas_les_points_deux_fois(): void
    {
        $client = User::factory()->create(['phone' => '0644009900', 'is_guest' => \App\Enums\Ask::YES]);
        DB::table('users')->where('id', $client->id)
            ->update(['loyalty_code' => 'KDSREOP1', 'loyalty_points' => 0]);

        $o = $this->commande(OrderStatus::PREPARED, ['loyalty_customer_code' => 'KDSREOP1']);

        // 1ʳᵉ validation : la cuisine dit « prêt » → le crédit part par la voie normale.
        app(\App\Listeners\AwardLoyaltyPointsOnDelivery::class)->handle(
            new OrderStatusChanged($o->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)
        );
        $apres1 = (int) $client->fresh()->loyalty_points;
        $this->assertSame(200, $apres1, '20 € × 10 points/€');

        // On rouvre, puis la cuisine re-valide.
        $this->rouvrir($o);
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::PREPARED]);
        app(\App\Listeners\AwardLoyaltyPointsOnDelivery::class)->handle(
            new OrderStatusChanged($o->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)
        );

        $this->assertSame(200, (int) $client->fresh()->loyalty_points,
            'le client a été payé deux fois pour la même commande');
        $this->assertSame(1, (int) DB::table('loyalty_transactions')->where('order_id', $o->id)->count(),
            'deux lignes au grand-livre pour un seul gain');
    }

    /** Rouvrir deux fois de suite : le second appel est refusé, l'état ne dérive pas. */
    public function test_rouvrir_deux_fois_de_suite_est_refuse_la_seconde(): void
    {
        $o = $this->commande(OrderStatus::PREPARED);

        $this->rouvrir($o);

        try {
            $this->rouvrir($o);
            $this->fail('une commande déjà en préparation a été « rouverte » une seconde fois');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(OrderStatus::PREPARING, (int) $o->fresh()->status);
        $this->assertSame(1, OrderStatusTransition::query()->where('order_id', $o->id)
            ->where('reason', 'kitchen_reopen')->count(), 'deux traces pour une seule réouverture');
    }
}
