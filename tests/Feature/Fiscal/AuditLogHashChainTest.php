<?php

namespace Tests\Feature\Fiscal;

use App\Models\AuditLog;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fiscal\Concerns\InstallsAuditLogImmutabilityTriggers;
use Tests\TestCase;

/**
 * [POS-9.4.4 / POS-GA-F-04]
 *
 * HMAC-chained integrity for `audit_logs`:
 *  - a fresh chain verifies intact;
 *  - disabling the DB trigger (test-only) and flipping a byte in an
 *    earlier row is detected by verifyChain();
 *  - injecting a forged row with the wrong prev_hash is detected;
 *  - payload canonicalisation is order-independent (so the chain is
 *    stable across PHP array orderings).
 */
class AuditLogHashChainTest extends TestCase
{
    use InstallsAuditLogImmutabilityTriggers;
    use RefreshDatabase;

    /**
     * Dedicated branch for this suite — avoids collisions with other fiscal
     * tests that also use branch_id=1 (MySQL keeps audit row ids across
     * tests when DDL from this file drops triggers and breaks isolation).
     */
    private const BR = 910_501;

    private AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret', 'unit-test-secret-chain');
        $this->service = app(AuditLogService::class);
    }

    public function test_chain_verified_after_sequence_of_writes(): void
    {
        $a = $this->service->write([
            'branch_id' => self::BR, 'action' => 'order.create',  'payload' => ['id' => 1, 'total' => 100],
        ]);
        $b = $this->service->write([
            'branch_id' => self::BR, 'action' => 'order.pay',     'payload' => ['id' => 1, 'method' => 'cash'],
        ]);
        $c = $this->service->write([
            'branch_id' => self::BR, 'action' => 'order.cancel',  'payload' => ['id' => 1, 'reason' => 'client leaves'],
        ]);

        $this->assertNull($b->prev_hash !== $a->current_hash ? 'prev_hash mismatch' : null);
        $this->assertSame($a->current_hash, $b->prev_hash);
        $this->assertSame($b->current_hash, $c->prev_hash);

        $this->assertNull($this->service->verifyChain(self::BR), 'Pristine chain must verify.');
    }

    public function test_tampering_detected_on_payload(): void
    {
        $this->service->write(['branch_id' => self::BR, 'action' => 'order.create', 'payload' => ['id' => 1, 'total' => 100]]);
        $mid = $this->service->write(['branch_id' => self::BR, 'action' => 'order.pay',    'payload' => ['id' => 1, 'method' => 'cash']]);
        $this->service->write(['branch_id' => self::BR, 'action' => 'order.cancel', 'payload' => ['id' => 1]]);

        // Bypass the immutability trigger for the duration of the tampering
        // simulation (this is the exact attack the chain is designed to
        // detect even when an attacker has raw DB access).
        $this->dropImmutabilityTriggers();
        try {
            DB::table('audit_logs')->where('id', $mid->id)->update([
                'payload' => json_encode(['id' => 1, 'method' => 'card']),
            ]);
        } finally {
            // DDL drops survive transaction rollback on MySQL — reinstall so
            // subsequent tests (and other classes) still see INSERT-only triggers.
            $this->reinstallImmutabilityTriggers();
        }

        $firstBroken = $this->service->verifyChain(self::BR);
        $this->assertSame($mid->id, $firstBroken,
            'verifyChain() must point at the tampered row.');
    }

    public function test_forged_row_with_wrong_prev_hash_is_detected(): void
    {
        $this->service->write(['branch_id' => self::BR, 'action' => 'order.create', 'payload' => ['id' => 1]]);

        AuditLog::unguard();
        try {
            $forged = AuditLog::create([
                'branch_id' => self::BR,
                'action' => 'order.secret_admin_override',
                'payload' => ['id' => 1, 'new_total' => 0.01],
                'prev_hash' => str_repeat('0', 64), // wrong parent
                'current_hash' => str_repeat('f', 64), // irrelevant — prev_hash already broken
            ]);
        } finally {
            AuditLog::reguard();
        }

        $firstBroken = $this->service->verifyChain(self::BR);
        $this->assertSame($forged->id, $firstBroken);
    }

    public function test_payload_key_order_does_not_affect_hash(): void
    {
        $h1 = $this->service->computeHash(self::BR, null, 'order.create', ['a' => 1, 'b' => ['x' => 2, 'y' => 3]]);
        $h2 = $this->service->computeHash(self::BR, null, 'order.create', ['b' => ['y' => 3, 'x' => 2], 'a' => 1]);

        $this->assertSame($h1, $h2, 'Canonicalisation must be order-independent.');
    }

    public function test_secret_mandatory(): void
    {
        Config::set('fiscal.audit_secret', '');
        $this->expectException(\RuntimeException::class);
        $this->service->write(['branch_id' => self::BR, 'action' => 'order.create', 'payload' => ['id' => 1]]);
    }
}
