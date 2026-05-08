<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-5 / 5.5] AuditLogService HMAC chain integrity.
 *
 * NF525 / Loi Finance 2018 require a tamper-evident fiscal trail. The
 * production guarantee is documented in
 * `docs/FISCAL_SECRETS.md` and implemented by:
 *   - `App\Services\Fiscal\AuditLogService::write()` — append-only HMAC
 *     chain: each `current_hash = HMAC(prev_hash || canonical(payload))`
 *   - `App\Services\Fiscal\AuditLogService::verifyChain()` — re-walks
 *     the chain row-by-row and returns the id of the first row whose
 *     hash no longer matches.
 *
 * This suite is READ-ONLY over `AuditLogService`: no method is
 * modified, no shape is patched. Tests pin the externally-observable
 * contract so a regression to canonicalisation, secret resolution or
 * the HMAC algorithm fails CI immediately.
 *
 * IMPORTANT — to test the "tampered mid-row" scenario, the immutability
 * triggers (`audit_logs_no_update`, `audit_logs_no_delete`) must be
 * dropped IN THAT TEST ONLY. RefreshDatabase re-runs migrations per
 * test, so triggers are reinstalled before the next test.
 */
class AuditChainIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml seeds FISCAL_AUDIT_SECRET, but defensive in case a
        // future test config drift removes it.
        if ($this->app['config']->get('fiscal.audit_secret') === '' || $this->app['config']->get('fiscal.audit_secret') === null) {
            $this->app['config']->set('fiscal.audit_secret', str_repeat('a', 48));
        }

        $this->service = new AuditLogService();
    }

    /**
     * 100 sequential writes produce a chain that verifies clean.
     * Stress-tests the canonicalisation path + the lock + the unique
     * (branch_id, prev_hash) index together.
     */
    public function test_chain_intact_after_100_sequential_writes(): void
    {
        $branchId = 42;

        for ($i = 0; $i < 100; $i++) {
            $this->service->write([
                'branch_id'   => $branchId,
                'action'      => 'sale.persisted',
                'resource'    => 'order',
                'resource_id' => 1000 + $i,
                'payload'     => [
                    'sequence'    => $i,
                    'total_cents' => 1700 + $i,
                    // Mix in a non-list array to exercise the recursive
                    // ksort path of canonicalise().
                    'meta'        => ['z' => 'last', 'a' => 'first', 'm' => $i],
                ],
            ]);
        }

        $this->assertSame(100, AuditLog::query()->where('branch_id', $branchId)->count());

        $tampered = $this->service->verifyChain($branchId);

        $this->assertNull(
            $tampered,
            'verifyChain must return null on an intact chain. '
            . 'First-tampered id reported: ' . var_export($tampered, true)
        );
    }

    /**
     * Direct DB mutation of a mid-chain row must be detected by
     * verifyChain — even though application code can never reach this
     * state, NF525 requires forensics on raw DB-level tampering.
     *
     * To run this test we drop the immutability triggers temporarily.
     * That's a TEST-ONLY surface, never executed in production.
     */
    public function test_chain_invalidated_when_mid_row_tampered_via_raw_sql(): void
    {
        $branchId = 7;

        for ($i = 0; $i < 5; $i++) {
            $this->service->write([
                'branch_id'   => $branchId,
                'action'      => 'sale.persisted',
                'resource'    => 'order',
                'resource_id' => 5000 + $i,
                'payload'     => ['sequence' => $i, 'amount_cents' => 1000 + $i],
            ]);
        }

        $this->assertNull($this->service->verifyChain($branchId), 'Chain must be intact pre-tamper');

        // Drop the SQLite triggers locally so we can simulate raw-SQL
        // tampering. Triggers are re-installed by the next test's
        // RefreshDatabase migration cycle.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'mysql' || $driver === 'mariadb') {
            try {
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            } catch (\Throwable $e) {
                $this->markTestSkipped('Could not drop immutability triggers on this driver: ' . $e->getMessage());
            }
        } else {
            $this->markTestSkipped('Tamper test requires sqlite/mysql/mariadb. Driver: ' . $driver);
        }

        // Tamper with row #3 of the 5 we wrote — change `amount_cents`.
        $rows     = AuditLog::query()->where('branch_id', $branchId)->orderBy('id')->get();
        $victimId = $rows->get(2)->id;
        $newPayload = json_encode(['sequence' => 2, 'amount_cents' => 99999999]);

        DB::table('audit_logs')->where('id', $victimId)->update(['payload' => $newPayload]);

        $tampered = $this->service->verifyChain($branchId);

        $this->assertSame(
            (int) $victimId,
            $tampered,
            'verifyChain must return the id of the first tampered row'
        );
    }

    /**
     * Branch isolation — tampering with branch B's chain must NOT
     * invalidate branch A's chain. verifyChain(A) returns null even
     * when verifyChain(B) flags a forgery.
     */
    public function test_branch_isolation_one_branch_tamper_does_not_taint_others(): void
    {
        // Build two independent chains of 3 rows each.
        for ($i = 0; $i < 3; $i++) {
            $this->service->write([
                'branch_id'   => 1,
                'action'      => 'sale.persisted',
                'resource_id' => 10 + $i,
                'payload'     => ['branch' => 'A', 'i' => $i],
            ]);
            $this->service->write([
                'branch_id'   => 2,
                'action'      => 'sale.persisted',
                'resource_id' => 20 + $i,
                'payload'     => ['branch' => 'B', 'i' => $i],
            ]);
        }

        $this->assertNull($this->service->verifyChain(1));
        $this->assertNull($this->service->verifyChain(2));

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Tamper test requires sqlite/mysql/mariadb. Driver: ' . $driver);
        }

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Could not drop immutability triggers: ' . $e->getMessage());
        }

        // Tamper with the SECOND branch's middle row.
        $branchBRow = AuditLog::query()->where('branch_id', 2)->orderBy('id')->skip(1)->first();
        DB::table('audit_logs')
            ->where('id', $branchBRow->id)
            ->update(['payload' => json_encode(['branch' => 'B', 'i' => 99999])]);

        $tamperedB = $this->service->verifyChain(2);
        $tamperedA = $this->service->verifyChain(1);

        $this->assertSame((int) $branchBRow->id, $tamperedB, 'verifyChain(B) must flag the tampered row');
        $this->assertNull(
            $tamperedA,
            'verifyChain(A) must remain CLEAN — branch isolation invariant '
            . 'broken if this fails (CLAUDE.md Non-Negotiable Principle #8)'
        );
    }

    /**
     * Hash determinism — `computeHash()` is deterministic over (branch,
     * prev_hash, action, payload). Pinning this ensures Z/X reports
     * (which reuse the same algorithm) stay verifiable across PHP
     * version upgrades.
     */
    public function test_compute_hash_is_deterministic_over_canonical_payload(): void
    {
        $branchId = 5;
        $prevHash = str_repeat('0', 64);
        $action   = 'sale.persisted';

        // Same payload, different insertion order — must yield the same
        // hash (canonical form is recursively ksort'd).
        $payloadA = ['z' => 'value', 'a' => 'value', 'm' => ['c' => 1, 'a' => 2]];
        $payloadB = ['m' => ['a' => 2, 'c' => 1], 'a' => 'value', 'z' => 'value'];

        $hashA = $this->service->computeHash($branchId, $prevHash, $action, $payloadA);
        $hashB = $this->service->computeHash($branchId, $prevHash, $action, $payloadB);

        $this->assertSame($hashA, $hashB, 'computeHash must be insertion-order-insensitive (canonical ksort)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashA, 'hash must be 64-char lower-hex SHA-256');
    }

    /**
     * Different payloads must yield different hashes — sanity check
     * that we never collapsed the chain to "any input → same hash"
     * (the worst-case canonicalisation regression).
     */
    public function test_compute_hash_changes_when_payload_changes(): void
    {
        $branchId = 9;
        $prevHash = str_repeat('0', 64);
        $action   = 'sale.persisted';

        $hash1 = $this->service->computeHash($branchId, $prevHash, $action, ['amount' => 100]);
        $hash2 = $this->service->computeHash($branchId, $prevHash, $action, ['amount' => 101]);

        $this->assertNotSame($hash1, $hash2, 'Different payloads MUST produce different hashes');
    }

    /**
     * The first row of a branch chain has prev_hash = null. A regression
     * to "prev_hash = ''" would silently fork chains across branches
     * (because prevHash || canonical(...) collides for null vs '').
     */
    public function test_first_row_in_chain_has_null_prev_hash(): void
    {
        $row = $this->service->write([
            'branch_id' => 99,
            'action'    => 'sale.persisted',
            'payload'   => ['first' => true],
        ]);

        $this->assertNull(
            $row->prev_hash,
            'First row of a branch chain must have prev_hash = NULL (not empty string)'
        );
        $this->assertNotEmpty($row->current_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row->current_hash);
    }

    /**
     * Subsequent writes in the same branch advance prev_hash exactly to
     * the previous row's current_hash. Forks (two rows with the same
     * prev_hash) are forbidden by the UNIQUE(branch_id, prev_hash) index.
     */
    public function test_subsequent_row_prev_hash_equals_previous_current_hash(): void
    {
        $branchId = 33;
        $first  = $this->service->write([
            'branch_id' => $branchId,
            'action'    => 'sale.persisted',
            'payload'   => ['n' => 1],
        ]);
        $second = $this->service->write([
            'branch_id' => $branchId,
            'action'    => 'sale.persisted',
            'payload'   => ['n' => 2],
        ]);

        $this->assertSame(
            $first->current_hash,
            $second->prev_hash,
            'Each row prev_hash must equal the previous row current_hash'
        );
    }

    /**
     * write() refuses calls with a missing/empty action — the algorithm
     * canonicalises on action, so an empty action would produce
     * unverifiable rows.
     */
    public function test_write_rejects_empty_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->write([
            'branch_id' => 1,
            'action'    => '',
            'payload'   => ['x' => 1],
        ]);
    }

    /**
     * write() refuses null branch_id (system events must explicitly pass
     * branch_id=0 to write to the system chain). Without this guard,
     * a CLI job would read tail-across-all-chains and poison whichever
     * branch happens to be latest.
     */
    public function test_write_rejects_null_branch_id_when_no_user_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->write([
            'action'  => 'cli.job.ran',
            'payload' => ['detail' => 'forgot-to-pin-branch'],
        ]);
    }
}
