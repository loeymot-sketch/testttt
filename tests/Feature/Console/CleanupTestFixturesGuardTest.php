<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [SELF-AUDIT C 2026-07-05] `foodking:cleanup-test-fixtures` faisait un HARD-delete des orders matchés
 * SANS garde fiscale ni cascade des enfants RESTRICT-FK (order_coupons/order_addresses) → un ordre avec
 * coupon faisait throw le delete final → rollback all-or-nothing de TOUT le sweep. Et un ordre fiscalisé
 * pouvait être physiquement retiré (rupture gap-free NF525). Ce test verrouille le garde fiscal + la
 * cascade + l'exclusion des ordres portant des lignes immuables (order_payments, trigger 45000).
 */
class CleanupTestFixturesGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'PWFIXC-';

    private function fixtureOrder(string $suffix, ?int $fiscal = null): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'token' => self::PREFIX.$suffix,
            'status' => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => $fiscal,
        ]);
    }

    private function runCleanup(): int
    {
        return Artisan::call('foodking:cleanup-test-fixtures', [
            '--prefix' => self::PREFIX,
            '--apply' => true,
            '--confirm' => 'PW-FIXTURES',
        ]);
    }

    /** @test — un ordre fixture FISCALISÉ n'est jamais hard-deleté (gap-free NF525). */
    public function fiscalised_fixture_order_is_never_deleted(): void
    {
        $fiscal = $this->fixtureOrder('fisc', 5150);
        $plain = $this->fixtureOrder('plain-1');

        $this->runCleanup();

        $this->assertNotNull(Order::withTrashed()->withoutGlobalScopes()->find($fiscal->id), 'Ordre fiscalisé conservé.');
        $this->assertNull(Order::withoutGlobalScopes()->find($plain->id), 'Fixture non fiscalisée nettoyée.');
    }

    /** @test — un ordre fixture avec order_coupons est supprimé sans rollback du lot (cascade RESTRICT-FK). */
    public function fixture_order_with_coupon_is_deleted_without_rolling_back_the_batch(): void
    {
        $withCoupon = $this->fixtureOrder('coupon');
        $plain = $this->fixtureOrder('plain-2');

        DB::table('order_coupons')->insert([
            'order_id' => $withCoupon->id,
            'coupon_id' => 555,
            'user_id' => $withCoupon->user_id,
            'discount' => 2.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCleanup();

        $this->assertNull(Order::withoutGlobalScopes()->find($withCoupon->id), 'Ordre avec coupon supprimé (cascade order_coupons).');
        $this->assertNull(Order::withoutGlobalScopes()->find($plain->id), 'La FK RESTRICT ne rollback plus tout le lot.');
        $this->assertSame(0, DB::table('order_coupons')->where('order_id', $withCoupon->id)->count(), 'Ligne order_coupons cascadée.');
    }

    /** @test — un ordre portant une ligne IMMUABLE order_payments est préservé (exclu), le reste est nettoyé. */
    public function fixture_order_with_immutable_payment_row_is_preserved(): void
    {
        $withPayment = $this->fixtureOrder('pay');
        $plain = $this->fixtureOrder('plain-3');

        DB::table('order_payments')->insert([
            'order_id' => $withPayment->id,
            'branch_id' => $withPayment->branch_id,
            'mode' => 1, // CASH
            'amount' => 5.00,
            'change_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCleanup();

        $this->assertNotNull(
            Order::withoutGlobalScopes()->find($withPayment->id),
            'Un ordre avec ligne order_payments immuable est EXCLU du sweep (jamais de tentative de delete → trigger 45000).'
        );
        $this->assertNull(
            Order::withoutGlobalScopes()->find($plain->id),
            'Le reste du lot est nettoyé (l\'ordre protégé n\'a pas fait rollback tout le sweep).'
        );
    }
}
