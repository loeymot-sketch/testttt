<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL owner 2026-07-27] Nouveau barème livraison (structure préparée, livraison
 * lancée plus tard — le web l'affiche « Ça arrive bientôt ») :
 *   jusqu'à 3 km → 4 € fixe ; au-delà, +2 € par km entamé.
 *   Grille owner : 3 km→4 € · 4 km→5 € · 5 km→7 € · 6 km→9 €.
 * Exprimé avec la formule EXISTANTE de DeliveryFeeService (zéro code touché) :
 *   fee = max(minimum, base + per_km × ceil(km − free_km))
 *   → base=3, per_km=2, free_km=3, minimum=4 reproduit la grille au centime.
 * Ancien barème (2026-06-27) : base=4, per_km=1, free_km=5, minimum=4.
 * Miroir web : api.js DELIVERY (même math, affichage seulement — backend = SSOT).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('branches')->where('id', 1)->update([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 2.00,
            'delivery_fee_free_km' => 3.00,
            'delivery_fee_minimum' => 4.00,
        ]);
    }

    public function down(): void
    {
        DB::table('branches')->where('id', 1)->update([
            'delivery_fee_base'    => 4.00,
            'delivery_fee_per_km'  => 1.00,
            'delivery_fee_free_km' => 5.00,
            'delivery_fee_minimum' => 4.00,
        ]);
    }
};
