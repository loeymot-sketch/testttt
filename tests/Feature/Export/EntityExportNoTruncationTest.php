<?php

/**
 * [ULTRA-LOOP R3 2026-07-07 — exports Excel d'entités tronqués à 10 lignes]
 *
 * Les classes App\Exports\*Export construisent leur collection à partir de
 * Service::list($request) AVEC la MÊME requête que l'UI — laquelle porte
 * paginate=1 / per_page=10. Chaque service renvoie alors un paginator de 10
 * lignes (`paginate==1 ? paginate(per_page) : get('*')`), donc l'export Excel
 * ne contenait que la 1re page (10) au lieu de TOUT (prouvé : ItemExport 10/66,
 * EmployeeExport 10/128).
 *
 * Le jumeau CustomerExport:25 était déjà guéri via
 * `$this->request->merge(['paginate' => 0])`. Cette sentinelle verrouille le
 * même merge sur les 11 exports d'entités restants :
 *   - fonctionnel (2-3 représentatifs) : Item / DiningTable / ItemCategory
 *     renvoient TOUTES les lignes créées, jamais 10.
 *   - structurel : les 11 fichiers Export portent bien le merge paginate=0
 *     (couvre Administrator, Chef, DeliveryBoy, Waiter, Employee, Offer,
 *     PushNotification, Subscriber sans setup RBAC lourd).
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Export;

use App\Exports\DiningTableExport;
use App\Exports\ItemCategoryExport;
use App\Exports\ItemExport;
use App\Http\Requests\PaginateRequest;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\DiningTableService;
use App\Services\ItemCategoryService;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityExportNoTruncationTest extends TestCase
{
    use RefreshDatabase;

    /** The truncating payload the admin UI sends when hitting the export endpoint. */
    private function uiRequest(): PaginateRequest
    {
        return PaginateRequest::create('/', 'GET', ['paginate' => 1, 'per_page' => 10]);
    }

    /** FIX (P3 headline) — ItemExport must contain EVERY catalog item, not the first page of 10. */
    public function test_item_export_returns_all_rows_not_just_the_first_paginated_page(): void
    {
        Item::factory()->count(15)->create();
        $expected = Item::query()->count();
        $this->assertGreaterThan(10, $expected, 'precondition: more than one page of items');

        $rows = (new ItemExport(app(ItemService::class), $this->uiRequest()))->collection();

        $this->assertSame(
            $expected,
            $rows->count(),
            'ItemExport must include the FULL catalogue, not the first paginated page (10).'
        );
    }

    /** FIX — DiningTableExport must contain every table, not 10. */
    public function test_dining_table_export_returns_all_rows(): void
    {
        DiningTable::factory()->count(15)->create();
        $expected = DiningTable::query()->count();
        $this->assertGreaterThan(10, $expected, 'precondition: more than one page of tables');

        $rows = (new DiningTableExport(app(DiningTableService::class), $this->uiRequest()))->collection();

        $this->assertSame(
            $expected,
            $rows->count(),
            'DiningTableExport must include every table, not the first paginated page (10).'
        );
    }

    /** FIX — ItemCategoryExport must contain every category, not 10. */
    public function test_item_category_export_returns_all_rows(): void
    {
        ItemCategory::factory()->count(15)->create();
        $expected = ItemCategory::query()->count();
        $this->assertGreaterThan(10, $expected, 'precondition: more than one page of categories');

        $rows = (new ItemCategoryExport(app(ItemCategoryService::class), $this->uiRequest()))->collection();

        $this->assertSame(
            $expected,
            $rows->count(),
            'ItemCategoryExport must include every category, not the first paginated page (10).'
        );
    }

    /**
     * STRUCTURAL — the same merge(paginate=0) must be present in ALL entity exports.
     * Locks the pattern on the exports whose services require heavier RBAC/relation
     * setup to exercise functionally (Administrator, Chef, DeliveryBoy, Waiter,
     * Employee, Offer, PushNotification, Subscriber), plus the 3 tested above.
     */
    public function test_all_entity_exports_force_full_fetch(): void
    {
        $exports = [
            'AdministratorExport', 'ChefExport', 'DeliveryBoyExport', 'WaiterExport',
            'EmployeeExport', 'DiningTableExport', 'ItemCategoryExport', 'ItemExport',
            'OfferExport', 'PushNotificationExport', 'SubscriberExport',
        ];

        foreach ($exports as $export) {
            $path = app_path("Exports/{$export}.php");
            $this->assertFileExists($path, "Export {$export} must exist.");
            $source = file_get_contents($path);
            $this->assertStringContainsString(
                "\$this->request->merge(['paginate' => 0]);",
                $source,
                "{$export} must force a non-paginated fetch (merge paginate=0) so the Excel export is never truncated to the first page of 10."
            );
        }
    }
}
