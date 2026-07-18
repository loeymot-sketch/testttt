<?php

use App\Console\Commands\AssignMenuVatCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER 2026-07-18] « TVA 10 % partout ». Lie tous les items menu actifs à
 * NULL/0 % au VAT 10 % (TTC) canonique via {@see AssignMenuVatCommand} — mode
 * tax-inclusive donc les prix AFFICHÉS ne changent pas (la TVA est extraite).
 * Corrige le blocage go-live B1 (preflight : 7 boissons à TVA NULL sur le VPS,
 * ex. Coca Cherry/Tropico/Ice Tea/Fanta Citron). Idempotente (no-op si déjà 10 %).
 * DATA uniquement — chaîne Z + composition_snapshot des commandes passées intacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(AssignMenuVatCommand::class)) {
            return;
        }
        \Illuminate\Support\Facades\Artisan::call('fiscal:assign-menu-vat');
    }

    public function down(): void
    {
        // No-op : on ne re-dé-taxe jamais un menu (retour arrière = risque fiscal).
    }
};
