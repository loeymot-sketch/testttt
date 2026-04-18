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
}
