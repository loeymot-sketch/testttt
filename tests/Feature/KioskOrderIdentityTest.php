<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 E-ADV-3 2026-06-12] Kiosk order identity + refund wallet.
 *
 * Kiosk orders persist user_id = the kiosk MACHINE account (plumbing identity
 * required by show/changeStatus ownership + finalizePaidKioskOrder detection
 * — NOT changed here). Two leaks healed:
 *
 *  1. Historique/tracker (SimpleOrderResource:74 `customer_name`) surfaced
 *     the machine account's name (« Admin Le Cayenne ») as the CLIENT of the
 *     order — 3 different labels for the same order across surfaces.
 *     → kiosk-surface orders display « Client borne » (matches the W2 heal
 *     label on encaissement/show).
 *
 *  2. PaymentService::cashBack credited `users.balance` of order.user_id —
 *     for kiosk orders that is the MACHINE/staff account: every borne refund
 *     credited the ADMIN wallet (observed live: balance 2,00 → 5,80 after a
 *     3,80 refund) = phantom liability double-counting the drawer OUT.
 *     → wallet credit is skipped for kiosk-machine / staff / walk-in
 *     identities (refund is CASH at the drawer); real customers keep it.
 */
class KioskOrderIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_order_customer_name_is_client_borne_not_machine_account(): void
    {
        $this->seedSpatieRoles();
        [$branch, $machineUser] = $this->kioskMachineFixture('Admin Le Cayenne');

        $order = FrontendOrder::create([
            'user_id' => $machineUser->id,
            'branch_id' => $branch->id,
            'order_serial_no' => 'TEST-IDENT-1',
            'order_datetime' => now(),
            'source_surface' => 'kiosk',
            'total' => 10,
            'subtotal' => 10,
            'discount' => 0,
            'status' => 5,
            'payment_status' => 5,
            'order_type' => 25,
        ]);
        $order->setRelation('user', $machineUser);

        $payload = (new SimpleOrderResource($order))->toArray(Request::create('/'));

        $this->assertSame('Client borne', $payload['customer_name']);
    }

    public function test_web_order_customer_name_still_shows_real_customer(): void
    {
        $this->seedSpatieRoles();
        [$branch] = $this->kioskMachineFixture('Admin Le Cayenne');
        $customer = User::factory()->create(['branch_id' => 0, 'name' => 'Jeanne Vraie-Cliente']);

        $order = FrontendOrder::create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_serial_no' => 'TEST-IDENT-2',
            'order_datetime' => now(),
            'source_surface' => 'web',
            'total' => 10,
            'subtotal' => 10,
            'discount' => 0,
            'status' => 5,
            'payment_status' => 5,
            'order_type' => 5,
        ]);
        $order->setRelation('user', $customer);

        $payload = (new SimpleOrderResource($order))->toArray(Request::create('/'));

        $this->assertSame('Jeanne Vraie-Cliente', $payload['customer_name']);
    }

    public function test_kiosk_refund_does_not_credit_machine_account_wallet(): void
    {
        $this->seedSpatieRoles();
        [$branch, $machineUser] = $this->kioskMachineFixture('Admin Le Cayenne');
        $machineUser->forceFill(['balance' => 2.00])->save();

        $order = FrontendOrder::create([
            'user_id' => $machineUser->id,
            'branch_id' => $branch->id,
            'order_serial_no' => 'TEST-IDENT-3',
            'order_datetime' => now(),
            'source_surface' => 'kiosk',
            'total' => 3.80,
            'subtotal' => 3.80,
            'discount' => 0,
            'status' => 5,
            'payment_status' => 5,
            'order_type' => 25,
        ]);
        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'COUNTER-' . $order->id . '-TEST',
            'amount' => 3.80,
            'payment_method' => 'counter_cash',
            'sign' => '+',
            'type' => 'payment',
        ]);

        $cashBack = app(PaymentService::class)->cashBack($order, 'counter_cash', 'TXN-IDENT-TEST');

        $this->assertNotNull($cashBack, 'cash_back ledger row must still be written');
        $this->assertSame(
            2.00,
            round((float) $machineUser->fresh()->balance, 2),
            'borne refund must NEVER credit the machine/staff wallet (refund is cash at the drawer)'
        );
    }

    public function test_real_customer_refund_still_credits_wallet(): void
    {
        $this->seedSpatieRoles();
        [$branch] = $this->kioskMachineFixture('Admin Le Cayenne');
        $customer = User::factory()->create(['branch_id' => 0, 'name' => 'Jeanne Vraie-Cliente']);
        $customer->assignRole('Customer');
        $customer->forceFill(['balance' => 0])->save();

        $order = FrontendOrder::create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_serial_no' => 'TEST-IDENT-4',
            'order_datetime' => now(),
            'source_surface' => 'web',
            'total' => 5.00,
            'subtotal' => 5.00,
            'discount' => 0,
            'status' => 5,
            'payment_status' => 5,
            'order_type' => 5,
        ]);
        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'GATE-' . $order->id . '-TEST',
            'amount' => 5.00,
            'payment_method' => 'stripe',
            'sign' => '+',
            'type' => 'payment',
        ]);

        app(PaymentService::class)->cashBack($order, 'stripe', 'TXN-IDENT-TEST2');

        $this->assertSame(
            5.00,
            round((float) $customer->fresh()->balance, 2),
            'real customer wallet refund behavior must be preserved'
        );
    }

    /**
     * @return array{0: Branch, 1: User}
     */
    private function kioskMachineFixture(string $machineUserName): array
    {
        $branch = Branch::factory()->create();
        $machineUser = User::factory()->create([
            'branch_id' => $branch->id,
            'name' => $machineUserName,
        ]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $machineUser->id,
            'status' => Status::ACTIVE,
        ]);

        return [$branch, $machineUser];
    }
}
