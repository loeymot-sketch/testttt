<?php

namespace Tests\Feature\Assistant;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-04 2026-08-28] La boucle complète, par HTTP : photographier → relire → appliquer.
 *
 * C'est le parcours de Karim, tel que le cahier des charges le décrit : il
 * photographie sa carte plastifiée, il voit apparaître catégories et produits, il
 * corrige ce que la lecture n'a pas su lire, il valide, et sa carte existe.
 *
 * Le point de conception que ce banc verrouille : **le serveur ne fait aucune
 * confiance à ce qu'il a proposé.** Il n'y a AUCUN état conservé entre la lecture
 * et l'application — les lignes reviennent du client et sont revalidées
 * intégralement. Même un client compromis ne peut donc créer que ce que
 * `ItemRequest` accepte, taxe obligatoire comprise.
 *
 * Tout tourne sur le bouchon déterministe : `assistant.enabled` vaut faux et
 * aucune requête ne sort de la machine. C'est le critère C1 — le GOAL converge
 * sans clé, donc sans le gate propriétaire G-IA.
 */
class LectureDeCartePuisApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Tax $taxe;
    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => \App\Enums\Status::ACTIVE]);

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        Permission::findOrCreate('items_create', 'sanctum');
        Permission::findOrCreate('item-categories_create', 'sanctum');
        $this->karim->givePermissionTo(['items_create', 'item-categories_create']);
    }

    private function photoDeCarte(): UploadedFile
    {
        return UploadedFile::fake()->image('ma-carte.jpg', 1200, 1600);
    }

    public function test_karim_photographie_sa_carte_et_voit_une_proposition(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/lecture', ['photo' => $this->photoDeCarte()]);

        $reponse->assertOk()
            ->assertJsonStructure([
                'status',
                'proposition' => ['categories', 'articles', 'source', 'tronquee'],
                'seuil_confiance',
                'source',
            ]);

        // RIEN n'a été écrit. C'est la moitié la plus importante de ce banc : la
        // lecture PROPOSE, elle n'applique pas.
        $this->assertSame(0, Item::query()->count(), 'La lecture a écrit en base.');
        $this->assertSame(0, ItemCategory::query()->count(), 'La lecture a écrit en base.');

        // L'écran doit pouvoir dire d'où vient ce qu'il affiche : bouchon ou vraie
        // lecture. Sans cette information, un commerçant croirait avoir importé sa
        // carte alors qu'il regarde une démonstration.
        $this->assertSame('bouchon', $reponse->json('source'));
    }

    public function test_la_photo_est_effacee_apres_lecture(): void
    {
        // Une carte de restaurant n'a pas à rester sur le serveur une fois lue, et
        // surtout pas à une adresse devinable.
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/lecture', ['photo' => $this->photoDeCarte()])
            ->assertOk();

        $restes = \Illuminate\Support\Facades\Storage::disk('local')->files('assistant/cartes');

        $this->assertSame([], $restes, 'La photo de carte est restée sur le disque.');
    }

    public function test_karim_valide_et_sa_carte_existe(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/application', [
                'tax_id'    => $this->taxe->id,
                'item_type' => 1,
                'articles'  => [
                    ['nom' => 'Tacos poulet', 'categorie' => 'Tacos', 'prix' => 8.5],
                    ['nom' => 'Coca 33cl',    'categorie' => 'Boissons', 'prix' => 2.0],
                ],
            ]);

        $reponse->assertOk();

        $this->assertSame(2, Item::query()->count());
        $this->assertSame(2, ItemCategory::query()->count());

        // Le résumé doit se lire, pas se recouper.
        $this->assertStringContainsString('2 produits ajoutés', $reponse->json('resume'));
    }

    public function test_sans_tva_choisie_rien_n_est_cree_et_le_message_le_dit(): void
    {
        // Le verrou central. Un produit sans taxe serait facturé à 0 % en silence.
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/application', [
                'item_type' => 1,
                'articles'  => [['nom' => 'Tacos poulet', 'categorie' => 'Tacos', 'prix' => 8.5]],
            ]);

        $reponse->assertStatus(422)->assertJsonValidationErrors(['tax_id']);

        $this->assertSame(0, Item::query()->count());

        // Et le message doit dire au commerçant CE QUI SE PASSERAIT, pas juste
        // « champ requis ».
        $this->assertStringContainsString(
            '0 %',
            $reponse->json('errors.tax_id.0'),
            "Le message doit expliquer la conséquence — sinon le commerçant choisit\n"
            . "au hasard pour se débarrasser de l'erreur."
        );
    }

    public function test_une_ligne_sans_prix_est_refusee_avant_toute_ecriture(): void
    {
        // Le bouchon rend exprès une ligne dont le prix n'a pas pu être lu
        // (« Assiette du chef », prix null). L'écran doit la faire SAISIR, jamais
        // l'inventer — et le serveur refuse si elle revient vide.
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/application', [
                'tax_id'    => $this->taxe->id,
                'item_type' => 1,
                'articles'  => [
                    ['nom' => 'Tacos poulet',     'categorie' => 'Tacos', 'prix' => 8.5],
                    ['nom' => 'Assiette du chef', 'categorie' => 'Assiettes', 'prix' => null],
                ],
            ]);

        $reponse->assertStatus(422)->assertJsonValidationErrors(['articles.1.prix']);

        $this->assertSame(
            0,
            Item::query()->count(),
            "Aucune ligne ne doit passer tant que la charge est invalide : sinon Karim\n"
            . "se retrouve avec une carte à moitié importée, sans savoir laquelle."
        );
    }

    public function test_le_plafond_de_relecture_humaine_est_applique(): void
    {
        // Ce n'est pas une limite technique mais une limite de RELECTURE : au-delà,
        // personne ne vérifie vraiment et la validation humaine devient une fiction.
        $plafond = (int) config('assistant.menu_extraction.max_items_par_lecture', 60);

        $trop = [];
        for ($i = 0; $i <= $plafond; $i++) {
            $trop[] = ['nom' => 'Produit ' . $i, 'categorie' => 'Divers', 'prix' => 1.0];
        }

        $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/application', [
                'tax_id'    => $this->taxe->id,
                'item_type' => 1,
                'articles'  => $trop,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['articles']);

        $this->assertSame(0, Item::query()->count());
    }

    public function test_un_employe_sans_droit_catalogue_ne_peut_pas_lire_une_carte(): void
    {
        // Lire une carte prépare une écriture au catalogue : ce n'est pas une
        // consultation, et ça ne doit pas devenir une porte dérobée.
        $employe = User::factory()->create(['branch_id' => 1]);

        $this->actingAs($employe, 'sanctum')
            ->postJson('/api/admin/assistant/menu/lecture', ['photo' => $this->photoDeCarte()])
            ->assertStatus(403);
    }

    public function test_un_employe_sans_droit_catalogue_ne_peut_pas_appliquer(): void
    {
        $employe = User::factory()->create(['branch_id' => 1]);

        $this->actingAs($employe, 'sanctum')
            ->postJson('/api/admin/assistant/menu/application', [
                'tax_id'    => $this->taxe->id,
                'item_type' => 1,
                'articles'  => [['nom' => 'Tacos poulet', 'categorie' => 'Tacos', 'prix' => 8.5]],
            ])
            ->assertStatus(403);

        $this->assertSame(0, Item::query()->count());
    }

    public function test_un_fichier_deguise_en_image_est_refuse(): void
    {
        // Reprend la garde du chemin FACTURE, la plus stricte des deux lectures
        // d'image en service. Le chemin Uber l'avait oubliée ; on ne reproduit pas
        // cet oubli sur une troisième porte d'entrée.
        $piege = UploadedFile::fake()->create('carte.php', 10, 'application/x-httpd-php');

        $this->actingAs($this->karim, 'sanctum')
            ->postJson('/api/admin/assistant/menu/lecture', ['photo' => $piege])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }
}
