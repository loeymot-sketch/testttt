<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.4.9 / POS-GA-F-01]
 *
 * Exercise the admin HTTP surface for fiscal Z reports:
 *  - permission gate enforced on every route;
 *  - open / close happy path returns signed payloads;
 *  - cross-branch show is rejected (403);
 *  - PDF route returns a signed bundle.
 */
class ZReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $manager;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');

        $this->branch = Branch::factory()->create();

        $this->manager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        // Fiscal management is admin/branch-manager only (POS-9.4.12 gives
        // pos-manage-fiscal only to those roles — here we grant it directly).
        $this->manager->givePermissionTo('pos-manage-fiscal');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->operator->assignRole('POS Operator');
    }

    private function apiHeaders(): array
    {
        return ['x-api-key' => config('app.api_key')];
    }

    public function test_permission_required_for_open(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');

        $response->assertStatus(403);
    }

    public function test_permission_required_for_close(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/close');

        $response->assertStatus(403);
    }

    public function test_manager_can_open_and_close(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        $open = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);
        $this->assertSame(1, (int) $open->json('data.sequence_no'));
        $this->assertSame(ZReport::STATUS_OPEN, $open->json('data.status'));

        $close = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);
        $this->assertSame(ZReport::STATUS_CLOSED, $close->json('data.status'));
        $this->assertNotNull($close->json('data.signature'));
    }

    public function test_pdf_route_returns_signed_bundle(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        $open   = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $closed = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $id     = $closed->json('data.id');

        $pdf = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/z-report/' . $id . '/pdf');

        $pdf->assertStatus(200);
        $pdf->assertJsonPath('data.verified', true);
        $this->assertNotNull($pdf->json('data.z_report.signature'));
    }

    public function test_cross_branch_show_is_forbidden(): void
    {
        $other = Branch::factory()->create();
        $z = ZReport::create([
            'branch_id'   => $other->id,
            'sequence_no' => 1,
            'opened_at'   => now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $this->actingAs($this->manager, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/z-report/' . $z->id);

        $response->assertStatus(403);
    }

    public function test_x_report_requires_permission(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report');
        $response->assertStatus(403);
    }

    public function test_x_report_returns_snapshot_for_manager(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report');

        $response->assertStatus(200);
        $response->assertJsonPath('data.branch_id', $this->branch->id);
        $response->assertJsonStructure([
            'data' => ['branch_id', 'generated_at', 'period' => ['from', 'to'], 'totals'],
        ]);
    }

    /**
     * [Wave T R1 F1 P0 2026-05-20] Sentinel — read-only index must NOT 422
     * when an admin (branch_id=0) hits it. LastZReportWidget on /admin/dashboard
     * mounts for POS operators routed through admin login and previously
     * received a silent 422, leaving the cashier with no Z visibility and
     * the dashboard widget stuck on the "no data" empty state.
     *
     * Admins receive a cross-branch list (latest sequence_no first), capped
     * at 100 like the branch-scoped variant. Show / open / close keep 422
     * because they touch fiscal state (signed chain integrity).
     */
    public function test_index_returns_cross_branch_for_admin_without_pinned_branch(): void
    {
        // Two branches, one Z per branch — index should return both, newest first.
        $other = Branch::factory()->create();
        ZReport::create([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subDay(),
            'status'      => ZReport::STATUS_OPEN,
        ]);
        ZReport::create([
            'branch_id'   => $other->id,
            'sequence_no' => 2,
            'opened_at'   => now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $admin = User::factory()->create([
            'branch_id' => 0, // unpinned admin (matches §9 admin bypass)
            'password'  => Hash::make('pwd'),
        ]);
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/z-report');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        // Ordered by sequence_no desc — newest first.
        $this->assertSame(2, (int) $response->json('data.0.sequence_no'));
    }

    /**
     * [Wave T R1 F1 P0 2026-05-20] Branch-pinned staff still see ONLY their
     * own Z reports — the admin relaxation is read-only AND scoped to the
     * "no pinned branch" case. This prevents a future regression where an
     * admin code-path tweak accidentally leaks cross-branch Z to branch
     * staff (BranchScope-equivalent guarantee at the controller layer).
     */
    public function test_index_remains_branch_scoped_for_pinned_user(): void
    {
        $other = Branch::factory()->create();
        ZReport::create([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);
        ZReport::create([
            'branch_id'   => $other->id,
            'sequence_no' => 5,
            'opened_at'   => now(),
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $this->actingAs($this->manager, 'sanctum'); // branch_id = $this->branch->id
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/z-report');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->branch->id, (int) $response->json('data.0.branch_id'));
    }

    /**
     * [Wave T R1 F1 P0 2026-05-20] Mutating routes keep the strict gate:
     * an admin without a pinned branch trying to open a Z still hits 422
     * because the fiscal chain is per-branch and the controller refuses to
     * trust a payload-side branch_id (NF525 §8 invariant).
     */
    public function test_open_still_requires_pinned_branch_for_admin(): void
    {
        $admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');

        $response->assertStatus(422);
    }
}
