<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Fournisseurs d'exemple.
 *
 * Léger et idempotent (updateOrCreate sur la clé unique branch_id+name) — aucune
 * facture inventée (le domaine achats se remplit par saisie/photo réelle).
 * Non câblé dans DatabaseSeeder (miroir du domaine raw-materials) : lancer via
 * `php artisan db:seed --class=Database\\Seeders\\SupplierSeeder`.
 */
class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['name' => 'Metro', 'contact' => 'Metro Cash & Carry'],
            ['name' => 'Promocash', 'contact' => 'Promocash Grossiste'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::updateOrCreate(
                ['branch_id' => 1, 'name' => $supplier['name']],
                ['contact' => $supplier['contact']],
            );
        }
    }
}
