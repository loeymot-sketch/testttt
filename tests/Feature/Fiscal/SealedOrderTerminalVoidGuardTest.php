<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\RefundWithCounterEntryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [SELF-AUDIT R6 P1 2026-07-05] Une vente SCELLÉE (fiscalisée, dans un Z clos) pouvait être (a) remboursée
 * via le miroir counter-entry (qui laisse le parent ACCEPT) PUIS (b) annulée EN PLACE (CANCELED/REJECTED
 * n'était PAS gardé par le sceau — seul RETURNED l'était) → ZReportService retranchait le total DEUX FOIS
 * (bloc miroir + bloc postZAdjustment) → total signé sous-évalué d'un total complet. Ce test verrouille :
 * une vente scellée ne peut plus être annulée/rejetée en place, et le miroir refuse un parent terminal.
 */
class SealedOrderTerminalVoidGuardTest extends TestCase
{
    use RefreshDatabase;

    private function sealedOrder(Branch $branch, int $status = OrderStatus::ACCEPT): Order
    {
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id' => $branch->id, 'sequence_no' => 1,
            'opened_at' => $opened, 'closed_at' => $closed, 'status' => ZReport::STATUS_CLOSED,
        ]);

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => $status,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 50.00,
            'total' => 50.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 10,
            'created_at' => $opened->copy()->addHours(2), // scellé dans le Z clos
        ]);
    }

    /** @test — CANCELED en place d'une vente scellée = BLOQUÉ (doit passer par le miroir counter-entry). */
    public function sealed_order_cannot_be_canceled_in_place(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('pos-orders', 'sanctum');
        $user->givePermissionTo('pos-orders');
        $this->actingAs($user, 'sanctum');

        $order = $this->sealedOrder($branch);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::CANCELED, 'reason' => 'void en place d\'une vente scellée']);

        try {
            app(OrderService::class)->changeStatus($order, $request, false);
        } catch (\Throwable $e) {
            // bloqué — attendu
        }

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $order->fresh()->status,
            'Une vente SCELLÉE ne doit PAS être annulée en place (sinon double-négatif du Z signé).'
        );
    }

    /** @test — le miroir counter-entry REFUSE un parent déjà terminal (CANCELED). */
    public function counter_entry_mirror_refused_on_terminal_parent(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($user);

        $parent = $this->sealedOrder($branch, OrderStatus::CANCELED);

        $this->expectException(\InvalidArgumentException::class);
        app(RefundWithCounterEntryService::class)->execute($parent, 'remboursement d\'une commande déjà annulée');
    }
}
