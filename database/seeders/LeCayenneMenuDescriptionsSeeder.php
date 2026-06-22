<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Seeder;

/**
 * [owner 2026-06-22] Descriptions menu « qualité grande chaîne » pour la borne + la caisse.
 *
 * Objectif owner : un copy APPÉTISSANT et bien fait pour le client (façon McDonald's / BK
 * kiosk), pas une liste technique. Règles :
 *  - accroche appétissante en tête (les ~60 premiers caractères vendent même tronqués),
 *  - repères qualité (« maison », « mariné », « croustillant », « généreux », « fondant »),
 *  - JAMAIS répéter le nom du produit dans la description (ex. un « Bowl Poulet crispy » ne
 *    redit pas « Poulet crispy » — la valeur est déjà dans le nom),
 *  - ≤ ~88 caractères (la carte affiche 2 lignes propres, sans troncature),
 *  - français correct, voix de marque cohérente.
 *
 * Clé = nom canonique de l'item (stable en V1 LOCAL Le Cayenne). Idempotent : ré-exécutable.
 * Les boissons/suppléments restent volontairement simples (le nom suffit).
 */
class LeCayenneMenuDescriptionsSeeder extends Seeder
{
    public function run(): void
    {
        $descriptions = [
            // — Sandwichs Cayenne (signature) —
            'Sandwich Cayenne' => 'Notre signature : viande marinée, sauce Cayenne maison et crudités fraîches.',
            'Big Cayenne'      => 'Version XL : 2 viandes, cheddar, œuf, jambon, sauce Cayenne maison et crudités.',

            // — Galettes —
            'Galette Normale'  => 'Galette dorée et croustillante, garnie à votre goût et sauce maison au choix.',
            'Galette Cayenne'  => 'Galette croustillante, sauce Cayenne maison incluse et crudités fraîches.',

            // — Sandwichs Classiques —
            'Sandwich Classique' => 'Pain faluche moelleux, viande au choix, crudités fraîches et sauce maison.',
            'Big Classique'      => 'Version XL : pain faluche, 2 viandes, cheddar, œuf, jambon, sauce et crudités.',

            // — Burgers —
            'Chicken Burger' => 'Filet pané croustillant et doré, crudités fraîches et sauce, pain brioché moelleux.',
            'Big Chicken'    => 'Version XL : filet pané croustillant, cheddar, jambon, œuf et sauce, pain brioché.',

            // — Tacos —
            'Tacos'     => 'Viande au choix, frites maison et sauce fromagère onctueuse, le tout gratiné à souhait.',
            'Big Tacos' => 'Double viande, frites maison et sauce fromagère onctueuse, gratiné. Grande faim assurée.',

            // — Bols Frites (le poulet est déjà dans le nom : on ne le répète pas) —
            'Bowl Frites Poulet mariné'   => 'Sur un lit de frites maison croustillantes, sauce au choix. Gratiné en option.',
            'Bowl Frites Poulet curry'    => 'Sur un lit de frites maison croustillantes, sauce au choix. Gratiné en option.',
            'Bowl Frites Poulet tandoori' => 'Sur un lit de frites maison croustillantes, sauce au choix. Gratiné en option.',
            'Bowl Frites Poulet crispy'   => 'Sur un lit de frites maison croustillantes, sauce au choix. Gratiné en option.',

            // — Bols Riz —
            'Bowl Riz Poulet mariné'   => 'Sur un lit de riz basmati parfumé, sauce au choix. Gratiné en option.',
            'Bowl Riz Poulet curry'    => 'Sur un lit de riz basmati parfumé, sauce au choix. Gratiné en option.',
            'Bowl Riz Poulet tandoori' => 'Sur un lit de riz basmati parfumé, sauce au choix. Gratiné en option.',
            'Bowl Riz Poulet crispy'   => 'Sur un lit de riz basmati parfumé, sauce au choix. Gratiné en option.',

            // — Frites —
            'Petite Frites' => 'Frites maison croustillantes. Nature, ou avec cheddar fondant et oignons.',
            'Grande Frites' => 'Grandes frites maison croustillantes. Nature, ou cheddar fondant et oignons.',

            // — Menu enfant —
            'Menu Nuggets' => '6 nuggets de poulet croustillants, frites maison et une boisson Capri-Sun.',

            // — Desserts —
            'Glace'      => 'Glace artisanale, douce et onctueuse.',
            'Tarte Daim' => 'Tarte gourmande au Daim, croquante et délicieusement caramélisée.',
            'Tiramisu'   => 'Tiramisu maison, crémeux et généreux, juste comme il faut.',
        ];

        $updated = 0;
        foreach ($descriptions as $name => $desc) {
            $n = Item::withoutGlobalScope(BranchScope::class)
                ->where('name', $name)
                ->whereNull('deleted_at')
                ->update(['description' => $desc]);
            $updated += $n;
        }

        $this->command?->info("LeCayenneMenuDescriptionsSeeder: {$updated} descriptions menu mises à jour (qualité grande-chaîne).");
    }
}
