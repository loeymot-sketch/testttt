<?php

/**
 * [GENIE Wave 1 — T1.1 2026-06-16] Characterization sentinel for the frozen FiscalSequenceService lock-
 * acquire-failure path. When Cache::lock->block() can't acquire within the window (a stuck lock from a
 * crashed worker — reachable on the single box), next() throws RuntimeException. This PINS that behavior
 * (no frozen edit) so a future change is forced to be deliberate — and documents that the callers
 * currently have NO degrade path (the gap flagged by the completeness audit; the real degrade-path fix is
 * an owner-gated frozen change).
 */

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FiscalLockAcquireFailureTest extends TestCase
{
    /** @test */
    public function test_next_throws_when_the_fiscal_lock_cannot_be_acquired(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturn(false); // never acquires within the window
        $lock->shouldReceive('release')->andReturnNull();
        Cache::shouldReceive('lock')->andReturn($lock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not acquire lock/i');

        app(FiscalSequenceService::class)->next(1);
    }
}
