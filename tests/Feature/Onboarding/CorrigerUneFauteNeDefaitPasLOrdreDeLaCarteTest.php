<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-02 2026-08-28] Corriger une faute de frappe ne défait plus l'ordre de la carte.
 *
 * ═══ LE DÉFAUT, EN TROIS PIÈCES QUI SE TIENNENT ═══
 *
 * 1. `ItemCreateComponent:530` envoyait `fd.append('order', 1)` — une **constante**,
 *    en création COMME en modification (le même formulaire poste sur
 *    `/admin/item/{id}`).
 * 2. `SimpleItemResource` n'exposait **pas** `order` : le formulaire ne pouvait donc
 *    pas renvoyer la valeur réelle, même s'il l'avait voulu.
 * 3. `ItemService:423` fait `$item->update($request->validated() + …)` : la constante
 *    écrasait le rang en base.
 *
 * La borne **trie là-dessus** (`KioskMenuService:321-322`). Des rangs utiles
 * existent : 79 articles à 1, 57 à 0, 27 à 2, 4 à 99, un à 999, un à 9998.
 *
 * Un commerçant qui corrigeait une faute dans le NOM d'un produit voyait donc
 * l'ordre de sa carte se défaire — sans rien avoir demandé, et sans rien voir.
 *
 * ═══ CE QUI REND CE DÉFAUT INSTRUCTIF ═══
 *
 * C'est le motif **exact** corrigé le matin même sur `allergen_flags`, dans ce même
 * formulaire. Le commentaire posé à cette occasion, deux lignes plus haut, décrit ce
 * défaut mot pour mot :
 *
 * > « corriger une faute dans le NOM d'un produit effacerait ses allergènes
 * > déclarés — le défaut exact corrigé le même jour sur `siret` »
 *
 * `order` avait le même, et personne ne l'a vu en écrivant la phrase.
 */
class CorrigerUneFauteNeDefaitPasLOrdreDeLaCarteTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;
    private ItemCategory $categorie;
    private Tax $taxe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        foreach (['items', 'items_show', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->patron->givePermissionTo(['items', 'items_show', 'items_create', 'items_edit']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $this->categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
    }

    public function test_la_ressource_expose_le_rang_sinon_le_formulaire_ne_peut_pas_le_renvoyer(): void
    {
        // C'est la pièce SANS LAQUELLE les deux autres ne servent à rien : le
        // formulaire ne peut renvoyer que ce que la lecture lui donne.
        $produit = Item::factory()->create([
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'status'           => Status::ACTIVE,
            'order'            => 7,
        ]);

        // LES DEUX chemins d'hydratation du formulaire : la liste
        // (`SimpleItemResource`) et la fiche (`ItemResource`). Corriger l'un et
        // laisser l'autre reproduirait le defaut une fois sur deux — le motif
        // « jumeau oublie ». Ma premiere version ne verifiait que la fiche.
        $verifies = 0;

        foreach (['/api/admin/item', '/api/admin/item/show/' . $produit->id] as $url) {
            $lecture = $this->getJson($url);

            if (! in_array($lecture->status(), [200, 201], true)) {
                continue;
            }

            $verifies++;

            $this->assertStringContainsString(
                '"order"',
                (string) $lecture->getContent(),
                "`{$url}` n'expose pas le rang d'affichage.\n\n"
                . "Le formulaire ne peut donc pas le renvoyer, et poste une constante :\n"
                . "corriger une faute de frappe défait l'ordre de la carte, que la borne\n"
                . 'utilise pour trier.'
            );
        }

        // SANS CE COMPTE, le banc serait vert si les DEUX routes échouaient : la
        // boucle passerait son tour et rien ne serait mesuré. C'est le piège de
        // l'assertion vide, que ce chantier documente depuis trois jours — et que
        // mon premier jet avait retendu, avec un `assertTrue(true)` en guise de
        // conclusion.
        $this->assertSame(
            2,
            $verifies,
            "Un des deux chemins d'hydratation du formulaire n'a pas répondu :\n"
            . "seuls {$verifies} sur 2 ont été mesurés, et ce banc ne prouve donc\n"
            . 'que la moitié de ce qu\'il annonce.'
        );
    }

    public function test_un_enregistrement_qui_ne_touche_pas_au_rang_le_preserve(): void
    {
        $produit = Item::factory()->create([
            'name'             => 'Tacos poulet',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'price'            => 8.50,
            'status'           => Status::ACTIVE,
            'is_featured'      => Ask::YES,
            'order'            => 42,
        ]);

        // Le patron corrige UNE faute de frappe. Il ne touche pas au rang, et
        // l'écran ne lui en parle même pas.
        $reponse = $this->putJson('/api/admin/item/' . $produit->id, [
            'name'             => 'Tacos poulets',
            'price'            => '8.50',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'item_type'        => $produit->item_type ?: \App\Enums\ItemType::VEG,
            'is_featured'      => Ask::YES,
            'status'           => Status::ACTIVE,
            'order'            => 42,   // ce que le formulaire renvoie désormais
            'description'      => '',
            'caution'          => '',
        ]);

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            'Modification refusée : ' . mb_substr((string) $reponse->getContent(), 0, 250)
        );

        $this->assertSame(
            42,
            (int) $produit->fresh()->order,
            "Le rang d'affichage est retombé à " . (int) $produit->fresh()->order . ".\n\n"
            . "Le commerçant a corrigé une faute dans un nom, et l'ordre de sa carte\n"
            . "s'est défait. La borne trie sur ce champ."
        );

        $this->assertSame('Tacos poulets', $produit->fresh()->name, 'La correction demandée n\'a pas eu lieu.');
    }

    public function test_le_formulaire_renvoie_le_rang_reel_et_non_une_constante(): void
    {
        // Les deux bancs ci-dessus passent par l'API. Celui-ci vérifie l'ÉCRAN :
        // le serveur peut bien préserver le rang, si le formulaire poste `1` en
        // dur, il l'écrase quand même. C'est là qu'était le défaut.
        $formulaire = file_get_contents(
            resource_path('js/components/admin/items/ItemCreateComponent.vue')
        );

        // On retire les commentaires avant de chercher : le correctif CITE l'ancien
        // code pour expliquer ce qui a change, et une recherche naive se declencherait
        // sur cette explication. Ma premiere version faisait exactement ca.
        $sansCommentaires = preg_replace('#/\*[\s\S]*?\*/#', '', $formulaire);
        $sansCommentaires = preg_replace('#^\s*//.*$#m', '', $sansCommentaires);

        $this->assertStringNotContainsString(
            "fd.append('order', 1)",
            $sansCommentaires,
            "Le formulaire poste de nouveau un rang CONSTANT.\n"
            . "Corriger une faute de frappe défera l'ordre de la carte, et aucun banc\n"
            . 'côté serveur ne le verra puisque le serveur, lui, fait ce qu\'on lui dit.'
        );

        $this->assertStringContainsString(
            "fd.append('order', this.props.form.order ?? 1)",
            $formulaire,
            'Le formulaire ne renvoie pas le rang réel.'
        );

        $liste = file_get_contents(
            resource_path('js/components/admin/items/ItemListComponent.vue')
        );

        $this->assertStringContainsString(
            'order: item.order',
            $liste,
            "Le formulaire n'est pas hydraté avec le rang : il renverrait `undefined`,\n"
            . 'et le repli `?? 1` écraserait de nouveau.'
        );
    }
}
