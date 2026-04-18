<?php

namespace Tests\Unit\Services\Menu;

use App\Services\Menu\MenuSnapshot;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the branch-scoped monotonic menu snapshot.
 *
 * @see app/Services/Menu/MenuSnapshot.php
 */
class MenuSnapshotTest extends TestCase
{
    private function make(): MenuSnapshot
    {
        return new MenuSnapshot(new Repository(new ArrayStore()));
    }

    public function test_current_returns_one_when_key_absent(): void
    {
        $snap = $this->make();
        $this->assertSame(1, $snap->current(1));
    }

    public function test_current_is_idempotent(): void
    {
        $snap = $this->make();
        $a = $snap->current(5);
        $b = $snap->current(5);
        $this->assertSame(1, $a);
        $this->assertSame(1, $b);
    }

    public function test_bump_increments_and_returns_new_value(): void
    {
        $snap = $this->make();
        $snap->current(1); // seed
        $next = $snap->bump(1);
        $this->assertSame(2, $next);
        $this->assertSame(2, $snap->current(1));
    }

    public function test_bump_initializes_when_absent(): void
    {
        $snap = $this->make();
        $next = $snap->bump(42);
        $this->assertGreaterThanOrEqual(2, $next);
        $this->assertSame($next, $snap->current(42));
    }

    public function test_branches_are_isolated(): void
    {
        $snap = $this->make();
        $snap->bump(1);
        $snap->bump(1);
        $snap->bump(2);
        $this->assertSame(3, $snap->current(1));
        $this->assertSame(2, $snap->current(2));
    }

    public function test_bump_returns_strictly_monotonic_sequence(): void
    {
        $snap = $this->make();
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $seen[] = $snap->bump(7);
        }
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen, 'Snapshot bumps must be monotonic');
        $this->assertSame(count($seen), count(array_unique($seen)), 'Snapshot bumps must be unique');
    }
}
