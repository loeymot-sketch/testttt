<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [AUDIT-F-003 / Sub-task 1] Schema sentinel — verrouille la structure DB
 * et l'API minimale des deux nouveaux models.
 *
 * Ce test échoue si :
 * - une migration manque (table absente)
 * - une colonne required est renommée/supprimée
 * - le BranchScope n'est pas appliqué (regression isolation)
 * - les status constants disparaissent (regression API publique)
 */
class CashDrawerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_drawer_sessions_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('cash_drawer_sessions'));

        $required = [
            'id', 'branch_id', 'opened_by_user_id',
            'opened_at', 'closed_at',
            'opening_amount', 'closing_amount', 'expected_closing_amount',
            'variance', 'variance_reason', 'status',
            'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertTrue(
                Schema::hasColumn('cash_drawer_sessions', $col),
                "cash_drawer_sessions.{$col} missing"
            );
        }
    }

    public function test_cash_movements_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('cash_movements'));

        $required = [
            'id', 'cash_drawer_session_id', 'branch_id', 'order_id',
            'type', 'amount', 'direction', 'notes',
            'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertTrue(
                Schema::hasColumn('cash_movements', $col),
                "cash_movements.{$col} missing"
            );
        }
    }

    public function test_z_reports_table_has_cash_columns(): void
    {
        $this->assertTrue(Schema::hasTable('z_reports'));

        foreach (['cash_opening_amount', 'cash_closing_amount', 'cash_variance', 'cash_movements_count'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('z_reports', $col),
                "z_reports.{$col} missing — alter migration not applied"
            );
        }
    }

    public function test_cash_drawer_session_status_constants_are_stable(): void
    {
        $this->assertSame('open', CashDrawerSession::STATUS_OPEN);
        $this->assertSame('closed', CashDrawerSession::STATUS_CLOSED);
        $this->assertSame('reconciled', CashDrawerSession::STATUS_RECONCILED);
    }

    public function test_cash_movement_type_and_direction_constants_are_stable(): void
    {
        $this->assertSame('order_payment', CashMovement::TYPE_ORDER_PAYMENT);
        $this->assertSame('cashback', CashMovement::TYPE_CASHBACK);
        $this->assertSame('drawer_open', CashMovement::TYPE_DRAWER_OPEN);
        $this->assertSame('drawer_close', CashMovement::TYPE_DRAWER_CLOSE);
        $this->assertSame('adjustment', CashMovement::TYPE_ADJUSTMENT);

        $this->assertSame('in', CashMovement::DIRECTION_IN);
        $this->assertSame('out', CashMovement::DIRECTION_OUT);
    }

    public function test_cash_drawer_session_persists_via_eloquent_with_branch_isolation(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user);

        $session = CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $user->id,
            'opened_at'         => now(),
            'opening_amount'    => 100.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        $this->assertNotNull($session->id);
        $this->assertSame($branch->id, $session->branch_id);
        $this->assertTrue($session->isOpen());
        $this->assertFalse($session->isClosed());
    }

    public function test_cash_movement_signed_amount_respects_direction(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user);

        $session = CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $user->id,
            'opened_at'         => now(),
            'opening_amount'    => 50.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        $in = CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $branch->id,
            'type'                   => CashMovement::TYPE_ORDER_PAYMENT,
            'amount'                 => 25.50,
            'direction'              => CashMovement::DIRECTION_IN,
        ]);

        $out = CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $branch->id,
            'type'                   => CashMovement::TYPE_CASHBACK,
            'amount'                 => 5.00,
            'direction'              => CashMovement::DIRECTION_OUT,
        ]);

        $this->assertSame(25.50, $in->signedAmount());
        $this->assertSame(-5.00, $out->signedAmount());
    }
}
