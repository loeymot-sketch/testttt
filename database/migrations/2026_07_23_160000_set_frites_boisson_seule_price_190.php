<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL owner 2026-07-23] Prix catalogue « Frites Seules » et « Boisson Seule » = 1,90 €
 * (au lieu de 2,00 €), pour aligner la CAISSE sur la BORNE.
 *
 * Contexte : la borne dérive déjà 1,90 € via le ratio de rôle
 * (CompositionSnapshotBuilder::menuRoleAdjustedAddonPrice : menu 2,50 € × fries_ratio 0,76).
 * La CAISSE (pos-wizard) lit le prix catalogue de l'addonItem (= prix de l'Item cible) et
 * affichait/scellait donc 2,00 €. On corrige la donnée SSOT → affichage == scellé == 1,90 €
 * sur toutes les surfaces. Le « Menu (Frites + Boisson) » reste à 2,50 € (inchangé).
 *
 * Ne touche PAS le ratio (borne dérive du menu 2,50 €, pas de l'item Frites/Boisson) → aucun
 * double-décompte. Immuabilité NF525 préservée : les orders existants gardent leur snapshot
 * scellé ; seules les futures commandes utilisent 1,90 €.
 */
return new class extends Migration
{
    private array $targets = ['Frites Seules', 'Boisson Seule'];

    public function up(): void
    {
        foreach ($this->targets as $name) {
            DB::table('items')
                ->where('name', $name)
                ->where('price', 2.00)
                ->update(['price' => 1.90, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $name) {
            DB::table('items')
                ->where('name', $name)
                ->where('price', 1.90)
                ->update(['price' => 2.00, 'updated_at' => now()]);
        }
    }
};
