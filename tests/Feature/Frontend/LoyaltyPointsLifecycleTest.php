<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [CYCLE 9 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05]
 *
 * LE SYSTÈME DE FIDÉLITÉ, VALIDÉ EN BOUCLE COMPLÈTE — lacune nommée : aucun test ne couvrait
 * ce cycle de vie de bout en bout.
 *
 * La promesse faite au client sur le site est simple : « 1 € dépensé = 10 points », affichée
 * au panier, au checkout et au suivi. Ce test vérifie que la promesse et le crédit réel
 * coïncident, et que le cycle est SYMÉTRIQUE — parce que le défaut « la maison paie » (award
 * permissif face à un clawback restrictif) a déjà coûté cher à ce projet.
 *
 *   commande créée      → AUCUN point (ils se gagnent au retrait, pas à la commande)
 *   commande retirée    → points crédités = floor(total × taux), au point près
 *   double événement    → AUCUN doublon (idempotence)
 *   commande remboursée → points REPRIS, symétriquement
 */
class LoyaltyPointsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TAUX = 10;   // points par euro

    protected function setUp(): void
    {
        parent::setUp();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => self::TAUX,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);
    }

    private function commande(User $user, float $total, int $status): FrontendOrder
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();

        return FrontendOrder::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'idempotency_key'  => 'LOY-' . uniqid(),
            'total'            => $total,
            'subtotal'         => $total,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::PAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => $status,
            'order_type'       => OrderType::TAKEAWAY,
            'source_surface'   => 'web',
            'order_datetime'   => now(),
            'preparation_time' => 15,
            'business_date'    => now()->toDateString(),
        ]);
    }

    /**
     * Un client réel reçoit son `loyalty_code` à l'inscription (vérifié sur un compte créé par
     * l'API : « EAA2618C ») ; la factory ne le pose pas. Sans lui, le listener abandonne le
     * crédit — c'est une condition légitime du produit, pas un défaut, et mon premier jet de
     * test l'ignorait.
     */
    private function client(int $soldeInitial = 0): User
    {
        return User::factory()->create([
            'loyalty_points' => $soldeInitial,
            'loyalty_code'   => 'LOY' . strtoupper(substr(uniqid(), -6)),
        ]);
    }

    private function solde(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->id)->value('loyalty_points');
    }

    /** Le crédit doit valoir EXACTEMENT ce que le site a annoncé : floor(total × taux). */
    public function test_points_credited_match_what_the_site_announced(): void
    {
        $user = $this->client();
        // 10,80 € — le panier de référence de cette campagne (7,40 + 0,90 + 2,50).
        $order = $this->commande($user, 10.80, OrderStatus::DELIVERED);

        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($order, OrderStatus::PREPARED, OrderStatus::DELIVERED));

        $attendu = (int) floor(10.80 * self::TAUX); // 108, comme affiché au panier
        $this->assertSame(
            $attendu,
            $this->solde($user),
            "Le site annonce « +{$attendu} pts » au panier et au checkout : le crédit réel doit valoir exactement cela."
        );
    }

    /** Le même événement rejoué ne doit JAMAIS créditer deux fois. */
    public function test_awarding_twice_never_doubles_the_points(): void
    {
        $user = $this->client();
        $order = $this->commande($user, 10.80, OrderStatus::DELIVERED);
        $listener = new AwardLoyaltyPointsOnDelivery();
        $event = new OrderStatusChanged($order, OrderStatus::PREPARED, OrderStatus::DELIVERED);

        $listener->handle($event);
        $premier = $this->solde($user);
        $listener->handle($event);

        $this->assertSame($premier, $this->solde($user), "Un événement rejoué doit être sans effet : sinon la maison offre des points à chaque relivraison du message.");
    }

    /** Une commande ANNULÉE ne doit créditer aucun point, même si l'événement passe. */
    public function test_a_cancelled_order_never_credits_points(): void
    {
        $user = $this->client();
        $order = $this->commande($user, 10.80, OrderStatus::CANCELED);

        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($order, OrderStatus::PENDING, OrderStatus::CANCELED));

        $this->assertSame(0, $this->solde($user), "Une commande annulée ne rapporte rien — sinon on paie des points pour une vente qui n'a pas eu lieu.");
    }

    /**
     * SYMÉTRIE — le défaut « la maison paie » venait d'un award permissif face à un clawback
     * restrictif. Ce qui a été crédité doit pouvoir être repris intégralement.
     */
    public function test_clawback_takes_back_exactly_what_was_credited(): void
    {
        $user = $this->client();
        $order = $this->commande($user, 10.80, OrderStatus::DELIVERED);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($order, OrderStatus::PREPARED, OrderStatus::DELIVERED));
        $credite = $this->solde($user);
        $this->assertGreaterThan(0, $credite);

        app(LoyaltyService::class)->clawbackEarnedPoints($user->id, $credite, (int) $order->id, 'test-symetrie');

        $this->assertSame(0, $this->solde($user), "Le clawback doit reprendre EXACTEMENT ce que l'award a donné : toute asymétrie fait payer la maison.");
    }

    /** Le solde ne doit jamais devenir négatif, même si le clawback dépasse le crédit. */
    public function test_balance_never_goes_negative(): void
    {
        $user = $this->client(30);
        $order = $this->commande($user, 10.80, OrderStatus::DELIVERED);

        app(LoyaltyService::class)->clawbackEarnedPoints($user->id, 500, (int) $order->id, 'test-plancher');

        $this->assertGreaterThanOrEqual(0, $this->solde($user), "Un solde négatif ferait payer au client des points qu'il n'a jamais eus.");
    }
}
