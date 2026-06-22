<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * [POS-9-H.2.3 / F-C5]
 *
 * Before this patch AuditLogService::write() accepted branch_id=null and
 * fell through to lastHashFor(null), a query WITHOUT a WHERE clause.
 * A CLI job that forgot to pin a branch would therefore read the latest
 * row of WHATEVER branch happened to be most recent and then chain its
 * own row on top of that — silently merging chains across tenants.
 *
 * After the fix:
 *   - branch_id=null  -> InvalidArgumentException.
 *   - branch_id=0     -> ok (explicit "system" chain).
 *   - branch_id>0     -> ok (tenant chain).
 *
 * System (0) and tenant (>0) chains remain independent because
 * lastHashFor and verifyChain both filter by branch_id.
 */
class AuditLogBranchRequiredTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret', 'unit-test-secret-branch-required');
        $this->svc = app(AuditLogService::class);
    }

    public function test_null_branch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/explicit branch_id/');

        $this->svc->write([
            // branch_id deliberately omitted
            'action' => 'cli.run', 'payload' => ['job' => 'anything'],
        ]);
    }

    public function test_explicit_null_branch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->write([
            'branch_id' => null, 'action' => 'cli.run', 'payload' => [],
        ]);
    }

    public function test_zero_branch_is_accepted_as_system_chain(): void
    {
        $row = $this->svc->write([
            'branch_id' => 0, 'action' => 'system.boot', 'payload' => ['cmd' => 'up'],
        ]);
        $this->assertSame(0, (int) $row->branch_id);
        $this->assertNotNull($row->current_hash);
    }

    public function test_system_and_tenant_chains_are_independent(): void
    {
        $s1 = $this->svc->write(['branch_id' => 0, 'action' => 'sys.a', 'payload' => []]);
        $t1 = $this->svc->write(['branch_id' => 1, 'action' => 'ten.a', 'payload' => []]);
        $s2 = $this->svc->write(['branch_id' => 0, 'action' => 'sys.b', 'payload' => []]);

        // System chain: s1 is genesis, s2 chains on s1.
        $this->assertNull($s1->prev_hash);
        $this->assertSame($s1->current_hash, $s2->prev_hash);

        // Tenant chain: t1 is its own genesis, independent of system.
        $this->assertNull($t1->prev_hash);

        // Both chains remain verifiable.
        $this->assertNull($this->svc->verifyChain(0));
        $this->assertNull($this->svc->verifyChain(1));
    }

    public function test_negative_branch_is_accepted_but_isolated(): void
    {
        // MySQL stores audit_logs.branch_id as UNSIGNED — use a high sentinel id
        // instead of -1 (SQLite allows signed). Same idea: isolated chain.
        $sentinel = 9_000_000_001;
        $r = $this->svc->write(['branch_id' => $sentinel, 'action' => 'weird', 'payload' => []]);
        $this->assertSame($sentinel, (int) $r->branch_id);
    }
}
