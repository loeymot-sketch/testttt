<?php

namespace Tests\Feature\Pos;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PosPurgeParkedScheduleTest extends TestCase
{
    public function test_kernel_registers_pos_purge_parked_daily(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $purge = $events->first(function ($event) {
            $cmd = (string) ($event->command ?? '');

            return str_contains($cmd, 'pos:purge-parked-orders');
        });

        $this->assertNotNull($purge, 'pos:purge-parked-orders should be scheduled (MEGA 2.G / F-11)');
        $this->assertStringContainsString('--older-than-hours=24', (string) $purge->command);
        $this->assertTrue((bool) $purge->withoutOverlapping);
    }
}
