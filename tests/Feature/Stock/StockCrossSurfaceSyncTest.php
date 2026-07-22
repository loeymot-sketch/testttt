<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Models\User;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [STOCK MASSIF 2026-07-22] Synchronisation cross-surfaces de la gestion de stock.
 *
 * Mandat owner : « à partir du téléphone, de la caisse, écran cuisine, gestion de
 * stock, admin — aucun problème, bonne synchronisation, pas de doublage ».
 *
 * SSOT écriture : {@see AvailabilityService::toggle} → item_branch_availability →
 * event ItemAvailabilityChanged (outbox + Echo private-branch.{id}). Lectures :
 *  - borne/web : {@see KioskMenuService::build} (+ SimpleItemResource overlay) ;
 *  - admin     : GET /api/admin/stock/catalog-overview ;
 *  - caisse/KDS: GET /api/admin/menu/availability/branch/{id} (snapshot service) ;
 *  - téléphone : /m/api/catalog + /m/api/toggle (PIN, MÊME service).
 *
 * Ce test verrouille : (a) event branch-scoped, (b) projection borne, (c) lecture
 * admin + panel caisse/KDS, (d) anti-doublage (UNIQUE (item_id, branch_id) +
 * idempotence), (e) extras/variations via stock_levels, + les edge cases quota
 * jour (auto-86 out_of_stock) vs rupture manuelle (stock_rupture) : restauration
 * manuelle, release annulation/remboursement, cron de reset.
 */
class StockCrossSurfaceSyncTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->service = app(AvailabilityService::class);
    }

    // -----------------------------------------------------------------
    // (a) Event branch-scoped + isolation branche (pas de doublage cross-branche)
    // -----------------------------------------------------------------

    public function test_toggle_emits_branch_scoped_event_and_touches_single_branch_only(): void
    {
        [$branchA, , $item] = $this->makeCatalog();
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);

        Event::fake([ItemAvailabilityChanged::class]);

        $this->service->toggle((int) $item->id, (int) $branchA->id, false, 'stock_rupture');

        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $e) use ($item, $branchA): bool {
            return $e->itemId === (int) $item->id
                && $e->branchId === (int) $branchA->id
                && $e->isAvailable === false
                && $e->reason === 'stock_rupture'
                && $e->type === 'branch_availability';
        });

        // Une seule ligne écrite, UNIQUEMENT pour la branche ciblée.
        $this->assertSame(1, ItemBranchAvailability::query()->where('item_id', $item->id)->count());
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchB->id,
        ]);
        $this->assertTrue($this->service->isAvailable((int) $item->id, (int) $branchB->id));
    }

    // -----------------------------------------------------------------
    // (b) Projection borne (KioskMenuService::build)
    // -----------------------------------------------------------------

    public function test_kiosk_menu_projection_reflects_rupture_then_restore(): void
    {
        [$branch, , $item] = $this->makeCatalog();

        $before = collect(app(KioskMenuService::class)->build($branch)['items'])->firstWhere('id', $item->id);
        $this->assertNotNull($before, 'Item absent de la projection borne.');
        $this->assertTrue($before['is_available']);

        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');

        $during = collect(app(KioskMenuService::class)->build($branch)['items'])->firstWhere('id', $item->id);
        $this->assertFalse($during['is_available']);
        $this->assertSame('stock_rupture', $during['unavailable_reason']);

        $this->service->toggle((int) $item->id, (int) $branch->id, true, null);

        $after = collect(app(KioskMenuService::class)->build($branch)['items'])->firstWhere('id', $item->id);
        $this->assertTrue($after['is_available']);
        $this->assertNull($after['unavailable_reason']);
    }

    // -----------------------------------------------------------------
    // (c) Lecture admin (catalog-overview) + panel caisse/KDS (snapshot branche)
    // -----------------------------------------------------------------

    public function test_admin_catalog_overview_and_pos_kds_snapshot_reflect_rupture(): void
    {
        [$branch, , $item] = $this->makeCatalog();
        $this->actAsAdminWithItemsShow();

        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');

        // Surface admin « Gestion Produits & Stock ».
        $overview = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $branch->id)
            ->assertOk()
            ->json();
        $row = collect($overview['categories'])
            ->flatMap(fn (array $cat): array => $cat['items'])
            ->firstWhere('id', (int) $item->id);
        $this->assertNotNull($row, 'Item absent du catalog-overview admin.');
        $this->assertFalse($row['is_available']);
        $this->assertSame('stock_rupture', $row['reason']);

        // Source du panel caisse/KDS (AvailabilityTogglePanel) : snapshot branche.
        $snapshot = $this->service->getBranchAvailabilitySnapshot((int) $branch->id);
        $this->assertContains((int) $item->id, array_column($snapshot['items'], 'item_id'));

        // Restauration → les deux lectures redeviennent propres.
        $this->service->toggle((int) $item->id, (int) $branch->id, true, null);

        $overviewAfter = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $branch->id)->json();
        $rowAfter = collect($overviewAfter['categories'])
            ->flatMap(fn (array $cat): array => $cat['items'])
            ->firstWhere('id', (int) $item->id);
        $this->assertTrue($rowAfter['is_available']);
        $this->assertNull($rowAfter['reason']);
        $this->assertSame([], $this->service->getBranchAvailabilitySnapshot((int) $branch->id)['items']);
    }

    // -----------------------------------------------------------------
    // Téléphone (/m) → SSOT → borne : miroir local de la preuve live VPS
    // -----------------------------------------------------------------

    public function test_mobile_toggle_endpoint_flows_to_kiosk_menu_and_shopping_list(): void
    {
        [$branch, , $item] = $this->makeCatalog();
        config(['mobile_stock.pin' => '2580']);

        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertOk()->assertJson(['unlocked' => true]);

        $this->postJson('/m/api/toggle', ['item_id' => (int) $item->id, 'is_available' => false])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'item_id' => (int) $item->id,
                'branch_id' => (int) $branch->id,
                'is_available' => false,
                'unavailable_reason' => 'stock_rupture',
            ]);

        // Liste « À acheter » du téléphone.
        $catalog = $this->getJson('/m/api/catalog')->assertOk()->json();
        $this->assertContains((int) $item->id, array_column($catalog['shopping'], 'id'));

        // Propagation borne (même lecture que /api/frontend/menu).
        $projected = collect(app(KioskMenuService::class)->build($branch)['items'])->firstWhere('id', $item->id);
        $this->assertFalse($projected['is_available']);
        $this->assertSame('stock_rupture', $projected['unavailable_reason']);

        // Restauration depuis le téléphone → borne dispo + shopping vide.
        $this->postJson('/m/api/toggle', ['item_id' => (int) $item->id, 'is_available' => true])
            ->assertOk()->assertJson(['is_available' => true]);
        $this->assertSame([], $this->getJson('/m/api/catalog')->json()['shopping']);
        $restored = collect(app(KioskMenuService::class)->build($branch)['items'])->firstWhere('id', $item->id);
        $this->assertTrue($restored['is_available']);
    }

    // -----------------------------------------------------------------
    // (d) Anti-doublage : double-toggle rapide + contrainte UNIQUE DB
    // -----------------------------------------------------------------

    public function test_rapid_double_toggle_creates_single_row_and_is_idempotent(): void
    {
        [$branch, , $item] = $this->makeCatalog();

        Event::fake([ItemAvailabilityChanged::class]);

        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');
        // Double-tap rapide même état + même raison → no-op silencieux (pas de 2e event).
        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);

        $this->service->toggle((int) $item->id, (int) $branch->id, true, null);
        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 3);

        // JAMAIS plus d'une ligne pour (item, branch), quel que soit le nombre de toggles.
        $this->assertSame(
            1,
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)
                ->where('branch_id', $branch->id)
                ->count()
        );
    }

    public function test_db_unique_constraint_rejects_duplicate_availability_row(): void
    {
        [$branch, , $item] = $this->makeCatalog();

        $this->service->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');

        // Filet DB : la contrainte UNIQUE (item_id, branch_id) — migration
        // 2026_04_15_230100 l.26 — rejette toute insertion parallèle qui aurait
        // contourné le lockForUpdate du service.
        $caught = false;
        try {
            ItemBranchAvailability::query()->create([
                'item_id' => (int) $item->id,
                'branch_id' => (int) $branch->id,
                'is_available' => true,
                'daily_consumed_qty' => 0,
                'daily_reset_at' => now()->toDateString(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'La contrainte UNIQUE (item_id, branch_id) doit rejeter le doublon.');
        $this->assertSame(
            1,
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)
                ->where('branch_id', $branch->id)
                ->count()
        );
    }

    // -----------------------------------------------------------------
    // (e) Extras + variations (stock_levels polymorphique) : même discipline
    // -----------------------------------------------------------------

    public function test_extra_and_variation_toggles_single_stock_level_row_and_events(): void
    {
        [$branch, , $item] = $this->makeCatalog();

        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'group_label' => 'supplements',
            'is_available' => true,
        ]);
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Grand format',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        Event::fake([ItemExtraAvailabilityChanged::class, ItemVariationAvailabilityChanged::class]);

        // Double-toggle rapide extra → une seule ligne stock_levels, un seul event.
        $this->service->toggleExtra((int) $extra->id, (int) $branch->id, false, 'stock_rupture');
        $this->service->toggleExtra((int) $extra->id, (int) $branch->id, false, 'stock_rupture');
        Event::assertDispatchedTimes(ItemExtraAvailabilityChanged::class, 1);
        $this->assertSame(1, StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('stockable_type', ItemExtra::class)
            ->where('stockable_id', $extra->id)
            ->count());
        $this->assertFalse($this->service->isExtraAvailable((int) $extra->id, (int) $branch->id));

        // Variation : même contrat.
        $this->service->toggleVariation((int) $variation->id, (int) $branch->id, false, 'stock_rupture');
        $this->service->toggleVariation((int) $variation->id, (int) $branch->id, false, 'stock_rupture');
        Event::assertDispatchedTimes(ItemVariationAvailabilityChanged::class, 1);
        $this->assertSame(1, StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('stockable_type', ItemVariation::class)
            ->where('stockable_id', $variation->id)
            ->count());
        $this->assertFalse($this->service->isVariationAvailable((int) $variation->id, (int) $branch->id));

        // Snapshot panel caisse/KDS voit les deux ruptures.
        $snapshot = $this->service->getBranchAvailabilitySnapshot((int) $branch->id);
        $this->assertContains((int) $extra->id, array_column($snapshot['extras'], 'extra_id'));
        $this->assertContains((int) $variation->id, array_column($snapshot['variations'], 'variation_id'));

        // Réactivation flag-only → row supprimé (règle V1 « absent = disponible »), event émis.
        $this->service->toggleExtra((int) $extra->id, (int) $branch->id, true, null);
        $this->service->toggleVariation((int) $variation->id, (int) $branch->id, true, null);
        Event::assertDispatchedTimes(ItemExtraAvailabilityChanged::class, 2);
        Event::assertDispatchedTimes(ItemVariationAvailabilityChanged::class, 2);
        $this->assertTrue($this->service->isExtraAvailable((int) $extra->id, (int) $branch->id));
        $this->assertTrue($this->service->isVariationAvailable((int) $variation->id, (int) $branch->id));
        $this->assertSame(0, StockLevel::query()->where('branch_id', $branch->id)->count());
    }

    // -----------------------------------------------------------------
    // Edge — quota jour (auto-86 out_of_stock) vs restauration MANUELLE
    // -----------------------------------------------------------------

    public function test_quota_auto_86_then_manual_restore_is_re_ruptured_by_next_order(): void
    {
        [$branch, , $item] = $this->makeCatalog();

        // Quota jour = 2. Une commande de 2 → auto-86 (out_of_stock).
        $this->service->setMaxDailyQty((int) $item->id, (int) $branch->id, 2);
        $this->service->decrementForOrder($this->makeOrderWithLine($branch, $item, 2));

        $row = $this->availabilityRow($item, $branch);
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        $this->assertSame(2, (int) $row->daily_consumed_qty);

        // Restauration MANUELLE (toggle dispo) : l'item redevient vendable MAIS le
        // compteur quota N'EST PAS remis à zéro (comportement observé).
        $this->service->toggle((int) $item->id, (int) $branch->id, true, null);
        $row = $this->availabilityRow($item, $branch);
        $this->assertTrue((bool) $row->is_available);
        $this->assertSame(2, (int) $row->daily_consumed_qty, 'La restauration manuelle ne crédite pas le quota.');

        // Conséquence observée : la PROCHAINE commande contenant l'item le re-86
        // immédiatement (compteur toujours >= max). La vraie levée du 86 quota
        // passe par setMaxDailyQty (relever/supprimer le plafond).
        $this->service->decrementForOrder($this->makeOrderWithLine($branch, $item, 1));
        $row = $this->availabilityRow($item, $branch);
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);

        // setMaxDailyQty(null) = plafond levé → restauration auto immédiate.
        $this->service->setMaxDailyQty((int) $item->id, (int) $branch->id, null);
        $row = $this->availabilityRow($item, $branch);
        $this->assertTrue((bool) $row->is_available);
        $this->assertNull($row->unavailable_reason);
    }

    // -----------------------------------------------------------------
    // Edge — release annulation/remboursement : crédite le quota, ne lève
    // JAMAIS une rupture manuelle
    // -----------------------------------------------------------------

    public function test_release_credits_quota_and_restores_only_auto_86(): void
    {
        [$branch, $category, $itemQ] = $this->makeCatalog();
        $itemM = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $itemQ->tax_id,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        // itemQ : quota 2, commandé 2 → auto-86. itemM : commandé 1 puis 86 MANUEL.
        $this->service->setMaxDailyQty((int) $itemQ->id, (int) $branch->id, 2);
        $this->service->setMaxDailyQty((int) $itemM->id, (int) $branch->id, 5);
        $order = $this->makeOrderWithLine($branch, $itemQ, 2);
        $lineM = OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $itemM->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);
        $order->unsetRelation('orderItems');
        $this->service->decrementForOrder($order);
        $this->service->toggle((int) $itemM->id, (int) $branch->id, false, 'stock_rupture');

        $this->assertSame('out_of_stock', $this->availabilityRow($itemQ, $branch)->unavailable_reason);
        $this->assertSame('stock_rupture', $this->availabilityRow($itemM, $branch)->unavailable_reason);

        $lineQ = OrderItem::query()->where('order_id', $order->id)->where('item_id', $itemQ->id)->firstOrFail();

        Event::fake([ItemAvailabilityChanged::class]);

        // Annulation totale → release des deux lignes.
        $this->service->releaseForOrderItems([
            ['order_item_id' => (int) $lineQ->id, 'item_id' => (int) $itemQ->id, 'branch_id' => (int) $branch->id, 'qty' => 2],
            ['order_item_id' => (int) $lineM->id, 'item_id' => (int) $itemM->id, 'branch_id' => (int) $branch->id, 'qty' => 1],
        ]);

        // itemQ (86 QUOTA) : compteur crédité + remis en vente + event flip.
        $rowQ = $this->availabilityRow($itemQ, $branch);
        $this->assertSame(0, (int) $rowQ->daily_consumed_qty);
        $this->assertTrue((bool) $rowQ->is_available);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $e) use ($itemQ, $branch): bool {
            return $e->itemId === (int) $itemQ->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === true
                && $e->reason === 'released_after_cancel_or_refund';
        });

        // itemM (86 MANUEL) : compteur crédité MAIS reste en rupture (garde stock_rupture).
        $rowM = $this->availabilityRow($itemM, $branch);
        $this->assertSame(0, (int) $rowM->daily_consumed_qty);
        $this->assertFalse((bool) $rowM->is_available);
        $this->assertSame('stock_rupture', $rowM->unavailable_reason);

        // Double-release (event rejoué / cancel-puis-refund) : ledger released_qty
        // sature → AUCUN double crédit (anti-doublage temporel).
        $this->service->releaseForOrderItems([
            ['order_item_id' => (int) $lineQ->id, 'item_id' => (int) $itemQ->id, 'branch_id' => (int) $branch->id, 'qty' => 2],
        ]);
        $this->assertSame(0, (int) $this->availabilityRow($itemQ, $branch)->daily_consumed_qty);
        $this->assertSame(2, (int) OrderItem::query()->whereKey($lineQ->id)->value('released_qty'));
    }

    // -----------------------------------------------------------------
    // Edge — cron de reset quotidien : ré-active UNIQUEMENT les 86 quota,
    // préserve les ruptures manuelles. Observation : AUCUN event émis.
    // -----------------------------------------------------------------

    public function test_reset_stale_quota_cron_reenables_quota_86_preserves_manual_and_emits_no_event(): void
    {
        [$branch, $category, $itemQ] = $this->makeCatalog();
        $itemM = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $itemQ->tax_id,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);
        $yesterday = now()->subDay()->toDateString();

        ItemBranchAvailability::query()->create([
            'item_id' => $itemQ->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now()->subDay(),
            'daily_consumed_qty' => 3,
            'max_daily_qty' => 3,
            'daily_reset_at' => $yesterday,
        ]);
        ItemBranchAvailability::query()->create([
            'item_id' => $itemM->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'unavailable_since' => now()->subDay(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => $yesterday,
        ]);

        Event::fake([ItemAvailabilityChanged::class]);

        $this->artisan('foodking:availability:reset-stale-quota')->assertSuccessful();

        // 86 QUOTA d'hier → ré-activé + compteur remis à zéro.
        $rowQ = $this->availabilityRow($itemQ, $branch);
        $this->assertTrue((bool) $rowQ->is_available);
        $this->assertNull($rowQ->unavailable_reason);
        $this->assertSame(0, (int) $rowQ->daily_consumed_qty);
        $this->assertSame(now()->toDateString(), \Carbon\Carbon::parse((string) $rowQ->daily_reset_at)->toDateString());

        // Rupture MANUELLE d'hier → PRÉSERVÉE (compteur resetté seulement).
        $rowM = $this->availabilityRow($itemM, $branch);
        $this->assertFalse((bool) $rowM->is_available);
        $this->assertSame('stock_rupture', $rowM->unavailable_reason);

        // Comportement OBSERVÉ (documenté, pas exigé) : le cron met à jour la DB
        // en direct (DB::table) sans dispatcher ItemAvailabilityChanged → pas
        // d'invalidation cache borne ni bump snapshot à 00:05 (rattrapé par le
        // TTL kiosk.menu_cache_ttl 60 s). Si ce test casse un jour parce que le
        // cron ÉMET l'event, c'est une AMÉLIORATION de la synchro : mettre à
        // jour cette assertion en conséquence.
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Branch, 1: ItemCategory, 2: Item}
     */
    private function makeCatalog(): array
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        return [$branch, $category, $item];
    }

    private function makeOrderWithLine(Branch $branch, Item $item, int $qty): Order
    {
        $order = Order::factory()->create(['branch_id' => $branch->id]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => $qty,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10 * $qty,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        return $order->fresh();
    }

    private function availabilityRow(Item $item, Branch $branch): ItemBranchAvailability
    {
        return ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();
    }

    private function actAsAdminWithItemsShow(): User
    {
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_show', 'guard_name' => 'sanctum']);
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_show');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }
}
