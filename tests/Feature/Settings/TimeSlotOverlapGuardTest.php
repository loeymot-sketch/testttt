<?php

namespace Tests\Feature\Settings;

use App\Models\TimeSlot;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] TimeSlotService::store()'s
 * overlap guard only catches "new slot nested inside an existing one" or
 * "one edge of the new slot falls inside an existing one" — it never checks
 * the reverse (new slot fully CONTAINS an existing one) nor exact-boundary
 * duplicates, because every branch uses strict `>`/`<` against the NEW
 * slot's edges only. Overlapping/empty slots silently break frontend
 * ordering-window availability (owner-facing impact, not just a data nit).
 */
class TimeSlotOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): \App\Models\User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');
        return $admin;
    }

    private function authed()
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')));
    }

    public function test_rejects_new_slot_that_fully_contains_an_existing_slot(): void
    {
        TimeSlot::query()->create(['opening_time' => '10:00', 'closing_time' => '11:00', 'day' => 1]);

        // Pre-fix: none of the 3 branches catch "new fully contains existing"
        // (new.opening < existing.opening AND new.closing > existing.closing).
        $resp = $this->authed()->postJson('/api/admin/setting/time-slot', [
            'opening_time' => '09:00',
            'closing_time' => '12:00',
            'day' => 1,
        ]);

        $resp->assertStatus(422);
        $this->assertDatabaseMissing('time_slots', ['opening_time' => '09:00', 'closing_time' => '12:00']);
    }

    public function test_rejects_exact_duplicate_slot(): void
    {
        TimeSlot::query()->create(['opening_time' => '10:00', 'closing_time' => '11:00', 'day' => 1]);

        $resp = $this->authed()->postJson('/api/admin/setting/time-slot', [
            'opening_time' => '10:00',
            'closing_time' => '11:00',
            'day' => 1,
        ]);

        $resp->assertStatus(422);
        $this->assertSame(1, TimeSlot::where('day', 1)->count());
    }

    public function test_allows_back_to_back_adjacent_slots(): void
    {
        // Symmetric assertion: the fix must not become overly strict and
        // reject legitimate adjacent (non-overlapping) slots.
        TimeSlot::query()->create(['opening_time' => '10:00', 'closing_time' => '11:00', 'day' => 1]);

        $resp = $this->authed()->postJson('/api/admin/setting/time-slot', [
            'opening_time' => '11:00',
            'closing_time' => '12:00',
            'day' => 1,
        ]);

        $resp->assertStatus(201);
        $this->assertSame(2, TimeSlot::where('day', 1)->count());
    }

    public function test_still_rejects_partial_overlap_new_starts_inside_existing(): void
    {
        // Locks the pre-existing (already-correct) behavior.
        TimeSlot::query()->create(['opening_time' => '10:00', 'closing_time' => '11:00', 'day' => 1]);

        $resp = $this->authed()->postJson('/api/admin/setting/time-slot', [
            'opening_time' => '10:30',
            'closing_time' => '11:30',
            'day' => 1,
        ]);

        $resp->assertStatus(422);
    }

    public function test_allows_same_time_range_on_a_different_day(): void
    {
        TimeSlot::query()->create(['opening_time' => '10:00', 'closing_time' => '11:00', 'day' => 1]);

        $resp = $this->authed()->postJson('/api/admin/setting/time-slot', [
            'opening_time' => '10:00',
            'closing_time' => '11:00',
            'day' => 2,
        ]);

        $resp->assertStatus(201);
    }
}
