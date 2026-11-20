<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB 2026-08-28] Le jumeau muet de l'import de carte.
 *
 * Le même défaut avait été fermé côté PRODUITS le même jour
 * (`LaCarteExporteeSeReimporteTest`). Côté CATÉGORIES il n'avait pas bougé, et il y
 * était plus grave encore — trois couches empilées :
 *
 *   1. `ItemCategoryExport::headings()` écrit « Nom », « Statut », « Description »,
 *      que `WithHeadingRow` slugge en `nom`, `statut`, `description` — quand les
 *      règles attendaient `name`, `status`, `description`. AUCUNE colonne ne
 *      correspondait.
 *   2. `SkipsOnFailure` avalait donc les échecs `name required` de toutes les lignes.
 *   3. `Excel::import(new ItemCategoryImport(...))` construisait l'instance EN LIGNE,
 *      donc la jetait : ses échecs n'étaient jamais lus. Et le contrôleur répondait
 *      `response('', 202)` — un 202 VIDE, quoi qu'il arrive.
 *
 * Bout à bout : le commerçant exportait ses catégories, en corrigeait une, redéposait
 * le fichier. Zéro catégorie créée, et un succès à l'écran. Aucun mot, nulle part.
 *
 * LE MOTIF, plus important que le défaut : une correction appliquée à UN SEUL des
 * deux jumeaux. C'est pourquoi la résolution d'en-têtes vit maintenant dans un trait
 * partagé (`AccepteLesEnTetesExportes`) plutôt qu'en double.
 *
 * CE BANC MORD : retirer le trait, ou revenir au 202 vide, le fait rougir.
 */
class LesCategoriesExporteesSeReimportentTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        Permission::findOrCreate('settings', 'sanctum');
        $this->karim->givePermissionTo(['settings']);
    }

    /**
     * Dépose un classeur dont les EN-TÊTES sont exactement ceux que
     * `ItemCategoryExport::headings()` produit dans la locale donnée.
     *
     * @param  list<list<string>>  $lignes
     */
    private function deposerCommeExporte(string $locale, array $lignes): \Illuminate\Testing\TestResponse
    {
        $entetes = [
            trans('all.label.name', [], $locale),
            trans('all.label.status', [], $locale),
            trans('all.label.description', [], $locale),
        ];

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray(array_merge([$entetes], $lignes), null, 'A1');

        $chemin = tempnam(sys_get_temp_dir(), 'cats') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($chemin);

        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->post('/api/admin/setting/item-category/import/file', [
                'file' => new UploadedFile($chemin, 'categories.xlsx', null, null, true),
            ]);

        @unlink($chemin);

        return $reponse;
    }

    public function test_un_export_francais_de_categories_se_reimporte(): void
    {
        $actif = trans('statuse.' . Status::ACTIVE, [], 'fr');

        $reponse = $this->deposerCommeExporte('fr', [
            ['Nos Tacos', $actif, 'Les tacos maison'],
            ['Boissons',  $actif, ''],
        ]);

        $reponse->assertStatus(202);

        $this->assertNotNull(
            ItemCategory::query()->where('name', 'Nos Tacos')->first(),
            "Les en-têtes « Nom / Statut / Description » sont ceux que l'application\n"
            . "vient d'exporter. Ne pas les reconnaître rendait l'aller-retour\n"
            . 'impossible, sans un seul message.'
        );

        $this->assertSame(2, ItemCategory::query()->count());
    }

    public function test_la_reponse_dit_ce_qui_a_ete_fait(): void
    {
        $actif = trans('statuse.' . Status::ACTIVE, [], 'fr');

        $reponse = $this->deposerCommeExporte('fr', [['Nos Tacos', $actif, '']]);

        $corps = $reponse->json();

        $this->assertIsArray(
            $corps,
            "Le contrôleur répondait `response('', 202)` — un corps VIDE. Le commerçant\n"
            . 'ne pouvait pas savoir si zéro, une ou onze catégories avaient été créées.'
        );

        $this->assertSame(1, $corps['creees'] ?? null);
        $this->assertSame([], $corps['echecs'] ?? null);
        $this->assertStringContainsString('1 categorie', (string) ($corps['message'] ?? ''));
    }

    public function test_une_ligne_deja_presente_nest_pas_comptee_comme_une_erreur(): void
    {
        ItemCategory::factory()->create(['name' => 'Nos Tacos', 'status' => Status::ACTIVE]);

        $actif = trans('statuse.' . Status::ACTIVE, [], 'fr');

        $reponse = $this->deposerCommeExporte('fr', [
            ['Nos Tacos', $actif, ''],   // déjà là
            ['Boissons',  $actif, ''],   // nouvelle
        ]);

        $corps = $reponse->json();

        $this->assertCount(
            1,
            $corps['deja_presents'] ?? [],
            "Redéposer le fichier entier après avoir corrigé quelques lignes est le\n"
            . "parcours NORMAL. Présenter les lignes déjà créées comme des erreurs donne\n"
            . "l'impression exactement inverse de ce qui s'est passé."
        );

        $this->assertSame([], $corps['echecs'] ?? null, "Une ligne déjà là n'est pas une faute.");
        $this->assertSame(1, $corps['creees'] ?? null);

        $this->assertStringNotContainsString(
            'deja-present',
            (string) json_encode($corps),
            'Le marqueur technique ne doit jamais atteindre le commerçant.'
        );
    }

    public function test_un_statut_illisible_est_refuse_et_nomme(): void
    {
        $reponse = $this->deposerCommeExporte('fr', [['Nos Tacos', 'Peut-être', '']]);

        $corps = $reponse->json();

        $this->assertCount(
            1,
            $corps['echecs'] ?? [],
            "Un statut non reconnu était rabattu en silence : la catégorie naissait\n"
            . 'INACTIVE, donc invisible partout, et l\'écran annonçait un succès.'
        );

        $this->assertStringContainsString('Peut-être', $corps['echecs'][0]['raison']);
        $this->assertSame(0, ItemCategory::query()->count());
    }
}
