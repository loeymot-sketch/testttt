<?php

namespace Tests\Feature\Uber;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Events\OrderStatusChanged;
use App\Jobs\PushUberMenuJob;
use App\Listeners\NotifyUberOrderReady;
use App\Listeners\SyncUberItemAvailability;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Services\Uber\UberClient;
use App\Services\Uber\UberMenuBuilder;
use App\Services\Uber\UberOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [UBER-BASIC-PROD 2026-08-02] Verrouille les capacités exigées par la checklist
 * « Basic Production validation » d'Uber (email Case# 58936938) : menu upload (PUT v2),
 * item out-of-stock (86 sync), mark-order-ready (/v1/delivery), events store.* ack 200
 * (menu_refresh_request → re-push), et mapping direct des IDs "item-<id>" du menu uploadé.
 */
class UberBasicProductionCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shhh-basicprod';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config()->set('uber.webhook_signing_secret', self::SECRET);
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.store_id', 'store-uuid-1');
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

    private function makeCatalog(): array
    {
        $tax = Tax::factory()->create(['tax_rate' => 10]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $empty = ItemCategory::factory()->create(['name' => 'Vide', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create(['name' => 'Suprême', 'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => Status::ACTIVE, 'price' => 7.50]);
        $off = Item::factory()->create(['name' => 'Ancien', 'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => Status::INACTIVE, 'price' => 5.00]);

        return [$cat, $empty, $item, $off];
    }

    /** @test — menu : structure v2 complète, prix en centimes, ids réversibles, inactifs exclus. */
    public function menu_builder_construit_le_payload_v2_depuis_la_ssot(): void
    {
        [$cat, $empty, $item] = $this->makeCatalog();

        $menu = app(UberMenuBuilder::class)->build();

        $this->assertArrayHasKey('menus', $menu);
        $this->assertArrayHasKey('modifier_groups', $menu);
        $this->assertSame(['cat-' . $cat->id], $menu['menus'][0]['category_ids'], 'Catégorie vide exclue.');
        $this->assertCount(7, $menu['menus'][0]['service_availability']);

        $ids = array_column($menu['items'], 'id');
        $this->assertContains('item-' . $item->id, $ids, 'ID réversible "item-<id>".');
        $this->assertCount(1, $menu['items'], 'Item INACTIVE exclu du menu.');
        $this->assertSame(750, $menu['items'][0]['price_info']['price'], 'Prix en CENTIMES (7,50 € → 750).');
        $this->assertSame('Suprême', $menu['items'][0]['title']['translations']['en_us']);
    }

    /** @test — menu : PUT v2 poussé vers le bon endpoint. */
    public function menu_push_appelle_put_menus_v2(): void
    {
        $this->makeCatalog();
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v2/eats/stores/*/menus' => Http::response(['ok' => true], 200),
        ]);

        $ok = app(UberMenuBuilder::class)->push(app(UberClient::class));

        $this->assertTrue($ok);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v2/eats/stores/store-uuid-1/menus')
            && strtoupper($req->method()) === 'PUT'
            && isset($req->data()['menus']));
    }

    /** @test — webhook store.menu_refresh_request : ack 200 + job de re-push dispatché. */
    public function webhook_menu_refresh_ack_200_et_dispatch_le_push(): void
    {
        Bus::fake();

        $this->signedPost(['event_id' => 'evt-mr1', 'event_type' => 'store.menu_refresh_request', 'meta' => ['resource_id' => 'store-uuid-1']])
            ->assertStatus(200)
            ->assertJson(['status' => 'ack_store_event']);

        Bus::assertDispatched(PushUberMenuJob::class);
    }

    /** @test — webhooks store.deprovisioned / store.status.changed : toujours ack 200 (exigence Uber). */
    public function webhooks_store_sont_toujours_acquittes_200(): void
    {
        foreach (['store.deprovisioned', 'store.status.changed', 'store.provisioned'] as $i => $type) {
            $this->signedPost(['event_id' => 'evt-st' . $i, 'event_type' => $type, 'meta' => ['resource_id' => 'store-uuid-1']])
                ->assertStatus(200);
        }
    }

    /** @test — 86 : rupture → suspension de l'item côté Uber ; retour → levée (suspend_until 0). */
    public function rupture_86_suspend_et_reactive_l_item_sur_uber(): void
    {
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v2/eats/stores/*/menus/items/*' => Http::response(['ok' => true], 200),
        ]);

        $listener = app(SyncUberItemAvailability::class);
        $listener->handle(ItemAvailabilityChanged::forBranch(42, 1, false, 'rupture'));
        $listener->handle(ItemAvailabilityChanged::forBranch(42, 1, true, null));

        $sent = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/menus/items/item-42'))->values();
        $this->assertCount(2, $sent, 'Un POST par bascule 86.');
        $this->assertSame(8640000000, $sent[0][0]->data()['suspension_info']['suspension']['suspend_until'], 'Rupture → suspendu (valeur far-future officielle Uber).');
        $this->assertNull($sent[1][0]->data()['suspension_info']['suspension']['suspend_until'], 'Retour → suspension levée (null = disponible, doc officielle).');
    }

    /** @test — ready : PREPARED d'une commande Uber → POST /v1/delivery/order/{id}/ready. */
    public function prepared_signale_ready_a_uber_pour_une_commande_uber_seulement(): void
    {
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/delivery/order/*/ready' => Http::response(['ok' => true], 200),
        ]);

        $uber = new Order();
        $uber->source_surface = 'uber_eats';
        $uber->transaction_id = 'uber:U-READY-1';
        $web = new Order();
        $web->source_surface = 'web';
        $web->transaction_id = 'web:W-1';

        $listener = app(NotifyUberOrderReady::class);
        $listener->handle(new OrderStatusChanged($uber, OrderStatus::PREPARING, OrderStatus::PREPARED));
        $listener->handle(new OrderStatusChanged($uber, OrderStatus::ACCEPT, OrderStatus::PREPARING)); // pas PREPARED → rien
        $listener->handle(new OrderStatusChanged($web, OrderStatus::PREPARING, OrderStatus::PREPARED)); // pas Uber → rien

        $ready = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/v1/delivery/order/'))->values();
        $this->assertCount(1, $ready, 'Un SEUL ready : commande Uber passée PREPARED.');
        $this->assertStringContainsString('/v1/delivery/order/U-READY-1/ready', $ready[0][0]->url());
    }

    /** @test — cancel sortant : CANCELED caisse d'une commande Uber → POST cancel ; écho Uber bloqué. */
    public function annulation_caisse_signale_cancel_a_uber_sans_echo(): void
    {
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/delivery/order/*/cancel' => Http::response(['ok' => true], 200),
        ]);

        $uber = new Order();
        $uber->id = 777;
        $uber->source_surface = 'uber_eats';
        $uber->transaction_id = 'uber:U-CXL-1';

        $listener = app(\App\Listeners\SyncUberOrderCancel::class);

        // 1. Annulation initiée CAISSE → cancel sortant.
        $listener->handle(new OrderStatusChanged($uber, OrderStatus::ACCEPT, OrderStatus::CANCELED));
        // 2. Annulation initiée UBER (marqueur origin posé par cancelFromUber) → PAS d'écho.
        \Illuminate\Support\Facades\Cache::put('uber.cancel_origin.777', true, 600);
        $listener->handle(new OrderStatusChanged($uber, OrderStatus::ACCEPT, OrderStatus::CANCELED));

        $sent = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/cancel'))->values();
        $this->assertCount(1, $sent, 'Un seul cancel sortant (l\'écho Uber est bloqué par le marqueur).');
        $this->assertStringContainsString('/v1/delivery/order/U-CXL-1/cancel', $sent[0][0]->url());
        $reason = $sent[0][0]->data()['cancellation_reason'] ?? null;
        $this->assertIsArray($reason, 'cancellation_reason objet REQUIS par la validation Uber.');
        $this->assertNotContains(strtoupper((string) $reason['type']), ['OTHER', 'UNKNOWN'], 'Jamais OTHER/UNKNOWN par défaut (règle < 10 %).');
    }

    /** @test — mapper : l'ID "item-<id>" du menu uploadé se résout directement (sans carte manuelle). */
    public function mapper_resout_directement_les_ids_du_menu_uploade(): void
    {
        [, , $item] = $this->makeCatalog();

        $mapper = app(UberOrderMapper::class);
        $this->assertSame((int) $item->id, $mapper->resolveItemId('Titre Uber Différent', 'item-' . $item->id));
        $this->assertNull($mapper->resolveItemId('Inconnu 999', 'item-999999'), 'ID fantôme → NULL (placeholder).');
    }
}
