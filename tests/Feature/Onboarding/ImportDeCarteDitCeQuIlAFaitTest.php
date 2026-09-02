<?php

namespace Tests\Feature\Onboarding;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-02 2026-08-28] L'import Excel annonçait un succès sans jamais dire ce qu'il
 * avait fait.
 *
 * DEUX MÉCANIQUES DE SILENCE, superposées :
 *
 * 1. L'instance d'import était construite EN LIGNE dans l'appel à `Excel::import()`,
 *    donc jetée aussitôt. Les échecs collectés par `SkipsFailures` n'étaient jamais
 *    lus, et le contrôleur répondait `202` vide dans tous les cas.
 *
 * 2. Une ligne dont la catégorie n'existait pas était sautée SANS ÊTRE UN ÉCHEC :
 *    `getCategoryId()` renvoyait null, `model()` ne retournait rien, et Maatwebsite
 *    traitait ça comme « rien à créer » (`ModelManager::toModels()` fait
 *    `Collection::wrap(null)`, qui donne une collection vide).
 *
 * Ce que vivait le commerçant : il dépose son fichier de 45 lignes, la fenêtre se
 * ferme, une bulle verte s'affiche — et il peut avoir 0, 12 ou 45 produits créés,
 * sans aucun moyen de savoir lequel ni quelle ligne corriger. C'est exactement la
 * soirée que cette mission promet de lui sauver.
 *
 * Le correctif ne rend pas l'import plus permissif : il le rend BAVARD. Les mêmes
 * lignes sont refusées, mais elles sont désormais nommées.
 */
class ImportDeCarteDitCeQuIlAFaitTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Tax::factory()->create(['tax_rate' => 10, 'status' => \App\Enums\Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Tacos', 'status' => \App\Enums\Status::ACTIVE]);

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        foreach (['items', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items', 'items_create', 'items_edit']);
    }

    /**
     * Construit un vrai fichier CSV et le dépose sur la route d'import.
     *
     * @param  list<array<string, string>>  $lignes
     */
    private function deposer(array $lignes): \Illuminate\Testing\TestResponse
    {
        $entetes = ['name', 'category', 'price', 'item_type', 'tax', 'status', 'featured', 'description', 'caution'];

        // La route n'accepte que `xls`/`xlsx` : on écrit un VRAI classeur plutôt
        // qu'un CSV renommé, sinon PhpSpreadsheet échoue à la lecture et le banc
        // mesurerait le refus de format au lieu du comportement d'import.
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $onglet = $classeur->getActiveSheet();

        $matrice = [$entetes];
        foreach ($lignes as $ligne) {
            $matrice[] = array_map(static fn ($e) => (string) ($ligne[$e] ?? ''), $entetes);
        }
        $onglet->fromArray($matrice, null, 'A1');

        $chemin = tempnam(sys_get_temp_dir(), 'carte') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($chemin);

        $fichier = new UploadedFile($chemin, 'carte.xlsx', null, null, true);

        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->post('/api/admin/item/import/file', ['file' => $fichier]);

        @unlink($chemin);

        return $reponse;
    }

    /** @return array<string, string> Une ligne valide, à altérer au besoin. */
    private function ligneValide(array $remplacements = []): array
    {
        return $remplacements + [
            'name'        => 'Tacos poulet',
            'category'    => 'Tacos',
            'price'       => '8.50',
            'item_type'   => 'veg',
            'tax'         => '10',
            'status'      => 'active',
            'featured'    => 'no',
            'description' => '',
            'caution'     => '',
        ];
    }

    public function test_un_fichier_valide_dit_combien_de_produits_ont_ete_crees(): void
    {
        $reponse = $this->deposer([
            $this->ligneValide(),
            $this->ligneValide(['name' => 'Tacos merguez']),
        ]);

        $reponse->assertStatus(202);

        $this->assertSame(2, $reponse->json('creees'), 'Le compte rendu doit annoncer 2 créations.');
        $this->assertSame([], $reponse->json('echecs'));
        $this->assertStringContainsString('2 produits ajoutes', (string) $reponse->json('message'));

        $this->assertSame(2, Item::query()->count());
    }

    public function test_une_categorie_inconnue_n_est_plus_avalee_en_silence(): void
    {
        // LE DÉFAUT CENTRAL. Avant, cette ligne disparaissait sans un mot : ni créée,
        // ni signalée. Le commerçant lisait « succès » sur une carte incomplète.
        $reponse = $this->deposer([
            $this->ligneValide(),
            $this->ligneValide(['name' => 'Pizza reine', 'category' => 'Pizzas']),
        ]);

        $reponse->assertStatus(202);

        $echecs = $reponse->json('echecs');

        $this->assertCount(
            1,
            $echecs,
            "La ligne à catégorie inconnue doit être SIGNALÉE, pas sautée.\n"
            . 'Reçu : ' . json_encode($echecs, JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame('category', $echecs[0]['colonne']);

        // Et le motif doit nommer les catégories qui existent, sinon le commerçant
        // ne peut pas deviner l'orthographe attendue.
        $this->assertStringContainsString('Tacos', $echecs[0]['raison']);

        $this->assertSame(1, Item::query()->count(), 'Seule la ligne valide devait passer.');
        $this->assertSame(1, $reponse->json('creees'));
    }

    public function test_le_compte_rendu_dit_aussi_ce_qu_il_reste_a_corriger(): void
    {
        $reponse = $this->deposer([
            $this->ligneValide(),
            $this->ligneValide(['name' => 'Pizza reine', 'category' => 'Pizzas']),
            $this->ligneValide(['name' => 'Calzone', 'category' => 'Pizzas']),
        ]);

        $message = (string) $reponse->json('message');

        $this->assertStringContainsString('1 produit ajoute', $message);
        $this->assertStringContainsString('2 lignes a corriger', $message);
    }

    public function test_un_fichier_entierement_mauvais_ne_dit_plus_succes(): void
    {
        // Le pire cas d'avant : 202 vide, bulle verte, zéro produit.
        $reponse = $this->deposer([
            $this->ligneValide(['name' => 'Pizza reine', 'category' => 'Pizzas']),
        ]);

        $this->assertSame(0, $reponse->json('creees'));
        $this->assertStringContainsString('Aucun produit ajoute', (string) $reponse->json('message'));
        $this->assertSame(0, Item::query()->count());
    }

    public function test_l_import_reste_aussi_strict_qu_avant_sur_la_taxe(): void
    {
        // Contrôle négatif : rendre l'import bavard ne doit pas le rendre permissif.
        // Un taux de TVA inexistant reste refusé — c'est le garde posé le 2026-08-27,
        // et sans lui un article importé serait facturé à 0 % en silence.
        $reponse = $this->deposer([$this->ligneValide(['tax' => '3'])]);

        $this->assertTrue(
            $reponse->status() === 422 || $reponse->json('creees') === 0,
            'Un taux de TVA inconnu doit être refusé, pas créé à 0 %.'
        );

        $this->assertSame(0, Item::query()->count());
    }
}
