<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-08-19] « Sandwich Classique … vraiment toute la même chose que Cayenne. »
 *
 * `menu:add-sandwich-classique` clonait les variations, les extras et les lignes de recette
 * matière — mais PAS les FORMULES (`item_addons` : « Menu (Frites + Boisson) »,
 * « Frites Seules », « Boisson Seule »). Constaté sur les DEUX bases : Cayenne 3 formules,
 * Sandwich Classique 0. Conséquence concrète à la caisse : le bloc « Formule » du wizard
 * restait vide, donc le Sandwich Classique ne pouvait pas être passé en menu — alors que
 * c'est précisément un clone du Cayenne.
 *
 * Ce cas verrouille le clonage des formules ET son idempotence : la commande est rejouée à
 * chaque mise à jour de catalogue, elle ne doit jamais empiler de doublons.
 */
class SandwichClassiqueCloneAddonsTest extends TestCase
{
    use RefreshDatabase;

    private function creerSource(string $slug, string $nom): Item
    {
        return Item::factory()->create([
            'name' => $nom,
            'slug' => $slug,
            'status' => Status::ACTIVE,
            'price' => 7.40,
        ]);
    }

    private function creerFormule(int $itemId, int $addonItemId, string $role = 'menu_component'): void
    {
        DB::table('item_addons')->insert([
            'item_id' => $itemId,
            'addon_item_id' => $addonItemId,
            'addon_item_variation' => null,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nbFormules(string $slug): int
    {
        $id = Item::where('slug', $slug)->whereNull('deleted_at')->value('id');

        return $id === null ? 0 : DB::table('item_addons')
            ->where('item_id', $id)
            ->whereNull('deleted_at')
            ->count();
    }

    public function test_les_formules_du_cayenne_sont_clonees_sur_le_sandwich_classique(): void
    {
        $cayenne = $this->creerSource('cayenne', 'Cayenne');
        $menu = $this->creerSource('menu-frites-boisson', 'Menu (Frites + Boisson)');
        $frites = $this->creerSource('frites-seules', 'Frites Seules');
        $boisson = $this->creerSource('boisson-seule', 'Boisson Seule');

        $this->creerFormule($cayenne->id, $menu->id);
        $this->creerFormule($cayenne->id, $frites->id, 'side');
        $this->creerFormule($cayenne->id, $boisson->id, 'drink');

        $this->assertSame(0, $this->nbFormules('sandwich-classique'), 'préalable : le clone n\'existe pas encore');

        $this->artisan('menu:add-sandwich-classique')->assertSuccessful();

        $this->assertSame(
            3,
            $this->nbFormules('sandwich-classique'),
            'sans ses formules, le Sandwich Classique ne peut pas être passé en menu à la caisse'
        );
    }

    public function test_le_clonage_des_formules_est_idempotent(): void
    {
        $cayenne = $this->creerSource('cayenne', 'Cayenne');
        $menu = $this->creerSource('menu-frites-boisson', 'Menu (Frites + Boisson)');
        $this->creerFormule($cayenne->id, $menu->id);

        $this->artisan('menu:add-sandwich-classique')->assertSuccessful();
        $apresPremier = $this->nbFormules('sandwich-classique');

        $this->artisan('menu:add-sandwich-classique')->assertSuccessful();
        $apresSecond = $this->nbFormules('sandwich-classique');

        $this->assertSame(1, $apresPremier);
        $this->assertSame(
            $apresPremier,
            $apresSecond,
            'la commande est rejouée à chaque mise à jour de catalogue : elle ne doit jamais empiler de doublons'
        );
    }

    public function test_l_execution_a_blanc_n_ecrit_rien(): void
    {
        $cayenne = $this->creerSource('cayenne', 'Cayenne');
        $menu = $this->creerSource('menu-frites-boisson', 'Menu (Frites + Boisson)');
        $this->creerFormule($cayenne->id, $menu->id);

        $this->artisan('menu:add-sandwich-classique', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            0,
            $this->nbFormules('sandwich-classique'),
            '--dry-run doit compter sans jamais écrire'
        );
    }
}
