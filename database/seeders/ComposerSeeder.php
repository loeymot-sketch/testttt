<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ComposerSeeder extends Seeder
{
    public function run(): void
    {
        // B2 introduces schema only. Default profile materialization belongs to B3/B4
        // once the composer write API and runtime migration are available.
    }
}
