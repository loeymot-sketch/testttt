<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Libraries\EnumAppLibrary;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-02 2026-08-28] Le fichier que l'application exporte doit pouvoir y rentrer.
 *
 * C'est le SEUL moyen, pour un restaurateur, de modifier sa carte en masse :
 * exporter, corriger dans un tableur, réimporter. Cet aller-retour ne revenait
 * jamais, et il échouait en silence.
 *
 * DEUX RUPTURES, chacune suffisante à elle seule.
 *
 * 1. LES EN-TÊTES. `ItemExport::headings()` écrit `trans('all.label.name')` — donc
 *    « Nom », « Catégorie », « Prix » — et `WithHeadingRow` les slugge en `nom`,
 *    `categorie`, `prix`. L'import cherchait `name`, `category`, `price` : aucune
 *    colonne ne correspondait, toutes les lignes échouaient sur `name required`,
 *    `SkipsOnFailure` les avalait, et l'écran annonçait un succès.
 *
 *    ⚠️ MESURÉ, et contraire à ce que j'avais d'abord écrit : le défaut ne frappait
 *    QUE les locales non anglaises. En anglais, chaque libellé se slugge exactement
 *    sur le nom canonique — `Category` → `category`, `Tax` → `tax` — et l'aller-retour
 *    fonctionnait déjà. Les sept colonnes cassaient en français. Je l'avais annoncé
 *    autrement, et le contrôle négatif de ce banc l'a démenti : neutraliser les alias
 *    fait rougir le cas français et laisse le cas anglais vert.
 *
 * 2. LES VALEURS. L'export écrit `trans('statuse.'.$status)` — « Actif » —, et
 *    `EnumAppLibrary::itemStatus('actif')` ne reconnaissait ni `active` ni
 *    `inactive`, donc retombait SILENCIEUSEMENT sur `Status::INACTIVE`. Les 45
 *    produits étaient recréés INVISIBLES : ni borne, ni caisse, ni cuisine. Le
 *    commerçant lisait « import réussi » et découvrait une borne vide le lendemain
 *    matin, sans un seul indice.
 *
 * Ce banc exerce la boucle complète, sur les deux locales, et vérifie surtout que
 * le repli silencieux a disparu : une valeur illisible est désormais REFUSÉE et
 * NOMMÉE, jamais rabattue sur un défaut.
 */
class LaCarteExporteeSeReimporteTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        foreach (['items', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items', 'items_create', 'items_edit']);
    }

    /**
     * Dépose un classeur dont les EN-TÊTES et les VALEURS sont ceux que l'export
     * produit dans la locale donnée.
     */
    private function deposerCommeExporte(string $locale, array $remplacements = []): \Illuminate\Testing\TestResponse
    {
        $entetes = [
            trans('all.label.name', [], $locale),
            trans('all.label.item_category_id', [], $locale),
            trans('all.label.price', [], $locale),
            trans('all.label.item_type', [], $locale),
            trans('all.label.tax_id', [], $locale),
            trans('all.label.status', [], $locale),
            trans('all.label.featured', [], $locale),
            trans('all.label.caution', [], $locale),
            trans('all.label.description', [], $locale),
        ];

        // ⚠️ L'union `+` conserve l'ORDRE D'INSERTION : `[5 => 'x'] + [0 => ..., 1 => ...]`
        // place la colonne 5 en tête, et `array_values()` décale toute la ligne. On
        // trie donc par clé avant d'aplatir — bug attrapé par ce banc lui-même, qui
        // signalait un échec sur « category » là où le test portait sur « status ».
        $colonnes = $remplacements + [
            0 => 'Tacos poulet',
            1 => 'Tacos',
            2 => '8.50',
            3 => trans('itemType.' . \App\Enums\ItemType::VEG, [], $locale),
            4 => '10',
            5 => trans('statuse.' . Status::ACTIVE, [], $locale),
            6 => trans('ask.' . \App\Enums\Ask::NO, [], $locale),
            7 => '',
            8 => '',
        ];
        ksort($colonnes);
        $ligne = array_values($colonnes);

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray([$entetes, $ligne], null, 'A1');

        $chemin = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($chemin);

        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->post('/api/admin/item/import/file', [
                'file' => new UploadedFile($chemin, 'carte.xlsx', null, null, true),
            ]);

        @unlink($chemin);

        return $reponse;
    }

    public function test_un_export_francais_se_reimporte(): void
    {
        $reponse = $this->deposerCommeExporte('fr');

        $this->assertSame(
            1,
            $reponse->json('creees'),
            "Le fichier exporté en français doit se réimporter.\n"
            . 'Échecs : ' . json_encode($reponse->json('echecs'), JSON_UNESCAPED_UNICODE)
        );

        $article = Item::query()->first();
        $this->assertNotNull($article);

        $this->assertSame(
            Status::ACTIVE,
            (int) $article->status,
            "L'article est recréé INACTIF : « Actif » n'a pas été reconnu et le repli\n"
            . "silencieux a fait son œuvre. Le commerçant découvrirait une borne vide."
        );
    }

    public function test_un_export_anglais_se_reimporte_aussi(): void
    {
        // CONTRÔLE, pas détecteur de régression : l'anglais bouclait DÉJÀ, parce que
        // chaque libellé s'y slugge sur le nom canonique. Ce cas existe pour vérifier
        // que le correctif du français n'a rien cassé de l'autre côté.
        $reponse = $this->deposerCommeExporte('en');

        $this->assertSame(
            1,
            $reponse->json('creees'),
            'Échecs : ' . json_encode($reponse->json('echecs'), JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_un_statut_illisible_est_refuse_et_non_rabattu_sur_inactif(): void
    {
        // LE CŒUR DU DÉFAUT. Avant, n'importe quelle valeur non reconnue donnait
        // INACTIF sans un mot. Un repli muet sur un statut est de la même famille
        // que le repli muet sur une taxe à 0 % : il transforme une faute de frappe
        // en catastrophe invisible.
        $reponse = $this->deposerCommeExporte('fr', [5 => 'Actiff']);

        $echecs = $reponse->json('echecs');

        $this->assertNotEmpty(
            $echecs,
            "Un statut illisible doit être REFUSÉ et nommé, pas rabattu sur INACTIF."
        );

        $this->assertSame('status', $echecs[0]['colonne']);

        // Et le motif doit dire ce qui se passerait, et quoi écrire à la place.
        $this->assertStringContainsString('invisible', $echecs[0]['raison']);
        $this->assertStringContainsString('Actif', $echecs[0]['raison']);

        $this->assertSame(0, Item::query()->count());
    }

    public function test_un_type_de_produit_illisible_ne_devient_plus_vegetarien(): void
    {
        // `itemType()` renvoyait ItemType::VEG pour TOUTE valeur non reconnue : un
        // kebab importé se retrouvait étiqueté végétarien.
        $reponse = $this->deposerCommeExporte('fr', [3 => 'Kebab']);

        $echecs = $reponse->json('echecs');

        $this->assertNotEmpty($echecs);
        $this->assertSame('item_type', $echecs[0]['colonne']);
        $this->assertSame(0, Item::query()->count());
    }

    public function test_la_reconnaissance_accepte_les_deux_ecritures(): void
    {
        // Contrôle direct de la bibliothèque : l'anglais canonique ET la traduction.
        $this->assertSame(Status::ACTIVE, EnumAppLibrary::itemStatus('active'));
        $this->assertSame(Status::ACTIVE, EnumAppLibrary::itemStatus('Actif'));
        $this->assertSame(Status::INACTIVE, EnumAppLibrary::itemStatus('Inactif'));

        $this->assertSame(\App\Enums\ItemType::NON_VEG, EnumAppLibrary::itemType('non veg'));
        $this->assertSame(\App\Enums\Ask::YES, EnumAppLibrary::itemFeature('Oui'));

        // Et surtout : ce qui n'est pas reconnu rend `null`, jamais un défaut.
        $this->assertNull(EnumAppLibrary::itemStatus('Actiff'));
        $this->assertNull(EnumAppLibrary::itemType('Kebab'));
        $this->assertNull(EnumAppLibrary::itemFeature('Peut-être'));
        $this->assertNull(EnumAppLibrary::itemStatus(''));
    }

    public function test_les_valeurs_acceptees_sont_montrables_au_commercant(): void
    {
        // « Valeur invalide » ne dit pas quoi écrire. Le message doit porter la liste.
        $acceptees = EnumAppLibrary::valeursAcceptees('statuse');

        $this->assertContains('Actif', $acceptees);
        $this->assertContains('Active', $acceptees);
        $this->assertNotContains('', $acceptees);
    }
}
