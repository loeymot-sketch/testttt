<?php

namespace Tests\Feature\Console;

use App\Models\Branch;
use App\Models\PosParkedOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [CAISSE-S2] The pos:purge-parked-orders command runs unattended via the scheduler. It must convert
 * an unhandled throw into a domain-tagged log + clean FAILURE exit (not a silent skip), while leaving
 * the happy path unchanged.
 */
class PosPurgeParkedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function parked(string $token, $createdAt): PosParkedOrder
    {
        $p = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'L',
            'payload_json' => json_encode(['items' => []]),
            'preview_total' => 10.0,
            'items_count' => 1,
            'idempotency_token' => $token,
        ]);
        $p->forceFill(['created_at' => $createdAt])->saveQuietly(); // age the row past the purge window
        return $p;
    }

    public function test_happy_path_purges_old_keeps_fresh_and_exits_success(): void
    {
        $old1 = $this->parked('old-1', now()->subHours(48));
        $old2 = $this->parked('old-2', now()->subHours(30));
        $fresh = $this->parked('fresh', now()->subHours(2));

        $this->artisan('pos:purge-parked-orders', ['--older-than-hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('pos_parked_orders', ['id' => $old1->id]);
        $this->assertDatabaseMissing('pos_parked_orders', ['id' => $old2->id]);
        $this->assertDatabaseHas('pos_parked_orders', ['id' => $fresh->id]);
    }

    public function test_failure_logs_and_exits_failure_when_purge_throws(): void
    {
        Log::spy();
        // Simulate a DB failure on the purge query (the service is final → cannot be mocked):
        // dropping the table makes ->delete() throw a QueryException, exercising the catch path.
        Schema::drop('pos_parked_orders');

        $this->artisan('pos:purge-parked-orders', ['--older-than-hours' => 24])
            ->assertExitCode(1); // self::FAILURE

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => is_string($msg) && str_contains($msg, 'pos:purge-parked-orders failed'))
            ->once();
    }
}
