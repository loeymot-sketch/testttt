<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Smartisan\Settings\Facades\Settings;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L1 2026-06-11] Seed the SINGLE loyalty rate
 * source of truth (settings group `loyalty_setup`). Before this command the
 * rates only existed as divergent hard-coded fallbacks (10 pts/€ backend vs
 * 1 pt/€ promised by the client apps — a silent 10x cashback).
 *
 * Canonical D11 defaults: 1 pt/€ earn · 100 pts = 1 € · min redeem 100.
 */
class SetLoyaltyRatesCommand extends Command
{
    protected $signature = 'foodking:set-loyalty-rates
        {per_euro=1 : Points crédités par euro dépensé}
        {per_discount=100 : Points nécessaires pour 1 € de réduction}
        {min=100 : Minimum de points pour utiliser}';

    protected $description = 'Seed le barème fidélité unique (settings loyalty_setup) — canon D11';

    public function handle(): int
    {
        $perEuro = max(0, (int) $this->argument('per_euro'));
        $perDiscount = max(1, (int) $this->argument('per_discount'));
        $min = max(0, (int) $this->argument('min'));

        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => $perEuro,
            'loyalty_points_for_1_euro_discount' => $perDiscount,
            'loyalty_min_redeem_points'          => $min,
        ]);

        $this->info("Barème fidélité seedé : {$perEuro} pt/€ · {$perDiscount} pts = 1 € · min {$min} pts.");

        return self::SUCCESS;
    }
}
