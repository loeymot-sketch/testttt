<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [TRAP-1 / HIST-04 heal 2026-06-04] Regression coverage for the Historique
 * "En ligne" filter SILENTLY DROPPING legacy online orders.
 *
 * DEFECT (DB-proven, deep-review): the badge labels a NULL-`source_surface`
 * order "En ligne" via the legacy `source` fallback
 * (HistoriqueListComponent.vue:304-311 — source WEB|APP → "En ligne"), but the
 * filter sends `source_surface='web'`, applied by OrderService::applyOrderFilter
 * as `LIKE '%web%'`, which NEVER matches NULL. → ~66 badged-online orders
 * vanished under the "En ligne" filter.
 *
 * FIX (OrderService::list filter loop): when the online sentinel value
 * (web/app/mobile) is sent, expand the predicate to mirror the badge exactly —
 * any web/app/mobile surface, OR a NULL surface whose legacy `source` is
 * WEB/APP. kiosk/pos surfaces and NULL+non-online rows stay excluded.
 *
 * This test is the missing case the sibling OrderHistoryOnlineFilterAppCoverage
 * test explicitly did NOT cover (it only ever seeds explicit-surface rows): the
 * NULL `source_surface` + legacy `source` online row.
 */
class OrderHistoryOnlineFilterLegacyNullSourceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // branch_id=0 admin bypasses BranchScope → sees every origin.
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
    }

    /**
     * Seed a row with explicit control over source_surface (string|null) and the
     * legacy source enum (int|null). The factory leaves both NULL by default, so
     * the legacy-online case (NULL surface + source=WEB) is reproducible here.
     */
    private function makeOrder(?string $sourceSurface, ?int $source, int $orderType, int $paymentStatus): Order
    {
        return Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'order_type'     => $orderType,
            'source_surface' => $sourceSurface,
            'source'         => $source,
            'payment_status' => $paymentStatus,
            'total'          => 10.00,
        ]);
    }

    /**
     * THE 66-ROW CASE. A legacy online order with source_surface=NULL and the
     * legacy source=WEB is badged "En ligne" by the UI. Before the fix the
     * "En ligne" filter (source_surface=web → LIKE '%web%') NEVER matched NULL,
     * so this order disappeared. After the fix it IS returned. We also assert a
     * modern web-surface order still matches, and that non-online origins
     * (kiosk surface / pos surface / NULL+POS source) are NOT swept in.
     */
    public function test_online_filter_includes_legacy_null_source_online_orders(): void
    {
        // The legacy/online order: NULL surface, legacy source=WEB → badged "En ligne".
        $legacyWebNull = $this->makeOrder(null, Source::WEB, OrderType::TAKEAWAY, PaymentStatus::PAID);
        // A legacy app order: NULL surface, legacy source=APP → also badged "En ligne".
        $legacyAppNull = $this->makeOrder(null, Source::APP, OrderType::TAKEAWAY, PaymentStatus::PAID);
        // A modern online order with the surface tag set → must still match.
        $modernWeb = $this->makeOrder('web', null, OrderType::TAKEAWAY, PaymentStatus::PAID);

        // Non-online origins that must NOT leak into the En-ligne filter:
        $kiosk    = $this->makeOrder('kiosk', null, OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $pos      = $this->makeOrder('pos', null, OrderType::POS, PaymentStatus::PAID);
        // A NULL-surface legacy POS order: badge calls it Caisse, not En ligne → excluded.
        $legacyPosNull = $this->makeOrder(null, Source::POS, OrderType::POS, PaymentStatus::PAID);

        $this->actingAs($this->admin, 'sanctum');

        // 'online' UI selection → backend filter source_surface=web (HistoriqueListComponent.vue:337).
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=web');
        $res->assertOk();

        $returnedIds = collect($res->json('data'))->pluck('id')->all();

        // HARD: the legacy NULL-source online orders now APPEAR (the 66-row fix).
        $this->assertContains($legacyWebNull->id, $returnedIds, 'Legacy online order (NULL surface, source=WEB) must appear in the "En ligne" filter');
        $this->assertContains($legacyAppNull->id, $returnedIds, 'Legacy online order (NULL surface, source=APP) must appear in the "En ligne" filter');

        // HARD: the modern web-surface order still matches.
        $this->assertContains($modernWeb->id, $returnedIds, 'Modern web-surface order must still match the "En ligne" filter');

        // HARD: non-online origins are NOT swept in.
        $this->assertNotContains($kiosk->id, $returnedIds, 'Kiosk order must NOT appear in the online filter');
        $this->assertNotContains($pos->id, $returnedIds, 'POS order must NOT appear in the online filter');
        $this->assertNotContains($legacyPosNull->id, $returnedIds, 'Legacy POS order (NULL surface, source=POS) must NOT appear in the online filter');

        // Count-precise: exactly the three online rows, nothing more.
        sort($returnedIds);
        $expected = [$legacyWebNull->id, $legacyAppNull->id, $modernWeb->id];
        sort($expected);
        $this->assertSame($expected, $returnedIds, 'Online filter returns exactly the badge-online set');
    }
}
