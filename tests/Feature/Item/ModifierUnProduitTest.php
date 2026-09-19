<?php

namespace Tests\Feature\Item;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [F-ITEM-SAVE-MUET 2026-09-03]
 *
 * Modifier un produit dont le nom est deja porte par une AUTRE fiche echoue — c'est
 * voulu. Ce qui ne l'etait pas : le message ne disait pas QUI occupait le nom, et le
 * coupable pouvait etre une fiche DESACTIVEE, absente de toutes les listes que le
 * commercant consulte. Il ne voyait donc qu'un enregistrement qui « ne marche pas ».
 *
 * Cas reel, reproduit sur la production le 2026-09-03 : deux « Sandwich Classique »,
 * l'un de mai desactive, l'autre d'aout actif. Modifier l'actif renvoyait 422 a cause
 * de l'invisible.
 *
 * Ce banc verrouille aussi le comportement NORMAL, qu'aucune correction du message ne
 * doit casser : garder son propre nom en modifiant autre chose reste autorise.
 */
class ModifierUnProduitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        foreach (['items', 'items_create', 'items_edit', 'items_delete', 'items_show'] as $droit) {
            Permission::firstOrCreate(['name' => $droit, 'guard_name' => 'sanctum']);
        }
    }

    private function connecterAdministrateur(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);
    }

    private function taxe(): Tax
    {
        return Tax::firstOrCreate(
            ['code' => 'TEST-VAT-10'],
            ['name' => 'TVA 10 % (test)', 'tax_rate' => 10,
             'type' => \App\Enums\TaxType::PERCENTAGE, 'status' => Status::ACTIVE]
        );
    }

    private function categorie(string $nom): ItemCategory
    {
        return ItemCategory::create([
            'name' => $nom,
            'slug' => \Illuminate\Support\Str::slug($nom),
            'status' => Status::ACTIVE,
        ]);
    }

    private function creerProduit(string $nom, int $categorieId, int $statut = Status::ACTIVE): Item
    {
        return Item::create([
            'name' => $nom,
            'slug' => \Illuminate\Support\Str::slug($nom).'-'.uniqid(),
            'item_category_id' => $categorieId,
            'item_type' => 1,
            'price' => 7.40,
            'is_featured' => 1,
            'status' => $statut,
            'order' => 1,
            'tax_id' => $this->taxe()->id,
        ]);
    }

    private function charge(Item $produit, array $remplace = []): array
    {
        return array_merge([
            'name' => $produit->name,
            'item_category_id' => $produit->item_category_id,
            'item_type' => 1,
            'price' => $produit->price,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'tax_id' => $this->taxe()->id,
        ], $remplace);
    }

    /** Le cas ordinaire : je change le prix, je garde le nom. Cela DOIT passer. */
    public function test_garder_son_propre_nom_en_changeant_le_prix_est_autorise(): void
    {
        $this->connecterAdministrateur();
        $categorie = $this->categorie('Sandwichs');
        $produit = $this->creerProduit('Sandwich Classique', $categorie->id);

        $reponse = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson("/api/admin/item/{$produit->id}", $this->charge($produit, ['price' => 8.20]));

        $reponse->assertStatus(200);
        $this->assertSame('8.200000', (string) $produit->fresh()->price);
    }

    /** Un homonyme DESACTIVE bloque toujours — mais le message doit le nommer. */
    public function test_un_homonyme_desactive_est_nomme_dans_le_message(): void
    {
        $this->connecterAdministrateur();
        $ancienne = $this->categorie('Sandwich Classique');
        $courante = $this->categorie('Sandwichs');

        $obsolete = $this->creerProduit('Sandwich Classique', $ancienne->id, Status::INACTIVE);
        $actif = $this->creerProduit('Sandwich Classique', $courante->id);

        $reponse = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson("/api/admin/item/{$actif->id}", $this->charge($actif, ['price' => 9.10]));

        $reponse->assertStatus(422);

        $message = (string) ($reponse->json('errors.name.0') ?? '');
        $this->assertStringContainsString('#'.$obsolete->id, $message,
            'Le message doit nommer la fiche qui occupe le nom.');
        $this->assertStringContainsString('DESACTIVE', $message,
            'Le message doit dire que la fiche en conflit est desactivee — sinon elle reste introuvable.');
    }

    /** Le garde-fou reste un garde-fou : deux produits ACTIFS ne peuvent pas partager un nom. */
    public function test_reprendre_le_nom_d_un_autre_produit_reste_refuse(): void
    {
        $this->connecterAdministrateur();
        $categorie = $this->categorie('Tacos');

        $voisin = $this->creerProduit('Tacos L', $categorie->id);
        $mien = $this->creerProduit('Tacos XL', $categorie->id);

        $reponse = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson("/api/admin/item/{$mien->id}", $this->charge($mien, ['name' => 'Tacos L']));

        $reponse->assertStatus(422);
        $this->assertStringContainsString('#'.$voisin->id, (string) $reponse->json('errors.name.0'));
        $this->assertSame('Tacos XL', $mien->fresh()->name);
    }

    /** Creation : le nom d'un produit existant reste refuse. */
    public function test_creer_un_produit_avec_un_nom_deja_pris_reste_refuse(): void
    {
        $this->connecterAdministrateur();
        $categorie = $this->categorie('Burgers');
        $existant = $this->creerProduit('Big Burger', $categorie->id);

        $reponse = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson('/api/admin/item', [
                'name' => 'Big Burger',
                'item_category_id' => $categorie->id,
                'item_type' => 1,
                'price' => 9.90,
                'is_featured' => 1,
                'status' => Status::ACTIVE,
                'order' => 1,
                'tax_id' => $this->taxe()->id,
            ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('#'.$existant->id, (string) $reponse->json('errors.name.0'));
    }
}
