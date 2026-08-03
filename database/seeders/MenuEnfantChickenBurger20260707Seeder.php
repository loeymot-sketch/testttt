<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * [owner 2026-07-07] 2e menu enfant = « Chicken Burger » (au lieu de « Burger »).
 *
 * L'item 106 existait déjà (« Menu Enfant Burger », cat 11, 4,90 €, frites +
 * Capri-Sun) ; l'owner veut le libeller « Chicken Burger » à la caisse ET sur la
 * borne. Incrémental (le deploy ne rejoue PAS OwnerMenuUpdate20260623Seeder) +
 * idempotent (met à jour par ID, aucun doublon).
 *
 * Rollback : UPDATE items SET name='Menu Enfant Burger',
 *   description='Burger, frites et Capri-Sun.' WHERE id=106;
 */
class MenuEnfantChickenBurger20260707Seeder extends Seeder
{
    public function run(): void
    {
        $item = Item::withoutGlobalScopes()->find(106);
        if (! $item) {
            $this->command?->warn('  item 106 (menu enfant burger) absent — rien à faire.');

            return;
        }

        $item->name = 'Menu Enfant Chicken Burger';
        $item->description = 'Chicken burger, frites et Capri-Sun.';
        $item->save();

        $this->command?->info('  Menu Enfant #106 → « Menu Enfant Chicken Burger ».');
    }
}
