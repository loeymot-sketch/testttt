<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\User;
use App\Support\Labels\DlcCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P1.4] Import CSV des produits à étiqueter : le fichier-modèle est le contrat, l'aperçu
 * n'écrit rien, un nom existant n'est JAMAIS écrasé, chaque erreur dit la ligne et la raison, aucune durée n'est validée d'office.
 */
class DlcCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "Produit;Famille;Conservation;Après décongélation;Ouverture;Décongélation;Préparation;Cuisson;Sous vide;Congélation;Repas témoin;Réception;Allergènes;Mention;Source des durées\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
    }

    private function caisse(int $branch = 1): User
    {
        $user = User::factory()->create(['branch_id' => $branch]);
        $user->givePermissionTo(['pos-orders']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** Une ligne du fichier : colonnes dans l'ordre du modèle (clés = DlcCsvImporter::COLUMNS) ; ce qui n'est pas donné reste vide. */
    private function line(array $c): string
    {
        return implode(';', array_map(fn ($k) => $c[$k] ?? '', array_keys(DlcCsvImporter::COLUMNS)));
    }

    private function upload(string $content, string $name = 'produits.csv', bool $commit = false)
    {
        $file = UploadedFile::fake()->createWithContent($name, $content);

        return $this->postJson('/api/admin/labels/products/import', ['file' => $file, 'commit' => $commit]);
    }

    public function test_le_fichier_modele_est_relu_sans_erreur_et_redonne_ses_exemples(): void
    {
        $tpl = DlcCsvImporter::template();
        $this->assertStringStartsWith("\xEF\xBB\xBFProduit;Famille;Conservation", $tpl);
        $r = DlcCsvImporter::parse($tpl, []);
        $this->assertNull($r['fatal']);
        $this->assertSame(3, $r['summary']['total']);
        $this->assertSame(['new' => 3, 'exists' => 0, 'invalid' => 0, 'total' => 3], $r['summary'], json_encode($r['rows'], JSON_UNESCAPED_UNICODE));
        // le modèle montre une décongélation avec sa conservation, un allergène, un « aucun »
        $steak = collect($r['rows'])->firstWhere('name', 'Steak haché décongelé');
        $this->assertSame('thawed', $steak['data']['actions'][0]['key']);
        $this->assertNotNull($steak['data']['thawed_storage']);
        $this->assertSame(['lait'], collect($r['rows'])->firstWhere('name', 'Cheddar tranché (ouvert)')['data']['allergens']);
        $this->assertTrue(collect($r['rows'])->firstWhere('name', 'Oignons rouges émincés')['data']['no_allergens']);
    }

    public function test_l_apercu_n_ecrit_rien_et_le_telechargement_du_modele_marche(): void
    {
        $this->caisse();
        $dl = $this->get('/api/admin/labels/products/import-template')->assertOk();
        $this->assertStringContainsString('text/csv', $dl->headers->get('Content-Type'));
        $this->assertStringContainsString('modele-produits-dlc.csv', $dl->headers->get('Content-Disposition'));

        $csv = self::HEAD.$this->line(['name' => 'Oignons émincés', 'category' => 'Légumes & crudités', 'storage' => '0 à +4 °C', 'prepared' => '24 h', 'allergens' => 'aucun'])."\n";
        $r = $this->upload($csv)->assertOk()->json('data');
        $this->assertFalse($r['committed']);
        $this->assertSame(0, $r['created']);
        $this->assertSame('new', $r['rows'][0]['status']);
        $this->assertSame(0, DlcLabelProduct::query()->count(), 'un aperçu n’écrit rien');
    }

    public function test_l_import_cree_seulement_les_lignes_valides_et_nouvelles_et_ne_valide_aucune_duree(): void
    {
        $user = $this->caisse();
        $existing = new DlcLabelProduct(['name' => 'Olives', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days', 'storage' => '0 à +4 °C']);
        $existing->branch_id = 1;
        $existing->save();

        $csv = self::HEAD
            .$this->line(['name' => 'Oignons émincés', 'category' => 'Légumes & crudités', 'storage' => '0 à +4 °C', 'prepared' => '24 h', 'allergens' => 'aucun', 'source' => 'GBPH restauration'])."\n"
            .$this->line(['name' => 'olives', 'storage' => '0 à +4 °C', 'opened' => '10 j'])."\n"                      // existe (casse différente)
            .$this->line(['name' => 'Poulet mariné', 'storage' => '0 à +3 °C', 'thawed_storage' => '0 à +2 °C', 'thawed' => '24 h', 'opened' => '48 h', 'allergens' => 'Moutarde|Lait'])."\n"
            .$this->line(['name' => 'Sans conservation', 'prepared' => '24 h'])."\n";                                   // invalide
        $r = $this->upload($csv, 'produits.csv', true)->assertCreated()->json('data');

        $this->assertSame(['new' => 2, 'exists' => 1, 'invalid' => 1, 'total' => 4], $r['summary']);
        $this->assertSame(2, $r['created']);
        $this->assertSame(3, DlcLabelProduct::query()->count());

        // jamais écrasé : « Olives » garde ses 3 jours
        $this->assertSame(3, DlcLabelProduct::query()->where('name', 'Olives')->first()->shelf_life_value);

        $poulet = DlcLabelProduct::query()->where('name', 'Poulet mariné')->firstOrFail();
        $this->assertSame([['key' => 'opened', 'value' => 48, 'unit' => 'hours'], ['key' => 'thawed', 'value' => 24, 'unit' => 'hours']], $poulet->actionList(), 'l’ordre des colonnes fixe l’événement par défaut');
        $this->assertSame('0 à +2 °C', $poulet->thawed_storage);
        $this->assertSame(['moutarde', 'lait'], $poulet->allergens);
        $this->assertSame(1, $poulet->branch_id);
        // aucune durée validée d'office : c'est au responsable
        $this->assertNull($poulet->durations_checked_at);
        $this->assertSame('own', $poulet->durationReview());
        $this->assertSame('Import du restaurant', $poulet->duration_source);
        $this->assertSame('GBPH restauration', DlcLabelProduct::query()->where('name', 'Oignons émincés')->first()->duration_source);

        // rejouer le même fichier ne crée rien (idempotent)
        $again = $this->upload($csv, 'produits.csv', true)->assertOk()->json('data');
        $this->assertSame(0, $again['created']);
        $this->assertSame(3, DlcLabelProduct::query()->count());
    }

    public function test_chaque_erreur_dit_la_ligne_et_la_raison(): void
    {
        $this->caisse();
        $csv = self::HEAD
            .$this->line(['name' => 'A sans conservation', 'prepared' => '24 h'])."\n"                                                  // ligne 2
            .$this->line(['name' => 'B durée sans unité', 'storage' => '0 à +4 °C', 'prepared' => '24'])."\n"                           // 3
            .$this->line(['name' => 'C trop long', 'storage' => '0 à +4 °C', 'prepared' => '800 h'])."\n"                               // 4
            .$this->line(['name' => 'D décongelé sans conservation', 'storage' => '-18 °C', 'thawed' => '24 h'])."\n"                     // 5
            .$this->line(['name' => 'E allergène inconnu', 'storage' => '0 à +4 °C', 'prepared' => '24 h', 'allergens' => 'Pistache'])."\n" // 6
            .$this->line(['name' => 'F contradictoire', 'storage' => '0 à +4 °C', 'prepared' => '24 h', 'allergens' => 'Lait|aucun'])."\n"  // 7
            .$this->line(['name' => 'G après décongélation au congélateur', 'storage' => '-18 °C', 'thawed_storage' => '-18 °C', 'thawed' => '24 h'])."\n" // 8
            .$this->line(['name' => 'H sans durée', 'storage' => '0 à +4 °C'])."\n"                                                      // 9
            .$this->line(['name' => 'Doublon', 'storage' => '0 à +4 °C', 'prepared' => '24 h'])."\n"                                    // 10
            .$this->line(['name' => 'doublon', 'storage' => '0 à +4 °C', 'prepared' => '48 h'])."\n";                                   // 11
        $rows = collect($this->upload($csv)->assertOk()->json('data.rows'))->keyBy('line');

        $this->assertMatchesRegularExpression('/Conservation obligatoire/', $rows[2]['errors'][0]);
        $this->assertMatchesRegularExpression('/« 24 » illisible.*« 24 h » ou « 3 j »/', $rows[3]['errors'][0]);
        $this->assertMatchesRegularExpression('/hors limites \(1 à 720 h\)/', $rows[4]['errors'][0]);
        $this->assertMatchesRegularExpression('/Après décongélation/', $rows[5]['errors'][0]);
        $this->assertMatchesRegularExpression('/Allergène inconnu : « Pistache »/', $rows[6]['errors'][0]);
        $this->assertMatchesRegularExpression('/Allergènes ET « aucun »/', $rows[7]['errors'][0]);
        $this->assertMatchesRegularExpression('/jamais le congélateur/', $rows[8]['errors'][0]);
        $this->assertMatchesRegularExpression('/Aucune durée/', $rows[9]['errors'][0]);
        $this->assertSame('new', $rows[10]['status']);
        $this->assertSame('invalid', $rows[11]['status']);
        $this->assertMatchesRegularExpression('/déjà présent plus haut/', $rows[11]['errors'][0]);
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 11] as $l) $this->assertSame('invalid', $rows[$l]['status'], "ligne $l");
    }

    public function test_un_fichier_excel_francais_windows_1252_virgules_ou_points_virgules_est_lu(): void
    {
        $this->caisse();
        $utf8 = "Produit;Conservation;Préparation;Allergènes\n\"Crème, fraîche\";0 à +4 °C;48 h;Lait\nŒufs durs;0 à +4 °C;3 j;Œufs\n";
        $cp1252 = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        $rows = $this->upload($cp1252)->assertOk()->json('data.rows');
        $this->assertSame(['Crème, fraîche', 'Œufs durs'], array_column($rows, 'name'));
        $this->assertSame(['new', 'new'], array_column($rows, 'status'));

        $commas = "Produit,Conservation,Ouverture\nOlives,0 à +4 °C,3 j\n";
        $this->assertSame('new', $this->upload($commas)->assertOk()->json('data.rows.0.status'));
        // allergènes reconnus par leur nom (accents, œ, pluriels) ; la cellule vide n'invente rien
        $r = DlcCsvImporter::parse("Produit;Conservation;Ouverture;Allergènes\nA;0 à +4 °C;1 j;Œufs|Fruits à coque\nB;0 à +4 °C;1 j;\n", []);
        $this->assertSame(['oeufs', 'fruits_a_coque'], $r['rows'][0]['data']['allergens']);
        $this->assertSame([], $r['rows'][1]['data']['allergens']);
        $this->assertFalse($r['rows'][1]['data']['no_allergens']);
    }

    public function test_les_refus_de_fichier(): void
    {
        $this->caisse();
        $this->upload("Produit;Conservation\nOlives;0 à +4 °C\n", 'produits.xlsx')->assertStatus(422);
        $this->upload('', 'vide.csv')->assertStatus(422);
        $r = $this->upload("Nom du truc;Autre\nA;B\n")->assertStatus(422);
        $this->assertStringContainsString('fichier-modèle', $r->json('message'));
        $rows = implode("\n", array_map(fn ($i) => "P$i;0 à +4 °C;1 j", range(1, 501)));
        $this->assertStringContainsString('500 au plus', $this->upload("Produit;Conservation;Ouverture\n".$rows)->assertStatus(422)->json('message'));
        $this->postJson('/api/admin/labels/products/import', [])->assertStatus(422);
    }

    public function test_sans_authentification_ou_sans_permission_c_est_refuse(): void
    {
        $this->postJson('/api/admin/labels/products/import', [])->assertStatus(401);
        $user = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($user, ['*']);
        $this->get('/api/admin/labels/products/import-template')->assertStatus(403);
    }

    public function test_un_import_est_range_dans_la_branche_de_l_appelant_et_ne_voit_pas_l_autre_branche(): void
    {
        $other = new DlcLabelProduct(['name' => 'Olives', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days', 'storage' => '0 à +4 °C']);
        $other->branch_id = 2;
        $other->save();
        $this->caisse(1);
        $r = $this->upload("Produit;Conservation;Ouverture\nOlives;0 à +4 °C;3 j\n", 'p.csv', true)->assertCreated()->json('data');
        $this->assertSame(1, $r['created'], 'le « Olives » de l’autre restaurant n’est pas le mien');
        $this->assertSame(1, DlcLabelProduct::withoutGlobalScopes()->where('branch_id', 1)->where('name', 'Olives')->count());
        $this->assertSame(1, DlcLabelProduct::withoutGlobalScopes()->where('branch_id', 2)->where('name', 'Olives')->count());
    }
}
