<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Allergen;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB 2026-08-28] Toute la chaîne allergènes existait, sauf l'écran de saisie.
 *
 * Vérifié maillon par maillon avant d'écrire une ligne :
 *
 *   colonne `items.allergen_flags` + pivot `item_allergen` ............... OK
 *   `ItemRequest:84-104` valide `allergen_flags` ET `kds_station` ........ OK
 *   `ItemObserver` → `AllergenService::projectFlags()` → `sync()` ........ OK
 *   `ItemResource` expose ; POS et KDS affichent ........................ OK
 *   `helpers/kioskFilters.js` FILTRE LA BORNE par allergène ............. OK
 *   une route qui liste les 14 allergènes .............................. ABSENTE
 *   un écran qui envoie `allergen_flags` ............................... ABSENT
 *
 * Le docblock de `app/Models/Allergen.php` dit lui-même « Utile pour l'UI admin
 * CRUD allergènes ». Cette UI n'a jamais existé.
 *
 * CE QUE ÇA COÛTAIT — et ce n'est pas une question d'ergonomie :
 * `LeCayenneAllergenSeeder` pose des correspondances explicitement DEVINÉES (son
 * propre commentaire dit « using **guessed mappings** : Sandwich →
 * gluten/oeufs/lait/moutarde/sulfites »). La borne présentait donc ces
 * suppositions comme des faits à un client qui filtre par allergie, et le
 * commerçant n'avait aucun moyen de les corriger. Pour un restaurant français,
 * l'information allergène sur denrée non préemballée est une obligation
 * (Règlement INCO 1169/2011, décret 2015-447).
 *
 * `kds_station` était dans le même état : validé, lu par le KDS, jamais écrit.
 */
class UnCommercantPeutDeclarerSesAllergenesTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;
    private Tax $taxe;
    private ItemCategory $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->artisan('db:seed', ['--class' => 'AllergensSeeder']);

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $this->categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        foreach (['items', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items', 'items_create', 'items_edit']);
    }

    /** @param array<string, mixed> $extra */
    private function champsProduit(array $extra = []): array
    {
        return array_merge([
            'name'             => 'Tacos poulet',
            'price'            => '8.50',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'item_type'        => 1,
            'is_featured'      => 10,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'description'      => '',
            'caution'          => '',
        ], $extra);
    }

    public function test_le_referentiel_des_allergenes_est_atteignable(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')->getJson('/api/admin/item/allergens');

        $reponse->assertOk();

        $liste = $reponse->json('data');

        $this->assertCount(
            14,
            $liste,
            "Les 14 allergènes de l'Annexe II du Règlement UE 1169/2011 doivent être\n"
            . "listés. AUCUNE route ne les exposait : il n'y avait même pas de quoi\n"
            . 'peupler une liste de choix dans le formulaire produit.'
        );

        foreach (['code', 'cle', 'icon'] as $clef) {
            $this->assertArrayHasKey($clef, $liste[0], "Chaque entrée porte `{$clef}`.");
        }

        // La CLÉ de traduction, pas une traduction : le sous-arbre `allergens.*`
        // n'existe que dans `resources/js/languages/*.json`, côté navigateur.
        // `trans('allergens.gluten')` renvoyait la clé telle quelle — l'écran aurait
        // affiché « allergens.gluten » au lieu de « Gluten ». C'est l'écran qui
        // résout, là où les traductions vivent déjà.
        $this->assertStringStartsWith('allergens.', (string) $liste[0]['cle']);

        $codes = array_column($liste, 'code');
        $this->assertContains('gluten', $codes);
        $this->assertContains('arachides', $codes);
    }

    public function test_un_produit_cree_avec_des_allergenes_les_conserve(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')->post('/api/admin/item', $this->champsProduit([
            'allergen_flags'         => ['gluten', 'lait'],
            'allergen_flags_defini'  => '1',
            'kds_station'            => 'cuisine_chaude',
        ]));

        $this->assertContains($reponse->status(), [200, 201, 202], (string) $reponse->getContent());

        $produit = Item::query()->where('name', 'Tacos poulet')->firstOrFail();

        $this->assertEqualsCanonicalizing(['gluten', 'lait'], $produit->allergen_flags ?? []);
        $this->assertSame('cuisine_chaude', $produit->kds_station);

        // Le pivot, alimenté par l'observateur : c'est LUI que la borne interroge.
        $this->assertEqualsCanonicalizing(
            ['gluten', 'lait'],
            $produit->allergens()->pluck('code')->all(),
            "Le pivot `item_allergen` est la source du filtre de la borne. Sans lui,\n"
            . 'le produit reste présenté comme sans allergène.'
        );
    }

    public function test_la_liste_rend_les_allergenes_au_formulaire_qui_les_renvoie(): void
    {
        Item::factory()->create([
            'name'             => 'Tacos poulet',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'status'           => Status::ACTIVE,
            'allergen_flags'   => ['gluten'],
            'kds_station'      => 'bar',
        ]);

        $ligne = collect(
            $this->actingAs($this->karim, 'sanctum')->getJson('/api/admin/item?paginate=0')->json('data')
        )->firstWhere('name', 'Tacos poulet');

        $this->assertNotNull($ligne);

        // LE PIÈGE ÉVITÉ. Le tiroir d'édition s'hydrate depuis cette liste. Ajouter
        // les cases à l'écran sans rendre la valeur ici reproduirait à l'identique le
        // défaut d'effacement corrigé le même jour sur `siret`, sur les réglages de
        // borne et sur `channels` : corriger une faute dans le NOM d'un produit
        // effacerait ses allergènes — une information légalement due au client.
        $this->assertSame(['gluten'], $ligne['allergen_flags'] ?? null);
        $this->assertSame('bar', $ligne['kds_station'] ?? null);
    }

    public function test_renommer_un_produit_nefface_pas_ses_allergenes(): void
    {
        $produit = Item::factory()->create([
            'name'             => 'Tacos poluet',      // la faute à corriger
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'status'           => Status::ACTIVE,
            'allergen_flags'   => ['gluten', 'moutarde'],
        ]);

        // Ce que renvoie le formulaire après hydratation : les mêmes allergènes.
        $this->actingAs($this->karim, 'sanctum')->post('/api/admin/item/' . $produit->id, $this->champsProduit([
            'name'                  => 'Tacos poulet',
            'allergen_flags'        => ['gluten', 'moutarde'],
            'allergen_flags_defini' => '1',
        ]));

        $this->assertEqualsCanonicalizing(
            ['gluten', 'moutarde'],
            $produit->fresh()->allergen_flags ?? [],
            'Corriger une faute de frappe ne doit rien effacer.'
        );
    }

    public function test_un_commercant_peut_RETIRER_un_allergene_declare_par_erreur(): void
    {
        $produit = Item::factory()->create([
            'name'             => 'Tacos poulet',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'status'           => Status::ACTIVE,
            'allergen_flags'   => ['gluten'],
        ]);

        // Décocher la DERNIÈRE case n'envoie aucune entrée `allergen_flags[]`. Sans le
        // témoin, c'est indiscernable d'un formulaire qui ignore le champ, et
        // `validated()` ne contiendrait pas la clé : l'allergène resterait à vie.
        //
        // Une déclaration FAUSSE est pire qu'une déclaration absente : elle écarte un
        // client d'un plat qu'il pouvait manger, et fait douter des autres.
        $this->actingAs($this->karim, 'sanctum')->post('/api/admin/item/' . $produit->id, $this->champsProduit([
            'allergen_flags_defini' => '1',
        ]));

        $this->assertSame(
            [],
            $produit->fresh()->allergen_flags ?? [],
            "Le témoin `allergen_flags_defini` doit permettre de vider la liste."
        );

        $this->assertSame(
            [],
            $produit->fresh()->allergens()->pluck('code')->all(),
            'Le pivot doit suivre, sinon la borne continue de filtrer sur l\'ancien état.'
        );
    }

    public function test_sans_temoin_un_champ_absent_ne_touche_a_rien(): void
    {
        $produit = Item::factory()->create([
            'name'             => 'Tacos poulet',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'status'           => Status::ACTIVE,
            'allergen_flags'   => ['gluten'],
        ]);

        // Un écran qui ne connaît pas ce champ (l'import, une API tierce) ne doit
        // surtout pas l'effacer par omission. C'est la moitié prudente du témoin.
        $this->actingAs($this->karim, 'sanctum')
            ->post('/api/admin/item/' . $produit->id, $this->champsProduit());

        $this->assertSame(
            ['gluten'],
            $produit->fresh()->allergen_flags ?? [],
            "Sans témoin, l'absence du champ signifie « je n'en sais rien », pas « aucun »."
        );
    }

    public function test_un_code_inconnu_est_refuse_et_non_enregistre(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')->postJson('/api/admin/item', $this->champsProduit([
            'allergen_flags'        => ['gluten', 'kryptonite'],
            'allergen_flags_defini' => '1',
        ]));

        $reponse->assertStatus(422);

        $this->assertSame(
            0,
            Item::query()->where('name', 'Tacos poulet')->count(),
            "Un code hors du référentiel légal ne doit pas passer : il ne voudrait rien\n"
            . 'dire pour le client qui lit la borne.'
        );

        // Le référentiel fait autorité des deux côtés : la validation lit les mêmes
        // codes que la route de liste.
        $this->assertGreaterThan(0, Allergen::query()->count());
    }
}
