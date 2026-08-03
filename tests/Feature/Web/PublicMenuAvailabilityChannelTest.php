<?php

namespace Tests\Feature\Web;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\SimpleItemResource;
use App\Listeners\PersistItemAvailabilityChangedToOutbox;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WEB-86-SYNC 2026-07-19] Le SITE WEB (client, non authentifié) doit refléter une rupture
 * (86) posée depuis la gestion (caisse/KDS/admin) en quasi-temps-réel, comme la borne.
 *
 * La borne reçoit le 86 sur le canal PRIVÉ staff `private-branch.{id}` (auth-gated,
 * routes/channels.php) — le web public NE PEUT PAS s'y abonner. On vérifie ici les DEUX
 * mécanismes qui rendent le web synchro :
 *   1. BONUS INSTANTANÉ — l'outbox diffuse AUSSI sur un canal PUBLIC `public-menu.{id}`
 *      (sans auth, payload PII-free) que le web peut écouter (Echo.channel).
 *   2. SOCLE FIABLE (polling) — le GET PUBLIC /api/frontend/item?branch_id={id} renvoie
 *      déjà `is_available` branch-aware (global + override ItemBranchAvailability).
 */
class PublicMenuAvailabilityChannelTest extends TestCase
{
    use RefreshDatabase;

    private function persist(ItemAvailabilityChanged $event): void
    {
        (new PersistItemAvailabilityChangedToOutbox())->handle($event);
    }

    private function makeItem(string $name, bool $globallyAvailable = true): Item
    {
        $cat = ItemCategory::create([
            'name' => 'Burgers', 'slug' => 'burgers-'.uniqid(), 'sort' => 1, 'status' => Status::ACTIVE,
        ]);
        $tax = Tax::factory()->create();

        return Item::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'item_type' => 1,
            'price' => 8.90,
            'is_available' => $globallyAvailable,
            'status' => Status::ACTIVE,
            'order' => 1,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. BONUS INSTANTANÉ — canal public + payload PII-free
    // ---------------------------------------------------------------------

    public function test_branch_86_fans_out_to_private_staff_and_public_web_channel_pii_free(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Le Cayenne', 'city' => 'Hénin', 'state' => 'HDF',
            'zip_code' => '62110', 'address' => '437 Gruyelle', 'status' => Status::ACTIVE,
        ]);

        $this->persist(ItemAvailabilityChanged::forBranch(
            itemId: 4242, branchId: $branch->id, isAvailable: false, reason: 'rupture', price: 9.90
        ));

        $row = DomainEvent::query()->latest('id')->firstOrFail();
        $channels = json_decode($row->channel, true);

        // Le canal staff privé reste présent (inchangé — la borne continue de recevoir).
        $this->assertContains('private-branch.'.$branch->id, $channels,
            'Le canal STAFF privé doit rester présent (borne/POS/KDS inchangés).');
        // Le nouveau canal public sans auth pour le web.
        $this->assertContains('public-menu.'.$branch->id, $channels,
            'Un canal PUBLIC sans auth (public-menu.{id}) doit être ajouté pour le web client.');

        $this->assertSame('ItemAvailabilityChanged', $row->broadcast_as);
        $this->assertSame(EventType::MENU_ITEM_AVAILABILITY_CHANGED, $row->event_type);

        // Contrat PII-free : uniquement des faits catalogue publics — AUCUNE donnée client.
        $this->assertEqualsCanonicalizing(
            ['item_id', 'status', 'price', 'type', 'is_available', 'branch_id', 'reason'],
            array_keys($row->payload),
            'Le payload public ne doit contenir que le contrat catalogue PII-free.'
        );
        $this->assertSame(4242, $row->payload['item_id']);
        $this->assertFalse($row->payload['is_available']);
        $this->assertSame('rupture', $row->payload['reason']);
        $this->assertSame($branch->id, $row->payload['branch_id']);
    }

    public function test_global_item_change_fans_out_public_channel_for_each_active_branch(): void
    {
        $b1 = Branch::forceCreate([
            'name' => 'B1', 'city' => 'A', 'state' => 'X', 'zip_code' => '1', 'address' => 'a', 'status' => Status::ACTIVE,
        ]);
        // status legacy 1 : accepté par le fan-out (parité PersistCatalogChanged).
        $b2 = Branch::forceCreate([
            'name' => 'B2', 'city' => 'B', 'state' => 'Y', 'zip_code' => '2', 'address' => 'b', 'status' => 1,
        ]);

        $item = $this->makeItem('Big Burger');
        $this->persist(ItemAvailabilityChanged::fromItem($item->fresh(), 'status'));

        $row = DomainEvent::query()->latest('id')->firstOrFail();
        $channels = json_decode($row->channel, true);

        foreach ([$b1, $b2] as $b) {
            $this->assertContains('private-branch.'.$b->id, $channels);
            $this->assertContains('public-menu.'.$b->id, $channels,
                "Le canal public web doit couvrir chaque branche active (branche {$b->id}).");
        }
    }

    // ---------------------------------------------------------------------
    // 2. SOCLE FIABLE — le GET public /api/frontend/item renvoie is_available branch-aware
    // ---------------------------------------------------------------------

    public function test_frontend_item_list_payload_reflects_branch_86_for_web_polling(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Le Cayenne', 'city' => 'Hénin', 'state' => 'HDF',
            'zip_code' => '62110', 'address' => '437 Gruyelle', 'status' => Status::ACTIVE,
        ]);

        $available = $this->makeItem('Menu Cheese');
        $ruptured  = $this->makeItem('Menu Fish');

        // 86 PAR BRANCHE (posé par le dashboard rupture / caisse / KDS).
        ItemBranchAvailability::create([
            'branch_id' => $branch->id,
            'item_id' => $ruptured->id,
            'is_available' => false,
            'unavailable_reason' => 'Rupture frigo',
            'daily_reset_at' => now()->toDateString(),
        ]);

        $request = new PaginateRequest();
        $request->merge(['branch_id' => $branch->id]);

        $result = app(ItemService::class)->simpleList($request);
        $payload = SimpleItemResource::collection($result)->toArray(request());

        $byId = collect($payload)->keyBy('id');

        $this->assertFalse($byId[$ruptured->id]['is_available'],
            'Le web (polling /api/frontend/item?branch_id) doit voir is_available=false pour l\'item 86 par branche.');
        $this->assertSame('Rupture frigo', $byId[$ruptured->id]['availability_reason'],
            'Le motif de rupture doit être exposé pour l\'affichage « épuisé ».');
        $this->assertTrue($byId[$available->id]['is_available'],
            'Les autres items restent disponibles.');
    }

    public function test_frontend_item_list_endpoint_http_reflects_branch_86(): void
    {
        config(['app.api_key' => '123456']);
        $this->withoutMiddleware();

        $branch = Branch::forceCreate([
            'name' => 'Le Cayenne', 'city' => 'Hénin', 'state' => 'HDF',
            'zip_code' => '62110', 'address' => '437 Gruyelle', 'status' => Status::ACTIVE,
        ]);
        $ruptured = $this->makeItem('Menu Terminator');
        ItemBranchAvailability::create([
            'branch_id' => $branch->id,
            'item_id' => $ruptured->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'daily_reset_at' => now()->toDateString(),
        ]);

        $res = $this->getJson('/api/frontend/item?branch_id='.$branch->id);
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $ruptured->id);
        $this->assertNotNull($row, 'L\'item doit apparaître dans le catalogue public.');
        $this->assertFalse($row['is_available'], 'L\'endpoint HTTP polling doit renvoyer is_available=false pour le 86.');
    }
}
