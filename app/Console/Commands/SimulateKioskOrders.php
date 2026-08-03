<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SimulateKioskOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kiosk:simulate-orders {count=50}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simule une forte charge de commandes venant du Kiosk (End-to-End Testing)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $count = $this->argument('count');
        $this->info("Début de la simulation de $count commandes simultanées Kiosk...");

        $user = \App\Models\User::where('branch_id', 1)->first() ?? \App\Models\User::first();

        // [GOAL-2026-05-30] queue_number must be UNIQUE per branch like the real
        // allocator (OrderService::allocateQueueNumber / FrontendOrderService): 4-digit
        // zero-padded "A####" continuing from the current DB max. The previous
        // `'A' . str_pad($i + 1, 3, ...)` used the loop index, so EVERY single-order
        // invocation produced "A001" -> 3 separate orders sharing one display number,
        // surfacing as the SAME order rendered 3x on the OSS customer wall (real bug
        // report 2026-05-30). Dev/E2E load command only; mirror the real 4-digit format
        // so simulated orders never collide with each other or with real orders.
        $maxQueue = (int) \DB::table('orders')
            ->where('branch_id', 1)
            ->whereNotNull('queue_number')
            ->where('queue_number', 'like', 'A%')
            ->get()
            ->map(static fn ($r): int => preg_match('/^A\d+$/', (string) $r->queue_number) === 1
                ? (int) substr((string) $r->queue_number, 1) : 0)
            ->max();

        // On simule une création massive
        for ($i = 0; $i < $count; $i++) {
            $order = \App\Models\Order::create([
                'order_serial_no' => date('dmy') . rand(1000, 9999),
                'queue_number' => 'A' . str_pad($maxQueue + $i + 1, 4, '0', STR_PAD_LEFT),
                'user_id' => $user->id, // Client valide
                'branch_id' => 1,
                'subtotal' => 15.00,
                'total_tax' => 1.50,
                'total' => 16.50,
                'order_type' => 10,
                'order_datetime' => now(),
                'payment_method' => 1,
                'payment_status' => 5,
                'status' => 1,
                'source' => 10,
            ]);

            \App\Events\SendOrderGotPush::dispatch(['order_id' => $order->id]);
            \App\Events\SendOrderGotMail::dispatch(['order_id' => $order->id]);
            \App\Events\SendOrderGotSms::dispatch(['order_id' => $order->id]);
        }

        $this->info("Simulation terminée ! Les {$count} commandes ont été injectées. Vérifiez le POS/KDS pour constater la synchronisation temps réel.");
        return Command::SUCCESS;
    }
}
