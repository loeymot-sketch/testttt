<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Exceptions\MissingIdempotencyKeyException;
use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Http\Middleware\ResolveIdempotencyBranchFromRoute;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [P0 PAIEMENT EN LIGNE 2026-08-07] Le paiement en ligne était MORT pour presque tous les
 * clients — et aucune suite ne le voyait.
 *
 * Plainte owner, capture d'écran à l'appui : sous les moyens de paiement, une ligne rouge
 * « Idempotency requires authenticated user with resolvable branch_id ». La requête
 * n'atteignait JAMAIS Mollie.
 *
 * Cause : `IdempotencyKeyMiddleware` (zone gelée) résout la branche par `users.branch_id`,
 * puis la borne rattachée, puis en DERNIER RECOURS `input('branch_id', -1)`. Or un compte
 * de rôle « Customer » porte **branch_id = 0** et n'a aucune borne. Le site envoyait
 * `branch_id` par convention sur trois appels sur quatre ; celui du paiement l'avait oublié.
 * Mesuré en production : **21 comptes sur 24** dans ce cas, carte ET portefeuille morts.
 *
 * POURQUOI AUCUN TEST NE L'A ATTRAPÉ — et c'est la leçon la plus chère : la fixture de
 * `MollieWalletMethodTest::webCardOrder()` crée le client avec `branch_id = $branch->id`,
 * donc **> 0**. Elle évitait pile le cas qui casse. Un test vert peut encoder le bug quand
 * sa fixture ne ressemble pas à la production. Ici, le client est créé avec `branch_id = 0`,
 * la forme RÉELLE d'un compte client.
 *
 * Correctif côté serveur : `ResolveIdempotencyBranchFromRoute`, posé AVANT `idempotency`,
 * dérive la branche de la COMMANDE portée par la route. Le fichier gelé n'est pas touché —
 * on alimente son point d'extension documenté.
 */
class IdempotencyBranchFromRouteTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
            $this->createdInstalledFlag = true;
        }

        // Le garde n'agit que si l'idempotence est activée — c'est la posture de production
        // (AppServiceProvider REFUSE de démarrer en prod si IDEMPOTENCY_MIDDLEWARE_ENABLED
        // n'est pas true, CLAUDE.md §8). Tester avec le drapeau OFF ne prouverait rien.
        Config::set('idempotency.enabled', true);
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    // ==================================================================
    // (a) LE DÉFAUT EST RÉEL — reproduit sans le correctif
    // ==================================================================

    /**
     * Preuve que le 422 vu par l'owner n'est pas une hypothèse : avec la forme RÉELLE d'un
     * compte client (branch_id = 0, aucune borne) et un corps sans `branch_id`, le garde gelé
     * refuse. C'est exactement le message lu sur la capture d'écran.
     */
    public function test_le_garde_gele_refuse_un_compte_client_sans_branch_id_dans_le_corps(): void
    {
        [$customer, $order] = $this->webCardOrderWithCustomerBranchZero();

        $request = $this->orderRequest($order->id, ['method' => 'applepay']);
        $request->setUserResolver(fn () => $customer);

        $this->expectException(MissingIdempotencyKeyException::class);
        $this->expectExceptionMessage('Idempotency requires authenticated user with resolvable branch_id.');

        app(IdempotencyKeyMiddleware::class)->handle($request, fn ($r) => response('jamais atteint'));
    }

    /**
     * … et la même requête PASSE dès que notre middleware a injecté la branche de la commande.
     * Les deux tests encadrent le correctif : l'un montre la casse, l'autre la réparation.
     */
    public function test_le_middleware_de_branche_debloque_exactement_ce_meme_appel(): void
    {
        [$customer, $order] = $this->webCardOrderWithCustomerBranchZero();

        $request = $this->orderRequest($order->id, ['method' => 'applepay']);
        $request->setUserResolver(fn () => $customer);

        $passed = false;
        (new ResolveIdempotencyBranchFromRoute())->handle($request, function (Request $r) use (&$passed) {
            $passed = true;

            return app(IdempotencyKeyMiddleware::class)->handle($r, fn () => response('atteint'));
        });

        $this->assertTrue($passed, 'le middleware de branche doit laisser passer la requête');
        $this->assertSame((int) $order->branch_id, (int) $request->input('branch_id'));
    }

    // ==================================================================
    // (b) BOUT EN BOUT — la vraie route, avec la vraie pile de middlewares
    // ==================================================================

    /**
     * Le cas de l'owner, joué sur la route réelle : compte client (branch_id = 0), clé
     * d'idempotence présente, AUCUN `branch_id` dans le corps. Avant le correctif : 422 et
     * rien chez Mollie. Après : la requête traverse le garde et atteint le contrôleur.
     */
    public function test_un_client_branch_zero_peut_payer_sans_branch_id_dans_le_corps(): void
    {
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_dummyMollieKey123');
        Http::fake([
            'api.mollie.com/*' => Http::response([
                'resource' => 'payment',
                'id' => 'tr_BRANCH01',
                'mode' => 'test',
                'status' => 'open',
                'amount' => ['value' => '11.80', 'currency' => 'EUR'],
                '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/tr_BRANCH01']],
            ], 201),
        ]);

        [$customer, $order] = $this->webCardOrderWithCustomerBranchZero();

        $response = $this->actingAs($customer, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'web-mollie-' . $order->id . '-applepay')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 'applepay']);

        // L'assertion qui compte : PLUS de refus d'idempotence. Le message exact est vérifié
        // pour ne pas confondre avec un 422 métier légitime (moyen inconnu, commande déjà payée…).
        $this->assertNotSame(
            'Idempotency requires authenticated user with resolvable branch_id.',
            $response->json('message'),
            'le paiement est de nouveau bloqué par la résolution de branche'
        );
        $response->assertOk();
        $this->assertSame('wallet', $response->json('reason'));
    }

    /**
     * Jumeau Google Pay — vérifier Apple seul reproduirait le motif « le correctif est complet
     * sur la surface regardée, pas sur sa jumelle », qui revient dans ce projet depuis le 29/07.
     */
    public function test_le_meme_deblocage_vaut_pour_google_pay(): void
    {
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_dummyMollieKey123');
        Http::fake([
            'api.mollie.com/*' => Http::response([
                'resource' => 'payment',
                'id' => 'tr_BRANCH02',
                'mode' => 'test',
                'status' => 'open',
                'amount' => ['value' => '11.80', 'currency' => 'EUR'],
                '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/tr_BRANCH02']],
            ], 201),
        ]);

        [$customer, $order] = $this->webCardOrderWithCustomerBranchZero();

        $this->actingAs($customer, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'web-mollie-' . $order->id . '-googlepay')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['method' => 'googlepay'])
            ->assertOk()
            ->assertJsonPath('reason', 'wallet');
    }

    // ==================================================================
    // (c) SÉCURITÉ — la vérité du serveur écrase celle du client
    // ==================================================================

    /**
     * Le corps est écrit par le client : s'il pouvait choisir la branche, il pourrait faire
     * varier la portée de sa propre clé d'idempotence d'un envoi à l'autre et contourner la
     * garde anti-double-débit. La branche de la COMMANDE doit gagner, toujours.
     */
    public function test_la_branche_du_serveur_ecrase_celle_envoyee_par_le_client(): void
    {
        [, $order] = $this->webCardOrderWithCustomerBranchZero();

        $request = $this->orderRequest($order->id, ['method' => 'applepay', 'branch_id' => 999999]);

        (new ResolveIdempotencyBranchFromRoute())->handle($request, fn ($r) => response('ok'));

        $this->assertSame((int) $order->branch_id, (int) $request->input('branch_id'));
        $this->assertNotSame(999999, (int) $request->input('branch_id'));
    }

    /**
     * `IdempotencyKeyMiddleware` calcule son empreinte de charge utile sur le corps BRUT
     * (`getContent()`), pas sur les entrées fusionnées. Si `merge()` altérait ce corps, la
     * détection de conflit « même clé, corps différent » (409) changerait de comportement —
     * une régression invisible sur le chemin du paiement.
     */
    public function test_le_corps_brut_reste_intact_donc_l_empreinte_de_charge_utile_aussi(): void
    {
        [, $order] = $this->webCardOrderWithCustomerBranchZero();

        $request = $this->orderRequest($order->id, ['method' => 'applepay']);
        $avant = $request->getContent();
        $empreinteAvant = hash('sha256', $avant ?: '');

        (new ResolveIdempotencyBranchFromRoute())->handle($request, fn ($r) => response('ok'));

        $this->assertSame($avant, $request->getContent());
        $this->assertSame($empreinteAvant, hash('sha256', $request->getContent() ?: ''));
        $this->assertStringNotContainsString('branch_id', (string) $request->getContent());
    }

    // ==================================================================
    // (d) ROBUSTESSE — le middleware n'invente rien et ne casse rien
    // ==================================================================

    /**
     * L'ordre des middlewares est réglé par `$middlewarePriority` (Kernel) où ce middleware ne
     * figure pas : il ne doit donc PAS supposer que la liaison de modèle a déjà eu lieu. Ici le
     * paramètre de route est un identifiant brut, comme avant `SubstituteBindings`.
     */
    public function test_resout_la_branche_meme_si_la_liaison_de_modele_n_a_pas_encore_eu_lieu(): void
    {
        [, $order] = $this->webCardOrderWithCustomerBranchZero();

        // Paramètre laissé sous forme d'identifiant (pas d'instance de modèle).
        $request = $this->orderRequest($order->id, ['method' => 'applepay'], bindModel: false);

        (new ResolveIdempotencyBranchFromRoute())->handle($request, fn ($r) => response('ok'));

        $this->assertSame((int) $order->branch_id, (int) $request->input('branch_id'));
    }

    /**
     * Une route sans commande (création de commande, fidélité…) doit traverser sans être
     * modifiée : le middleware ne doit pas inventer une branche là où il n'en connaît aucune,
     * ni écraser un `branch_id` légitimement fourni par ces appels-là.
     */
    public function test_une_route_sans_commande_est_laissee_intacte(): void
    {
        $request = Request::create('/api/frontend/loyalty/redeem', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 'ABC', 'branch_id' => 7]));
        $request->setRouteResolver(fn () => (new RoutingRoute('POST', 'api/frontend/loyalty/redeem', []))
            ->bind($request));

        (new ResolveIdempotencyBranchFromRoute())->handle($request, fn ($r) => response('ok'));

        $this->assertSame(7, (int) $request->input('branch_id'), 'un appel sans commande ne doit pas être touché');
    }

    /**
     * Commande inexistante : on ne lève pas. Le garde gelé reste seul juge et conserve sa
     * posture fail-closed — un middleware d'infrastructure ne doit pas devenir une NOUVELLE
     * cause de panne sur le chemin du paiement.
     */
    public function test_une_commande_introuvable_ne_leve_pas_et_laisse_le_garde_decider(): void
    {
        $request = $this->orderRequest(987654321, ['method' => 'applepay'], bindModel: false);

        $reached = false;
        (new ResolveIdempotencyBranchFromRoute())->handle($request, function () use (&$reached) {
            $reached = true;

            return response('ok');
        });

        $this->assertTrue($reached, 'le middleware ne doit jamais interrompre la chaîne');
        $this->assertNull($request->input('branch_id'));
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    /**
     * La forme RÉELLE d'un client web : compte de rôle « Customer », `branch_id = 0`, aucune
     * borne rattachée. C'est ce que la fixture historique évitait, et c'est ce qui cassait.
     *
     * @return array{0: User, 1: Order}
     */
    private function webCardOrderWithCustomerBranchZero(): array
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => 0]);

        $this->assertSame(0, (int) $customer->branch_id, 'la fixture doit reproduire la production');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'transaction_id' => null,
            'fiscal_sequence_no' => null,
            'subtotal' => 11.80,
            'total' => 11.80,
        ]);

        return [$customer, $order];
    }

    /** Requête JSON sur la route de paiement, avec le paramètre de route lié ou non. */
    private function orderRequest(int $orderId, array $body, bool $bindModel = true): Request
    {
        $request = Request::create(
            "/api/frontend/order/{$orderId}/mollie-checkout",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_IDEMPOTENCY_KEY' => 'web-mollie-' . $orderId . '-t'],
            json_encode($body)
        );

        $route = (new RoutingRoute('POST', 'api/frontend/order/{frontendOrder}/mollie-checkout', []))
            ->bind($request);
        $route->setParameter(
            'frontendOrder',
            $bindModel ? \App\Models\FrontendOrder::withoutGlobalScopes()->find($orderId) : (string) $orderId
        );
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
