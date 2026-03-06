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
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('order_items')->truncate();
        DB::table('orders')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->call('db:seed', ['--class' => 'OrderTableSeeder']);
        $this->call('db:seed', ['--class' => 'OrderItemTableSeeder']);
        $this->call('db:seed', ['--class' => 'KdsOrderTableSeeder']);
        $this->call('db:seed', ['--class' => 'OrderTableSeederVersionTwo']);

        $this->info('Fresh order and order items seeded!');
    }
}