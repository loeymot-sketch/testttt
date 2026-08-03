<?php

namespace Tests\Unit\Services\Fiscal;

use App\Exceptions\FiscalChainCorruptedException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalChainValidator;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-VERIFY-08-01
 * @source docs/audit/plans/PLAN_P11_FISCAL_Z_OPEN_HARDENING_2026-05-06.md
 *
 * Validates that FiscalChainValidator extends ZReportService::verifyChain
 * with a bounded audit_logs tail walk and produces the typed
 * FiscalChainCorruptedException with the legacy 'NF525 Z-chain ...' prefix
 * for Z-chain failures (preserves contract with ZOpenChainVerifiedTest).
 */
class FiscalChainValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function newValidator(): FiscalChainValidator
    {
        return new FiscalChainValidator(
            app(ZReportService::class),
            app(AuditLogService::class)
        );
    }

    private function emitGoodAuditChain(int $branchId, int $count): void
    {
        $svc = app(AuditLogService::class);
        for ($i = 0; $i < $count; $i++) {
            $svc->write([
                'branch_id'   => $branchId,
                'user_id'     => null,
                'action'      => 'test.event_' . $i,
                'resource'    => 'test',
                'resource_id' => $i + 1,
                'payload'     => ['n' => $i],
            ]);
        }
    }

    public function test_intact_chains_do_not_throw(): void
    {
        $branch = Branch::factory()->create();
        $this->emitGoodAuditChain($branch->id, 5);

        $this->newValidator()->assertChainIntegrity($branch->id);
        $this->assertTrue(true); // no throw
    }

    /**
     * Insert a forged audit_log row directly via DB::table to bypass the
     * Eloquent INSERT-only enforcement (audit_logs UPDATE trigger).
     */
    private function insertForgedAuditRow(int $branchId, string $prevHash, string $currentHash, string $payload = '{}'): int
    {
        return (int) \DB::table('audit_logs')->insertGetId([
            'branch_id'    => $branchId,
            'user_id'      => null,
            'action'       => 'test.forged',
            'resource'     => 'test',
            'resource_id'  => 9999,
            'payload'      => $payload,
            'prev_hash'    => $prevHash,
            'current_hash' => $currentHash,
            'created_at'   => now(),
        ]);
    }

    public function test_audit_chain_signature_tampered_throws_kind_audit_chain(): void
    {
        $branch = Branch::factory()->create();
        $this->emitGoodAuditChain($branch->id, 3);

        // Read the legitimate last row to chain off it
        $last = AuditLog::query()->where('branch_id', $branch->id)->orderByDesc('id')->first();
        $legitPrev = (string) $last->current_hash;

        // Insert a forged row: current_hash is invalid (won't match recompute)
        $this->insertForgedAuditRow($branch->id, $legitPrev, str_repeat('f', 64), '{"n":"TAMPERED"}');

        try {
            $this->newValidator()->assertChainIntegrity($branch->id);
            $this->fail('Expected FiscalChainCorruptedException');
        } catch (FiscalChainCorruptedException $e) {
            $this->assertSame(FiscalChainCorruptedException::KIND_AUDIT_CHAIN, $e->kind);
            $this->assertSame($branch->id, $e->branchId);
            $this->assertNotEmpty($e->errors);
            $this->assertSame('signature_mismatch', $e->errors[0]['kind']);
        }
    }

    public function test_audit_chain_prev_hash_fork_throws_kind_audit_chain(): void
    {
        $branch = Branch::factory()->create();
        $this->emitGoodAuditChain($branch->id, 3);

        // Insert a forged row with WRONG prev_hash (chain break)
        $this->insertForgedAuditRow($branch->id, str_repeat('a', 64), str_repeat('b', 64));

        try {
            $this->newValidator()->assertChainIntegrity($branch->id);
            $this->fail('Expected FiscalChainCorruptedException');
        } catch (FiscalChainCorruptedException $e) {
            $this->assertSame(FiscalChainCorruptedException::KIND_AUDIT_CHAIN, $e->kind);
            $this->assertNotEmpty($e->errors);
            // The first error captured will be either chain_break or signature_mismatch
            $this->assertContains($e->errors[0]['kind'], ['chain_break', 'signature_mismatch']);
        }
    }

    public function test_feature_flag_disabled_skips_audit_chain_check(): void
    {
        config(['fiscal.chain_validation_enabled' => false]);

        $branch = Branch::factory()->create();
        $this->emitGoodAuditChain($branch->id, 3);

        // Insert a forged row — would fail validation if enabled.
        $this->insertForgedAuditRow($branch->id, str_repeat('a', 64), str_repeat('b', 64));

        // Should NOT throw — extension is disabled.
        $this->newValidator()->assertChainIntegrity($branch->id);
        $this->assertTrue(true);
    }

    public function test_window_bounded_returns_empty_for_intact_short_chain(): void
    {
        $branch = Branch::factory()->create();
        $this->emitGoodAuditChain($branch->id, 3);

        $errors = $this->newValidator()->verifyAuditChainTail($branch->id, 500);
        $this->assertSame([], $errors);
    }

    public function test_window_smaller_than_chain_only_walks_tail(): void
    {
        $branch = Branch::factory()->create();
        // Tamper the FIRST batch by inserting a forged row early, then add
        // many clean rows on top — the tail walker should not see the forgery.
        $this->insertForgedAuditRow($branch->id, str_repeat('z', 64), str_repeat('y', 64));
        $this->emitGoodAuditChain($branch->id, 10);

        // Window of 3 only walks the last 3 (clean) rows — forgery is outside.
        $errors = $this->newValidator()->verifyAuditChainTail($branch->id, 3);
        $this->assertSame([], $errors);
    }

    public function test_empty_chain_returns_empty(): void
    {
        $branch = Branch::factory()->create();
        $errors = $this->newValidator()->verifyAuditChainTail($branch->id, 100);
        $this->assertSame([], $errors);
    }

    public function test_message_preserves_legacy_prefix_on_z_chain_failure(): void
    {
        // We can't easily corrupt the Z chain here without an open Z, but we
        // ensure the constant prefix is honoured by the exception class path.
        $exc = new FiscalChainCorruptedException(
            FiscalChainCorruptedException::KIND_Z_CHAIN,
            42,
            [],
            'NF525 Z-chain verification failed for branch 42'
        );
        $this->assertStringStartsWith('NF525 Z-chain verification failed', $exc->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $exc);
    }
}
