<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FreshOrderSeed extends Command
{
    protected $signature = 'seed:fresh-orders';
    protected $description = 'Fresh seed orders and order items';

    public function handle()
    {
        // [WJ-2 WI-3 DBA-005] Production guard — refuse to TRUNCATE orders+order_items
        // in production. Without this guard, an operator running the wrong command
        // on a prod console would wipe the entire fiscal order history (NF525 chain
        // breakage, irreversible data loss). Mirrors the canonical pattern used by
        // E2EStressCommand, CleanupTestFixturesCommand, EnsureKioskMachineCommand,
        // and Iter15CleanupTestOrdersCommand.
        if (app()->environment('production')) {
            $this->error('seed:fresh-orders refuses to run in APP_ENV=production. Aborting (NF525 / data-loss protection).');
            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('order_items')->truncate();
        DB::table('orders')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->call('db:seed', ['--class' => 'OrderTableSeeder']);
        $this->call('db:seed', ['--class' => 'OrderItemTableSeeder']);
        $this->call('db:seed', ['--class' => 'KdsOrderTableSeeder']);
        $this->call('db:seed', ['--class' => 'OrderTableSeederVersionTwo']);

        $this->info('Fresh order and order items seeded!');
        return self::SUCCESS;
    }
}