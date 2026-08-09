<?php

namespace Tests\Feature\Promo;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\PromoFlyer;
use App\Services\Promo\PromoFlyerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-08-09 « ameliore … la gestion »] Ce que les tickets RAPPORTENT, et les deux
 * gestes de rattrapage.
 *
 * Le ticket coûte du papier et 10 % de marge. Jusqu'ici l'écran disait qui avait reçu un code,
 * jamais si quelqu'un l'avait utilisé : l'exploitant dépensait à l'aveugle et ne pouvait pas
 * décider de continuer, d'augmenter la remise ou d'arrêter.
 *
 * Deux règles verrouillées ici, parce qu'elles sont faciles à casser sans s'en apercevoir :
 *   · une commande ANNULÉE ne compte PAS comme un retour — sinon le taux se gonfle tout seul
 *     et l'exploitant croit à un succès qui n'existe pas ;
 *   · le taux se calcule sur les tickets RÉELLEMENT IMPRIMÉS — un ticket resté en file n'a
 *     jamais atteint personne, le compter comme un échec ferait renoncer à une idée qui marche.
 */
class PromoFlyerManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private PromoFlyerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->service = app(PromoFlyerService::class);
    }

    private function flyer(string $name = 'Camille'): PromoFlyer
    {
        return $this->service->create($name, (int) $this->branch->id, null, 'test');
    }

    /**
     * Simule une commande passée avec ce code.
     *
     * On passe par la FABRIQUE de commandes et non par une insertion à la main : la table
     * `orders` porte des colonnes obligatoires (utilisateur, type, horodatage, paiement) qu'une
     * insertion partielle rate, et surtout un test qui invente sa propre commande finit par
     * tester une commande qui ne peut pas exister en vrai.
     */
    private function redeem(PromoFlyer $flyer, float $total, int $status = OrderStatus::DELIVERED): int
    {
        $orderId = \App\Models\Order::factory()->create([
            'branch_id' => $this->branch->id,
            'total'     => $total,
            'status'    => $status,
        ])->id;

        DB::table('order_coupons')->insert([
            'order_id'   => $orderId,
            'coupon_id'  => $flyer->coupon_id,
            'user_id'    => 1,
            'discount'   => round($total * 0.1, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** @test */
    public function test_a_redeemed_code_is_reported_with_the_order_it_generated(): void
    {
        $flyer = $this->flyer();
        $this->redeem($flyer, 25.00);

        $usages = $this->service->redemptionsFor([$flyer->coupon_id]);

        $this->assertArrayHasKey((int) $flyer->coupon_id, $usages);
        $this->assertEqualsWithDelta(25.00, $usages[(int) $flyer->coupon_id]['order_total'], 0.01);
        $this->assertEqualsWithDelta(2.50, $usages[(int) $flyer->coupon_id]['discount'], 0.01);
    }

    /**
     * LE PIÈGE. Une commande annulée ne doit pas gonfler le taux de retour — sinon
     * l'exploitant croit à un succès qui n'a jamais eu lieu et continue d'imprimer.
     */
    /** @test */
    public function test_a_cancelled_order_does_not_count_as_a_return(): void
    {
        $flyer = $this->flyer();
        $this->redeem($flyer, 25.00, OrderStatus::CANCELED);

        $this->assertSame(
            [],
            $this->service->redemptionsFor([$flyer->coupon_id]),
            'Une commande annulee est comptee comme un retour : le taux se gonfle tout seul.'
        );
    }

    /** @test */
    public function test_an_unused_code_reports_nothing(): void
    {
        $flyer = $this->flyer();

        $this->assertSame([], $this->service->redemptionsFor([$flyer->coupon_id]));
    }

    /**
     * La règle d'usage du tableau de bord doit être EXACTEMENT celle du moteur de coupons :
     * deux comptages différents feraient croire à un bug de l'un ou de l'autre.
     */
    /** @test */
    public function test_the_dashboard_counts_exactly_like_the_coupon_engine(): void
    {
        $flyer = $this->flyer();
        $this->redeem($flyer, 30.00, OrderStatus::RETURNED);

        $this->assertSame([], $this->service->redemptionsFor([$flyer->coupon_id]));

        // Le moteur de coupons doit, lui aussi, considérer le code comme encore disponible.
        $coupon = Coupon::withoutGlobalScopes()->find($flyer->coupon_id);
        $utilise = DB::table('order_coupons')
            ->join('orders', 'orders.id', '=', 'order_coupons.order_id')
            ->where('order_coupons.coupon_id', $coupon->id)
            ->whereNotIn('orders.status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
            ->count();

        $this->assertSame(0, $utilise);
    }

    /**
     * Une impression ratée laissait un cadeau promis à personne : il faut pouvoir relancer
     * SANS créer un second code au même nom.
     */
    /** @test */
    public function test_a_failed_flyer_can_be_reprinted_without_creating_a_second_code(): void
    {
        $flyer = $this->flyer();
        $codeInitial = $flyer->code;

        // On épuise les tentatives.
        for ($i = 0; $i < PromoFlyer::MAX_ATTEMPTS; $i++) {
            $claimed = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
            if ($claimed !== []) {
                $this->service->acknowledge($claimed[0], false, 'Imprimante hors ligne');
            }
        }
        $this->assertSame(PromoFlyer::STATUS_FAILED, $flyer->fresh()->status);

        $this->service->requeue($flyer->fresh());

        $frais = $flyer->fresh();
        $this->assertSame(PromoFlyer::STATUS_PENDING, $frais->status);
        $this->assertSame(0, (int) $frais->attempts, 'Repartir de 5/5 ferait abandonner au premier cycle.');
        $this->assertSame($codeInitial, $frais->code, 'Le code doit rester le MEME : c\'est le meme cadeau.');
        $this->assertCount(1, $this->service->claimPending((int) $this->branch->id, 'ecran-1'));

        $this->assertSame(
            1,
            PromoFlyer::withoutGlobalScopes()->count(),
            'Reimprimer a cree un second ticket : le client en recevrait deux.'
        );
    }

    /**
     * Un prénom mal tapé, un ticket imprimé en double : le code doit pouvoir être neutralisé,
     * mais la TRACE de ce qui a été offert doit survivre — c'est elle qui rend les
     * statistiques honnêtes.
     */
    /** @test */
    public function test_a_revoked_code_stops_working_but_leaves_its_trace(): void
    {
        $flyer = $this->flyer();

        $this->service->revoke($flyer);

        $coupon = Coupon::withoutGlobalScopes()->find($flyer->coupon_id);
        $this->assertSame((int) Status::INACTIVE, (int) $coupon->status, 'Le code reste utilisable.');
        $this->assertFalse($coupon->isUsableNow(null, null), 'Un code annule doit etre refuse.');

        // La trace demeure.
        $this->assertNotNull($flyer->fresh(), 'Le ticket a ete supprime : on perd ce qui a ete offert.');
        $this->assertSame($flyer->code, $flyer->fresh()->code);

        // Et il ne s'imprime plus.
        $this->assertCount(
            0,
            $this->service->claimPending((int) $this->branch->id, 'ecran-1'),
            'Un code annule ne doit plus sortir du papier.'
        );
    }

    /** @test */
    public function test_revoking_an_already_printed_flyer_keeps_its_printed_status(): void
    {
        $flyer = $this->flyer();
        $claimed = $this->service->claimPending((int) $this->branch->id, 'ecran-1');
        $this->service->acknowledge($claimed[0], true);

        $this->service->revoke($flyer->fresh());

        $this->assertSame(
            PromoFlyer::STATUS_PRINTED,
            $flyer->fresh()->status,
            'Le papier EST sorti : effacer ce fait rendrait l\'historique faux.'
        );
        $this->assertSame(
            (int) Status::INACTIVE,
            (int) Coupon::withoutGlobalScopes()->find($flyer->coupon_id)->status
        );
    }
}
