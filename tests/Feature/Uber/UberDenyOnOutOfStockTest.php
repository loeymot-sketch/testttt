<?php

namespace Tests\Feature\Uber;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Menu\AvailabilityService;
use App\Models\Order;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [AUDIT-5SYS 2026-08-12 P2] `config('uber.deny_on_out_of_stock')` était documenté comme une
 * décision métier explicite ("refuser la commande si un produit est en rupture") mais jamais lu
 * nulle part dans le code — l'interrupteur restait inerte même basculé à true, et
 * `UberClient::denyOrder()` (endpoint deny_pos_order) n'était jamais appelé. Ce test verrouille
 * le câblage réel.
 */
class UberDenyOnOutOfStockTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shhh-deny';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config()->set('uber.webhook_signing_secret', self::SECRET);
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.auto_accept', false);
        config()->set('uber.branch_id', 1);
        \App\Models\Branch::factory()->create(['id' => 1]);
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/uber', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function itemDetail(int $itemId, string $title): array
    {
        return [
            'display_id' => 'DENY1',
            'eater' => ['first_name' => 'Client'],
            'payment' => ['charges' => ['total' => ['amount' => 1000]]],
            'cart' => ['items' => [[
                'title' => $title,
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => 1000], 'total_price' => ['amount' => 1000]],
            ]]],
        ];
    }

    /** @test */
    public function interrupteur_actif_refuse_la_commande_et_appelle_deny_pos_order_si_produit_en_rupture(): void
    {
        config()->set('uber.deny_on_out_of_stock', true);

        $tax = Tax::factory()->create(['tax_rate' => 0]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => \App\Enums\Status::ACTIVE]);
        $item = Item::factory()->create(['name' => 'Tacos M', 'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => \App\Enums\Status::ACTIVE, 'price' => 10.00]);
        app(AvailabilityService::class)->toggle((int) $item->id, 1, false, 'stock_rupture');

        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/eats/orders/*/deny_pos_order' => Http::response(['ok' => true], 200),
            'api.uber.com/v1/eats/orders/*' => Http::response($this->itemDetail((int) $item->id, 'Tacos M'), 200),
        ]);

        $this->signedPost(['event_id' => 'evt-deny', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-DENY']])
            ->assertStatus(200);

        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-DENY')->count(),
            'Rupture + interrupteur actif → aucune commande interne ne doit être créée.'
        );

        $denyCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'deny_pos_order'))->count();
        $this->assertSame(1, $denyCalls, 'Le POS doit notifier Uber du refus (deny_pos_order), sinon Uber croit la commande silencieusement ignorée.');
    }

    /** @test */
    public function interrupteur_inactif_par_defaut_continue_a_accepter_malgre_la_rupture(): void
    {
        // Pas de config()->set : défaut = false (comportement historique préservé).
        $tax = Tax::factory()->create(['tax_rate' => 0]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => \App\Enums\Status::ACTIVE]);
        $item = Item::factory()->create(['name' => 'Tacos M', 'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => \App\Enums\Status::ACTIVE, 'price' => 10.00]);
        app(AvailabilityService::class)->toggle((int) $item->id, 1, false, 'stock_rupture');

        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/eats/orders/*' => Http::response($this->itemDetail((int) $item->id, 'Tacos M'), 200),
        ]);

        $this->signedPost(['event_id' => 'evt-nodeny', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-NODENY']])
            ->assertStatus(200);

        $this->assertSame(
            1,
            Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-NODENY')->count(),
            'Comportement historique préservé : par défaut on n\'exclut pas une commande Uber payée pour rupture.'
        );
    }
}
