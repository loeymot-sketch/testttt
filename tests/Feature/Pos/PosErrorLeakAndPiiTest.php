<?php

namespace Tests\Feature\Pos;

use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [SEC 2026-07-22] Deux durcissements POS :
 *
 *  (1) FUITE SQL (P2) — Handler::render() renvoyait le getMessage() BRUT d'une
 *      QueryException (SQL complet + valeurs bindées) au client même en prod
 *      (APP_DEBUG=false). Désormais le message passe par le sanitiseur maison
 *      QueryExceptionLibrary::message() + garde fail-closed (le sanitiseur
 *      lui-même retourne le brut quand errorInfo[1] est absent — connexion
 *      tombée, chemins SQLite). Structure {success,message} + HTTP 422 inchangés.
 *
 *  (2) PII staff POS (P3) + N+1 (P2) — OrderDetailsResource projetait le client
 *      via OrderUserResource (email, username, balance, currency_balance =
 *      solde portefeuille financier) ET forçait ->load('roles','media') (~3
 *      requêtes PAR commande, même si le contrôleur avait déjà eager-loadé).
 *      Désormais projection légère { id, name, phone (PhoneDisplay::safe),
 *      loyalty_points } — miroir de la minimisation delivery_boy déjà en place
 *      dans la même ressource. Consommateur front vérifié : seul `user.name`
 *      est lu (EncaissementComponent.vue:177).
 */
class PosErrorLeakAndPiiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));
    }

    /**
     * Prod (app.debug=false), QueryException AVEC errorInfo[1] (chemin MySQL
     * classique) → message générique traduit, AUCUN fragment SQL ni binding.
     */
    public function test_query_exception_with_error_info_does_not_leak_sql_in_prod(): void
    {
        Config::set('app.debug', false);

        $pdo = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'foodking.nope' doesn't exist");
        $pdo->errorInfo = ['42S02', 1146, "Table 'foodking.nope' doesn't exist"];
        $e = new QueryException(
            'select * from `orders` where `card_token` = ?',
            ['sk_live_SECRET_BINDING'],
            $pdo
        );

        // Invocation DIRECTE du handler (déterministe : évite un catch-all route
        // qui masquerait /_test/* et rendrait le test faux-vert).
        $request = Request::create('/api/anything', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = app(\App\Exceptions\Handler::class)->render($request, $e);

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(trans('all.message.database_error_message'), $payload['message']);

        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('select ', $body);
        $this->assertStringNotContainsString('sqlstate', $body);
        $this->assertStringNotContainsString('sk_live_secret_binding', $body);
        $this->assertStringNotContainsString('card_token', $body);
    }

    /**
     * Prod, QueryException SANS errorInfo (connexion tombée / chemins SQLite) —
     * QueryExceptionLibrary::message() retourne le BRUT dans ce cas : la garde
     * fail-closed du Handler doit quand même servir le message générique.
     */
    public function test_query_exception_without_error_info_is_fail_closed_in_prod(): void
    {
        Config::set('app.debug', false);

        // Pas d'errorInfo → QueryExceptionLibrary::message() retournerait le brut :
        // la garde fail-closed du Handler doit forcer le message générique.
        $e = new QueryException(
            'insert into `order_payments` (`reference`) values (?)',
            ['sk_live_OTHER_SECRET'],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused')
        );

        $request = Request::create('/api/anything', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = app(\App\Exceptions\Handler::class)->render($request, $e);

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(trans('all.message.database_error_message'), $payload['message']);

        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('insert into', $body);
        $this->assertStringNotContainsString('sqlstate', $body);
        $this->assertStringNotContainsString('sk_live_other_secret', $body);
    }

    /**
     * OrderDetailsResource : la projection `user` renvoyée aux files staff POS
     * (counter-collect/pending, show, encaissement) ne contient PLUS
     * email / username / balance / currency_balance — uniquement la projection
     * légère { id, name, phone, loyalty_points }.
     */
    public function test_order_details_resource_user_projection_has_no_pii(): void
    {
        $branch = Branch::factory()->create();

        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Client Cayenne',
            'email' => 'client-secret@example.com',
            'phone' => '0611223344',
            'balance' => 42.50,
            'loyalty_points' => 120,
        ]);
        $customer->assignRole('Customer');

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
        ]);
        $order->load('user', 'orderItems', 'branch');

        $data = (new OrderDetailsResource($order))->toArray(Request::create('/'));

        $this->assertIsArray($data['user']);
        $user = $data['user'];

        // Clés exactes de la projection minimisée — verrouille la surface.
        $this->assertSame(['id', 'name', 'phone', 'loyalty_points'], array_keys($user));

        $this->assertSame($customer->id, $user['id']);
        $this->assertSame('Client Cayenne', $user['name']);
        $this->assertSame('0611223344', $user['phone']);
        $this->assertSame(120, $user['loyalty_points']);

        // PII / financier absents.
        $this->assertArrayNotHasKey('email', $user);
        $this->assertArrayNotHasKey('username', $user);
        $this->assertArrayNotHasKey('balance', $user);
        $this->assertArrayNotHasKey('currency_balance', $user);

        // L'email n'apparaît nulle part ailleurs dans la payload sérialisée.
        $this->assertStringNotContainsString(
            'client-secret@example.com',
            json_encode($data['user'])
        );
    }
}
