<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * [PILOTAGE 2026-08-09] Une option du wizard — un supplément, une sauce, un type
 * de pain — pouvait être CRÉÉE depuis l'admin, mais pas ILLUSTRÉE.
 *
 * Sa photo était déduite de son NOM via `config/menu_images.php` : ajouter une
 * image demandait d'éditer un fichier PHP et de déposer un fichier sur le
 * serveur. Hors de portée du propriétaire, donc jamais fait — 131 choix sur
 * 1002 s'affichaient en case grise, dont les deux premières étapes de la borne.
 *
 * Ces tests fixent le contrat de la sortie de secours : la photo posée depuis
 * l'admin PRIME, la table par nom reste le REPLI, et rien de ce qui marchait
 * avant ne change.
 */
class OptionPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function optionDe(Item $item, string $nom): ItemExtra
    {
        return ItemExtra::create([
            'item_id' => $item->id, 'name' => $nom, 'price' => 0.90, 'status' => 5,
        ]);
    }

    public function test_la_photo_televersee_prime_sur_la_correspondance_par_nom(): void
    {
        $item = Item::factory()->create();
        // « Cheddar » est illustré par la table de config : c'est le cas le plus
        // exigeant, puisqu'il faut que le téléversement PASSE DEVANT.
        $option = $this->optionDe($item, 'Cheddar');
        $avant = $option->thumb;
        $this->assertNotNull($avant, 'pré-requis : Cheddar est illustré par la table par nom');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image", [
                'photo' => UploadedFile::fake()->image('cheddar-maison.jpg', 400, 400),
            ])
            ->assertOk();

        $apres = $option->fresh()->thumb;
        $this->assertNotSame($avant, $apres, 'la photo posée doit remplacer celle déduite du nom');
        $this->assertStringContainsString('cheddar-maison', $apres);
    }

    public function test_retirer_la_photo_fait_retomber_sur_la_correspondance_par_nom(): void
    {
        $item = Item::factory()->create();
        $option = $this->optionDe($item, 'Cheddar');
        $repli = $option->thumb;

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image", [
                'photo' => UploadedFile::fake()->image('temporaire.jpg', 400, 400),
            ])->assertOk();
        $this->assertStringContainsString('temporaire', $option->fresh()->thumb);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image")
            ->assertOk();

        $this->assertSame($repli, $option->fresh()->thumb, 'sans photo, on doit revenir au repli, pas au vide');
    }

    public function test_une_option_sans_photo_ni_correspondance_garde_l_image_par_defaut(): void
    {
        // Le repli neutre doit rester : sans lui, la mise en page casse au lieu
        // d'afficher une vignette anonyme.
        $item = Item::factory()->create();
        $option = $this->optionDe($item, 'Ingrédient totalement inconnu');

        $this->assertStringContainsString(
            (string) config('menu_images.default'),
            (string) $option->thumb
        );
    }

    public function test_l_identifiant_du_produit_dans_l_url_n_est_pas_decoratif(): void
    {
        // Sans ce contrôle, on changerait la photo d'une option d'un AUTRE
        // produit en devinant son identifiant.
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();
        $optionDeB = $this->optionDe($itemB, 'Cheddar');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/item/extra/{$itemA->id}/{$optionDeB->id}/change-image", [
                'photo' => UploadedFile::fake()->image('intrus.jpg', 400, 400),
            ])
            ->assertNotFound();
    }

    public function test_un_editeur_de_branche_ne_peut_pas_changer_une_photo_du_catalogue_global(): void
    {
        $item = Item::factory()->create();
        $option = $this->optionDe($item, 'Cheddar');
        $editeurBranche = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $editeurBranche->givePermissionTo('items_edit');

        $this->actingAs($editeurBranche, 'sanctum')
            ->postJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image", [
                'photo' => UploadedFile::fake()->image('photo.jpg', 400, 400),
            ])
            ->assertForbidden();

        // La suppression doit être aussi fermée que l'ajout — sinon on peut
        // effacer sans pouvoir reposer.
        $this->actingAs($editeurBranche, 'sanctum')
            ->deleteJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image")
            ->assertForbidden();
    }

    public function test_un_fichier_dangereux_est_refuse(): void
    {
        $item = Item::factory()->create();
        $option = $this->optionDe($item, 'Cheddar');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/item/extra/{$item->id}/{$option->id}/change-image", [
                'photo' => UploadedFile::fake()->create('charge-utile.pht', 40, 'application/x-httpd-php'),
            ])
            ->assertStatus(422);
    }
}
